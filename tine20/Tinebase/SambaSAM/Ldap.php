<?php
/**
 * Tine 2.0
 * 
 * @package     Tinebase
 * @subpackage  Samba
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @copyright   Copyright (c) 2009 Metaways Infosystems GmbH (http://www.metaways.de)
 * @author      Cornelius Weiss <c.weiss@metaways.de>
 * @version     $Id$
 */

/**
 * class Tinebase_SambaSAM_Ldap
 * 
 * Samba Account Managing
 * 
 * @package Tinebase
 * @subpackage Samba
 */
class Tinebase_SambaSAM_Ldap extends Tinebase_SambaSAM_Abstract
{

    /**
     * @var Tinebase_Ldap
     */
    protected $_ldap = NULL;

   /**
     * holdes the instance of the singleton
     *
     * @var Tinebase_SambaSAM_Ldap
     */
    private static $_instance = NULL;
    
    /**
     * direct mapping
     *
     * @var array
     */
    protected $_rowNameMapping = array(
        'sid'              => 'sambasid', 
        'primaryGroupSID'  => 'sambaprimarygroupsid', 
        'acctFlags'        => 'sambaacctflags',
        'homeDrive'        => 'sambahomedrive',
        'homePath'         => 'sambahomepath',
        'profilePath'      => 'sambaprofilepath',
        'logonScript'      => 'sambalogonscript',    
        'lmPassword'       => 'sambalmpassword',
        'ntPassword'       => 'sambantpassword',
        'logonTime'        => 'sambalogontime',
        'logoffTime'       => 'sambalogofftime',
        'kickoffTime'      => 'sambakickofftime',
        'pwdLastSet'       => 'sambapwdlastset',
        'pwdCanChange'     => 'sambapwdcanchange',
        'pwdMustChange'    => 'sambapwdmustchange',
    );
    
    /**
     * objectclasses required by this backend
     *
     * @var array
     */
    protected $_requiredObjectClass = array(
        'sambaSamAccount',
    );
    
    /**
     * the constructor
     *
     * @param  array $options Options used in connecting, binding, etc.
     * don't use the constructor. use the singleton 
     */
    private function __construct(array $_options) 
    {
        $this->_options = $_options;
        if (empty($this->_options['sid'])) {
            throw new Exception('you need to configure the sid of the samba installation');
        }
        
        $this->_ldap = new Tinebase_Ldap($_options);
        $this->_ldap->bind();
    }
    
    /**
     * don't clone. Use the singleton.
     *
     */
    private function __clone() {}

   /**
     * the singleton pattern
     *
     * @param  array $options Options used in connecting, binding, etc.
     * @return Tinebase_SambaSAM_Ldap
     */
    public static function getInstance(array $_options = array()) 
    {
        if (self::$_instance === NULL) {
            self::$_instance = new Tinebase_SambaSAM_Ldap($_options);
        }
        
        return self::$_instance;
    }
    
    /**
     * get user by id
     *
     * @param   int         $_userId
     * @return  Tinebase_Model_SAMUser user
     */
    public function getUserById($_userId) 
    {
        try {
            $userId = Tinebase_Model_User::convertUserIdToInt($_userId);
            $ldapData = $this->_ldap->fetch($this->_options['userDn'], 'uidnumber=' . $userId);
            $user = $this->_ldap2User($ldapData);
        } catch (Exception $e) {
            throw new Exception('User not found');
        }
        
        return $user;
    }

