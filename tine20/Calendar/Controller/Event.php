<?php
/**
 * Tine 2.0
 * 
 * @package     Calendar
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Cornelius Weiss <c.weiss@metaways.de>
 * @copyright   Copyright (c) 2010-2011 Metaways Infosystems GmbH (http://www.metaways.de)
 */

/**
 * Calendar Event Controller
 * 
 * In the calendar application, the container grants concept is slightly extended:
 *  1. GRANTS for events are not only based on the events "calendar" (technically 
 *     a container) but additionally a USER gets implicit grants for an event if 
 *     he is ATTENDER (+READ GRANT) or ORGANIZER (+READ,EDIT GRANT).
 *  2. ATTENDER which are invited to a certain "event" can assign the "event" to
 *     one of their personal calenders as "display calendar" (technically personal 
 *     containers they are admin of). The "display calendar" of an ATTENDER is
 *     stored in the attendee table.  Each USER has a default calendar, as 
 *     PREFERENCE,  all invitations are assigned to.
 *  3. The "effective GRANT" a USER has on an event (read/update/delete/...) is the 
 *     maximum GRANT of the following sources: 
 *      - container: GRANT the USER has to the calender of the event
 *      - implicit:  Additional READ GRANT for an attender and READ,EDIT
 *                   GRANT for the organizer.
 *      - inherited: GRANT the USER has to a the "display calendar" of an ATTENDER 
 *                   of the event, LIMITED by the maximum GRANT the ATTENDER has 
 *                   to the event. NOTE: that the ATTENDERS 'event' and _not_ 
 *                   'calendar' is important to also inherit implicit GRANTS.
 * 
 * When Applying/Asuring grants, we have to deal with two differnt situations:
 *  A: Check: Check individual grants on a event (record) basis.
 *            This is required for CRUD actions and done by this controllers 
 *            _checkGrant method.
 *  B: Seach: From the grants perspective this is a multy step process
 *            1. limiting the query (mixture of grants and filter)
 *            2. transform event set (all events user has only free/busy grant 
 *               for need to be cleaned)
 * 
 *  NOTE: To empower the client for enabling/disabling of actions based on the 
 *        grants a user has to an event, we need to compute the "effective GRANT"
 *        also for read/search operations.
 *        For performance reasons only filter related attendee are taken into
 *        account for grant computations in search operations.
 *                  
 * Case A is not critical, as the amount of data is low and for CRUD operations
 * performace is less important. Case B however is the hard one, as lots of
 * calendars and events may be involved and performance is an issue.
 * 
 * 
 * @todo add handling to fetch all exceptions of a given event set (ActiveSync Frontend)
 * 
 * @package Calendar
 */
class Calendar_Controller_Event extends Tinebase_Controller_Record_Abstract implements Tinebase_Controller_Alarm_Interface
{
    /**
     * @var boolean
     * 
     * just set is_delete=1 if record is going to be deleted
     */
    protected $_purgeRecords = FALSE;
    
    /**
     * send notifications?
     *
     * @var boolean
     */
    protected $_sendNotifications = TRUE;
    
    /**
     * @var Calendar_Controller_Event
     */
    private static $_instance = NULL;
    
    /**
     * @var Tinebase_Model_User
     */
    protected $_currentAccount = NULL;
    
    /**
     * the constructor
     *
     * don't use the constructor. use the singleton 
     */
    private function __construct() {
        $this->_applicationName = 'Calendar';
        $this->_modelName       = 'Calendar_Model_Event';
        $this->_backend         = new Calendar_Backend_Sql();
        $this->_currentAccount  = Tinebase_Core::getUser();
    }

    /**
     * don't clone. Use the singleton.
     */
    private function __clone() 
    {
        
    }
    
    /**
     * singleton
     *
     * @return Calendar_Controller_Event
     */
    public static function getInstance() 
    {
        if (self::$_instance === NULL) {
            self::$_instance = new Calendar_Controller_Event();
        }
        return self::$_instance;
    }
    
    /**
     * checks if all attendee of given event are not busy for given event
     * 
     * @param Calendar_Model_Event $_event
     * @return void
     * @throws Calendar_Exception_AttendeeBusy
     */
    public function checkBusyConficts($_event)
    {
        $ignoreUIDs = !empty($_event->uid) ? array($_event->uid) : array();
        
        // don't check if event is trasparent
        if ($_event->transp == Calendar_Model_Event::TRANSP_TRANSP || count($_event->attendee) < 1) {
            return;
        }
        
        $eventSet = new Tinebase_Record_RecordSet('Calendar_Model_Event', array($_event));
        
        if (! empty($_event->rrule)) {
            $checkUntil = clone $_event->dtstart;
            $checkUntil->add(1, Tinebase_DateTime::MODIFIER_MONTH);
            Calendar_Model_Rrule::mergeRecuranceSet($eventSet, $_event->dtstart, $checkUntil);
        }
        
        $periods = array();
        foreach($eventSet as $event) {
            $periods[] = array('from' => $event->dtstart, 'until' => $event->dtend);
        }
        
        $fbInfo = $this->getFreeBusyInfo($periods, $_event->attendee, $ignoreUIDs);
        //if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . ' (' . __LINE__ . ') value: ' . print_r($fbInfo->toArray(), true));
        
        if (count($fbInfo) > 0) {
            $busyException = new Calendar_Exception_AttendeeBusy();
            $busyException->setFreeBusyInfo($fbInfo);
            throw $busyException;
        }
    }
    
    /**
     * add one record
     *
     * @param   Tinebase_Record_Interface $_record
     * @param   bool                      $_checkBusyConficts
     * @return  Tinebase_Record_Interface
     * @throws  Tinebase_Exception_AccessDenied
     * @throws  Tinebase_Exception_Record_Validation
     */
    public function create(Tinebase_Record_Interface $_record, $_checkBusyConficts = FALSE)
    {
        try {
            $db = $this->_backend->getAdapter();
            $transactionId = Tinebase_TransactionManager::getInstance()->startTransaction($db);
            
            $_record->uid = $_record->uid ? $_record->uid : Tinebase_Record_Abstract::generateUID();
            $_record->originator_tz = $_record->originator_tz ? $_record->originator_tz : Tinebase_Core::get(Tinebase_Core::USERTIMEZONE);
            
            // we need to resolve groupmembers before free/busy checking
            Calendar_Model_Attender::resolveGroupMembers($_record->attendee);
            
            if ($_checkBusyConficts) {
                // ensure that all attendee are free
                $this->checkBusyConficts($_record);
            }
            
            $sendNotifications = $this->_sendNotifications;
            $this->_sendNotifications = FALSE;
                
            $event = parent::create($_record);
            $this->_saveAttendee($_record);
            
            $this->_sendNotifications = $sendNotifications;
            
            Tinebase_TransactionManager::getInstance()->commitTransaction($transactionId);
        } catch (Exception $e) {
            Tinebase_TransactionManager::getInstance()->rollBack();
            throw $e;
        }
        
        $createdEvent = $this->get($event->getId());
        
        // send notifications
        if ($this->_sendNotifications) {
            $this->doSendNotifications($createdEvent, $this->_currentAccount, 'created');
        }
        
        return $createdEvent;
    }
    
