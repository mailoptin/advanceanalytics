<?php

namespace MailOptin\Tests\AdvanceAnalytics;

use MailOptin\AdvanceAnalytics\AnalyticsRepository;
use WP_UnitTestCase;

class AnalyticsRepositoryTest extends WP_UnitTestCase
{
    public function setUp()
    {
        parent::setUp();
    }

    public function tearDown()
    {
        parent::tearDown();
    }


    public function addConversion()
    {
        return AnalyticsRepository::add_conversion(
            array(
                'optin_id' => 22,
                'optin_type' => 'lightbox',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/54.0.2840.99 Safari/537.36 OPR/41.0.2353.69',
                'conversion_page' => 'http://wordpress.dev/hello-world/',
                'referrer' => 'http://wordpress.dev/'
            )
        );
    }


    public function addConversionAlternate()
    {
        return AnalyticsRepository::add_conversion(
            array(
                'optin_id' => 15,
                'optin_type' => 'lightbox',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/54.0.2840.99 Safari/537.36 OPR/41.0.2353.69',
                'conversion_page' => 'http://goal.dev/',
                'referrer' => 'http://wordpress.dev/'
            )
        );
    }

    public function addImpression()
    {
        return AnalyticsRepository::add_impression(
            array(
                'optin_id' => 22,
                'optin_type' => 'lightbox',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/54.0.2840.99 Safari/537.36 OPR/41.0.2353.69',
                'conversion_page' => 'http://wordpress.dev/hello-world/',
                'referrer' => 'http://wordpress.dev/'
            )
        );
    }

    public function testAddImpression()
    {
        $impression = $this->addImpression();

        $this->assertInternalType('integer', $impression);
        $fetch = AnalyticsRepository::get_by_optin_id($impression);
        $this->assertSame(22, absint($fetch['optin_id']));
        $this->assertSame('impression', $fetch['stat_type']);
    }

    public function testAddConversion()
    {
        $conversion = $this->addConversion();

        $this->assertInternalType('integer', $conversion);
        $fetch = AnalyticsRepository::get_by_optin_id($conversion);
        $this->assertSame(22, absint($fetch['optin_id']));
        $this->assertSame('conversion', $fetch['stat_type']);

    }

    public function testDelete()
    {
        $conversion = $this->addConversion();
        $this->assertInternalType('integer', $conversion);
        AnalyticsRepository::delete_by_stat_type('conversion');
        $this->assertNull(AnalyticsRepository::get_by_optin_id($conversion));

        $conversion = $this->addConversion();
        $this->assertInternalType('integer', $conversion);
        AnalyticsRepository::delete_by_date(date_i18n('Y-m-d'));
        $this->assertNull(AnalyticsRepository::get_by_optin_id($conversion));
    }

    public function testGetByStatType()
    {
        $this->addConversion();
        $this->addConversion();
        $this->addConversion();
        $result = AnalyticsRepository::get_by_stat_type('conversion');
        $this->assertCount(3, $result);


        $this->addImpression();
        $this->addImpression();
        $result = AnalyticsRepository::get_by_stat_type('impression');
        $this->assertInternalType('array', $result);
        $this->assertCount(2, $result);
    }

    public function testGetStatCountByDate()
    {
        $this->addConversion();
        $this->addConversion();
        $this->addConversion();

        $result = AnalyticsRepository::get_stat_count_by_date('conversion', date_i18n('Y-m-d'));
        $this->assertInternalType('integer', $result);
        $this->assertSame(3, $result);

        $this->addConversionAlternate();
        $result = AnalyticsRepository::get_stat_count_by_date('conversion', date_i18n('Y-m-d'), 15);
        $this->assertInternalType('integer', $result);
        $this->assertSame(1, $result);
        
    }

    public function testTopOptinConversionPages()
    {
        $this->addConversion();
        $this->addConversion();
        $this->addConversion();
        $this->addConversionAlternate();
        $this->addConversionAlternate();
        $this->addConversionAlternate();
        $this->addConversionAlternate();
        $this->addConversionAlternate();

        $result = AnalyticsRepository::top_optin_conversion_pages();

        $this->assertSame('http://goal.dev/', $result[0]['conversion_page']);
        
    }

    public function testTopConvertingOptin()
    {
        $this->addConversion();
        $this->addConversion();
        $this->addConversion();
        $this->addConversionAlternate();
        $this->addConversionAlternate();
        $this->addConversionAlternate();
        $this->addConversionAlternate();
        $this->addConversionAlternate();

        $result = AnalyticsRepository::top_converting_optins();
        $this->assertSame(15, absint($result[0]['optin_id']));
    }
}