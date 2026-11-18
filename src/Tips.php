<?php

namespace MailOptin\AdvanceAnalytics;

/**
 * Plain-language tips on the Statistics page. Each is a fixed rule, shown only when the difference is large
 * and the numbers big enough to trust (500 views a side, one rate at least double the other), at most one
 * per card, and each can be dismissed. A dismissed tip stays hidden until its numbers change a lot.
 */
class Tips
{
    const MIN_VIEWS = 500;
    const RATIO = 2;
    const MIN_TWOSTEP_YES = 100;
    const USER_META = 'mo_stats_dismissed_tips';

    /**
     * One device converting at half the rate or less of another.
     *
     * @param array[] $rows Report::breakdown('device').
     * @param int $optin_id For the dismiss key.
     */
    public static function device($rows, $optin_id = 0)
    {
        // Shares of the views where the device is known, as in the table.
        $total = array_sum(array_map(function ($r) {
            return '' === $r['value'] ? 0 : $r['impression'];
        }, $rows));
        $rows  = array_values(array_filter($rows, function ($r) {
            return '' !== $r['value'] && $r['impression'] >= self::MIN_VIEWS;
        }));

        if (count($rows) < 2 || ! $total) return null;

        usort($rows, function ($a, $b) {
            return ($b['conversion'] / $b['impression']) <=> ($a['conversion'] / $a['impression']);
        });

        $best      = $rows[0];
        $worst     = $rows[count($rows) - 1];
        $best_rate = $best['conversion'] / $best['impression'];
        $low_rate  = $worst['conversion'] / $worst['impression'];

        if ($best_rate <= 0 || $best_rate < self::RATIO * $low_rate) return null;

        $ratio = $low_rate > 0 ? $best_rate / $low_rate : 99;
        $part  = $ratio >= 4 ? __('a quarter or less of', 'mailoptin') : ($ratio >= 3 ? __('a third or less of', 'mailoptin') : __('half or less of', 'mailoptin'));

        $headline = sprintf(
            /* translators: 1: e.g. "Phones", 2: share of views, 3: their rate, 4: "a third or less of", 5: e.g. "desktop", 6: its rate */
            __('%1$s get %2$s of views but convert at %3$s, %4$s %5$s\'s %6$s.', 'mailoptin'),
            self::device_plural($worst['value']),
            self::percent($worst['impression'] / $total, 0),
            self::percent($low_rate),
            $part,
            strtolower(Labels::device($best['value'])),
            self::percent($best_rate)
        );

        $advice = Visitor::DESKTOP === $worst['value']
            ? __('Check the campaign on a big screen: it may be easy to miss there, or show too late.', 'mailoptin')
            : __('Forms are harder to fill in on a small screen. Try a slide-in or bar for them, fewer fields, or two steps.', 'mailoptin');

        return self::tip('device|' . $worst['value'] . '|' . $best['value'] . '|' . floor($ratio), $optin_id, $headline, $advice);
    }

    /**
     * One page or source converting at twice the average or more.
     *
     * @param string $dimension page or source.
     * @param array[] $rows Report::breakdown($dimension).
     * @param float|null $average The period's conversion rate.
     */
    public static function standout($dimension, $rows, $average, $optin_id = 0)
    {
        if ( ! $average) return null;

        $best = null;
        foreach ($rows as $r) {
            if ('' === $r['value'] || $r['impression'] < self::MIN_VIEWS) continue;
            $rate = $r['conversion'] / $r['impression'];
            if ($rate >= self::RATIO * $average && ( ! $best || $rate > $best['rate'])) $best = $r + ['rate' => $rate];
        }

        if ( ! $best) return null;

        $ratio = $best['rate'] / $average;
        $label = 'page' === $dimension ? Labels::page($best['value']) : Labels::source($best['value']);

        $headline = sprintf(
            /* translators: 1: page or site name, 2: its rate, 3: how many times the average, e.g. "2.4", 4: the average */
            'page' === $dimension ? __('"%1$s" converts at %2$s, %3$s times your average of %4$s.', 'mailoptin') : __('Visitors from %1$s convert at %2$s, %3$s times your average of %4$s.', 'mailoptin'),
            $label,
            self::percent($best['rate']),
            number_format_i18n($ratio, 1),
            self::percent($average)
        );

        $advice = 'page' === $dimension
            ? __('Pages on the same subject are a good place to show this campaign, or one made for them.', 'mailoptin')
            : __('A campaign of their own, with the Referrer display rule, may do even better.', 'mailoptin');

        return self::tip($dimension . '|' . md5($best['value']) . '|' . floor($ratio), $optin_id, $headline, $advice);
    }

    /** Most people who said yes to two steps didn't then subscribe. */
    public static function twostep($yes, $done, $optin_id = 0)
    {
        $done = min($done, $yes);

        if ($yes < self::MIN_TWOSTEP_YES || $done / $yes >= 0.5) return null;

        $lost = 1 - $done / $yes;

        $headline = sprintf(
            /* translators: %s: share of people, e.g. "58%" */
            __('%s of people who said yes didn\'t finish the form.', 'mailoptin'),
            self::percent($lost, 0)
        );

        return self::tip('twostep|' . floor($lost * 10), $optin_id, $headline, __('Fewer fields usually helps, and so does saying what they get by subscribing.', 'mailoptin'));
    }

    /** One wheel prize used twice as often as another: [label, use rate] each. */
    public static function wheel_prizes($best, $low, $optin_id = 0)
    {
        $headline = sprintf(
            /* translators: 1: prize, 2: its use rate, 3: another prize, 4: its use rate */
            __('%1$s is used by %2$s of winners, against %4$s for %3$s.', 'mailoptin'),
            $best[0], self::percent($best[1], 0), $low[0], self::percent($low[1], 0)
        );

        return self::tip('wheel|' . md5($best[0] . '|' . $low[0]) . '|' . floor($best[1] / max(0.0001, $low[1])), $optin_id, $headline, __('A bigger prize less often may bring in more orders than a small one most of the time.', 'mailoptin'));
    }

    protected static function tip($rule, $optin_id, $headline, $advice)
    {
        $key = substr(md5($rule . '|' . absint($optin_id)), 0, 12);

        if (in_array($key, self::dismissed(), true)) return null;

        return ['key' => $key, 'headline' => $headline, 'advice' => $advice];
    }

    public static function dismissed()
    {
        $keys = get_user_meta(get_current_user_id(), self::USER_META, true);

        return is_array($keys) ? $keys : [];
    }

    public static function dismiss($key)
    {
        $key = preg_replace('/[^a-f0-9]/', '', (string)$key);
        if (12 !== strlen($key)) return;

        $keys   = self::dismissed();
        $keys[] = $key;

        // The newest 200 are plenty.
        update_user_meta(get_current_user_id(), self::USER_META, array_slice(array_values(array_unique($keys)), -200));
    }

    /** 0.0456 -> "4.6%" */
    public static function percent($fraction, $decimals = 1)
    {
        return number_format_i18n(100 * (float)$fraction, $decimals) . '%';
    }

    protected static function device_plural($device)
    {
        switch ($device) {
            case Visitor::PHONE:
                return __('Phones', 'mailoptin');
            case Visitor::TABLET:
                return __('Tablets', 'mailoptin');
            default:
                return __('Desktops', 'mailoptin');
        }
    }
}
