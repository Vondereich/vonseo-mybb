<?php
/** Read-only saved-rule inspection without a live MyBB installation. */
define('IN_MYBB', 1);
require_once dirname(__DIR__).'/inc/plugins/vonseo/Utils.php';
require_once dirname(__DIR__).'/inc/plugins/vonseo/Url.php';
require_once dirname(__DIR__).'/inc/plugins/vonseo/Redirects.php';
require_once dirname(__DIR__).'/inc/plugins/vonseo/AdminRedirects.php';

class InspectorTestDb
{
    public $rules = array();
    public $queries = array();
    public $exists = true;
    public $tableChecks = 0;
    public $escaped = array();
    public function table_exists($table)
    {
        if($table !== 'vonseo_redirects') { throw new RuntimeException('Unexpected table'); }
        ++$this->tableChecks;
        return $this->exists;
    }
    public function escape_string($value) { $this->escaped[] = $value; return addslashes($value); }
    public function simple_select($table, $fields, $where = '', $options = array())
    {
        $this->queries[] = array($table, $fields, $where, $options);
        if($table !== 'vonseo_redirects' || !preg_match("/^source_hash='([a-f0-9]{64})'$/", $where, $match) || $options !== array('limit' => 1))
        {
            throw new RuntimeException('Unexpected or unbounded query');
        }
        return isset($this->rules[$match[1]]) ? $this->rules[$match[1]] : array();
    }
    public function fetch_array($row) { return $row ?: false; }
    public function write_query(...$args) { throw new RuntimeException('Inspector must not write'); }
    public function insert_query(...$args) { throw new RuntimeException('Inspector must not insert'); }
    public function update_query(...$args) { throw new RuntimeException('Inspector must not update'); }
    public function delete_query(...$args) { throw new RuntimeException('Inspector must not delete'); }
}
class InspectorTestMyBB
{
    public $settings = array('bburl' => 'https://example.com/forum', 'vonseo_enabled' => '1',
        'vonseo_redirects_enabled' => '1', 'vonseo_external_redirects' => '0');
}
// A deliberately stale frontend count must not hide saved rules from the ACP.
class VonSEO_State
{
    public static function activeRedirects() { throw new RuntimeException('ACP inspection must not read the frontend count cache'); }
}
$passed = 0;
$failed = 0;
function check($condition, $message)
{
    global $passed, $failed;
    if($condition) { ++$passed; } else { ++$failed; echo '[FAIL] '.$message.PHP_EOL; }
}
function rules(array $items)
{
    global $db;
    $db = new InspectorTestDb();
    foreach($items as $i => $item)
    {
        $db->rules[hash('sha256', $item[0])] = array('rid' => $i + 1, 'source_path' => $item[0], 'target_url' => $item[1],
            'status_code' => isset($item[2]) ? $item[2] : 301, 'enabled' => isset($item[3]) ? $item[3] : 1,
            'hits' => 9, 'last_hit' => 1234);
    }
}
$mybb = new InspectorTestMyBB();
$inspector = new VonSEO_AdminRedirects();
rules(array());
$result = $inspector->inspect('/missing');
check($result['status'] === 'no_rule' && $result['hops'] === 0 && !$result['steps'] && !$result['suggestion'], 'A missing rule is not claimed as a successful HTTP response');
check(count($db->queries) === 1 && $db->tableChecks === 1, 'Empty inspection has one indexed lookup and one table check');
check($db->queries[0][1] === 'rid,source_path,target_url,status_code,enabled' && $db->escaped === array(hash('sha256', '/missing')), 'Only fixed rule columns and an escaped hash enter the DB query');

rules(array(array('/a', '/b'), array('/b', '/final', 308)));
$beforeRules = $db->rules;
$result = $inspector->inspect('https://EXAMPLE.com:443/forum/a#section');
check($result['status'] === 'no_rule' && $result['hops'] === 2 && count($result['steps']) === 2 && $result['suggestion'], 'Permanent local chains are traced with a manual shortening suggestion');
check($result['source'] === '/a' && $result['final_url'] === 'https://example.com/forum/final', 'Same-origin default ports, board subfolders and input fragments are handled');
check(count($db->queries) === 3 && $db->rules === $beforeRules, 'Inspection does not change rules, hit counters or timestamps');
$html = VonSEO_AdminRedirects::render($result);
check(strpos($html, 'Possible shortening') !== false && strpos($html, 'Review rule #1') !== false && strpos($html, 'HTTP 200') !== false, 'Result labels explain the boundary and link to manual review');

