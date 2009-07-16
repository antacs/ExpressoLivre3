<?php
/**
 * Tine 2.0
 *
 * @package     Felamimail
 * @subpackage  Controller
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Philipp Schuele <p.schuele@metaways.de>
 * @copyright   Copyright (c) 2009 Metaways Infosystems GmbH (http://www.metaways.de)
 * @version     $Id$
 * 
 * @todo        add cleanup routine for deleted (by other clients)/outofdate folders?
 * @todo        use other/shared namespaces
 * @todo        translate standard folder names
 */

/**
 * folder controller for Felamimail
 *
 * @package     Felamimail
 * @subpackage  Controller
 */
class Felamimail_Controller_Folder extends Tinebase_Controller_Abstract implements Tinebase_Controller_SearchInterface
{
    /**
     * application name (is needed in checkRight())
     *
     * @var string
     */
    protected $_applicationName = 'Felamimail';
    
    /**
     * last search count (2 dim array: userId => accountId => count)
     *
     * @var array
     */
    protected $_lastSearchCount = array();
    
    /**
     * system folder names
     *
     * @var array
     * 
     * @todo    update these from account settings
     */
    protected $_systemFolders = array('inbox', 'drafts', 'sent', 'templates', 'junk', 'trash');
    
    /**
     * folder delimiter/separator
     * 
     * @var string
     */
    protected $_delimiter = '/';
    
    /**
     * folder backend
     *
     * @var Felamimail_Backend_Folder
     */
    protected $_folderBackend = NULL;
    
    /**
     * holds the instance of the singleton
     *
     * @var Felamimail_Controller_Folder
     */
    private static $_instance = NULL;
    
    /**
     * the constructor
     *
     * don't use the constructor. use the singleton
     */
    private function __construct() {
        $this->_currentAccount = Tinebase_Core::getUser();
        $this->_folderBackend = new Felamimail_Backend_Folder();
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
     * @return Felamimail_Controller_Folder
     */
    public static function getInstance() 
    {
        if (self::$_instance === NULL) {
            self::$_instance = new Felamimail_Controller_Folder();
        }
        
        return self::$_instance;
    }

    /************************************* public functions *************************************/
    
    /**
     * get list of records
     *
     * @param Tinebase_Model_Filter_FilterGroup|optional $_filter
     * @param Tinebase_Model_Pagination|optional $_pagination
     * @param bool $_getRelations
     * @return Tinebase_Record_RecordSet
     * 
     */
    public function search(Tinebase_Model_Filter_FilterGroup $_filter = NULL, Tinebase_Record_Interface $_pagination = NULL, $_getRelations = FALSE, $_onlyIds = FALSE)
    {
        $filterValues = $this->_extractFilter($_filter);
        
        try {
            // try to get folders from imap backend
            $result = $this->getSubFolders($filterValues['globalname'], $filterValues['account_id']);    
            
        } catch (Zend_Mail_Protocol_Exception $zmpe) {
            Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ . ' ' . $zmpe->getMessage());
            
            // get folders from db
            $filter = new Felamimail_Model_FolderFilter(array(
                array('field' => 'parent',      'operator' => 'equals', 'value' => $filterValues['globalname']),
                array('field' => 'account_id',  'operator' => 'equals', 'value' => $filterValues['account_id'])
            ));
            $result = $this->_folderBackend->search($filter);
        }
        
        $result = $this->updateFolderStatus($filterValues['account_id'], $result);
        
        $this->_lastSearchCount[$this->_currentAccount->getId()][$filterValues['account_id']] = count($result);
        
        return $result;
    }
    
    /**
     * Gets total count of search with $_filter
     * 
     * @param Tinebase_Model_Filter_FilterGroup $_filter
     * @return int
     */
    public function searchCount(Tinebase_Model_Filter_FilterGroup $_filter)
    {
        $filterValues = $this->_extractFilter($_filter);
        
        return $this->_lastSearchCount[$this->_currentAccount->getId()][$filterValues['account_id']];
    }
    
