<?php

namespace MailOptin\AdvanceAnalytics;

use MailOptin\Core\Repositories\OptinCampaignsRepository as OCR;

/**
 * The Statistics page: a period and campaign picker, totals against the period before, subscribers per
 * day, a breakdown by device, page, source and country, every campaign's numbers, and for one campaign its
 * two-step answers. Server-rendered; a few lines of script add the chart's hover.
 */
class StatsPage
{
    const PERIODS = [7, 30, 90, 365];
    const TABS = ['device', 'page', 'source', 'country'];

    protected $period;
    protected $optin_id;
    protected $tab;

    public function __construct()
    {
        $period         = absint($_GET['period'] ?? 30);
        $this->period   = in_array($period, self::PERIODS, true) ? $period : 30;
        $this->optin_id = absint($_GET['optin'] ?? 0);
        $tab            = sanitize_key($_GET['tab'] ?? 'device');
        $this->tab      = in_array($tab, self::TABS, true) ? $tab : 'device';

        if ($this->optin_id && ! OCR::get_optin_campaign_name($this->optin_id)) $this->optin_id = 0;
    }

    /** What a conversion is called here: a message's button is clicked; a form is subscribed to. */
    protected function conversions_label($lower = false)
    {
        if ( ! $this->optin_id) {
            $label = __('Conversions', 'mailoptin');
        } elseif (class_exists('\MailOptin\Core\OptinForms\Messages') && \MailOptin\Core\OptinForms\Messages::is_campaign($this->optin_id)) {
            $label = __('Clicks', 'mailoptin');
        } elseif (class_exists('\MailOptin\Libsodium\WooCommerce\ProductOffers\ProductOffers') && \MailOptin\Libsodium\WooCommerce\ProductOffers\ProductOffers::is_offer_campaign($this->optin_id)) {
            $label = __('Conversions', 'mailoptin');
        } else {
            $label = __('Subscribers', 'mailoptin');
        }

        return $lower ? strtolower($label) : $label;
    }

    public static function base_url()
    {
        return admin_url('admin.php?page=' . MAILOPTIN_ADVANCE_ANALYTICS_SETTINGS_SLUG);
    }

    protected function url($args = [])
    {
        $args = array_merge(['period' => $this->period, 'optin' => $this->optin_id ?: null, 'tab' => $this->tab], $args);
        if (30 === (int)($args['period'] ?? 0)) $args['period'] = null;
        if ('device' === ($args['tab'] ?? '')) $args['tab'] = null;

        return add_query_arg(array_filter($args, function ($v) {
            return null !== $v && '' !== $v && 0 !== $v;
        }), self::base_url());
    }

    public function render()
    {
        Store::maybe_upgrade();
        Store::roll_up();

        $report   = Report::last_days($this->period, $this->optin_id);
        $previous = $report->previous();
        $totals   = $report->totals();
        $before   = $previous->totals();

        echo '<div class="mo-stats">';
        $this->styles();
        $this->title_row();
        $this->kpis($totals, $before);

        if ( ! $totals['impression'] && ! $totals['conversion']) {
            $this->empty_state();
        } else {
            $this->chart($report);
            if ($this->optin_id) $this->two_steps($totals);
            $this->breakdown_card($report, $totals);
            if ( ! $this->optin_id) $this->campaigns_card($report);
        }

        $this->footer();
        echo '</div>';
        $this->script();
    }

    protected function period_label($days)
    {
        switch ($days) {
            case 7:
                return __('Last 7 days', 'mailoptin');
            case 90:
                return __('Last 90 days', 'mailoptin');
            case 365:
                return __('Last 12 months', 'mailoptin');
        }

        return __('Last 30 days', 'mailoptin');
    }

