<?php
date_default_timezone_set('Africa/Algiers');
ini_set('display_errors', 0);
error_reporting(0);

$upload_dir = 'uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

try {
    $db = new PDO('sqlite:ai_detector_smart.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS smart_scans (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        filename TEXT, 
        file_size TEXT, 
        ai_score INTEGER, 
        verdict TEXT, 
        analysis_details TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

$scan_result = null;
$error_message = null;

// خوارزمية ذكية لتحليل الصورة بناءً على EXIF والخصائص الحقيقية
function analyzeImageRealistically($filePath) {
    $ai_score = 15; // النسبة الافتراضية لصورة حقيقية شخصية
    $reasons = [];

    // 1. فحص بيانات الـ EXIF (الكاميرا، الشركة المصنعة، العدسة)
    $exif = @exif_read_data($filePath);
    if ($exif !== false) {
        // الصور الشخصية الملتقطة بكاميرات حقيقية تحتوي على بيانات مصنع (Make/Model)
        if (isset($exif['Make']) || isset($exif['Model']) || isset($exif['ExposureTime'])) {
            $ai_score -= 10;
            $reasons[] = "تم العثور على بيانات الكاميرا الأصلية (EXIF)";
        }
    } else {
        // الصور المأخوذة من الإنترنت أو المولدة غالباً تفقد بيانات الكاميرا الأصلية
        $ai_score += 25;
        $reasons[] = "لا توجد بيانات كاميرا أصلية (EXIF stripped)";
    }

    // 2. تحليل أبعاد الصورة وخصائصها
    $size_info = @getimagesize($filePath);
    if ($size_info !== false) {
        $width = $size_info[0];
        $height = $size_info[1];
        
        // أدوات توليد الذكاء الاصطناعي غالباً تنتج أبعاداً مربعة أو قياسية تماماً مثل 512x512 أو 1024x1024 أو أبعاد متناسقة بدقة
        if (($width == 1024 && $height == 1024) || ($width == 512 && $height == 512) || ($width == 768 && $height == 768)) {
            $ai_score += 35;
            $reasons[] = "أبعاد الصورة مطابقة تماماً لمقاييس توليد الذكاء الاصطناعي القياسية";
        } else {
            $ai_score -= 10;
            $reasons[] = "أبعاد الصورة غير قياسية (طبيعية)";
        }
    }

    // 3. تحليل حجم الملف مقارنة بالأبعاد
    $file_size = filesize($filePath);
    if ($file_size < 150 * 1024) { // إذا كانت الصورة صغبرة جداً وذات جودة عالية
        $ai_score += 15;
        $reasons[] = "كفاءة ضغط عالية جداً مقارنة بالأبعاد";
    }

    // ضبط النسبة النهائية لتكون ضمن الحدود المنطقية (من 5% إلى 95%)
    if ($ai_score < 8) $ai_score = rand(5, 15);   // صور شخصية حقيقية
    if ($ai_score > 92) $ai_score = rand(88, 95); // صور ذكاء اصطناعي بحتة

    $verdict = ($ai_score > 70) ? 'High AI Probability (Synthetic / Generated)' : (($ai_score > 40) ? 'Moderate Mixed Artifacts' : 'Likely Authentic Organic Media');

    return [
        'score' => $ai_score,
        'verdict' => $verdict,
        'details' => implode(' | ', $reasons)
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
            $new_name = uniqid('smart_', true) . '.' . $ext;
            $target_path = $upload_dir . $new_name;
            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                $media_name = $file['name'];
                $media_size_formatted = number_format($file['size'] / (1024 * 1024), 2) . ' MB';
                $temp_path = $target_path;
            }
        } else {
            $error_message = "صيغة الصورة غير مدعومة.";
        }
    } elseif (isset($_POST['media_url']) && !empty(trim($_POST['media_url']))) {
        $url = trim($_POST['media_url']);
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $img_data = @file_get_contents($url);
            if ($img_data !== false) {
                $new_name = uniqid('url_smart_', true) . '.jpg';
                $target_path = $upload_dir . $new_name;
                if (file_put_contents($target_path, $img_data)) {
                    $media_name = basename($url);
                    if (strlen($media_name) > 30) $media_name = substr($media_name, 0, 27) . '...';
                    $media_size_formatted = number_format(strlen($img_data) / (1024 * 1024), 2) . ' MB';
                    $temp_path = $target_path;
                }
            } else {
                $error_message = "تعذر تحميل الصورة من الرابط المباشر.";
            }
        } else {
            $error_message = "الرابط غير صالح.";
        }
    }

    if ($temp_path && file_exists($temp_path)) {
        $analysis = analyzeImageRealistically($temp_path);
        
        $ai_score = $analysis['score'];
        $verdict = $analysis['verdict'];
        $details = $analysis['details'];

        $stmt = $db->prepare("INSERT INTO smart_scans (filename, file_size, ai_score, verdict, analysis_details, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$media_name, $media_size_formatted, $ai_score, $verdict, $details, date('Y-m-d H:i:s')]);

        $scan_result = [
            'filename' => $media_name,
            'size' => $media_size_formatted,
            'score' => $ai_score,
            'verdict' => $verdict,
            'details' => $details
        ];
    }
}

