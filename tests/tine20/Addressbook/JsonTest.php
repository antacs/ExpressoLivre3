<?php
/**
 * Tine 2.0 - http://www.tine20.org
 * 
 * @package     Addressbook
 * @license     http://www.gnu.org/licenses/agpl.html
 * @copyright   Copyright (c) 2008-2011 Metaways Infosystems GmbH (http://www.metaways.de)
 * @author      Lars Kneschke <l.kneschke@metaways.de>
 * 
 * @todo        add testSetImage (NOTE: we can't test the upload yet, so we needd to simulate the upload)
 */

/**
 * Test helper
 */
require_once dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'TestHelper.php';

/**
 * Test class for Tinebase_Group
 */
class Addressbook_JsonTest extends PHPUnit_Framework_TestCase
{
    /**
     * instance of test class
     *
     * @var Addressbook_Frontend_Json
     */
    protected $_instance;
    
    /**
     * contacts that should be deleted later
     * 
     * @var array
     */
    protected $_contactIdsToDelete = array();
    
    /**
     * @var array test objects
     */
    protected $objects = array();
    
    /**
     * container to use for the tests
     *
     * @var Tinebase_Model_Container
     */
    protected $container;

    /**
     * Runs the test methods of this class.
     *
     * @access public
     * @static
     */
    public static function main()
    {
		$suite  = new PHPUnit_Framework_TestSuite('Tine 2.0 Addressbook Json Tests');
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
        $this->_instance = new Addressbook_Frontend_Json();
        
        $personalContainer = Tinebase_Container::getInstance()->getPersonalContainer(
            Zend_Registry::get('currentAccount'), 
            'Addressbook', 
            Zend_Registry::get('currentAccount'), 
            Tinebase_Model_Grants::GRANT_EDIT
        );
        
        if ($personalContainer->count() === 0) {
            $this->container = Tinebase_Container::getInstance()->addPersonalContainer(Zend_Registry::get('currentAccount')->accountId, 'Addressbook', 'PHPUNIT');
        } else {
            $this->container = $personalContainer[0];
        }
            	
        // define filter
        $this->objects['paging'] = array(
            'start' => 0,
            'limit' => 10,
            'sort' => 'n_fileas',
            'dir' => 'ASC',
        );
    }

    /**
     * Tears down the fixture
     * This method is called after a test is executed.
     *
     * @access protected
     */
    protected function tearDown()
    {
	    $this->_instance->deleteContacts($this->_contactIdsToDelete);
    }
    
    /**
     * try to get all contacts
     */
    public function testGetAllContacts()
    {
        $paging = $this->objects['paging'];
                
        $filter = array(
            array('field' => 'containerType', 'operator' => 'equals',   'value' => 'all'),
        );
        $contacts = $this->_instance->searchContacts($filter, $paging);
        
        $this->assertGreaterThan(0, $contacts['totalcount']);
    }    

    /**
     * test search contacts by list
     */
    public function testSearchContactsByList()
    {
        $paging = $this->objects['paging'];
        
        $adminListId = Tinebase_Group::getInstance()->getDefaultAdminGroup()->list_id;
        $filter = array(
            array('field' => 'list', 'operator' => 'equals',   'value' => $adminListId),
        );
        $contacts = $this->_instance->searchContacts($filter, $paging);
        
        $this->assertGreaterThan(0, $contacts['totalcount']);
        // check if user in admin list
        $found = FALSE;
        foreach ($contacts['results'] as $contact) {
            if ($contact['account_id'] == Tinebase_Core::getUser()->getId()) {
                $found = TRUE;
                break;
            }
        }
        $this->assertTrue($found);
    }    
    
    /**
     * try to get contacts by missing container
     *
     */
    public function testGetMissingContainerContacts()
    {
        $paging = $this->objects['paging'];
                
        $filter = array(
            array('field' => 'container_id', 'operator' => 'equals',   'value' => ''),
        );
        $contacts = $this->_instance->searchContacts($filter, $paging);
        
        $this->assertGreaterThan(0, $contacts['totalcount']);
    }    
    
