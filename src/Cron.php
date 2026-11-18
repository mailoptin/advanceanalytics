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
        // The first summing after an upgrade, and the rest of a run that ran out of time.
        add_action(Store::ROLLUP_HOOK, [Store::class, 'roll_up']);
    }

    /**
     * Each night: sum finished days into the daily totals, then delete detail older than 40 days and
     * daily totals older than 13 months.
     * 
     * @return false|int
     */
    public function cleanup_old_stat()
    {
        // Sum the finished days first, so nothing is deleted before it's in the daily totals.
        Store::maybe_upgrade();
        Store::roll_up();
        Store::clean_up();

        return true;
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