    /**
     * create folder
     *
     * @param string $_folderName to create
     * @param string $_parentFolder
     * @param string $_accountId [optional]
     * @return Felamimail_Model_Folder
     */
    public function create($_folderName, $_parentFolder = '', $_accountId = 'default')
    {
        $account = Felamimail_Controller_Account::getInstance()->get($_accountId);
        $this->_delimiter = $account->delimiter;
        
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Trying to create new folder: ' . $_parentFolder . $this->_delimiter . $_folderName );
        
        $imap = Felamimail_Backend_ImapFactory::factory($account);
        $imap->createFolder($_folderName, (empty($_parentFolder)) ? NULL : $_parentFolder , $this->_delimiter);
        
        $globalname = (empty($_parentFolder)) ? $_folderName : $_parentFolder . $this->_delimiter . $_folderName;
        
        // create new folder
        $folder = new Felamimail_Model_Folder(array(
            'localname'     => $_folderName,
            'globalname'    => $globalname,
            'account_id'    => $_accountId,
            'parent'        => $_parentFolder
        ));           
        
        $folder = $this->_folderBackend->create($folder);
        return $folder;
    }
    
    /**
     * remove folder
     *
     * @param string $_folderName globalName (complete path) of folder to delete
     * @param string $_accountId
     */
    public function delete($_folderName, $_accountId = 'default')
    {
        $imap = Felamimail_Backend_ImapFactory::factory($_accountId);
        $imap->removeFolder($_folderName);
        
        try {
            $folder = $this->_folderBackend->getByBackendAndGlobalName($_accountId, $_folderName);
            $this->_folderBackend->delete($folder->getId());
        } catch (Tinebase_Exception_NotFound $tenf) {
            Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' Trying to delete non-existant folder.');
        }
    }
    
    /**
     * rename folder
     *
     * @param string $_oldFolderName local (complete path) of folder to rename
     * @param string $_newFolderName new globalName of folder
     * @param string $_accountId [optional]
     * @return Felamimail_Model_Folder
     * 
     * @todo use delimiter to explode name?
     */
    public function rename($_newLocalName, $_oldGlobalName, $_accountId = 'default')
    {
        //$globalNameParts = explode($this->_delimiter, $_oldGlobalName);
        //$folder->localname = array_pop($globalNameParts);
        
        $newGlobalName = preg_replace("/[_\-a-zA-Z0-9\.]+$/", $_newLocalName, $_oldGlobalName);
        
        $imap = Felamimail_Backend_ImapFactory::factory($_accountId);
        $imap->renameFolder($_oldGlobalName, $newGlobalName);
        
        // rename folder in db
        try {
            $folder = $this->_folderBackend->getByBackendAndGlobalName($_accountId, $_oldGlobalName);
            $folder->globalname = $newGlobalName;
            $folder->localname = $_newLocalName;
            $folder = $this->_folderBackend->update($folder);
            
        } catch (Tinebase_Exception_NotFound $tenf) {
            Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' Trying to rename non-existant folder.');
            throw $tenf;
        }
        
        return $folder;
    }

    /**
     * get (sub) folder and create folders in db backend
     *
     * @param string $_folderName
     * @param string $_accountId [optional]
     * @return Tinebase_Record_RecordSet of Felamimail_Model_Folder
     */
    public function getSubFolders($_folderName = '', $_accountId = 'default')
    {
        $account = Felamimail_Controller_Account::getInstance()->get($_accountId);
        $imap = Felamimail_Backend_ImapFactory::factory($account);
        
        $this->_delimiter = $account->delimiter;
        
        // try to get subfolders of $_folderName
        if(empty($_folderName)) {
            Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Get subfolders of root for backend ' . $_accountId);
            $folders = $imap->getFolders('', '%');
            
            // get imap server capabilities and save delimiter / personal namespace in account
            Felamimail_Controller_Account::getInstance()->updateCapabilities(
                $account, 
                $imap, 
                (! empty($folders) && isset($folders[0]['delimiter']) && ! empty($folders[0]['delimiter'])) ? $folders[0]['delimiter'] : NULL
            );
            
        } else {
            try {
                
                Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' trying to get subfolders of ' . $_folderName . $this->_delimiter);
                $folders = $imap->getFolders($_folderName . $this->_delimiter, '%');
                
            } catch (Zend_Mail_Storage_Exception $zmse) {
                
                Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' ' . $zmse->getMessage() .' - Trying again ...');
                
                // try again without delimiter
                try {
                    Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' trying to get subfolders of ' . $_folderName . $this->_delimiter);
                    $folders = $imap->getFolders($_folderName, '%');
                    
                } catch (Zend_Mail_Storage_Exception $zmse) {
                    Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' ' . $zmse->getMessage());
                    $folders = array();
                }
            }
            
            // remove folder if self
            if (in_array($_folderName, array_keys($folders))) {
                unset($folders[$_folderName]);
            }
        }
        
        //Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . print_r($folders, true));
        
        // get folder record set and sort it
        $result = $this->_getOrCreateFolders($folders, $account, $_folderName);
        $result = $this->_sortFolders($result);
        
        return $result;
    }    
    /**
     * delete all messages in one folder
     *
     * @param string $_folderId
     * @return void
     */
    public function emptyFolder($_folderId)
    {
        $filter = new Felamimail_Model_MessageFilter(array(
            array('field' => 'folder_id', 'operator' => 'equals', 'value' => $_folderId)
        ));
        
        $messages = Felamimail_Controller_Message::getInstance()->search($filter);
        
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Trying to delete ' 
            . count($messages) . ' messages from folder with id ' . $_folderId . '.'
        );
        return Felamimail_Controller_Message::getInstance()->delete($messages->getArrayOfIds());
    }
    
    /**
     * get status of all folders of account
     * - use $messageCacheBackend->seenCountByFolderId if offline/no connection to imap
     *
     * @param string $_accountId
     * @param Tinebase_Record_RecordSet $_folders [optional]
     * @param string $_folderId [optional]
     * @return Tinebase_Record_RecordSet with updated folder status
     * @throws Felamimail_Exception
     * 
     * @todo update folders in db?
     */
    public function updateFolderStatus($_accountId, $_folders = NULL, $_folderId = NULL)
    {
        if ($_folders === NULL && ($_folderId === NULL || empty($_folderId))) {
            // get all folders of account
            $filter = new Felamimail_Model_FolderFilter(array(
                array('field' => 'account_id',  'operator' => 'equals', 'value' => $_accountId)
            ));
            $folders = $this->_folderBackend->search($filter);
        } else {
            if ($_folderId !== NULL && ! empty($_folderId)) {
                // get single folder
                $folders = new Tinebase_Record_RecordSet(
                    'Felamimail_Model_Folder', 
                    array($this->_folderBackend->get($_folderId))
                );
            } else if ($_folders !== NULL && $_folders instanceof Tinebase_Record_RecordSet) {
                // recordset was given
                $folders = $_folders;
            } else {
                throw new Felamimail_Exception("Wrong params: " . $_folderId);
            }
        }
        
        // try imap connection
        try {
            $imap = Felamimail_Backend_ImapFactory::factory($_accountId);
        } catch (Zend_Mail_Protocol_Exception $zmpe) {
            Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ . ' No connection to imap server ...');
            $imap = FALSE;
        }
        
        // return status of all folders
        foreach ($folders as $folder) {

            if ($imap) {
                $imapFolderStatus = $imap->getFolderStatus($folder->globalname);
                
                $folder->unreadcount = (isset($imapFolderStatus['unseen'])) ? $imapFolderStatus['unseen'] : 0;
                $folder->recentcount = $imapFolderStatus['recent'];
                $folder->totalcount = $imapFolderStatus['messages'];
                
            } else {
                // count messages even if no imap connection available
                $cacheBackend = new Felamimail_Backend_Cache_Sql_Message();
                $folder->totalcount = $cacheBackend->searchCountByFolderId($folder->getId());
                $seenCount = $cacheBackend->seenCountByFolderId($folder->getId());
                $folder->unreadcount = $folder->totalcount - $seenCount;
            }
        }
        
        return $folders;
    }
    
    /************************************* protected functions *************************************/
    
    /**
     * extract values from folder filter
     *
     * @param Felamimail_Model_FolderFilter $_filter
     * @return array (assoc) with filter values
     * 
     * @todo add AND/OR conditions for multiple filters of the same field?
     */
    protected function _extractFilter(Felamimail_Model_FolderFilter $_filter)
    {
        $result = array('account_id' => 'default', 'globalname' => '');
        
        $filters = $_filter->getFilterObjects();
        foreach($filters as $filter) {
            switch($filter->getField()) {
                case 'account_id':
                    $result['account_id'] = $filter->getValue();
                    break;
                case 'globalname':
                    $result['globalname'] = $filter->getValue();
                    break;
            }
        }
        
        return $result;
    }

    /**
     * create new folders or get existing folders from db and return record set
     *
     * @param array $_folders
     * @param Felamimail_Model_Account $_account
     * @param string $_parentFolder
     * @return Tinebase_Record_RecordSet of Felamimail_Model_Folder
     * 
     * @todo replace mb_convert_encoding with iconv or something like that
     * @todo update $this->_systemFolders with configured folders from account for sorting here? 
     */
    protected function _getOrCreateFolders(array $_folders, $_account, $_parentFolder)
    {
        $result = new Tinebase_Record_RecordSet('Felamimail_Model_Folder');
        
        //-- get configured account standard folders here 
        
        // do some mapping and save folder in db (if it doesn't exist
        foreach ($_folders as $folderData) {
            try {
                // decode folder name
                if (extension_loaded('mbstring')) {
                    $folderData['localName'] = mb_convert_encoding($folderData['localName'], "utf-8", "UTF7-IMAP");
                }
                
                $folder = $this->_folderBackend->getByBackendAndGlobalName($_account->getId(), $folderData['globalName']);
                $folder->is_selectable = ($folderData['isSelectable'] == '1');
                $folder->has_children = ($folderData['hasChildren'] == '1');
                
            } catch (Tinebase_Exception_NotFound $tenf) {
                // create new folder
                $folder = new Felamimail_Model_Folder(array(
                    'localname'     => (! empty($folderData['localName'])) ? $folderData['localName'] : $folderData['globalName'],
                    'globalname'    => $folderData['globalName'],
                    'is_selectable' => ($folderData['isSelectable'] == '1'),
                    'has_children'  => ($folderData['hasChildren'] == '1'),
                    'account_id'    => $_account->getId(),
                    'timestamp'     => Zend_Date::now(),
                    'user_id'       => $this->_currentAccount->getId(),
                    'parent'        => $_parentFolder,
                    'system_folder' => in_array(strtolower($folderData['localName']), $this->_systemFolders)
                ));
                
                $folder = $this->_folderBackend->create($folder);
            }
            
            $result->addRecord($folder);
        }
        
        return $result;
    }
    
    /**
     * sort folder record set
     * - begin with INBOX + other standard folders, add other folders
     *
     * @param Tinebase_Record_RecordSet $_folders
     * @return Tinebase_Record_RecordSet
     * 
     * @todo make sort case sensitive
     * @todo finish that
     * @todo write test
     */
    protected function _sortFolders(Tinebase_Record_RecordSet $_folders)
    {
        $sortedFolders = new Tinebase_Record_RecordSet('Felamimail_Model_Folder');
        
        /*
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
            . ' Sorting folders ...'
            . print_r($_folders->globalname, TRUE)
        );
        */
        
        $_folders->sort('localname', 'ASC', SORT_STRING);
        $_folders->addIndices(array('globalname'));
        
        foreach ($this->_systemFolders as $systemFolderName) {
            $folders = $_folders->filter('globalname', strtolower($systemFolderName));
            if (count($folders) > 0) {
                $sortedFolders->addRecord($folders->getFirstRecord());
            }
        }
        
        // add the rest
        foreach ($_folders as $folder) {
            if (! in_array($folder->globalname, $this->_systemFolders)) {
            //if (! in_array($folder->localname, $this->_systemFolders)) {
                $sortedFolders->addRecord($folder);
            }
        }
        
        /*
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
            . ' Sorted folders ...'
            . print_r($sortedFolders->globalname, TRUE)
        );
        */
        
        return $sortedFolders;
    }
}