    /**
     * try to get other people contacts
     *
     */
    public function testGetOtherPeopleContacts()
    {
        $paging = $this->objects['paging'];
        
        $filter = array(
            array('field' => 'containerType', 'operator' => 'equals',   'value' => 'otherUsers'),
        );
        $contacts = $this->_instance->searchContacts($filter, $paging);
        
        $this->assertGreaterThanOrEqual(0, $contacts['totalcount'], 'getting other peoples contacts failed');
    }
        
    /**
     * try to get contacts by owner
     *
     */
    public function testGetContactsByTelephone()
    {
        $this->_addContact();
        
        $paging = $this->objects['paging'];
        
        $filter = array(
            array('field' => 'telephone', 'operator' => 'contains', 'value' => '+49TELCELLPRIVATE')
        );
        $contacts = $this->_instance->searchContacts($filter, $paging);
        $this->assertEquals(1, $contacts['totalcount']);
    }

    /**
     * add a contact
     *
     * @param string $_orgName
     * @param boolean $_forceCreation
     * @return array contact data
     */
    protected function _addContact($_orgName = NULL, $_forceCreation = FALSE)
    {
        $newContactData = $this->_getContactData($_orgName);
        $newContact = $this->_instance->saveContact($newContactData, $_forceCreation);
        $this->assertEquals($newContactData['n_family'], $newContact['n_family'], 'Adding contact failed');
        
        $this->_contactIdsToDelete[] = $newContact['id'];
        
        return $newContact;
    }
    
    /**
     * get contact data
     * 
     * @param string $_orgName
     * @return array
     */
    protected function _getContactData($_orgName = NULL)
    {
        $note = array(
            'note_type_id'      => 1,
            'note'              => 'phpunit test note',            
        );
        
        return array(
            'n_given'           => 'ali',
            'n_family'          => 'PHPUNIT',
            'org_name'          => ($_orgName === NULL) ? Tinebase_Record_Abstract::generateUID() : $_orgName,
            'container_id'      => $this->container->id,
            'notes'             => array($note),
            'tel_cell_private'  => '+49TELCELLPRIVATE',
        );
    }
    
    /**
     * try to get contacts by owner
     *
     */
    public function testGetContactsByAddressbookId()
    {
        $this->_addContact();
        
        $paging = $this->objects['paging'];
        
        $filter = array(
            array('field' => 'containerType', 'operator' => 'equals',   'value' => 'singleContainer'),
            array('field' => 'container', 'operator' => 'equals',   'value' => $this->container->id),
        );
        $contacts = $this->_instance->searchContacts($filter, $paging);
        
        $this->assertGreaterThan(0, $contacts['totalcount']);
    }
    
    /**
     * try to get contacts by owner / container_id
     *
     */
    public function testGetContactsByOwner()
    {
        $this->_addContact();
        
        $paging = $this->objects['paging'];
        
        $filter = array(
            array('field' => 'containerType', 'operator' => 'equals',   'value' => 'personal'),
            array('field' => 'owner',  'operator' => 'equals',   'value' => Zend_Registry::get('currentAccount')->getId()),
        );
        $contacts = $this->_instance->searchContacts($filter, $paging);
        
        $this->assertGreaterThan(0, $contacts['totalcount']);
    }

    /**
     * test getting contact
     *
     */
    public function testGetContact()
    {
        $contact = $this->_addContact();
        
        $contact = $this->_instance->getContact($contact['id']);
        
        $this->assertEquals('PHPUNIT', $contact['n_family'], 'getting contact failed');
    }

