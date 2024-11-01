<?php
/**
 * @copyright (C) 2021 - 2024 Holger Brandt IT Solutions
 * @license GPL2
*/

if(!defined('ABSPATH')){
    exit;
}

class WADA_Model_Stats extends WADA_Model_BaseReadOnly
{
    public function __construct($options = array()){
        parent::__construct($options);
    }

    protected function loadData($options = array()){
        $res = array();
        //WADA_Log::debug('WADA_Model_Stats->loadData with options: '.print_r($options, true));
        if(array_search('general_events', $options) !== false){
            $res['general_events'] = $this->getEventCounts();
        }
        if(array_search('general_sensors', $options) !== false){
            $res['general_sensors'] = $this->getSensorCounts();
        }
        if(array_search('first_event', $options) !== false){
            $res['first_event'] = $this->getFirstEvent();
        }
        if(array_search('general_notifications', $options) !== false){
            $res['general_notifications'] = $this->getNotificationCounts();
        }
        if(array_search('inactive_admins', $options) !== false){
            $res['inactive_admins'] = $this->getInactiveAdminCounts();
        }
        if(array_search('login_attempts', $options) !== false){
            $res['login_attempts_7d'] = $this->getLoginAttempts('7');
            $res['login_attempts_30d'] = $this->getLoginAttempts('30');
            $res['login_attempts_90d'] = $this->getLoginAttempts('90');
        }
        if(array_search('possible_extensions', $options) !== false){
            $res['possible_extensions'] = $this->getPossibleExtensions();
        }
        $this->_data = $res;
    }

    protected function getFirstEvent(){
        global $wpdb;
        $res = null;
        $sql = 'SELECT * FROM '.WADA_Database::tbl_events().' WHERE occurred_on IN (SELECT MIN(occurred_on) FROM '.WADA_Database::tbl_events().') LIMIT  1';
        $firstEvent = $wpdb->get_row($sql);
        if($firstEvent){
            $res = new stdClass();
            $res->id = $firstEvent->id;
            $res->date_utc = $firstEvent->occurred_on;
            $res->date_wp = WADA_DateUtils::formatUTCasDatetimeForWP($res->date_utc);
        }
        return $res;
    }

    public function getEventCounts(){
        global $wpdb;

        $sql = 'SELECT severity, sum(event_cr) as sev_cr '
            .'FROM ( '
            .'SELECT ev.sensor_id, ev.event_cr, sen.severity '
            .'FROM (SELECT sensor_id, count(*) as event_cr FROM '.WADA_Database::tbl_events().' GROUP BY sensor_id) ev '
            .'LEFT JOIN '.WADA_Database::tbl_sensors().' sen ON (ev.sensor_id = sen.id) '
            .') sev_stats GROUP BY severity';
        $eventCounts = $wpdb->get_results($sql);
        $severitiesOnFile = array_column($eventCounts, 'severity');
        $severityLevels = WADA_Model_Sensor::getSeverityLevels(true);

        $totalEventCount = 0;
        $severityResult = array();
        foreach($severityLevels AS $severityLevel => $severityName){
            $resultObj = new stdClass();
            $resultObj->severity = $severityLevel;
            $resultObj->name = $severityName;
            $found = array_search($severityLevel, $severitiesOnFile);
            if($found === false){
                $resultObj->count = 0;
            }else{
                $resultObj->count = $eventCounts[$found]->sev_cr;
            }
            $totalEventCount += $resultObj->count;
            $severityResult[] = $resultObj;
        }

        $result = new stdClass();
        $result->totalEvents = $totalEventCount;
        $result->bySeverityLevel = $severityResult;
        //WADA_Log::debug('WADA_Model_Stats->getEventCounts result: '.print_r($result, true));

        return $result;
    }

    public function getTopEventTypes($returnFirstX = 5){
        global $wpdb;
        $returnFirstX = intval($returnFirstX);

        $sql = 'SELECT evts.*, sen.name AS sensor_name '
            .'FROM ( '
                .'SELECT sensor_id, count(*) AS nr_events  '
                .'FROM '.WADA_Database::tbl_events().' '
                .'GROUP BY sensor_id '
            .') evts '
            .'LEFT JOIN '.WADA_Database::tbl_sensors().' sen ON (evts.sensor_id = sen.id) '
            .'ORDER BY nr_events DESC '
            .'LIMIT '.$returnFirstX;
        return $wpdb->get_results($sql);
    }


