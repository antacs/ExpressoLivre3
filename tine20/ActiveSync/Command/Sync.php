<?php
/**
 * Tine 2.0
 *
 * @package     ActiveSync
 * @license     http://www.tine20.org/licenses/agpl-nonus.txt AGPL Version 1 (Non-US)
 *              NOTE: According to sec. 8 of the AFFERO GENERAL PUBLIC LICENSE (AGPL), 
 *              Version 1, the distribution of the Tine 2.0 ActiveSync module in or to the 
 *              United States of America is excluded from the scope of this license.
 * @copyright   Copyright (c) 2009 Metaways Infosystems GmbH (http://www.metaways.de)
 * @author      Lars Kneschke <l.kneschke@metaways.de>
 */

/**
 * class to handle ActiveSync Sync command
 *
 * @package     ActiveSync
 */
 
class ActiveSync_Command_Sync extends ActiveSync_Command_Wbxml 
{
    const STATUS_SUCCESS                                = 1;
    const STATUS_PROTOCOL_VERSION_MISMATCH              = 2;
    const STATUS_INVALID_SYNC_KEY                       = 3;
    const STATUS_PROTOCOL_ERROR                         = 4;
    const STATUS_SERVER_ERROR                           = 5;
    const STATUS_ERROR_IN_CLIENT_SERVER_CONVERSION      = 6;
    const STATUS_CONFLICT_MATCHING_THE_CLIENT_AND_SERVER_OBJECT = 7;
    const STATUS_OBJECT_NOT_FOUND                       = 8;
    const STATUS_USER_ACCOUNT_MAYBE_OUT_OF_DISK_SPACE   = 9;
    const STATUS_ERROR_SETTING_NOTIFICATION_GUID        = 10;
    const STATUS_DEVICE_NOT_PROVISIONED_FOR_NOTIFICATIONS = 11;
    const STATUS_FOLDER_HIERARCHY_HAS_CHANGED           = 12;
    const STATUS_RESEND_FULL_XML                        = 13;
    const STATUS_WAIT_INTERVAL_OUT_OF_RANGE             = 14;
    
    const CONFLICT_OVERWRITE_SERVER                     = 0;
    const CONFLICT_OVERWRITE_PIM                        = 1;
    
    const MIMESUPPORT_DONT_SEND_MIME                    = 0;
    const MIMESUPPORT_SMIME_ONLY                        = 1;
    const MIMESUPPORT_SEND_MIME                         = 2;
    
    const BODY_TYPE_PLAIN_TEXT                          = 1;
    const BODY_TYPE_HTML                                = 2;
    const BODY_TYPE_RTF                                 = 3;
    const BODY_TYPE_MIME                                = 4;


    protected $_defaultNameSpace    = 'uri:AirSync';
    protected $_documentElement     = 'Sync';
    
    /**
     * list of collections
     *
     * @var array
     */
    protected $_collections = array();

    /**
     * the contentState sql backend
     *
     * @var ActiveSync_Backend_ContentState
     */
    protected $_contentStateBackend;
    
    /**
     * the folderState sql backend
     *
     * @var ActiveSync_Backend_FolderState
     */
    protected $_folderStateBackend;
    
    /**
     * total count of items in all collections
     *
     * @var integer
     */
    protected $_totalCount;
    
    /**
     * there are more entries than WindowSize available
     * the MoreAvailable tag hot added to the xml output
     *
     * @var boolean
     */
    protected $_moreAvailable = false;
    
    /**
     * instance of ActiveSync_Controller
     *
     * @var ActiveSync_Controller
     */
    protected $_controller;
    
    protected $_session;
    
    /**
     * the constructor
     *
     * @param ActiveSync_Model_Device $_device
     */
    public function __construct(ActiveSync_Model_Device $_device)
    {
        parent::__construct($_device);
        
        $this->_contentStateBackend  = new ActiveSync_Backend_ContentState();
        $this->_folderStateBackend   = new ActiveSync_Backend_FolderState();
        $this->_session              = new Zend_Session_Namespace('moreData');
        $this->_controller           = ActiveSync_Controller::getInstance();

        // continue sync / MoreAvailable sent in previous repsonse
        if(isset($this->_session->syncTimeStamp)) {
            $this->_syncTimeStamp = $this->_session->syncTimeStamp;
        }
    }
    
