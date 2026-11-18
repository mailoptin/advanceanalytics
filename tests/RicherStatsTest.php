<?php

namespace MailOptin\Tests\AdvanceAnalytics;

use MailOptin\AdvanceAnalytics\AdvanceAnalytics;
use MailOptin\AdvanceAnalytics\Report;
use MailOptin\AdvanceAnalytics\StatsPage;
use MailOptin\AdvanceAnalytics\Store;
use MailOptin\AdvanceAnalytics\Tips;
use MailOptin\AdvanceAnalytics\Visitor;
use MailOptin\Core\Repositories\OptinCampaignsRepository as OCR;
use WP_UnitTestCase;

/**
 * Device, country and source; the daily totals and how long things are kept; the report's numbers; the
 * tips; and the page itself.
 */
class RicherStatsTest extends WP_UnitTestCase
{
    public function set_up()
    {
        parent::set_up();
        global $wpdb;
        $wpdb->query('DELETE FROM ' . AdvanceAnalytics::advance_stat_table_name());
        $wpdb->query('DELETE FROM ' . Store::daily_table());
        delete_option(Store::LAST_ROLLUP_OPTION);
        unset($_SERVER['HTTP_CF_IPCOUNTRY'], $_REQUEST['stat_data']);
    }

    protected function day($offset)
    {
        return gmdate('Y-m-d', strtotime(Store::today() . ' ' . $offset . ' days'));
    }

    /** Detail rows as the tracking would write them. */
    protected function rows($date, $optin, $type, $n, $device = 'desktop', $country = 'GB', $page = 'https://example.org/a/?x=1', $referrer = 'https://www.google.co.uk/')
    {
        global $wpdb;
        for ($i = 0; $i < $n; $i++) {
            $wpdb->insert(AdvanceAnalytics::advance_stat_table_name(), [
                'date' => $date, 'stat_type' => $type, 'optin_id' => $optin, 'optin_type' => 'lightbox',
                'conversion_page' => $page, 'referrer' => $referrer, 'device' => $device, 'country' => $country,
            ]);
        }
    }

    public function test_device_from_the_user_agent()
    {
        $this->assertSame('phone', Visitor::device('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148'));
        $this->assertSame('phone', Visitor::device('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36'));
        $this->assertSame('tablet', Visitor::device('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148'));
        $this->assertSame('tablet', Visitor::device('Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 Chrome/120 Safari/537.36'));
        $this->assertSame('desktop', Visitor::device('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131 Safari/537.36'));
        $this->assertSame('', Visitor::device('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'), 'Bots aren\'t a device.');
        $this->assertSame('', Visitor::device(''));
    }

    public function test_country_from_the_cdn_else_the_time_zone()
    {
        $this->assertSame('GB', Visitor::country_from_time_zone('Europe/London'));
        $this->assertSame('AR', Visitor::country_from_time_zone('America/Argentina/Buenos_Aires'), 'Three-part zones work.');
        $this->assertSame('', Visitor::country_from_time_zone('UTC'));
        $this->assertSame('IN', Visitor::country_from_time_zone('Asia/Calcutta'), 'Old names browsers still send.');
        $this->assertSame('', Visitor::country_from_time_zone('../../etc/passwd'));
        $this->assertSame('', Visitor::country_from_time_zone('Mars/Olympus'));

        add_filter('mailoptin_stats_use_woocommerce_geolocation', '__return_false');
        $this->assertSame('JP', Visitor::country('Asia/Tokyo'));

        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'de';
        $this->assertSame('DE', Visitor::country('Asia/Tokyo'), 'The CDN knows better than the time zone.');
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX';
        $this->assertSame('JP', Visitor::country('Asia/Tokyo'), 'Cloudflare\'s "unknown" is skipped.');
        remove_filter('mailoptin_stats_use_woocommerce_geolocation', '__return_false');
    }