    public function getNrEventsOfLastXDays($returnOfXDays = 7){
        global $wpdb;
        $returnOfXDays = intval($returnOfXDays);
        $sql = 'SELECT COUNT(*) AS nr_events  '
            .'FROM '.WADA_Database::tbl_events().' evt  '
            .'WHERE (evt.occurred_on >= DATE(NOW() - INTERVAL '.intval($returnOfXDays).' DAY))';
        return $wpdb->get_var($sql);
    }

    public function getLoginAttempts($timeFrameInDays = '7'){
        global $wpdb;
        $sql= "SELECT COUNT(*) as nr_installs"
            ." FROM ("
            ." SELECT DISTINCT ip_address"
            ." FROM ".WADA_Database::tbl_logins()
            ." where (login_date >= DATE(NOW() - INTERVAL ".intval($timeFrameInDays)." DAY))"
            ." ) offenders";
        return $wpdb->get_var($sql);
    }

    protected function getInactiveUsersCountFor($inactiveSinceDays, $userIdsInScope, $currUtc){
        $inActiveUsersQuery = WADA_UserUtils::getInactiveUsersQuery($inactiveSinceDays, $userIdsInScope, $currUtc);
        global $wpdb;
        $sql= "SELECT count(*) as inactive_cr FROM (".$inActiveUsersQuery." ) inact";
        return $wpdb->get_var($sql);
    }

    public function getInactiveAdminCounts(){
        $currUtc = WADA_DateUtils::getUTCforMySQLTimestamp();
        $allAdminIds = get_users(
            array(
                'fields' => 'ID',
                'role__in' => array('administrator')
            )
        );

        $result = array();
        $checkInActiveDays = array(7, 14, 30, 90);
        foreach($checkInActiveDays AS $day){
            $inActiveCr = $this->getInactiveUsersCountFor($day, $allAdminIds, $currUtc);
            if($inActiveCr > 0){
                $result[] = (object)array('days' => $day, 'nr_inactive' => $inActiveCr);
            }
        }

        return $result;
    }

    public function getSensorCounts(){
        global $wpdb;

        $sql = 'SELECT active, count(*) as sensor_cr FROM '.WADA_Database::tbl_sensors().' GROUP BY active';
        $sensorCounts = $wpdb->get_results($sql);

        $totalSensors = 0;
        $sensorStatusCount = new stdClass();
        $sensorStatusCount->active = 0;
        $sensorStatusCount->inactive = 0;
        foreach($sensorCounts AS $sensorStatus){
            $totalSensors += $sensorStatus->sensor_cr;
            if($sensorStatus->active == 1){
                $sensorStatusCount->active += $sensorStatus->sensor_cr;
            }else{
                $sensorStatusCount->inactive += $sensorStatus->sensor_cr;
            }
        }
        $result = new stdClass();
        $result->totalSensors = $totalSensors;
        $result->bySensorStatus = $sensorStatusCount;
        WADA_Log::debug('WADA_Model_Stats->getSensorCounts result: '.print_r($result, true));

        return $result;
    }

    public function getNotificationCounts(){
        global $wpdb;

        $sql = 'SELECT active, count(*) as notification_cr FROM '.WADA_Database::tbl_notifications().' GROUP BY active';
        $notificationCounts = $wpdb->get_results($sql);

        $totalNotifications = 0;
        $notificationStatusCount = new stdClass();
        $notificationStatusCount->active = 0;
        $notificationStatusCount->inactive = 0;
        foreach($notificationCounts AS $notificationStatus){
            $totalNotifications += $notificationStatus->notification_cr;
            if($notificationStatus->active == 1){
                $notificationStatusCount->active += $notificationStatus->notification_cr;
            }else{
                $notificationStatusCount->inactive += $notificationStatus->notification_cr;
            }
        }
        $result = new stdClass();
        $result->totalNotifications = $totalNotifications;
        $result->byNotificationStatus = $notificationStatusCount;

        $sql = 'SELECT count(*) as event_notification_cr FROM '.WADA_Database::tbl_event_notifications().' ';
        $eventNotificationCr = $wpdb->get_var($sql);
        $result->eventNotificationCr = $eventNotificationCr;

        $sql = 'SELECT count(*) as queue_cr FROM '.WADA_Database::tbl_notification_queue().' ';
        $queueCr = $wpdb->get_var($sql);
        $result->queueCr = $queueCr;

        return $result;
    }

