<?php
/**
 * Tine 2.0
 *
 * @package     Felamimail
 * @subpackage  Backend
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Lars Kneschke <l.kneschke@metaways.de>
 * @copyright   Copyright (c) 2007-2010 Metaways Infosystems GmbH (http://www.metaways.de)
 * @version     $Id$
 * 
 */

/**
 * sql cache backend class for Felamimail messages
 *
 * @package     Felamimail
 */
class Felamimail_Backend_Cache_Sql_Message extends Tinebase_Backend_Sql_Abstract
{
    /**
     * Table name without prefix
     *
     * @var string
     */
    protected $_tableName = 'felamimail_cache_message';
    
    /**
     * Model name
     *
     * @var string
     */
    protected $_modelName = 'Felamimail_Model_Message';
    
    /**
     * foreign tables (key => tablename)
     *
     * @var array
     */
    protected $_foreignTables = array(
        'to'    => array(
            'table'  => 'felamimail_cache_message_to',
            'joinOn' => 'message_id',
            'field'  => 'email'
        ),
        'cc'    => array(
            'table'  => 'felamimail_cache_message_cc',
            'joinOn' => 'message_id',
            'field'  => 'email'
        ),
        'bcc'    => array(
            'table'  => 'felamimail_cache_message_bcc',
            'joinOn' => 'message_id',
            'field'  => 'email'
        ),
        'flags'    => array(
            'table'         => 'felamimail_cache_message_flag',
            'joinOn'        => 'message_id',
            'field'         => 'flag',
        ),
    );

    /******************* overwritten functions *********************/

    /**
     * Gets total count of search with $_filter
     * 
     * @param Tinebase_Model_Filter_FilterGroup $_filter
     * @return int
     */
    public function searchCount(Tinebase_Model_Filter_FilterGroup $_filter)
    {        
        $select = $this->_getSelect(array('count' => 'COUNT(*)', 'flags' => 'felamimail_cache_message_flag.flag'));
        $this->_addFilter($select, $_filter);
        
        $stmt = $this->_db->query($select);
        $rows = (array)$stmt->fetchAll(Zend_Db::FETCH_ASSOC);
        
        return count($rows);        
    }    
        
    /******************* public functions *********************/
    
    /**
     * update foreign key values
     * 
     * @param string $_mode create|update
     * @param Tinebase_Record_Abstract $_record
     * 
     * @todo support update mode
     */
    protected function _updateForeignKeys($_mode, Tinebase_Record_Abstract $_record)
    {
        if ($_mode == 'create') {
            
            foreach ($this->_foreignTables as $key => $foreign) {
                if (!isset($_record->{$key}) || empty($_record->{$key})) {
                    continue;
                }
                
                //if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' ' . $_field . ': ' . print_r($_record->{$_field}, TRUE));
                
                foreach ($_record->{$key} as $data) {
                    if ($key == 'flags') {
                        $data = array(
                            'flag'      => $data,
                            'folder_id' => $_record->folder_id
                        );
                    }
                    $data['message_id'] = $_record->getId();
                    $this->_db->insert($this->_tablePrefix . $foreign['table'], $data);
                }
            }
        }
    }
    
    /**
     * add flag to message
     *
     * @param Felamimail_Model_Message $_message
     * @param string $_flag
     */
    public function addFlag($_message, $_flag)
    {
        if (empty($_flag)) {
            // nothing todo
            return;
        }
        
        $data = array(
            'flag'          => $_flag,
            'message_id'    => $_message->getId(),
            'folder_id'     => $_message->folder_id
        );
        $this->_db->insert($this->_tablePrefix . $this->_foreignTables['flags']['table'], $data);
    }
    
    /**
     * set flags of message
     *
     * @param  mixed         $_messages
     * @param  string|array  $_flags
     */
    public function setFlags($_messages, $_flags)
    {
        if ($_messages instanceof Tinebase_Record_RecordSet) {
            $messages = $_messages;
        } elseif ($_messages instanceof Felamimail_Model_Message) {
            $messages = new Tinebase_Record_RecordSet('Felamimail_Model_Message', array($_messages));;
        } else {
            throw new Tinebase_Exception_UnexpectedValue('$_messages must be instance of Felamimail_Model_Message');
        }
        
        $where = array(
            $this->_db->quoteInto($this->_db->quoteIdentifier('message_id') . ' IN (?)', $messages->getArrayOfIds())
        );
        $this->_db->delete($this->_tablePrefix . $this->_foreignTables['flags']['table'], $where);
        
        $flags = (array) $_flags;

        foreach ($flags as $flag) {
            foreach ($messages as $message) {
                $data = array(
                    'flag'          => $flag,
                    'message_id'    => $message->getId(),
                    'folder_id'     => $message->folder_id
                );
                $this->_db->insert($this->_tablePrefix . $this->_foreignTables['flags']['table'], $data);
            }
        }
    }
    
    /**
     * remove flag from messages
     *
     * @param  mixed  $_messages
     * @param  mixed  $_flag
     */
    public function clearFlag($_messages, $_flag)
    {
        if ($_messages instanceof Tinebase_Record_RecordSet) {
            $messageIds = $_messages->getArrayOfIds();
        } elseif ($_messages instanceof Felamimail_Model_Message) {
            $messageIds = $_messages->getId();
        } else {
            // single id or array of ids
            $messageIds = $_messages;
        }
        
        $where = array(
            $this->_db->quoteInto($this->_db->quoteIdentifier('message_id') . ' IN (?)', $messageIds),
            $this->_db->quoteInto($this->_db->quoteIdentifier('flag') . ' IN (?)', $_flag)
        );
        
        $this->_db->delete($this->_tablePrefix . $this->_foreignTables['flags']['table'], $where);
    }
    