    public function test_sources_and_pages()
    {
        $this->assertSame('Google', Visitor::source('https://www.google.co.uk/', 'example.org'));
        $this->assertSame('Google', Visitor::source('https://news.google.com/x', 'example.org'));
        $this->assertSame('Facebook', Visitor::source('https://l.facebook.com/l.php?u=1', 'example.org'));
        $this->assertSame('X (Twitter)', Visitor::source('https://t.co/abc', 'example.org'));
        $this->assertSame('Direct', Visitor::source('', 'example.org'));
        $this->assertSame('', Visitor::source('https://www.example.org/other/', 'example.org'), 'Moving between your own pages.');
        $this->assertSame('news.ycombinator.com', Visitor::source('https://news.ycombinator.com/item?id=1', 'example.org'));

        $this->assertSame('https://example.org/a', Visitor::page('https://example.org/a/?utm_source=x#top'));
    }

    public function test_finished_days_are_summed_once_and_today_stays_live()
    {
        $this->rows($this->day(-3), 7, 'impression', 10, 'phone', 'US');
        $this->rows($this->day(-3), 7, 'conversion', 1, 'phone', 'US');
        $this->rows($this->day(-1), 7, 'impression', 20, 'desktop', 'GB');
        $this->rows($this->day(-1), 7, 'conversion', 4, 'desktop', 'GB');
        $this->rows(Store::today(), 7, 'impression', 5, 'tablet', '');

        Store::roll_up();
        Store::roll_up_day($this->day(-1)); // Again: no double counting.

        global $wpdb;
        $daily = Store::daily_table();
        $this->assertSame('30', $wpdb->get_var("SELECT SUM(count) FROM $daily WHERE dimension = 'total' AND stat_type = 'impression'"), 'Today isn\'t summed until it\'s over.');
        $this->assertSame($this->day(-1), get_option(Store::LAST_ROLLUP_OPTION));

        $report = Report::last_days(7, 7);
        $this->assertSame(['impression' => 35, 'conversion' => 5, 'twostep_yes' => 0, 'twostep_done' => 0], $report->totals(), 'Daily totals plus today, live.');

        $series = $report->series();
        $this->assertCount(7, $series, 'Every day of the period, empty ones too.');
        $this->assertSame(5, end($series)['impression']);
        $this->assertSame(0, $series[0]['impression']);

        $devices = array_column($report->breakdown('device'), 'impression', 'value');
        $this->assertSame(['desktop' => 20, 'phone' => 10, 'tablet' => 5], $devices, 'Most views first.');

        $sources = $report->breakdown('source');
        $this->assertSame('Google', $sources[0]['value']);
        $this->assertSame('https://example.org/a', $report->breakdown('page')[0]['value'], 'Pages without their query string.');

        $this->assertSame(0, Report::last_days(7, 8)->totals()['impression'], 'One campaign only.');
    }

    public function test_previous_period_and_best_device()
    {
        $this->rows($this->day(-10), 3, 'impression', 4);
        Store::roll_up();

        $report = Report::last_days(7, 0);
        $this->assertSame($this->day(-13), $report->previous()->start);
        $this->assertSame($this->day(-7), $report->previous()->end);
        $this->assertSame(4, $report->previous()->totals()['impression']);

        $this->rows($this->day(-1), 3, 'impression', 200, 'desktop');
        $this->rows($this->day(-1), 3, 'conversion', 10, 'desktop');
        $this->rows($this->day(-1), 3, 'impression', 200, 'phone');
        $this->rows($this->day(-1), 3, 'conversion', 2, 'phone');
        $this->rows($this->day(-1), 3, 'impression', 50, 'tablet');
        $this->rows($this->day(-1), 3, 'conversion', 10, 'tablet');
        delete_option(Store::LAST_ROLLUP_OPTION);
        Store::roll_up();

        $best = Report::last_days(7)->best_devices();
        $this->assertSame('desktop', $best[3]['value'], 'Tablets convert better here, but 50 views are too few to say.');
    }

