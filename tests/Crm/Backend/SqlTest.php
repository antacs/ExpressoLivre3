<?php
/**
 * Tine 2.0 - http://www.tine20.org
 * 
 * @package     Crm
 * @license     http://www.gnu.org/licenses/agpl.html
 * @copyright   Copyright (c) 2008 Metaways Infosystems GmbH (http://www.metaways.de)
 * @author      Lars Kneschke <l.kneschke@metaways.de>
 * @version     $Id$
 */

/**
 * Test helper
 */
require_once dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'TestHelper.php';

if (!defined('PHPUnit_MAIN_METHOD')) {
    define('PHPUnit_MAIN_METHOD', 'Crm_Backend_SqlTest::main');
}

/**
 * Test class for Tinebase_Account
 */
class Crm_Backend_SqlTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var array test objects
     */
    protected $objects = array();
    
    protected $testContainer;
    
    protected $backend;

    /**
     * Runs the test methods of this class.
     *
     * @access public
     * @static
     */
    public static function main()
    {
		$suite  = new PHPUnit_Framework_TestSuite('Tine 2.0 Crm SQL Backend Tests');
        PHPUnit_TextUI_TestRunner::run($suite);
	}

    /**
     * Sets up the fixture.
     * This method is called before a test is executed.
     *
     * @access protected
     */
    protected function setUp()
    {
        $personalContainer = Tinebase_Container::getInstance()->getPersonalContainer(
            Zend_Registry::get('currentAccount'), 
            'Crm', 
            Zend_Registry::get('currentAccount'), 
            Tinebase_Container::GRANT_EDIT
        );
        
        if($personalContainer->count() === 0) {
            $this->testContainer = Tinebase_Container::getInstance()->addPersonalContainer(Zend_Registry::get('currentAccount')->accountId, 'Crm', 'PHPUNIT');
        } else {
            $this->testContainer = $personalContainer[0];
        }
        
        $this->objects['initialLead'] = new Crm_Model_Lead(array(
            'id'            => 120,
            'lead_name'     => 'PHPUnit',
            'leadstate_id'  => 1,
            'leadtype_id'   => 1,
            'leadsource_id' => 1,
            'container'     => $this->testContainer->id,
            'start'         => Zend_Date::now(),
            'description'   => 'Description',
            'end'           => Zend_Date::now(),
            'turnover'      => '200000',
            'probability'   => 70,
            'end_scheduled' => Zend_Date::now(),
        )); 
        
        $this->objects['updatedLead'] = new Crm_Model_Lead(array(
            'id'            => 120,
            'lead_name'     => 'PHPUnit',
            'leadstate_id'  => 1,
            'leadtype_id'   => 1,
            'leadsource_id' => 1,
            'container'     => $this->testContainer->id,
            'start'         => Zend_Date::now(),
            'description'   => 'Description updated',
            'end'           => NULL,
            'turnover'      => '200000',
            'probability'   => 70,
            'end_scheduled' => NULL,
        )); 
        
        $this->backend = new Crm_Backend_Sql();
    }

    /**
     * Tears down the fixture
     * This method is called after a test is executed.
     *
     * @access protected
     */
    protected function tearDown()
    {
	   #Tinebase_Container::getInstance()->deleteContainer($this->testContainer->id);
    }
    
    /**
     * try to add a lead
     *
     */
    public function testAddLead()
    {
        $lead = $this->backend->addLead($this->objects['initialLead']);
        
        $this->assertEquals($this->objects['initialLead']->id, $lead->id);
        $this->assertEquals($this->objects['initialLead']->description, $lead->description);
    }

    /**
     * try to get a lead
     *
     */
    public function testGetLead()
    {
        $lead = $this->backend->getLead($this->objects['initialLead']);
        
        $this->assertEquals($this->objects['initialLead']->id, $lead->id);
        $this->assertEquals($this->objects['initialLead']->description, $lead->description);
    }
    
    /**
     * try to update a lead
     *
     */
    public function testUpdateLead()
    {
        $lead = $this->backend->updateLead($this->objects['updatedLead']);
        
        $this->assertEquals($this->objects['updatedLead']->id, $lead->id);
        $this->assertEquals($this->objects['updatedLead']->description, $lead->description);
    }

    /**
     * try to get multiple leads
     *
     */
    public function testGetLeads()
    {
        $leads = $this->backend->getLeads(array($this->testContainer->id), 'PHPUnit');
        
        $this->assertEquals(1, count($leads));
    }
    
    /**
     * try to delete a contact
     *
     */
    public function testDeleteLead()
    {
        $this->backend->deleteLead($this->objects['initialLead']);
        
        $this->setExpectedException('UnderflowException');
        
        $this->backend->getLead($this->objects['initialLead']);
    }
    
    /**
     * try to get the lead sources
     *
     */
    public function testGetLeadSources()
    {
        $sources = $this->backend->getLeadSources();
        
        $this->assertEquals(4, count($sources));
    }
    
}		
	

if (PHPUnit_MAIN_METHOD == 'Crm_Backend_SqlTest::main') {
    Crm_Backend_SqlTest::main();
}