    /**
     * test updating of a contact (including geodata)
     */
    public function testUpdateContactWithGeodata()
    {
        $contact = $this->_addContact();
        
        $contact['n_family'] = 'PHPUNIT UPDATE';
        $contact['adr_one_locality'] = 'Hamburg';
        $contact['adr_one_street'] = 'Pickhuben 2';
        $updatedContact = $this->_instance->saveContact($contact);
        
        $this->assertEquals($contact['id'], $updatedContact['id'], 'updated produced a new contact');
        $this->assertEquals('PHPUNIT UPDATE', $updatedContact['n_family'], 'updating data failed');

        if (Tinebase_Config::getInstance()->getConfig(Tinebase_Config::MAPPANEL, NULL, TRUE)->value) {
            // check geo data 
            $this->assertEquals('9.99489818142748', $updatedContact['adr_one_lon'], 'wrong geodata (lon)');
            $this->assertEquals('53.5444309689663', $updatedContact['adr_one_lat'], 'wrong geodata (lat)');
            
            // try another address
            $updatedContact['adr_one_locality']    = 'Wien';
            $updatedContact['adr_one_street']      = 'Blindengasse 52';
            $updatedContact['adr_one_postalcode']  = '1095';
            $updatedContact['adr_one_countryname'] = '';
            $updatedContact = $this->_instance->saveContact($updatedContact);
            
            // check geo data 
            $this->assertEquals('16.3419589',   $updatedContact['adr_one_lon'], 'wrong geodata (lon)');
            $this->assertEquals('48.2147964',   $updatedContact['adr_one_lat'], 'wrong geodata (lat)');
            $this->assertEquals('AT',           $updatedContact['adr_one_countryname'], 'wrong country');
            $this->assertEquals('1080',         $updatedContact['adr_one_postalcode'], 'wrong postalcode');
        }
    }
    
    /**
     * test deleting contact
     *
     */
    public function testDeleteContact()
    {
        $contact = $this->_addContact();
        
        $this->_instance->deleteContacts($contact['id']);
        
        $this->setExpectedException('Exception');
        $contact = $this->_instance->getContact($contact['id']);
    }
    
    /**
     * get all salutations
     *
     */
    public function testGetSalutations()
    {
        $salutations = $this->_instance->getSalutations();
        
        $this->assertGreaterThan(2, $salutations['totalcount']);
    }
    
    /**
     * test export data
     */
    public function testExport()
    {
        $filter = new Addressbook_Model_ContactFilter(array(
            array(
                'field'    => 'n_fileas',
                'operator' => 'equals',
                'value'    =>  Tinebase_Core::getUser()->accountDisplayName
            )
        ));
        $sharedTagName = Tinebase_Record_Abstract::generateUID();
        $tag = new Tinebase_Model_Tag(array(
            'type'  => Tinebase_Model_Tag::TYPE_SHARED,
            'name'  => $sharedTagName,
            'description' => 'testImport',
            'color' => '#009B31',
        ));
        Tinebase_Tags::getInstance()->attachTagToMultipleRecords($filter, $tag);
        
        $personalTagName = Tinebase_Record_Abstract::generateUID();
        $tag = new Tinebase_Model_Tag(array(
            'type'  => Tinebase_Model_Tag::TYPE_PERSONAL,
            'name'  => $personalTagName,
            'description' => 'testImport',
            'color' => '#009B31',
        ));
        Tinebase_Tags::getInstance()->attachTagToMultipleRecords($filter, $tag);
        
        // export first and create files array
        $exporter = new Addressbook_Export_Csv($filter, Addressbook_Controller_Contact::getInstance());
        $filename = $exporter->generate();
        $export = file_get_contents($filename);
        $this->assertContains($sharedTagName, $export, 'shared tag was not found in export:' . $export);
        $this->assertContains($personalTagName, $export, 'personal tag was not found in export:' . $export);
        
        // cleanup
        unset($filename);
        $sharedTagToDelete = Tinebase_Tags::getInstance()->getTagByName($sharedTagName);
        $personalTagToDelete = Tinebase_Tags::getInstance()->getTagByName($personalTagName);
        Tinebase_Tags::getInstance()->deleteTags(array($sharedTagToDelete->getId(), $personalTagToDelete->getId()));
    }
    
