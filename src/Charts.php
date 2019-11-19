<?php

namespace MailOptin\AdvanceAnalytics;

use MailOptin\Core\Admin\SettingsPage\OptinCampaign_List;
use MailOptin\Core\Repositories\OptinCampaignsRepository;

class Charts
{
    const imp_last_30_days = 'impression_last_30_days';
    const total_imp_last_30_days = 'mo_total_impression_last_30_days';
    const subscribers_last_30_days = 'mo_subscribers_last_30_days';
    const total_subscribers_last_30_days = 'mo_total_subscribers_last_30_days';
    const conversion_rate_last_30_days = 'mo_conversion_rate_last_30_days';
    const total_conversion_rate_last_30_days = 'mo_total_conversion_rate_last_30_days';
    const top_converting_page_chart = 'mo_top_converting_page_chart';
    const top_converting_optin_chart = 'mo_top_converting_optin_chart';
    const post_url_to_title = 'mo_post_url_to_title';
    const top_displayed_optin_chart = 'mo_top_displayed_optin_chart';

    /**
     * Convert a post/page url to its title.
     *
     * @param string $url
     *
     * @return string
     */
    public static function post_url_to_title($url)
    {
        $cache_key = md5(md5(self::post_url_to_title . "_$url"));
        $title = get_transient($cache_key);

        if ($title === false) {
            if (($id = url_to_postid($url)) !== 0) {
                $title = get_the_title($id);
            } else {
                $title = $url;
            }

            set_transient($cache_key, $title, HOUR_IN_SECONDS);
        }


        if (($id = url_to_postid($url)) !== 0) {
            return get_the_title($id);
        } else {
            return $url;
        }
    }

    /**
     * Delete chart data cache.
     */
    public static function clear_cache()
    {
        $format1 = "Y-m-d";
        $format2 = "M jS";
        $quote_wrap1 = 'false';
        $quote_wrap2 = 'true';
        delete_transient(md5("mo_last_30_days_{$format1}_{$quote_wrap1}"));
        delete_transient(md5("mo_last_30_days_{$format2}_{$quote_wrap2}"));

        $filter_by_optin = !empty($_POST['mo_analytics_filter']) ? absint($_POST['mo_analytics_filter']) : null;
        delete_transient(md5(self::imp_last_30_days . "$filter_by_optin"));
        delete_transient(md5(self::subscribers_last_30_days . "$filter_by_optin"));
        delete_transient(md5(self::total_imp_last_30_days . "$filter_by_optin"));
        delete_transient(md5(self::total_subscribers_last_30_days . "$filter_by_optin"));

        delete_transient(md5(self::conversion_rate_last_30_days));
        delete_transient(md5(self::total_conversion_rate_last_30_days));
        delete_transient(md5(self::top_converting_page_chart));
        delete_transient(md5(self::top_converting_optin_chart));
        delete_transient(md5(self::top_displayed_optin_chart));
    }

    /**
     * Array of last 30 days.
     *
     * @param string $format
     *
     * @return array|bool
     */
    public static function last_30_days($format = 'Y-m-d', $quote_wrap = false)
    {
        $quote_wrap_string = ($quote_wrap) ? 'true' : 'false';
        $cache_key = md5("mo_last_30_days_{$format}_{$quote_wrap_string}");

        $data = get_transient($cache_key);

        if ($data === false) {
            $data = array();
            for ($i = 29; $i >= 0; $i--) {
                if ($quote_wrap === true) {
                    $data[] = '"' . date($format, strtotime('-' . $i . ' days', current_time('timestamp'))) . '"';
                } else {
                    $data[] = date($format, strtotime('-' . $i . ' days', current_time('timestamp')));
                }
            }

            set_transient($cache_key, $data, HOUR_IN_SECONDS);
        }

        return $data;
    }

    /**
     * Optin impression data for last 30days.
     *
     * @return mixed
     */
    public static function impression_last_30_days()
    {
        $filter_by_optin = !empty($_POST['mo_analytics_filter']) ? absint($_POST['mo_analytics_filter']) : null;
        $cache_key = md5(self::imp_last_30_days . "$filter_by_optin");

        $data = get_transient($cache_key);

        if ($data === false) {
            $data = array_reduce(self::last_30_days(), function ($carry, $date) use ($filter_by_optin) {
                $carry[] = absint(AnalyticsRepository::get_stat_count_by_date('impression', $date, $filter_by_optin));
                return $carry;
            });

            set_transient($cache_key, $data, HOUR_IN_SECONDS);
        }

        return $data;
    }

