<?php

namespace MailOptin\AdvanceAnalytics;

/**
 * The numbers on the Statistics page for one period and, optionally, one campaign: from the daily totals,
 * plus today read live from the detail table.
 */
class Report
{
    public $start;
    public $end;
    public $optin_id;

    protected $today;
    protected $today_rows = null;

    /**
     * @param string $start Y-m-d, first day included.
     * @param string $end Y-m-d, last day included.
     * @param int $optin_id 0 for every campaign.
     */
    public function __construct($start, $end, $optin_id = 0)
    {
        $this->start    = $start;
        $this->end      = $end;
        $this->optin_id = absint($optin_id);
        $this->today    = Store::today();
    }

    /** The last $days days, today included. */
    public static function last_days($days, $optin_id = 0)
    {
        $days  = max(1, absint($days));
        $today = Store::today();

        return new self(gmdate('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' days')), $today, $optin_id);
    }

    /** The same number of days just before this period. */
    public function previous()
    {
        $length = (int)round((strtotime($this->end) - strtotime($this->start)) / DAY_IN_SECONDS) + 1;

        return new self(
            gmdate('Y-m-d', strtotime($this->start . ' -' . $length . ' days')),
            gmdate('Y-m-d', strtotime($this->start . ' -1 day')),
            $this->optin_id
        );
    }

    /**
     * Sums for one breakdown, merged from the daily totals and today's detail.
     *
     * @param string $dimension total, device, country, page or source.
     * @param bool $by_date One row per day.
     * @param bool $by_optin One row per campaign.
     *
     * @return array Keyed "date|optin|value" => ['date', 'optin_id', 'value', 'impression', 'conversion', 'twostep_yes', 'twostep_done'].
     */
    public function sums($dimension, $by_date = false, $by_optin = false)
    {
        global $wpdb;

        $out = [];
        $add = function ($date, $optin, $value, $type, $n) use (&$out, $by_date, $by_optin) {
            $date  = $by_date ? $date : '';
            $optin = $by_optin ? (int)$optin : 0;
            $key   = $date . '|' . $optin . '|' . $value;
            if ( ! isset($out[$key])) {
                $out[$key] = ['date' => $date, 'optin_id' => $optin, 'value' => (string)$value, 'impression' => 0, 'conversion' => 0, 'twostep_yes' => 0, 'twostep_done' => 0];
            }
            if (isset($out[$key][$type])) $out[$key][$type] += (int)$n;
        };

        // Finished days, from the daily totals.
        $last_full = min($this->end, gmdate('Y-m-d', strtotime($this->today . ' -1 day')));
        if ($this->start <= $last_full) {
            $table  = Store::daily_table();
            $select = ($by_date ? 'date, ' : '') . ($by_optin ? 'optin_id, ' : '');
            $where  = $wpdb->prepare('date BETWEEN %s AND %s AND dimension = %s', $this->start, $last_full, $dimension);
            if ($this->optin_id) $where .= $wpdb->prepare(' AND optin_id = %d', $this->optin_id);

            $rows = $wpdb->get_results("SELECT {$select}value, stat_type, SUM(count) AS n FROM $table WHERE $where GROUP BY {$select}value, stat_type", ARRAY_A);

            foreach ((array)$rows as $r) {
                $add($r['date'] ?? '', $r['optin_id'] ?? 0, $r['value'], $r['stat_type'], $r['n']);
            }
        }

        // Today, live.
        if ($this->start <= $this->today && $this->end >= $this->today) {
            if (null === $this->today_rows) $this->today_rows = Store::day_from_detail($this->today, $this->optin_id);

            foreach ($this->today_rows as $r) {
                if ($r[2] === $dimension) $add($this->today, $r[0], $r[3], $r[1], $r[4]);
            }
        }

        return $out;
    }

    /** ['impression' => n, 'conversion' => n, 'twostep_yes' => n, 'twostep_done' => n] */
    public function totals()
    {
        $rows = $this->sums('total');
        $row  = $rows ? reset($rows) : [];

        return [
            'impression'   => (int)($row['impression'] ?? 0),
            'conversion'   => (int)($row['conversion'] ?? 0),
            'twostep_yes'  => (int)($row['twostep_yes'] ?? 0),
            'twostep_done' => (int)($row['twostep_done'] ?? 0),
        ];
    }

    /** Every day of the period, in order, with its views and subscribers (0 on days with none). */
    public function series()
    {
        $by_date = [];
        foreach ($this->sums('total', true) as $r) $by_date[$r['date']] = $r;

        $series = [];
        for ($day = $this->start; $day <= $this->end; $day = gmdate('Y-m-d', strtotime($day . ' +1 day'))) {
            $series[] = [
                'date'       => $day,
                'impression' => (int)($by_date[$day]['impression'] ?? 0),
                'conversion' => (int)($by_date[$day]['conversion'] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * One breakdown's rows, most views first. Values with views or conversions only.
     *
     * @return array[] ['value', 'impression', 'conversion', 'twostep_yes', 'twostep_done']
     */
    public function breakdown($dimension)
    {
        $rows = array_values(array_filter($this->sums($dimension), function ($r) {
            return $r['impression'] > 0 || $r['conversion'] > 0;
        }));

        usort($rows, function ($a, $b) {
            return [$b['impression'], $b['conversion']] <=> [$a['impression'], $a['conversion']];
        });

        return $rows;
    }

    /** Each campaign's totals, most views first. */
    public function campaigns()
    {
        $rows = array_values(array_filter($this->sums('total', false, true), function ($r) {
            return $r['impression'] > 0 || $r['conversion'] > 0;
        }));

        usort($rows, function ($a, $b) {
            return [$b['impression'], $b['conversion']] <=> [$a['impression'], $a['conversion']];
        });

        return $rows;
    }

    /**
     * Each campaign's best-converting device, among devices with at least $min_views views.
     *
     * @return array optin_id => ['value' => 'desktop', 'rate' => 0.051]
     */
    public function best_devices($min_views = 100)
    {
        $best = [];

        foreach ($this->sums('device', false, true) as $r) {
            if ('' === $r['value'] || $r['impression'] < $min_views) continue;

            $rate = $r['conversion'] / $r['impression'];
            $id   = $r['optin_id'];
            if ( ! isset($best[$id]) || $rate > $best[$id]['rate']) $best[$id] = ['value' => $r['value'], 'rate' => $rate];
        }

        return $best;
    }

    /** The first day with any stats, or '' when there are none. */
    public static function first_day()
    {
        global $wpdb;

        $daily  = (string)$wpdb->get_var('SELECT MIN(date) FROM ' . Store::daily_table());
        $detail = (string)$wpdb->get_var('SELECT MIN(date) FROM ' . AdvanceAnalytics::advance_stat_table_name());

        $days = array_filter([$daily, $detail]);

        return $days ? min($days) : '';
    }

    /** A rate as a fraction, or null when there were no views. */
    public static function rate($conversions, $views)
    {
        return $views > 0 ? $conversions / $views : null;
    }
}