    protected function title_row()
    {
        $campaigns = OCR::get_optin_campaigns();

        echo '<div class="mo-stats-title-row"><div>';
        if ($this->optin_id) {
            $type = OCR::get_optin_campaign_type($this->optin_id);
            $live = OCR::is_activated($this->optin_id);
            printf('<p class="mo-stats-crumb"><a href="%s">%s</a> ›</p>', esc_url($this->url(['optin' => null])), esc_html__('All campaigns', 'mailoptin'));
            printf(
                '<h2>%s <span class="mo-stats-badge">%s</span> <span class="mo-stats-badge %s">%s</span></h2>',
                esc_html(OCR::get_optin_campaign_name($this->optin_id)),
                esc_html(Labels::optin_type($type)),
                $live ? 'is-ok' : '',
                $live ? esc_html__('Live', 'mailoptin') : esc_html__('Paused', 'mailoptin')
            );
            printf('<p class="mo-stats-sub"><a href="%s">%s</a></p>', esc_url(admin_url('customize.php?mailoptin_optin_campaign_id=' . $this->optin_id)), esc_html__('Edit this campaign', 'mailoptin'));
        } else {
            printf('<h2>%s</h2><p class="mo-stats-sub">%s</p>', esc_html__('All campaigns', 'mailoptin'), esc_html__('Who sees your campaigns, and who subscribes.', 'mailoptin'));
        }
        echo '</div>';

        // A GET form: the choices are in the address, so a view can be bookmarked or shared.
        echo '<form class="mo-stats-filters" method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="' . esc_attr(MAILOPTIN_ADVANCE_ANALYTICS_SETTINGS_SLUG) . '">';
        if ('device' !== $this->tab) echo '<input type="hidden" name="tab" value="' . esc_attr($this->tab) . '">';

        echo '<label class="mo-stats-select"><span>' . esc_html__('Period', 'mailoptin') . '</span><select name="period">';
        foreach (self::PERIODS as $days) {
            printf('<option value="%d"%s>%s</option>', $days, selected($days, $this->period, false), esc_html($this->period_label($days)));
        }
        echo '</select></label>';

        echo '<label class="mo-stats-select"><span>' . esc_html__('Campaign', 'mailoptin') . '</span><select name="optin">';
        printf('<option value="">%s</option>', esc_html__('All campaigns', 'mailoptin'));
        foreach ((array)$campaigns as $c) {
            printf('<option value="%d"%s>%s</option>', absint($c['id']), selected(absint($c['id']), $this->optin_id, false), esc_html($c['name']));
        }
        echo '</select></label>';
        echo '<button type="submit" class="button mo-stats-apply">' . esc_html__('Show', 'mailoptin') . '</button>';
        echo '</form></div>';
    }

    protected function delta($now, $before, $kind)
    {
        $label = sprintf(
            /* translators: %s: e.g. "Last 30 days" lowercased: "the 30 days before" */
            esc_html__('on the %s before', 'mailoptin'),
            365 === $this->period ? esc_html__('12 months', 'mailoptin') : esc_html(sprintf(_n('%d day', '%d days', $this->period, 'mailoptin'), $this->period))
        );

        if (null === $before) return '<span class="mo-stats-muted">' . esc_html__('Not enough history to compare yet', 'mailoptin') . '</span>';

        if ('points' === $kind) {
            $change = 100 * ($now - $before);
            $text   = sprintf(esc_html__('%s points', 'mailoptin'), number_format_i18n(abs($change), 2));
        } else {
            if ( ! $before) return '<span class="mo-stats-muted">' . esc_html__('None in the period before', 'mailoptin') . '</span>';
            $change = 100 * ($now - $before) / $before;
            $text   = number_format_i18n(abs($change), 0) . '%';
        }

        if (abs($change) < 0.005) return '<span class="mo-stats-muted">' . esc_html__('No change', 'mailoptin') . '</span> ' . $label;

        $up = $change > 0;

        return sprintf(
            '<span class="%s"><span aria-hidden="true">%s</span> <span class="screen-reader-text">%s</span>%s</span> %s',
            $up ? 'mo-stats-up' : 'mo-stats-down',
            $up ? '▲' : '▼',
            $up ? esc_html__('Up', 'mailoptin') : esc_html__('Down', 'mailoptin'),
            $text,
            $label
        );
    }

    protected function kpis($t, $b)
    {
        // Compare only with a period the stats fully cover; a part-covered one would show huge changes.
        $first      = Report::first_day();
        $had_before = '' !== $first && $first <= Report::last_days($this->period)->previous()->start;
        $rate       = Report::rate($t['conversion'], $t['impression']);
        $rate_b     = Report::rate($b['conversion'], $b['impression']);

        $tiles = [
            [__('Views', 'mailoptin'), number_format_i18n($t['impression']), $this->delta($t['impression'], $had_before ? $b['impression'] : null, 'count')],
            [$this->conversions_label(), number_format_i18n($t['conversion']), $this->delta($t['conversion'], $had_before ? $b['conversion'] : null, 'count')],
            [__('Conversion rate', 'mailoptin'), null === $rate ? '—' : Tips::percent($rate), null === $rate || null === $rate_b || ! $had_before ? '<span class="mo-stats-muted">' . esc_html__('Not enough history to compare yet', 'mailoptin') . '</span>' : $this->delta($rate, $rate_b, 'points')],
        ];

        echo '<div class="mo-stats-grid3">';
        foreach ($tiles as $tile) {
            printf('<div class="mo-stats-card mo-stats-kpi"><p class="mo-stats-label">%s</p><div class="mo-stats-num">%s</div><div class="mo-stats-delta">%s</div></div>', esc_html($tile[0]), esc_html($tile[1]), $tile[2]);
        }
        echo '</div>';
    }

    protected function empty_state()
    {
        echo '<div class="mo-stats-card mo-stats-empty">';
        printf('<h3>%s</h3>', esc_html__('No views in this period yet', 'mailoptin'));
        printf('<p>%s</p>', esc_html__('Stats start when a live campaign is shown to a visitor. Views in the Customizer preview aren\'t counted.', 'mailoptin'));
        echo '</div>';
    }

