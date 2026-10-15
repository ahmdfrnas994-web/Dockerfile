function fetch_real_meta($url) {
    $title = "Secure Redirect";
    $image = "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600&auto=format&fit=crop&q=60";

    // التحقق إذا كان الرابط تابعاً ليوتيوب
    if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $matches)) {
        $video_id = $matches[1];
        // الصورة الافتراضية عالية الجودة من يوتيوب
        $image = "https://img.youtube.com/vi/{$video_id}/hqdefault.jpg";
        
        $oembed_url = "https://www.youtube.com/oembed?url=" . urlencode($url) . "&format=json";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $oembed_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // زيادة المهلة إلى 5 ثوانٍ
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // تجاوز التحقق من شهادة SSL لتجنب الأخطاء المحلية
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'); // إضافة متصفح وهمي لمنع الحظر
        
        $json = curl_exec($ch);
        curl_close($ch);
        
        if ($json) {
            $data = json_decode($json, true);
            if (isset($data['title'])) {
                $title = $data['title'];
            }
        }
    } else {
        // محاولة سحب العنوان والصورة لأي موقع عادى آخر عبر الـ Open Graph
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        $html = curl_exec($ch);
        curl_close($ch);

        if ($html) {
            // استخراج العنوان (og:title أو title العادي)
            if (preg_match('/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
                $title = html_entity_decode($m[1]);
            } elseif (preg_match('/<title>(.*?)<\/title>/i', $html, $m)) {
                $title = html_entity_decode(trim($m[1]));
            }

            // استخراج الصورة (og:image)
            if (preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
                $image = $m[1];
            }
        }
    }

    return ['title' => trim($title), 'image' => trim($image)];
}
