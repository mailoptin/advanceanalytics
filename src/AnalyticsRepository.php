<?php

namespace MailOptin\AdvanceAnalytics;

use MailOptin\Core\Repositories\AbstractRepository;


class AnalyticsRepository extends AbstractRepository
{
    /**
     * Optin campaigns sorted by their number of conversions
     *
     * @return array
     */
    public static function top_converting_optins()
    {
        $table = AdvanceAnalytics::$advance_stat_table_name;
        $limit = apply_filters('mo_top_converting_optins_limit', 10);

        return self::wpdb()->get_results(
            "SELECT optin_id, COUNT(optin_id) AS occurrence FROM $table
            WHERE optin_id IS NOT NULL AND stat_type = 'conversion'
            GROUP BY optin_id
            ORDER BY occurrence DESC  LIMIT $limit;",
            'ARRAY_A'
        );
    }

    /**
     * Pages / url with the top optin conversion.
     *
     * @return array
     */
    public static function top_optin_conversion_pages()
    {
        $table = AdvanceAnalytics::$advance_stat_table_name;
        $limit = apply_filters('mo_top_optin_conversion_pages_limit', 10);

        return self::wpdb()->get_results(
            "SELECT conversion_page, COUNT(conversion_page) AS occurrence FROM $table
            WHERE conversion_page IS NOT NULL AND stat_type = 'conversion'
            GROUP BY conversion_page
            ORDER BY occurrence DESC  LIMIT $limit;",
            'ARRAY_A'
        );
    }

    /**
     * Optin campaigns sorted by their number of impressions
     *
     * @return array
     */
    public static function top_impressive_optins()
    {
        $table = AdvanceAnalytics::$advance_stat_table_name;
        $limit = apply_filters('mo_top_impressive_optins_limit', 10);

        return self::wpdb()->get_results(
            "SELECT optin_id, COUNT(optin_id) AS occurrence FROM $table
            WHERE optin_id IS NOT NULL AND stat_type = 'impression'
            GROUP BY optin_id
            ORDER BY occurrence DESC  LIMIT $limit;",
            'ARRAY_A'
        );
    }

    /**
     * Return array of {stat_type} count by date.
     *
     * @param string $date
     * @param string $stat_type
     * @param int|null $optin_id
     *
     * @return array|mixed|null|object
     */
    public static function get_stat_count_by_date($stat_type, $date, $optin_id = null)
    {
        $table = AdvanceAnalytics::$advance_stat_table_name;
        $sql = "SELECT COUNT(*) FROM $table WHERE stat_type = '%s' AND date = '%s'";
        $args = array($stat_type, $date);

        // ensure $optin_id came in well.
        if (isset($optin_id) && !is_null($optin_id) && is_int($optin_id)) {
            $sql .= " AND optin_id = %d";
            $args[] = $optin_id;
        }

        return absint(
            self::wpdb()->get_var(
                self::wpdb()->prepare($sql, $args)
            )
        );
    }

    /**
     * Record optin impressions.
     *
     * @param array $data
     *
     * @return int
     */
    public static function add_impression($data)
    {
        return self::add('impression', $data);
    }

    /**
     * Record optin conversions.
     *
     * @param array $data
     *
     * @return int
     */
    public static function add_conversion($data)
    {
        return self::add('conversion', $data);
    }


    /**
     * Add stat data.
     *
     * @param mixed $data
     *
     * @return int
     */
    public static function add($type, $data)
    {
        $response = parent::wpdb()->insert(
            AdvanceAnalytics::$advance_stat_table_name,
            array(
                'date' => date_i18n('Y-m-d'),
                'stat_type' => $type,
                'optin_id' => absint($data['optin_id']),
                'optin_type' => $data['optin_type'],
                'conversion_page' => $data['conversion_page'],
                'referrer' => $data['referrer']
            ),
            array(
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s'
            )
        );

        return !$response ? $response : self::wpdb()->insert_id;
    }

    /**
     * Get stat data by type.
     *
     * @param mixed $stat_type
     *
     * @return array|null|object|void
     */
    public static function get_by_stat_type($stat_type)
    {
        $table = AdvanceAnalytics::$advance_stat_table_name;

        return self::wpdb()->get_results(
            self::wpdb()->prepare("SELECT * FROM $table WHERE stat_type = '%s'", $stat_type),
            'ARRAY_A'
        );
    }

    /**
     * Get stat data by ID.
     *
     * @param mixed $id
     *
     * @return array|null|object|void
     */
    public static function get_by_id($id)
    {
        $table = AdvanceAnalytics::$advance_stat_table_name;

        return self::wpdb()->get_row(
            self::wpdb()->prepare("SELECT * FROM $table WHERE id = %d", $id),
            'ARRAY_A'
        );
    }

    /**
     * Delete stat by date.
     *
     * @param mixed $stat_date
     *
     * @return false|int
     */
    public static function delete_by_date($stat_date)
    {
        return parent::wpdb()->delete(
            AdvanceAnalytics::$advance_stat_table_name,
            array('date' => $stat_date),
            array('%s')
        );
    }

    /**
     * Delete stat by stat type.
     *
     * @param mixed $stat_type
     *
     * @return false|int
     */
    public static function delete_by_stat_type($stat_type)
    {
        return parent::wpdb()->delete(
            AdvanceAnalytics::$advance_stat_table_name,
            array('stat_type' => $stat_type),
            array('%s')
        );
    }

    /**
     * Delete all rows in start table.
     *
     * @return false|int
     */
    public static function delete_all_record()
    {
        $table = AdvanceAnalytics::$advance_stat_table_name;
        return parent::wpdb()->query(
            "truncate $table"
        );
    }
}