    /**
     * deletes a recur series
     *
     * @param  Calendar_Model_Event $_recurInstance
     * @return void
     */
    public function deleteRecurSeries($_recurInstance)
    {
        $baseEvent = $this->getRecurBaseEvent($_recurInstance);
        $this->delete($baseEvent->getId());
    }
    
    /**
     * Gets all entries
     *
     * @param string $_orderBy Order result by
     * @param string $_orderDirection Order direction - allowed are ASC and DESC
     * @throws Tinebase_Exception_InvalidArgument
     * @return Tinebase_Record_RecordSet
     */
    public function getAll($_orderBy = 'id', $_orderDirection = 'ASC') 
    {
        throw new Tinebase_Exception_NotImplemented('not implemented');
    }
    
    /**
     * returns freebusy information for given period and given attendee
     * 
     * @todo merge overlapping events to one freebusy entry
     * 
     * @param  array of array with from and until                   $_periods
     * @param  Tinebase_Record_RecordSet of Calendar_Model_Attender $_attendee
     * @param  array of UIDs                                        $_ignoreUIDs
     * @return Tinebase_Record_RecordSet of Calendar_Model_FreeBusy
     */
    public function getFreeBusyInfo($_periods, $_attendee, $_ignoreUIDs = array())
    {
        $fbInfoSet = new Tinebase_Record_RecordSet('Calendar_Model_FreeBusy');
        
        // map groupmembers to users
        $attendee = clone $_attendee;
        $groupmembers = $attendee->filter('user_type', Calendar_Model_Attender::USERTYPE_GROUPMEMBER);
        $groupmembers->user_type = Calendar_Model_Attender::USERTYPE_USER;
        
        // base filter data
        $filterData = array(
            array('field' => 'attender', 'operator' => 'in',     'value' => $_attendee),
            array('field' => 'transp',   'operator' => 'equals', 'value' => Calendar_Model_Event::TRANSP_OPAQUE)
        );
        
        // add all periods to filterdata
        $periodFilters = array();
        foreach ($_periods as $period) {
            $periodFilters[] = array(
                'field' => 'period', 
                'operator' => 'within', 
                'value' => array(
                    'from' => $period['from'], 
                    'until' => $period['until']
            ));
        }
        $filterData[] = array('condition' => 'OR', 'filters' => $periodFilters);
        
        // finaly create filter
        $filter = new Calendar_Model_EventFilter($filterData);
        
        $events = $this->search($filter, new Tinebase_Model_Pagination(), FALSE, FALSE);
        
        foreach ($_periods as $period) {
            Calendar_Model_Rrule::mergeRecuranceSet($events, $period['from'], $period['until']);
        }
        
        //if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . ' (' . __LINE__ . ') value: ' . print_r($events->toArray(), true));
        
        // create a typemap
        $typeMap = array();
        foreach($attendee as $attender) {
            if (! array_key_exists($attender['user_type'], $typeMap)) {
                $typeMap[$attender['user_type']] = array();
            }
            
            $typeMap[$attender['user_type']][$attender['user_id']] = array();
        }
        //if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . ' (' . __LINE__ . ') value: ' . print_r($typeMap, true));
        
        // generate freeBusyInfos
        foreach($events as $event) {
            // skip events with ignoreUID
            if (in_array($event->uid, $_ignoreUIDs)) {
                continue;
            }
            
            // check if event is conflicting one of the given periods
            $conflicts = FALSE;
            foreach($_periods as $period) {
                if ($event->dtstart->isEarlier($period['until']) && $event->dtend->isLater($period['from'])) {
                    $conflicts = TRUE;
                    break;
                }
            }
            if (! $conflicts) {
                continue;
            }
            
            // map groupmembers to users
            $groupmembers = $event->attendee->filter('user_type', Calendar_Model_Attender::USERTYPE_GROUPMEMBER);
            $groupmembers->user_type = Calendar_Model_Attender::USERTYPE_USER;
        
            foreach ($event->attendee as $attender) {
                // skip declined events
                if ($attender->status == Calendar_Model_Attender::STATUS_DECLINED) {
                    continue;
                }
                
                if (array_key_exists($attender->user_type, $typeMap) && array_key_exists($attender->user_id, $typeMap[$attender->user_type])) {
                    $fbInfo = new Calendar_Model_FreeBusy(array(
                        'user_type' => $attender->user_type,
                        'user_id'   => $attender->user_id,
                        'dtstart'   => clone $event->dtstart,
                        'dtend'     => clone $event->dtend,
                        'type'      => Calendar_Model_FreeBusy::FREEBUSY_BUSY,
                    ), true);
                    
                    if ($event->{Tinebase_Model_Grants::GRANT_READ}) {
                        $fbInfo->event = clone $event;
                        unset($fbInfo->event->attendee);
                    }
                    
                    //$typeMap[$attender->user_type][$attender->user_id][] = $fbInfo;
                    $fbInfoSet->addRecord($fbInfo);
                }
            }
        }
        
        return $fbInfoSet;
    }
    
    /**
     * get list of records
     *
     * @param Tinebase_Model_Filter_FilterGroup|optional    $_filter
     * @param Tinebase_Model_Pagination|optional            $_pagination
     * @param bool                                          $_getRelations
     * @param boolean                                       $_onlyIds
     * @param string                                        $_action for right/acl check
     * @return Tinebase_Record_RecordSet|array
     */
    public function search(Tinebase_Model_Filter_FilterGroup $_filter = NULL, Tinebase_Record_Interface $_pagination = NULL, $_getRelations = FALSE, $_onlyIds = FALSE, $_action = 'get')
    {
        $events = parent::search($_filter, $_pagination, $_getRelations, $_onlyIds, $_action);
        if (! $_onlyIds) {
            $this->_freeBusyCleanup($events, $_action);
        }
        
        return $events;
    }
    
    /**
     * cleanup search results (freebusy)
     * 
     * @param Tinebase_Record_RecordSet $_events
     * @param string $_action
     */
    protected function _freeBusyCleanup(Tinebase_Record_RecordSet $_events, $_action)
    {
        foreach ($_events as $event) {
            $doFreeBusyCleanup = $event->doFreeBusyCleanup();
            if ($doFreeBusyCleanup && $_action !== 'get') {
                $_events->removeRecord($event);
            }
        }
    }
    