    /**
     * Optin total impression data for last 30days.
     *
     * @return mixed
     */
    public static function total_impression_last_30_days()
    {
        $filter_by_optin = !empty($_POST['mo_analytics_filter']) ? absint($_POST['mo_analytics_filter']) : null;
        $cache_key = md5(self::total_imp_last_30_days . "$filter_by_optin");

        $data = get_transient($cache_key);

        if ($data === false) {
            $data = array_reduce(self::last_30_days(), function ($carry, $date) use ($filter_by_optin) {
                $carry += absint(AnalyticsRepository::get_stat_count_by_date('impression', $date, $filter_by_optin));
                return $carry;
            });

            set_transient($cache_key, $data, HOUR_IN_SECONDS);
        }

        return $data;
    }

    /**
     * Optin conversion data for last 30days.
     *
     * @return mixed
     */
    public static function subscribers_last_30_days()
    {
        $filter_by_optin = !empty($_POST['mo_analytics_filter']) ? absint($_POST['mo_analytics_filter']) : null;
        $cache_key = md5(self::subscribers_last_30_days . "$filter_by_optin");

        $data = get_transient($cache_key);

        if ($data === false) {
            $data = array_reduce(self::last_30_days(), function ($carry, $date) use ($filter_by_optin) {
                $carry[] = absint(AnalyticsRepository::get_stat_count_by_date('conversion', $date, $filter_by_optin));
                return $carry;
            });

            set_transient($cache_key, $data, HOUR_IN_SECONDS);
        }

        return $data;
    }

    /**
     * Optin total subscribers data for last 30days.
     *
     * @return mixed
     */
    public static function total_subscribers_last_30_days()
    {
        $filter_by_optin = !empty($_POST['mo_analytics_filter']) ? absint($_POST['mo_analytics_filter']) : null;
        $cache_key = md5(self::total_subscribers_last_30_days . "$filter_by_optin");

        $data = get_transient($cache_key);

        if ($data === false) {
            $data = array_reduce(self::last_30_days(), function ($carry, $item) use ($filter_by_optin) {
                $carry += absint(AnalyticsRepository::get_stat_count_by_date('conversion', $item, $filter_by_optin));
                return $carry;
            });

            set_transient($cache_key, $data, HOUR_IN_SECONDS);
        }

        return $data;
    }

    /**
     * Optin conversion rate for last 30days.
     *
     * @return mixed
     */
    public static function conversion_rate_last_30_days()
    {
        $filter_by_optin = !empty($_POST['mo_analytics_filter']) ? absint($_POST['mo_analytics_filter']) : null;
        $cache_key = md5(self::conversion_rate_last_30_days);

        $data = get_transient($cache_key);

        if ($data === false) {
            $data = array_reduce(self::last_30_days(), function ($carry, $item) use ($filter_by_optin) {
                $conversions = AnalyticsRepository::get_stat_count_by_date('conversion', $item, $filter_by_optin);
                $impressions = AnalyticsRepository::get_stat_count_by_date('impression', $item, $filter_by_optin);
                $carry[] = (0 == $conversions) || (0 == $impressions) ? '0' : number_format(($conversions / $impressions) * 100, 2);
                return $carry;
            });

            set_transient($cache_key, $data, HOUR_IN_SECONDS);
        }

        return $data;
    }

    /**
     * Optin total conversion rate for last 30days.
     *
     * @return mixed
     */
    public static function total_conversion_rate_last_30_days()
    {
        $conversions = self::total_subscribers_last_30_days();
        $impressions = self::total_impression_last_30_days();

        $data = (0 == $conversions) || (0 == $impressions) ? '0' : number_format(($conversions / $impressions) * 100, 2);

        return $data;
    }

    /**
     * Display top converting page url|title.
     *
     * @return string
     */
    public static function top_converting_page_chart()
    {
        $cache_key = md5(self::top_converting_page_chart);
        $pages = get_transient($cache_key);

        if ($pages === false) {
            $pages = AnalyticsRepository::top_optin_conversion_pages();
            set_transient($cache_key, $pages, HOUR_IN_SECONDS);
        }

        $html = '<div style="text-align:left"><ol>';

        if (is_array($pages) && !empty($pages)) {
            $html .= array_reduce($pages, function ($carry, $page) {
                $url = $page['conversion_page'];
                $occurence = $page['occurrence'];

                $title_or_url = self::post_url_to_title($url);
                $carry .= @sprintf("%s{$title_or_url}%s %s", "<li><a href=\"$url\" target='_blank'>", '</a>', "($occurence)</li>");
                return $carry;
            });
        } else {
            $html .= apply_filters('mo_top_converting_page_chart_no_data', __('No data currently available.', 'mailoptin'));
        }

        $html .= '</ol></div>';

        return $html;
    }

