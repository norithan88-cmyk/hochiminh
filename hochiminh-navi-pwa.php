<?php
/**
 * Plugin Name: ホーチミンナビ PWA
 * Description: ホーチミンナビをAndroid・iPhoneのホーム画面に追加できるようにします。
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

add_action('init', function () {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

    if ($path === '/hochiminh-navi.webmanifest') {
        nocache_headers();
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo wp_json_encode([
            'name' => 'ホーチミンナビ',
            'short_name' => 'ホーチミンナビ',
            'description' => 'ベトナム・ホーチミン市の生活・観光サポートサイト',
            'start_url' => '/?source=app',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#f4faf7',
            'theme_color' => '#173c5a',
            'icons' => [
                ['src' => 'https://anjo-izumi.life/wp-content/uploads/2026/08/mion-radio-avatar.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => 'https://anjo-izumi.life/wp-content/uploads/2026/08/mion-radio-avatar.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable']
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($path === '/hochiminh-navi-sw.js') {
        nocache_headers();
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: /');
        echo "const CACHE='hochiminh-navi-v1';\n";
        echo "self.addEventListener('install',e=>{self.skipWaiting();e.waitUntil(caches.open(CACHE).then(c=>c.addAll(['/'])))});\n";
        echo "self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()))});\n";
        echo "self.addEventListener('fetch',e=>{if(e.request.method!=='GET')return;e.respondWith(fetch(e.request).then(r=>{const x=r.clone();caches.open(CACHE).then(c=>c.put(e.request,x));return r}).catch(()=>caches.match(e.request).then(r=>r||caches.match('/'))))});\n";
        exit;
    }
}, 0);

add_action('wp_head', function () {
    echo '<link rel="manifest" href="/hochiminh-navi.webmanifest">' . "\n";
    echo '<meta name="theme-color" content="#173c5a">' . "\n";
    echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-title" content="ホーチミンナビ">' . "\n";
    echo '<link rel="apple-touch-icon" href="https://anjo-izumi.life/wp-content/uploads/2026/08/mion-radio-avatar.png">' . "\n";
    echo '<script>if("serviceWorker" in navigator){window.addEventListener("load",function(){navigator.serviceWorker.register("/hochiminh-navi-sw.js")})}</script>' . "\n";
});

/* 「今日の3行まとめ」をサーバー側で実データに書き換える（検索エンジン向けに生HTMLを毎日更新） */
function hochiminh_navi_weather_label($code) {
    if ($code === 0) return '快晴';
    if ($code <= 2) return '晴れ時々くもり';
    if ($code === 3) return 'くもり';
    if ($code >= 45 && $code <= 48) return '霧';
    if (($code >= 51 && $code <= 67) || ($code >= 80 && $code <= 82)) return '雨';
    if (($code >= 71 && $code <= 77) || ($code >= 85 && $code <= 86)) return '雪';
    if ($code >= 95) return '雷雨';
    return '変わりやすい天気';
}

function hochiminh_navi_build_summary_lines() {
    $cache_key = 'hochiminh_navi_summary_lines_v1';
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $lines = [null, null, null];

    // ① 天気（Open-Meteo、ホーチミン市中心部の座標）
    $weather = wp_remote_get('https://api.open-meteo.com/v1/forecast?latitude=10.7769&longitude=106.7009&daily=weather_code,temperature_2m_max,temperature_2m_min&timezone=Asia%2FHo_Chi_Minh&forecast_days=1', ['timeout' => 5]);
    if (!is_wp_error($weather)) {
        $body = json_decode(wp_remote_retrieve_body($weather), true);
        if (isset($body['daily']['weather_code'][0])) {
            $label = hochiminh_navi_weather_label((int) $body['daily']['weather_code'][0]);
            $high = round((float) $body['daily']['temperature_2m_max'][0]);
            $low = round((float) $body['daily']['temperature_2m_min'][0]);
            $lines[0] = 'ホーチミンは' . $label . '、最高' . $high . '度・最低' . $low . '度の予想です';
        }
    }

    // ② 固定案内（在住者向け情報）
    $lines[1] = '病院の緊急連絡先・ベトナム語コピペ集などの生活情報をご案内しています';

    // ③ 固定案内（観光情報）
    $lines[2] = 'ベンタイン市場や戦争証跡博物館などの観光スポット情報もご案内しています';

    set_transient($cache_key, $lines, 20 * MINUTE_IN_SECONDS);
    return $lines;
}

add_action('template_redirect', function () {
    if (is_admin()) return;
    if (!is_front_page() && !is_home()) return;
    ob_start(function ($html) {
        $lines = hochiminh_navi_build_summary_lines();
        $ids = ['iz-sum-1', 'iz-sum-2', 'iz-sum-3'];
        foreach ($ids as $index => $id) {
            if (empty($lines[$index])) continue;
            $escaped = esc_html($lines[$index]);
            $html = preg_replace(
                '/(<li id="' . preg_quote($id, '/') . '">)[^<]*(<\/li>)/u',
                '$1' . str_replace('$', '\$', $escaped) . '$2',
                $html,
                1
            );
        }
        return $html;
    });
});