    /**
     * test import
     */
    public function testImport()
    {
        $result = $this->_importHelper();
        $this->assertEquals(2, $result['totalcount'], 'dryrun should detect 2 for import.' . print_r($result, TRUE));
        $this->assertEquals(0, $result['failcount'], 'Import failed for one or more records.');
        $this->assertEquals('Müller, Klaus', $result['results'][0]['n_fileas'], 'file as not found');
        
        // import again without dryrun
        $result = $this->_importHelper(array('dryrun' => 0));
        $this->assertEquals(2, $result['totalcount'], 'Didn\'t import anything.');
        $klaus = $result['results'][0];
        $this->assertEquals('Import list (' . Tinebase_Translation::dateToStringInTzAndLocaleFormat(Tinebase_DateTime::now(), NULL, NULL, 'date') . ')', $klaus['tags'][0]['name']);

        // import with duplicates
        $result = $this->_importHelper(array('dryrun' => 0));
        $this->assertEquals(0, $result['totalcount'], 'Do not import anything.');
        $this->assertEquals(2, $result['duplicatecount'], 'Should find 2 dups.');
        $this->assertEquals(1, count($result['exceptions'][0]['exception']['clientRecord']['tags']), '1 autotag expected');
        
        // import again with clientRecords
        $klaus['adr_one_locality'] = 'Hamburg';
        $clientRecords = array(array(
            'recordData'        => $klaus,
            'resolveStrategy'   => 'mergeMine',
            'index'             => 0,
        ));
        $result = $this->_importHelper(array('dryrun' => 0), $clientRecords);
        $this->assertEquals(1, $result['totalcount'], 'Should merge Klaus');
        $this->assertEquals(1, $result['duplicatecount'], 'Fritz is no duplicate.');
        $this->assertEquals('Hamburg', $result['results'][0]['adr_one_locality'], 'locality should change');
    }

    /**
    * test import with discard resolve strategy
    */
    public function testImportWithResolveStrategyDiscard()
    {
        $result = $this->_importHelper(array('dryrun' => 0));
        $fritz = $result['results'][1];
        
        $clientRecords = array(array(
        	'recordData'        => $fritz,
            'resolveStrategy'   => 'discard',
            'index'             => 1,
        ));
        $result = $this->_importHelper(array('dryrun' => 0), $clientRecords);
        $this->assertEquals(0, $result['totalcount'], 'Should discard fritz');
        $this->assertEquals(0, $result['failcount'], 'no failures expected');
        $this->assertEquals(1, $result['duplicatecount'], 'klaus should still be a duplicate');
    }
        
    /**
    * test import with mergeTheirs resolve strategy
    */
    public function testImportWithResolveStrategyMergeTheirs()
    {
        $result = $this->_importHelper(array('dryrun' => 0));
        $fritz = $result['results'][1];
        $fritz['tags'][] = array(
            'name'		=> 'new import tag'
        );
        
        $clientRecords = array(array(
            'recordData'        => $fritz,
            'resolveStrategy'   => 'mergeTheirs',
            'index'             => 1,
        ));
        $result = $this->_importHelper(array('dryrun' => 0), $clientRecords);
        $this->assertEquals(1, $result['totalcount'], 'Should merge fritz');
        $this->assertEquals(2, count($result['results'][0]['tags']), 'Should merge tags');
        $this->assertEquals(0, $result['failcount'], 'no failures expected');
        $this->assertEquals(1, $result['duplicatecount'], 'klaus should still be a duplicate');
    }
        
    /**
     * import helper
     * 
     * @param array $_additionalOptions
     * @param array $_clientRecords
     * @return array
     */
    protected function _importHelper($_additionalOptions = array('dryrun' => 1), $_clientRecords = array())
    {
        $definition = Tinebase_ImportExportDefinition::getInstance()->getByName('adb_tine_import_csv');
        $definitionOptions = Tinebase_ImportExportDefinition::getOptionsAsZendConfigXml($definition);
        
        $tempFileBackend = new Tinebase_TempFile();
        $tempFile = $tempFileBackend->createTempFile(dirname(dirname(dirname(dirname(__FILE__)))) . '/tine20/' . $definitionOptions->example);
        $options = array_merge($_additionalOptions, array(
            'container_id'  => $this->container->getId(),
        ));
        $result = $this->_instance->importContacts($tempFile->getId(), $definition->getId(), $options, $_clientRecords);
        if (isset($_additionalOptions['dryrun']) && $_additionalOptions['dryrun'] === 0) {
            foreach ($result['results'] as $contact) {
                $this->_contactIdsToDelete[] = $contact['id'];
            }
        }
        
        return $result;
    }
    
