<?php
/**
 * Tine 2.0
 *
 * @package     Felamimail
 * @subpackage  Backend
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Philipp Schüle <p.schuele@metaways.de>
 * @copyright   Copyright (c) 2009-2011 Metaways Infosystems GmbH (http://www.metaways.de)
 * 
 */

/**
 * sql backend class for Felamimail Accounts
 *
 * @package     Felamimail
 */
class Messenger_Backend_Account extends Tinebase_Backend_Sql_Abstract
{
    /**
     * Table name without prefix
     *
     * @var string
     */
    protected $_tableName = 'messenger_account';
    
    /**
     * Model name
     *
     * @var string
     */
    protected $_modelName = 'Messenger_Model_Account';

}