    public function test_old_rows_go()
    {
        $this->rows($this->day(-41), 1, 'impression', 1);
        $this->rows($this->day(-39), 1, 'impression', 1);
        global $wpdb;
        $wpdb->insert(Store::daily_table(), ['date' => $this->day(-401), 'optin_id' => 1, 'stat_type' => 'impression', 'dimension' => 'total', 'value' => '', 'value_hash' => md5(''), 'count' => 3]);
        $wpdb->insert(Store::daily_table(), ['date' => $this->day(-399), 'optin_id' => 1, 'stat_type' => 'impression', 'dimension' => 'total', 'value' => '', 'value_hash' => md5(''), 'count' => 3]);

        Store::clean_up();
        $this->assertSame('2', $wpdb->get_var('SELECT COUNT(*) FROM ' . AdvanceAnalytics::advance_stat_table_name()), 'Nothing summed yet: no detail is deleted.');

        update_option(Store::LAST_ROLLUP_OPTION, $this->day(-1));
        Store::clean_up();

        $this->assertSame('1', $wpdb->get_var('SELECT COUNT(*) FROM ' . AdvanceAnalytics::advance_stat_table_name()));
        $this->assertSame('1', $wpdb->get_var('SELECT COUNT(*) FROM ' . Store::daily_table()));
    }

    public function test_pages_differing_only_in_case_are_kept_apart()
    {
        $this->rows($this->day(-1), 5, 'impression', 3, 'desktop', 'GB', 'https://example.org/Blog/post');
        $this->rows($this->day(-1), 5, 'impression', 2, 'desktop', 'GB', 'https://example.org/blog/post');

        $this->assertTrue(Store::roll_up_day($this->day(-1)));

        $pages = array_column(Report::last_days(7, 5)->breakdown('page'), 'impression', 'value');
        $this->assertSame(['https://example.org/Blog/post' => 3, 'https://example.org/blog/post' => 2], $pages);
        $this->assertSame(5, Report::last_days(7, 5)->totals()['impression']);
    }

    public function test_days_cron_has_not_reached_are_read_live()
    {
        $this->rows($this->day(-2), 6, 'impression', 4);
        $this->rows($this->day(-1), 6, 'impression', 6);
        update_option(Store::LAST_ROLLUP_OPTION, $this->day(-3));

        $this->assertSame(10, Report::last_days(7, 6)->totals()['impression']);
    }

    public function test_views_are_kept_before_the_upgrade_has_run()
    {
        update_option(Store::DB_VERSION_OPTION, 1);
        $this->assertNotFalse(\MailOptin\AdvanceAnalytics\AnalyticsRepository::add('impression', ['optin_id' => 9, 'optin_type' => 'lightbox', 'conversion_page' => str_repeat('é', 300), 'referrer' => '']));
        update_option(Store::DB_VERSION_OPTION, Store::DB_VERSION);

        global $wpdb;
        $page = $wpdb->get_var('SELECT conversion_page FROM ' . AdvanceAnalytics::advance_stat_table_name() . ' WHERE optin_id = 9');
        $this->assertSame(256, mb_strlen($page), 'Cut by characters, not bytes.');
    }

    protected function device_rows($phone_views, $phone_subs, $desktop_views, $desktop_subs)
    {
        return [
            ['value' => 'phone', 'impression' => $phone_views, 'conversion' => $phone_subs],
            ['value' => 'desktop', 'impression' => $desktop_views, 'conversion' => $desktop_subs],
            ['value' => '', 'impression' => 99999, 'conversion' => 1],
        ];
    }

