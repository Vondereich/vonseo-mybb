<?php
/** Real SQL boundary/race proof in random scratch tables, never production plugin tables. */
if(PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IN_MYBB', 1);
define('TIME_NOW', 1774267200);
define('TABLE_PREFIX', 'vonseo_qc_'.bin2hex(random_bytes(6)).'_');
require_once dirname(__DIR__).'/inc/plugins/vonseo/Utils.php';
require_once dirname(__DIR__).'/inc/plugins/vonseo/Url.php';
require_once dirname(__DIR__).'/inc/plugins/vonseo/Redirects.php';
require_once dirname(__DIR__).'/inc/plugins/vonseo/IndexNow.php';
if(isset($argv[1])) { require $argv[1]; $settings = $config['database']; }
else { $settings = array('hostname'=>getenv('DB_HOST') ?: '127.0.0.1', 'port'=>getenv('DB_PORT') ?: 3306,
    'username'=>getenv('DB_USER') ?: 'root', 'password'=>getenv('DB_PASSWORD') ?: '', 'database'=>getenv('DB_DATABASE') ?: 'vonseo_test'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function qcConnection(array $settings): mysqli {
    $connection = new mysqli($settings['hostname'], $settings['username'], $settings['password'], $settings['database'], isset($settings['port']) ? (int)$settings['port'] : 3306);
    $connection->set_charset('utf8mb4');
    $connection->query("SET SESSION sql_mode='STRICT_ALL_TABLES'");
    return $connection;
}
class CorrectnessSqlDb {
    public $connection;
    public $async = false;
    public function __construct(mysqli $connection) { $this->connection = $connection; }
    public function table_exists($table) { return in_array($table, array('vonseo_redirects','vonseo_indexnow_threads'), true); }
    public function escape_string($value) { return $this->connection->real_escape_string($value); }
    public function simple_select($table, $fields, $where='', $options=array()) {
        return $this->connection->query('SELECT '.$fields.' FROM `'.TABLE_PREFIX.$table.'`'.($where !== '' ? ' WHERE '.$where : '').(isset($options['limit']) ? ' LIMIT '.(int)$options['limit'] : ''));
    }
    public function fetch_array($query) { return $query->fetch_assoc(); }
    public function fetch_field($query, $field) { $row = $this->fetch_array($query); return isset($row[$field]) ? $row[$field] : null; }
    public function write_query($sql) { return $this->async ? $this->connection->query($sql, MYSQLI_ASYNC) : $this->connection->query($sql); }
    public function insert_query($table, array $data) {
        $columns = implode(',', array_keys($data));
        $values = array_map(function($value) { return "'".$value."'"; }, array_values($data));
        $this->connection->query('INSERT INTO `'.TABLE_PREFIX.$table.'` ('.$columns.') VALUES ('.implode(',', $values).')');
        return $this->connection->insert_id;
    }
    public function update_query($table, array $data, $where) {
        $set = array(); foreach($data as $name=>$value) { $set[] = $name."='".$value."'"; }
        $this->connection->query('UPDATE `'.TABLE_PREFIX.$table.'` SET '.implode(',', $set).' WHERE '.$where);
    }
}
class SqlHistoryProbe extends VonSEO_IndexNow {
    public function remember($id, $url) { $this->rememberPublicUrl($id, $url); }
    public function submitted($id, $url, $time) { $this->markThreadSubmitted($id, $url, $time); }
}
$connection = qcConnection($settings); $other = qcConnection($settings);
$db = new CorrectnessSqlDb($connection);
$mybb = new stdClass(); $mybb->settings = array('vonseo_external_redirects'=>0);
$passed=0; $failed=0;
function checkCorrectnessSql($condition, $message) {
    if($condition) { ++$GLOBALS['passed']; } else { ++$GLOBALS['failed']; echo '[FAIL] '.$message.PHP_EOL; }
}
$tables = array(TABLE_PREFIX.'vonseo_redirects', TABLE_PREFIX.'vonseo_indexnow_threads');
try {
    $connection->query('CREATE TABLE `'.$tables[0].'` (rid INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_hash CHAR(64) UNIQUE, source_path VARCHAR(768), target_url VARCHAR(1536), status_code INT, enabled INT, hits INT, first_hit INT, last_hit INT, created_at INT, updated_at INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $connection->query('CREATE TABLE `'.$tables[1].'` (tid INT UNSIGNED PRIMARY KEY, last_public_url TEXT NOT NULL, last_submitted_at INT UNSIGNED NOT NULL DEFAULT 0, updated_at INT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $redirects = new VonSEO_Redirects(new VonSEO_Url('https://example.com/forum'));
    foreach(array('a', 'é', '界', '😀') as $character) {
        $source = '/'.str_repeat($character,767); $prefix='https://example.com/forum/';
        $target=$prefix.str_repeat($character,1536-strlen($prefix));
        $saved = $redirects->saveRule($source,$target,301);
        $row=isset($saved['rid']) ? $db->fetch_array($db->simple_select('vonseo_redirects','*',"rid='".(int)$saved['rid']."'")) : array();
        checkCorrectnessSql(!$saved['errors'] && $row['source_path']===$source && $row['target_url']===$target, 'Exact ASCII/multibyte VARCHAR boundary readback: '.$character);
    }
    $before=(int)$db->fetch_field($db->simple_select('vonseo_redirects','COUNT(*) AS total'),'total');
    $result=$redirects->importCsv("source_path,target_url,status_code,enabled\n/".str_repeat('a',768).",/valid,301,1\n/over-target,https://example.com/forum/".str_repeat('é',1512).",301,1\n/good,/target,301,1\n");
    $after=(int)$db->fetch_field($db->simple_select('vonseo_redirects','COUNT(*) AS total'),'total');
    checkCorrectnessSql($result['imported']===1 && $result['skipped']===2 && $after===$before+1, 'Strict SQL rejects overlong rows before write; valid CSV row survives');
    $probe=new SqlHistoryProbe(new VonSEO_Url('https://example.com/forum'));
    $probe->remember(10,'https://example.com/forum/t-10-old');
    $connection->begin_transaction();
    $probe->submitted(10,'https://example.com/forum/t-10-old',TIME_NOW+120);
    // Connection B executes the actual admission while A holds the row lock.
    $db=new CorrectnessSqlDb($other); $db->async=true;
    $probe->remember(10,'https://example.com/forum/t-10-new');
    $read=array($other); $error=array(); $reject=array();
    checkCorrectnessSql(mysqli_poll($read,$error,$reject,0,100000)===0, 'Second connection waits on the first worker row lock');
    $connection->commit();
    $read=array($other); $error=array(); $reject=array();
    if(mysqli_poll($read,$error,$reject,5)!==1) { throw new RuntimeException('Concurrent history statement did not finish'); }
    $other->reap_async_query(); $db->async=false;
    $probe->submitted(10,'https://example.com/forum/t-10-older',TIME_NOW+30);
    $row=$db->fetch_array($db->simple_select('vonseo_indexnow_threads','*',"tid='10'"));
    checkCorrectnessSql((int)$row['last_submitted_at']===TIME_NOW+120 && $row['last_public_url']==='https://example.com/forum/t-10-new', 'Real interleaving retains monotonic cooldown and newer admission URL');
    $probe->submitted(11,'https://example.com/forum/t-11-new',TIME_NOW+120);
    $row=$db->fetch_array($db->simple_select('vonseo_indexnow_threads','*',"tid='11'"));
    checkCorrectnessSql((int)$row['last_submitted_at']===TIME_NOW+120, 'Successful delivery initializes missing history');
    echo 'Server: '.$connection->server_info.PHP_EOL;
} finally {
    $connection->rollback();
    $other->close();
    foreach($tables as $table) {
        if(!preg_match('/^vonseo_qc_[a-f0-9]{12}_vonseo_(redirects|indexnow_threads)$/D',$table)) { throw new RuntimeException('Unsafe scratch cleanup target'); }
        $connection->query('DROP TABLE IF EXISTS `'.$table.'`');
    }
    $connection->close();
}
echo 'SQL release correctness: '.$passed.' passed, '.$failed.' failed; only random scratch tables removed'.PHP_EOL;
exit($failed ? 1 : 0);