    protected function chart(Report $report)
    {
        $series = $report->series();
        $best   = null;
        foreach ($series as $d) {
            if ($d['conversion'] > 0 && ( ! $best || $d['conversion'] > $best['conversion'])) $best = $d;
        }
        $today = end($series);

        echo '<div class="mo-stats-card mo-stats-chart">';
        printf('<div class="mo-stats-card-head"><div><h3>%s</h3>', esc_html(sprintf(__('%s per day', 'mailoptin'), $this->conversions_label())));
        if ($best) {
            printf('<p class="mo-stats-hint">%s</p>', esc_html(sprintf(
                /* translators: 1: number, 2: a date */
                __('%1$s on %2$s, the best day of the period', 'mailoptin'),
                number_format_i18n($best['conversion']),
                date_i18n('l j M', strtotime($best['date']))
            )) . ' · ' . esc_html(sprintf(__('Today so far: %s', 'mailoptin'), number_format_i18n($today['conversion']))));
        }
        echo '</div></div>';

        $points = array_map(function ($d) {
            return [Store::today() === $d['date'] ? __('Today so far', 'mailoptin') : date_i18n('D j M', strtotime($d['date'])), $d['conversion'], $d['impression']];
        }, $series);

        echo '<div class="mo-stats-plot" data-points="' . esc_attr(wp_json_encode($points)) . '">';
        echo '<div class="mo-stats-svg-wide">' . $this->svg($series, 1100, 230) . '</div>';
        echo '<div class="mo-stats-svg-narrow">' . $this->svg($series, 360, 200) . '</div>';
        echo '<div class="mo-stats-tooltip" hidden></div>';
        echo '</div>';

        // The same numbers as a table, for screen readers.
        echo '<table class="screen-reader-text"><caption>' . esc_html__('Subscribers and views per day', 'mailoptin') . '</caption><tr><th>' . esc_html__('Day', 'mailoptin') . '</th><th>' . esc_html__('Subscribers', 'mailoptin') . '</th><th>' . esc_html__('Views', 'mailoptin') . '</th></tr>';
        foreach ($points as $p) printf('<tr><td>%s</td><td>%d</td><td>%d</td></tr>', esc_html($p[0]), $p[1], $p[2]);
        echo '</table></div>';
    }

    /** A line of subscribers per day, with a light wash under it; the axes are quiet. */
    protected function svg($series, $w, $h)
    {
        $pad = ['l' => 40, 'r' => 14, 't' => 12, 'b' => 26];
        $n   = count($series);
        $max = max(1, max(array_column($series, 'conversion')));

        // A round top for the axis: 1, 2 or 5 times a power of ten, in four steps.
        $step = $max / 4;
        $mag  = pow(10, floor(log10(max($step, 1))));
        foreach ([1, 2, 5, 10] as $m) {
            if ($m * $mag >= $step) {
                $step = $m * $mag;
                break;
            }
        }
        $top = max(4, $step * ceil($max / $step));
        $ticks_y = (int)round($top / $step);

        $x = function ($i) use ($pad, $w, $n) {
            return round($pad['l'] + ($n > 1 ? $i * ($w - $pad['l'] - $pad['r']) / ($n - 1) : ($w - $pad['l'] - $pad['r']) / 2), 1);
        };
        $y = function ($v) use ($pad, $h, $top) {
            return round($pad['t'] + (1 - $v / $top) * ($h - $pad['t'] - $pad['b']), 1);
        };

        $out = sprintf('<svg viewBox="0 0 %d %d" role="img" aria-label="%s" focusable="false">', $w, $h, esc_attr__('Subscribers per day', 'mailoptin'));

        for ($k = 0; $k <= $ticks_y; $k++) {
            $v   = $k * $step;
            $out .= sprintf('<line x1="%d" x2="%d" y1="%s" y2="%s" stroke="#f0f0f1"/>', $pad['l'], $w - $pad['r'], $y($v), $y($v));
            $out .= sprintf('<text x="%d" y="%s" text-anchor="end" fill="#646970" font-size="11">%s</text>', $pad['l'] - 8, $y($v) + 4, esc_html(number_format_i18n($v)));
        }

        $ticks = $n > 1 ? array_unique(array_map(function ($k) use ($n) {
            return (int)round($k * ($n - 1) / 4);
        }, [0, 1, 2, 3, 4])) : [0];
        foreach ($ticks as $i) {
            $anchor = 0 === $i ? 'start' : ($i === $n - 1 ? 'end' : 'middle');
            $out    .= sprintf('<text x="%s" y="%d" text-anchor="%s" fill="#646970" font-size="11">%s</text>', $x($i), $h - 6, $anchor, esc_html(date_i18n('j M', strtotime($series[$i]['date']))));
        }

        $line = [];
        foreach ($series as $i => $d) $line[] = $x($i) . ',' . $y($d['conversion']);

        $out .= sprintf('<path d="M%s,%s L%s L%s,%s Z" fill="#2271b1" opacity=".1"/>', $x(0), $y(0), implode(' L', $line), $x($n - 1), $y(0));
        $out .= sprintf('<polyline points="%s" fill="none" stroke="#2271b1" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>', implode(' ', $n > 1 ? array_slice($line, 0, -1) : $line));
        // Today isn't over: its part of the line is dashed.
        if ($n > 1) $out .= sprintf('<polyline points="%s %s" fill="none" stroke="#2271b1" stroke-width="2" stroke-dasharray="4 4" stroke-linecap="round"/>', $line[$n - 2], $line[$n - 1]);
        $out .= sprintf('<line class="mo-stats-cross" x1="0" x2="0" y1="%d" y2="%s" stroke="#8c8f94" stroke-width="1" visibility="hidden"/>', $pad['t'], $y(0));
        $out .= '<circle class="mo-stats-dot" r="5" cx="0" cy="0" fill="#2271b1" stroke="#fff" stroke-width="2" visibility="hidden"/>';
        $out .= sprintf('<rect class="mo-stats-hit" x="%d" y="0" width="%d" height="%d" fill="transparent" data-left="%d" data-right="%d" data-top="%s" data-bottom="%s"/>', $pad['l'], $w - $pad['l'] - $pad['r'], $h, $pad['l'], $w - $pad['r'], $top, $h - $pad['b']);

        return $out . '</svg>';
    }

