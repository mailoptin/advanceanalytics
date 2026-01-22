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
        // Hook the Email_Template_List table to Custom_Settings_Page_Api main content filter.
        add_filter('wp_cspa_main_content_area', array($this, 'analytics_chart_main_display'), 10, 2);

        add_action('wp_cspa_after_settings_tab', array($this, 'chart_filter'));
    }

    /**
     * HTML select dropdown to filter analytics by optin campaigns.
     */
    public function chart_filter($page_option_name)
    {
        if ($page_option_name != 'mo_analytics') return;

        $optins = OptinCampaignsRepository::get_optin_campaigns();
        $initial = '<option value="">' . __('Select Optin Campaign...', 'mailoptin') . '</option>';

        $select_dropdown = array_reduce($optins, function ($carry, $optin) {
            $id = absint($optin['id']);
            $name = $optin['name'];
            $selected = selected($_POST['mo_analytics_filter'] ?? '', $id, false);
            $carry .= "<option value='$id' $selected>$name</option>";
            return $carry;
        }, $initial);
        ?>
        <div class="tablenav top">
            <div class="alignleft actions bulkactions" style="line-height: 2em;font-size: 15px">
                <strong style="font-weight:500;"><?php _e('Filter By:', 'mailoptin'); ?></strong>
            </div>
            <div class="alignleft actions bulkactions">
                <label for="mo-analytics-filter-top" class="screen-reader-text">
                    <?php _e('Filter analytics by', 'mailoptin'); ?>
                </label>
                <form method="post">
                    <?php wp_nonce_field('mo_chart_settings_page'); ?>
                    <select name="mo_analytics_filter" id="mo-analytics-filter-top">
                        <?php echo $select_dropdown; ?>
                    </select>
                    <input type="submit" id="do-mo-filter" class="button action" value="<?php _e('Apply', 'mailoptin'); ?>">
                </form>
            </div>

            <div class="alignleft actions">
                <span style="margin-left: 100px;">
                    <?php $this->refresh_stat_button_structure(); ?>
                    <?php $this->clear_stat_button_structure(); ?>
                </span>
            </div>
            <br class="clear">
        </div>
        <?php
    }

    /**
     * Settings page to display charts.
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

        $instance = Custom_Settings_Page_Api::instance();
        do_action('mo_before_analytic_charts', $instance, $option_name);

        echo $instance->_header_without_frills();
        Charts::stat_overview_chart();
        echo $instance->_footer_without_button();

        echo $instance->_header_without_frills();
        Charts::impression_chart();
        echo $instance->_footer_without_button();

        echo $instance->_header_without_frills();
        Charts::conversion_chart();
        echo $instance->_footer_without_button();

        echo $instance->_header_without_frills();
        Charts::conversion_rate_chart();
        echo $instance->_footer_without_button();

        do_action('mo_after_analytic_charts', $instance, $option_name);
    }

    /**
     * Handle processing of actions such as form submissions.
     */
    public function process_actions()
    {
        /**
         * invalidate cache when analytics is filtered.
         */
        if (!empty($_POST['mo_analytics_filter']) && check_admin_referer('mo_chart_settings_page')) {
            Charts::clear_cache();
        }

        if (!empty($_POST['mo_process_form']) && check_admin_referer('mo_chart_settings_page')) {
            if ($_POST['mo_process_form'] == 'refresh_stat') {
                Charts::clear_cache();
            }

            if ($_POST['mo_process_form'] == 'clear_stat') {
                AnalyticsRepository::delete_all_record();
                Charts::clear_cache();
            }
        }
    }

    public function analytic_chart_sidebar()
    {
        return array(
            array(
                'section_title' => __('Top Converting Optins (Count)', 'mailoptin'),
                'content' => Charts::top_converting_optin_chart()
            ),
            array(
                'section_title' => __('Top Converting Pages (Count)', 'mailoptin'),
                'content' => Charts::top_converting_page_chart()
            ),
            array(
                'section_title' => __('Top Displayed Optins (Count)', 'mailoptin'),
                'content' => Charts::top_displayed_optin_chart()
            )
        );
    }

    public function refresh_stat_button_structure()
    {
        echo '<form id="mo-refresh-stat" method="post" class="mo-inline">';
        wp_nonce_field('mo_chart_settings_page');
        echo '<input type="hidden" name="mo_process_form" value="refresh_stat">';
        submit_button(__('Refresh Analytics', 'mailoptin'), 'secondary', 'submit', false);
        echo '</form>';
    }

    /**
     * Output form with submit button to clear stat.
     */
    public function clear_stat_button_structure()
    {
        echo '<form method="post" id="mo-clear-stat" class="mo-inline" style="margin-left: 10px">';
        wp_nonce_field('mo_chart_settings_page');
        echo '<input type="hidden" name="mo_process_form" value="clear_stat">';
        submit_button(__('Clear Stats', 'mailoptin'), 'secondary', 'submit', false);
        echo '</form>';
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