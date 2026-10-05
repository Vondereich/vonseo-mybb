<?php
/** Real MySQL/MariaDB list queries against connection-local temporary tables. */
if(PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IN_MYBB', 1);
require_once dirname(__DIR__).'/inc/plugins/vonseo/Utils.php';
require_once dirname(__DIR__).'/inc/plugins/vonseo/AdminList.php';
function htmlspecialchars_uni($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function my_number_format($value) { return number_format($value); }
if(isset($argv[1]))
{
    require $argv[1];
    $settings = $config['database'];
}
else
{
    $settings = array('hostname' => getenv('DB_HOST') ?: '127.0.0.1', 'port' => getenv('DB_PORT') ?: 3306,
        'username' => getenv('DB_USER') ?: 'root', 'password' => getenv('DB_PASSWORD') ?: '',
        'database' => getenv('DB_DATABASE') ?: 'vonseo_test');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = new mysqli($settings['hostname'], $settings['username'], $settings['password'], $settings['database'], isset($settings['port']) ? (int)$settings['port'] : 3306);
$connection->set_charset('utf8mb4');
class ListSqlDb
{
    public $connection;
    public $prefix;
    public function __construct($connection)
    {
        $this->connection = $connection;
        $this->prefix = 'vonseo_probe_'.bin2hex(random_bytes(6)).'_';
    }
    public function table_exists($table) { return in_array($table, array('vonseo_redirects', 'vonseo_404_log'), true); }
    public function escape_string($value) { return $this->connection->real_escape_string($value); }
    public function simple_select($table, $fields, $where = '', $options = array())
    {
        $sql = 'SELECT '.$fields.' FROM `'.$this->prefix.$table.'`'.($where !== '' ? ' WHERE '.$where : '');
        if(isset($options['order_by'])) { $sql .= ' ORDER BY '.$options['order_by'].' '.$options['order_dir']; }
        if(isset($options['limit'])) { $sql .= ' LIMIT '.(int)$options['limit_start'].','.(int)$options['limit']; }
        return $this->connection->query($sql);
    }
    public function fetch_field($query, $field) { $row = $query->fetch_assoc(); return $row[$field]; }
    public function fetch_array($query) { return $query->fetch_assoc(); }
}
$db = new ListSqlDb($connection);
$redirectTable = $db->prefix.'vonseo_redirects';
$errorTable = $db->prefix.'vonseo_404_log';
$connection->query('CREATE TEMPORARY TABLE `'.$redirectTable.'` (rid INT PRIMARY KEY, source_path VARCHAR(512), target_url VARCHAR(512), status_code INT, enabled INT, hits INT, last_hit INT)');
$connection->query('CREATE TEMPORARY TABLE `'.$errorTable.'` (path_hash CHAR(64) PRIMARY KEY, path VARCHAR(512), hits INT, first_seen INT, last_seen INT)');
$insert = $connection->prepare('INSERT INTO `'.$redirectTable.'` VALUES (?,?,?,?,?,?,?)');
for($i = 1; $i <= 321; ++$i)
{
    $source = '/test-'.$i;
    $target = '/destination-'.$i;
    $status = $i % 2 ? 301 : 410;
    $enabled = $i % 3 ? 1 : 0;
    $hits = $i % 7;
    $lastHit = $i;
    $insert->bind_param('issiiii', $i, $source, $target, $status, $enabled, $hits, $lastHit);
    $insert->execute();
}
$i = 322; $source = "/O'Brien_100%!"; $target = '/target'; $status = 302; $enabled = 0; $hits = 100; $lastHit = 0;
$insert->execute(); // Existing bind references include the special row values.
$insert->close();
$insertError = $connection->prepare('INSERT INTO `'.$errorTable.'` VALUES (?,?,?,?,?)');
for($i = 1; $i <= 301; ++$i)
{
    $path = '/missing-'.$i; $hash = hash('sha256', $path); $hits = $i % 5; $first = $i; $last = $i + 1;
    $insertError->bind_param('ssiii', $hash, $path, $hits, $first, $last);
    $insertError->execute();
}
$path = "/O'Brien_100%!"; $hash = hash('sha256', $path); $hits = 100; $first = 0; $last = 1; $insertError->execute();
$passed = 0; $failed = 0;
function checkSql($condition, $message)
{
    global $passed, $failed;
    if($condition) { ++$passed; } else { ++$failed; echo '[FAIL] '.$message.PHP_EOL; }
}
$list = new VonSEO_AdminList('redirects', array());
$rows = $list->rows();
checkSql(count($rows) === 50 && $rows[0]['rid'] == 322 && $list->total === 322 && $list->pages === 7, 'First redirect page');
$all = array();
for($page = 1; $page <= 7; ++$page)
{
    $list = new VonSEO_AdminList('redirects', array('page' => $page, 'sort' => 'hits', 'direction' => 'desc'));
    foreach($list->rows() as $row) { $all[] = (int)$row['rid']; }
}
checkSql(count($all) === 322 && count(array_unique($all)) === 322 && $all[0] === 322, 'All redirects remain reachable without duplicate ties');
$list = new VonSEO_AdminList('redirects', array('page' => PHP_INT_MAX));
$rows = $list->rows();
checkSql($list->page === 7 && count($rows) === 22 && (int)end($rows)['rid'] === 1, 'Deep page clamps safely');
foreach(array("O'Brien", '_100', '%!', '!') as $search)
{
    $list = new VonSEO_AdminList('redirects', array('search' => $search));
    $rows = $list->rows();
    checkSql($list->total === 1 && (int)$rows[0]['rid'] === 322, 'Literal search: '.$search);
}
$list = new VonSEO_AdminList('redirects', array('search' => "' OR 1=1 -- ", 'status' => '301 OR 1=1'));
checkSql($list->rows() === array() && $list->total === 0, 'SQL-like input does not widen results');
$list = new VonSEO_AdminList('redirects', array('search' => 'destination-321'));
$rows = $list->rows();
checkSql(count($rows) === 1 && (int)$rows[0]['rid'] === 321, 'Destination search');
$list = new VonSEO_AdminList('redirects', array('status' => 410, 'enabled' => 0));
$rows = $list->rows();
checkSql($list->total === 53 && count($rows) === 50 && count(array_filter($rows, static function($row) { return (int)$row['status_code'] !== 410 || (int)$row['enabled'] !== 0; })) === 0, 'Combined filters and disabled rules');
$list = new VonSEO_AdminList('notfound', array('search' => "O'Brien_100%!"));
checkSql(count($list->rows()) === 1 && $list->total === 1, '404 literal search');
$all = array();
for($page = 1; $page <= 7; ++$page)
{
    $list = new VonSEO_AdminList('notfound', array('page' => $page, 'sort' => 'hits'));
    foreach($list->rows() as $row) { $all[] = $row['path_hash']; }
}
checkSql(count($all) === 302 && count(array_unique($all)) === 302, 'All 404 rows beyond 250 remain reachable');
$list = new VonSEO_AdminList('notfound', array('sort' => 'last_seen', 'direction' => 'desc'));
$rows = $list->rows();
checkSql($rows[0]['path'] === '/missing-301', '404 date sorting');
$count = $connection->query('SELECT COUNT(*) AS total FROM `'.$redirectTable.'`')->fetch_assoc();
checkSql((int)$count['total'] === 322, 'Read-only list queries preserve all rules');
$connection->close(); // Temporary tables disappear with this connection.
echo 'ACP MySQL/MariaDB lists: '.$passed.' passed, '.$failed.' failed'.PHP_EOL;
exit($failed ? 1 : 0);
