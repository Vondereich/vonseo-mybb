<?php
/** Read-only fingerprints for an explicitly selected local board. Never prints credentials or content. */
if(PHP_SAPI !== 'cli' || !isset($argv[1])) { exit(2); }
$root = realpath($argv[1]);
if(!$root || !is_file($root.'/inc/config.php')) { exit(2); }
define('IN_MYBB', 1);
require $root.'/inc/config.php';
$settings = $config['database'];
$prefix = $settings['table_prefix'];
if(!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) { exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$sql = new mysqli($settings['hostname'], $settings['username'], $settings['password'], $settings['database'], isset($settings['port']) ? (int)$settings['port'] : 3306);
$sql->set_charset('utf8mb4');
$identity = $sql->query("SELECT name,value FROM {$prefix}settings WHERE name IN ('bbname','bburl','homename','homeurl') OR name LIKE 'vonseo\\_%' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$out = array('settings_value_hash'=>hash('sha256',json_encode($identity)), 'settings_count'=>count($identity));
foreach(array('vonseo_redirects'=>'rid','vonseo_indexnow_queue'=>'qid','threads'=>'tid','forums'=>'fid') as $table=>$order) {
    $rows=$sql->query("SELECT * FROM {$prefix}{$table} ORDER BY {$order}")->fetch_all(MYSQLI_ASSOC);
    $out[$table]=array('rows'=>count($rows),'hash'=>hash('sha256',json_encode($rows)));
    if($table==='threads') {
        foreach($rows as &$row) { unset($row['views']); }
        unset($row);
        $out[$table]['content_hash_without_views']=hash('sha256',json_encode($rows));
    }
}
$out['temporary_permission_fixtures']=array();
foreach(array('users'=>"username LIKE 'vsqc\\_%'",'forums'=>"name LIKE 'VonSEO QC %'",'threads'=>"subject LIKE 'VonSEO QC %'") as $table=>$where) {
    $out['temporary_permission_fixtures'][$table]=(int)$sql->query("SELECT COUNT(*) AS n FROM {$prefix}{$table} WHERE {$where}")->fetch_assoc()['n'];
}
$store=$sql->query("SHOW TABLES LIKE '".$sql->real_escape_string($prefix.'vonseo_overrides')."'");
$out['override_storage']=$store->num_rows===1;
if($out['override_storage']) {
    $rows=$sql->query("SELECT * FROM {$prefix}vonseo_overrides ORDER BY entity_type,entity_id")->fetch_all(MYSQLI_ASSOC);
    $out['overrides']=array('rows'=>count($rows),'hash'=>hash('sha256',json_encode($rows)));
    $out['override_indexes']=$sql->query("SHOW INDEX FROM {$prefix}vonseo_overrides")->fetch_all(MYSQLI_ASSOC);
}
$out['database_version']=$sql->server_info;
echo json_encode($out,JSON_PRETTY_PRINT).PHP_EOL;