rules(array(array('/a', '/b', 302), array('/b', '/final', 301)));
$result = $inspector->inspect('/a');
check($result['hops'] === 2 && !$result['suggestion'], 'Temporary chains do not get a permanent shortening suggestion');
rules(array(array('/a', '/b'), array('/b', '/a')));
$result = $inspector->inspect('/a');
check($result['status'] === 'loop' && $result['hops'] === 2 && count($db->queries) === 2 && !$result['suggestion'], 'A repeating source stops before another DB query');
rules(array(array('/a', '/a#fragment')));
$result = $inspector->inspect('/a');
check($result['status'] === 'loop' && $result['hops'] === 0 && $result['steps'][0]['state'] === 'Self-redirect blocked', 'Fragment-only self redirects match the runtime guard');
rules(array(array('/a', '/a/')));
check($inspector->inspect('/a')['status'] === 'loop', 'Trailing-slash self comparisons match the existing runtime policy');

rules(array(array('/a', '/b'), array('/b', '/c', 301, 0)));
$result = $inspector->inspect('/a');
check($result['status'] === 'disabled' && $result['hops'] === 1 && $result['steps'][1]['state'] === 'Disabled', 'A disabled rule is visible but never followed');
rules(array(array('/a', '/b', 301, 0)));
check($inspector->inspect('/a')['hops'] === 0, 'An initially disabled rule has no redirect hop');
rules(array(array('/a', '/retired'), array('/retired', 'ignored', 410)));
$result = $inspector->inspect('/a');
check($result['status'] === 'gone' && $result['hops'] === 1 && $result['steps'][1]['target'] === '' && !$result['suggestion'], '410 terminates the chain without a fabricated redirect destination');
check(strpos(VonSEO_AdminRedirects::render($result), 'No destination (410 Gone)') !== false, '410 table labels explain the empty destination');

foreach(VonSEO_AdminRedirects::methods() as $method)
{
    foreach(array(301, 302, 307, 308) as $code)
    {
        rules(array(array('/a', '/b', $code)));
        $result = $inspector->inspect('/a', $method);
        $blocked = !in_array($method, array('GET', 'HEAD'), true) && in_array($code, array(301, 302), true);
        check($result['status'] === ($blocked ? 'method_blocked' : 'no_rule') && $result['hops'] === ($blocked ? 0 : 1), $method.' '.$code.' follows the runtime method policy');
    }
}
rules(array(array('/a', '/b', 307), array('/b', '/c', 301)));
$result = $inspector->inspect('/a', 'post');
check($result['status'] === 'method_blocked' && $result['hops'] === 1 && $result['method'] === 'POST', 'A method-preserving first hop does not bypass a later method restriction');
rules(array(array('/a', '/b', 410)));
check($inspector->inspect('/a', 'POST')['status'] === 'gone', '410 is available for non-GET methods too');

rules(array(array('/a', 'https://outside.example/destination')));
check($inspector->inspect('/a')['status'] === 'invalid_target', 'An external target is rejected when external redirects are off');
$mybb->settings['vonseo_external_redirects'] = '1';
rules(array(array('/a', 'https://outside.example/destination')));
$result = $inspector->inspect('/a');
check($result['status'] === 'external' && $result['hops'] === 1 && count($db->queries) === 1 && !$result['suggestion'], 'Allowed external targets stop after one saved rule without fetching or following');
foreach(array('http://example.com/forum/b', 'https://example.com:444/forum/b') as $target)
{
    rules(array(array('/a', $target)));
    check($inspector->inspect('/a')['status'] === 'external', 'Another scheme or port is outside the board origin');
}
$mybb->settings['vonseo_external_redirects'] = '0';
foreach(array('https://example.com/elsewhere', 'https://example.com/forumish/b') as $target)
{
    rules(array(array('/a', $target), array('/elsewhere', '/would-be-wrong')));
    $result = $inspector->inspect('/a');
    check($result['status'] === 'outside_board' && count($db->queries) === 1, 'Same-origin destinations outside the exact board path are not mapped back into the forum');
}
foreach(array('https://example.com/forum/../elsewhere', 'https://example.com/forum/a%2Fb', 'https://example.com/forum/%2e%2e/elsewhere', 'https://example.com/forum/a%5Cb') as $target)
{
    rules(array(array('/a', $target)));
    check($inspector->inspect('/a')['status'] === 'routing_uncertain' && count($db->queries) === 1, 'Ambiguous browser/server path normalization is not guessed');
}
foreach(array('javascript:alert(1)', '//outside.example/b', 'https://user:password@example.com/forum/b', "/bad\r\nLocation: x", '/bad\\path') as $target)
{
    rules(array(array('/a', $target)));
    check($inspector->inspect('/a')['status'] === 'invalid_target', 'Unsafe legacy targets stop inspection');
}