    protected function two_steps($t)
    {
        $on = class_exists('\MailOptin\Core\OptinForms\TwoStep') && OCR::get_customizer_value($this->optin_id, 'twostep_status') === true;

        if ( ! $on && ! $t['twostep_yes']) return;

        $views = max(1, $t['impression']);
        $yes   = $t['twostep_yes'];
        $done  = min($t['twostep_done'], $yes);

        echo '<div class="mo-stats-card">';
        printf(
            '<div class="mo-stats-card-head"><div><h3>%s</h3><p class="mo-stats-hint">%s</p></div><a href="%s">%s</a></div>',
            esc_html__('Two steps', 'mailoptin'),
            esc_html__('How many people answered the question, and how many then subscribed', 'mailoptin'),
            esc_url(admin_url('customize.php?mailoptin_optin_campaign_id=' . $this->optin_id)),
            esc_html__('Edit the question', 'mailoptin')
        );

        $steps = [
            [__('Saw the question', 'mailoptin'), $t['impression'], ''],
            [__('Said yes', 'mailoptin'), $yes, sprintf(__('%s of views', 'mailoptin'), Tips::percent($yes / $views))],
            [__('Subscribed', 'mailoptin'), $done, $yes ? sprintf(__('%s of those who said yes', 'mailoptin'), Tips::percent($done / $yes, 0)) : ''],
        ];

        echo '<div class="mo-stats-funnel">';
        foreach ($steps as $s) {
            printf(
                '<div class="mo-stats-step"><div class="mo-stats-step-what">%s</div><div class="mo-stats-step-bar"><div style="width:%s%%"></div></div><div class="mo-stats-step-n">%s%s</div></div>',
                esc_html($s[0]),
                esc_attr(round(100 * $s[1] / $views, 2)),
                esc_html(number_format_i18n($s[1])),
                $s[2] ? '<small>' . esc_html($s[2]) . '</small>' : ''
            );
        }
        echo '</div>';

        if ( ! $on) printf('<p class="mo-stats-foot">%s</p>', esc_html__('Two steps is off for this campaign now; these are from when it was on.', 'mailoptin'));

        $this->tip(Tips::twostep($yes, $done, $this->optin_id));
        echo '</div>';
    }

