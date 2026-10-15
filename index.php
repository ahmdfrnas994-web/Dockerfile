<?php
date_default_timezone_set('Africa/Algiers');
ini_set('display_errors', 0);
error_reporting(0);

$upload_dir = 'uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

try {
    $db = new PDO('sqlite:ai_detector_local.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS local_scans (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        filename TEXT, 
        file_size TEXT, 
        ai_score INTEGER, 
        verdict TEXT, 
        analysis_note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

$scan_result = null;
$error_message = null;

// تحليل محلي ذكي يعتمد على خصائص الصورة الحقيقية (GD Library)
function analyzeLocallyWithoutApi($filePath) {
    $score = 20; // نقطة بداية منخفضة لصالح الصور الحقيقية
    $notes = [];

    $imageInfo = @getimagesize($filePath);
    if ($imageInfo === false) {
        return ['score' => 50, 'verdict' => 'ملف غير صالح', 'notes' => 'تعذر قراءة الصورة'];
    }

    $width = $imageInfo[0];
    $height = $imageInfo[1];
    $fileSize = filesize($filePath);

    // 1. فحص الأبعاد القياسية لأدوات التوليد (مثل Midjourney و DALL-E)
    $standardAiSizes = ['1024x1024', '512x512', '768x768', '1536x1024', '1024x1536'];
    $currentDim = "{$width}x{$height}";
    if (in_array($currentDim, $standardAiSizes)) {
        $score += 35;
        $notes[] = "أبعاد الصورة مطابقة لمقاييس التوليد بالذكاء الاصطناعي";
    } else {
        $score -= 10;
        $notes[] = "أبعاد الصورة طبيعية غير قياسية";
    }

    // 2. تحليل نسبة الحجم إلى الأبعاد (حجم الملف الصغير مع أبعاد كبيرة جداً غالباً يدل على توليد AI أو ضغط اصطناعي)
    $pixelCount = $width * $height;
    $bytesPerPixel = $fileSize / $pixelCount;
    
    if ($bytesPerPixel < 0.15) { // ضغط ععالي وملمس ناعم جداً
        $score += 30;
        $notes[] = "معدل تفاصيل البايتات يشير إلى نعومة اصطناعية";
    } else {
        $score -= 15;
        $notes[] = "تشبع البايتات والضوضاء طبيعي (صورة حقيقية)";
    }

    // 3. فحص بيانات EXIF الكاميرا
    $exif = @exif_read_data($filePath);
    if ($exif && (isset($exif['Make']) || isset($exif['Model']))) {
        $score -= 30; // وجود كاميرا حقيقية يخفض نسبة الـ AI بشكل كبير
        $notes[] = "تم رصد بصمة كاميرا رقمية أصلية";
    } else {
        $score += 15;
        $notes[] = "لا توجد بصمة كاميرا (مأخوذة من إنترنت أو منشأة)";
    }

    // ضبط الحدود لمنع التطرف في النتائج
    if ($score < 10) $score = rand(8, 18);
    if ($score > 90) $score = rand(85, 94);

    $verdict = ($score > 70) ? 'احتمالية عالية لتكوين بالذكاء الاصطناعي' : (($score > 40) ? 'صورة مختلطة أو معدلة' : 'صورة حقيقية وأصلية');

    return [
        'score' => $score,
        'verdict' => $verdict,
        'notes' => implode(' | ', $notes)
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $media_name = '';
    $media_size_formatted = '';
    $temp_path = '';

    if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['media_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];

        if (in_array($ext, $allowed)) {
            $new_name = uniqid('local_', true) . '.' . $ext;
            $target_path = $upload_dir . $new_name;
            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                $media_name = $file['name'];
                $media_size_formatted = number_format($file['size'] / (1024 * 1024), 2) . ' MB';
                $temp_path = $target_path;
            }
        } else {
            $error_message = "صيغة الملف غير مدعومة.";
        }
    } elseif (isset($_POST['media_url']) && !empty(trim($_POST['media_url']))) {
        $url = trim($_POST['media_url']);
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $img_data = @file_get_contents($url);
            if ($img_data !== false) {
                $new_name = uniqid('url_local_', true) . '.jpg';
                $target_path = $upload_dir . $new_name;
                if (file_put_contents($target_path, $img_data)) {
                    $media_name = basename($url);
                    if (strlen($media_name) > 30) $media_name = substr($media_name, 0, 27) . '...';
                    $media_size_formatted = number_format(strlen($img_data) / (1024 * 1024), 2) . ' MB';
                    $temp_path = $target_path;
                }
            } else {
                $error_message = "تعذر تحميل الصورة من الرابط.";
            }
        } else {
            $error_message = "الرابط غير صالح.";
        }
    }

    if ($temp_path && file_exists($temp_path)) {
        $analysis = analyzeLocallyWithoutApi($temp_path);
        
        $ai_score = $analysis['score'];
        $verdict = $analysis['verdict'];
        $analysis_note = $analysis['notes'];

        $stmt = $db->prepare("INSERT INTO local_scans (filename, file_size, ai_score, verdict, analysis_note, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$media_name, $media_size_formatted, $ai_score, $verdict, $analysis_note, date('Y-m-d H:i:s')]);

        $scan_result = [
            'filename' => $media_name,
            'size' => $media_size_formatted,
            'score' => $ai_score,
            'verdict' => $verdict,
            'notes' => $analysis_note
        ];
    }
}

$history = $db->query("SELECT * FROM local_scans ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Local AI Forensic Terminal</title>
    <style>
        :root {
            --bg: #030712;
            --card: #0b0f19;
            --panel-bg: #111827;
            --border: #1f2937;
            --accent: #6366f1;
            --accent-glow: rgba(99, 102, 241, 0.4);
            --text: #f9fafb;
            --muted: #9ca3af;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        body { background-color: var(--bg); color: var(--text); padding: 30px 15px; min-height: 100vh; display: flex; flex-direction: column; justify-content: space-between; }
        .container { max-width: 1050px; width: 100%; margin: 0 auto; display: grid; gap: 24px; }
        
        .panel { background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 35px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7); position: relative; overflow: hidden; }
        .panel::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--accent), transparent); }
        
        h1 { font-size: 22px; font-weight: 700; margin-bottom: 6px; color: var(--text); display: flex; align-items: center; gap: 10px; }
        h1 span.badge { font-size: 10px; background: var(--accent); color: white; padding: 2px 8px; border-radius: 20px; letter-spacing: 1px; }
        p.subtitle { font-size: 13px; color: var(--muted); margin-bottom: 25px; line-height: 1.5; }

        .upload-zone { border: 2px dashed var(--border); border-radius: 14px; padding: 35px; text-align: center; background: var(--panel-bg); transition: all 0.3s ease; cursor: pointer; position: relative; }
        .upload-zone:hover { border-color: var(--accent); box-shadow: 0 0 20px var(--accent-glow); }
        .upload-zone input[type="file"] { position: absolute; width: 100%; height: 100%; top: 0; left: 0; opacity: 0; cursor: pointer; }
        
        .url-box { display: flex; gap: 12px; margin-top: 10px; }
        .url-input { flex: 1; background: var(--panel-bg); border: 1px solid var(--border); border-radius: 10px; padding: 14px 18px; color: var(--text); font-size: 14px; outline: none; transition: border-color 0.2s; }
        .url-input:focus { border-color: var(--accent); box-shadow: 0 0 10px var(--accent-glow); }
        
        .btn { background: var(--accent); color: white; border: none; padding: 0 28px; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 14px; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
        .btn:hover { background: #4f46e5; transform: translateY(-1px); box-shadow: 0 4px 12px var(--accent-glow); }

        .error-box { background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger); color: var(--danger); padding: 14px; border-radius: 10px; margin-bottom: 20px; font-size: 13px; }
        .result-box { background: var(--panel-bg); border: 1px solid var(--border); border-radius: 14px; padding: 25px; margin-top: 25px; }
        .score-display { font-size: 32px; font-weight: 800; margin: 12px 0; letter-spacing: -1px; }

        .table-wrap { overflow-x: auto; margin-top: 15px; border: 1px solid var(--border); border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; text-align: right; font-size: 13px; }
        th { background: var(--panel-bg); color: var(--muted); padding: 12px 16px; border-bottom: 1px solid var(--border); text-transform: uppercase; font-size: 11px; }
        td { padding: 12px 16px; border-bottom: 1px solid var(--border); color: #cbd5e1; }
        tr:hover { background: rgba(30, 41, 59, 0.3); }

        footer { text-align: center; margin-top: 40px; font-size: 12px; color: var(--muted); border-top: 1px solid var(--border); padding-top: 20px; }
        footer span { color: var(--accent); font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        
        <div class="panel">
            <h1>Local AI Forensic Terminal <span class="badge">LOCAL v5.0</span></h1>
            <p class="subtitle">نظام تحليل محلي متطور يفحص بصمات الكاميرا، أبعاد الصور، وكثافة البيانات داخل PHP دون الحاجة لأي مفاتيح خارجية.</p>
            
            <?php if ($error_message): ?>
                <div class="error-box"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="upload-zone">
                    <svg style="width:48px;height:48px;fill:var(--muted);margin-bottom:12px;" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
                    <p style="font-size: 14px; color: var(--text); font-weight: 500;">اختر صورتك الشخصية أو صورة القط للفحص المحلي</p>
                    <input type="file" name="media_file" onchange="this.form.submit()">
                </div>

                <div style="text-align: center; margin: 15px 0; color: var(--muted); font-size: 12px;">أو ضع رابط URL مباشر للفحص</div>
                
                <div class="url-box">
                    <input type="url" name="media_url" class="url-input" placeholder="https://example.com/image.jpg" required>
                    <button type="submit" class="btn">فحص محلي ذكي</button>
                </div>
            </form>

            <?php if ($scan_result): ?>
                <div class="result-box">
                    <p style="font-size: 11px; text-transform: uppercase; color: var(--muted); letter-spacing: 1px;">نتيجة الفحص لـ: <strong><?php echo htmlspecialchars($scan_result['filename']); ?></strong></p>
                    <div class="score-display" style="color: <?php echo $scan_result['score'] > 70 ? 'var(--danger)' : 'var(--success)'; ?>">
                        نسبة الذكاء الاصطناعي: <?php echo $scan_result['score']; ?>%
                    </div>
                    <p style="font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 6px;"><?php echo htmlspecialchars($scan_result['verdict']); ?></p>
                    <p style="font-size: 12px; color: var(--muted);">المؤشرات: <?php echo htmlspecialchars($scan_result['notes']); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h1>سجل الفحوصات المحلية</h1>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#ID</th>
                            <th>الملف</th>
                            <th>الحجم</th>
                            <th>نسبة AI</th>
                            <th>النتيجة</th>
                            <th>المؤشرات</th>
                            <th>التوقيت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($history)): ?>
                            <tr><td colspan="7" style="text-align: center; color: var(--muted); padding: 30px;">لا توجد سجلات مسجلة.</td></tr>
                        <?php else: ?>
                            <?php foreach ($history as $row): ?>
                            <tr>
                                <td><?php echo $row['id']; ?></td>
                                <td style="font-weight: 600; color: var(--text);"><?php echo htmlspecialchars($row['filename']); ?></td>
                                <td><?php echo htmlspecialchars($row['file_size']); ?></td>
                                <td style="font-weight: bold; color: <?php echo $row['ai_score'] > 70 ? 'var(--danger)' : 'var(--success)'); ?>">
                                    <?php echo $row['ai_score']; ?>%
                                </td>
                                <td><?php echo htmlspecialchars($row['verdict']); ?></td>
                                <td style="font-size: 11px; color: var(--muted);"><?php echo htmlspecialchars($row['analysis_note']); ?></td>
                                <td style="color: var(--muted);"><?php echo htmlspecialchars($row['created_at']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <footer>
        <p>Local Intelligence Core &bull; Powered by <span>azeez</span> &copy; 2026</p>
    </footer>
</body>
</html>
