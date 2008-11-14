<?php

/**
 * this classes provides access to the sql table <prefix>_cal_dates
 * 
 * @package     Calendar
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Cornelius Weiss <c.weiss@metaways.de>
 * @copyright   Copyright (c) 2007-2008 Metaways Infosystems GmbH (http://www.metaways.de)
 * @version     $Id$
 *
 */

/**
 * this classes provides access to the sql table <prefix>_cal_dates
 * 
 * @package     Calendar
 */
class Calendar_Backend_Sql_Dates extends Zend_Db_Table_Abstract
{
    protected $_referenceMap = array(
        'Events' => array(
            'columns'       => 'cal_id',
            'refTableClass' => 'Calendar_Backend_Sql_Events',
            'refClumns'     => 'cal_id'
        )
    );
    
}