    protected function breakdown_card(Report $report, $totals)
    {
        $rows = $report->breakdown($this->tab);
        if ('source' !== $this->tab) {
            // "Not recorded" goes last: it's what's unknown, not a place or device.
            usort($rows, function ($a, $b) {
                return ('' === $a['value']) <=> ('' === $b['value']);
            });
        }
        $names = [
            'device'  => __('Device', 'mailoptin'),
            'page'    => __('Page', 'mailoptin'),
            'source'  => __('Source', 'mailoptin'),
            'country' => __('Country', 'mailoptin'),
        ];

        echo '<div class="mo-stats-card" id="mo-stats-breakdown">';
        printf('<div class="mo-stats-card-head"><div><h3>%s</h3><p class="mo-stats-hint">%s</p></div></div>', esc_html__('Breakdown', 'mailoptin'), esc_html(sprintf(__('Views and %s by where they came from', 'mailoptin'), $this->conversions_label(true))));

        echo '<nav class="mo-stats-tabs" aria-label="' . esc_attr__('Breakdown', 'mailoptin') . '">';
        foreach ($names as $tab => $name) {
            printf('<a class="mo-stats-tab%s" href="%s#mo-stats-breakdown"%s>%s</a>', $tab === $this->tab ? ' is-on' : '', esc_url($this->url(['tab' => $tab])), $tab === $this->tab ? ' aria-current="page"' : '', esc_html($name));
        }
        echo '</nav>';

        // Long lists: the top 10, then the rest together.
        $shown = array_slice($rows, 0, 10);
        $rest  = array_slice($rows, 10);
        if ($rest) {
            $shown[] = [
                'value'      => '__rest',
                'impression' => array_sum(array_column($rest, 'impression')),
                'conversion' => array_sum(array_column($rest, 'conversion')),
                'count'      => count($rest),
            ];
        }

        // Shares and bars count the rows where this was recorded; "Not recorded" is listed, not counted.
        $recorded = array_sum(array_map(function ($r) {
            return '' === $r['value'] ? 0 : $r['impression'];
        }, $rows));
        $max_rate = 0;
        foreach ($shown as $r) {
            if ('' !== $r['value'] && $r['impression']) $max_rate = max($max_rate, $r['conversion'] / $r['impression']);
        }

        printf(
            '<table class="mo-stats-table"><thead><tr><th scope="col">%s</th><th scope="col">%s</th><th scope="col" class="mo-stats-hide-sm">%s</th><th scope="col">%s</th><th scope="col">%s</th></tr></thead><tbody>',
            esc_html($names[$this->tab]),
            esc_html__('Views', 'mailoptin'),
            esc_html__('Share of views', 'mailoptin'),
            esc_html($this->conversions_label()),
            esc_html__('Conversion rate', 'mailoptin')
        );

        foreach ($shown as $r) {
            $rate = Report::rate($r['conversion'], $r['impression']);
            [$label, $hint, $muted] = $this->row_label($r);

            printf(
                '<tr><td class="mo-stats-name%s">%s%s</td><td>%s</td><td class="mo-stats-hide-sm">%s</td><td>%s</td><td class="mo-stats-rate"><div class="mo-stats-bar"><span class="mo-stats-track">%s</span><b>%s</b></div></td></tr>',
                $muted ? ' is-muted' : '',
                esc_html($label),
                $hint ? '<small>' . esc_html($hint) . '</small>' : '',
                esc_html(number_format_i18n($r['impression'])),
                '' === $r['value'] || ! $recorded ? '—' : esc_html(Tips::percent($r['impression'] / $recorded, 0)),
                esc_html(number_format_i18n($r['conversion'])),
                null !== $rate && $max_rate > 0 && '' !== $r['value'] ? '<i style="width:' . esc_attr(round(100 * $rate / $max_rate, 1)) . '%"></i>' : '',
                null === $rate ? '—' : esc_html(Tips::percent($rate))
            );
        }

        if ( ! $shown) printf('<tr><td colspan="5" class="mo-stats-muted">%s</td></tr>', esc_html__('Nothing recorded for this period.', 'mailoptin'));
        echo '</tbody></table>';

        if ('country' === $this->tab) {
            printf('<p class="mo-stats-foot">%s</p>', esc_html__('Country comes from your CDN or WooCommerce\'s geolocation where there is one, otherwise from the visitor\'s time zone. No IP address is stored.', 'mailoptin'));
        }
        if (in_array($this->tab, ['device', 'country'], true) && array_filter($rows, function ($r) {
                return '' === $r['value'];
            })) {
            printf('<p class="mo-stats-foot">%s</p>', esc_html__('"Not recorded" is views from before device and country were recorded, and visitors whose browser didn\'t say.', 'mailoptin'));
        }

        $average = Report::rate($totals['conversion'], $totals['impression']);
        if ('device' === $this->tab) {
            $this->tip(Tips::device($rows, $this->optin_id));
        } elseif (in_array($this->tab, ['page', 'source'], true)) {
            $this->tip(Tips::standout($this->tab, $rows, $average, $this->optin_id));
        }

        echo '</div>';
    }

    /** [label, hint, muted] for a breakdown row. */
    protected function row_label($r)
    {
        if ('__rest' === $r['value']) {
            $what = [
                'device'  => _n('%s other device', '%s other devices', $r['count'], 'mailoptin'),
                'page'    => _n('%s other page', '%s other pages', $r['count'], 'mailoptin'),
                'source'  => _n('%s other source', '%s other sources', $r['count'], 'mailoptin'),
                'country' => _n('%s other country', '%s other countries', $r['count'], 'mailoptin'),
            ];

            return [sprintf($what[$this->tab], number_format_i18n($r['count'])), '', true];
        }

        if ('' === $r['value'] && 'source' !== $this->tab) return [Labels::not_recorded(), '', true];

        switch ($this->tab) {
            case 'device':
                return [Labels::device($r['value']), '', false];
            case 'country':
                return [Labels::country($r['value']), '', false];
            case 'page':
                return [Labels::page($r['value']), '', false];
        }

        return [Labels::source($r['value']), Labels::source_hint($r['value']), false];
    }

