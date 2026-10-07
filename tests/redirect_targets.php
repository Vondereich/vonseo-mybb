<?php
/** Redirect target policy through the real normalizer, saver and CSV importers. */
if(PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IN_MYBB', 1);
define('TIME_NOW', 1774267200);
require_once dirname(__DIR__).'/inc/plugins/vonseo/Utils.php';
require_once dirname(__DIR__).'/inc/plugins/vonseo/Url.php';
require_once dirname(__DIR__).'/inc/plugins/vonseo/Redirects.php';

/** In-memory persistence only; saveRule() and importCsv() are not overridden. */
class TargetTestDb
{
    /** @var array<int,array> */
    public $rows = array();
    /** @var int */
    public $writes = 0;
    /** @var int */
    private $nextId = 1;

    public function table_exists(string $table): bool { return $table === 'vonseo_redirects'; }
    public function escape_string(string $value): string { return addslashes($value); }
    public function simple_select(string $table, string $fields, string $where = '', array $options = array()): array
    {
        if($table !== 'vonseo_redirects' || $options !== array('limit' => 1))
        {
            throw new RuntimeException('Unexpected target-test query');
        }
        foreach($this->rows as $row)
        {
            if(preg_match("/source_hash='([a-f0-9]{64})'/", $where, $match) && $row['source_hash'] !== $match[1]) { continue; }
            if(preg_match("/rid='(\d+)'/", $where, $match) && $row['rid'] !== (int)$match[1]) { continue; }
            if(preg_match("/rid!='(\d+)'/", $where, $match) && $row['rid'] === (int)$match[1]) { continue; }
            if(strpos($where, "enabled='1'") !== false && !$row['enabled']) { continue; }
            return $row;
        }
        return array();
    }
    /** @return array|false */
    public function fetch_array(array $row) { return $row ?: false; }
    /** @return mixed */
    public function fetch_field(array $row, string $field) { return isset($row[$field]) ? $row[$field] : false; }
    public function insert_query(string $table, array $data): int
    {
        $rid = $this->nextId++;
        $this->store($table, $rid, $data);
        return $rid;
    }
    public function update_query(string $table, array $data, string $where): void
    {
        if(!preg_match("/^rid='(\d+)'$/", $where, $match)) { throw new RuntimeException('Unexpected update'); }
        $this->store($table, (int)$match[1], $data);
    }
    private function store(string $table, int $rid, array $data): void
    {
        if($table !== 'vonseo_redirects') { throw new RuntimeException('Unexpected write table'); }
        foreach($data as &$value) { if(is_string($value)) { $value = stripslashes($value); } }
        unset($value);
        $this->rows[$rid] = array_merge(isset($this->rows[$rid]) ? $this->rows[$rid] : array(), $data, array('rid' => $rid));
        ++$this->writes;
    }
}

$passed = 0;
$failed = 0;
function checkTarget(bool $condition, string $message): void
{
    global $passed, $failed;
    if($condition) { ++$passed; } else { ++$failed; echo '[FAIL] '.$message.PHP_EOL; }
}
$mybb = new stdClass();
$mybb->settings = array('vonseo_external_redirects' => '0', 'vonseo_redirects_enabled' => '1');
foreach(array('https://example.com', 'https://example.com/forum', 'https://example.com/community/forum') as $base)
{
    $db = new TargetTestDb();
    $redirects = new VonSEO_Redirects(new VonSEO_Url($base));
    $invalid = array(
        '/https://outside.example.invalid/collect',
        '/HTTP://outside.example.invalid/collect',
        'https://outside.example.invalid/collect',
        '/http://example.com/target',
        '/https://example.com:444/target',
        'https://user:password@example.com/target',
        '/https://user:password@example.com/target',
        '//outside.example.invalid/collect',
        'javascript:alert(1)',
        "/target\r\nLocation: https://outside.example.invalid/",
        '/target&#13;&#10;Location: https://outside.example.invalid/',
        '/target&amp;#13;&amp;#10;Location: https://outside.example.invalid/',
        '/&amp;#104;ttps://outside.example.invalid/collect',
        '',
        ' '
    );
    foreach($invalid as $i => $target)
    {
        checkTarget($redirects->normalizeTarget($target) === false, $base.' rejects target #'.$i);
        $before = $db->rows;
        $writes = $db->writes;
        $saved = $redirects->saveRule('/invalid-'.$i, $target, 301);
        checkTarget(!empty($saved['errors']) && $db->writes === $writes && $db->rows === $before, $base.' rejects manual create #'.$i.' before persistence');
    }

    $valid = array(
        '/' => $base.'/',
        '/target' => $base.'/target',
        'target' => $base.'/target',
        '/showthread.php?tid=1&amp;page=2#pid3' => $base.'/showthread.php?tid=1&page=2#pid3',
        $base.'/target?0#fragment' => $base.'/target?0#fragment',
        '/https://example.com/target' => 'https://example.com/target',
        '/https://EXAMPLE.com/next//page/' => 'https://example.com/next/page',
        '/folder/https://example.com/target' => $base.'/folder/https:/example.com/target',
        '/tag:php' => $base.'/tag:php'
    );
    foreach($valid as $target => $expected)
    {
        $normalized = $redirects->normalizeTarget($target);
        checkTarget($normalized === $expected && $redirects->normalizeTarget($normalized) === $expected,
            $base.' preserves allowed targets and agrees with stored-target revalidation: '.$target);
    }
    $saved = $redirects->saveRule('/manual-good', '/target', 302);
    $rid = $saved['rid'];
    checkTarget(empty($saved['errors']) && $db->rows[$rid]['target_url'] === $base.'/target', $base.' manual save persists the validated absolute destination');
    $before = $db->rows;
    $writes = $db->writes;
    $edited = $redirects->saveRule('/manual-good', $invalid[0], 302, 1, $rid);
    checkTarget(!empty($edited['errors']) && $db->rows === $before && $db->writes === $writes, $base.' invalid edit preserves the existing rule');
    $csv = "source_path,target_url,status_code,enabled\n/manual-good,".$invalid[0].",302,1\n/csv-invalid,".$invalid[0].",301,1\n/csv-good,/csv-target,301,1\n";
    $imported = $redirects->importCsv($csv, true);
    checkTarget($imported['imported'] === 1 && $imported['updated'] === 0 && $imported['skipped'] === 2 && count($imported['errors']) === 2,
        $base.' CSV rejects invalid create/overwrite rows and continues with a valid row');
    checkTarget($db->rows[$rid] === $before[$rid] && $db->writes === $writes + 1, $base.' CSV overwrite cannot replace a valid rule with a disallowed target');

    $file = tmpfile();
    try
    {
        fwrite($file, "source_path,target_url,status_code,enabled\n/file-invalid,".$invalid[0].",301,1\n/file-good,/file-target,301,1\n");
        fflush($file);
        $meta = stream_get_meta_data($file);
        $imported = $redirects->importCsvFile($meta['uri']);
        checkTarget($imported['imported'] === 1 && $imported['skipped'] === 1 && count($imported['errors']) === 1 && $db->writes === $writes + 2,
            $base.' uploaded-file importer uses the same target policy');
    }
    finally { fclose($file); }

    // An already stored absolute external URL must still be blocked at runtime.
    $db->rows[$rid]['target_url'] = 'https://outside.example.invalid/collect';
    $before = $db->rows;
    $writes = $db->writes;
    $path = parse_url($base, PHP_URL_PATH);
    $_SERVER['REQUEST_URI'] = ($path ?: '').'/manual-good';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    checkTarget($redirects->handleRequest() === false && $db->rows === $before && $db->writes === $writes, $base.' retains the runtime guard without recording a hit');

    $mybb->settings['vonseo_external_redirects'] = '1';
    $external = 'https://outside.example.invalid/collect';
    foreach(array($external, '/'.$external) as $i => $target)
    {
        $saved = $redirects->saveRule('/external-'.$i, $target, 307);
        checkTarget(empty($saved['errors']) && $db->rows[$saved['rid']]['target_url'] === $external && $redirects->normalizeTarget($saved['target']) === $external,
            $base.' explicit external opt-in keeps save and runtime validation consistent #'.$i);
    }
    checkTarget($redirects->normalizeTarget($invalid[6]) === false && $redirects->normalizeTarget($invalid[11]) === false,
        $base.' external opt-in does not permit credentials or normalized CRLF');
    $mybb->settings['vonseo_external_redirects'] = '0';
}
echo 'Redirect target policy: '.$passed.' passed, '.$failed.' failed'.PHP_EOL;
exit($failed ? 1 : 0);
