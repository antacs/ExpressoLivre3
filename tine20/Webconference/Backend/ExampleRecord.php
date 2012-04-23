<?php
/**
 * Tine 2.0
 *
 * @package     Webconference
 * @subpackage  Backend
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Philipp Schuele <p.schuele@metaways.de>
 * @copyright   Copyright (c) 2009 Metaways Infosystems GmbH (http://www.metaways.de)
 */


/**
 * backend for ExampleRecords
 *
 * @package     Webconference
 * @subpackage  Backend
 */
class Webconference_Backend_ExampleRecord extends Tinebase_Backend_Sql_Abstract
{
    /**
     * Table name without prefix
     *
     * @var string
     */
    protected $_tableName = 'example_application_record';
    
    /**
     * Model name
     *
     * @var string
     */
    protected $_modelName = 'Webconference_Model_ExampleRecord';

    /**
     * if modlog is active, we add 'is_deleted = 0' to select object in _getSelect()
     *
     * @var boolean
     */
    protected $_modlogActive = TRUE;

    /************************ overwritten functions *******************/  
    
    /************************ helper functions ************************/
}