    protected function campaigns_card(Report $report)
    {
        $rows = $report->campaigns();
        if ( ! $rows) return;

        $best      = $report->best_devices();
        $best_cell = $best ? '<th scope="col" class="mo-stats-hide-sm">' . esc_html__('Converts best on', 'mailoptin') . '</th>' : '';

        echo '<div class="mo-stats-card">';
        printf('<div class="mo-stats-card-head"><div><h3>%s</h3><p class="mo-stats-hint">%s</p></div></div>', esc_html__('Campaigns', 'mailoptin'), esc_html__('Click one for its own breakdown', 'mailoptin'));
        printf(
            '<table class="mo-stats-table"><thead><tr><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th>%s</tr></thead><tbody>',
            esc_html__('Campaign', 'mailoptin'),
            esc_html__('Views', 'mailoptin'),
            esc_html__('Conversions', 'mailoptin'),
            esc_html__('Conversion rate', 'mailoptin'),
            $best_cell
        );

        foreach ($rows as $r) {
            $id   = $r['optin_id'];
            $name = OCR::get_optin_campaign_name($id);
            $rate = Report::rate($r['conversion'], $r['impression']);

            $about = [Labels::optin_type(OCR::get_optin_campaign_type($id))];
            if (OCR::get_customizer_value($id, 'twostep_status') === true) $about[] = __('two steps on', 'mailoptin');

            printf(
                '<tr><td class="mo-stats-name">%s<small>%s</small></td><td>%s</td><td>%s</td><td><b class="mo-stats-strong">%s</b></td>%s</tr>',
                $name ? sprintf('<a href="%s">%s</a>', esc_url($this->url(['optin' => $id, 'tab' => null])), esc_html($name)) : esc_html__('Deleted campaign', 'mailoptin'),
                esc_html(implode(' · ', $about)),
                esc_html(number_format_i18n($r['impression'])),
                esc_html(number_format_i18n($r['conversion'])),
                null === $rate ? '—' : esc_html(Tips::percent($rate)),
                ! $best ? '' : '<td class="mo-stats-hide-sm">' . (isset($best[$id]) ? esc_html(Labels::device($best[$id]['value']) . ' · ' . Tips::percent($best[$id]['rate'])) : '<span class="mo-stats-muted">—</span>') . '</td>'
            );
        }

        echo '</tbody></table></div>';
    }

    protected function tip($tip)
    {
        if ( ! $tip) return;

        $dismiss = wp_nonce_url(add_query_arg('mo_dismiss_tip', $tip['key'], $this->url()), 'mo_dismiss_tip');

        printf(
            '<div class="mo-stats-note"><span class="mo-stats-note-icon" aria-hidden="true">i</span><div><b>%s</b> %s</div><a class="mo-stats-note-close" href="%s" aria-label="%s">×</a></div>',
            esc_html($tip['headline']),
            esc_html($tip['advice']),
            esc_url($dismiss),
            esc_attr__('Dismiss this tip', 'mailoptin')
        );
    }

    protected function footer()
    {
        $first = Report::first_day();

        echo '<div class="mo-stats-footer"><p>';
        echo esc_html__('A view is counted each time a campaign shows. Daily totals are kept for 13 months, and the details behind the breakdowns for 40 days.', 'mailoptin');
        if ($first) echo ' ' . esc_html(sprintf(__('Stats start on %s.', 'mailoptin'), date_i18n(get_option('date_format'), strtotime($first))));
        echo '</p>';

        echo '<form method="post" onsubmit="return confirm(\'' . esc_js(__('Delete every stat on this page, for every campaign? This can\'t be undone. The totals on the Optin Campaigns list stay.', 'mailoptin')) . '\')">';
        wp_nonce_field('mo_chart_settings_page');
        echo '<input type="hidden" name="mo_process_form" value="clear_stat">';
        echo '<button type="submit" class="button-link mo-stats-danger">' . esc_html__('Clear all stats', 'mailoptin') . '</button></form></div>';
    }

