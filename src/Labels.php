<?php

namespace MailOptin\AdvanceAnalytics;

/** How the stored values read on the Statistics page. */
class Labels
{
    public static function not_recorded()
    {
        return __('Not recorded', 'mailoptin');
    }

    public static function device($value)
    {
        switch ($value) {
            case Visitor::PHONE:
                return __('Phone', 'mailoptin');
            case Visitor::TABLET:
                return __('Tablet', 'mailoptin');
            case Visitor::DESKTOP:
                return __('Desktop', 'mailoptin');
        }

        return self::not_recorded();
    }

    public static function country($code)
    {
        if ('' === (string)$code) return self::not_recorded();

        $name = function_exists('MailOptin\Core\country_code_to_name') ? \MailOptin\Core\country_code_to_name($code) : '';

        return $name ?: $code;
    }

    /** A page's title where it's a post or page on this site, else its address without the domain. */
    public static function page($url)
    {
        if ('' === (string)$url) return self::not_recorded();

        $key   = 'mo_stats_title_' . md5($url);
        $title = get_transient($key);

        if (false === $title) {
            $home = untrailingslashit(home_url());
            if ($url === $home || $url . '/' === home_url('/')) {
                $title = __('Home page', 'mailoptin');
            } else {
                $id    = url_to_postid($url);
                $title = $id ? wp_strip_all_tags(get_the_title($id)) : '';
                if ('' === trim($title)) {
                    $path  = (string)wp_parse_url($url, PHP_URL_PATH);
                    $title = '' !== $path && '/' !== $path ? $path : $url;
                }
            }
            set_transient($key, $title, DAY_IN_SECONDS);
        }

        return $title;
    }

    public static function source($value)
    {
        if ('' === (string)$value) return __('Your own pages', 'mailoptin');
        if ('Direct' === $value) return __('Direct', 'mailoptin');

        return $value;
    }

    /** A line under a source's name, where it needs one. */
    public static function source_hint($value)
    {
        if ('' === (string)$value) return __('Moved here from another page on your site', 'mailoptin');
        if ('Direct' === $value) return __('Typed in, bookmarked or from an app', 'mailoptin');

        return '';
    }

    public static function optin_type($type)
    {
        $types = [
            'lightbox' => __('Pop-up', 'mailoptin'),
            'slidein'  => __('Slide-in', 'mailoptin'),
            'bar'      => __('Bar', 'mailoptin'),
            'inpost'   => __('In-post', 'mailoptin'),
            'sidebar'  => __('Sidebar', 'mailoptin'),
        ];

        return $types[$type] ?? ucfirst((string)$type);
    }
}