    /**
     * returns freeTime (suggestions) for given period of given attendee
     * 
     * @param  Tinebase_DateTime                                            $_from
     * @param  Tinebase_DateTime                                            $_until
     * @param  Tinebase_Record_RecordSet of Calendar_Model_Attender $_attendee
     * 
     * ...
     */
    public function searchFreeTime($_from, $_until, $_attendee/*, $_constains, $_mode*/)
    {
        $fbInfoSet = $this->getFreeBusyInfo(array(array('from' => $_from, 'until' => $_until)), $_attendee);
        
//        $fromTs = $_from->getTimestamp();
//        $untilTs = $_until->getTimestamp();
//        $granularity = 1800;
//        
//        // init registry of granularity
//        $eventRegistry = array_combine(range($fromTs, $untilTs, $granularity), array_fill(0, ceil(($untilTs - $fromTs)/$granularity)+1, ''));
//        
//        foreach ($fbInfoSet as $fbInfo) {
//            $startIdx = $fromTs + $granularity * floor(($fbInfo->dtstart->getTimestamp() - $fromTs) / $granularity);
//            $endIdx = $fromTs + $granularity * ceil(($fbInfo->dtend->getTimestamp() - $fromTs) / $granularity);
//            
//            for ($idx=$startIdx; $idx<=$endIdx; $idx+=$granularity) {
//                //$eventRegistry[$idx][] = $fbInfo;
//                $eventRegistry[$idx] .= '.';
//            }
//        }
        
        //print_r($eventRegistry);
    }
    
    /**
     * update one record
     *
     * @param   Tinebase_Record_Interface $_record
     * @param   bool                      $_checkBusyConficts
     * @return  Tinebase_Record_Interface
     * @throws  Tinebase_Exception_AccessDenied
     * @throws  Tinebase_Exception_Record_Validation
     */
    public function update(Tinebase_Record_Interface $_record, $_checkBusyConficts = FALSE)
    {
        try {
            $db = $this->_backend->getAdapter();
            $transactionId = Tinebase_TransactionManager::getInstance()->startTransaction($db);
            
            $event = $this->get($_record->getId());
            if ($this->_doContainerACLChecks === FALSE || $event->hasGrant(Tinebase_Model_Grants::GRANT_EDIT)) {
                Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " updating event: {$_record->id} ");
    	        
                // we need to resolve groupmembers before free/busy checking
                Calendar_Model_Attender::resolveGroupMembers($_record->attendee);
                        
                if ($_checkBusyConficts) {
                    // only do free/busy check if start/endtime changed  or attendee added or rrule changed
                    if (   ! $event->dtstart->equals($_record->dtstart) || 
                           ! $event->dtend->equals($_record->dtend) ||
                           count(array_diff($_record->attendee->user_id, $event->attendee->user_id)) > 0 || 
                           $event->rrule != $_record->rrule // attendee add
                       ) {
                        
                        // ensure that all attendee are free
                        $this->checkBusyConficts($_record);
                    }
                }
                
                $sendNotifications = $this->sendNotifications(FALSE);
                
                parent::update($_record);
                $this->_saveAttendee($_record);
                
                $this->sendNotifications($sendNotifications);
                
            } else if ($_record->attendee instanceof Tinebase_Record_RecordSet) {
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " user has no editGrant for event: {$_record->id}, updating attendee status with valid authKey only");
                foreach ($_record->attendee as $attender) {
                    if ($attender->status_authkey) {
                        $this->attenderStatusUpdate($event, $attender, $attender->status_authkey);
                    }
                }
            }
            