    protected function script()
    {
        ?>
        <script>
            (function () {
                document.querySelectorAll('.mo-stats-filters select').forEach(function (s) {
                    s.addEventListener('change', function () { s.form.submit(); });
                });
                document.querySelectorAll('.mo-stats-apply').forEach(function (b) { b.style.display = 'none'; });

                document.querySelectorAll('.mo-stats-plot').forEach(function (plot) {
                    var points = JSON.parse(plot.getAttribute('data-points') || '[]'), tip = plot.querySelector('.mo-stats-tooltip');
                    plot.querySelectorAll('svg').forEach(function (svg) {
                        var hit = svg.querySelector('.mo-stats-hit'), cross = svg.querySelector('.mo-stats-cross'), dot = svg.querySelector('.mo-stats-dot');
                        var left = +hit.dataset.left, right = +hit.dataset.right, top = +hit.dataset.top, bottom = +hit.dataset.bottom, vb = svg.viewBox.baseVal;
                        function show(evt) {
                            var box = svg.getBoundingClientRect(), sx = (evt.clientX - box.left) * vb.width / box.width;
                            var i = points.length > 1 ? Math.round((sx - left) / (right - left) * (points.length - 1)) : 0;
                            i = Math.max(0, Math.min(points.length - 1, i));
                            var p = points[i], x = points.length > 1 ? left + i * (right - left) / (points.length - 1) : (left + right) / 2;
                            var y = 12 + (1 - p[1] / top) * (bottom - 12);
                            cross.setAttribute('x1', x); cross.setAttribute('x2', x); cross.setAttribute('visibility', 'visible');
                            dot.setAttribute('cx', x); dot.setAttribute('cy', y); dot.setAttribute('visibility', 'visible');
                            var rate = p[2] ? (100 * p[1] / p[2]).toFixed(1) + '%' : '—';
                            tip.innerHTML = '';
                            var b = document.createElement('b'); b.textContent = p[0]; tip.appendChild(b);
                            tip.appendChild(document.createTextNode(p[1] + ' <?php echo esc_js($this->conversions_label(true)); ?> · ' + p[2] + ' <?php echo esc_js(__('views', 'mailoptin')); ?> · ' + rate));
                            tip.hidden = false;
                            var px = x * box.width / vb.width, pb = plot.getBoundingClientRect();
                            tip.style.left = Math.min(Math.max(0, px - tip.offsetWidth / 2), pb.width - tip.offsetWidth) + 'px';
                            tip.style.top = (box.top - pb.top + y * box.height / vb.height - tip.offsetHeight - 14) + 'px';
                        }
                        function hide() { tip.hidden = true; cross.setAttribute('visibility', 'hidden'); dot.setAttribute('visibility', 'hidden'); }
                        hit.addEventListener('mousemove', show);
                        hit.addEventListener('touchstart', function (e) { show(e.touches[0]); }, {passive: true});
                        hit.addEventListener('mouseleave', hide);
                    });
                });
            })();
        </script>
        <?php
    }