    protected function getPopularExtensions(){
        return array(
            array(
                'extension_name' => 'WP Admin Audit for WooCommerce',
                'extension_slug' => 'wp-admin-audit-for-woocommerce/wada-woocommerce.php',
                'extension_url' => 'https://www.wpadminaudit.com/extensions/wc',
                'target_plugin' => 'WooCommerce',
                'target_slugs' => array(
                    'woocommerce/woocommerce.php'
                )
            ),
            array(
                'extension_name' => 'WP Admin Audit for Advanced Custom Fields',
                'extension_slug' => 'wp-admin-audit-for-advanced-custom-fields/wada-advanced-custom-fields.php',
                'extension_url' => 'https://www.wpadminaudit.com/extensions/acf',
                'target_plugin' => 'Advanced Custom Fields',
                'target_slugs' => array(
                    'advanced-custom-fields/acf.php',
                    'advanced-custom-fields-pro/acf.php'
                )
            ),
            array(
                'extension_name' => 'WP Admin Audit for Contact Form 7',
                'extension_slug' => 'wp-admin-audit-for-contact-form-7/wada-contact-form-7.php',
                'extension_url' => 'https://www.wpadminaudit.com/extensions/cf7',
                'target_plugin' => 'Contact Form 7',
                'target_slugs' => array(
                    'contact-form-7/wp-contact-form-7.php'
                )
            ),
            array(
                'extension_name' => 'WP Admin Audit for WPForms',
                'extension_slug' => 'wp-admin-audit-for-wpforms/wada-wpforms.php',
                'extension_url' => 'https://www.wpadminaudit.com/extensions/wpf',
                'target_plugin' => 'WPForms',
                'target_slugs' => array(
                    'wpforms/wpforms.php'
                )
            ),
            array(
                'extension_name' => 'WP Admin Audit for Redirection',
                'extension_slug' => 'wp-admin-audit-for-redirection/wada-redirection.php',
                'extension_url' => 'https://www.wpadminaudit.com/extensions/rdn',
                'target_plugin' => 'Redirection',
                'target_slugs' => array(
                    'redirection/redirection.php'
                )
            ),
            array(
                'extension_name' => 'WP Admin Audit for Loco Translate',
                'extension_slug' => 'wp-admin-audit-for-loco-translate/wada-loco-translate.php',
                'extension_url' => 'https://www.wpadminaudit.com/extensions/locot',
                'target_plugin' => 'Loco Translate',
                'target_slugs' => array(
                    'loco-translate/loco.php'
                )
            ),
            array(
                'extension_name' => 'WP Admin Audit for Rank Math SEO',
                'extension_slug' => 'wp-admin-audit-for-rank-math-seo/wada-rank-math-seo.php',
                'extension_url' => 'https://www.wpadminaudit.com/extensions/rmseo',
                'target_plugin' => 'Rank Math SEO',
                'target_slugs' => array(
                    'seo-by-rank-math/rank-math.php',
                    'seo-by-rank-math-pro/rank-math-pro.php'
                )
            )
        );
    }
    public function getPossibleExtensions($extensions = null){
        $possibleExtensions = array();
        if(is_null($extensions)){
            $extensions = $this->getPopularExtensions();
        }

        foreach($extensions AS $extension){
            $ext = (object)$extension;
            foreach($ext->target_slugs AS $slug){
                $targetPlgInstalled = WADA_PluginUtils::isPluginInstalled($slug);
                if($targetPlgInstalled){
                    WADA_Log::debug('getPossibleExtensions target plugin '.$slug.' installed: '.$targetPlgInstalled);
                    $targetPlgActive = WADA_PluginUtils::isPluginActive($slug);
                    $extInstalled = WADA_PluginUtils::isPluginInstalled($ext->extension_slug);
                    if($targetPlgActive && !$extInstalled){
                        $possibleExtensions[] = $ext;
                        break;
                    }else{
                        WADA_Log::debug('getPossibleExtensions target plugin '.$slug.' active: '.$targetPlgActive);
                        WADA_Log::debug('getPossibleExtensions ext for '.$slug.' installed: '.$extInstalled);
                    }
                }
            }
        }
        WADA_Log::debug('getPossibleExtensions possibleExtensions: '.print_r($possibleExtensions, true));
        return $possibleExtensions;
    }

}