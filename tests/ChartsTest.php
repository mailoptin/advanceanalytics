<?php

namespace MailOptin\AdvanceAnalytics\tests;


use MailOptin\AdvanceAnalytics\Charts;

class ChartsTest extends \WP_UnitTestCase
{
    /**
     * @var $classInstance Charts
     */
    private $classInstance;

    public function setUp()
    {
        parent::setUp();

        $this->classInstance = Charts::get_instance();
    }

    public function tearDown()
    {
        parent::tearDown();
    }
    
    public function testLast30Days()
    {
    }
}