    /**
     * Display top optin with highest number of display or views.
     *
     * @return string
     */
    public static function top_displayed_optin_chart()
    {
        $cache_key = md5(self::top_displayed_optin_chart);
        $optins = get_transient($cache_key);

        if ($optins === false) {
            $optins = AnalyticsRepository::top_impressive_optins();
            set_transient($cache_key, $optins, HOUR_IN_SECONDS);
        }

        $html = '<div style="text-align:left"><ol>';

        if (is_array($optins) && !empty($optins)) {
            $html .= array_reduce($optins, function ($carry, $optin) {

                $optin_id = absint($optin['optin_id']);
                $optin_title = OptinCampaignsRepository::get_optin_campaign_name($optin_id);
                $optin_url = OptinCampaign_List::_optin_campaign_customize_url($optin_id);
                $occurrence = $optin['occurrence'];

                $carry .= "<li><a href=\"{$optin_url}\" target='_blank'>{$optin_title}</a> ($occurrence)</li>";

                return $carry;
            });
        } else {
            $html .= apply_filters('mo_top_converting_page_chart_no_data', __('No data currently available.', 'mailoptin'));
        }

        $html .= '</ol></div>';

        return $html;
    }

    /**
     * Display top converting optin campaigns.
     *
     * @return string
     */
    public static function top_converting_optin_chart()
    {
        $html = '<div style="text-align:left"><ol>';

        $cache_key = md5(self::top_converting_optin_chart);
        $optins = get_transient($cache_key);

        if ($optins === false) {
            $optins = AnalyticsRepository::top_converting_optins();
            set_transient($cache_key, $optins, HOUR_IN_SECONDS);
        }

        if (!is_array($optins) || empty($optins)) {
            $html .= apply_filters('mo_top_converting_optin_chart_no_data', __('No data currently available.', 'mailoptin'));
        }

        foreach ($optins as $optin) {
            $optin_id = absint($optin['optin_id']);
            $optin_title = OptinCampaignsRepository::get_optin_campaign_name($optin_id);
            $optin_url = OptinCampaign_List::_optin_campaign_customize_url($optin_id);
            $occurrence = $optin['occurrence'];
            $html .= sprintf("%s{$optin_title}%s %s", "<li><a href=\"$optin_url\" target='_blank'>", '</a>', "($occurrence)</li>");
        }

        $html .= '</ol></div>';

        return $html;
    }

    /**
     * Impression chart.
     */
    public static function impression_chart()
    {
        ?>
        <div id="mo_impression_chart_container" style="width:100%; height:400px;"></div>
        <script>
            jQuery(function () {
                var myChart = Highcharts.chart('mo_impression_chart_container', {
                    credits: {enabled: false},
                    legend: {enabled: false},
                    chart: {type: 'column'},
                    title: {text: '<?php _e('Impressions', 'mailoptin'); ?>'},
                    subtitle: {text: '<?php _e('This chart shows the number of optin impressions.', 'mailoptin'); ?>'},
                    yAxis: [{title: {text: '<?php _e('No. of Impressions', 'mailoptin'); ?>'}}],
                    xAxis: {categories: [<?php echo implode(',', self::last_30_days('M jS', true)); ?>]},
                    series: [{
                        name: '<?php _e('Impressions', 'mailoptin'); ?>',
                        data: [<?php echo implode(',', self::impression_last_30_days()); ?>]
                    }]
                });
            });

        </script>
        <?php
    }

    /**
     * Conversion chart.
     */
    public static function conversion_chart()
    {
        ?>
        <div id="mo_subscriber_chart_container" style="width:100%; height:400px;"></div>
        <script>
            jQuery(function () {
                var myChart = Highcharts.chart('mo_subscriber_chart_container', {
                    credits: {enabled: false},
                    legend: {enabled: false},
                    chart: {type: 'column'},
                    title: {text: '<?php _e('Subscribers', 'mailoptin'); ?>'},
                    subtitle: {text: '<?php _e('This chart shows the number of optin subscribers.', 'mailoptin'); ?>'},
                    yAxis: [{title: {text: '<?php _e('No. of Subscribers', 'mailoptin'); ?>'}}],
                    xAxis: {categories: [<?php echo implode(',', self::last_30_days('M jS', true)); ?>]},
                    series: [{
                        name: '<?php _e('Conversions', 'mailoptin'); ?>',
                        data: [<?php echo implode(',', self::subscribers_last_30_days()); ?>]
                    }]
                });
            });

        </script>
        <?php
    }