    /**
     * process the XML file and add, change, delete or fetches data 
     *
     * @todo can we get rid of LIBXML_NOWARNING
     * @return resource
     */
    public function handle()
    {
        // input xml
        $xml = new SimpleXMLElement($this->_inputDom->saveXML());
        #$xml = simplexml_import_dom($this->_inputDom);
        
        foreach ($xml->Collections->Collection as $xmlCollection) {
            $clientSyncKey  = (int)$xmlCollection->SyncKey;
            $collectionId   = (string)$xmlCollection->CollectionId;

            $collectionData = array(
                'syncKey'         => $clientSyncKey,
                'syncKeyValid'    => true,
                'class'           => isset($xmlCollection->Class) ? (string)$xmlCollection->Class : null,
                'collectionId'    => $collectionId,
                'windowSize'      => isset($xmlCollection->WindowSize) ? (int)$xmlCollection->WindowSize : 100,
                'deletesAsMoves'  => isset($xmlCollection->DeletesAsMoves) ? true : false,
                'getChanges'      => isset($xmlCollection->GetChanges) ? true : false,
                'added'           => array(),
                'changed'         => array(),
                'deleted'         => array(),
                'forceAdd'        => array(),
                'forceChange'     => array(),
                'toBeFetched'     => array(),
                'filterType'      => 0,
                'mimeSupport'     => self::MIMESUPPORT_DONT_SEND_MIME,
                'mimeTruncation'  => 8,
                'bodyPreferences' => array()
            );
            
            // process options
            if (isset($xmlCollection->Options)) {
                // optional parameters
                if (isset($xmlCollection->Options->FilterType)) {
                    $collectionData['filterType'] = (int)$xmlCollection->Options->FilterType;
                }
                if (isset($xmlCollection->Options->MIMESupport)) {
                    $collectionData['mimeSupport'] = (int)$xmlCollection->Options->MIMESupport;
                }
                if (isset($xmlCollection->Options->MIMETruncation)) {
                    $collectionData['mimeTruncation'] = (int)$xmlCollection->Options->MIMETruncation;
                }
                
                // try to fetch element from AirSyncBase:BodyPreference
                $airSyncBase = $xmlCollection->Options->children('uri:AirSyncBase');
                
                if (isset($airSyncBase->BodyPreference)) {
                    
                    foreach ($airSyncBase->BodyPreference as $bodyPreference) {
                        $type = (int) $bodyPreference->Type;
                        $collectionData['bodyPreferences'][$type] = array(
                            'type' => $type
                        );
                        
                        // optional
                        if (isset($bodyPreference->TruncationSize)) {
                            $collectionData['bodyPreferences'][$type]['truncationSize'] = (int) $bodyPreference->TruncationSize;
                        }
                    }
                }
            }
            
            // does the folder exist for this device
            try {
                $folder         = $this->getFolderState($this->_device, $collectionId);
                // newer clients don't send the class tag anymore
                $collectionData['class'] = $folder->class;
            } catch (Tinebase_Exception_NotFound $e) {
                Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ . " folder $collectionId not found");
                $this->_collections['collectionNotFound'][$collectionId] = $collectionData;
                continue;
            }
            
            Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " SyncKey is $clientSyncKey Class: $folder->class CollectionId: $collectionId");
            
            
            $this->_collections[$collectionData['class']][$collectionId] = $collectionData;
            
