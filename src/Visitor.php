<?php

namespace MailOptin\AdvanceAnalytics;

/**
 * What a stat row records about the visitor: their kind of device, their country and the site they came
 * from. Nothing that identifies a person is kept: no IP address, no user agent.
 */
class Visitor
{
    const PHONE = 'phone';
    const TABLET = 'tablet';
    const DESKTOP = 'desktop';

    /** Phone, tablet or desktop, from the browser's user agent. Bots and empty agents are ''. */
    public static function device($user_agent = null)
    {
        $ua = null === $user_agent ? (string)($_SERVER['HTTP_USER_AGENT'] ?? '') : (string)$user_agent;

        if ('' === trim($ua) || preg_match('/bot|crawl|spider|slurp|headless|lighthouse|preview/i', $ua)) return '';

        // Tablets first: iPads and Android tablets also mention "Mobile"-like words.
        if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle|SM-T\d|Nexus (7|9|10)/i', $ua)) return self::TABLET;
        if (preg_match('/Android/i', $ua) && ! preg_match('/Mobile/i', $ua)) return self::TABLET;

        if (preg_match('/Mobile|iPhone|iPod|Android|Windows Phone|IEMobile|Opera Mini|BlackBerry|webOS/i', $ua)) return self::PHONE;

        return self::DESKTOP;
    }

    /**
     * The visitor's country as a two-letter code, or ''. In order: the CDN's country header (Cloudflare,
     * CloudFront, Fastly, Vercel and similar), WooCommerce's geolocation when its database is set up (no
     * outside lookup), then the browser's time zone. The IP address is used only for WooCommerce's lookup
     * and never stored.
     *
     * @param string $time_zone The browser's IANA time zone, e.g. "Europe/London".
     */
    public static function country($time_zone = '')
    {
        foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_VERCEL_IP_COUNTRY', 'HTTP_FASTLY_GEO_COUNTRY_CODE', 'GEOIP_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY'] as $header) {
            $code = self::clean_code($_SERVER[$header] ?? '');
            if ('' !== $code) return $code;
        }

        if (apply_filters('mailoptin_stats_use_woocommerce_geolocation', class_exists('\WC_Geolocation'))) {
            try {
                // No fallbacks: only WooCommerce's own database (MaxMind), so no request leaves the site.
                $geo  = \WC_Geolocation::geolocate_ip('', false, false);
                $code = self::clean_code($geo['country'] ?? '');
                if ('' !== $code) return $code;
            } catch (\Throwable $e) {
            }
        }

        return self::country_from_time_zone($time_zone);
    }

    public static function country_from_time_zone($time_zone)
    {
        $time_zone = (string)$time_zone;

        if ('' === $time_zone || strlen($time_zone) > 64 || ! preg_match('#^[A-Za-z_]+(/[A-Za-z0-9_+\-]+){1,2}$#', $time_zone)) return '';

        try {
            $location = (new \DateTimeZone($time_zone))->getLocation();
        } catch (\Throwable $e) {
            return '';
        }

        return self::clean_code(is_array($location) ? ($location['country_code'] ?? '') : '');
    }

    protected static function clean_code($code)
    {
        $code = strtoupper(trim((string)$code));

        // XX, ZZ, T1 (Tor) and "??" are "unknown" in the various sources.
        if ( ! preg_match('/^[A-Z]{2}$/', $code) || in_array($code, ['XX', 'ZZ'], true)) return '';

        return $code;
    }

    /** Known sites, by the host's main name, under one name each. */
    protected static $sources = [
        'google'     => 'Google',
        'bing'       => 'Bing',
        'duckduckgo' => 'DuckDuckGo',
        'yahoo'      => 'Yahoo',
        'yandex'     => 'Yandex',
        'baidu'      => 'Baidu',
        'ecosia'     => 'Ecosia',
        'facebook'   => 'Facebook',
        'fb'         => 'Facebook',
        'instagram'  => 'Instagram',
        'pinterest'  => 'Pinterest',
        'linkedin'   => 'LinkedIn',
        'lnkd'       => 'LinkedIn',
        'reddit'     => 'Reddit',
        'youtube'    => 'YouTube',
        'tiktok'     => 'TikTok',
        'threads'    => 'Threads',
        'bsky'       => 'Bluesky',
        't'          => 'X (Twitter)',
        'twitter'    => 'X (Twitter)',
        'x'          => 'X (Twitter)',
        'chatgpt'    => 'ChatGPT',
        'perplexity' => 'Perplexity',
    ];

    /**
     * The site a visitor came from, as shown in the stats: "Direct" for no referrer, "" for this site (a
     * visitor moving between your own pages), a known site's name, or the referrer's host.
     */
    public static function source($referrer, $site_host = null)
    {
        $referrer = trim((string)$referrer);

        if ('' === $referrer) return 'Direct';

        $host = strtolower((string)wp_parse_url($referrer, PHP_URL_HOST));
        if ('' === $host) return 'Direct';

        $host      = preg_replace('/^(www\d?|m|l|lm|mobile)\./', '', $host);
        $site_host = null === $site_host ? strtolower((string)wp_parse_url(home_url(), PHP_URL_HOST)) : strtolower($site_host);
        $site_host = preg_replace('/^www\d?\./', '', $site_host);

        if ($host === $site_host) return '';

        $parts = explode('.', $host);
        // The name before the public suffix: google in google.co.uk, facebook in facebook.com.
        $name = count($parts) >= 3 && strlen($parts[count($parts) - 2]) <= 3 ? $parts[count($parts) - 3] : $parts[max(0, count($parts) - 2)];

        if (isset(self::$sources[$name])) return self::$sources[$name];

        return substr($host, 0, 100);
    }

    /** A page as the stats group it: the address without its query string or fragment. */
    public static function page($url)
    {
        $url = trim((string)$url);
        if ('' === $url) return '';

        $url = preg_replace('/[?#].*$/', '', $url);

        return substr(untrailingslashit($url) ?: $url, 0, 191);
    }
}
