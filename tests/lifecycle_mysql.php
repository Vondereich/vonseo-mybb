<?php
/** Installed MyBB database/functions, isolated table prefix and settings-file sandbox. */
if(PHP_SAPI !== 'cli' || !isset($argv[1], $argv[2])) { exit(2); }
$coreRoot = realpath($argv[1]); $fixtureRoot = realpath($argv[2]);
$tempRoot = realpath(sys_get_temp_dir());
if(!$coreRoot || !$fixtureRoot || !$tempRoot ||
    strpos(strtolower(str_replace('\\','/',$fixtureRoot)), strtolower(str_replace('\\','/',$tempRoot)).'/') !== 0 ||
    strpos(basename($fixtureRoot), 'vonseo-lifecycle-') !== 0) { throw new RuntimeException('Expected an isolated temporary fixture directory'); }
define('IN_MYBB', 1); define('TIME_NOW', 1774267200);
define('MYBB_ROOT', str_replace('\\','/',$fixtureRoot).'/');
define('TABLE_PREFIX', 'vonseo_life_'.bin2hex(random_bytes(6)).'_');
if(is_file($coreRoot.'/inc/config.php')) { require $coreRoot.'/inc/config.php'; }
else {
    // CI uses pinned pristine MyBB source and an ephemeral database service.
    $config=array('database'=>array('type'=>'mysqli','hostname'=>getenv('DB_HOST') ?: '127.0.0.1',
        'username'=>getenv('DB_USER') ?: 'root','password'=>getenv('DB_PASSWORD') ?: '',
        'database'=>getenv('DB_DATABASE') ?: 'vonseo_test','encoding'=>'utf8mb4','table_prefix'=>'mybb_'));
}
$sourcePrefix = $config['database']['table_prefix'];
if(!preg_match('/^[A-Za-z0-9_]+$/D',$sourcePrefix)) { throw new RuntimeException('Unexpected source prefix'); }
require $coreRoot.'/inc/db_base.php'; require $coreRoot.'/inc/db_mysqli.php';
require $coreRoot.'/inc/functions.php';
class LifecycleCache {
    public $data=array();
    public function read($key) { return isset($this->data[$key]) ? $this->data[$key] : false; }
    public function update($key,$value) { $this->data[$key]=$value; }
    public function delete($key) { unset($this->data[$key]); }
    public function update_tasks() {}
}
$mybb = new stdClass(); $mybb->debug_mode=false;
$mybb->settings=array('bbname'=>"O'Brien Board",'bburl'=>'https://example.com/forum','homename'=>'Parent site','homeurl'=>'https://example.com');
$mybb->user=array('uid'=>1); $cache=new LifecycleCache(); $plugins=null;
set_error_handler(function($severity,$message,$file,$line) {
    if(error_reporting() & $severity) { throw new ErrorException($message,0,$severity,$file,$line); }
    return false;
});
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=new DB_MySQLi(); $db->type='mysqli'; $db->set_table_prefix(TABLE_PREFIX); $db->connect($config['database']);
$db->write_query("SET SESSION sql_mode='STRICT_ALL_TABLES'");
$coreTables=array('settings','settinggroups','tasks','tasklog');
$owned=array('vonseo_redirects','vonseo_404_log','vonseo_indexnow_queue','vonseo_indexnow_threads','vonseo_meta','vonseo_overrides');
$passed=0; $failed=0;
function lifecycleCheck($condition,$label) {
    if($condition) { ++$GLOBALS['passed']; } else { ++$GLOBALS['failed']; echo '[FAIL] '.$label.PHP_EOL; }
}
function lifecycleRows($table) {
    global $db;
    $rows=array(); $query=$db->simple_select($table,'*');
    while($row=$db->fetch_array($query)) { $rows[]=$row; }
    return $rows;
}
try {
    if(is_file($coreRoot.'/inc/config.php')) {
        foreach($coreTables as $table) { $db->write_query('CREATE TABLE `'.TABLE_PREFIX.$table.'` LIKE `'.$sourcePrefix.$table.'`'); }
    } else {
        $tables=array(); require $coreRoot.'/install/resources/mysql_db_tables.php';
        $created=array();
        foreach($tables as $sql) {
            if(preg_match('/^CREATE TABLE mybb_([a-z_]+)\s/',$sql,$match) && in_array($match[1],$coreTables,true)) {
                $sql=str_replace('CREATE TABLE mybb_'.$match[1], 'CREATE TABLE '.TABLE_PREFIX.$match[1], $sql);
                $sql=preg_replace('/ENGINE=MyISAM;/','ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;', $sql);
                $db->write_query($sql); $created[]=$match[1];
            }
        }
        if(count($created)!==count($coreTables)) { throw new RuntimeException('Pinned MyBB schema did not supply all required core tables'); }
    }
    foreach($mybb->settings as $name=>$value) {
        $db->insert_query('settings',array('name'=>$name,'title'=>$name,'value'=>$db->escape_string($value),'optionscode'=>'text','description'=>'fixture','disporder'=>1,'gid'=>0,'isdefault'=>0));
    }
    require MYBB_ROOT.'inc/plugins/vonseo.php'; vonseo_require_core();
    vonseo_activate();
    lifecycleCheck(VonSEO_State::schemaVersion()===6 && count(lifecycleRows('vonseo_overrides'))===0,'Fresh native-DB install creates schema 6');
    $initialSettings=lifecycleRows('settings');
    lifecycleCheck(count(array_filter($initialSettings,function($row){return strpos($row['name'],'vonseo_')===0;}))===25,'Fresh install registers 25 VonSEO settings');
    lifecycleCheck(count(lifecycleRows('tasks'))===1 && lifecycleRows('tasks')[0]['enabled']=='1','Activation enables exactly one worker task');
    $redirects=new VonSEO_Redirects(new VonSEO_Url());
    $redirect=$redirects->saveRule('/life-old',"/life-o'brien",301);
    lifecycleCheck(!$redirect['errors'] && lifecycleRows('vonseo_redirects')[0]['target_url']==="https://example.com/forum/life-o'brien",'Real MyBB caller escaping preserves apostrophes');
    // Test native escaping with both supported UTF-8 forms, before any SQL write.
    $encoding=$db->db_encoding;
    foreach(array('utf8','utf8mb4') as $mode) {
        $db->db_encoding=$mode;
        $valid=$redirects->validateRule('/emoji-😀','/destination-😀',301);
        lifecycleCheck($mode==='utf8' ? !empty($valid['errors']) : empty($valid['errors']) &&
            stripslashes($db->escape_string($valid['source']))===$valid['source'], 'Actual MyBB charset handling preserves identity or rejects: '.$mode);
    }
    $db->db_encoding=$encoding;
    $db->replace_query('vonseo_overrides',array('entity_type'=>'thread','entity_id'=>16,'seo_title'=>$db->escape_string("O'Brien SEO"),'seo_description'=>'Fixture description','seo_image'=>'','index_mode'=>'noindex','updated_by'=>1,'updated_at'=>TIME_NOW));
    $db->insert_query('vonseo_indexnow_queue',array('tid'=>16,'generation'=>str_repeat('b',32),'event_type'=>'current','url_hash'=>hash('sha256','fixture'),'url'=>'https://example.com/forum/showthread.php?tid=16','attempts'=>3,'available_at'=>TIME_NOW));
    $preserved=array(lifecycleRows('vonseo_redirects'),lifecycleRows('vonseo_overrides'),lifecycleRows('vonseo_indexnow_queue'));
    vonseo_deactivate();
    lifecycleCheck(lifecycleRows('tasks')[0]['enabled']=='0' && $preserved===array(lifecycleRows('vonseo_redirects'),lifecycleRows('vonseo_overrides'),lifecycleRows('vonseo_indexnow_queue')),'Deactivation disables worker but retains all plugin data');
    vonseo_activate(); vonseo_activate();
    lifecycleCheck(count(lifecycleRows('tasks'))===1 && $preserved===array(lifecycleRows('vonseo_redirects'),lifecycleRows('vonseo_overrides'),lifecycleRows('vonseo_indexnow_queue')) && $initialSettings===lifecycleRows('settings'),'Repeated activation preserves values, overrides, redirects and retries');
    // Schema 5 -> 6 migration boundary, not a claim of a full older-forum clone.
    $db->drop_table('vonseo_overrides'); VonSEO_State::setSchemaVersion(5);
    VonSEO_State::resetRuntime(); $cache->delete('vonseo_override_schema');
    vonseo_activate();
    lifecycleCheck(VonSEO_State::schemaVersion()===6 && $db->table_exists('vonseo_overrides') &&
        $preserved[0]===lifecycleRows('vonseo_redirects') && $preserved[2]===lifecycleRows('vonseo_indexnow_queue'),'Schema 5 upgrade restores override table without resetting existing data');
    $db->insert_query('tasks',array('title'=>'Unrelated fixture','description'=>'Independent task fixture','file'=>'unrelated_fixture','minute'=>'*','hour'=>'*','day'=>'*','month'=>'*','weekday'=>'*','nextrun'=>TIME_NOW+60,'lastrun'=>0,'enabled'=>0,'logging'=>0,'locked'=>0));
    vonseo_uninstall();
    foreach($owned as $table) { lifecycleCheck(!$db->table_exists($table),'Uninstall removes owned table '.$table); }
    lifecycleCheck(count(lifecycleRows('settings'))===4 && lifecycleRows('settings')[0]['value']==="O'Brien Board",'Uninstall retains original MyBB identity settings');
    lifecycleCheck(count(lifecycleRows('tasks'))===1 && lifecycleRows('tasks')[0]['file']==='unrelated_fixture','Uninstall retains unrelated tasks');
    vonseo_activate();
    lifecycleCheck(VonSEO_State::schemaVersion()===6 && count(lifecycleRows('vonseo_overrides'))===0,'Fresh reinstall after uninstall succeeds');
} finally {
    foreach(array_merge($owned,$coreTables) as $table) {
        if(!preg_match('/^vonseo_life_[a-f0-9]{12}_$/D',TABLE_PREFIX) || !in_array($table,array_merge($owned,$coreTables),true)) { throw new RuntimeException('Unsafe cleanup prefix'); }
        $db->drop_table($table,true);
    }
    // PHP closes the connection at process exit; some MyBB versions alias
    // read/write handles and cannot explicitly close both under newer mysqli.
}
echo 'MyBB native-DB lifecycle: '.$passed.' passed, '.$failed.' failed; only isolated scratch tables removed'.PHP_EOL;
exit($failed ? 1 : 0);