    /**
     * delete all cached messages for one folder
     *
     * @param  mixed  $_folderId
     */
    public function deleteByFolderId($_folderId)
    {
        $folderId = ($_folderId instanceof Felamimail_Model_Folder) ? $_folderId->getId() : $_folderId;
        
        $where = array(
            $this->_db->quoteInto($this->_db->quoteIdentifier('folder_id') . ' = ?', $folderId)
        );
        
        $this->_db->delete($this->_tablePrefix . $this->_tableName, $where);
    }

    /**
     * get count of cached messages by folder (id) 
     *
     * @param  mixed  $_folderId
     * @return integer
     */
    public function searchCountByFolderId($_folderId)
    {
        $folderId = ($_folderId instanceof Felamimail_Model_Folder) ? $_folderId->getId() : $_folderId;
        
        $filter = new Felamimail_Model_MessageFilter(array(
            array('field' => 'folder_id', 'operator' => 'equals', 'value' => $folderId)
        ));
        
        $count = $this->searchCount($filter);
        
        return $count;
    }
    
    /**
     * get count of seen cached messages by folder (id) 
     *
     * @param  mixed  $_folderId
     * @return integer
     * 
     */
    public function seenCountByFolderId($_folderId)
    {
        $folderId = ($_folderId instanceof Felamimail_Model_Folder) ? $_folderId->getId() : $_folderId;
        
        $select = $this->_db->select();
        $select->from(
            array('flags' => $this->_tablePrefix . $this->_foreignTables['flags']['table']), 
            array('count' => 'COUNT(DISTINCT message_id)')
        )->where(
            $this->_db->quoteInto($this->_db->quoteIdentifier('folder_id') . ' = ?', $folderId)
        )->where(
            $this->_db->quoteInto($this->_db->quoteIdentifier('flag') . ' = ?', '\Seen')
        );

        $seenCount = $this->_db->fetchOne($select);
                
        return $seenCount;
    }
    
    /**
     * get messageuids by folder (id)
     *
     * @param  mixed  $_folderId
     * @return array
     */
    public function getMessageuidsByFolderId($_folderId)
    {
        $folderId = ($_folderId instanceof Felamimail_Model_Folder) ? $_folderId->getId() : $_folderId;
        
        $select = $this->_db->select();
        $select->from(array($this->_tableName => $this->_tablePrefix . $this->_tableName), $this->_tableName . '.messageuid')
                ->where($this->_db->quoteInto($this->_db->quoteIdentifier('folder_id') . ' = ?', $folderId));
        
        $stmt = $this->_db->query($select);
        $rows = (array)$stmt->fetchAll(Zend_Db::FETCH_ASSOC);
        
        $result = array();
        foreach ($rows as $row) {
            $result[] = $row['messageuid'];
        }

        return $result;
    }
    
    /**
     * delete messages with given messageuids by folder (id)
     *
     * @param  array  $_msguids
     * @param  mixed  $_folderId
     * @return integer number of deleted rows
     */
    public function deleteMessageuidsByFolderId($_msguids, $_folderId)
    {
        if (empty($_msguids) || !is_array($_msguids)) {
            return FALSE;
        }
        
        $folderId = ($_folderId instanceof Felamimail_Model_Folder) ? $_folderId->getId() : $_folderId;
        
        $where = array(
            $this->_db->quoteInto($this->_db->quoteIdentifier('messageuid') . ' IN (?)', $_msguids),
            $this->_db->quoteInto($this->_db->quoteIdentifier('folder_id') . ' = ?', $folderId)
        );
        
        return $this->_db->delete($this->_tablePrefix . $this->_tableName, $where);
    }

    /**
     * converts record into raw data for adapter
     *
     * @param  Tinebase_Record_Abstract $_record
     * @return array
     */
    protected function _recordToRawData($_record)
    {
        $result = parent::_recordToRawData($_record);
        
        if(isset($result['structure'])) {
            $result['structure'] = Zend_Json::encode($result['structure']);
        }
        
        return $result;
    }
    
    /**
     * converts raw data from adapter into a single record
     *
     * @param  array $_rawData
     * @return Tinebase_Record_Abstract
     */
    protected function _rawDataToRecord(array $_rawData)
    {
        if (isset($_rawData['structure'])) {
            $_rawData['structure'] = Zend_Json::decode($_rawData['structure']);
        }
        
        $result = parent::_rawDataToRecord($_rawData);
                
        return $result;
    }
    
    /**
     * converts raw data from adapter into a set of records
     *
     * @param  array $_rawDatas of arrays
     * @return Tinebase_Record_RecordSet
     */
    protected function _rawDataToRecordSet(array $_rawDatas)
    {
        foreach($_rawDatas as &$_rawData) {
            if(isset($_rawData['structure'])) {
                $_rawData['structure'] = Zend_Json::decode($_rawData['structure']);
            }
        }
        $result = parent::_rawDataToRecordSet($_rawDatas);
        
        return $result;
    }
}