    /**
     * Conversion chart.
     */
    public static function conversion_rate_chart()
    {
        ?>
        <div id="mo_conversion_rate_chart_container" style="width:100%; height:400px;"></div>
        <script>
            jQuery(function () {
                var myChart = Highcharts.chart('mo_conversion_rate_chart_container', {
                    credits: {enabled: false},
                    legend: {enabled: false},
                    tooltip: {
                        pointFormat: '<span style="color:{point.color}">\u25CF</span> {series.name}: <b>{point.y}%</b><br/>'
                    },
                    title: {text: '<?php _e('Conversion Rate', 'mailoptin'); ?>'},
                    subtitle: {text: '<?php _e('This chart shows your conversion rate.', 'mailoptin'); ?>'},
                    yAxis: [{title: {text: '<?php _e('Conversion Rate', 'mailoptin'); ?>'}}],
                    xAxis: {categories: [<?php echo implode(',', self::last_30_days('M jS', true)); ?>]},
                    series: [{
                        name: '<?php _e('Conversion Rate', 'mailoptin'); ?>',
                        data: [<?php echo implode(',', self::conversion_rate_last_30_days()); ?>]
                    }]
                });
            });
        </script>
        <?php
    }

    /**
     * Optin analytic overview chart.
     */
    public static function stat_overview_chart()
    {
        ?>
        <div id="mo_stat_overview_chart_container" style="width:100%; height:400px;"></div>
        <script>
            jQuery(function () {
                Highcharts.chart('mo_stat_overview_chart_container', {
                    credits: {enabled: false},
                    title: {text: '<?php _e('Overview', 'mailoptin'); ?>'},
                    subtitle: {text: '<?php _e('This chart shows the number of impressions, subscriptions and conversion rate.', 'mailoptin'); ?>'},
                    xAxis: {categories: [<?php echo implode(',', self::last_30_days('M jS', true)); ?>]},
                    labels: {
                        items: [{
                            html: '<?php _e('Total Statistics', 'mailoptin'); ?>',
                            style: {
                                left: '50px',
                                top: '-40px',
                                color: (Highcharts.theme && Highcharts.theme.textColor) || 'black'
                            }
                        }]
                    },
                    series: [{
                        type: 'column',
                        name: '<?php _e('Impressions', 'mailoptin'); ?>',
                        data: [<?php echo implode(',', self::impression_last_30_days()); ?>]
                    }, {
                        type: 'column',
                        name: '<?php _e('Subscribers', 'mailoptin'); ?>',
                        data: [<?php echo implode(',', self::subscribers_last_30_days()); ?>]
                    }, {
                        type: 'spline',
                        name: '<?php _e('Conversion Rate', 'mailoptin'); ?>',
                        data: [<?php echo implode(',', self::conversion_rate_last_30_days()); ?>],
                        tooltip: {
                            pointFormat: '<span style="color:{point.color}">\u25CF</span> {series.name}: <b>{point.y}%</b><br/>',
                        },
                        marker: {
                            lineWidth: 2,
                            lineColor: Highcharts.getOptions().colors[3],
                            fillColor: 'white'
                        }
                    }, {
                        type: 'pie',
                        tooltip: {
                            pointFormat: '<span style="color:{point.color}">\u25CF</span>  <b>{point.y}</b><br/>',
                        },
                        data: [{
                            name: '<?php _e('Impressions', 'mailoptin'); ?>',
                            y: <?php echo self::total_impression_last_30_days(); ?>,
                            color: Highcharts.getOptions().colors[0]
                        }, {
                            name: '<?php _e('Subscribers', 'mailoptin'); ?>',
                            y: <?php echo self::total_subscribers_last_30_days(); ?>,
                            color: Highcharts.getOptions().colors[1]
                        }, {
                            name: '<?php _e('Conversion Rate', 'mailoptin'); ?>',
                            y: <?php echo self::total_conversion_rate_last_30_days() ?>,
                            color: '#F7A35C'
                        }],
                        center: [70, 1],
                        size: 80,
                        showInLegend: false,
                        dataLabels: {enabled: false}
                    }]
                });
            });
        </script>
        <?php
    }

    /**
     * @return Charts
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