rules(array(array('/a?view=1', '/b?view=2#section'), array('/b?view=2', '/final')));
$result = $inspector->inspect('/a?view=1');
check($result['hops'] === 2 && $result['status'] === 'no_rule', 'Exact query identities remain in the trace while destination fragments are ignored for matching');
check($inspector->inspect('/a?view=2')['hops'] === 0 && $inspector->inspect('/a')['hops'] === 0, 'A path-only or different-query visit cannot match the query-bearing rule');
rules(array(array('/a?x=1&y=2', '/final')));
check($inspector->inspect('/a?x=1&amp;y=2')['hops'] === 1, 'HTML entity normalization uses the shared source resolver once');

// Legacy frontend normalization must not be misrepresented as an exact trace.
foreach(array(
    array('https://example.com/forum/forum/b', 'https://example.com/forum/forum/b'),
    array('https://example.com/forum/forum/', 'https://example.com/forum/forum/'),
    array('https://example.com/forum//forum/b', 'https://example.com/forum//forum/b'),
    array('/forum/forum/b', 'https://example.com/forum/forum/b'),
    array('/a?0', 'https://example.com/forum/a?0'),
    array('https://example.com/forum/a?0', 'https://example.com/forum/a?0')
) as $case)
{
    rules(array(array('/a', '/final'), array('/b', '/final')));
    $result = $inspector->inspect($case[0]);
    check($result['status'] === 'routing_uncertain' && !$db->queries && !$db->tableChecks &&
        !$result['steps'] && !$result['hops'] && !$result['suggestion'], 'Ambiguous initial identities stop before a fabricated exact lookup');
    check($result['final_url'] === $case[1], 'The initial uncertain URL is not silently shortened or stripped of its zero query');
}
foreach(array('https://example.com/forum/forum/b', 'https://example.com/forum/b?0') as $target)
{
    rules(array(array('/a', '/first'), array('/first', $target), array('/b', '/final')));
    $result = $inspector->inspect('/a');
    check($result['status'] === 'routing_uncertain' && $result['hops'] === 2 && count($db->queries) === 2 &&
        $result['final_url'] === $target && !$result['suggestion'], 'Repeated board prefixes and zero queries stop a chain before an uncertain next identity');
    check(strpos(VonSEO_AdminRedirects::render($result), 'Legacy redirect normalization') !== false,
        'The uncertainty message explains the legacy frontend boundary');
}
foreach(array('0=1', 'x=0', '00', '%30') as $query)
{
    rules(array(array('/a?'.$query, '/final')));
    check($inspector->inspect('/a?'.$query)['hops'] === 1 &&
        $inspector->inspect('https://example.com/forum/a?'.$query)['hops'] === 1, 'Nonzero raw query identities still match exactly');
}
rules(array(array('/forumish/b', '/final')));
check($inspector->inspect('https://example.com/forum/forumish/b')['hops'] === 1, 'A prefix-like segment is not mistaken for a repeated board path');

