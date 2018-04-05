<?php
/**
 * Copyright (C) 2016  Agbonghama Collins <me@w3guy.com>
 */

namespace MailOptin\AdvanceAnalytics;

use MailOptin\Core\Repositories\AbstractRepository;

class Cron extends AbstractRepository
{
    public function __construct()
    {
        add_action('init', array($this, 'create_schedule'));
        add_action('mo_advance_analytic_cleanup', array($this, 'cleanup_old_stat'));
    }

    /**
     * Delete record older than 40 days.
     * 
     * @return false|int
     */
    public function cleanup_old_stat()
    {
        $table = AdvanceAnalytics::advance_stat_table_name();

        return self::wpdb()->query(
            "DELETE FROM $table WHERE DATEDIFF(NOW(), date) >= 40"
        );
    }

    public function create_schedule()
    {
        //check if event scheduled before
        if (!wp_next_scheduled('mo_advance_analytic_cleanup')) {
            wp_schedule_event(time(), 'daily', 'mo_advance_analytic_cleanup');
        }
    }

    /**
     * @return Cron|null
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