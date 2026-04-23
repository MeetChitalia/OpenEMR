<?php

require_once(__DIR__ . "/forms.inc");

use OpenEMR\Common\Session\EncounterSessionUtil;

//function called to set the global session variable for encounter number
function setencounter($enc)
{
    return EncounterSessionUtil::setEncounter($enc);
}


//fetches encounter pc_catid by encounter number
function fetchCategoryIdByEncounter($encounter)
{
    global $attendant_type;
    $table = $attendant_type == 'pid' ? 'form_encounter' : 'form_groups_encounter';
    $sql = "SELECT pc_catid FROM " . escape_table_name($table) . " WHERE encounter = ? limit 1";
    $result = sqlQuery($sql, array($encounter));
    // sqlQuery returns a recordset, so we need to fetch the first row
    if ($result && !$result->EOF) {
        $row = $result->FetchRow();
        return $row['pc_catid'] ?? null;
    }
    return null;
}

/**
 * @param $encounter
 * @return mixed
 */
function fetchDateService($encounter)
{
    $sql = "select date from form_encounter where encounter = ?";
    $result = sqlQuery($sql, [$encounter]);
    // sqlQuery returns a recordset, so we need to fetch the first row
    if ($result && !$result->EOF) {
        $row = $result->FetchRow();
        $date = $row['date'] ?? '';
        $result = explode(" ", $date);
        return $result[0];
    }
    return '';
}