    /**
     * testImportWithTags
     */
    public function testImportWithTags()
    {
        $definition = Tinebase_ImportExportDefinition::getInstance()->getByName('adb_tine_import_csv');
        $definitionOptions = Tinebase_ImportExportDefinition::getOptionsAsZendConfigXml($definition);
        
        $options = array(
        	'dryrun'     => 0,
        	'autotags'   => array(array(
    	        'name'	        => 'Importliste (19.10.2011)',
    	        'description'	=> 'Kontakte der Importliste vom 19.10.2011 um 20.00 Uhr. Bearbeiter: UNITTEST',
    	        'contexts'		=> array('Addressbook' => ''),
    	        'type'			=> Tinebase_Model_Tag::TYPE_SHARED,
    	    )),
        );
        $result = $this->_importHelper($options);
        $fritz = $result['results'][1];
        
        $this->assertEquals(2, count($result['results']), 'should import 2');
        $this->assertEquals(1, count($result['results'][0]['tags']), 'no tag added');
        $this->assertEquals('Importliste (19.10.2011)', $result['results'][0]['tags'][0]['name']);
        
        $fritz['tags'] = array(array(
            'name'	=> 'supi',
            'type'	=> Tinebase_Model_Tag::TYPE_PERSONAL,
        ));
        $fritz = $this->_instance->saveContact($fritz);
        //print_r($fritz);
        
        // once again for duplicates (check if client record has tag)
        $result = $this->_importHelper($options);
        $this->assertEquals(1, count($result['exceptions'][0]['exception']['clientRecord']['tags']), 'no tag added');
        $this->assertEquals('Importliste (19.10.2011)', $result['exceptions'][0]['exception']['clientRecord']['tags'][0]['name']);
        $fritzClient = $result['exceptions'][1]['exception']['clientRecord'];
        
        $fritzClient['tags'][] = array(
            'name'	=> 'supi',
            'type'	=> Tinebase_Model_Tag::TYPE_PERSONAL,
        );
        $fritzClient['adr_one_locality'] = '';
        $fritzClient['id'] = $fritz['id'];
        $clientRecords = array(array(
            'recordData'        => $fritzClient,
            'resolveStrategy'   => 'mergeMine',
            'index'             => 1,
        ));
        //print_r($clientRecords);
        $result = $this->_importHelper(array('dryrun' => 0), $clientRecords);
        //print_r($result['results'][0]);
        $this->assertEquals(1, $result['totalcount'], 'Should merge fritz');
        $this->assertEquals(2, count($result['results'][0]['tags']), 'Should merge tags');
        $this->assertEquals(NULL, $result['results'][0]['adr_one_locality'], 'Should remove locality');

        //Tinebase_Tags::getInstance()->deleteTags(array($result['results'][0]['tags'][0]['id']));
    }

    /**
     * test project relation filter
     */
    public function testProjectRelationFilter()
    {
        $contact = $this->_instance->saveContact($this->_getContactData());
        $project = $this->_getProjectData($contact);
        
        $projectJson = new Projects_Frontend_Json();
        $newProject = $projectJson->saveProject($project);
        
        $this->_testProjectRelationFilter($contact, 'definedBy', $newProject);
        $this->_testProjectRelationFilter($contact, 'in', $newProject);
        $this->_testProjectRelationFilter($contact, 'equals', $newProject);
    }
    