    protected function styles()
    {
        ?>
        <style>
            .mo-stats{--blue:#2271b1;--ink:#1d2327;--text:#3c434a;--muted:#646970;--line:#dcdcde;--line-soft:#f0f0f1;--ok:#00701a;--ok-bg:#edfaef;--bad:#b32d2e;--soft:#f0f6fc;max-width:1180px;margin:8px 0 40px;color:var(--text);font-size:13px;line-height:1.5}
            .mo-stats *{box-sizing:border-box}
            .mo-stats .mo-stats-title-row h2{font-size:20px!important;line-height:1.3!important;color:var(--ink);margin:0!important;padding:0!important;font-weight:600}
            .mo-stats .mo-stats-card h3{margin:0;font-size:14px;font-weight:600;color:var(--ink)}
            .mo-stats a{color:var(--blue)}
            .mo-stats-title-row{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}
            .mo-stats-crumb{margin:0 0 4px}
            .mo-stats-crumb a{text-decoration:none}
            .mo-stats-sub{color:var(--muted);margin:4px 0 0}
            .mo-stats-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
            .mo-stats-select{display:inline-flex;align-items:center;gap:6px;min-height:40px;padding:0 0 0 12px;border:1px solid #8c8f94;border-radius:6px;background:#fff}
            .mo-stats-select span{color:var(--muted)}
            .mo-stats-select select{border:0;box-shadow:none;background-color:transparent;font-weight:600;color:var(--ink);min-height:38px;max-width:240px}
            .mo-stats-select:focus-within{outline:2px solid var(--blue);outline-offset:1px}
            .mo-stats-grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:16px}
            .mo-stats-card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:20px 24px;margin-bottom:16px;position:relative}
            .mo-stats-grid3 .mo-stats-card{margin-bottom:0}
            .mo-stats-label{color:var(--muted);margin:0 0 4px}
            .mo-stats-num{font-size:28px;line-height:1.2;color:var(--ink);font-weight:600}
            .mo-stats-delta{margin-top:8px}
            .mo-stats-up{color:var(--ok);font-weight:600}
            .mo-stats-down{color:var(--bad);font-weight:600}
            .mo-stats-muted,.mo-stats-hint,.mo-stats-foot{color:var(--muted)}
            .mo-stats-hint{margin:2px 0 0}
            .mo-stats-foot{margin:12px 0 0;font-size:12px}
            .mo-stats-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px;flex-wrap:wrap}
            .mo-stats-card-head > a{text-decoration:none}
            .mo-stats-tabs{display:flex;gap:4px;border-bottom:1px solid var(--line);margin:0 -24px 8px;padding:0 24px;overflow-x:auto}
            .mo-stats-tab{display:inline-flex;align-items:center;min-height:44px;padding:0 12px;color:var(--muted)!important;font-weight:500;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap}
            .mo-stats-tab.is-on{color:var(--ink)!important;border-color:var(--blue);font-weight:600}
            .mo-stats-tab:focus{box-shadow:none;outline:2px solid var(--blue);outline-offset:-2px}
            .mo-stats-table{width:100%;border-collapse:collapse}
            .mo-stats-table th{font-weight:500;color:var(--muted);text-align:right;padding:8px 12px;border-bottom:1px solid var(--line);font-size:12px}
            .mo-stats-table td{padding:12px;border-bottom:1px solid var(--line-soft);text-align:right;font-variant-numeric:tabular-nums}
            .mo-stats-table th:first-child,.mo-stats-table td:first-child{text-align:left;padding-left:0}
            .mo-stats-table tr:last-child td{border-bottom:0}
            .mo-stats-name{color:var(--ink);font-weight:500;word-break:break-word}
            .mo-stats-name.is-muted{color:var(--muted);font-weight:400}
            .mo-stats-name small{display:block;color:var(--muted);font-weight:400;font-size:12px}
            .mo-stats-name a{text-decoration:none}
            .mo-stats-strong,.mo-stats-bar b{font-weight:600;color:var(--ink)}
            .mo-stats-rate{width:34%}
            .mo-stats-bar{display:flex;align-items:center;gap:10px;justify-content:flex-end}
            .mo-stats-track{flex:1;max-width:220px;height:10px;display:flex}
            .mo-stats-track i{display:block;height:10px;border-radius:0 4px 4px 0;background:var(--blue)}
            .mo-stats-bar b{min-width:48px;text-align:right}
            .mo-stats-note{display:flex;gap:10px;align-items:flex-start;background:var(--soft);border-radius:6px;padding:12px 8px 12px 14px;color:var(--ink);margin-top:16px}
            .mo-stats-note-icon{flex:none;width:18px;height:18px;border-radius:50%;background:var(--blue);color:#fff;font:600 12px/18px Georgia,serif;text-align:center;margin-top:1px}
            .mo-stats-note > div{flex:1}
            .mo-stats-note-close{flex:none;display:inline-grid;place-items:center;width:44px;height:44px;margin:-12px 0 -12px;font-size:20px;color:var(--muted)!important;text-decoration:none;border-radius:6px}
            .mo-stats-note-close:hover{color:var(--ink)!important}
            .mo-stats-plot{position:relative}
            .mo-stats-plot svg{display:block;width:100%;height:auto;overflow:visible}
            .mo-stats-svg-narrow{display:none}
            .mo-stats-tooltip{position:absolute;background:#fff;border:1px solid var(--line);border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.08);padding:8px 12px;font-size:12px;pointer-events:none;white-space:nowrap;z-index:2}
            .mo-stats-tooltip b{display:block;color:var(--ink);font-size:13px}
            .mo-stats-funnel{display:grid;gap:14px}
            .mo-stats-step{display:grid;grid-template-columns:150px 1fr 220px;align-items:center;gap:16px}
            .mo-stats-step-what{color:var(--ink);font-weight:500}
            .mo-stats-step-bar div{height:24px;min-width:2px;border-radius:0 4px 4px 0;background:var(--blue)}
            .mo-stats-step-n{text-align:right;color:var(--ink);font-weight:600;font-variant-numeric:tabular-nums}
            .mo-stats-step-n small{display:block;color:var(--muted);font-weight:400;font-size:12px}
            .mo-stats-badge{display:inline-block;font-size:11px;font-weight:600;border-radius:999px;padding:2px 8px;background:var(--line-soft);color:var(--text);vertical-align:3px;margin-left:4px}
            .mo-stats-badge.is-ok{background:var(--ok-bg);color:var(--ok)}
            .mo-stats-empty{text-align:center;padding:40px 24px}
            .mo-stats-empty p{color:var(--muted);margin:6px auto 0;max-width:460px}
            .mo-stats-footer{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;color:var(--muted);font-size:12px}
            .mo-stats-footer p{margin:0;max-width:760px}
            .mo-stats-danger{color:var(--bad)!important;font-size:12px!important;min-height:32px}
            @media (max-width:782px){
                .mo-stats-grid3{grid-template-columns:1fr;gap:12px}
                .mo-stats-card{padding:16px}
                .mo-stats-tabs{margin:0 -16px 8px;padding:0 16px}
                .mo-stats-kpi{display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap}
                .mo-stats-kpi .mo-stats-num{font-size:22px}
                .mo-stats-kpi .mo-stats-delta{width:100%;margin-top:2px}
                .mo-stats-hide-sm,.mo-stats-track{display:none}
                .mo-stats-rate{width:auto}
                .mo-stats-table td,.mo-stats-table th{padding-left:8px;padding-right:0}
                .mo-stats-filters,.mo-stats-select{width:100%}
                .mo-stats-select{padding-right:4px}
                .mo-stats-select select{flex:1;min-width:0;max-width:none;width:100%}
                .mo-stats-svg-wide{display:none}
                .mo-stats-svg-narrow{display:block}
                .mo-stats-step{grid-template-columns:1fr auto}
                .mo-stats-step-bar{grid-column:1 / -1;order:3}
            }
        </style>
        <?php
    }
}
