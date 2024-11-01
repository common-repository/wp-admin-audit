<?php
/**
 * @copyright (C) 2021 - 2024 Holger Brandt IT Solutions
 * @license GPL2
*/

if(!defined('ABSPATH')){
    exit;
}

class WADA_CompUtils
{
    /**
     * @param string $currentString
     * @param string $priorString
     * @return array
     */
    public static function getChangedString($keyName, $currentString, $priorString){
        $changed = false;
        if(is_null($currentString) && !is_null($priorString)){
            $changed = true;
        }elseif(!is_null($currentString) && is_null($priorString)){
            $changed = true;
        }elseif(!is_null($currentString) && !is_null($priorString) && $currentString != $priorString){
            $changed = true;
        }

        if($changed){
            return array(
                'info_key' => $keyName,
                'info_value' => $currentString,
                'prior_value' => $priorString
            );
        }else{
            return array();
        }
    }

    /**
     * @param object $previousObj
     * @param object $currentObject
     * @return array
     */
    public static function getChangedAttributesForAllAttributes($previousObj, $currentObject, $returnAsEventInfoObj = true){
        //WADA_Log::debug('computils prevObj: '.print_r($previousObj, true));
        //WADA_Log::debug('computils currObj: '.print_r($currentObject, true));
        $priorAttributes = $previousObj ? array_keys(get_object_vars($previousObj)) : array();
        $currentAttributes = $currentObject ? array_keys(get_object_vars($currentObject)) : array();
        //WADA_Log::debug('computils prevObj attr: '.print_r($priorAttributes, true));
        //WADA_Log::debug('computils currObj attr: '.print_r($currentAttributes, true));
        $attributes2Check = array_merge($priorAttributes, $currentAttributes);
        //WADA_Log::debug('computils attr to check 1: '.print_r($attributes2Check, true));
        $attributes2Check = array_values(array_unique($attributes2Check));
        //WADA_Log::debug('computils attr to check 2: '.print_r($attributes2Check, true));
        return self::getChangedAttributes($previousObj, $currentObject, $attributes2Check, $returnAsEventInfoObj);
    }
    
    /**
     * @param object $previousObj
     * @param object $currentObject
     * @param array $attributes2Check
     * @return array
     */
	public static function getChangedAttributes($previousObj, $currentObject, $attributes2Check, $returnAsEventInfoObj = true, $autoConvertArrays = true){
        $changedAttributes = array();

        foreach($attributes2Check AS $attribute2Check){
            if(!isset($previousObj->$attribute2Check) && !isset($currentObject->$attribute2Check)){
                continue;
            }
            $priorValue = isset($previousObj->$attribute2Check) ? $previousObj->$attribute2Check : null;
            $currentValue = isset($currentObject->$attribute2Check) ? $currentObject->$attribute2Check : null;

            if($autoConvertArrays){
                $currentValue = is_array($currentValue) ? self::getArrayAsStr($currentValue) : $currentValue;
                $priorValue = is_array($priorValue) ? self::getArrayAsStr($priorValue) : $priorValue;
            }

            WADA_Log::debug('getChangedAttributes '.$attribute2Check.': '.$priorValue.' -> '.$currentValue);

            if($priorValue != $currentValue){
                if($returnAsEventInfoObj) {
                    $changedAttributes[] = array(
                        'info_key' => $attribute2Check,
                        'info_value' => $currentValue,
                        'prior_value' => $priorValue
                    );
                }else{
                    $changedAttributes[] = $attribute2Check;
                }
            }
        }
        //WADA_Log::debug('getChangedAttributes result: '.print_r($changedAttributes, true));

        return $changedAttributes;
    }

    public static function getChangedObjectIdsInArrays($previousObjArray, $currentObjArray, $objectIdField, $objectIdFieldName = null, $returnAsEventInfoObj = true, $doCallbackForResults = null){
        $changedAttributes = array();
        $prevIds = array();
        $currIds = array();

        if(is_null($objectIdFieldName)){
            $objectIdFieldName = $objectIdField;
        }

	    foreach($previousObjArray AS $prevObj){
            $prevIds[] = $prevObj->$objectIdField;
        }
        foreach($currentObjArray AS $currObj){
            $currIds[] = $currObj->$objectIdField;
        }

        $changedAttributes = self::getChangedIdsOfIdArrays($objectIdFieldName, $currIds, $prevIds, $returnAsEventInfoObj, $doCallbackForResults);
        WADA_Log::debug('getChangedObjectIdsInArrays for field '.$objectIdField.', result: '.print_r($changedAttributes, true));

        return $changedAttributes;
    }

    public static function getChangedIdsOfIdArrays($idName, $currIds, $prevIds, $returnAsEventInfoObj = true, $doCallbackForResults = null){
        $changedAttributes = array();
        $commonIdsArray = array_unique(array_merge($prevIds, $currIds), SORT_NUMERIC);

        foreach($commonIdsArray AS $id){
            $inPrevObjArray = in_array($id, $prevIds);
            $inCurrObjArray = in_array($id, $currIds);

            if($inPrevObjArray && $inCurrObjArray){
                continue;
            }

            if($doCallbackForResults && is_callable($doCallbackForResults)){
                $changedAttributes[] = call_user_func($doCallbackForResults, $id, $inCurrObjArray, $inPrevObjArray);
            }else {
                if ($returnAsEventInfoObj) {
                    $changedAttributes[] = array(
                        'info_key' => $idName,
                        'info_value' => $inCurrObjArray ? $id : null,
                        'prior_value' => $inPrevObjArray ? $id : null
                    );
                } else {
                    $changedAttributes[] = $idName . $id;
                }
            }
        }
        return $changedAttributes;
    }

    public static function getArrayAsStr($arr, $maxLevel = 5, $currLevel = 0) {
        $currLevel++;
        if (is_scalar($arr) || is_null($arr)) {
            return json_encode($arr);
        }
        if (is_resource($arr)) {
            return '"[Resource]"';
        }
        if ($arr instanceof Closure) {
            return '"[Closure]"';
        }

        if ($currLevel >= $maxLevel) {
            return json_encode('Array depth limited at level ' . $currLevel);
        }

        if (is_array($arr) || is_object($arr)) {
            $elements = [];
            foreach ($arr as $key => $value) {
                $elements[] = json_encode($key) . ': ' . self::getArrayAsStr($value, $maxLevel, $currLevel);
            }
            return '{' . implode(', ', $elements) . '}';
        }

        return '"[Unknown Type]"';
    }

}