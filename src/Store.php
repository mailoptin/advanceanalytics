<?php

namespace MailOptin\AdvanceAnalytics;

/**
 * Where the stats live, and how long.
 *
 * - The detail table (one row per view, subscription and two-step answer) keeps 40 days, as it always has.
 * - Each night, every finished day is summed into the daily table: one count per campaign, kind of event
 *   and value of each breakdown (device, country, page, source). Those totals are kept for 13 months, so
 *   longer periods stay fast without keeping every row.
 * - On upgrade, the days already in the detail table are summed the same way, so history starts with them.
 */
class Store
{
    const DB_VERSION = 2;
    const DB_VERSION_OPTION = 'mo_advance_analytics_db_version';
    const LAST_ROLLUP_OPTION = 'mo_advance_analytics_rolled_up_to';

    const DETAIL_DAYS = 40;
    const DAILY_DAYS = 400;

    const DIMENSIONS = ['device', 'country', 'page', 'source'];
    const STAT_TYPES = ['impression', 'conversion', 'twostep_yes', 'twostep_done'];

    public static function daily_table()
    {
        global $wpdb;

        return $wpdb->prefix . 'mo_optin_stat_daily';
    }

    /** Brings the tables up to date once per version: adds the two columns and the daily table, then sums the days already stored. */
    public static function maybe_upgrade()
    {
        if ((int)get_option(self::DB_VERSION_OPTION, 1) >= self::DB_VERSION) return;

        global $wpdb;

        $detail = AdvanceAnalytics::advance_stat_table_name();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($detail))) !== $detail) {
            AdvanceAnalytics::create_stat_table();
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM $detail", 0);
        if ( ! in_array('device', $columns, true)) $wpdb->query("ALTER TABLE $detail ADD COLUMN device varchar(10) NOT NULL DEFAULT ''");
        if ( ! in_array('country', $columns, true)) $wpdb->query("ALTER TABLE $detail ADD COLUMN country char(2) NOT NULL DEFAULT ''");

        self::create_daily_table();

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);

        self::roll_up();
    }

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
            count int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY day_value (date, optin_id, stat_type, dimension, value),
            KEY date_dimension (date, dimension)
        ) $collate");
    }

    /** "Today" for the stats, in the site's time zone: rows are dated with it. */
    public static function today()
    {
        return current_time('Y-m-d');
    }

    /**
     * One day's totals from the detail table: [optin_id, stat_type, dimension, value, count] rows, one set
     * per breakdown plus a "total". Used to fill the daily table and to show today live.
     *
     * @param string $date Y-m-d
     * @param int $optin_id 0 for every campaign.
     */
    public static function day_from_detail($date, $optin_id = 0)
    {
        global $wpdb;

        $detail = AdvanceAnalytics::advance_stat_table_name();
        $where  = $wpdb->prepare('date = %s', $date) . ($optin_id ? $wpdb->prepare(' AND optin_id = %d', $optin_id) : '');

        $rows = $wpdb->get_results(
            "SELECT optin_id, stat_type, device, country, conversion_page, referrer, COUNT(*) AS n
             FROM $detail WHERE $where GROUP BY optin_id, stat_type, device, country, conversion_page, referrer",
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
            $add($r['optin_id'], $type, 'page', Visitor::page($r['conversion_page']), $n);
            $add($r['optin_id'], $type, 'source', Visitor::source($r['referrer'], $site_host), $n);
        }

        return array_values($sum);
    }

    /** Sums one finished day into the daily table, replacing what was there (safe to run twice). */
    public static function roll_up_day($date)
    {
        global $wpdb;

        $table = self::daily_table();
        $rows  = self::day_from_detail($date);

        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE date = %s", $date));

        foreach (array_chunk($rows, 200) as $chunk) {
            $values = [];
            foreach ($chunk as $r) {
                $values[] = $wpdb->prepare('(%s, %d, %s, %s, %s, %d)', $date, $r[0], $r[1], $r[2], $r[3], $r[4]);
            }
            $wpdb->query("INSERT INTO $table (date, optin_id, stat_type, dimension, value, count) VALUES " . implode(',', $values));
        }
    }

    /**
     * Sums every finished day not summed yet (all of them on the first run), up to yesterday. Days before
     * the detail table's oldest row have nothing to sum.
     */
    public static function roll_up()
    {
        global $wpdb;

        $detail    = AdvanceAnalytics::advance_stat_table_name();
        $yesterday = gmdate('Y-m-d', strtotime(self::today() . ' -1 day'));
        $last      = (string)get_option(self::LAST_ROLLUP_OPTION, '');
        $oldest    = (string)$wpdb->get_var("SELECT MIN(date) FROM $detail");

        if ('' === $oldest) {
            update_option(self::LAST_ROLLUP_OPTION, $yesterday, false);

            return;
        }

        $from = '' !== $last ? gmdate('Y-m-d', strtotime($last . ' +1 day')) : $oldest;
        if ($from < $oldest) $from = $oldest;

        for ($day = $from; $day <= $yesterday; $day = gmdate('Y-m-d', strtotime($day . ' +1 day'))) {
            self::roll_up_day($day);
        }

        update_option(self::LAST_ROLLUP_OPTION, $yesterday, false);
    }

    /** Old rows go: detail after 40 days (once summed), daily totals after 13 months. */
    public static function clean_up()
    {
        global $wpdb;

        $detail = AdvanceAnalytics::advance_stat_table_name();
        $daily  = self::daily_table();
        $today  = self::today();

        $wpdb->query($wpdb->prepare("DELETE FROM $detail WHERE date <= %s", gmdate('Y-m-d', strtotime($today . ' -' . self::DETAIL_DAYS . ' days'))));
        $wpdb->query($wpdb->prepare("DELETE FROM $daily WHERE date <= %s", gmdate('Y-m-d', strtotime($today . ' -' . self::DAILY_DAYS . ' days'))));
    }

    /** "Clear stats": everything, in both tables. */
    public static function clear_all()
    {
        global $wpdb;

        $wpdb->query('TRUNCATE ' . AdvanceAnalytics::advance_stat_table_name());
        $wpdb->query('TRUNCATE ' . self::daily_table());
        delete_option(self::LAST_ROLLUP_OPTION);
    }
}
