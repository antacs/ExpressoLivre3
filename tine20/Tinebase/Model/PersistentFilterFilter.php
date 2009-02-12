<?php
/**
 * Tine 2.0
 * 
 * @package     Tinebase
 * @subpackage  Filter
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Philipp Schuele <p.schuele@metaways.de>
 * @copyright   Copyright (c) 2007-2008 Metaways Infosystems GmbH (http://www.metaways.de)
 * @version     $Id$
 */

/**
 *  persistent filter filter class
 * 
 * @package     Tinebase
 * @subpackage  Filter 
 */
class Tinebase_Model_PersistentFilterFilter extends Tinebase_Model_Filter_FilterGroup
{    
    /**
     * @var string application of this filter group
     */
    protected $_applicationName = 'Tinebase';
    
    /**
     * @var array filter model fieldName => definition
     */
    protected $_filterModel = array(
        'query'          => array('filter' => 'Tinebase_Model_Filter_Query', 'options' => array('fields' => array('name'))),
        'application_id' => array('filter' => 'Tinebase_Model_Filter_ForeignId'),
        'account_id'     => array('filter' => 'Tinebase_Model_Filter_ForeignId'),
        'model'          => array('filter' => 'Tinebase_Model_Filter_Text'),
        'is_default'     => array('filter' => 'Tinebase_Model_Filter_Bool'),
    );
    
    /**
     * is acl filter resolved?
     *
     * @var boolean
     */
    protected $_isResolved = FALSE;
    
    /**
     * appends current filters to a given select object
     * - add user phone ids to filter
     * 
     * @param  Zend_Db_Select
     * @return void
     */
    public function appendFilterSql($_select)
    {
        // ensure acl policies
        $this->_appendAclSqlFilter($_select);
                
        parent::appendFilterSql($_select);
    }
    
    /**
     * add account id to filter (only if is_default == 0)
     *
     * @param Zend_Db_Select $_select
     * 
     * @todo    implement
     * @todo    add is_default 
     */
    protected function _appendAclSqlFilter($_select) {
        
        if (! $this->_isResolved) {
            
            /*
            //$defaultFilter = $this->_findFilter('is_default');
            //if ($defaultFilter !== NULL && $defaultFilter->)
            
            $accountIdFilter = $this->_findFilter('account_id');
            $userId = Tinebase_Core::getUser()->getId();
            
            // set user account id as filter
            if ($accountIdFilter === NULL) {
                $accountIdFilter = $this->createFilter('account_id', 'equals', $userId);
                $this->addFilter($accountIdFilter);

            } else {
                $accountIdFilter->setValue($userId);
            }
            
            $this->_isResolved = TRUE;
            */
        }
    }
}