    public function test_tips_only_on_big_trustworthy_differences()
    {
        wp_set_current_user(1);
        $tip = Tips::device($this->device_rows(1000, 10, 1000, 40));
        $this->assertNotNull($tip);
        $this->assertStringContainsString('Phones', $tip['headline']);
        $this->assertStringContainsString('a quarter or less of desktop', $tip['headline']);
        $this->assertStringContainsString('get 50% of views', $tip['headline'], 'Shares of the views where the device is known.');

        $this->assertNull(Tips::device($this->device_rows(400, 1, 1000, 40)), 'Under 500 views is too few.');
        $this->assertNull(Tips::device($this->device_rows(1000, 25, 1000, 40)), 'Under double is not worth a tip.');

        Tips::dismiss($tip['key']);
        $this->assertNull(Tips::device($this->device_rows(1000, 10, 1000, 40)), 'Dismissed.');
        $this->assertNotNull(Tips::device($this->device_rows(1000, 10, 1000, 60)), 'It comes back when the numbers change a lot.');

        $pages = [['value' => 'https://example.org/tulips', 'impression' => 600, 'conversion' => 36], ['value' => 'https://example.org/b', 'impression' => 5000, 'conversion' => 50]];
        $this->assertNotNull(Tips::standout('page', $pages, 0.015));
        $this->assertNull(Tips::standout('page', $pages, 0.04));

        $this->assertNotNull(Tips::twostep(200, 60));
        $this->assertNull(Tips::twostep(200, 120), 'Most finished.');
        $this->assertNull(Tips::twostep(50, 5), 'Too few.');
    }

    public function test_two_step_answers_and_message_clicks_are_recorded()
    {
        $id = OCR::add_optin_campaign(wp_generate_password(10, false), 'Stats ' . wp_generate_password(4, false), 'BareMetal', 'lightbox');

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';
        $_REQUEST['stat_data']      = ['optin_uuid' => 'x', 'conversion_page' => 'https://example.org/p/', 'referrer' => '', 'tz' => 'Europe/Dublin'];
        add_filter('mailoptin_stats_use_woocommerce_geolocation', '__return_false');

        do_action('mailoptin_twostep_yes', $id);
        do_action('mailoptin_twostep_done', $id);
        do_action('mailoptin_message_clicked', $id);

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT stat_type, device, country, conversion_page FROM ' . AdvanceAnalytics::advance_stat_table_name() . ' WHERE optin_id = %d ORDER BY id', $id), ARRAY_A);

        $this->assertSame(['twostep_yes', 'twostep_done', 'conversion'], array_column($rows, 'stat_type'));
        $this->assertSame('phone', $rows[0]['device']);
        $this->assertSame('IE', $rows[0]['country']);
        $this->assertSame('https://example.org/p/', $rows[0]['conversion_page']);

        $totals = Report::last_days(7, $id)->totals();
        $this->assertSame(1, $totals['twostep_yes']);
        $this->assertSame(1, $totals['twostep_done']);

        remove_filter('mailoptin_stats_use_woocommerce_geolocation', '__return_false');
        OCR::delete_optin_campaign($id);
    }

    public function test_the_page_renders_and_escapes()
    {
        $id = OCR::add_optin_campaign(wp_generate_password(10, false), '<script>alert(1)</script> Tulips', 'BareMetal', 'lightbox');
        $this->rows($this->day(-1), $id, 'impression', 1000, 'phone', 'US');
        $this->rows($this->day(-1), $id, 'conversion', 10, 'phone', 'US');
        $this->rows($this->day(-1), $id, 'impression', 1000, 'desktop', 'GB');
        $this->rows($this->day(-1), $id, 'conversion', 40, 'desktop', 'GB');
        Store::roll_up();

        wp_set_current_user(1);
        if ( ! defined('MAILOPTIN_ADVANCE_ANALYTICS_SETTINGS_SLUG')) define('MAILOPTIN_ADVANCE_ANALYTICS_SETTINGS_SLUG', 'mailoptin-statistics');

        ob_start();
        (new StatsPage())->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('2,000', $html);
        $this->assertStringContainsString('Phones get 50% of views but convert at 1.0%', $html, 'The device tip.');
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Tulips', $html);
        $this->assertStringContainsString('once there&#039;s a full period to compare with', $html);

        $_GET['optin'] = $id;
        $_GET['tab']   = 'country';
        ob_start();
        (new StatsPage())->render();
        $html = ob_get_clean();
        unset($_GET['optin'], $_GET['tab']);

        $this->assertStringContainsString('United Kingdom', $html);
        $this->assertStringContainsString('Subscribers per day', $html, 'A form campaign\'s conversions are subscribers.');

        OCR::delete_optin_campaign($id);
    }
}