            if($clientSyncKey === 0) {
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->DEBUG(__METHOD__ . '::' . __LINE__ . " client synckey $clientSyncKey provided");
                $this->_collections[$folder->class][$collectionId]['syncKeyValid'] = false;
                continue;
            }
            if($this->_controller->validateSyncKey($this->_device, $clientSyncKey, $folder->class, $collectionId) !== true) {
                Tinebase_Core::getLogger()->WARN(__METHOD__ . '::' . __LINE__ . " invalid synckey $clientSyncKey provided");
                $this->_collections[$folder->class][$collectionId]['syncKeyValid'] = false;
                continue;
            }
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__);
            $dataController = ActiveSync_Controller::dataFactory($folder->class, $this->_device, $this->_syncTimeStamp);
            
            // handle incoming data
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__);
            if(isset($xmlCollection->Commands->Add)) {
                $adds = $xmlCollection->Commands->Add;
                Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " found " . count($adds) . " entries to be added to server");
                
                foreach ($adds as $add) {
                	if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " add entry with clientId " . (string) $add->ClientId);
                    // search for existing entries if first sync
                    if($clientSyncKey == 1) {
                        $existing = $dataController->search($collectionId, $add->ApplicationData);
                    } else {
                        $existing = array(); // count() == 0
                    }
                    
                    try {
                        if(count($existing) === 0) {
                            Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " entry not found. adding as new");
                            $added = $dataController->add($collectionId, $add->ApplicationData);
                        } else {
                            Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " found matching entry. reuse existing entry");
                            // use the first found entry
                            $added = $existing[0];
                        }
                        $this->_collections[$folder->class][$collectionId]['added'][(string)$add->ClientId]['serverId'] = $added->getId(); 
                        $this->_collections[$folder->class][$collectionId]['added'][(string)$add->ClientId]['status'] = self::STATUS_SUCCESS;
                        $this->_addContentState($collectionData['class'], $collectionData['collectionId'], $added->getId());
                    } catch (Exception $e) {
                        Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ . " failed to add entry " . $e->getMessage());
                        $this->_collections[$folder->class][$collectionId]['added'][(string)$add->ClientId]['status'] = self::STATUS_SERVER_ERROR;
                    }
                }
            }
        
            // handle changes, but only if not first sync
            if($clientSyncKey > 1 && isset($xmlCollection->Commands->Change)) {
                $changes = $xmlCollection->Commands->Change;
                Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " found " . count($changes) . " entries to be updated on server");
                foreach ($changes as $change) {
                    $serverId = (string)$change->ServerId;
                    try {
                        $changed = $dataController->change($collectionId, $serverId, $change->ApplicationData);
                        $this->_collections[$folder->class][$collectionId]['changed'][$serverId] = self::STATUS_SUCCESS;
                    } catch (Tinebase_Exception_AccessDenied $e) {
                        $this->_collections[$folder->class][$collectionId]['changed'][$serverId] = self::STATUS_CONFLICT_MATCHING_THE_CLIENT_AND_SERVER_OBJECT;
                        $this->_collections[$folder->class][$collectionId]['forceChange'][$serverId] = $serverId;
                    } catch (Tinebase_Exception_NotFound $e) {
                        // entry does not exist anymore, will get deleted automaticly
                        $this->_collections[$folder->class][$collectionId]['changed'][$serverId] = self::STATUS_OBJECT_NOT_FOUND;
                    } catch (Exception $e) {
                        Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ . " failed to update entry " . $e);
                        // something went wrong while trying to update the entry
                        $this->_collections[$folder->class][$collectionId]['changed'][$serverId] = self::STATUS_SERVER_ERROR;
                    }
                }
            }
        
            // handle deletes, but only if not first sync
            if($clientSyncKey > 1 && isset($xmlCollection->Commands->Delete)) {
                $deletes = $xmlCollection->Commands->Delete;
                Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " found " . count($deletes) . " entries to be deleted on server");
                foreach ($deletes as $delete) {
                    $serverId = (string)$delete->ServerId;
                    try {
                        $dataController->delete($collectionId, $serverId, $collectionData);
                    } catch(Tinebase_Exception_NotFound $e) {
                        Tinebase_Core::getLogger()->crit(__METHOD__ . '::' . __LINE__ . ' tried to delete entry ' . $serverId . ' but entry was not found');
                    } catch (Tinebase_Exception $e) {
                        Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' tried to delete entry ' . $serverId . ' but permission was denied');
                        $this->_collections[$folder->class][$collectionId]['forceAdd'][$serverId] = $serverId;
                    }
                    $this->_collections[$folder->class][$collectionId]['deleted'][$serverId] = self::STATUS_SUCCESS;
                    $this->_deleteContentState($collectionData['class'], $collectionData['collectionId'], $serverId);
                }
            }
                        
            // handle deletes, but only if not first sync
            if($clientSyncKey > 1 && isset($xmlCollection->Commands->Fetch)) {
                // the default value for GetChanges is 1. If the phone don't want the changes it must set GetChanges to 0
                // unfortunately the iPhone dont set GetChanges to 0 when fetching email body, but is confused when we send
                // changes
                if (! isset($xmlCollection->GetChanges)) {
                    $this->_collections[$folder->class][$collectionId]['getChanges'] = false;
                }
                
                $fetches = $xmlCollection->Commands->Fetch;
                Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " found " . count($fetches) . " entries to be fetched from server");
                foreach ($fetches as $fetch) {
                    $serverId = (string)$fetch->ServerId;
                    
                    $this->_collections[$folder->class][$collectionId]['toBeFetched'][$serverId] = $serverId;
                }
            }            
            
        }  
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__);       
    }    
    
    /**
     * (non-PHPdoc)
     * @see ActiveSync_Command_Wbxml::getResponse()
     */
    public function getResponse($_keepSession = false)
    {
        // add aditional namespaces for contacts, tasks and email
        $this->_outputDom->documentElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:Contacts'    , 'uri:Contacts');
        $this->_outputDom->documentElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:Tasks'       , 'uri:Tasks');
        $this->_outputDom->documentElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:Email'       , 'uri:Email');
        $this->_outputDom->documentElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:Calendar'    , 'uri:Calendar');
        $this->_outputDom->documentElement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:AirSyncBase' , 'uri:AirSyncBase');
        
        $sync = $this->_outputDom->documentElement;
        
        $collections = $sync->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Collections'));
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__);
        foreach($this->_collections as $class => $classCollections) {
            foreach($classCollections as $collectionId => $collectionData) {
                if ($class == 'collectionNotFound') {
                    if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__);
                    $newSyncKey = $collectionData['syncKey'];
                    
                    $status = self::STATUS_FOLDER_HIERARCHY_HAS_CHANGED;
                    $this->_folderStateBackend->resetState($this->_device);
                    $this->_controller->updateSyncKey($this->_device, 0, $this->_syncTimeStamp, 'FolderSync');
                    
                    // Sync 0
                    // send back a new SyncKey only
                    $collection = $collections->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Collection'));
                    $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Class', $collectionData['class']));
                    $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'SyncKey', $newSyncKey));
                    $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'CollectionId', $collectionData['collectionId']));
                    $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Status', $status));
                    
                    if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__);
                } elseif ($collectionData['syncKeyValid'] !== true) {
                    $newSyncKey = 1;
                    $status = $collectionData['syncKey'] == 0 ? self::STATUS_SUCCESS : self::STATUS_INVALID_SYNC_KEY;
    
                    // Sync 0
                    // send back a new SyncKey only
                    $collection = $collections->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Collection'));
                    $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Class', $collectionData['class']));
                    $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'SyncKey', $newSyncKey));
                    $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'CollectionId', $collectionData['collectionId']));
                    $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Status', $status));
                    
                    $this->_contentStateBackend->resetState($this->_device, $collectionData['class'], $collectionData['collectionId']);
                } else {
                    #if ($collectionData['getChanges'] === false && !empty($collectionData['toBeFetched'])) {
                    if (empty($collectionData['added']) && empty($collectionData['changed']) && empty($collectionData['deleted']) && $collectionData['getChanges'] === false) {
                        // keep synckey during fetch requests
                        $newSyncKey = $collectionData['syncKey'];
                    } else {
                        $newSyncKey = $collectionData['syncKey'] + 1;
                    }
                    
                    
                    // collection header
                    $collection = $collections->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Collection'));
                    $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Class', $collectionData['class']));
                    $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'SyncKey', $newSyncKey));
                    $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'CollectionId', $collectionData['collectionId']));
                    $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Status', self::STATUS_SUCCESS));
                    
                    $responses = NULL;
                    // sent reponse for newly added entries
                    if(!empty($collectionData['added'])) {
                        if($responses === NULL) {
                            $responses = $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Responses'));
                        }
                        foreach($collectionData['added'] as $clientId => $entryData) {
                            $add = $responses->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Add'));
                            $add->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'ClientId', $clientId));
                            if(isset($entryData['serverId'])) {
                                $add->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'ServerId', $entryData['serverId']));
                            }
                            $add->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Status', $entryData['status']));
                        }
                    }
                    
                    // sent reponse for changed entries
                    // not really needed
                    if(!empty($collectionData['changed'])) {
                        if($responses === NULL) {
                            $responses = $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Responses'));
                        }
                        foreach($collectionData['changed'] as $serverId => $status) {
                            $change = $responses->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Change'));
                            $change->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'ServerId', $serverId));
                            $change->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Status', $status));
                        }
                    }
                    
                    $dataController = ActiveSync_Controller::dataFactory($collectionData['class'], $this->_device, $this->_syncTimeStamp);
                    
                    // sent response for to be fetched entries
                    if(!empty($collectionData['toBeFetched'])) {
                        if($responses === NULL) {
                            $responses = $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Responses'));
                        }
                        foreach($collectionData['toBeFetched'] as $serverId) {
                            $fetch = $responses->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Fetch'));
                            $fetch->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'ServerId', $serverId));

                            
                            try {
                                $applicationData = $this->_outputDom->createElementNS('uri:AirSync', 'ApplicationData');
                                $dataController->appendXML($applicationData, $collectionData['collectionId'], $serverId, $collectionData, true);
                                
                                $fetch->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Status', self::STATUS_SUCCESS));
                                
                                $fetch->appendChild($applicationData);
                            } catch (Exception $e) {
                                Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " unable to convert entry to xml: " . $e->getMessage());
                                $fetch->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Status', self::STATUS_OBJECT_NOT_FOUND));
                            }
                        }
                    }
                    
                    if($collectionData['getChanges'] === true) {
                        if($newSyncKey === 1) {
                            // all entries available
                            $serverAdds    = $dataController->getServerEntries($collectionData['collectionId'], $collectionData['filterType']);
                            $serverChanges = array();
                            $serverDeletes = array();
                        } else {
                            // continue sync session
                            if(isset($this->_session->syncTimeStamp)) {
                                $serverAdds = $this->_session->serverAdds[$collectionData['class']];
                                $serverChanges = $this->_session->serverChanges[$collectionData['class']];
                                $serverDeletes = $this->_session->serverDeletes[$collectionData['class']];
                            } else {
                                // fetch entries added since last sync
                                
                                #$serverAdds = $dataController->getSince('added', $syncState->lastsync, $this->_syncTimeStamp);
                                
                                $allClientEntries = $this->_contentStateBackend->getClientState($this->_device, $collectionData['class'], $collectionData['collectionId']);
                                $allServerEntries = $dataController->getServerEntries($collectionData['collectionId'], $collectionData['filterType']);
                                
                                // add entries
                                $serverDiff = array_diff($allServerEntries, $allClientEntries);
                                // add entries which produced problems during delete from client
                                $serverAdds = $this->_collections[$class][$collectionId]['forceAdd'];
                                // add entries not yet sent to client
                                $serverAdds = array_unique(array_merge($serverAdds, $serverDiff));
                                
                                foreach($serverAdds as $id => $serverId) {
                                    // skip entries added by client during this sync session
                                    if(isset($collectionData['added'][$serverId]) && !isset($this->_collections[$class][$collectionId]['forceAdd'][$serverId])) {
                                        Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " skipped added entry: " . $serverId);
                                        unset($serverAdds[$id]);
                                    }
                                }
                                
                                // entries to be deleted
                                $serverDeletes = array_diff($allClientEntries, $allServerEntries);
                                
                                // fetch entries changed since last sync
                                $syncState  = $this->_controller->getSyncState($this->_device, $collectionData['class'], $collectionData['collectionId'], $collectionData['syncKey']);
                                $serverChanges = $dataController->getChanged($collectionData['collectionId'], $syncState->lastsync, $this->_syncTimeStamp);
                                $serverChanges = array_merge($serverChanges, $this->_collections[$class][$collectionId]['forceChange']);
                                
                                foreach($serverChanges as $id => $serverId) {
                                    // skip entry, was changed by client
                                    if(isset($collectionData['changed'][$serverId]) && !isset($this->_collections[$class][$collectionId]['forceChange'][$serverId])) {
                                        Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " skipped changed entry: " . $serverId);
                                        unset($serverChanges[$id]);
                                    }
                                }
                                
                                // entries comeing in scope are already in $serverAdds and do not need to
                                // be send with $serverCanges
                                $serverChanges = array_diff($serverChanges, $serverAdds);
                            }                        
                        }
                        
                        Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " found (added/changed/deleted) " . count($serverAdds) . '/' . count($serverChanges) . '/' . count($serverDeletes)  . ' entries for sync from server to client');
    
                        if ((count($serverAdds) + count($serverChanges) + count($serverDeletes)) > $collectionData['windowSize'] ) {
                            $this->_moreAvailable = true;
                            $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'MoreAvailable'));
                        }
                        
                        if (count($serverAdds) > 0 || count($serverChanges) > 0 || count($serverDeletes) > 0) {
                            $commands = $collection->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Commands'));
                        }
                        
                        /**
                         * process added entries
                         */
                        // fetch estimated entries in one batch
                        $ids = array_slice($serverAdds, 0, abs($collectionData['windowSize'] - $this->_totalCount), TRUE);
                        $serverEntries = $dataController->getMultiple($ids);
                        
                        foreach($serverAdds as $id => $serverId) {
                            if($this->_totalCount === $collectionData['windowSize']) {
                                break;
                            }
                            
                            try {
                                #$add = $commands->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Add'));
                                $add = $this->_outputDom->createElementNS('uri:AirSync', 'Add');
                                
                                 
                                $entriesIdx = $serverEntries->getIndexById($serverId);
                                $serverEntriy = $entriesIdx !== FALSE ? $serverEntries[$entriesIdx] : $serverId;
                                
                                $add->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'ServerId', $serverId));
                                $applicationData = $add->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'ApplicationData'));
                                $dataController->appendXML($applicationData, $collectionData['collectionId'], $serverEntriy, $collectionData);
        
                                $commands->appendChild($add);
                                
                                #$this->_addContentState($collectionData['class'], $collectionData['collectionId'], $serverId);
                                
                                $this->_totalCount++;
                            } catch (Exception $e) {
                                Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " unable to convert entry to xml: " . $e->getMessage());
                            }
                            // mark as send to the client, even the conversion to xml might have failed                 
                            $this->_addContentState($collectionData['class'], $collectionData['collectionId'], $serverId);
                            if ($serverEntriy instanceof Tinebase_Record_Abstract) $serverEntries->removeRecord($serverEntriy);
                            unset($serverAdds[$id]);    
                        }
    
                        /**
                         * process changed entries
                         */
                        foreach($serverChanges as $id => $serverId) {
                            if($this->_totalCount === $collectionData['windowSize']) {
                                break;
                            }

                            try {
                                $change = $this->_outputDom->createElementNS('uri:AirSync', 'Change');
                                
                                $entriesIdx = $serverEntries->getIndexById($serverId);
                                $serverEntriy = $entriesIdx !== FALSE ? $serverEntries[$entriesIdx] : $serverId;
                                
                                $change->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'ServerId', $serverId));
                                $applicationData = $change->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'ApplicationData'));
                                $dataController->appendXML($applicationData, $collectionData['collectionId'], $serverEntriy, $collectionData);
        
                                $commands->appendChild($change);
                                
                                $this->_totalCount++;
                            } catch (Exception $e) {
                                Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " unable to convert entry to xml: " . $e->getMessage());
                            }
                            if ($serverEntriy instanceof Tinebase_Record_Abstract) $serverEntries->removeRecord($serverEntriy);
                            unset($serverChanges[$id]);    
                        }
    
                        /**
                         * process deleted entries
                         */
                        foreach($serverDeletes as $id => $serverId) {
                            if($this->_totalCount === $collectionData['windowSize']) {
                                break;
                            }
                                                        
                            try {
                                #$delete = $commands->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'Delete'));
                                $delete = $this->_outputDom->createElementNS('uri:AirSync', 'Delete');
                                $delete->appendChild($this->_outputDom->createElementNS('uri:AirSync', 'ServerId', $serverId));
                                
                                $this->_deleteContentState($collectionData['class'], $collectionData['collectionId'], $serverId);
        
                                $commands->appendChild($delete);
                                
                                $this->_totalCount++;
                            } catch (Exception $e) {
                                Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " unable to convert entry to xml: " . $e->getMessage());
                            }
                            unset($serverDeletes[$id]);    
                        }
                    }
                    Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " new synckey is $newSyncKey");                
                }
                
                // save data to session if more data available
                if($this->_moreAvailable === true) {
                    $this->_session->syncTimeStamp = $this->_syncTimeStamp;
                    $this->_session->serverAdds[$collectionData['class']]    = (array)$serverAdds;
                    $this->_session->serverChanges[$collectionData['class']] = (array)$serverChanges;
                    $this->_session->serverDeletes[$collectionData['class']] = (array)$serverDeletes;
                }
                
                if ($class != 'collectionNotFound') {
                    if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__);
                    // increment sync timestamp by 1 second
                    $this->_syncTimeStamp->add('1', Tinebase_DateTime::MODIFIER_SECOND);
                    $this->_controller->updateSyncKey($this->_device, $newSyncKey, $this->_syncTimeStamp, $collectionData['class'], $collectionData['collectionId']);
                    
                    // store current filter type
                    try {
                        $folderState = $this->getFolderState($this->_device, $collectionData['collectionId']);
                        $folderState->lastfiltertype = $collectionData['filterType'];
                        $this->_folderStateBackend->update($folderState);
                    } catch (Tinebase_Exception_NotFound $tenf) {
                        // failed to get folderstate => should not happen but is also no problem in this state
                        if (Tinebase_Core::isLogLevel(Zend_Log::CRIT)) Tinebase_Core::getLogger()->crit(__METHOD__ . '::' . __LINE__ . ' failed to get content state for: ' . $collectionData['collectionId']);
                    }
                }
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__);
            }
        }
        
        parent::getResponse($this->_moreAvailable);
    }

    /**
     * save contentstate (aka: remember that we have sent the entry to the client)
     *
     * @param string $_class the class from the xml
     * @param string $_collectionId the collection id from the xml
     * @param string $_contentId the Tine 2.0 id of the entry
     */
    protected function _addContentState($_class, $_collectionId, $_contentId)
    {
        $contentState = new ActiveSync_Model_ContentState(array(
            'device_id'     => $this->_device->getId(),
            'class'         => $_class,
            'collectionid'  => $_collectionId,
            'contentid'     => $_contentId,
            'creation_time' => $this->_syncTimeStamp
        ));
        
        /**
         * if the entry got added earlier, and there was an error, the entry gets added again
         * @todo it's better to wrap the whole process into a transation
         */
        try {
            $this->_contentStateBackend->create($contentState);
        } catch (Zend_Db_Statement_Exception $e) {
            $this->_deleteContentState($_class, $_collectionId, $_contentId);
            $this->_contentStateBackend->create($contentState);
        }
    }
    
    /**
     * delete contentstate (aka: forget that we have sent the entry to the client)
     *
     * @param string $_class the class from the xml
     * @param string $_collectionId the collection id from the xml
     * @param string $_contentId the Tine 2.0 id of the entry
     */
    protected function _deleteContentState($_class, $_collectionId, $_contentId)
    {
        $contentStateFilter = new ActiveSync_Model_ContentStateFilter(array(
            array(
                    'field'     => 'device_id',
                    'operator'  => 'equals',
                    'value'     => $this->_device->getId()
            ),
            array(
                    'field'     => 'class',
                    'operator'  => 'equals',
                    'value'     => $_class
            ),
            array(
                    'field'     => 'collectionid',
                    'operator'  => 'equals',
                    'value'     => $_collectionId
            ),
            array(
                    'field'     => 'contentid',
                    'operator'  => 'equals',
                    'value'     => $_contentId
            )
        ));
        $state = $this->_contentStateBackend->search($contentStateFilter, NULL, true);
        
        if(count($state) > 0) {
            $this->_contentStateBackend->delete($state[0]);
        } else {
            Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ . " no contentstate found for " . print_r($contentStateFilter->toArray(), true));
        }
    }    
    
    /**
     * @param unknown_type $_deviceId
     * @param unknown_type $_class
     * @param unknown_type $_folderId
     * @return ActiveSync_Model_FolderState
     */
    public function getFolderState($_deviceId, $_folderId)
    {
        $deviceId = $_deviceId instanceof ActiveSync_Model_Device ? $_deviceId->getId() : $_deviceId;
        
        // store current filter type
        $filter = new ActiveSync_Model_FolderStateFilter(array(
            array(
                'field'     => 'device_id',
                'operator'  => 'equals',
                'value'     => $deviceId,
            ),
            array(
                'field'     => 'folderid',
                'operator'  => 'equals',
                'value'     => $_folderId
            )
        ));
        $folderStates = $this->_folderStateBackend->search($filter);

        if ($folderStates->count() == 0) {
            throw new Tinebase_Exception_NotFound('folderstate for device not found');
        }
        
        return $folderStates->getFirstRecord();
    }
}
