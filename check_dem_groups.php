<?php
echo "<div style='color:red'>DEBUG: Script is running</div>";
require_once 'library/globals.php';
require_once 'library/sql.inc';

// Print all group_ids and titles for DEM layout from layout_group_properties
$res = sqlStatement("SELECT grp_group_id, grp_title FROM layout_group_properties WHERE grp_form_id = 'DEM' ORDER BY grp_group_id");
echo "<h2>layout_group_properties (DEM)</h2><table border=1><tr><th>group_id</th><th>title</th></tr>";
while ($row = sqlFetchArray($res)) {
    echo "<tr><td>" . htmlspecialchars($row['grp_group_id']) . "</td><td>" . htmlspecialchars($row['grp_title']) . "</td></tr>";
}
echo "</table>";

// Print all group_ids and field counts from layout_options
$res2 = sqlStatement("SELECT group_id, COUNT(*) as cnt FROM layout_options WHERE form_id = 'DEM' GROUP BY group_id ORDER BY group_id");
echo "<h2>layout_options (DEM)</h2><table border=1><tr><th>group_id</th><th>field count</th></tr>";
while ($row = sqlFetchArray($res2)) {
    echo "<tr><td>" . htmlspecialchars($row['group_id']) . "</td><td>" . htmlspecialchars($row['cnt']) . "</td></tr>";
}
echo "</table>"; 