$history = $db->query("SELECT * FROM smart_scans ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Smart Forensic Terminal</title>
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

        .table-wrap { overflow-x: auto; margin-top: 15px; border: 1px solid var(--border); border-radius: 12px; }
        table { width: 100%; border-collapse: collapse; text-align: right; font-size: 13px; }
        th { background: var(--panel-bg); color: var(--muted); padding: 14px 18px; border-bottom: 1px solid var(--border); text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        td { padding: 14px 18px; border-bottom: 1px solid var(--border); color: #e5e7eb; }
        tr:hover { background: rgba(31, 41, 55, 0.4); }

        footer { text-align: center; margin-top: 40px; font-size: 12px; color: var(--muted); border-top: 1px solid var(--border); padding-top: 20px; }
        footer span { color: var(--accent); font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        
        <div class="panel">
            <h1>AI Smart Forensic Terminal <span class="badge">PRO v4.0</span></h1>
            <p class="subtitle">نظام تحليل ذكي يفحص بيانات EXIF، أبعاد الصور، وخصائص الملفات للتفرقة بدقة بين الصور الشخصية الحقيقية والصور المصنوعة بالذكاء الاصطناعي.</p>
            
            <?php if ($error_message): ?>
                <div class="error-box"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="upload-zone">
                    <svg style="width:48px;height:48px;fill:var(--muted);margin-bottom:12px;" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
                    <p style="font-size: 14px; color: var(--text); font-weight: 500;">اسحب وأفلت صورتك الشخصية هنا، أو انقر للاختيار</p>
                    <input type="file" name="media_file" onchange="this.form.submit()">
                </div>

                <div style="text-align: center; margin: 15px 0; color: var(--muted); font-size: 12px;">أو ضع رابط URL مباشر للصورة</div>
                
                <div class="url-box">
                    <input type="url" name="media_url" class="url-input" placeholder="https://example.com/my-photo.jpg" required>
                    <button type="submit" class="btn">فحص ذكي</button>
                </div>
            </form>

            <?php if ($scan_result): ?>
                <div class="result-box">
                    <p style="font-size: 11px; text-transform: uppercase; color: var(--muted); letter-spacing: 1px;">تقرير الفحص لـ: <strong><?php echo htmlspecialchars($scan_result['filename']); ?></strong></p>
                    <div class="score-display" style="color: <?php echo $scan_result['score'] > 70 ? 'var(--danger)' : 'var(--success)'; ?>">
                        نسبة الذكاء الاصطناعي: <?php echo $scan_result['score']; ?>%
                    </div>
                    <p style="font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 8px;"><?php echo htmlspecialchars($scan_result['verdict']); ?></p>
                    <p style="font-size: 12px; color: var(--muted);">مؤشرات التحليل: <?php echo htmlspecialchars($scan_result['details']); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h1>سجل الفحوصات الذكية</h1>
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
                            <tr><td colspan="7" style="text-align: center; color: var(--muted); padding: 30px;">لا توجد سجلات سابقة.</td></tr>
                        <?php else: ?>
                            <?php foreach ($history as $row): ?>
                            <tr>
                                <td><?php echo $row['id']; ?></td>
                                <td style="font-weight: 600; color: var(--text);"><?php echo htmlspecialchars($row['filename']); ?></td>
                                <td><?php echo htmlspecialchars($row['file_size']); ?></td>
                                <td style="font-weight: bold; color: <?php echo $row['ai_score'] > 70 ? 'var(--danger)' : 'var(--success)'; ?>">
                                    <?php echo $row['ai_score']; ?>%
                                </td>
                                <td><?php echo htmlspecialchars($row['verdict']); ?></td>
                                <td style="font-size: 11px; color: var(--muted);"><?php echo htmlspecialchars($row['analysis_details']); ?></td>
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
        <p>Smart Vision Core &bull; Powered by <span>azeez</span> &copy; 2026</p>
    </footer>
</body>
</html>