    /**
     * get Project (create and link project + contacts)
     *
     * @return array
     */
    protected function _getProjectData($_contact)
    {
        $project = array(
            'title'         => Tinebase_Record_Abstract::generateUID(),
            'description'   => 'blabla',
            'status'        => 'IN-PROCESS',
        );
        
        $project['relations'] = array(
            array(
                'own_model'              => 'Projects_Model_Project',
                'own_backend'            => 'Sql',
                'own_id'                 => 0,
                'own_degree'             => Tinebase_Model_Relation::DEGREE_SIBLING,
                'type'                   => 'COWORKER',
                'related_backend'        => 'Sql',
                'related_id'             => $_contact['id'],
                'related_model'          => 'Addressbook_Model_Contact',
                'remark'                 => NULL,
            ),
            array(
                'own_model'              => 'Projects_Model_Project',
                'own_backend'            => 'Sql',
                'own_id'                 => 0,
                'own_degree'             => Tinebase_Model_Relation::DEGREE_SIBLING,
                'type'                   => 'RESPONSIBLE',
                'related_backend'        => 'Sql',
                'related_id'             => Tinebase_Core::getUser()->contact_id,
                'related_model'          => 'Addressbook_Model_Contact',
                'remark'                 => NULL,
            )
            
        );
        
        return $project;
    }
    
    /**
     * helper for project relation filter test
     * 
     * @param array $_contact
     * @param string
     * @param array $_project
     */
    protected function _testProjectRelationFilter($_contact, $_operator, $_project)
    {
        switch ($_operator) {
            case 'definedBy':
                $closedStatus = Projects_Config::getInstance()->get(Projects_Config::PROJECT_STATUS)->records->filter('is_open', 0);
                $filters = array(
                    array('field' => ":relation_type", "operator" => "equals", "value" => "COWORKER"),
                    array('field' => "status",         "operator" => "notin",  "value" => $closedStatus->getId()),
                    array('field' => 'id',             'operator' =>'in',      'value' => array($_project['id']))
                );
                break;
            case 'in':
                $filters = array(array('field' => 'id', 'operator' => $_operator, 'value' => array($_project['id'])));
                break;
            case 'equals':
                $filters = array(array('field' => 'id', 'operator' => $_operator, 'value' => $_project['id']));
                break;
        }
        
        $filterId = Tinebase_Record_Abstract::generateUID();
        $filter = array(
            array(
                'field'     => 'foreignRecord', 
                'operator'  => 'AND',
                'id'        => $filterId,
                'value' => array(
                    'linkType'      => 'relation',
                    'appName'       => 'Projects',
                    'modelName'     => 'Project',
                    'filters'       => $filters
                )
            ),
            array('field' => 'id', 'operator' => 'in', 'value' => array($_contact['id'], Tinebase_Core::getUser()->contact_id)),
        );
        $result = $this->_instance->searchContacts($filter, array());
        
        $this->assertEquals('relation', $result['filter'][0]['value']['linkType']);
        $this->assertTrue(isset($result['filter'][0]['id']), 'id expected');
        $this->assertEquals($filterId, $result['filter'][0]['id']);
        
        if ($_operator === 'definedBy') {
            $this->assertEquals(':relation_type',        $result['filter'][0]['value']['filters'][0]['field']);
            $this->assertEquals(1, $result['totalcount'], 'Should find only the COWORKER!');
            $this->assertEquals($_contact['org_name'], $result['results'][0]['org_name']);
        } else {
            $this->assertEquals(2, $result['totalcount'], 'Should find both contacts!');
        }
    }
    
