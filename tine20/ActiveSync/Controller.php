<?php
/**
 * Tine 2.0
 * 
 * MAIN controller for acticesync
 *
 * @package     ActiveSync
 * @subpackage  ActiveSync
 * @license     http://www.tine20.org/licenses/agpl-nonus.txt AGPL Version 1 (Non-US)
 *              NOTE: According to sec. 8 of the AFFERO GENERAL PUBLIC LICENSE (AGPL), 
 *              Version 1, the distribution of the Tine 2.0 ActiveSync module in or to the 
 *              United States of America is excluded from the scope of this license.
 * @author      Lars Kneschke <l.kneschke@metaways.de>
 * @copyright   Copyright (c) 2008-2009 Metaways Infosystems GmbH (http://www.metaways.de)
 * 
 */

/**
 * main controller for ActiveSync
 *
 * @package     ActiveSync
 * @subpackage  ActiveSync
 */
class ActiveSync_Controller extends Tinebase_Controller_Abstract
{
    const CLASS_CONTACTS = 'Contacts';
    const CLASS_CALENDAR = 'Calendar';
    const CLASS_TASKS    = 'Tasks';
    const CLASS_EMAIL    = 'Email';
    const STORE_MAILBOX  = 'Mailbox';
    
    /**
     * holds the instance of the singleton
     *
     * @var ActiveSync_Controller
     */
    private static $_instance = NULL;
    
    /**
     * the syncstate sql backend
     *
     * @var ActiveSync_Backend_SyncState
     */
    protected $_syncStateBackend;
    
    /**
     * device backend
     *
     * @var ActiveSync_Backend_Device
     */
    protected $_deviceBackend;
    
    /**
     * constructor (get current user)
     */
    private function __construct() {
        #$this->_currentAccount   = Tinebase_Core::getUser();
        $this->_syncStateBackend    = new ActiveSync_Backend_SyncState();
        $this->_deviceBackend       = new ActiveSync_Backend_Device();
    }
    
    /**
     * don't clone. Use the singleton.
     *
     */
    private function __clone() 
    {        
    }
    
    /**
     * the singleton pattern
     *
     * @return ActiveSync_Controller
     */
    public static function getInstance() 
    {
        if (self::$_instance === NULL) {
            self::$_instance = new ActiveSync_Controller;
        }
        
        return self::$_instance;
    }

    /**
     * the factory pattern for data controller
     *
     * @param string $_class the class name
     * @param ActiveSync_Model_Device $_device
     * @param Tinebase_DateTime $_syncTimeStamp
     * @return ActiveSync_Controller_Abstract
     */
    public static function dataFactory($_class, ActiveSync_Model_Device $_device, Tinebase_DateTime $_syncTimeStamp) 
    {
        switch($_class) {
            case self::CLASS_CONTACTS:
            case self::CLASS_CALENDAR:
            case self::CLASS_TASKS:
                $className = 'ActiveSync_Controller_' . $_class;
                $backend = new $className($_device, $_syncTimeStamp);
                break;
                
            case self::CLASS_EMAIL:
            case self::STORE_MAILBOX:
                $className = 'ActiveSync_Controller_' . self::CLASS_EMAIL;
                $backend = new $className($_device, $_syncTimeStamp);
                break;
                
            default:
                throw new Exception('unsupported class ActiveSync_Controller_' . $_class);
                break;
        }
        
        return $backend;
    }
    
    /**
     * get sync state
     *
     * @param ActiveSync_Model_Device $_device
     * @param string $_type
     * @param string|optional $_counter
     * @return ActiveSync_Model_SyncState
     */
    public function getSyncState(ActiveSync_Model_Device $_device, $_class, $_collectionId, $_counter = NULL)
    {
        $type = $_class . '-' . $_collectionId;
        
        $syncState = new ActiveSync_Model_SyncState(array(
            'device_id'  => $_device->getId(),
            'type'      => $type
        ));
        
        if($_counter !== NULL) {
            $syncState->counter = $_counter;
        }
        
        $syncState = $this->_syncStateBackend->get($syncState);
        
        return $syncState;
    }
    