foreach(array('', array('a'), str_repeat('x', 2049), '//outside.example/a', 'https://outside.example/a',
    'https://example.com/outside', 'https://user:pass@example.com/forum/a', 'ftp://example.com/forum/a',
    "/a\0b", "/a\nb", '/a\\b', '/a b', '/a/../b', '/a%2fb') as $source)
{
    rules(array());
    check($inspector->inspect($source)['status'] === 'invalid_source' && !$db->queries && !$db->tableChecks, 'Invalid source input is rejected before any DB lookup');
}
rules(array());
check($inspector->inspect('/a', 'GET;DROP TABLE settings')['status'] === 'invalid_method' && !$db->queries, 'Unrecognized request methods cannot enter the query path');
$db->exists = false;
check($inspector->inspect('/a')['status'] === 'missing_table' && !$db->queries, 'A missing schema is reported without an SQL error');
rules(array(array('/a', '/b', 999)));
check($inspector->inspect('/a')['status'] === 'invalid_rule', 'Unsupported saved response codes are diagnosed');
$db->rules[hash('sha256', '/a')]['source_path'] = '/different';
check($inspector->inspect('/a')['status'] === 'invalid_rule', 'Corrupt stored source identities are not trusted');

$longRules = array();
for($i = 0; $i < 20; ++$i) { $longRules[] = array('/p'.$i, '/p'.($i + 1)); }
rules($longRules);
$result = $inspector->inspect('/p0');
check($result['status'] === 'limit' && $result['hops'] === 12 && count($db->queries) === 12 && $db->tableChecks === 1 && !$result['suggestion'], 'A long non-cyclic chain stops at twelve lookups without a false loop claim');
$longRules[11][1] = '/p0';
rules($longRules);
check($inspector->inspect('/p0')['status'] === 'loop' && count($db->queries) === 12, 'A cycle closing exactly at the lookup cap is detected');

$mybb->settings['bburl'] = 'https://example.com';
$rootInspector = new VonSEO_AdminRedirects();
rules(array(array('/a', '/b')));
check($rootInspector->inspect('https://example.com/a')['final_url'] === 'https://example.com/b', 'Root deployments do not add a phantom forum subfolder');
rules(array(array('/forum/forum/b', '/final')));
check($rootInspector->inspect('https://example.com/forum/forum/b')['hops'] === 1, 'Root installs do not treat ordinary repeated segments as a board-prefix ambiguity');
rules(array(array('/a', '/final')));
check($rootInspector->inspect('/a?0')['status'] === 'routing_uncertain', 'The legacy zero-query exception also applies to root installs');
$mybb->settings['bburl'] = 'https://example.com/community/forum';
$nestedInspector = new VonSEO_AdminRedirects();
rules(array(array('/b', '/final')));
check($nestedInspector->inspect('https://example.com/community/forum/community/forum/b')['status'] === 'routing_uncertain' &&
    !$db->queries, 'Nested board base paths are checked as whole prefixes, not individual segments');
$mybb->settings['bburl'] = 'https://example.com/forum';
rules(array(array('/a', '/b')));
$mybb->settings['vonseo_redirects_enabled'] = '0';
$result = $inspector->inspect('/a');
check(!$result['engine_enabled'] && $result['hops'] === 1 && strpos(VonSEO_AdminRedirects::render($result), 'redirect engine is disabled') !== false, 'Disabled engines still permit clearly labelled saved-configuration inspection');
$mybb->settings['vonseo_redirects_enabled'] = '1';
$mybb->settings['vonseo_enabled'] = '0';
check(!$inspector->inspect('/a')['engine_enabled'], 'The main SEO-engine toggle is respected in the result label');
$mybb->settings['vonseo_enabled'] = '1';

rules(array(array('/<script>alert(1)</script>', '/<svg/onload=alert(1)>')));
$result = $inspector->inspect('/<script>alert(1)</script>');
$html = VonSEO_AdminRedirects::render($result);
check(strpos($html, '<script>') === false && strpos($html, '<svg/') === false && strpos($html, '&lt;script&gt;') !== false && strpos($html, '&lt;svg/') !== false, 'Source and target markup render as escaped text, never executable HTML');
check(substr_count($html, 'href=') === count($result['steps']) && strpos($html, 'href="https:') === false && strpos($html, 'href="javascript:') === false, 'Only fixed local rule-review links are clickable');
$result['steps'][0]['target'] = "bad\xFFtext";
check(preg_match('//u', VonSEO_AdminRedirects::render($result)) === 1, 'Malformed UTF-8 stored text cannot break report output');
echo 'ACP redirect inspector: '.$passed.' passed, '.$failed.' failed'.PHP_EOL;
exit($failed ? 1 : 0);
