<?php
/** Read-only ACP list boundaries, without a MyBB installation. */
define('IN_MYBB', 1);
require_once dirname(__DIR__).'/inc/plugins/vonseo/Utils.php';
require_once dirname(__DIR__).'/inc/plugins/vonseo/AdminList.php';

function htmlspecialchars_uni($value)
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function my_number_format($value)
{
    return number_format($value);
}
class ListTestDb
{
    public $exists = true;
    public $total = 301;
    public $queries = array();
    public $escapeCalls = array();
    public function table_exists($table) { return $this->exists; }
    public function escape_string($value) { $this->escapeCalls[] = $value; return addslashes($value); }
    public function simple_select($table, $fields, $where = '', $options = array())
    {
        $this->queries[] = array($table, $fields, $where, $options);
        return $fields === '*' ? (object)array('rows' => array(array('rid' => 7))) : array('total' => $this->total);
    }
    public function fetch_field($query, $field) { return $query[$field]; }
    public function fetch_array($query) { return array_shift($query->rows); }
}
$passed = 0;
$failed = 0;
function check($condition, $message)
{
    global $passed, $failed;
    if($condition) { ++$passed; } else { ++$failed; echo '[FAIL] '.$message.PHP_EOL; }
}
$db = new ListTestDb();
$list = new VonSEO_AdminList('redirects', array());
check($list->sort === 'newest' && $list->direction === 'desc' && $list->page === 1, 'Default redirects order');
$rows = $list->rows();
check(count($rows) === 1 && $list->pages === 7 && $list->total === 301, 'Rows beyond the former limit remain reachable');
check($db->queries[1][3]['limit'] === 50 && $db->queries[1][3]['limit_start'] === 0, 'First page is bounded');
$list = new VonSEO_AdminList('notfound', array('page' => PHP_INT_MAX, 'sort' => 'hits'));
$list->rows();
check($list->page === 7 && $db->queries[3][3]['limit_start'] === 300, 'Oversized requested page clamps before OFFSET');
check($db->queries[3][3]['order_by'] === 'hits DESC, path_hash', 'Ties have a stable unique ordering');
$db->total = 0;
$before = count($db->queries);
$list->rows();
check($list->page === 1 && $list->pages === 1 && count($db->queries) === $before + 1, 'Empty results skip row retrieval and clamp pagination');
check(strpos($list->pagination(), 'Showing 0-0 of 0 results') !== false, 'Empty result count');
$db->exists = false;
$before = count($db->queries);
$list = new VonSEO_AdminList('redirects', array('page' => 9));
check($list->rows() === array() && count($db->queries) === $before && $list->page === 1, 'Missing table is a safe empty list');
$db->exists = true;
$db->total = 301;
$list = new VonSEO_AdminList('redirects', array('sort' => 'rid; DROP TABLE settings', 'direction' => 'desc; SELECT 1', 'status' => '301 OR 1=1', 'enabled' => '0 OR 1=1', 'page' => '-3'));
$list->rows();
check($list->sort === 'newest' && $list->direction === 'desc' && $list->conditions() === '' && $list->page === 1, 'Untrusted SQL clauses are rejected');
$list = new VonSEO_AdminList('redirects', array('search' => "O'Brien%_!\\", 'status' => '410', 'enabled' => '0', 'sort' => 'path'));
$where = $list->conditions();
check(end($db->escapeCalls) === "%O'Brien!%!_!!\\%", 'Quotes and literal wildcard text pass through DB escaping');
check(strpos($where, "O\\'Brien") !== false && strpos($where, "ESCAPE '!'") !== false, 'Search cannot break its SQL string');
check(strpos($where, 'status_code=410 AND enabled=0') !== false, 'Disabled rules are a valid filter');
check($list->direction === 'asc' && strpos($where, 'target_url LIKE') !== false, 'Path defaults ascending and redirect destinations are searchable');
$list = new VonSEO_AdminList('notfound', array('search' => 'lost', 'enabled' => '0', 'status' => '410'));
check(strpos($list->conditions(), 'enabled') === false && strpos($list->conditions(), 'status_code') === false, '404 list ignores redirect-only filters');
$list = new VonSEO_AdminList('redirects', array('search' => array('bad'), 'sort' => array('bad'), 'page' => array(9)));
check($list->search === '' && $list->sort === 'newest' && $list->page === 1, 'Array-shaped request values are rejected');
$list = new VonSEO_AdminList('redirects', array('search' => str_repeat('a', 1000)));
check(strlen($list->search) === 200, 'Search size is bounded');
$list = new VonSEO_AdminList('redirects', array('search' => '\"><script>alert(1)</script>&x=y', 'enabled' => '0', 'status' => '410', 'sort' => 'hits', 'page' => 3));
$list->rows();
$html = $list->filters();
check(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false, 'Search output is escaped');
check(strpos($html, 'name="page"') === false && strpos($html, 'method="get"') !== false, 'Applying filters starts on page one without mutating data');
$url = $list->url(array('page' => 4));
parse_str(parse_url($url, PHP_URL_QUERY), $params);
check($params['search'] === $list->search && $params['enabled'] === '0' && $params['status'] === '410' && $params['page'] === '4', 'Page links retain filters without query injection');
$heading = $list->heading('Hits', 'hits');
check(strpos($heading, 'direction=asc') !== false && strpos($heading, 'page=1') !== false && strpos($heading, '&amp;') !== false, 'Sorting toggles direction and resets page');
check(substr_count($list->pagination(), '<a ') <= 4 && strpos($list->pagination(), 'Page 3 of 7') !== false, 'Navigation is bounded and names the current page');
$list = new VonSEO_AdminList('notfound', array('sort' => 'path', 'direction' => 'asc', 'page' => 7));
$list->rows();
check(strpos($list->pagination(), '>Next<') === false && strpos($list->pagination(), 'Showing 301-301 of 301') !== false, 'Last-page range and navigation');
$rejected = false;
try { new VonSEO_AdminList('settings', array()); } catch(InvalidArgumentException $exception) { $rejected = true; }
check($rejected, 'List type cannot select unrelated tables');
echo 'ACP lists: '.$passed.' passed, '.$failed.' failed'.PHP_EOL;
exit($failed ? 1 : 0);
