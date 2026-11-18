<?php

namespace MailOptin\AdvanceAnalytics;

/**
 * Where the stats live, and how long.
 *
 * - The detail table (one row per view, conversion and two-step answer) keeps 40 days, as it always has.
 * - Each night, every finished day is summed into the daily table: one count per campaign, kind of event
 *   and value of each breakdown (device, country, page, source). Those totals are kept for 13 months, so
 *   longer periods stay fast without keeping every row.
 * - On upgrade, the days already in the detail table are summed the same way (by cron, a few at a time),
 *   so history starts with them.
 */
class Store
{
    const DB_VERSION = 2;
    const DB_VERSION_OPTION = 'mo_advance_analytics_db_version';
    const LAST_ROLLUP_OPTION = 'mo_advance_analytics_rolled_up_to';
    const UPGRADE_LOCK = 'mo_advance_analytics_upgrading';
    const ROLLUP_LOCK = 'mo_advance_analytics_rolling_up';
    const ROLLUP_HOOK = 'mo_advance_analytic_rollup';
    const CACHE_GEN_OPTION = 'mo_advance_analytics_cache_gen';

    const DETAIL_DAYS = 40;
    const DAILY_DAYS = 400;

    /** Seconds one roll-up run may take before leaving the rest to the next. */
    const ROLLUP_BUDGET = 20;

    const DIMENSIONS = ['device', 'country', 'page', 'source'];
    const STAT_TYPES = ['impression', 'conversion', 'twostep_yes', 'twostep_done'];

    public static function daily_table()
    {
        global $wpdb;

        return $wpdb->prefix . 'mo_optin_stat_daily';
    }

    /** Whether the detail table has the device and country columns yet: until then, rows are written without them. */
    public static function has_new_columns()
    {
        return (int)get_option(self::DB_VERSION_OPTION, 1) >= self::DB_VERSION;
    }

    /**
     * A short lock in the options table: add_option() fails when the row exists, so only one request gets
     * it. A lock older than $ttl seconds is treated as left behind by a request that died.
     */
    protected static function lock($name, $ttl)
    {
        if (add_option($name, time(), '', 'no')) return true;

        if ((int)get_option($name) < time() - $ttl) {
            delete_option($name);

            return add_option($name, time(), '', 'no');
        }

        return false;
    }

