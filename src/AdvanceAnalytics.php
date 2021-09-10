<?php

namespace MailOptin\AdvanceAnalytics;

use MailOptin\Core\Repositories\OptinCampaignsRepository;
use function MailOptin\Core\is_mailoptin_admin_page;

class AdvanceAnalytics
{
    public function __construct()
    {
        if ( ! defined('MAILOPTIN_DETACH_LIBSODIUM')) return;

        // hooking into "mo_create_database_tables" filter didn't work hence this workaround.
        register_activation_hook(MAILOPTIN_SYSTEM_FILE_PATH, array(__CLASS__, 'create_stat_table'));
        // there are times the register activation hook doesn't trigger. so let's run it again.

        add_action('admin_init', array(__CLASS__, 'create_stat_table_if_missing'));

        $this->load_extension_classes();

        add_filter('mo_drop_database_tables', array(__CLASS__, 'delete_stat_table'));

        add_filter('mo_drop_mu_database_tables', array(__CLASS__, 'delete_mu_stat_table'));

        add_action('mailoptin_track_impressions', array($this, 'track_impressions'), 10, 2);

        add_action('mailoptin_track_conversions', array($this, 'track_conversions'), 10, 2);
    }

    public static function advance_stat_table_name()
    {
        global $wpdb;

        return $wpdb->prefix . 'mo_optin_advance_stat';
    }

    /**
     * Class belong to this extension that needs to be instantiated goes here
     */
    public function load_extension_classes()
    {
        SettingsPage::get_instance();

        Cron::get_instance();
    }

    /**
     * Add impression record to advance stat table.
     *
     * @param array $requestBody
     * @param int $optin_campaign_id
     */
    public function track_impressions($requestBody, $optin_campaign_id)
    {
        $optin_type = OptinCampaignsRepository::get_optin_campaign_type($optin_campaign_id);

        if (empty($optin_type)) return;

        AnalyticsRepository::add_impression(
            array(
                'optin_id'        => $optin_campaign_id,
                'optin_type'      => $optin_type,
                'conversion_page' => $requestBody['conversion_page'],
                'referrer'        => $requestBody['referrer']
            )
        );
    }

    /**
     * Add conversion record to advance stat table.
     *
     * @param array $data
     * @param int $optin_campaign_id
     */
    public function track_conversions($data, $optin_campaign_id)
    {
        // skip if optin campaign Id isn't defined.
        if ( ! isset($optin_campaign_id) || empty($optin_campaign_id) || $optin_campaign_id === 0) return;

        AnalyticsRepository::add_conversion(
            array(
                'optin_id'        => $optin_campaign_id,
                'optin_type'      => OptinCampaignsRepository::get_optin_campaign_type($optin_campaign_id),
                'conversion_page' => $data['conversion_page'],
                'referrer'        => $data['referrer']
            )
        );
    }

    /**
     * Drop table on un-installation.
     */
    public static function delete_stat_table($sql)
    {
        $advance_stat_table = self::advance_stat_table_name();

        $sql[] = "DROP TABLE IF EXISTS $advance_stat_table";

        return $sql;
    }

    /**
     * Uninstall tables when MU blog is deleted.
     *
     * @param array $tables
     *
     * @return array
     */
    public static function delete_mu_stat_table($tables)
    {
        $tables[] = self::advance_stat_table_name();

        return $tables;
    }

    public static function create_stat_table_if_missing()
    {
        if ( ! is_mailoptin_admin_page()) return;

        global $wpdb;

        $table_name = AdvanceAnalytics::advance_stat_table_name();
        $query      = $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name));

        if ( ! $wpdb->get_var($query) == $table_name) {
            self::create_stat_table();
        }
    }

    /**
     * Create stat table on installation.
     */
    public static function create_stat_table()
    {
        global $wpdb;

        $collate = '';
        if ($wpdb->has_cap('collation')) {
            $collate = $wpdb->get_charset_collate();
        }

        $advance_stat_table = self::advance_stat_table_name();

        $sql = "CREATE TABLE IF NOT EXISTS $advance_stat_table (
        id BIGINT NOT NULL PRIMARY KEY AUTO_INCREMENT,
        date date NOT NULL,
        stat_type varchar(20) NOT NULL,
        optin_id int(10) NOT NULL DEFAULT '0',
        optin_type varchar(50) NOT NULL,
        conversion_page varchar(256) DEFAULT NULL,
        referrer varchar(256) DEFAULT NULL,
        KEY date (date),
        KEY optin_id (optin_id),
        KEY optin_type (optin_type),
        KEY conversion_page (conversion_page)
        )  $collate;
		";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta($sql);
    }

    /**
     * Singleton poop.
     *
     * @return AdvanceAnalytics|null
     */
    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}