            Tinebase_TransactionManager::getInstance()->commitTransaction($transactionId);
        } catch (Exception $e) {
            Tinebase_TransactionManager::getInstance()->rollBack();
            throw $e;
        }
        
        $updatedEvent = $this->get($event->getId());
        
        // send notifications
        if ($this->_sendNotifications) {
            $this->doSendNotifications($updatedEvent, $this->_currentAccount, 'changed', $event);
        }
        return $updatedEvent;
    }
    
    /**
     * update multiple records
     * 
     * @param   Tinebase_Model_Filter_FilterGroup $_filter
     * @param   array $_data
     * @return  integer number of updated records
     */
    public function updateMultiple($_filter, $_data)
    {
        $this->_checkRight('update');
        $this->checkFilterACL($_filter, 'update');
        
        // get only ids
        $ids = $this->_backend->search($_filter, NULL, TRUE);
        
        foreach ($ids as $eventId) {
            $event = $this->get($eventId);
            foreach ($_data as $field => $value) {
                $event->$field = $value;
            }
            
            $this->update($event);
        }
        
        return count($ids);
    }
    
    /**
     * updates a recur series
     *
     * @param  Calendar_Model_Event $_recurInstance
     * @param  bool                 $_checkBusyConficts
     * @return Calendar_Model_Event
     */
    public function updateRecurSeries($_recurInstance, $_checkBusyConficts = FALSE)
    {
        $baseEvent = $this->getRecurBaseEvent($_recurInstance);
        
        // compute time diff (NOTE: if the recur instance is the baseEvent, it has no recurid)
        $instancesOriginalDtStart = $_recurInstance->recurid ? new Tinebase_DateTime(substr($_recurInstance->recurid, -19), 'UTC') : clone $baseEvent->dtstart;
        
        $dtstartDiff = $instancesOriginalDtStart->diff($_recurInstance->dtstart);
        
        $instancesEventDuration = $_recurInstance->dtstart->diff($_recurInstance->dtend);
        
        // replace baseEvent with adopted instance
        $newBaseEvent = clone $_recurInstance;
        
        $newBaseEvent->setId($baseEvent->getId());
        unset($newBaseEvent->recurid);
        
        $newBaseEvent->dtstart     = clone $baseEvent->dtstart;
        $newBaseEvent->dtstart->add($dtstartDiff);
        
        $newBaseEvent->dtend       = clone $newBaseEvent->dtstart;
        $newBaseEvent->dtend->add($instancesEventDuration);
        
        $newBaseEvent->exdate      = $baseEvent->exdate;
        
        return $this->update($newBaseEvent, $_checkBusyConficts);
    }
    
    /**
     * creates an exception instance of a recurring event
     *
     * NOTE: deleting persistent exceptions is done via a normal delte action
     *       and handled in the delteInspection
     * 
     * @param  Calendar_Model_Event  $_event
     * @param  bool                  $_deleteInstance
     * @param  bool                  $_allFollowing
     * @param  bool                  $_checkBusyConficts
     * @return Calendar_Model_Event  exception Event | updated baseEvent
     */
    public function createRecurException($_event, $_deleteInstance = FALSE, $_allFollowing = FALSE, $_checkBusyConficts = FALSE)
    {
        $baseEvent = $this->getRecurBaseEvent($_event);
        
        // check if this is an exception to the first occurence
        if ($baseEvent->getId() == $_event->getId()) {
            if ($_allFollowing) {
                throw new Exception('please edit or delete complete series!');
            }
            // NOTE: if the baseEvent gets a time change, we can't compute the recurdid w.o. knowing the original dtstart
            $recurid = $baseEvent->setRecurId();
            unset($baseEvent->recurid);
            $_event->recurid = $recurid;
        }
        
        // just do attender status update if user has no edit grant
        if ($this->_doContainerACLChecks && !$baseEvent->{Tinebase_Model_Grants::GRANT_EDIT}) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " user has no editGrant for event: '{$baseEvent->getId()}'. Only creating exception for attendee status");
            if ($_event->attendee instanceof Tinebase_Record_RecordSet) {
                foreach ($_event->attendee as $attender) {
                    if ($attender->status_authkey) {
                        $exceptionAttender = $this->attenderStatusCreateRecurException($_event, $attender, $attender->status_authkey);
                    }
                }
            }
            
            return $this->get($exceptionAttender->cal_event_id);
        }
        
        // NOTE: recurid is computed by rrule recur computations and therefore is already part of the event.
        if (empty($_event->recurid)) {
            throw new Exception('recurid must be present to create exceptions!');
        }
        $exdate = new Tinebase_DateTime(substr($_event->recurid, -19));
        
        // we do notifications ourself
        $sendNotifications = $this->sendNotifications(FALSE);
        
        $db = $this->_backend->getAdapter();
        $transactionId = Tinebase_TransactionManager::getInstance()->startTransaction($db);
        
        $exdates = is_array($baseEvent->exdate) ? $baseEvent->exdate : array();
        
        if ($_allFollowing != TRUE) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " adding exdate for: '{$_event->recurid}'");
            
            array_push($exdates, $exdate);
            $baseEvent->exdate = $exdates;
            $updatedBaseEvent = $this->update($baseEvent, FALSE);
            
            if ($_deleteInstance == FALSE) {
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " creating persistent exception for: '{$_event->recurid}'");
                
                $_event->setId(NULL);
                unset($_event->rrule);
                unset($_event->exdate);
            
                foreach(array('attendee', 'notes', 'alarms') as $prop) {
                    if ($_event->{$prop} instanceof Tinebase_Record_RecordSet) {
                        $_event->{$prop}->setId(NULL);
                    }
                }
                $persistentExceptionEvent = $this->create($_event, $_checkBusyConficts);
            }
            
        } else {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " shorten recur series for/to: '{$_event->recurid}'");
                
            // split past/future exceptions
            $pastExdates = array();
            $futureExdates = array();
            foreach($exdates as $exdate) {
                $exdate->isLater($_event->dtstart) ? $futureExdates[] = $exdate : $pastExdates[] = $exdate;
            }
            
            $persistentExceptionEvents = $this->getRecurExceptions($_event);
            $pastPersistentExceptionEvents = new Tinebase_Record_RecordSet('Calendar_Model_Event');
            $futurePersistentExceptionEvents = new Tinebase_Record_RecordSet('Calendar_Model_Event');
            foreach($persistentExceptionEvents as $persistentExceptionEvent) {
                $persistentExceptionEvent->dtstart->isLater($_event->dtstart) ? $futurePersistentExceptionEvents->addRecord($persistentExceptionEvent) : $pastPersistentExceptionEvents->addRecord($persistentExceptionEvent);
            }
            
            // update baseEvent
            $rrule = Calendar_Model_Rrule::getRruleFromString($baseEvent->rrule);
            $rrule->until = clone $_event->dtend;
            $rrule->until->subDay(1);
            $baseEvent->rrule = (string) $rrule;
            $baseEvent->exdate = $pastExdates;
            
            $updatedBaseEvent = $this->update($baseEvent, FALSE);
            
            if ($_deleteInstance == TRUE) {
                // delte all future persistent events
                $this->delete($futurePersistentExceptionEvents->getId());
            } else {
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " create new recur series for/at: '{$_event->recurid}'");
                
                // NOTE: in order to move exceptions correctly in time we need to find out the original dtstart
                //       and create the new baseEvent with this time. A following update also updates its exceptions
                $originalDtstart = new Tinebase_DateTime(substr($_event->recurid, -19));
                $adoptedDtstart = clone $_event->dtstart;
                $dtStartHasDiff = $adoptedDtstart->compare($originalDtstart) != 0; // php52 compat
                $eventLength = $_event->dtstart->diff($_event->dtend);
                
                $_event->dtstart = clone $originalDtstart;
                $_event->dtend = clone $originalDtstart;
                $_event->dtend->add($eventLength);
                
                $_event->setId(NULL);
                $_event->uid = $futurePersistentExceptionEvents->uid = Tinebase_Record_Abstract::generateUID();
                $futurePersistentExceptionEvents->setRecurId();
                unset($_event->recurid);
                foreach(array('attendee', 'notes', 'alarms') as $prop) {
                    if ($_event->{$prop} instanceof Tinebase_Record_RecordSet) {
                        $_event->{$prop}->setId(NULL);
                    }
                }
                $_event->exdate = $futureExdates;
                $futurePersistentExceptionEvents->setRecurId();
                
                $persistentExceptionEvent = $this->create($_event, $_checkBusyConficts && $dtStartHasDiff);
                foreach($futurePersistentExceptionEvents as $futurePersistentExceptionEvent) {
                    $this->update($futurePersistentExceptionEvent, FALSE);
                }
                
                if ($dtStartHasDiff) {
                    if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " new recur series has adpted dtstart -> update to adopt exceptions'");
                    $persistentExceptionEvent->dtstart = clone $adoptedDtstart;
                    $persistentExceptionEvent->dtend = clone $adoptedDtstart;
                    $persistentExceptionEvent->dtend->add($eventLength);
                    
                    $persistentExceptionEvent = $this->update($persistentExceptionEvent, $_checkBusyConficts);
                }
            }
                
        }
        
        Tinebase_TransactionManager::getInstance()->commitTransaction($transactionId);
        
        // restore original notification handling
        $this->sendNotifications($sendNotifications);
        $notificationAction = $_deleteInstance ? 'deleted' : 'created';
        $notificationEvent = $_deleteInstance ? $updatedBaseEvent : $persistentExceptionEvent;
        
        // send notifications
        if ($this->_sendNotifications) {
            // NOTE: recur exception is a fake event from client. 
            //       this might lead to problems, so we wrap the calls
            try {
                if (count($_event->attendee) > 0) {
                    $_event->attendee->bypassFilters = TRUE;
                }
                $_event->created_by = $baseEvent->created_by;
                
                $this->doSendNotifications($notificationEvent, $this->_currentAccount, $notificationAction);
            } catch (Exception $e) {
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " " . $e->getTraceAsString());
                Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ . " could not send notification {$e->getMessage()}");
            }
        }
        
        return $notificationEvent;
    }
    
    /**
     * returns base event of a recurring series
     *
     * @param  Calendar_Model_Event $_event
     * @return Calendar_Model_Event
     */
    public function getRecurBaseEvent($_event)
    {
        $baseEventId = array_value(0, $this->_backend->search(new Calendar_Model_EventFilter(array(
            array('field' => 'uid',     'operator' => 'equals', 'value' => $_event->uid),
            array('field' => 'is_deleted', 'operator' => 'equals', 'value' => '0'),
            array('field' => 'recurid', 'operator' => 'isnull', 'value' => NULL)
        )), NULL, TRUE));
        
        // make shure we have a 'fully featured' event
        return $this->get($baseEventId);
    }
    
    /**
     * returns all persistent recur exceptions of recur series identified by uid of given event
     * 
     * NOTE: deleted instances are saved in the base events exception property
     * NOTE: returns all exceptions regardless of current filters and access restrictions
     * 
     * @param  Calendar_Model_Event $_event
     * @param  boolean              $_fakeDeletedInstances
     * @return Tinebase_Record_RecordSet of Calendar_Model_Event
     */
    public function getRecurExceptions($_event, $_fakeDeletedInstances = FALSE)
    {
        $exceptions = $this->_backend->search(new Calendar_Model_EventFilter(array(
            array('field' => 'uid',     'operator' => 'equals',  'value' => $_event->uid),
            array('field' => 'recurid', 'operator' => 'notnull', 'value' => NULL)
        )));
        
        if ($_fakeDeletedInstances) {
            $baseEvent = $this->getRecurBaseEvent($_event);
            $eventLength = $baseEvent->dtstart->diff($baseEvent->dtend);

            // compute remaining exdates
            $deletedInstanceDtStarts = array_diff(array_unique((array) $baseEvent->exdate), $exceptions->getOriginalDtStart());
            foreach((array) $deletedInstanceDtStarts as $deletedInstanceDtStart) {
                $fakeEvent = clone $baseEvent;
                $fakeEvent->setId(NULL);
                
                $fakeEvent->dtstart = clone $deletedInstanceDtStart;
                $fakeEvent->dtend = clone $deletedInstanceDtStart;
                $fakeEvent->dtend->add($eventLength);
                $fakeEvent->is_deleted = TRUE;
                $fakeEvent->setRecurId();
                
                $exceptions->addRecord($fakeEvent);
            }
        }
        
        $exceptions->exdate = NULL;
        $exceptions->rrule = NULL;
        $exceptions->rrule_until = NULL;
        
        return $exceptions;
    }
    
    /****************************** overwritten functions ************************/
    
    /**
     * inspect alarm and set time
     * 
     * @param Tinebase_Record_Abstract $_record
     * @return void
     */
    protected function _inspectAlarmGet(Tinebase_Record_Abstract $_record)
    {
        foreach ($_record->alarms as $alarm) {
            $options = Zend_Json::decode($alarm->options);
            if (is_array($options) && array_key_exists('minutes_before', $options)) {
                $alarm->minutes_before = $options['minutes_before'];
            } else {
                $alarm->setMinutesBefore($_record->{$this->_recordAlarmField});
            }
        }
    }
    
    /**
     * inspect alarm and set time
     * 
     * @param Tinebase_Record_Abstract $_record
     * @param Tinebase_Model_Alarm $_alarm
     * @return void
     * @throws Tinebase_Exception_InvalidArgument
     */
    protected function _inspectAlarmSet(Tinebase_Record_Abstract $_record, Tinebase_Model_Alarm $_alarm)
    {
        if ($_record->rrule) {
            $exceptions = $this->getRecurExceptions($_record);
            $options = Zend_Json::decode($_alarm->options);
            $_alarm->minutes_before = isset($options['minutes_before']) ? $options['minutes_before'] : $_alarm->minutes_before;
            $eventLength = $_record->dtstart->diff($_record->dtend);
            
            // compute next occurance from now+minutes_before!
            $nextOccurrence = Calendar_Model_Rrule::computeNextOccurrence($_record, $exceptions, Tinebase_DateTime::now()->addMinute($_alarm->minutes_before));
            
            if (! $nextOccurrence) {
                $_alarm->sent_status = Tinebase_Model_Alarm::STATUS_SUCCESS;
                $_alarm->sent_message = 'Nothing to send, series is over';
                return;
            }
            
            $eventStart = clone $nextOccurrence->dtstart;
        } else {
            $eventStart = clone $_record->dtstart;
        }
        
        // if alarm time has been set, we don't need to calculate it and set minutes_before to 0
        if ($_alarm->alarm_time instanceof DateTime && $_alarm->minutes_before == 'custom') {
            $_alarm->minutes_before = 0;
            $customDateTime = TRUE;
        } else {
            $_alarm->setTime($eventStart);
            $customDateTime = FALSE;
        }
        
        // we need to save some values in the options because of recurring events
        $_alarm->options = Zend_Json::encode(array(
            'minutes_before' => $_alarm->minutes_before,
            'recurid'        => isset($nextOccurrence) ? $nextOccurrence->recurid : NULL,
            'custom'         => $customDateTime,
        ));
    }
    
    /**
     * inspect update of one record
     * 
     * @param   Tinebase_Record_Interface $_record      the update record
     * @param   Tinebase_Record_Interface $_oldRecord   the current persistent record
     * @return  void
     */
    protected function _inspectBeforeUpdate($_record, $_oldRecord)
    {
        // if dtstart of an event changes, we update the originator_tz, alarm times and reset attendee responses
        if (! $_oldRecord->dtstart->equals($_record->dtstart)) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' dtstart changed -> adopting organizer_tz');
            $_record->originator_tz = Tinebase_Core::get(Tinebase_Core::USERTIMEZONE);
            
            // update exdates and recurids if dtsart of an recurevent changes
            if (! empty($_record->rrule)) {
                $diff = $_oldRecord->dtstart->diff($_record->dtstart);
                
                // update rrule->until
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' dtstart of a series changed -> adopting rrule_until');
                
                
                $rrule = $_record->rrule instanceof Calendar_Model_Rrule ? $_record->rrule : Calendar_Model_Rrule::getRruleFromString($_record->rrule);
                if ($rrule->until instanceof DateTime) {
                    Calendar_Model_Rrule::addUTCDateDstFix($rrule->until, $diff, $_record->originator_tz);
                    $_record->rrule = (string) $rrule;
                }
                
                // update exdate(s)
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' dtstart of a series changed -> adopting '. count($_record->exdate) . ' exdate(s)');
                
                foreach ((array)$_record->exdate as $exdate) {
                    Calendar_Model_Rrule::addUTCDateDstFix($exdate, $diff, $_record->originator_tz);
                }
                
                // update exceptions
                $exceptions = $this->_backend->getMultipleByProperty($_record->uid, 'uid');
                unset($exceptions[$exceptions->getIndexById($_record->getId())]);
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' dtstart of a series changed -> adopting '. count($exceptions) . ' recurid(s)');
                foreach ($exceptions as $exception) {
                    $originalDtstart = new Tinebase_DateTime(substr($exception->recurid, -19));
                    Calendar_Model_Rrule::addUTCDateDstFix($originalDtstart, $diff, $_record->originator_tz);
                    
                    $exception->setRecurId();
                    $this->_backend->update($exception);
                }
            }
            
            foreach($_record->attendee as $attender) {
                if ($attender->user_id == Tinebase_Core::getUser()->contact_id && in_array($attender->user_type, array(Calendar_Model_Attender::USERTYPE_USER, Calendar_Model_Attender::USERTYPE_GROUPMEMBER))) {
                    // don't touch current users status
                    continue;
                }
                
                $attender->status = Calendar_Model_Attender::STATUS_NEEDSACTION;
            }
        }
        
        // delete recur exceptions if update is not longer a recur series
        if (! empty($_oldRecord->rrule) && empty($_record->rrule)) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' deleteing recur exceptions as event is no longer a recur series');
            $exceptionIds = $this->_backend->getMultipleByProperty($_record->uid, 'uid')->getId();
            unset($exceptionIds[array_search($_record->getId(), $exceptionIds)]);
            $this->_backend->delete($exceptionIds);
        }
        
        // touch base event of a recur series if an persisten exception changes
        if ($_record->recurid) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' touch base event of a persisten exception');
            $baseEvent = $this->getRecurBaseEvent($_record);
            $this->_touch($baseEvent, TRUE);
        }
    }
    
    /**
     * inspects delete action
     *
     * @param array $_ids
     * @return array of ids to actually delete
     */
    protected function _inspectDelete(array $_ids) {
        $events = $this->_backend->getMultiple($_ids);
        
        foreach ($events as $event) {
            
            // implicitly delete persistent recur instances of series
            if (! empty($event->rrule)) {
                $exceptionIds = $this->_backend->getMultipleByProperty($event->uid, 'uid')->getId();
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Implicitly deleting ' . (count($exceptionIds) - 1 ) . ' persistent exception(s) for recurring series with uid' . $event->uid);
                $_ids = array_merge($_ids, $exceptionIds);
            }
            
            // deleted persistent recur instances must be added to exdate of the baseEvent
            if (! empty($event->recurid)) {
                $this->createRecurException($event, true);
            }
        }
        
        $this->_deleteAlarmsForIds($_ids);
        
        return array_unique($_ids);
    }
    
    /**
     * redefine required grants for get actions
     * 
     * @param Tinebase_Model_Filter_FilterGroup $_filter
     * @param string $_action get|update
     */
    public function checkFilterACL(Tinebase_Model_Filter_FilterGroup $_filter, $_action = 'get')
    {
        $hasGrantsFilter = FALSE;
        foreach($_filter->getAclFilters() as $aclFilter) {
            if ($aclFilter instanceof Calendar_Model_GrantFilter) {
                $hasGrantsFilter = TRUE;
                break;
            }
        }
        
        if (! $hasGrantsFilter) {
            // force a grant filter
            $grantsFilter = $_filter->createFilter('grants', 'in', '@setRequiredGrants');
            $_filter->addFilter($grantsFilter);
        }
        
        parent::checkFilterACL($_filter, $_action);
        
        if ($_action == 'get') {
            $_filter->setRequiredGrants(array(
                Tinebase_Model_Grants::GRANT_FREEBUSY,
                Tinebase_Model_Grants::GRANT_READ,
                Tinebase_Model_Grants::GRANT_ADMIN,
            ));
        }
    }
    
    /**
     * check grant for action (CRUD)
     *
     * @param Tinebase_Record_Interface $_record
     * @param string $_action
     * @param boolean $_throw
     * @param string $_errorMessage
     * @param Tinebase_Record_Interface $_oldRecord
     * @return boolean
     * @throws Tinebase_Exception_AccessDenied
     * 
     * @todo use this function in other create + update functions
     * @todo invent concept for simple adding of grants (plugins?) 
     */
    protected function _checkGrant($_record, $_action, $_throw = TRUE, $_errorMessage = 'No Permission.', $_oldRecord = NULL)
    {
        if (    !$this->_doContainerACLChecks 
            // admin grant includes all others
            ||  ($_record->container_id && $this->_currentAccount->hasGrant($_record->container_id, Tinebase_Model_Grants::GRANT_ADMIN))) {
            return TRUE;
        }

        switch ($_action) {
            case 'get':
                // NOTE: free/busy is not a read grant!
                $hasGrant = $_record->hasGrant(Tinebase_Model_Grants::GRANT_READ);
                if (! $hasGrant) {
                    $_record->doFreeBusyCleanup();
                }
                break;
            case 'create':
                $hasGrant = $this->_currentAccount->hasGrant($_record->container_id, Tinebase_Model_Grants::GRANT_ADD);
                break;
            case 'update':
                $hasGrant = (bool) $_record->hasGrant(Tinebase_Model_Grants::GRANT_EDIT);
                break;
            case 'delete':
                $hasGrant = (bool) $_record->hasGrant(Tinebase_Model_Grants::GRANT_DELETE);
                break;
            case 'sync':
                $hasGrant = (bool) $_record->hasGrant(Tinebase_Model_Grants::GRANT_SYNC);
                break;
            case 'export':
                $hasGrant = (bool) $_record->hasGrant(Tinebase_Model_Grants::GRANT_EXPORT);
                break;
        }
        
        if (!$hasGrant) {
            if ($_throw) {
                throw new Tinebase_Exception_AccessDenied($_errorMessage);
            } else {
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . 'No permissions to ' . $_action . ' in container ' . $_record->container_id);
            }
        }
        
        return $hasGrant;
    }
    
    /**
     * touches (sets seq and last_modified_time) given event
     * 
     * @param  $_event
     * @return void
     */
    protected function _touch($_event, $_setModifier = FALSE) {
        $_event->last_modified_time = Tinebase_DateTime::now();
        //$_event->last_modified_time = Tinebase_DateTime::now()->get(Tinebase_Record_Abstract::ISO8601LONG);
        $_event->seq = (int)$_event->seq + 1;
        if ($_setModifier) {
            $_event->last_modified_by = Tinebase_Core::getUser()->getId();
        }
        
        
        $this->_backend->update($_event);
    }
    
    /****************************** attendee functions ************************/
    
    /**
     * creates an attender status exception of a recurring event series
     * 
     * NOTE: Recur exceptions are implicitly created
     *
     * @param  Calendar_Model_Event    $_recurInstance
     * @param  Calendar_Model_Attender $_attender
     * @param  string                  $_authKey
     * @return Calendar_Model_Attender updated attender
     */
    public function attenderStatusCreateRecurException($_recurInstance, $_attender, $_authKey)
    {
        try {
            $db = $this->_backend->getAdapter();
            $transactionId = Tinebase_TransactionManager::getInstance()->startTransaction($db);
            
            $baseEvent = $this->getRecurBaseEvent($_recurInstance);
            
            if ($baseEvent->getId() == $_recurInstance->getId()) {
                // exception to the first occurence
                $_recurInstance->setRecurId();
            }
            
            // NOTE: recurid is computed by rrule recur computations and therefore is already part of the event.
            if (empty($_recurInstance->recurid)) {
                throw new Exception('recurid must be present to create exceptions!');
            }
            
            // check authkey on series
            $attender = $baseEvent->attendee->filter('status_authkey', $_authKey)->getFirstRecord();
            if ($attender->user_type != $_attender->user_type || $attender->user_id != $_attender->user_id) {
                throw new Tinebase_Exception_AccessDenied('Attender authkey mismatch');
            }
            
            // check if this intance takes place
            if (in_array($_recurInstance->dtstart, (array)$baseEvent->exdate)) {
                throw new Tinebase_Exception_AccessDenied('Event instance is deleted and may not be recreated via status setting!');
            }
            
            try {
                // check if we already have a persistent exception for this event
                $eventInsance = $this->_backend->getByProperty($_recurInstance->recurid, $_property = 'recurid');
            } catch (Exception $e) {
                // otherwise create it implicilty
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " creating recur exception for a exceptional attendee status");
                $doContainerAclChecks = $this->doContainerACLChecks(FALSE);
                // NOTE: the user might have no edit grants, so let's be carefull
                $diff = $baseEvent->dtstart->diff($baseEvent->dtend);
                
                $baseEvent->dtstart = new Tinebase_DateTime(substr($_recurInstance->recurid, -19), 'UTC');
                $baseEvent->dtend   = clone $baseEvent->dtstart;
                $baseEvent->dtend->add($diff);
                
                $baseEvent->id = $_recurInstance->id;
                $baseEvent->recurid = $_recurInstance->recurid;
                
                $attendee = $baseEvent->attendee;
                unset($baseEvent->attendee);
                
                $eventInsance = $this->createRecurException($baseEvent);
                $eventInsance->attendee = new Tinebase_Record_RecordSet('Calendar_Model_Attender');
                $this->doContainerACLChecks($doContainerAclChecks);
                
                foreach ($attendee as $attender) {
                    $attender->setId(NULL);
                    $attender->cal_event_id = $eventInsance->getId();
                    
                    $attender = $this->_backend->createAttendee($attender);
                    $eventInsance->attendee->addRecord($attender);
                }
            }
            
            // set attender to the newly created exception attender
            $exceptionAttender = $eventInsance->attendee->filter('status_authkey', $_authKey)->getFirstRecord();
            $exceptionAttender->status = $_attender->status;
            
            $updatedAttender = $this->attenderStatusUpdate($eventInsance, $exceptionAttender, $exceptionAttender->status_authkey);
            
            Tinebase_TransactionManager::getInstance()->commitTransaction($transactionId);
        } catch (Exception $e) {
            Tinebase_TransactionManager::getInstance()->rollBack();
            throw $e;
        }
        
        return $updatedAttender;
    }
    
    /**
     * updates an attender status for a complete recurring event series
     * 
     * @param  Calendar_Model_Event    $_recurInstance
     * @param  Calendar_Model_Attender $_attender
     * @param  string                  $_authKey
     * @return Calendar_Model_Attender updated attender
     */
    public function attenderStatusUpdateRecurSeries($_recurInstance, $_attender, $_authKey)
    {
        $baseEvent = $this->getRecurBaseEvent($_recurInstance);
        
        return $this->attenderStatusUpdate($baseEvent, $_attender, $_authKey);
    }
    
    /**
     * updates an attender status of a event
     * 
     * @param  Calendar_Model_Event    $_event
     * @param  Calendar_Model_Attender $_attender
     * @param  string                  $_authKey
     * @return Calendar_Model_Attender updated attender
     */
    public function attenderStatusUpdate($_event, $_attender, $_authKey)
    {
        try {
            $db = $this->_backend->getAdapter();
            $transactionId = Tinebase_TransactionManager::getInstance()->startTransaction($db);
            
            $event = $this->get($_event->getId());
            
            $currentAttender                      = $event->attendee[$event->attendee->getIndexById($_attender->getId())];
            $currentAttender->status              = $_attender->status;
            $currentAttender->displaycontainer_id = $_attender->displaycontainer_id;
            
            if ($currentAttender->status_authkey == $_authKey) {
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " update attender status for {$currentAttender->user_type} {$currentAttender->user_id}");
                $updatedAttender = $this->_backend->updateAttendee($currentAttender);
                
                $this->_touch($event, TRUE);
            } else {
                $updatedAttender = $currentAttender;
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " no permissions to update status for {$currentAttender->user_type} {$currentAttender->user_id}");
            }
            
            Tinebase_TransactionManager::getInstance()->commitTransaction($transactionId);
        } catch (Exception $e) {
            Tinebase_TransactionManager::getInstance()->rollBack();
            throw $e;
        }
        
        return $updatedAttender;
    }
    
    /**
     * saves all attendee of given event
     * 
     * NOTE: This function is executed in a create/update context. As such the user
     *       has edit/update the event and can do anything besides status settings of attendee
     * 
     * @todo add support for resources
     * 
     * @param Calendar_Model_Event $_event
     */
    protected function _saveAttendee($_event)
    {
        $attendee = $_event->attendee instanceof Tinebase_Record_RecordSet ? 
            $_event->attendee : 
            new Tinebase_Record_RecordSet('Calendar_Model_Attender');
        $attendee->cal_event_id = $_event->getId();
        
        Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . " About to save attendee for event {$_event->id} ");
        
        $currentEvent = $this->get($_event->getId());
        $currentAttendee = $currentEvent->attendee;
        
        $diff = $currentAttendee->getMigration($attendee->getArrayOfIds());
        $this->_backend->deleteAttendee($diff['toDeleteIds']);
        
        $calendar = Tinebase_Container::getInstance()->getContainerById($_event->container_id);
        
        foreach ($attendee as $attender) {
            $attenderId = $attender->getId();
            $idx = ($attenderId) ? $currentAttendee->getIndexById($attenderId) : FALSE;
            
            if ($idx !== FALSE) {
                $currentAttender = $currentAttendee[$idx];
                $this->_updateAttender($attender, $currentAttender, $calendar);
                
            } else {
                $this->_createAttender($attender, $calendar);
            }
        }
    }

    /**
     * creates a new attender
     * @todo add support for resources
     * 
     * @param Calendar_Model_Attender  $_attender
     * @param Tinebase_Model_Container $_calendar
     */
    protected function _createAttender($_attender, $_calendar) {
        
        // apply default user_type
        $_attender->user_type = $_attender->user_type ?  $_attender->user_type : Calendar_Model_Attender::USERTYPE_USER;
        
        $userAccountId = $_attender->getUserAccountId();
        
        // reset status if attender != currentuser        
        if ($_attender->user_type == Calendar_Model_Attender::USERTYPE_GROUP
                || $userAccountId != Tinebase_Core::getUser()->getId()) {

            $_attender->status = Calendar_Model_Attender::STATUS_NEEDSACTION;
        }
        
        // generate auth key
        $_attender->status_authkey = Tinebase_Record_Abstract::generateUID();
        
        // attach to display calendar if attender has/is a useraccount
        if ($userAccountId) {
            if ($_calendar->type == Tinebase_Model_Container::TYPE_PERSONAL && Tinebase_Container::getInstance()->hasGrant($userAccountId, $_calendar, Tinebase_Model_Grants::GRANT_ADMIN)) {
                // if attender has admin grant to personal phisycal container, this phys. cal also gets displ. cal
                $_attender->displaycontainer_id = $_calendar->getId();
            } else if ($_attender->displaycontainer_id && $userAccountId == Tinebase_Core::getUser()->getId() && Tinebase_Container::getInstance()->hasGrant($userAccountId, $_attender->displaycontainer_id, Tinebase_Model_Grants::GRANT_ADMIN)) {
                // allow user to set his own displ. cal
                $_attender->displaycontainer_id = $_attender->displaycontainer_id;
            } else {
                $displayCalId = Tinebase_Core::getPreference('Calendar')->getValueForUser(Calendar_Preference::DEFAULTCALENDAR, $userAccountId);
                if ($displayCalId) {
                    $_attender->displaycontainer_id = $displayCalId;
                }
            }
        }
        
        if ($_attender->user_type === Calendar_Model_Attender::USERTYPE_RESOURCE) {
            $resource = Calendar_Controller_Resource::getInstance()->get($_attender->user_id);
            $_attender->displaycontainer_id = $resource->container_id;
        }
        $this->_backend->createAttendee($_attender);
    }
    
    /**
     * updates an attender
     * 
     * @param Calendar_Model_Attender  $_attender
     * @param Calendar_Model_Attender  $_currentAttender
     * @param Tinebase_Model_Container $_calendar
     */
    protected function _updateAttender($_attender, $_currentAttender, $_calendar) {
        
        $userAccountId = $_currentAttender->getUserAccountId();
        
        // reset status if attender != currentuser and wrong authkey
        if ($_attender->user_type == Calendar_Model_Attender::USERTYPE_GROUP
                || $userAccountId != Tinebase_Core::getUser()->getId()) {
            
            if ($_attender->status_authkey != $_currentAttender->status_authkey) {
                Tinebase_Core::getLogger()->notice(__METHOD__ . '::' . __LINE__ . " wrong authkey -> resetting status ");
                $_attender->status = $_currentAttender->status;
            }
        }
        
        // preserv old authkey
        $_attender->status_authkey = $_currentAttender->status_authkey;
        
        //if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " updating attender: " . print_r($_attender->toArray(), TRUE));
        
        // update display calendar if attender has/is a useraccount
        if ($userAccountId) {
            if ($_calendar->type == Tinebase_Model_Container::TYPE_PERSONAL && Tinebase_Container::getInstance()->hasGrant($userAccountId, $_calendar, Tinebase_Model_Grants::GRANT_ADMIN)) {
                // if attender has admin grant to personal physical container, this phys. cal also gets displ. cal
                $_attender->displaycontainer_id = $_calendar->getId();
            } else if ($userAccountId == Tinebase_Core::getUser()->getId() && Tinebase_Container::getInstance()->hasGrant($userAccountId, $_attender->displaycontainer_id, Tinebase_Model_Grants::GRANT_ADMIN)) {
                // allow user to set his own displ. cal
                $_attender->displaycontainer_id = $_attender->displaycontainer_id;
            } else {
                $_attender->displaycontainer_id = $_currentAttender->displaycontainer_id;
            }
        }
        
        $this->_backend->updateAttendee($_attender);
    }
    
    /**
     * event handler for group updates
     * 
     * @param Tinebase_Model_Group $_group
     * @return void
     */
    public function onUpdateGroup($_groupId)
    {
        $filter = new Calendar_Model_EventFilter(array(
            array('field' => 'attender', 'operator' => 'equals', 'value' => array(
                'user_type' => Calendar_Model_Attender::USERTYPE_GROUP,
                'user_id'   => $_groupId
            )),
            array('field' => 'dtstart', 'operator' => 'after', 'value' => Tinebase_DateTime::now()->get(Tinebase_Record_Abstract::ISO8601LONG))
        ));
        
        $doContainerACLChecks = $this->doContainerACLChecks(FALSE);
        
        $events = $this->search($filter, new Tinebase_Model_Pagination(), FALSE, FALSE);
        //if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . ' (' . __LINE__ . ') updated group ' . $events);
        
        foreach($events as $event) {
            Calendar_Model_Attender::resolveGroupMembers($event->attendee);
            $this->update($event);
        }
        
         $this->doContainerACLChecks($doContainerACLChecks);
    }
    
    /****************************** alarm functions ************************/
    
    /**
     * send an alarm
     *
     * @param  Tinebase_Model_Alarm $_alarm
     * @return void
     * 
     * @todo make this working with recuring events
     * @todo throw exception on error
     */
    public function sendAlarm(Tinebase_Model_Alarm $_alarm) 
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " About to send alarm " . print_r($_alarm->toArray(), TRUE));
        
        $doContainerACLChecks = $this->doContainerACLChecks(FALSE);
        
        $event = $this->get($_alarm->record_id);
        
        if ($event->rrule) {
            $options = Zend_Json::decode($_alarm->options);
            if ($options['recurid']) {
                // adopt event time to recur instance
                $diff = $event->dtstart->diff($event->dtend);
                
                $event->dtstart = new Tinebase_DateTime(substr($options['recurid'], -19));
                
                $event->dtend = clone $event->dtstart;
                $event->dtend->add($diff);
            }
            
            // NOTE: In case of recuring events $event is always the baseEvent.
            // NOTE: Alarm inspection adopts the (referenced) alarm and sets alarm time to next occurance
            $this->_inspectAlarmSet($event, $_alarm);
            $_alarm->sent_status = Tinebase_Model_Alarm::STATUS_PENDING;
            $_alarm->sent_message = '';
            
            
            
        }
        $this->doContainerACLChecks($doContainerACLChecks);
        
        $this->doSendNotifications($event, $this->_currentAccount, 'alarm');
    }
    
    /**
     * send notifications 
     * 
     * @param Calendar_Model_Event       $_event
     * @param Tinebase_Model_FullAccount $_updater
     * @param Sting                      $_action
     * @param Calendar_Model_Event       $_oldEvent
     * @return void
     */
    public function doSendNotifications($_event, $_updater, $_action, $_oldEvent=NULL)
    {
        Tinebase_ActionQueue::getInstance()->queueAction('Calendar.sendEventNotifications', 
            $_event, 
            $_updater,
            $_action, 
            $_oldEvent ? $_oldEvent : NULL
        );
    }
}
