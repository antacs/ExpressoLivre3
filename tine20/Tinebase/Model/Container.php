<?php
/**
 * Tine 2.0
 * 
 * @package     Tinebase
 * @subpackage  Record
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @copyright   Copyright (c) 2007-2010 Metaways Infosystems GmbH (http://www.metaways.de)
 * @author      Lars Kneschke <l.kneschke@metaways.de>
 */

/**
 * defines the datatype for one container
 * 
 * @package     Tinebase
 * @subpackage  Record
 * @property    string application_id
 * @property    string type
 * 
 * NOTE: container class is in the transition from int based grants to string based
 *       grants! In the next refactoring step of container class, int based grants 
 *       will be replaced. Also the grants will not longer be part of container class!
 *       This way apps can define their own grants
 */
class Tinebase_Model_Container extends Tinebase_Record_Abstract
{
    /**
     * type for personal containers
     *
     */
    const TYPE_PERSONAL = 'personal';
    
    /**
     * type for shared container
     *
     */
    const TYPE_SHARED = 'shared';
    
	/**
     * key in $_validators/$_properties array for the filed which 
     * represents the identifier
     * 
     * @var string
     */    
    protected $_identifier = 'id';
    
    /**
     * application the record belongs to
     *
     * @var string
     */
    protected $_application = 'Tinebase';
    
    /**
     * list of zend inputfilter
     * 
     * this filter get used when validating user generated content with Zend_Input_Filter
     *
     * @var array
     */
    protected $_filters = array(
        'name'              => 'StringTrim'
    );
    
    /**
     * list of zend validator
     * 
     * this validators get used when validating user generated content with Zend_Input_Filter
     *
     * @var array
     */
    protected $_validators = array(
        'id'                => array('Digits', 'allowEmpty' => true),
        'name'              => array('presence' => 'required'),
        'type'              => array('InArray' => array(self::TYPE_PERSONAL, self::TYPE_SHARED)),
        'backend'           => array('presence' => 'required'),
        'color'             => array('allowEmpty' => true, array('regex', '/^#[0-9a-fA-F]{6}$/')),
        'application_id'    => array('Alnum', 'presence' => 'required'),
        'account_grants'    => array('allowEmpty' => true), // non persistent
        'owner_id'          => array('allowEmpty' => true), // non persistent
        'path'              => array('allowEmpty' => true), // non persistent
    // modlog fields
        'created_by'             => array('allowEmpty' => true),
        'creation_time'          => array('allowEmpty' => true),
        'last_modified_by'       => array('allowEmpty' => true),
        'last_modified_time'     => array('allowEmpty' => true),
        'is_deleted'             => array('allowEmpty' => true),
        'deleted_time'           => array('allowEmpty' => true),
        'deleted_by'             => array('allowEmpty' => true),    
    );
    
    /**
     * datetime fields
     *
     * @var array
     */
    protected $_datetimeFields = array(
        'creation_time',
        'last_modified_time',
        'deleted_time',
    );    
    
    /**
     * converts a int, string or Tinebase_Model_Container to a containerid
     *
     * @param   int|string|Tinebase_Model_Container $_containerId the containerid to convert
     * @return  int
     * @throws  Tinebase_Exception_InvalidArgument
     */
    static public function convertContainerIdToInt($_containerId)
    {
        if($_containerId instanceof Tinebase_Model_Container) {
            if($_containerId->getId() === NULL) {
                throw new Tinebase_Exception_InvalidArgument('No container id set.');
            }
            $id = (int) $_containerId->getId();
        } else {
            $id = (int) $_containerId;
        }
        
        if ($id === 0) {
            throw new Tinebase_Exception_InvalidArgument('Container id can not be 0 (' . $_containerId . ').');
        }
        
        return $id;
    }
    
    /**
     * gets path of this container
     *
     * @return string path
     */
    public function getPath()
    {
        $path = "/{$this->type}";
        switch ($this->type) {
            case 'internal':
                break;
            case 'shared':
                $path .= "/{$this->getId()}";
                break;
            case 'personal':
                if (! $this->owner_id) {
                    // we need to find out who has admin grant
                    $allGrants = Tinebase_Container::getInstance()->getGrantsOfContainer($this, true);
                    
                    foreach ($allGrants as $grants) {
                        if ($grants->{Tinebase_Model_Grants::GRANT_ADMIN} === true) {
                            $this->owner_id = $grants->account_id;
                            break;
                        }
                    }
                    if (! $this->owner_id) {
                        throw new Exception('could not find container admin');
                    }
                }
                
                $path .= "/{$this->owner_id}/{$this->getId()}";
                break;
            default:
                throw new Exception("unknown container type: '{$this->type}'");
                break;
        }
        return $path;
    }
    
    public static function path2node($_path)
    {
        
    }
    
    /**
     * returns containerId if given path represents a (single) container
     * 
     * 
     * @static
     * @param  String path
     * @return String|Bool
     */
    public static function pathIsContainer($_path)
    {
        if (preg_match("/^\/personal\/[0-9a-z_\-]+\/([a-f0-9]+)|^\/shared\/([a-f0-9]+)/i", $_path, $matches)) {
            return array_key_exists(2, $matches) ? $matches[2] : $matches[1];
        }
        
        return false;
    }
    
    /**
     * returns owner id if given path represents a personal _node_
     * 
     * @static
     * @param  String $_path
     * @return String|Bool
     */
    public static function pathIsPersonalNode($_path)
    {
        if (preg_match("/^\/personal\/([0-9a-z_\-]+)$/i", $_path, $matches)) {
            // transform current user 
            return $matches[1] == Tinebase_Model_User::CURRENTACCOUNT ? Tinebase_Core::getUser()->getId() : $matches[1];
        }
        
        return false;
    }
    
    /**
     * returns containername
     *
     * @return string
     */
    public function __toString()
    {
        return $this->name;
    }
}