    /**
     * adds sam properties for a new user
     * 
     * @param  Tinebase_Model_FullUser $_user
     * @param  Tinebase_Model_SAMUser  $_samUser
     * @return Tinebase_Model_SAMUser
     */
	public function addUser($_user, Tinebase_Model_SAMUser $_samUser)
	{
        $metaData = $this->_getMetaData($_user);
        $ldapData = $this->_user2ldap($_samUser);
        
        $ldapData['objectclass'] = array_unique(array_merge($metaData['objectClass'], $this->_requiredObjectClass));
        
        // defaults
        $ldapData['sambasid'] = $this->_options['sid'] . '-' . (2 * $_user->getId() + 1000);
        $ldapData['sambaacctflags'] = '[U          ]';
        $ldapData['sambapwdcanchange']	= isset($ldapData['sambapwdcanchange'])  ? $ldapData['sambapwdcanchange']  : 0;
        $ldapData['sambapwdmustchange']	= isset($ldapData['sambapwdmustchange']) ? $ldapData['sambapwdmustchange'] : 2147483647; 
        
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . '  $dn: ' . $metaData['dn']);
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . '  $ldapData: ' . print_r($ldapData, true));
        
        $this->_ldap->update($metaData['dn'], $ldapData);
        
        return $this->getUserById($_user->getId());
	}
	
	/**
     * updates sam properties for an existing user
     * 
     * @param  Tinebase_Model_FullUser $_user
     * @param  Tinebase_Model_SAMUser  $_samUser
     * @return Tinebase_Model_SAMUser
     */
	public function updateUser($_user, Tinebase_Model_SAMUser $_samUser)
	{
        $metaData = $this->_getMetaData($_user);
        $ldapData = $this->_user2ldap($_samUser);
        
        // check if user has all required object classes.
        foreach ($this->_requiredObjectClass as $className) {
            if (! in_array($className, $metaData['objectClass'])) {
                Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . '  $dn: ' . $metaData['dn'] . ' has no samba account yet, we create it on the fly. Make shure to reset the users password!');

                return $this->addUser($_user, $_samUser);
                break;
            }
        }
        
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . '  $dn: ' . $metaData['dn']);
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . '  $ldapData: ' . print_r($ldapData, true));
        
        $this->_ldap->update($metaData['dn'], $ldapData);
        
        return $this->getUserById($_user->getId());
	}

	
	/**
     * delete sam user
     *
     * @param int $_userId
     */
	public function deleteUser($_userId)
	{
        // nothing do do in ldap backend
	}


    /**
     * set the password for given user 
     * 
     * @param   Tinebase_Model_FullUser $_user
     * @param   string                  $_password
     * @param   bool                    $_encrypt encrypt password
     * @return  void
     * @throws  Tinebase_Exception_InvalidArgument
     */
    public function setPassword($_user, $_password, $_encrypt = TRUE)
	{
        if (! $_encrypt) {
            Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' can not transform crypted password into nt/lm samba password. Make shure to reset the users password!');
        } else {
            $metaData = $this->_getMetaData($_user);
            $ldapData = array(
                'sambantpassword' => $this->_generateNTPassword($_password),
                'sambalmpassword' => $this->_generateLMPassword($_password),
                'sambapwdlastset' => Zend_Date::now()->getTimestamp()
            ); 
            
            Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . '  $dn: ' . $metaData['dn']);
            Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . '  $ldapData: ' . print_r($ldapData, true));
            
            $this->_ldap->update($metaData['dn'], $ldapData);
        }
	}

    /**
     * update user status
     *
     * @param   int         $_userId
     * @param   string      $_status
     */
    public function setStatus($_userId, $_status)
    {
        $metaData - $this->_getMetaData($_userId);
        
        $acctFlags = $this->getUserById($_userId)->acctFlags;
        if (empty($currentFlags)) {
            $acctFlags = '[U          ]';
        }
        $acctFlags[2] = $_status == 'disabled' ? 'D' : ' ';
        $ldapData = array(
            'sambaacctflags' => $acctFlags,
        );
        
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . '  $dn: ' . $metaData['dn']);
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . '  $ldapData: ' . print_r($ldapData, true));
        
        $this->_ldap->update($metaData['dn'], $ldapData);
    }
	
	/**
     * adds sam properties to a new group
     *
	 * @param  int                     $_groupId
     * @param  Tinebase_Model_SAMGroup $_samGroup
     * @return Tinebase_Model_SAMGroup
     */
	public function addGroup($_groupId, Tinebase_Model_SAMGroup $_samGroup)
	{

	}


	/**
	 * updates sam properties on an updated group
	 *
	 * @param  int                     $_groupId
     * @param  Tinebase_Model_SAMGroup $_samGroup
	 * @return Tinebase_Model_SAMGroup
	 */
	public function updateGroup($_groupId, Tinebase_Model_SAMGroup $_samGroup)
	{

	}


	/**
	 * deletes sam groups
	 * 
	 * @param  array $_groupIds
	 * @return void
	 */
	public function deleteGroups(array $_groupIds)
	{

	}
    
    /**
     * get metatada of existing account
     *
     * @param  int         $_userId
     * @return string 
     */
    protected function _getMetaData($_userId)
    {
        $metaData = array();
        
        try {
            $userId = Tinebase_Model_User::convertUserIdToInt($_userId);
            $account = $this->_ldap->fetch($this->_options['userDn'], 'uidnumber=' . $userId, array('objectclass'));
            $metaData['dn'] = $account['dn'];
            
            $metaData['objectClass'] = $account['objectclass'];
            unset($metaData['objectClass']['count']);
            
        } catch (Tinebase_Exception_NotFound $enf) {
            throw new Exception("account with id $userId not found");
        }
        
        return $metaData;
    }

    /**
     * Fetches all accounts from backend matching the given filter
     *
     * @param string $_filter
     * @param string $_accountClass
     * @return Tinebase_Record_RecordSet
     */
    protected function _getUsersFromBackend($_filter, $_accountClass = 'Tinebase_Model_SAMUser')
    {
        $result = new Tinebase_Record_RecordSet($_accountClass);
        $accounts = $this->_ldap->fetchAll($this->_options['userDn'], $_filter, array_values($this->_rowNameMapping));
        
        foreach ($accounts as $account) {
            $accountObject = $this->_ldap2User($account, $_accountClass);
            
            $result->addRecord($accountObject);
        }
        
        return $result;
    }
    
    /**
     * Returns a user obj with raw data from ldap
     *
     * @param array $_userData
     * @param string $_accountClass
     * @return Tinebase_Record_Abstract
     */
    protected function _ldap2User($_userData, $_accountClass='Tinebase_Model_SAMUser')
    {
        $accountArray = array();
        
        foreach ($_userData as $key => $value) {
            if (is_int($key)) {
                continue;
            }
            $keyMapping = array_search($key, $this->_rowNameMapping);
            if ($keyMapping !== FALSE) {
                switch($keyMapping) {
                    case 'pwdLastSet':
                    case 'logonTime':
                    case 'logoffTime':
                    case 'kickoffTime':
                    case 'pwdCanChange':
                    case 'pwdMustChange':
                        $accountArray[$keyMapping] = new Zend_Date($value[0], Zend_Date::TIMESTAMP);
                        break;
                    default: 
                        $accountArray[$keyMapping] = $value[0];
                        break;
                }
            }
        }
        
        $accountObject = new $_accountClass($accountArray);
        
        return $accountObject;
    }
    
    /**
     * returns array of ldap data
     *
     * @param  Tinebase_Model_FullUser $_user
     * @return array
     */
    protected function _user2ldap(Tinebase_Model_FullUser $_user)
    {
        $ldapData = array();
        foreach ($_user as $key => $value) {
            $ldapProperty = array_key_exists($key, $this->_rowNameMapping) ? $this->_rowNameMapping[$key] : false;
            if ($ldapProperty) {
                switch ($key) {
                    case 'pwdLastSet':
                    case 'logonTime':
                    case 'logoffTime':
                    case 'kickoffTime':
                    case 'pwdCanChange':
                    case 'pwdMustChange':
                        $ldapData[$ldapProperty] = $value instanceof Zend_Date ? $value->getTimestamp() : '';
                        break;
                    default:
                        $ldapData[$ldapProperty] = $value;
                        break;
                }
            }
        }
        
        return $ldapData;
    }

}  
