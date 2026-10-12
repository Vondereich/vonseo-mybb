<?php
/** Isolated 1.1.0 regressions. No installed board, network or persistent database. */
if(PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IN_MYBB', 1);
define('IN_ADMINCP', 1);
define('MYBB_ROOT', dirname(__DIR__).'/');
define('TABLE_PREFIX', 'mybb_');
define('TIME_NOW', 1774267200);

class SEOQuery
{
    public $rows;
    public $position = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
}
class SEOCache
{
    public $data = array();
    public $forums = array();
    public function read($key) { return $key === 'forums' ? $this->forums : (isset($this->data[$key]) ? $this->data[$key] : false); }
    public function update($key, $value) { $this->data[$key] = $value; }
    public function delete($key) { unset($this->data[$key]); }
}
class MyBB
{
    const INPUT_INT = 1;
    public $request_method = 'get';
    public $post_code = 'test-only-token';
    public $user = array('uid' => 1);
    public $input = array();
    public $settings = array('bburl' => 'https://example.com/forum', 'bbname' => 'Sample Forum',
        'vonseo_enabled' => 1, 'vonseo_indexnow_enabled' => 0, 'vonseo_keyword_urls' => 0,
        'vonseo_site_description' => 'Community fallback description.', 'vonseo_default_image' => 'images/social.png',
        'vonseo_thread_attachment_image' => 0, 'vonseo_sitemap_chunk' => 1000, 'postsperpage' => 20, 'threadsperpage' => 20);
    public function get_input($key, $type = 0) {
        $value = isset($this->input[$key]) ? $this->input[$key] : '';
        return $type === self::INPUT_INT ? (int)$value : (is_string($value) ? $value : '');
    }
}
class SEODb
{
    public $overrides = array();
    public $threads = array();
    public $posts = array();
    public $history = array();
    public $queue = array();
    public $selected = array();
    public $replaced = array();
    public $writes = array();
    public $updates = array();
    public $affected = 0;
    public $storage = true;
    public function table_exists($table) { return $table === 'vonseo_overrides' ? $this->storage : in_array($table, array('vonseo_indexnow_queue', 'vonseo_indexnow_threads'), true); }
    public function escape_string($value) { return addslashes($value); }
    public function simple_select($table, $fields, $where = '', $options = array()) {
        $this->selected[] = array($table, $fields, $where, $options);
        $rows = array();
        if($table === 'vonseo_overrides') {
            foreach($this->overrides as $row) {
                if(preg_match("/entity_type='(forum|thread)'/", $where, $m) && $row['entity_type'] !== $m[1]) { continue; }
                if(preg_match("/entity_id='(\d+)'/", $where, $m) && (int)$row['entity_id'] !== (int)$m[1]) { continue; }
                if(strpos($where, "index_mode='noindex'") !== false && $row['index_mode'] !== 'noindex') { continue; }
                if(strpos($where, 'pending_mode IN') !== false && !in_array(isset($row['pending_mode']) ? $row['pending_mode'] : '', array('current','removed'), true)) { continue; }
                if(preg_match('/entity_id IN \(([^)]+)\)/', $where, $m) && !in_array((int)$row['entity_id'], array_map('intval', explode(',', $m[1])), true)) { continue; }
                $rows[] = $row;
            }
        } elseif($table === 'threads') {
            foreach($this->threads as $row) {
                if(preg_match("/tid='(\d+)'/", $where, $m) && (int)$row['tid'] !== (int)$m[1]) { continue; }
                if(preg_match("/fid='(\d+)'/", $where, $m) && (int)$row['fid'] !== (int)$m[1]) { continue; }
                if(preg_match("/tid>'(\d+)'/", $where, $m) && (int)$row['tid'] <= (int)$m[1]) { continue; }
                if(strpos($where, "visible='1'") !== false && (int)$row['visible'] !== 1) { continue; }
                if(preg_match('/visible IN \(([^)]+)\)/', $where, $m) && !in_array((int)$row['visible'], array_map('intval', explode(',', $m[1])), true)) { continue; }
                if(preg_match("/lastpost >= '(-?\d+)'/", $where, $m) && (int)$row['lastpost'] < (int)$m[1] && empty($row['sticky'])) { continue; }
                if(strpos($where, 'NOT EXISTS') !== false && ($this->isNoindex('thread', $row['tid']) || $this->isNoindex('forum', $row['fid']))) { continue; }
                if(preg_match('/fid IN \(([^)]+)\)/', $where, $m) && !in_array((int)$row['fid'], array_map('intval', explode(',', $m[1])), true)) { continue; }
                if(strpos($where, 'closed NOT LIKE') !== false && strpos($row['closed'], 'moved|') === 0) { continue; }
                $rows[] = $row;
            }
            usort($rows, function($a, $b) { return $a['tid'] <=> $b['tid']; });
            if(strpos($fields, 'COUNT(') !== false) { return new SEOQuery(array(array('total' => count($rows)))); }
        } elseif($table === 'forums') {
            if(preg_match("/fid='(\d+)'/", $where, $m) && isset($GLOBALS['cache']->forums[$m[1]])) { $rows[] = $GLOBALS['cache']->forums[$m[1]]; }
        } elseif($table === 'posts') {
            foreach($this->posts as $row) {
                if(preg_match("/tid='(\d+)'/", $where, $m) && (int)$row['tid'] !== (int)$m[1]) { continue; }
                if(preg_match('/visible IN \(([^)]+)\)/', $where, $m) && !in_array((int)$row['visible'], array_map('intval', explode(',', $m[1])), true)) { continue; }
                $rows[] = $row;
            }
            if(strpos($fields, 'COUNT(') !== false) { return new SEOQuery(array(array('total' => count($rows)))); }
        } elseif($table === 'vonseo_indexnow_threads') {
            if(preg_match("/tid='(\d+)'/", $where, $m) && isset($this->history[$m[1]])) { $rows[] = $this->history[$m[1]]; }
        } elseif($table === 'vonseo_indexnow_queue') {
            $rows = array_values($this->queue);
            if(strpos($fields, 'COUNT(') !== false) { $rows = array(array('total' => count($rows))); }
        }
        return new SEOQuery(array_slice($rows, isset($options['limit_start']) ? $options['limit_start'] : 0, isset($options['limit']) ? $options['limit'] : null));
    }
    public function fetch_array($query) { return isset($query->rows[$query->position]) ? $query->rows[$query->position++] : false; }
    public function fetch_field($query, $field) { $row = $this->fetch_array($query); return isset($row[$field]) ? $row[$field] : null; }
    public function isNoindex($type, $id) { return isset($this->overrides[$type.':'.$id]['index_mode']) && $this->overrides[$type.':'.$id]['index_mode'] === 'noindex'; }
    public function replace_query($table, $data) {
        $this->replaced[] = array($table, $data);
        $raw = array_map(function($value) { return is_string($value) ? stripslashes($value) : $value; }, $data);
        if($table === 'vonseo_overrides') { $this->overrides[$raw['entity_type'].':'.$raw['entity_id']] = $raw; }
        if($table === 'vonseo_indexnow_threads') { $this->history[$raw['tid']] = $raw; }
    }
    public function write_query($sql) {
        $this->writes[] = $sql;
        if(strpos($sql, 'INSERT INTO mybb_vonseo_indexnow_threads') === 0 &&
            preg_match("/VALUES \((\d+),'((?:\\\\.|[^'])*)',(\d+),(\d+)\)/s", $sql, $m)) {
            $tid = (int)$m[1]; $old = isset($this->history[$tid]) ? $this->history[$tid] : array();
            $this->history[$tid] = array('tid' => $tid,
                'last_public_url' => !$old || strpos($sql, 'UPDATE last_public_url=') !== false ? stripslashes($m[2]) : $old['last_public_url'],
                'last_submitted_at' => max(isset($old['last_submitted_at']) ? (int)$old['last_submitted_at'] : 0, (int)$m[3]),
                'updated_at' => max(isset($old['updated_at']) ? (int)$old['updated_at'] : 0, (int)$m[4]));
        }
    }
    public function update_query($table, $data, $where) {
        $this->updates[] = array($table, $data, $where);
        if($table === 'vonseo_overrides' && preg_match("/entity_id='(\d+)'/", $where, $m)) {
            $key = 'forum:'.$m[1];
            if(isset($this->overrides[$key]) && preg_match("/job_generation='([^']+)'/", $where, $g) && $this->overrides[$key]['job_generation'] === $g[1]) {
                $this->overrides[$key] = $data + $this->overrides[$key];
            }
        }
    }
    public function delete_query($table, $where) {
        $this->affected = 0;
        if($table === 'vonseo_indexnow_queue' && preg_match("/qid='(\d+)' AND generation='([^']+)'/", $where, $m)) {
            foreach($this->queue as $key => $row) { if($row['qid'] == $m[1] && $row['generation'] === $m[2]) { unset($this->queue[$key]); ++$this->affected; } }
        }
        if($table === 'vonseo_indexnow_threads' && preg_match("/tid='(\d+)'/", $where, $m)) { unset($this->history[$m[1]]); }
    }
    public function affected_rows() { return $this->affected; }
}
class SEOFinished extends Exception {}
class SEORedirect extends Exception {}
class SEODenied extends Exception {}
class SEOCSRF extends Exception {}
class Page
{
    public function add_breadcrumb_item($title, $url) {}
    public function output_header($title) { echo '<h1>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h1>'; }
    public function output_nav_tabs($tabs, $active) { echo 'SEO navigation'; }
    public function output_footer() { throw new SEOFinished(); }
}
function get_thread($id) { return isset($GLOBALS['db']->threads[$id]) ? $GLOBALS['db']->threads[$id] : false; }
function get_forum($id) { return isset($GLOBALS['cache']->forums[$id]) ? $GLOBALS['cache']->forums[$id] : false; }
function get_thread_link($tid, $page = 0, $action = '') { return 'showthread.php?tid='.(int)$tid.($page > 1 ? '&amp;page='.(int)$page : ''); }
function get_forum_link($fid, $page = 0) { return 'forumdisplay.php?fid='.(int)$fid.($page > 1 ? '&amp;page='.(int)$page : ''); }
function get_inactive_forums() { return ''; }
function forum_permissions($fid, $uid = 0, $gid = 1) { return array('canview' => $fid !== 4, 'canviewthreads' => 1, 'canonlyviewownthreads' => 0, 'candlattachments' => 0, 'canviewdeletionnotice' => !empty($GLOBALS['seoGuestNotices'])); }
function check_admin_permissions($action, $error = true) {
    $allowed = $action['action'] !== 'vonseo_overrides' || empty($GLOBALS['seoDenied']);
    if(!$allowed && $error) { throw new SEODenied(); }
    return $allowed;
}
function verify_post_check($token) { if($token !== 'test-only-token') { throw new SEOCSRF(); } }
function admin_redirect($url) { throw new SEORedirect($url); }
function flash_message($message, $type) { $GLOBALS['seoFlash'] = $message; }
function log_admin_action(...$args) { $GLOBALS['seoLogs'][] = $args; }
function htmlspecialchars_uni($value) { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function rebuild_settings() {}
$plugins = null;
require_once MYBB_ROOT.'inc/plugins/vonseo.php';
vonseo_require_core();
require_once MYBB_ROOT.'inc/plugins/vonseo/AdminSEO.php';

function seoFixture()
{
    $GLOBALS['mybb'] = new MyBB();
    $GLOBALS['db'] = new SEODb();
    $GLOBALS['cache'] = new SEOCache();
    $GLOBALS['cache']->data['usergroups'] = array(1 => array('canview' => 1, 'canviewcalendar' => 1));
    $GLOBALS['page'] = new Page();
    $GLOBALS['seoLogs'] = array();
    $GLOBALS['seoDenied'] = false;
    $GLOBALS['seoGuestNotices'] = false;
    foreach(array(1,2,3,4,5,6) as $fid) {
        $GLOBALS['cache']->forums[$fid] = array('fid' => $fid, 'name' => 'Forum '.$fid, 'description' => 'Forum description '.$fid,
            'type' => $fid === 1 ? 'c' : 'f', 'active' => $fid === 6 ? 0 : 1, 'linkto' => '',
            'password' => $fid === 5 ? 'protected' : '', 'parentlist' => $fid === 1 ? '1' : ($fid === 3 ? '1,2,3' : '1,'.$fid),
            'threads'=> $fid === 2 ? 45 : 0, 'deletedthreads'=>0, 'defaultdatecut'=>9999);
    }
    foreach(array(16=>2,17=>2,18=>4,19=>5,20=>2,21=>3,22=>6,23=>2) as $tid => $fid) {
        $GLOBALS['db']->threads[$tid] = array('tid'=>$tid,'fid'=>$fid,'subject'=>'Original subject '.$tid,'visible'=>$tid === 17 ? 0 : ($tid === 23 ? -1 : 1),'closed'=>$tid === 20 ? 'moved|2' : '', 'firstpost'=>100+$tid,'dateline'=>TIME_NOW-100,'lastpost'=>TIME_NOW-50,'uid'=>1,'replies'=>$tid === 16 ? 44 : 0,'sticky'=>0);
    }
    $_SERVER['SCRIPT_NAME'] = '/forum/showthread.php';
    VonSEO_Overrides::resetRuntime();
    VonSEO_State::resetRuntime();
}
seoFixture();
$passed = 0;
$failed = 0;
function seoCheck($condition, $label) {
    if($condition) { ++$GLOBALS['passed']; echo '[PASS] '.$label."\n"; }
    else { ++$GLOBALS['failed']; echo '[FAIL] '.$label."\n"; }
}
set_error_handler(function($severity, $message, $file, $line) { if(error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); } return false; });

if(isset($argv[1])) {
    $action = $argv[1]; $mode = isset($argv[2]) ? $argv[2] : 'get';
    if(!in_array($action, array('seo','seo_save','seo_reset','seo_preview'), true) || !in_array($mode, array('get','head','post','csrf','denied','invalid','array','private','out_of_range','engine_off'), true)) { exit(2); }
    $mybb->request_method = $mode === 'head' ? 'head' : (in_array($mode, array('get','out_of_range','engine_off'), true) ? 'get' : 'post');
    $mybb->input = array('action'=>$action,'entity_type'=>'thread','entity_id'=>$mode === 'private' ? '18' : '16','seo_page'=>'1',
        'my_post_key'=>$mode === 'csrf' ? 'wrong-token' : 'test-only-token','seo_title'=>"O'Brien <script>literal</script>",
        'seo_description'=>'Custom community description.','seo_image'=>'images/custom.png','index_mode'=>'inherit');
    if($mode === 'invalid') {
        $mybb->input['seo_image'] = 'javascript:alert(1)';
        $db->overrides['thread:16'] = array('entity_type'=>'thread','entity_id'=>16,'index_mode'=>'noindex','seo_title'=>'Saved title');
    }
    if($mode === 'array') { $mybb->input['seo_title'] = array('malformed'); }
    if($mode === 'out_of_range') { $mybb->input['seo_page'] = '99'; }
    if($mode === 'engine_off') { $mybb->settings['vonseo_enabled'] = 0; }
    $GLOBALS['seoDenied'] = $mode === 'denied';
    $outcome = '';
    ob_start();
    try { require MYBB_ROOT.'admin/modules/config/vonseo.php'; }
    catch(SEOFinished $e) { $outcome = 'rendered'; }
    catch(SEORedirect $e) { $outcome = 'redirect'; }
    catch(SEODenied $e) { $outcome = 'denied'; }
    catch(SEOCSRF $e) { $outcome = 'csrf'; }
    $output = ob_get_clean();
    $writes = count($db->replaced)+count($db->writes)+count($db->updates);
    $expected = $mode === 'denied' ? 'denied' : ($mode === 'csrf' && $action !== 'seo' ? 'csrf' :
        (in_array($mode, array('get','head'), true) && $action !== 'seo' ? 'redirect' :
        (in_array($action,array('seo_save','seo_reset'),true) && $mode === 'post' ? 'redirect' : 'rendered')));
    $expectWrite = in_array($action,array('seo_save','seo_reset'),true) && $mode === 'post';
    seoCheck($outcome === $expected && ($expectWrite ? $writes === 1 : $writes === 0), $action.' '.$mode.' method/token/permission and write boundary');
    if($expectWrite) { seoCheck(count($GLOBALS['seoLogs']) === 1, 'Mutation logs an admin action'); }
    if($mode === 'private') { seoCheck(strpos($output,'Original subject 18') === false && strpos($output, 'seo_title') === false, 'Private entity title/editor not exposed'); }
    if($mode === 'array' || $mode === 'invalid') { seoCheck(strpos($output, 'Nothing was saved') !== false, 'Invalid input produces escaped feedback'); }
    if($mode === 'invalid') { seoCheck(strpos($output,'Noindex is effective') !== false && strpos($output,'Unsaved draft') === false && strpos($output,'Saved title') !== false, 'Invalid draft retains saved noindex/title and accurate snapshot label'); }
    if($mode === 'out_of_range') { seoCheck(strpos($output,'snapshot uses page 1') !== false && strpos($output,'name="seo_page" value="1"') !== false && strpos($output,'page=99') === false, 'ACP controls and candidates use normalized page, not requested alias'); }
    if($mode === 'engine_off') { seoCheck(strpos($output,'SEO engine is off') !== false && strpos($output,'Configured head tags (inactive candidates)') !== false && strpos($output,'Expected VonSEO head tags') === false, 'ACP discloses inactive engine and labels candidate tags'); }
    if($action === 'seo_preview' && $mode === 'post') {
        seoCheck(strpos($output,'Unsaved draft') !== false && strpos($output,'<script>literal</script>') === false && strpos($output,'Expected VonSEO head tags') !== false, 'Draft preview is escaped and read-only');
        seoCheck(strpos($output, 'ACP schema snapshot cannot include DiscussionForumPosting') !== false,
            'Custom description does not conceal the guest-rendered schema snapshot limitation');
    }
    if($expectWrite && $action === 'seo_save') { seoCheck(strpos($db->replaced[0][1]['seo_title'], "O\\'Brien") === 0, 'SEO apostrophe is escaped before DB write'); }
    echo 'SEO ACP: '.$passed.' passed, '.$failed." failed\n";
    exit($failed ? 1 : 0);
}

$baseline = (new VonSEO_Context(new VonSEO_Url()))->resolveEntity('thread',16);
seoCheck($baseline->indexable && $baseline->title === 'Original subject 16 - Sample Forum', 'Blank overrides preserve automatic title');
seoCheck($baseline->description === 'Community fallback description.' && count($baseline->seoWarnings) === 2 &&
    strpos($baseline->seoWarnings[1], 'ACP schema snapshot cannot include DiscussionForumPosting') === 0,
    'ACP separately labels uncaptured description and schema snapshots');
$onePage = (new VonSEO_Context(new VonSEO_Url()))->resolveEntity('thread',21,99);
seoCheck($onePage->page===1 && strpos($onePage->canonical,'page=')===false && strpos($onePage->title,'Page 99')===false, 'One-page thread rejects nonexistent snapshot canonical/title');
$range = (new VonSEO_Context(new VonSEO_Url()))->resolveEntity('thread',16,4);
seoCheck($range->page===1 && strpos(implode(' ', $range->seoWarnings),'outside the guest-visible page range')!==false, 'Out-of-range thread page resets to first, not last page');
$validPage=(new VonSEO_Context(new VonSEO_Url()))->resolveEntity('thread',16,3);
seoCheck($validPage->page===3 && strpos($validPage->canonical,'page=3')!==false, 'Last valid thread page remains supported');
$privateQueries=count($db->selected);
(new VonSEO_Context(new VonSEO_Url()))->resolveEntity('thread',18,99);
seoCheck(count($db->selected)===$privateQueries, 'Private snapshot does not query pagination counters');
$savedReplies=$db->threads[16]['replies']; $db->threads[16]['replies']=19;
$db->posts=array_fill(0,20,array('tid'=>16,'visible'=>1));
$db->posts[]=array('tid'=>16,'visible'=>-1); $db->posts[]=array('tid'=>16,'visible'=>0);
$withoutNotices=(new VonSEO_Context(new VonSEO_Url()))->resolveEntity('thread',16,2);
$GLOBALS['seoGuestNotices']=true;
$withNotices=(new VonSEO_Context(new VonSEO_Url()))->resolveEntity('thread',16,2);
seoCheck($withoutNotices->page===1 && $withNotices->page===2, 'Thread pagination includes guest deletion notices only when permitted');
$db->posts=array_fill(0,20,array('tid'=>16,'visible'=>1)); $db->posts[]=array('tid'=>16,'visible'=>0);
seoCheck((new VonSEO_Context(new VonSEO_Url()))->resolveEntity('thread',16,2)->page===1, 'Admin session does not count unapproved posts for guest snapshot');
$GLOBALS['seoGuestNotices']=false; $db->threads[16]['replies']=$savedReplies;
$cache->forums[2]['threads']=40; $cache->forums[2]['deletedthreads']=1;
$forumWithout=(new VonSEO_Context(new VonSEO_Url()))->resolveEntity('forum',2,3);
$GLOBALS['seoGuestNotices']=true;
$forumWith=(new VonSEO_Context(new VonSEO_Url()))->resolveEntity('forum',2,3);
seoCheck($forumWithout->page===1 && $forumWith->page===3, 'Forum pagination uses fresh counters and guest deletion-notice policy');
$GLOBALS['seoGuestNotices']=false; $cache->forums[2]['defaultdatecut']=3; $mybb->settings['threadsperpage']=1;
$pruned=(new VonSEO_Context(new VonSEO_Url()))->resolveEntity('forum',2,2);
$db->threads[16]['lastpost']=TIME_NOW-86400*10;
seoCheck($pruned->page===2 && (new VonSEO_Context(new VonSEO_Url()))->resolveEntity('forum',2,2)->page===1, 'Forum default date cutoff affects guest page range');
$db->threads[16]['sticky']=1;
seoCheck((new VonSEO_Context(new VonSEO_Url()))->resolveEntity('forum',2,2)->page===2, 'Sticky threads remain in the pruned guest page count');
$db->threads[16]['sticky']=0; $db->threads[16]['lastpost']=TIME_NOW-50;
$cache->forums[2]['defaultdatecut']=9999; $cache->forums[2]['threads']=45; $mybb->settings['threadsperpage']=20;
$mybb->settings['vonseo_enabled']=0;
ob_start(); VonSEO_AdminSEO::preview($baseline); $inactivePreview=ob_get_clean();
seoCheck(strpos($inactivePreview,'Engine output: Off')!==false && strpos($inactivePreview,'Expected VonSEO head tags')===false && strpos($inactivePreview,'inactive candidates')!==false, 'Disabled engine has explicit state and no active-tag claim');
$noindexCandidate=(new VonSEO_Context(new VonSEO_Url()))->resolveEntity('thread',16);
VonSEO_Overrides::apply($noindexCandidate,array('index_mode'=>'noindex'));
ob_start(); VonSEO_AdminSEO::preview($noindexCandidate); $inactiveNoindex=ob_get_clean();
seoCheck(strpos($inactiveNoindex,'Noindex is effective')===false && strpos($inactiveNoindex,'not enforced while the engine is off')!==false, 'Disabled engine does not falsely claim effective noindex');
$mybb->settings['vonseo_enabled']=1;
$save = VonSEO_Overrides::save('thread',16,array('seo_title'=>"O'Brien topic",'seo_description'=>'A custom summary.','seo_image'=>'images/custom.png','index_mode'=>'inherit'),1);
seoCheck(!$save['errors'] && count($db->replaced) === 1, 'Valid override saved in plugin-owned table');
$custom = (new VonSEO_Context(new VonSEO_Url()))->resolveEntity('thread',16,3);
seoCheck($custom->title === "O'Brien topic - Page 3 - Sample Forum" && strpos($custom->canonical,'page=3') !== false, 'Override title retains pagination without changing canonical');
seoCheck($db->threads[16]['subject'] === 'Original subject 16' && $custom->description === 'A custom summary.', 'Native thread subject remains unchanged');
seoCheck($custom->socialImage === 'https://example.com/forum/images/custom.png' && $custom->seoSources['socialImage'] === 'Entity override', 'Public custom image resolves through existing validator');
$queryCount = count($db->selected); VonSEO_Overrides::get('thread',16); VonSEO_Overrides::get('thread',16);
seoCheck(count($db->selected) === $queryCount, 'Entity row lookups are request-cached');
$revision = VonSEO_Overrides::revision();
seoCheck(strlen($revision) === 32, 'Save invalidates cached sitemap counts with a revision');
foreach(array('javascript:alert(1)','data:image/png;base64,AAAA','https://user:password@example.com/a.png',"https://example.com/a\r\nb.png",'https://example.com/a%0Ab.png','https://example.com/a\\b.png') as $badImage) {
    $before = count($db->replaced); $r = VonSEO_Overrides::save('thread',16,array('seo_image'=>$badImage),1);
    seoCheck($r['errors'] && count($db->replaced) === $before && $db->overrides['thread:16']['seo_title'] === "O'Brien topic", 'Unsafe image rejected before writes');
}
foreach(array(array('seo_title'=>array('x')),array('seo_description'=>str_repeat('a',321)),array('seo_title'=>"bad\0input"),array('seo_description'=>"\xff"),array('index_mode'=>'index')) as $input) {
    $before = count($db->replaced); $r = VonSEO_Overrides::save('thread',16,$input,1);
    seoCheck($r['errors'] && count($db->replaced) === $before, 'Invalid boundary/policy rejects without writes');
}
foreach(array(17,18,19,20,22,23,99999) as $tid) {
    $before = count($db->replaced); $r = VonSEO_Overrides::save('thread',$tid,array('seo_title'=>'Never public'),1);
    seoCheck($r['errors'] && count($db->replaced) === $before, 'Private/unapproved/deleted/missing/moved entity cannot receive overrides');
}
VonSEO_Overrides::save('thread',16,array('seo_title'=>'0','seo_description'=>'0'),1);
$zero = (new VonSEO_Context(new VonSEO_Url()))->resolveEntity('thread',16);
seoCheck($zero->title === '0 - Sample Forum' && $zero->description === '0', 'Literal zero is a valid nonempty override');
VonSEO_Overrides::save('forum',2,array('index_mode'=>'noindex'),1);
$blocked = (new VonSEO_Context(new VonSEO_Url()))->resolveEntity('thread',16);
$child = (new VonSEO_Context(new VonSEO_Url()))->resolveEntity('thread',21);
seoCheck(!$blocked->indexable && $blocked->publicContent && $child->indexable, 'Forum policy covers direct threads, not subforums or user access');
$cache->delete('vonseo_override_schema'); VonSEO_Overrides::resetRuntime();
seoCheck(VonSEO_Overrides::noindexThread(16,2), 'Cold readiness cache retains durable noindex policy');
$meta = new VonSEO_Meta(new VonSEO_Robots(new VonSEO_Url()),new VonSEO_Social(),new VonSEO_Schema(new VonSEO_Url()));
$html = $meta->render($blocked);
seoCheck(strpos($html,'noindex') !== false && strpos($html,'canonical') === false && strpos($html,'application/ld+json') === false, 'Noindex output preserves existing robots-only contract');
seoCheck(!(new VonSEO_IndexNow())->capturePublicThreadUrl(16), 'IndexNow excludes public but SEO-noindex threads');
seoCheck(VonSEO_Overrides::indexedForumIds(array(2,3)) === array(3), 'Forum sitemap excludes only explicitly noindex forum IDs');
seoCheck(substr_count(VonSEO_Overrides::threadSitemapPredicate(),'NOT EXISTS') === 2 && strpos(VonSEO_Overrides::threadSitemapPredicate(),'mybb_threads.fid') !== false, 'Thread sitemap uses indexed forum and thread exclusions');
$mybb->input = array('type'=>'threads','page'=>'1');
ob_start(); (new VonSEO_Sitemap(new VonSEO_Url()))->output(); $xml = ob_get_clean();
seoCheck(strpos($xml,'tid=16') === false && strpos($xml,'tid=21') !== false && strpos($xml,'tid=18') === false, 'Mock sitemap excludes policy/private content while retaining public child');
VonSEO_Overrides::save('forum',2,array('index_mode'=>'inherit'),1);
$reset = VonSEO_Overrides::save('thread',16,array(),1);
$restored = (new VonSEO_Context(new VonSEO_Url()))->resolveEntity('thread',16);
seoCheck(!$reset['errors'] && $restored->indexable && $restored->title === $baseline->title && $restored->description === $baseline->description, 'Reset returns automatic output and current guest policy');
foreach(array('https://example.com','https://example.com/community/nested') as $root) {
    $mybb->settings['bburl']=$root;
    $r=(new VonSEO_Context(new VonSEO_Url()))->resolveEntity('forum',2);
    seoCheck(strpos($r->canonical,$root.'/') === 0 && $r->socialImage === $root.'/images/social.png', 'Root/nested-subfolder URL ownership remains intact');
}
seoFixture();
class SEOJobWorker extends VonSEO_IndexNow {
    public $seen = array(); public $race = false; public $fail = false;
    public function queueRemoval($tid,$url='',$transition=false) { $this->seen[]=$tid; if($this->race && count($this->seen)===1) { $GLOBALS['db']->overrides['forum:2']['job_generation']=str_repeat('b',32); $GLOBALS['db']->overrides['forum:2']['cursor_tid']=0; $GLOBALS['db']->overrides['forum:2']['pending_mode']='current'; } return !$this->fail; }
    public function queueThread($tid,$reason='update') { $this->seen[]=$tid; return true; }
}
for($tid=100;$tid<135;$tid++) { $db->threads[$tid]=$db->threads[16]; $db->threads[$tid]['tid']=$tid; }
foreach($db->threads as $tid => $thread) { if($thread['fid']===2) { $db->history[$tid]=array('tid'=>$tid,'last_public_url'=>'https://example.com/forum/showthread.php?tid='.$tid); } }
$db->overrides['forum:2']=array('entity_type'=>'forum','entity_id'=>2,'index_mode'=>'noindex','pending_mode'=>'removed','cursor_tid'=>0,'job_generation'=>str_repeat('a',32));
$failedWorker=new SEOJobWorker(); $failedWorker->fail=true;
seoCheck(VonSEO_Overrides::reconcile($failedWorker)===0 && $db->overrides['forum:2']['cursor_tid']===0 && $db->overrides['forum:2']['pending_mode']==='removed', 'Failed enqueue retains forum job and cursor for retry');
$worker=new SEOJobWorker(); $first=VonSEO_Overrides::reconcile($worker);
seoCheck($first===25 && count($worker->seen)===25 && $db->overrides['forum:2']['pending_mode']==='removed', 'Forum reconciliation is bounded to 25 threads per task');
$second=VonSEO_Overrides::reconcile($worker);
seoCheck($second<25 && $db->overrides['forum:2']['pending_mode']==='' && count(array_unique($worker->seen))===count($worker->seen), 'Cursor finishes remaining forum work without repeats');
$db->overrides['forum:2']['pending_mode']='removed'; $db->overrides['forum:2']['cursor_tid']=0; $db->overrides['forum:2']['job_generation']=str_repeat('a',32);
$racer=new SEOJobWorker(); $racer->race=true; VonSEO_Overrides::reconcile($racer);
seoCheck($db->overrides['forum:2']['job_generation']===str_repeat('b',32) && $db->overrides['forum:2']['cursor_tid']===0 && $db->overrides['forum:2']['pending_mode']==='current', 'A changed policy generation survives an older worker cursor update');
class SEOTransport extends VonSEO_IndexNow {
    public $urls = array(); public $race = false;
    protected function submitUrlsResult(array $urls) {
        $this->urls=$urls;
        if($this->race) { $GLOBALS['db']->queue[0]['generation']='fresh'; }
        return array('success'=>true,'class'=>'ready','http_status'=>200);
    }
}
foreach(array('known','never','race','private') as $mode) {
    seoFixture();
    $tid=$mode==='private'?18:16;
    $db->overrides['thread:'.$tid]=array('entity_type'=>'thread','entity_id'=>$tid,'index_mode'=>'noindex');
    if($mode!=='never') { $db->history[$tid]=array('tid'=>$tid,'last_public_url'=>'https://example.com/forum/showthread.php?tid='.$tid,'last_submitted_at'=>TIME_NOW-900); }
    $db->queue[]=array('qid'=>1,'tid'=>$tid,'generation'=>'old','event_type'=>'current','url'=>'https://example.com/forum/ignored','attempts'=>0,'retry_not_before'=>0);
    $transport=new SEOTransport(); $transport->race=$mode==='race'; $result=$transport->processQueue();
    seoCheck(in_array($mode,array('known','race'),true)?count($transport->urls)===1:!$transport->urls, 'Delivery uses history only for a public SEO policy transition: '.$mode);
    if($mode==='race') { seoCheck(count($db->queue)===1 && isset($db->history[$tid]), 'Concurrent queue generation and public history survive removal delivery'); }
}
foreach(array('seo','seo_save','seo_reset','seo_preview') as $action) {
    $modes=$action==='seo'?array('get','denied','private','out_of_range','engine_off'):($action==='seo_reset'?array('get','head','post','csrf','denied','private'):array('get','head','post','csrf','denied','invalid','array','private'));
    foreach($modes as $mode) {
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.$action.' '.$mode,$code);
        seoCheck($code===0,'Isolated ACP flow '.$action.' '.$mode);
    }
}
echo 'SEO overrides: '.$passed.' passed, '.$failed." failed\n";
exit($failed ? 1 : 0);
