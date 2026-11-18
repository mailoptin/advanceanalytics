<?php

namespace MailOptin\AdvanceAnalytics;

// Exit if accessed directly
use MailOptin\Core\Admin\SettingsPage\AbstractSettingsPage;
use MailOptin\Core\Repositories\OptinCampaignsRepository;
use W3Guy\Custom_Settings_Page_Api;

if (!defined('ABSPATH')) {
    exit;
}


class SettingsPage extends AbstractSettingsPage
{
    public function __construct()
    {
        add_action('mailoptin_advance_analytics_settings_page', [$this, 'init']);

        add_action('admin_init', [$this, 'process_actions']);
    }

    public function init()
    {
        add_filter('wp_cspa_main_content_area', array($this, 'analytics_chart_main_display'), 10, 2);
    }

    /**
     * The Statistics page.
     *
     * @param mixed $content
     * @param string $option_name
     *
     * @return mixed
     */
    public function analytics_chart_main_display($content, $option_name)
    {
        if ($option_name != 'mo_analytics') {
            return $content;
        }

        do_action('mo_before_analytic_charts', Custom_Settings_Page_Api::instance(), $option_name);

        (new StatsPage())->render();

        do_action('mo_after_analytic_charts', Custom_Settings_Page_Api::instance(), $option_name);
    }

    /**
     * "Clear all stats", and dismissing a tip (which then reloads the page without the tip's address).
     */
    public function process_actions()
    {
        if ( ! current_user_can(\MailOptin\Core\get_capability())) return;

        if ( ! empty($_GET['mo_dismiss_tip']) && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_key($_GET['_wpnonce']), 'mo_dismiss_tip')) {
            Tips::dismiss(sanitize_key($_GET['mo_dismiss_tip']));
            if ( ! headers_sent()) {
                wp_safe_redirect(remove_query_arg(['mo_dismiss_tip', '_wpnonce']));
                exit;
            }
        }

        if (isset($_POST['mo_process_form']) && 'clear_stat' === $_POST['mo_process_form'] && check_admin_referer('mo_chart_settings_page')) {
            Store::clear_all();
        }
    }

    /** The old page's sidebar: the top lists are now in the page itself. Kept for older cores, which ask for it. */
    public function analytic_chart_sidebar()
    {
        return [];
    }

    /**
     * @return SettingsPage
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