    /**
     * testAttenderForeignIdFilter
     */
    public function testAttenderForeignIdFilter()
    {
        $contact = $this->_addContact();
        $event = $this->_getEvent($contact);
        Calendar_Controller_Event::getInstance()->create($event);
        
        $filter = array(
            array(
                'field' => 'foreignRecord', 
                'operator' => 'AND', 
                'value' => array(
                    'linkType'      => 'foreignId',
                    'appName'       => 'Calendar',
                    'filterName'    => 'ContactAttendeeFilter',
                    'modelName'     => 'Event',
                    'filters'       => array(
                        array('field' => "period",            "operator" => "within", "value" => array(
                            'from'  => '2009-01-01 00:00:00',
                            'until' => '2010-12-31 23:59:59',
                        )),
                        array('field' => 'attender_status',   "operator" => "in",  "value" => array('NEEDS-ACTION', 'ACCEPTED')),
                        array('field' => 'attender_role',     "operator" => "in",  "value" => array('REQ')),
                    )
                )
            ),
            array('field' => 'id', 'operator' => 'in', 'value' => array(Tinebase_Core::getUser()->contact_id, $contact['id']))
        );
        $result = $this->_instance->searchContacts($filter, array());
        $this->assertEquals('foreignRecord', $result['filter'][0]['field']);
        $this->assertEquals('foreignId', $result['filter'][0]['value']['linkType']);
        $this->assertEquals('ContactAttendeeFilter', $result['filter'][0]['value']['filterName']);
        $this->assertEquals('Event', $result['filter'][0]['value']['modelName']);
        
        $this->assertEquals(1, $result['totalcount']);
        $this->assertEquals(Tinebase_Core::getUser()->contact_id, $result['results'][0]['id']);
    }
    
    /**
     * testOrganizerForeignIdFilter
     */
    public function testOrganizerForeignIdFilter()
    {
        $contact = $this->_addContact();
        $event = $this->_getEvent($contact);
        Calendar_Controller_Event::getInstance()->create($event);
        
        $filter = array(
            $this->_getOrganizerForeignIdFilter(),
            array('field' => 'id', 'operator' => 'in', 'value' => array(Tinebase_Core::getUser()->contact_id, $contact['id']))
        );
        $result = $this->_instance->searchContacts($filter, array());
        
        $this->assertEquals(1, $result['totalcount']);
        $this->assertEquals(Tinebase_Core::getUser()->contact_id, $result['results'][0]['id']);
    }
    
    /**
     * return event organizuer filter
     * 
     * @return array
     */
    protected function _getOrganizerForeignIdFilter()
    {
        return array(
            'field' => 'foreignRecord', 
            'operator' => 'AND', 
            'value' => array(
                'linkType'      => 'foreignId',
                'appName'       => 'Calendar',
                'filterName'    => 'ContactOrganizerFilter',
                'filters'       => array(
                    array('field' => "period",            "operator" => "within", "value" => array(
                        'from'  => '2009-01-01 00:00:00',
                        'until' => '2010-12-31 23:59:59',
                    )),
                    array('field' => 'organizer',   "operator" => "equals",  "value" => Tinebase_Core::getUser()->contact_id),
                )
            )
        );
    }
    
    /**
     * testOrganizerForeignIdFilterWithOrCondition
     */
    public function testOrganizerForeignIdFilterWithOrCondition()
    {
        $contact = $this->_addContact();
        $event = $this->_getEvent($contact);
        Calendar_Controller_Event::getInstance()->create($event);
        
        $filter = array(array(
            'condition' => 'OR',
            'filters'   => array(
                $this->_getOrganizerForeignIdFilter(),
                array('field' => 'id', 'operator' => 'in', 'value' => array($contact['id']))
            )
        ));
        $result = $this->_instance->searchContacts($filter, array());
        
        $this->assertEquals(2, $result['totalcount'], 'expected 2 contacts');
    }
    