    /**
     * search device
     *
     * @param string $_deviceId
     * @param string $_type
     * @param string|optional $_counter
     * @return ActiveSync_Model_SyncState
     */
    public function searchDevice(ActiveSync_Model_DeviceFilter $_deviceFilter)
    {
        $device = $this->_deviceBackend->search($_deviceFilter);
        
        return $device;
    }
    
    /**
     * get user device
     *
     * @param string $_deviceId
     * @param string $_deviceType
     * @param string $_userAgent
     * @param string $_acsVersion
     * @return ActiveSync_Model_Device
     */
    public function getUserDevice($_deviceId, $_deviceType, $_userAgent, $_acsVersion)
    {
        $deviceFilter = new ActiveSync_Model_DeviceFilter(array(
            array(
                'field'     => 'deviceid',
                'operator'  => 'equals',
                'value'     => $_deviceId
            ),
            array(
                'field'     => 'owner_id',
                'operator'  => 'equals',
                'value'     => Tinebase_Core::getUser()->accountId
            )
        ));
        
        $devices = $this->searchDevice($deviceFilter);
        
        if(count($devices) > 0) {
            // update existing device
            $device = $devices[0];
            $device->useragent  = $_userAgent;
            $device->acsversion = $_acsVersion;
            $device->devicetype = $_deviceType;
            
            $device = $this->updateDevice($device);
        } else {
            // create new device
            $device = new ActiveSync_Model_Device(array(
                'deviceid'   => $_deviceId,
                'devicetype' => $_deviceType,
                'owner_id'   => Tinebase_Core::getUser()->accountId,
                'policy_id'  => 1,
                'useragent'  => $_userAgent,
                'acsversion' => $_acsVersion,
                'policykey'  => ActiveSync_Command_Provision::generatePolicyKey()
            ));

            $device = $this->createDevice($device);
        }
        
        return $device;
    }
    
    /**
     * update device information
     * 
     * @param ActiveSync_Model_Device $_device
     * @return ActiveSync_Model_Device
     */
    public function updateDevice(ActiveSync_Model_Device $_device)
    {
        $device = $this->_deviceBackend->update($_device);
        
        return $device;
    }
    
    /**
     * store device information
     * 
     * @param ActiveSync_Model_Device $_device
     * @return ActiveSync_Model_Device
     */
    public function createDevice(ActiveSync_Model_Device $_device)
    {
        $device = $this->_deviceBackend->create($_device);
        
        return $device;
    }
    
    /**
     * validate sync key
     * 
     * @param ActiveSync_Model_Device $_device
     * @param $_counter
     * @param $_class
     * @param $_collectionId
     * @return boolean
     */
    public function validateSyncKey(ActiveSync_Model_Device $_device, $_counter, $_class, $_collectionId = NULL)
    {
        $type = $_collectionId !== NULL ? $_class . '-' . $_collectionId : $_class;
        
        $syncState = new ActiveSync_Model_SyncState(array(
            'device_id'  => $_device->getId(),
            'counter'   => $_counter,
            'type'      => $type
        ));
                
        try {
            $syncState = $this->_syncStateBackend->get($syncState);
            return true;
        } catch (ActiveSync_Exception_SyncStateNotFound $e) {
            // syncKey not found
            return false;
        }
    }
        
    /**
     * update sync key
     * 
     * @param ActiveSync_Model_Device $_device
     * @param Tinebase_DateTime $_counter
     * @param $_timeStamp
     * @param $_class
     * @param $_collectionId
     * @return void
     */
    public function updateSyncKey(ActiveSync_Model_Device $_device, $_counter, Tinebase_DateTime $_timeStamp, $_class, $_collectionId = NULL)
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' update synckey to ' . $_counter);
        
        $type = $_collectionId !== NULL ? $_class . '-' . $_collectionId : $_class;
        
        $syncState = new ActiveSync_Model_SyncState(array(
            'device_id' => $_device->getId(),
            'counter'   => $_counter,
            'type'      => $type,
            'lastsync'  => $_timeStamp->get(Tinebase_Record_Abstract::ISO8601LONG)
        ));
                
        try {
            // try to read current syncState
            $this->_syncStateBackend->get($syncState);
            // update syncState
            $this->_syncStateBackend->update($syncState);
        } catch (Exception $e) {
            // delete all old syncStates
            $this->_syncStateBackend->delete($syncState);
            // add new syncState
            $this->_syncStateBackend->create($syncState);
        }
    }
}