    /**
     * Brings the tables up to date once: both new columns in one ALTER, and the daily table. One request does
     * it, under a lock; the days already stored are summed afterwards by cron, not in this request.
     */
    public static function maybe_upgrade()
    {
        if (self::has_new_columns()) return;

        // Not in a visitor's AJAX request: only in a page load in wp-admin, or cron.
        if (wp_doing_ajax()) return;

        if ( ! self::lock(self::UPGRADE_LOCK, 15 * MINUTE_IN_SECONDS)) return;

        global $wpdb;

        $detail = AdvanceAnalytics::advance_stat_table_name();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($detail))) !== $detail) {
            AdvanceAnalytics::create_stat_table();
        }

        $columns = (array)$wpdb->get_col("SHOW COLUMNS FROM $detail", 0);
        $add     = [];
        if ( ! in_array('device', $columns, true)) $add[] = "ADD COLUMN device varchar(10) NOT NULL DEFAULT ''";
        if ( ! in_array('country', $columns, true)) $add[] = "ADD COLUMN country char(2) NOT NULL DEFAULT ''";

        $ok = ! $add || false !== $wpdb->query("ALTER TABLE $detail " . implode(', ', $add));

        self::create_daily_table();

        if ($ok) {
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION, true);
            if ( ! wp_next_scheduled(self::ROLLUP_HOOK)) wp_schedule_single_event(time(), self::ROLLUP_HOOK);
        }

        delete_option(self::UPGRADE_LOCK);
    }

    /**
     * The value's hash is in the unique key, not the value: it keeps the key short enough for older MySQL
     * (767-byte index limit) and tells apart values that differ only in letter case.
     */
    public static function create_daily_table()
    {
        global $wpdb;

        $table   = self::daily_table();
        $collate = $wpdb->has_cap('collation') ? $wpdb->get_charset_collate() : '';

        $wpdb->query("CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            date date NOT NULL,
            optin_id bigint(20) unsigned NOT NULL DEFAULT 0,
            stat_type varchar(20) NOT NULL,
            dimension varchar(10) NOT NULL,
            value varchar(191) NOT NULL DEFAULT '',
            value_hash char(32) CHARACTER SET ascii NOT NULL,
            count int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY day_value (date, optin_id, stat_type, dimension, value_hash),
            KEY date_dimension (date, dimension)
        ) $collate");
    }

    /** "Today" for the stats, in the site's time zone: rows are dated with it. */
    public static function today()
    {
        return current_time('Y-m-d');
    }

    /** The last day summed into the daily table, or '' when none has been. */
    public static function rolled_up_to()
    {
        return (string)get_option(self::LAST_ROLLUP_OPTION, '');
    }

    /**
     * One day's totals from the detail table: [optin_id, stat_type, dimension, value, count] rows, one set
     * per breakdown plus a "total". Used to fill the daily table and to show days not summed yet.
     *
     * Pages are grouped without their query string and sources by the referrer's host, in SQL, so a busy
     * day doesn't build a row for every distinct address; as exact bytes, so /Blog and /blog stay apart.
     *
     * @param string $date Y-m-d
     * @param int $optin_id 0 for every campaign.
     */
    public static function day_from_detail($date, $optin_id = 0)
    {
        global $wpdb;

        $detail = AdvanceAnalytics::advance_stat_table_name();
        $where  = $wpdb->prepare('date = %s', $date) . ($optin_id ? $wpdb->prepare(' AND optin_id = %d', $optin_id) : '');
        $extra  = self::has_new_columns() ? 'device, country' : "'' AS device, '' AS country";

        $rows = $wpdb->get_results(
            "SELECT optin_id, stat_type, $extra,
                CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(conversion_page, '#', 1), '?', 1) AS BINARY) AS page,
                CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '/', 3), '?', 1), '#', 1) AS BINARY) AS ref_host,
                COUNT(*) AS n
             FROM $detail WHERE $where
             GROUP BY optin_id, stat_type, device, country, page, ref_host",
            ARRAY_A
        );

        $sum       = [];
        $site_host = (string)wp_parse_url(home_url(), PHP_URL_HOST);

        $add = function ($optin, $type, $dimension, $value, $n) use (&$sum) {
            $key = $optin . "\0" . $type . "\0" . $dimension . "\0" . $value;
            if ( ! isset($sum[$key])) $sum[$key] = [(int)$optin, $type, $dimension, (string)$value, 0];
            $sum[$key][4] += (int)$n;
        };

        foreach ((array)$rows as $r) {
            $type = (string)$r['stat_type'];
            if ( ! in_array($type, self::STAT_TYPES, true)) continue;

            $n = (int)$r['n'];
            $add($r['optin_id'], $type, 'total', '', $n);
            $add($r['optin_id'], $type, 'device', (string)$r['device'], $n);
            $add($r['optin_id'], $type, 'country', (string)$r['country'], $n);
            $add($r['optin_id'], $type, 'page', Visitor::page($r['page']), $n);
            $add($r['optin_id'], $type, 'source', Visitor::source($r['ref_host'], $site_host), $n);
        }

        return array_values($sum);
    }

    /** The same, cached for five minutes: the page reads today on every load. */
    public static function day_from_detail_cached($date, $optin_id = 0)
    {
        $key  = 'mo_stats_day_' . md5($date . '|' . absint($optin_id) . '|' . (int)get_option(self::CACHE_GEN_OPTION, 0));
        $rows = get_transient($key);

        if ( ! is_array($rows)) {
            $rows = self::day_from_detail($date, $optin_id);
            set_transient($key, $rows, 5 * MINUTE_IN_SECONDS);
        }

        return $rows;
    }

    /**
     * Sums one finished day into the daily table, replacing what was there (safe to run twice).
     *
     * @return bool False when a write failed: the day isn't marked as summed, so its detail isn't deleted.
     */
    public static function roll_up_day($date)
    {
        global $wpdb;

        $table = self::daily_table();
        $rows  = self::day_from_detail($date);

        if (false === $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE date = %s", $date))) return false;

        foreach (array_chunk($rows, 200) as $chunk) {
            $values = [];
            foreach ($chunk as $r) {
                $value    = mb_substr($r[3], 0, 191);
                $values[] = $wpdb->prepare('(%s, %d, %s, %s, %s, %s, %d)', $date, $r[0], $r[1], $r[2], $value, md5($r[3]), $r[4]);
            }

            $sql = "INSERT INTO $table (date, optin_id, stat_type, dimension, value, value_hash, count) VALUES " . implode(',', $values)
                   . ' ON DUPLICATE KEY UPDATE count = count + VALUES(count)';

            if (false === $wpdb->query($sql)) return false;
        }

        return true;
    }

    /**
     * Sums every finished day not summed yet, up to yesterday, oldest first; under a lock, and for at most
     * ROLLUP_BUDGET seconds, leaving the rest to a run a minute later. Run by cron.
     */
    public static function roll_up()
    {
        if ( ! self::has_new_columns()) return;
        if ( ! self::lock(self::ROLLUP_LOCK, 10 * MINUTE_IN_SECONDS)) return;

        global $wpdb;

        $detail    = AdvanceAnalytics::advance_stat_table_name();
        $yesterday = gmdate('Y-m-d', strtotime(self::today() . ' -1 day'));
        $last      = self::rolled_up_to();
        $oldest    = (string)$wpdb->get_var("SELECT MIN(date) FROM $detail");
        $started   = microtime(true);

        if ('' === $oldest) {
            update_option(self::LAST_ROLLUP_OPTION, $yesterday, false);
            delete_option(self::ROLLUP_LOCK);

            return;
        }

        $from = '' !== $last ? gmdate('Y-m-d', strtotime($last . ' +1 day')) : $oldest;
        if ($from < $oldest) $from = $oldest;

        for ($day = $from; $day <= $yesterday; $day = gmdate('Y-m-d', strtotime($day . ' +1 day'))) {
            if (microtime(true) - $started > self::ROLLUP_BUDGET) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::ROLLUP_HOOK);
                break;
            }

            if ( ! self::roll_up_day($day)) break;

            update_option(self::LAST_ROLLUP_OPTION, $day, false);
        }

        delete_option(self::ROLLUP_LOCK);
    }

    /**
     * Old rows go: detail after 40 days, but never a day not yet summed into the daily totals; daily
     * totals after 13 months.
     */
    public static function clean_up()
    {
        global $wpdb;

        $detail = AdvanceAnalytics::advance_stat_table_name();
        $daily  = self::daily_table();
        $today  = self::today();
        $cutoff = gmdate('Y-m-d', strtotime($today . ' -' . self::DETAIL_DAYS . ' days'));
        $summed = self::rolled_up_to();

        if ('' !== $summed) {
            $wpdb->query($wpdb->prepare("DELETE FROM $detail WHERE date <= %s", min($cutoff, $summed)));
        }

        $wpdb->query($wpdb->prepare("DELETE FROM $daily WHERE date <= %s", gmdate('Y-m-d', strtotime($today . ' -' . self::DAILY_DAYS . ' days'))));
    }

    /** "Clear stats": everything, in both tables. */
    public static function clear_all()
    {
        global $wpdb;

        $wpdb->query('TRUNCATE ' . AdvanceAnalytics::advance_stat_table_name());
        $wpdb->query('TRUNCATE ' . self::daily_table());
        delete_option(self::LAST_ROLLUP_OPTION);
        update_option(self::CACHE_GEN_OPTION, (int)get_option(self::CACHE_GEN_OPTION, 0) + 1, false);
    }
}