    /**
     * returns a simple event
     *
     * @param array $_contact
     * @return Calendar_Model_Event
     */
    protected function _getEvent($_contact)
    {
        $testCalendar = Tinebase_Container::getInstance()->addContainer(new Tinebase_Model_Container(array(
            'name'           => 'PHPUnit test calendar',
            'type'           => Tinebase_Model_Container::TYPE_PERSONAL,
            'backend'        => 'Sql',
            'application_id' => Tinebase_Application::getInstance()->getApplicationByName('Calendar')->getId()
        ), true));
        
        return new Calendar_Model_Event(array(
            'summary'     => 'Wakeup',
            'dtstart'     => '2009-03-25 06:00:00',
            'dtend'       => '2009-03-25 06:15:00',
            'description' => 'Early to bed and early to rise, makes a men healthy, wealthy and wise',
            'attendee'    => new Tinebase_Record_RecordSet('Calendar_Model_Attender', array(
                array(
                    'user_id'        => Tinebase_Core::getUser()->contact_id,
                    'user_type'      => Calendar_Model_Attender::USERTYPE_USER,
                    'role'           => Calendar_Model_Attender::ROLE_REQUIRED,
                    'status_authkey' => Tinebase_Record_Abstract::generateUID(),
                ),
                array(
                    'user_id'        => $_contact['id'],
                    'user_type'      => Calendar_Model_Attender::USERTYPE_USER,
                    'role'           => Calendar_Model_Attender::ROLE_OPTIONAL,
                    'status_authkey' => Tinebase_Record_Abstract::generateUID(),
                ),
            )),
        
            'container_id' => $testCalendar->getId(),
            'organizer'    => Tinebase_Core::getUser()->contact_id,
            'uid'          => Calendar_Model_Event::generateUID(),
        
            Tinebase_Model_Grants::GRANT_READ    => true,
            Tinebase_Model_Grants::GRANT_EDIT    => true,
            Tinebase_Model_Grants::GRANT_DELETE  => true,
        ));
    }

    /**
     * testDuplicateCheck
     */
    public function testDuplicateCheck($_duplicateCheck = TRUE)
    {
        $contact = $this->_addContact();
        try {
            $this->_addContact($contact['org_name'], $_duplicateCheck);
            $this->assertFalse($_duplicateCheck, 'duplicate detection failed');
        } catch (Tinebase_Exception_Duplicate $ted) {
            $this->assertTrue($_duplicateCheck, 'force creation failed');
            $exceptionData = $ted->toArray();
            $this->assertEquals(1, count($exceptionData['duplicates']), print_r($exceptionData['duplicates'], TRUE));
            $this->assertEquals($contact['n_given'], $exceptionData['duplicates'][0]['n_given']);
            $this->assertEquals($contact['org_name'], $exceptionData['duplicates'][0]['org_name']);
        }
    }

    /**
     * testDuplicateCheckWithEmail
     */
    public function testDuplicateCheckWithEmail()
    {
        $contact = $this->_getContactData();
        $contact['email'] = 'test@example.org';
        $contact = $this->_instance->saveContact($contact);
        $this->_contactIdsToDelete[] = $contact['id'];
        try {
            $contact2 = $this->_getContactData();
            $contact2['email'] = 'test@example.org';
            $contact2 = $this->_instance->saveContact($contact2);
            $this->_contactIdsToDelete[] = $contact2['id'];
            $this->assertTrue(FALSE, 'no duplicate exception');
        } catch (Tinebase_Exception_Duplicate $ted) {
            $exceptionData = $ted->toArray();
            $this->assertEquals(1, count($exceptionData['duplicates']));
            $this->assertEquals($contact['email'], $exceptionData['duplicates'][0]['email']);
        }
    }

    /**
     * testForceCreation
     */
    public function testForceCreation()
    {
        $this->testDuplicateCheck(FALSE);
    }

    /**
     * testImportDefinitionsInRegistry
     */
    public function testImportDefinitionsInRegistry()
    {
        $registryData = $this->_instance->getRegistryData();
        
        $this->assertEquals('adb_tine_import_csv', $registryData['defaultImportDefinition']['name']);
        $this->assertTrue(is_array($registryData['importDefinitions']['results']));
        
        $options = $registryData['defaultImportDefinition']['plugin_options'];
        $this->assertTrue(is_array($options));
        $this->assertEquals('Addressbook_Model_Contact', $options['model']);
        $this->assertTrue(is_array($options['autotags']));
        $this->assertEquals('Import list (###CURRENTDATE###)', $options['autotags'][0]['name']);
    }
}
