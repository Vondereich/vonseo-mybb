<?php
/** Bounded, escaped, one-use ACP import feedback without a live MyBB database. */
define('IN_MYBB', 1);
define('TIME_NOW', 1774267200);
require_once dirname(__DIR__).'/inc/plugins/vonseo/AdminImport.php';

function my_number_format($value)
{
    return number_format($value);
}
function update_admin_session($key, $value)
{
    global $admin_session, $sessionWrites;
    $admin_session['data'][$key] = $value;
    $sessionWrites[] = array($key, $value);
}
$passed = 0;
$failed = 0;
function check($condition, $message)
{
    global $passed, $failed;
    if($condition) { ++$passed; } else { ++$failed; echo '[FAIL] '.$message.PHP_EOL; }
}
$admin_session = array('data' => array('unrelated' => 'preserved'));
$sessionWrites = array();
check(VonSEO_AdminImport::take() === array() && !$sessionWrites, 'No report means no session write');
check(VonSEO_AdminImport::render(array()) === '', 'No report means no output');
$result = array('imported' => 2, 'updated' => 1, 'skipped' => 3, 'errors' => array(
    "Row 4: invalid source URL '<script>alert(1)</script>'",
    "Row 6 ('/old'): invalid destination & unsafe scheme",
    'CSV exceeds the 2 MiB import limit.'
));
$report = VonSEO_AdminImport::prepare($result);
check($report['imported'] === 2 && $report['updated'] === 1 && $report['skipped'] === 3 && $report['warning_count'] === 3, 'Original import counts are preserved');
check($report['created_at'] === TIME_NOW && $report['warnings'][0]['row'] === 4, 'A report carries its creation time and parsed CSV row');
check($report['warnings'][1]['row'] === 6 && strpos($report['warnings'][1]['message'], "('/old'):") === 0, 'Validation warnings retain the source context');
check($report['warnings'][2]['row'] === 0, 'Whole-file failures do not invent a row number');
$html = VonSEO_AdminImport::render($report);
check(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false && strpos($html, '&amp; unsafe') !== false, 'All warning text is HTML escaped');
check(strpos($html, 'scope="col">CSV row') !== false && strpos($html, 'scope="row">4') !== false && strpos($html, 'File / format') !== false, 'Warnings have an accessible row-by-row table');
check(strpos($html, '2 created, 1 updated, 3 skipped.') !== false && strpos($html, 'valid rules may already have been saved') !== false, 'Counts and partial-import guidance are explicit');
check(strpos($html, 'Showing 3 of 3') !== false && strpos($html, 'quoted multiline record counts as one row') !== false, 'Warning totals and parsed-record numbering are explained');

$large = array('errors' => array_fill(0, 61, 'Row 2: '.str_repeat('x', 2000)));
$bounded = VonSEO_AdminImport::prepare($large);
check($bounded['warning_count'] === 61 && count($bounded['warnings']) === 50, 'Only the first 50 warnings are retained, with the full warning count');
check(strlen($bounded['warnings'][0]['message']) === 503 && substr($bounded['warnings'][0]['message'], -3) === '...', 'Long messages have an explicit byte-bound and truncation marker');
check(strlen(serialize($bounded)) < 40000, 'The session payload is bounded even for oversized warning text');
check(strpos(VonSEO_AdminImport::render($bounded), 'Showing 50 of 61') !== false, 'The report does not conceal omitted warnings');
$unicode = VonSEO_AdminImport::prepare(array('errors' => array('Row 2: café 社区 😀', 'Row 3: '.str_repeat('é', 249).'€')));
$unicodeHtml = VonSEO_AdminImport::render($unicode);
check(strpos($unicodeHtml, 'café 社区 😀') !== false && preg_match('//u', $unicodeHtml) === 1, 'Unicode is preserved and a truncated UTF-8 tail is substituted safely');
$malformed = VonSEO_AdminImport::prepare(array('errors' => array("Row 2: bad\xFFinput", array('unexpected'))));
check(preg_match('//u', VonSEO_AdminImport::render($malformed)) === 1 && $malformed['warnings'][1]['message'] === 'Invalid import warning.', 'Malformed text and non-scalar warning input cannot break HTML output');
$success = VonSEO_AdminImport::prepare(array('imported' => 3, 'errors' => array()));
check(strpos(VonSEO_AdminImport::render($success), '<table>') === false && strpos(VonSEO_AdminImport::render($success), '3 created') !== false, 'A clean import shows a compact result without an empty warning table');
$normal = VonSEO_AdminImport::prepare(array('imported' => -1, 'updated' => array('bad'), 'skipped' => '2'));
check($normal['imported'] === 0 && $normal['updated'] === 0 && $normal['skipped'] === 2, 'Malformed or negative counters are normalized');

VonSEO_AdminImport::store($result);
check(count($sessionWrites) === 1 && $sessionWrites[0][0] === VonSEO_AdminImport::SESSION_KEY && $admin_session['data']['unrelated'] === 'preserved', 'Storage uses only the current admin session and preserves other data');
check(VonSEO_AdminImport::take() === $report && $admin_session['data'][VonSEO_AdminImport::SESSION_KEY] === null, 'The next read returns and clears the report');
$before = count($sessionWrites);
check(VonSEO_AdminImport::take() === array() && count($sessionWrites) === $before, 'A consumed report cannot replay or cause another write');
$admin_session['data'][VonSEO_AdminImport::SESSION_KEY] = $report;
$otherSession = $admin_session;
$admin_session = array('data' => array());
check(VonSEO_AdminImport::take() === array(), 'A different administrator session cannot see the report');
$admin_session = $otherSession;
check(VonSEO_AdminImport::take() === $report, 'The original administrator session retains its own result');

foreach(array('boundary' => TIME_NOW - 900, 'expired' => TIME_NOW - 901, 'future' => TIME_NOW + 1) as $case => $timestamp)
{
    $candidate = $report;
    $candidate['created_at'] = $timestamp;
    $admin_session['data'][VonSEO_AdminImport::SESSION_KEY] = $candidate;
    $taken = VonSEO_AdminImport::take();
    check(($case === 'boundary' ? $taken === $candidate : $taken === array()) && $admin_session['data'][VonSEO_AdminImport::SESSION_KEY] === null, 'Timestamp handling: '.$case);
}
foreach(array('scalar', array('created_at' => TIME_NOW), array_merge($report, array('warnings' => 'bad')),
    array_merge($report, array('warnings' => array_fill(0, 51, array('row' => 2, 'message' => 'bad')))),
    array_merge($report, array('warnings' => array(array('row' => array(2), 'message' => 'bad')), 'warning_count' => 1))) as $candidate)
{
    $admin_session['data'][VonSEO_AdminImport::SESSION_KEY] = $candidate;
    check(VonSEO_AdminImport::take() === array(), 'Malformed persisted report is discarded');
}
echo 'ACP import reports: '.$passed.' passed, '.$failed.' failed'.PHP_EOL;
exit($failed ? 1 : 0);
