<?php
/**
 * @copyright (C) 2021 - 2024 Holger Brandt IT Solutions
 * @license GPL2
 */

if(!defined('ABSPATH')){
    exit;
}

class WADA_Migration_SensorTable extends WADA_Migration_Base {
    public $applicableBeforeVersion = '1.2.11';

    public function isMigrationApplicable(){
        $dbVersion = WADA_Settings::getDatabaseVersion('1.0.0');
        if(version_compare($dbVersion, $this->applicableBeforeVersion, "<")){
            if(!WADA_Database::isColExisting($this->wpdb->prefix.'wada_sensors', 'active_before')){
                WADA_Log::warning('SensorTable migration is applicable (active_before)');
                return true;
            }
            if(!WADA_Database::getColMaxLength($this->wpdb->prefix.'wada_sensors', 'name') <= 45){
                WADA_Log::warning('SensorTable migration is applicable (name)');
                return true;
            }
        }
        WADA_Log::debug('SensorTable migration is NOT applicable');
        return false;
    }

    public function doMigration(){
        WADA_Log::info('SensorTable doMigration');
        $res = array();
        $res[] = '+active_before: '.WADA_Database::addColIfNotExists($this->wpdb->prefix.'wada_sensors', 'active_before', 'TINYINT(1) NOT NULL DEFAULT 1', 'active');
        $res[] = '*name: '.WADA_Database::changeColType($this->wpdb->prefix.'wada_sensors', 'name', 'VARCHAR(255) NOT NULL');
        WADA_Log::info('SensorTable migration results: '.print_r($res, true));
        return true;
    }

}