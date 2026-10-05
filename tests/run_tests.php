<?php
/**
 * VonSEO Automated Test Suite (Self-contained Mock Runner)
 *
 * Runs test cases for URL resolver, Context, Schema, Meta, Robots,
 * IndexNow, and Redirects without requiring a live MyBB database.
 */

define('IN_MYBB', 1);
define('TIME_NOW', 1774267200); // Fixed timestamp for deterministic test
define('TABLE_PREFIX', 'mybb_');
define('MYBB_ROOT', dirname(__DIR__).'/');
define('THREAD_URL_PAGED', 'thread-{tid}-page-{page}.html');
define('THREAD_URL_POST', 'thread-{tid}-post-{pid}.html');
define('FORUM_URL_PAGED', 'forum-{fid}-page-{page}.html');

// Minimal MyBB URL helpers used by VonSEO_Url in query URL mode.
function get_thread_link(int $tid, int $page = 0, string $action = '')
{
    if($action !== '')
    {
        return 'showthread.php?tid='.(int)$tid.'&amp;action='.$action;
    }
    return 'showthread.php?tid='.(int)$tid.($page > 1 ? '&amp;page='.(int)$page : '');
}

function get_thread(int $tid)
{
    $GLOBALS['test_get_thread_count'] = isset($GLOBALS['test_get_thread_count']) ? $GLOBALS['test_get_thread_count'] + 1 : 1;
    return isset($GLOBALS['raw_thread_cache'][$tid]) ? $GLOBALS['raw_thread_cache'][$tid] : false;
}

function get_post_link(int $pid, int $tid = 0)
{
    return $tid > 0
        ? 'showthread.php?tid='.(int)$tid.'&amp;pid='.(int)$pid
        : 'showthread.php?pid='.(int)$pid;
}

function get_post(int $pid)
{
    $GLOBALS['test_get_post_count'] = isset($GLOBALS['test_get_post_count']) ? $GLOBALS['test_get_post_count'] + 1 : 1;
    return isset($GLOBALS['raw_post_cache'][$pid]) ? $GLOBALS['raw_post_cache'][$pid] : false;
}

function get_forum_link(int $fid, int $page = 0)
{
    return 'forumdisplay.php?fid='.(int)$fid.($page > 1 ? '&amp;page='.(int)$page : '');
}

function get_announcement_link(int $aid = 0)
{
    return 'announcements.php?aid='.(int)$aid;
}

function get_event_link(int $eid = 0)
{
    return 'calendar.php?action=event&amp;eid='.(int)$eid;
}

function get_calendar_link(int $calendar, int $year = 0, int $month = 0, int $day = 0)
{
    $url = 'calendar.php?calendar='.(int)$calendar;
    if($year > 0 && $month > 0)
    {
        $url .= '&amp;year='.(int)$year.'&amp;month='.(int)$month;
    }
    if($day > 0)
    {
        $url .= '&amp;day='.(int)$day;
    }
    return $url;
}

function get_forum(int $fid)
{
    return isset($GLOBALS['cache']->forums[$fid]) ? $GLOBALS['cache']->forums[$fid] : false;
}

function get_profile_link(int $uid)
{
    return 'member.php?action=profile&amp;uid='.(int)$uid;
}

function forum_permissions(int $fid, int $uid = 0, int $gid = 1)
{
    return isset($GLOBALS['test_forum_permissions'][$fid]) ? $GLOBALS['test_forum_permissions'][$fid] : array();
}

function get_inactive_forums()
{
    return isset($GLOBALS['test_inactive_forums']) ? $GLOBALS['test_inactive_forums'] : '';
}

function rebuild_settings()
{
    $GLOBALS['test_rebuild_count'] = isset($GLOBALS['test_rebuild_count']) ? $GLOBALS['test_rebuild_count'] + 1 : 1;
}

// Load plugin classes
require_once MYBB_ROOT.'inc/plugins/vonseo/Utils.php';
require_once MYBB_ROOT.'inc/plugins/vonseo/State.php';
require_once MYBB_ROOT.'inc/plugins/vonseo/Url.php';
require_once MYBB_ROOT.'inc/plugins/vonseo/Keyword.php';
require_once MYBB_ROOT.'inc/plugins/vonseo/Context.php';
require_once MYBB_ROOT.'inc/plugins/vonseo/Robots.php';
require_once MYBB_ROOT.'inc/plugins/vonseo/Social.php';
require_once MYBB_ROOT.'inc/plugins/vonseo/Schema.php';
require_once MYBB_ROOT.'inc/plugins/vonseo/Meta.php';
require_once MYBB_ROOT.'inc/plugins/vonseo/Sitemap.php';
require_once MYBB_ROOT.'inc/plugins/vonseo/Redirects.php';
require_once MYBB_ROOT.'inc/plugins/vonseo/IndexNow.php';
require_once MYBB_ROOT.'inc/plugins/vonseo/Errors.php';
require_once MYBB_ROOT.'inc/plugins/vonseo/Core.php';

// Mock MyBB globals
class MockMyBB
{
    public $user = array('uid' => 0);
    public $settings = array(
        'bburl' => 'https://example.com/forum',
        'bbname' => 'Community Forum',
        'homename' => 'Example Network',
        'homeurl' => 'https://example.com/',
        'vonseo_enabled' => '1',
        'vonseo_searchbox_schema' => '1',
        'vonseo_block_ai_crawlers' => '1',
        'vonseo_indexnow_enabled' => '1',
        'vonseo_indexnow_key' => 'abcdef0123456789abcdef0123456789',
        'vonseo_indexnow_auto_ping' => '1',
        'vonseo_site_description' => 'The best discussion community.',
        'vonseo_default_image' => 'https://example.com/forum/images/logo.png',
        'vonseo_profile_index' => '1',
        'vonseo_robots_txt' => "User-agent: *\nDisallow: /admin/"
    );
    public $input = array();
}

class MockCache
{
    public $forums = array();
    public $data = array();
    public $taskUpdates = 0;

    public function read(string $key)
    {
        if($key === 'forums')
        {
            return $this->forums;
        }
        return isset($this->data[$key]) ? $this->data[$key] : false;
    }

    public function update(string $key, array $value)
    {
        $this->data[$key] = $value;
    }

    public function delete(string $key)
    {
        unset($this->data[$key]);
    }

    public function update_tasks()
    {
        ++$this->taskUpdates;
    }
}

class MockDb
{
    public $threads = array();

    public function table_exists(string $table)
    {
        return false;
    }

    public function simple_select(string $table, string $fields, string $conditions = '', array $options = array())
    {
        if($table === 'threads' && preg_match("/tid='(\\d+)'/", $conditions, $matches))
        {
            $tid = (int)$matches[1];
            return isset($this->threads[$tid]) ? $this->threads[$tid] : array();
        }
        return array();
    }

    public function fetch_array(array $query)
    {
        return $query;
    }

    public function fetch_field(array $query, string $field)
    {
        return null;
    }

    public function escape_string(string $value)
    {
        return addslashes($value);
    }
}

class RecordingRedirects extends VonSEO_Redirects
{
    public $saved = array();

    public function saveRule($source, $target, $statusCode, $enabled = 1, $rid = 0)
    {
        $this->saved[] = array($source, $target, $statusCode, $enabled, $rid);
        return array('errors' => array(), 'rid' => count($this->saved));
    }
}

class InspectableRedirects extends VonSEO_Redirects
{
    public function getRequestCandidates()
    {
        return $this->requestCandidates();
    }
}

class InspectableSitemap extends VonSEO_Sitemap
{
    public function getPublicForumIds()
    {
        return $this->publicForumIds();
    }

    public function getAnnouncementCount($where, array $fids = array())
    {
        return $this->announcementCount($where, $fids);
    }
}

class RecordingIndexNow extends VonSEO_IndexNow
{
    public $submitted = array();

    public function submitUrls(array $urls)
    {
        $this->submitted = $urls;
        return true;
    }
}

class RecordingQueuedIndexNow extends VonSEO_IndexNow
{
    public $queued = array();

    protected function enqueueThread($tid, $url, $eventType = 'current', $availableAt = null)
    {
        $this->queued = array((int)$tid, $url, $eventType, $availableAt);
        return true;
    }
}

class RecordingProcessingIndexNow extends VonSEO_IndexNow
{
    public $submitResult = true;
    public $submissionResult = null;
    public $submitted = array();

    protected function submitUrlsResult(array $urls)
    {
        $this->submitted = $urls;
        if(is_array($this->submissionResult))
        {
            return $this->submissionResult;
        }
        return $this->submitResult
            ? array('success' => true, 'class' => 'ready', 'message' => '', 'http_status' => 200, 'retry_after' => 0, 'consume_attempt' => false)
            : array('success' => false, 'class' => 'remote_error', 'message' => 'Mock remote failure.', 'http_status' => 503, 'retry_after' => 0, 'consume_attempt' => true);
    }
}

class ConcurrentProcessingIndexNow extends RecordingProcessingIndexNow
{
    public $queueDb;

    protected function submitUrlsResult(array $urls)
    {
        $this->submitted = $urls;
        if($this->queueDb && isset($this->queueDb->rows[9]))
        {
            $this->queueDb->rows[9]['generation'] = 'ffffffffffffffffffffffffffffffff';
        }
        return array('success' => true, 'class' => 'ready', 'message' => '', 'http_status' => 200, 'retry_after' => 0, 'consume_attempt' => false);
    }
}

class ConcurrentFailureProcessingIndexNow extends RecordingProcessingIndexNow
{
    public $queueDb;

    protected function submitUrlsResult(array $urls)
    {
        $this->submitted = $urls;
        if($this->queueDb && isset($this->queueDb->rows[12]))
        {
            $this->queueDb->rows[12]['generation'] = '12121212121212121212121212121212';
        }
        return array(
            'success' => false,
            'class' => 'remote_error',
            'message' => 'Mock concurrent remote failure.',
            'http_status' => 503,
            'retry_after' => 0,
            'consume_attempt' => true
        );
    }
}

class InspectableIndexNow extends VonSEO_IndexNow
{
    public function classify($status, $retryAfter = 0)
    {
        return $this->classifyHttp($status, $retryAfter);
    }
}

class MockInstallQuery
{
    /** @var array */
    public $rows;
    public $position = 0;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }
}

class MockInstallDb
{
    public $type = 'mysqli';
    public $groups = array();
    public $settings = array();
    public $tables = array();
    public $redirectRows = array();
    public $removedTables = array();
    public $tasks = array();
    public $taskLogs = array();
    public $metaRows = array();
    public $fields = array();
    public $indexes = array();
    public $settingInsertPayloads = array();

    public function simple_select(string $table, string $fields, string $conditions = '', array $options = array())
    {
        if($table === 'settinggroups')
        {
            return new MockInstallQuery(isset($this->groups['vonseo']) ? array($this->groups['vonseo']) : array());
        }
        if($table === 'settings')
        {
            return new MockInstallQuery(array_values($this->settings));
        }
        if($table === 'tasks')
        {
            return new MockInstallQuery(array_values($this->tasks));
        }
        if($table === 'vonseo_meta')
        {
            if(preg_match("/meta_key='([^']+)'/", $conditions, $matches) && isset($this->metaRows[$matches[1]]))
            {
                return new MockInstallQuery(array($this->metaRows[$matches[1]]));
            }
            return new MockInstallQuery(array_values($this->metaRows));
        }
        if($table === 'vonseo_redirects' && strpos($fields, 'COUNT(') !== false)
        {
            return new MockInstallQuery(array(array('total' => count($this->redirectRows))));
        }
        if(($table === 'vonseo_404_log' || $table === 'vonseo_indexnow_queue' || $table === 'vonseo_indexnow_threads') && strpos($fields, 'COUNT(') !== false)
        {
            return new MockInstallQuery(array(array('total' => 0)));
        }
        return new MockInstallQuery(array());
    }

    public function fetch_field(MockInstallQuery $query, string $field)
    {
        return isset($query->rows[0][$field]) ? $query->rows[0][$field] : null;
    }

    public function fetch_array(MockInstallQuery $query)
    {
        return isset($query->rows[$query->position]) ? $query->rows[$query->position++] : false;
    }

    public function insert_query(string $table, array $data)
    {
        if($table === 'settinggroups')
        {
            $data['gid'] = 7;
            $this->groups['vonseo'] = $data;
            return 7;
        }
        if($table === 'settings')
        {
            $this->settingInsertPayloads[$data['name']] = $data;
            $stored = $data;
            foreach($stored as $field => $value)
            {
                if(is_string($value))
                {
                    $stored[$field] = stripslashes($value);
                }
            }
            $this->settings[$stored['name']] = $stored;
            return count($this->settings);
        }
        if($table === 'tasks')
        {
            $data['tid'] = count($this->tasks) + 1;
            $this->tasks[$data['file']] = $data;
            return $data['tid'];
        }
        return 0;
    }

    public function update_query(string $table, array $data, string $conditions)
    {
        if($table === 'settings' && preg_match("/name='([^']+)'/", $conditions, $matches) && isset($this->settings[$matches[1]]))
        {
            $this->settings[$matches[1]] = array_merge($this->settings[$matches[1]], $data);
        }
        elseif($table === 'tasks')
        {
            foreach($this->tasks as $file => $task)
            {
                if((preg_match("/tid='(\d+)'/", $conditions, $matches) && (int)$task['tid'] === (int)$matches[1]) ||
                   (preg_match("/file='([^']+)'/", $conditions, $matches) && $file === $matches[1]))
                {
                    $this->tasks[$file] = array_merge($task, $data);
                }
            }
        }
    }

    public function delete_query(string $table, string $conditions = '')
    {
        if($table === 'settings' && preg_match("/gid='(\\d+)'/", $conditions, $matches))
        {
            $gid = (int)$matches[1];
            foreach($this->settings as $name => $setting)
            {
                if((int)$setting['gid'] === $gid)
                {
                    unset($this->settings[$name]);
                }
            }
        }
        elseif($table === 'settings' && preg_match("/name='([^']+)'/", $conditions, $matches))
        {
            unset($this->settings[$matches[1]]);
        }
        elseif($table === 'settinggroups' && $conditions === "name='vonseo'")
        {
            unset($this->groups['vonseo']);
        }
        elseif($table === 'tasks' && $conditions === "file='vonseo_indexnow'")
        {
            unset($this->tasks['vonseo_indexnow']);
        }
        elseif($table === 'tasklog')
        {
            $this->taskLogs = array();
        }
        elseif($table === 'vonseo_indexnow_queue' || $table === 'vonseo_indexnow_threads')
        {
            // Migration fixture has no retained queue rows.
        }
    }

    public function drop_table(string $table)
    {
        $this->removedTables[] = $table;
        unset($this->tables[$table]);
        unset($this->fields[$table], $this->indexes[$table]);
        if($table === 'vonseo_redirects')
        {
            $this->redirectRows = array();
        }
    }

    public function escape_string(string $value)
    {
        return addslashes($value);
    }

    public function table_exists(string $table)
    {
        return isset($this->tables[$table]);
    }

    public function field_exists(string $field, string $table)
    {
        return !empty($this->fields[$table][$field]);
    }

    public function index_exists(string $table, string $index)
    {
        return !empty($this->indexes[$table][$index]);
    }

    public function replace_query(string $table, array $data)
    {
        if($table === 'vonseo_meta')
        {
            $this->metaRows[$data['meta_key']] = $data;
        }
    }

    public function build_create_table_collation()
    {
        return '';
    }

    public function write_query(string $sql)
    {
        if(preg_match('/CREATE TABLE mybb_(vonseo_[a-z0-9_]+)/', $sql, $matches))
        {
            $this->tables[$matches[1]] = true;
            if($matches[1] === 'vonseo_indexnow_queue')
            {
                $this->fields[$matches[1]] = array(
                    'tid' => true,
                    'generation' => true,
                    'event_type' => true,
                    'retry_not_before' => true
                );
                $this->indexes[$matches[1]] = array('tid' => true, 'retry_not_before' => true);
            }
        }
        elseif(preg_match('/ALTER TABLE mybb_vonseo_indexnow_queue ADD (?:UNIQUE KEY |KEY )?(tid|generation|event_type|retry_not_before)/', $sql, $matches))
        {
            if($matches[1] === 'tid' && strpos($sql, 'UNIQUE KEY') !== false)
            {
                $this->indexes['vonseo_indexnow_queue']['tid'] = true;
            }
            elseif($matches[1] === 'retry_not_before' && strpos($sql, 'ADD KEY') !== false)
            {
                $this->indexes['vonseo_indexnow_queue']['retry_not_before'] = true;
            }
            else
            {
                $this->fields['vonseo_indexnow_queue'][$matches[1]] = true;
            }
        }
    }
}

class Mock404Db
{
    public $count = 0;
    public $existingHash = '';
    public $inserted = array();
    public $updated = 0;
    public $selects = 0;
    public $countSelects = 0;
    public $pruned = 0;
    public $lastAffected = 0;
    public $lockAvailable = true;
    public $locks = 0;
    public $releases = 0;

    public function table_exists(string $table)
    {
        return $table === 'vonseo_404_log';
    }

    public function simple_select(string $table, string $fields, string $conditions = '', array $options = array())
    {
        $this->selects++;
        if($fields === 'COUNT(path_hash) AS total')
        {
            $this->countSelects++;
            return new MockInstallQuery(array(array('total' => $this->count)));
        }
        return new MockInstallQuery($this->existingHash !== '' ? array(array('path_hash' => $this->existingHash)) : array());
    }

    public function fetch_field(MockInstallQuery $query, string $field)
    {
        return isset($query->rows[0][$field]) ? $query->rows[0][$field] : null;
    }

    public function escape_string(string $value)
    {
        return addslashes($value);
    }

    public function insert_query(string $table, array $data)
    {
        $this->inserted[] = $data;
        return count($this->inserted);
    }

    public function write_query(string $sql)
    {
        $this->lastAffected = 0;
        if(strpos($sql, 'SELECT GET_LOCK(') === 0)
        {
            $this->locks++;
            return new MockInstallQuery(array(array('acquired' => $this->lockAvailable ? 1 : 0)));
        }
        if(strpos($sql, 'SELECT RELEASE_LOCK(') === 0)
        {
            $this->releases++;
            return new MockInstallQuery(array(array('released' => 1)));
        }
        if(strpos($sql, 'UPDATE mybb_vonseo_404_log SET') === 0
            && preg_match("/WHERE path_hash='([^']+)'$/", $sql, $matches))
        {
            if($this->existingHash !== '' && $matches[1] === $this->existingHash)
            {
                $this->updated++;
                $this->lastAffected = 1;
            }
        }
        elseif(strpos($sql, 'INSERT INTO mybb_vonseo_404_log') === 0 && preg_match("/VALUES \('([^']+)','([^']*)',1,/", $sql, $matches))
        {
            $this->inserted[] = array('path_hash' => $matches[1], 'path' => stripslashes($matches[2]));
            $this->count++;
            $this->lastAffected = 1;
        }
        elseif(strpos($sql, 'DELETE FROM mybb_vonseo_404_log') === 0)
        {
            $this->pruned++;
            $remove = preg_match('/LIMIT (\d+)/', $sql, $matches) ? (int)$matches[1] : 0;
            $this->count = max(0, $this->count - $remove);
            $this->lastAffected = $remove;
        }
        return true;
    }

    public function affected_rows()
    {
        return $this->lastAffected;
    }
}

class MockQueueDb
{
    public $rows = array();
    public $threads = array();
    public $posts = array();
    public $history = array();
    public $lastAffected = 0;

    public function table_exists(string $table)
    {
        return in_array($table, array('vonseo_indexnow_queue', 'vonseo_indexnow_threads'), true);
    }

    public function simple_select(string $table, string $fields, string $conditions = '', array $options = array())
    {
        if($table === 'threads' && preg_match("/tid='(\d+)'/", $conditions, $matches))
        {
            $tid = (int)$matches[1];
            return new MockInstallQuery(isset($this->threads[$tid]) ? array($this->threads[$tid]) : array());
        }
        if($table === 'posts' && preg_match("/pid='(\d+)'/", $conditions, $matches))
        {
            $pid = (int)$matches[1];
            return new MockInstallQuery(isset($this->posts[$pid]) ? array($this->posts[$pid]) : array());
        }
        if($table === 'vonseo_indexnow_threads' && preg_match("/tid='(\d+)'/", $conditions, $matches))
        {
            $tid = (int)$matches[1];
            return new MockInstallQuery(isset($this->history[$tid]) ? array($this->history[$tid]) : array());
        }
        if(strpos($fields, 'COUNT(') !== false)
        {
            return new MockInstallQuery(array(array('total' => count($this->rows))));
        }

        $rows = array_values(array_filter($this->rows, function($row) {
            return (int)$row['available_at'] <= TIME_NOW &&
                (!isset($row['retry_not_before']) || (int)$row['retry_not_before'] <= TIME_NOW);
        }));
        $limit = isset($options['limit']) ? (int)$options['limit'] : count($rows);
        return new MockInstallQuery(array_slice($rows, 0, $limit));
    }

    public function fetch_array(MockInstallQuery $query)
    {
        return isset($query->rows[$query->position]) ? $query->rows[$query->position++] : false;
    }

    public function fetch_field(MockInstallQuery $query, string $field)
    {
        return isset($query->rows[0][$field]) ? $query->rows[0][$field] : null;
    }

    public function delete_query(string $table, string $conditions = '')
    {
        $this->lastAffected = 0;
        if($table === 'vonseo_indexnow_threads' && preg_match("/tid='(\d+)'/", $conditions, $matches))
        {
            $tid = (int)$matches[1];
            if(isset($this->history[$tid]))
            {
                unset($this->history[$tid]);
                $this->lastAffected = 1;
            }
        }
        elseif($table === 'vonseo_indexnow_queue' && preg_match("/qid='(\d+)'/", $conditions, $matches))
        {
            $id = (int)$matches[1];
            if(isset($this->rows[$id]) && preg_match("/generation='([^']+)'/", $conditions, $generation) &&
               $this->rows[$id]['generation'] === stripslashes($generation[1]))
            {
                unset($this->rows[$id]);
                $this->lastAffected = 1;
            }
        }
    }

    public function update_query(string $table, array $data, string $conditions)
    {
        $this->lastAffected = 0;
        if($table === 'vonseo_indexnow_queue' && preg_match("/qid='(\d+)'/", $conditions, $matches) && isset($this->rows[(int)$matches[1]]) &&
           preg_match("/generation='([^']+)'/", $conditions, $generation) &&
           $this->rows[(int)$matches[1]]['generation'] === stripslashes($generation[1]))
        {
            $this->rows[(int)$matches[1]] = array_merge($this->rows[(int)$matches[1]], $data);
            $this->lastAffected = 1;
        }
        elseif($table === 'vonseo_indexnow_queue' && preg_match("/tid='(\d+)'/", $conditions, $matches))
        {
            foreach($this->rows as $id => $row)
            {
                if((int)$row['tid'] === (int)$matches[1] && (!isset($row['event_type']) || $row['event_type'] === 'current'))
                {
                    $this->rows[$id] = array_merge($row, $data);
                    $this->lastAffected++;
                }
            }
        }
    }

    public function replace_query(string $table, array $data)
    {
        if($table === 'vonseo_indexnow_threads')
        {
            $this->history[(int)$data['tid']] = $data;
            $this->lastAffected = 1;
        }
    }

    public function write_query(string $sql)
    {
        if(!preg_match("/INSERT INTO mybb_vonseo_indexnow_queue .*VALUES \((\d+),'([^']+)','([^']+)','([^']+)','([^']+)',0,(\d+),0,(\d+),(\d+),''\)/", $sql, $matches))
        {
            return;
        }
        $tid = (int)$matches[1];
        $existingId = 0;
        foreach($this->rows as $id => $row)
        {
            if((int)$row['tid'] === $tid)
            {
                $existingId = (int)$id;
                break;
            }
        }
        $id = $existingId > 0 ? $existingId : (empty($this->rows) ? 1 : max(array_keys($this->rows)) + 1);
        $availableAt = (int)$matches[6];
        if($existingId > 0)
        {
            $availableAt = min((int)$this->rows[$existingId]['available_at'], $availableAt);
        }
        $existing = $existingId > 0 ? $this->rows[$existingId] : array();
        $this->rows[$id] = array(
            'qid' => $id,
            'tid' => $tid,
            'generation' => stripslashes($matches[2]),
            'event_type' => stripslashes($matches[3]),
            'url_hash' => stripslashes($matches[4]),
            'url' => stripslashes($matches[5]),
            'attempts' => isset($existing['attempts']) ? (int)$existing['attempts'] : 0,
            'available_at' => $availableAt,
            'retry_not_before' => isset($existing['retry_not_before']) ? (int)$existing['retry_not_before'] : 0,
            'created_at' => (int)$matches[7],
            'updated_at' => (int)$matches[8],
            'last_error' => isset($existing['last_error']) ? (string)$existing['last_error'] : ''
        );
        $this->lastAffected = 1;
    }

    public function affected_rows()
    {
        return $this->lastAffected;
    }

    public function escape_string(string $value)
    {
        return addslashes($value);
    }
}

class MockSitemapDb
{
    public $threadCount = 1500;
    public $offsets = array();
    public $announcementCount = 2;
    public $announcementRows = array();
    public $announcementOffsets = array();
    public $announcementConditions = array();
    public $announcementCountQueries = 0;
    public $calendarRows = array();
    public $calendarPermissions = array();
    public $eventCount = 0;
    public $eventRows = array();
    public $eventOffsets = array();
    public $eventConditions = array();
    public $eventCountQueries = 0;

    public function simple_select(string $table, string $fields, string $conditions = '', array $options = array())
    {
        if($table === 'threads' && $fields === 'COUNT(tid) AS total')
        {
            return new MockInstallQuery(array(array('total' => $this->threadCount)));
        }
        if($table === 'threads')
        {
            $this->offsets[] = $options['limit_start'];
            return new MockInstallQuery(array(array('tid' => 10, 'subject' => 'Modern SEO Discussion', 'lastpost' => TIME_NOW, 'dateline' => TIME_NOW)));
        }
        if($table === 'announcements')
        {
            $this->announcementConditions[] = $conditions;
            if($fields === 'COUNT(aid) AS total')
            {
                ++$this->announcementCountQueries;
                return new MockInstallQuery(array(array('total' => $this->announcementCount)));
            }
            $this->announcementOffsets[] = isset($options['limit_start']) ? (int)$options['limit_start'] : 0;
            return new MockInstallQuery($this->announcementRows);
        }
        if($table === 'calendars')
        {
            return new MockInstallQuery($this->calendarRows);
        }
        if($table === 'usergroups')
        {
            return new MockInstallQuery(array(array('canviewcalendar' => 1)));
        }
        if($table === 'calendarpermissions')
        {
            $cid = preg_match("/cid='(\\d+)'/", $conditions, $matches) ? (int)$matches[1] : 0;
            return new MockInstallQuery(isset($this->calendarPermissions[$cid])
                ? array(array('canviewcalendar' => (int)$this->calendarPermissions[$cid])) : array());
        }
        if($table === 'events')
        {
            $this->eventConditions[] = $conditions;
            if($fields === 'COUNT(eid) AS total')
            {
                ++$this->eventCountQueries;
                return new MockInstallQuery(array(array('total' => $this->eventCount)));
            }
            $this->eventOffsets[] = isset($options['limit_start']) ? (int)$options['limit_start'] : 0;
            return new MockInstallQuery($this->eventRows);
        }
        return new MockInstallQuery(array());
    }

    public function fetch_field(MockInstallQuery $query, string $field)
    {
        return isset($query->rows[0][$field]) ? $query->rows[0][$field] : null;
    }

    public function fetch_array(MockInstallQuery $query)
    {
        return isset($query->rows[$query->position]) ? $query->rows[$query->position++] : false;
    }
}

$GLOBALS['mybb'] = new MockMyBB();
$GLOBALS['cache'] = new MockCache();
$GLOBALS['db'] = new MockDb();

class TestRunner
{
    private $passed = 0;
    private $failed = 0;

    public function assert(bool $condition, string $testName, string $details = '')
    {
        if($condition)
        {
            $this->passed++;
            echo " [PASS] {$testName}\n";
        }
        else
        {
            $this->failed++;
            echo " [FAIL] {$testName}\n";
            if($details !== '')
            {
                echo "        Details: {$details}\n";
            }
        }
    }

    public function report()
    {
        echo "\n==========================================\n";
        echo " Test Summary: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "==========================================\n";
        return $this->failed === 0;
    }
}

$t = new TestRunner();

http_response_code(200);
echo "Running VonSEO Test Suite...\n\n";

// --- TEST 1: URL Resolver ---
$url = new VonSEO_Url();
$t->assert($url->home() === 'https://example.com/forum/', 'Url::home() returns the board homepage');
$t->assert($url->thread(42) === 'https://example.com/forum/showthread.php?tid=42', 'Url::thread() returns canonical thread URL');
$t->assert($url->thread(42, 2) === 'https://example.com/forum/showthread.php?tid=42&page=2', 'Url::thread() with page 2 returns paginated URL');
$t->assert($url->forum(5) === 'https://example.com/forum/forumdisplay.php?fid=5', 'Url::forum() returns canonical forum URL');
$t->assert($url->announcement(7) === 'https://example.com/forum/announcements.php?aid=7', 'Url::announcement() uses MyBB announcement routing under the board URL');
$t->assert($url->event(8) === 'https://example.com/forum/calendar.php?action=event&eid=8', 'Url::event() uses MyBB event routing under the board URL');
$t->assert($url->calendar(3, 2026, 9) === 'https://example.com/forum/calendar.php?calendar=3&year=2026&month=9', 'Url::calendar() uses MyBB monthly calendar routing under the board URL');
$t->assert($url->misc('vonseo_indexnow_key') === 'https://example.com/forum/misc.php?action=vonseo_indexnow_key', 'Url::misc() returns correct action URL');
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '1';
$t->assert($url->thread(42, 0, 'Café & Teh') === 'https://example.com/forum/t-42-caf%C3%A9-teh', 'Keyword thread URL keeps ID and UTF-8 title without duplicate subfolder');
$t->assert($url->thread(43, 2, 'Café & Teh') === 'https://example.com/forum/t-43-caf%C3%A9-teh--p2', 'Keyword thread pagination has a flat, collision-safe path');
$t->assert($url->threadPost(43, 105, 'Café & Teh') === 'https://example.com/forum/t-43-caf%C3%A9-teh--post-105#pid105', 'Keyword post target keeps the thread slug and exact post anchor');
$actionUrls = array();
foreach(array('lastpost', 'newpost', 'nextnewest', 'nextoldest') as $action)
{
    $actionUrls[$action] = $url->threadAction(43, $action, 'Café & Teh');
}
$t->assert($actionUrls === array(
    'lastpost' => 'https://example.com/forum/t-43-caf%C3%A9-teh--lastpost',
    'newpost' => 'https://example.com/forum/t-43-caf%C3%A9-teh--newpost',
    'nextnewest' => 'https://example.com/forum/t-43-caf%C3%A9-teh--nextnewest',
    'nextoldest' => 'https://example.com/forum/t-43-caf%C3%A9-teh--nextoldest'
), 'All four stock dynamic thread actions use the keyword family');
$t->assert($url->threadAction(43, 'printable', 'Café & Teh') === '', 'Unrecognized thread actions are not exposed as keyword routes');
$t->assert($url->forum(5, 2, 'General Discussion') === 'https://example.com/forum/f-5-general-discussion--p2', 'Keyword forum URL supports flat pagination', $url->forum(5, 2, 'General Discussion'));
$t->assert($url->thread(44, 0, '!!!') === 'https://example.com/forum/t-44-thread', 'Punctuation-only title uses a safe fallback slug');
$rootUrl = new VonSEO_Url('https://example.com');
$t->assert($rootUrl->forum(7, 0, 'News') === 'https://example.com/f-7-news', 'Keyword URL works from a root-path MyBB install');
$rewriteRules = file_get_contents(MYBB_ROOT.'extras/htaccess-vonseo.txt');
preg_match_all('/^RewriteRule \^(?:t|f)-[^\r\n]+$/m', $rewriteRules, $keywordRules);
$safeKeywordRules = count($keywordRules[0]) === 6;
foreach($keywordRules[0] as $keywordRule)
{
    $safeKeywordRules = $safeKeywordRules && strpos($keywordRule, 'QSA') === false && substr(trim($keywordRule), -3) === '[L]';
}
$t->assert($safeKeywordRules, 'Keyword Apache rules discard incoming query parameters instead of allowing ID overrides');
$t->assert(strpos($rewriteRules, 'sitemap\\.xml$ misc.php?action=vonseo_sitemap [L,QSA]') === false &&
    strpos($rewriteRules, 'robots\\.txt$ misc.php?action=vonseo_robots [L,QSA]') === false &&
    strpos($rewriteRules, 'type=(forums|threads|announcements|calendars|events)') !== false,
    'Clean crawler routes discard action collisions while preserving supported sitemap selectors');
$t->assert(strpos($rewriteRules, 'misc.php?action=vonseo_route [L,QSA]') !== false && strpos($rewriteRules, 'vonseo_path=') === false,
    'Generic Apache fallback routes through REQUEST_URI without an unused captured-path parameter');
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '0';
$t->assert($url->threadPost(43, 105, 'Café & Teh') === 'https://example.com/forum/showthread.php?tid=43&pid=105#pid105', 'Post target rolls back to a MyBB URL with its exact anchor');
$t->assert($url->threadAction(43, 'lastpost', 'Café & Teh') === 'https://example.com/forum/showthread.php?tid=43&action=lastpost', 'Dynamic thread action rolls back to its native MyBB URL');

// --- TEST 2: Schema.org JSON-LD (WebSite and optional legacy SearchAction) ---
$schema = new VonSEO_Schema($url);
$homeContext = new VonSEO_Context($url);
$homeContext->type = 'home';
$homeContext->indexable = true;
$homeContext->canonical = $url->home();
$homeContext->title = 'Community Forum';
$homeContext->description = 'The best discussion community.';

$homeSchema = $schema->build($homeContext);
$t->assert(!empty($homeSchema['@graph']), 'Home schema generates @graph structure');
$websiteNode = null;
foreach($homeSchema['@graph'] as $node)
{
    if($node['@type'] === 'WebSite')
    {
        $websiteNode = $node;
        break;
    }
}
$t->assert($websiteNode !== null, 'WebSite schema node is present on homepage');
$t->assert(!empty($websiteNode['potentialAction']), 'Optional legacy SearchAction is present when explicitly enabled');
$t->assert(
    $websiteNode['potentialAction']['target']['urlTemplate'] === 'https://example.com/forum/search.php?action=do_search&keywords={search_term_string}',
    'SearchAction target uses one board subfolder and no duplicate slash'
);
$GLOBALS['mybb']->settings['vonseo_searchbox_schema'] = '0';
$disabledSchema = $schema->build($homeContext);
$t->assert(empty($disabledSchema['@graph'][0]['potentialAction']), 'Legacy SearchAction is absent when disabled');
$GLOBALS['mybb']->settings['vonseo_searchbox_schema'] = '1';

// --- TEST 3: Schema.org (DiscussionForumPosting with interactionStatistic) ---
$threadContext = new VonSEO_Context($url);
$threadContext->type = 'thread';
$threadContext->indexable = true;
$threadContext->canonical = 'https://example.com/forum/showthread.php?tid=10';
$threadContext->title = 'Modern SEO Discussion - Community Forum';
$threadContext->description = 'Let us talk about modern SEO standards.';
$threadContext->socialImage = 'https://example.com/forum/images/logo.png';
$threadContext->data = array(
    'first_post_text' => 'Let us talk about modern SEO standards. Here is the complete public post.',
    'first_post_edittime' => 1774263600,
    'first_page_url' => 'https://example.com/forum/showthread.php?tid=10',
    'thread' => array(
        'tid' => 10,
        'subject' => 'Modern SEO Discussion',
        'replies' => 25,
        'views' => 1250,
        'username' => 'Vondereich',
        'uid' => 1,
        'dateline' => 1774260000,
        'lastpost' => 1774267200
    ),
    'forum' => array(
        'fid' => 2,
        'name' => 'SEO & Webmastering'
    )
);

$threadSchema = $schema->build($threadContext);
$postingNode = null;
foreach($threadSchema['@graph'] as $node)
{
    if($node['@type'] === 'DiscussionForumPosting')
    {
        $postingNode = $node;
        break;
    }
}
$t->assert($postingNode !== null, 'DiscussionForumPosting node generated for thread');
$t->assert($postingNode['commentCount'] === 25, 'commentCount matches thread replies');
$t->assert(!empty($postingNode['interactionStatistic']), 'interactionStatistic array generated');
$t->assert($postingNode['articleSection'] === 'SEO & Webmastering', 'articleSection populated with forum name');
$t->assert($postingNode['author']['name'] === 'Vondereich', 'Author name is present in Person schema');
$t->assert($postingNode['text'] === $threadContext->data['first_post_text'], 'Thread schema contains the full guest-rendered original post text');
$t->assert(!isset($postingNode['image']), 'Default social image is not mislabeled as an inline post image');
$t->assert($postingNode['dateModified'] === VonSEO_Utils::isoDate(1774263600), 'Posting modified date uses original-post edit time, not latest reply time');
$laterContext = clone $threadContext;
$laterContext->page = 2;
$laterContext->canonical = 'https://example.com/forum/showthread.php?tid=10&page=2';
unset($laterContext->data['first_post_text']);
$laterSchema = $schema->build($laterContext);
$laterPosting = $laterSchema['@graph'][0];
$t->assert($laterPosting['url'] === $threadContext->canonical && $laterPosting['@id'] === $threadContext->canonical.'#posting', 'Later-page posting refers to the original first-page URL and stable ID');
$t->assert(!isset($laterPosting['text']) && !isset($laterPosting['mainEntityOfPage']), 'Later page does not claim the absent original text is visible there');
$missingTextContext = clone $threadContext;
unset($missingTextContext->data['first_post_text']);
$missingTextSchema = $schema->build($missingTextContext);
$t->assert(!isset($missingTextSchema['@graph'][0]) || $missingTextSchema['@graph'][0]['@type'] !== 'DiscussionForumPosting', 'Page one omits incomplete posting markup when original text was not rendered');
$missingAuthorContext = clone $threadContext;
$missingAuthorContext->data['thread']['username'] = '<b></b>';
$missingAuthorSchema = $schema->build($missingAuthorContext);
$t->assert(!isset($missingAuthorSchema['@graph'][0]) || $missingAuthorSchema['@graph'][0]['@type'] !== 'DiscussionForumPosting', 'Posting markup is omitted when the required author name has no visible text');

// --- TEST 4: Open Graph & Social Article Tags ---
$social = new VonSEO_Social();
$tags = $social->tags($threadContext);
$articleSectionFound = false;
$articleAuthorFound = false;
foreach($tags as $tag)
{
    if($tag[1] === 'article:section' && $tag[2] === 'SEO & Webmastering')
    {
        $articleSectionFound = true;
    }
    if($tag[1] === 'article:author' && $tag[2] === 'Vondereich')
    {
        $articleAuthorFound = true;
    }
}
$t->assert($articleSectionFound, 'Open Graph article:section tag is emitted');
$t->assert($articleAuthorFound, 'Open Graph article:author tag is emitted');
$originalBoardName = $GLOBALS['mybb']->settings['bbname'];
$GLOBALS['mybb']->settings['bbname'] = '  Community Forum  ';
$trimmedSocial = $social->tags($threadContext);
$trimmedSiteName = '';
foreach($trimmedSocial as $tag)
{
    if($tag[1] === 'og:site_name')
    {
        $trimmedSiteName = $tag[2];
    }
}
$trimmedSchema = $schema->build($homeContext);
$t->assert($trimmedSiteName === 'Community Forum' && $trimmedSchema['@graph'][0]['name'] === 'Community Forum',
    'MyBB board identity is trimmed before Open Graph and schema output');
$GLOBALS['mybb']->settings['bbname'] = $originalBoardName;
$siteDetailsSchema = $schema->build($homeContext);
$siteDetailsPublisher = isset($siteDetailsSchema['@graph'][0]['publisher']) ? $siteDetailsSchema['@graph'][0]['publisher'] : array();
$t->assert(isset($siteDetailsPublisher['name'], $siteDetailsPublisher['url']) &&
    $siteDetailsPublisher['name'] === 'Example Network' && $siteDetailsPublisher['url'] === 'https://example.com/',
    'MyBB Homepage Name and Homepage URL become the Organization publisher identity');
$originalHomeUrl = $GLOBALS['mybb']->settings['homeurl'];
$GLOBALS['mybb']->settings['homeurl'] = 'javascript:alert(1)';
$invalidSiteDetailsSchema = $schema->build($homeContext);
$invalidSiteDetailsPublisher = isset($invalidSiteDetailsSchema['@graph'][0]['publisher']) ? $invalidSiteDetailsSchema['@graph'][0]['publisher'] : array();
$t->assert(isset($invalidSiteDetailsPublisher['name'], $invalidSiteDetailsPublisher['url']) &&
    $invalidSiteDetailsPublisher['name'] === 'Community Forum' && $invalidSiteDetailsPublisher['url'] === 'https://example.com/forum/',
    'Invalid MyBB Homepage URL cannot enter Organization schema and falls back to the board identity');
$GLOBALS['mybb']->settings['homeurl'] = $originalHomeUrl;

// --- TEST 5: Robots Directives & AI Crawlers ---
$robots = new VonSEO_Robots($url);
$directive = $robots->directive($threadContext);
$t->assert(strpos($directive, 'max-image-preview:large') !== false, 'Robots directive includes max-image-preview:large');
$t->assert(strpos($directive, 'max-snippet:-1') !== false, 'Robots directive includes max-snippet:-1');

ob_start();
$robots->outputTxt();
$robotsOutput = ob_get_clean();
$t->assert(strpos($robotsOutput, 'GPTBot') !== false, 'robots.txt includes GPTBot disallow directive');
$t->assert(strpos($robotsOutput, 'ClaudeBot') !== false, 'robots.txt includes ClaudeBot disallow directive');
$t->assert(strpos($robotsOutput, 'Sitemap: https://example.com/forum/misc.php?action=vonseo_sitemap') !== false, 'robots.txt includes Sitemap endpoint');
$GLOBALS['mybb']->settings['vonseo_sitemap_enabled'] = '0';
ob_start();
$robots->outputTxt();
$robotsWithoutSitemap = ob_get_clean();
$t->assert(strpos($robotsWithoutSitemap, 'Sitemap:') === false, 'robots.txt omits the Sitemap line when the sitemap module is disabled');
$GLOBALS['mybb']->settings['vonseo_sitemap_enabled'] = '1';
$GLOBALS['mybb']->settings['vonseo_robots_txt'] .= "\nCrawl-delay: 7";
ob_start();
$robots->outputTxt();
$robotsTrainingOnly = ob_get_clean();
$t->assert(strpos($robotsTrainingOnly, 'Crawl-delay: 7') !== false, 'robots.txt preserves an administrator-defined Crawl-delay directive');
$t->assert(strpos($robotsTrainingOnly, 'ChatGPT-User') === false && strpos($robotsTrainingOnly, 'OAI-SearchBot') === false, 'Training crawler toggle does not block ChatGPT user fetches or AI search');
$GLOBALS['mybb']->settings['vonseo_block_ai_search_crawlers'] = '1';
ob_start();
$robots->outputTxt();
$robotsWithSearchBlock = ob_get_clean();
$t->assert(strpos($robotsWithSearchBlock, 'OAI-SearchBot') !== false && strpos($robotsWithSearchBlock, 'PerplexityBot') !== false, 'Separate AI search toggle emits search crawler blocks');
$GLOBALS['mybb']->settings['vonseo_block_ai_search_crawlers'] = '0';
$GLOBALS['mybb']->settings['vonseo_robots_txt'] = "User-agent: *\nDisallow: /forum/admin/";

// --- TEST 6: IndexNow Protocol Engine ---
$indexNow = new VonSEO_IndexNow($url);
$t->assert($indexNow->getKey() === 'abcdef0123456789abcdef0123456789', 'IndexNow::getKey() returns configured key');
$t->assert($indexNow->getKeyLocation() === 'https://example.com/forum/misc.php?action=vonseo_indexnow_key', 'IndexNow::getKeyLocation() returns key endpoint');

$generatedKey = $indexNow->generateKey();
$t->assert(strlen($generatedKey) === 32, 'IndexNow::generateKey() generates 32-char hex string');
$GLOBALS['mybb']->settings['vonseo_indexnow_key'] = '';
$t->assert($indexNow->getKey() === '' && $indexNow->getKey() === '', 'Blank IndexNow setting never generates a transient verification key');
$GLOBALS['mybb']->settings['vonseo_indexnow_key'] = 'abcdef0123456789abcdef0123456789';

// --- TEST 7: Redirects Normalization & Security Guards ---
$redirects = new VonSEO_Redirects($url);
$t->assert($redirects->normalizeSource('/old-thread') === '/old-thread', 'normalizeSource accepts board-relative path');
$t->assert($redirects->normalizeSource('https://example.com/forum/old-thread') === '/old-thread', 'normalizeSource normalizes same-host absolute URL');
$t->assert($redirects->normalizeSource('https://example.com:443/forum/old-thread') === '/old-thread', 'normalizeSource accepts the default HTTPS port for the board origin');
$t->assert($redirects->normalizeSource('http://example.com/forum/old-thread') === false, 'normalizeSource rejects a scheme mismatch on the same hostname');
$t->assert($redirects->normalizeSource('https://example.com:444/forum/old-thread') === false, 'normalizeSource rejects a port mismatch on the same hostname');
$t->assert($redirects->normalizeSource('https://external-evil.com/phish') === false, 'normalizeSource rejects foreign domain');
$t->assert($redirects->normalizeTarget('javascript:alert(1)') === false, 'normalizeTarget rejects javascript URI');
$t->assert($redirects->normalizeTarget('https://example.com:443/forum/new-thread') === 'https://example.com:443/forum/new-thread', 'normalizeTarget accepts the default HTTPS port for the board origin');
$t->assert($redirects->normalizeTarget('http://example.com/forum/new-thread') === false, 'normalizeTarget rejects a same-host scheme mismatch');
$t->assert($redirects->normalizeTarget('https://example.com:444/forum/new-thread') === false, 'normalizeTarget rejects a same-host port mismatch');

$inspectableRedirects = new InspectableRedirects($url);
$_SERVER['REQUEST_URI'] = '/forum/showthread.php?tid=10&page=2';
$t->assert($inspectableRedirects->getRequestCandidates() === array('/showthread.php?tid=10&page=2'), 'Redirect lookup keeps a query URL exact and does not fall back to a path-only wildcard');
$_SERVER['REQUEST_URI'] = '/forum/old-thread';
$t->assert($inspectableRedirects->getRequestCandidates() === array('/old-thread'), 'Redirect lookup keeps a query-free source path exact');

$validRule = $redirects->validateRule('/old-page', '/new-page', 301);
$t->assert(empty($validRule['errors']), 'validateRule accepts valid 301 rule');

$loopRule = $redirects->validateRule('/same-page', '/same-page', 301);
$t->assert(!empty($loopRule['errors']), 'validateRule rejects self-redirect loop');

// --- TEST 8: CSV Import Parser ---
$csvRedirects = new RecordingRedirects($url);
$csvSample = "source_path,target_url,status_code,enabled\n/test-old,/test-new,301,1\n/legacy-topic,\"https://example.com/forum/showthread.php?tid=5&page=2\",302,0\n";
$csvResult = $csvRedirects->importCsv($csvSample);
$t->assert($csvResult['imported'] === 2 && $csvResult['skipped'] === 0, 'CSV import skips the header and creates two rows');
$t->assert($csvRedirects->saved[1][1] === 'https://example.com/forum/showthread.php?tid=5&page=2', 'CSV import preserves a quoted destination URL');
$t->assert($csvRedirects->saved[1][2] === 302 && $csvRedirects->saved[1][3] === 0, 'CSV import preserves status and enabled fields');
$emptyImport = $csvRedirects->importCsv(" \r\n ");
$t->assert(!empty($emptyImport['errors']) && $emptyImport['imported'] === 0, 'Blank CSV returns a validation error');
$savedBeforeBound = count($csvRedirects->saved);
$tooManyRows = "source_path,target_url,status_code,enabled\n";
for($csvRow = 1; $csvRow <= 5001; $csvRow++)
{
    $tooManyRows .= "/old-{$csvRow},/new-{$csvRow},301,1\n";
}
$boundedImport = $csvRedirects->importCsv($tooManyRows);
$t->assert(!empty($boundedImport['errors']) && count($csvRedirects->saved) === $savedBeforeBound, 'CSV row limit fails before any redirect writes');
$oversizeImport = $csvRedirects->importCsv(str_repeat('x', VonSEO_Redirects::MAX_CSV_BYTES + 1));
$t->assert(!empty($oversizeImport['errors']) && count($csvRedirects->saved) === $savedBeforeBound, 'CSV byte limit fails before any redirect writes');
$multilineCsv = "source_path,target_url,status_code,enabled\n\"/bad\nsource\",/target,301,1\n/after-multiline,/after-target,302,1\n";
$multilineResult = $csvRedirects->importCsv($multilineCsv);
$t->assert($multilineResult['imported'] === 1 && $multilineResult['skipped'] === 1 && end($csvRedirects->saved)[0] === '/after-multiline', 'CSV parser treats a quoted multiline field as one rejected row and continues safely');

$runtimeDb = $GLOBALS['db'];
$GLOBALS['db'] = new MockDb();
$missingEdit = (new VonSEO_Redirects($url))->saveRule('/missing-edit', '/target', 301, 1, 999);
$t->assert(!empty($missingEdit['errors']) && $missingEdit['rid'] === 0, 'Redirect edit rejects a rule ID that no longer exists');
$GLOBALS['db'] = $runtimeDb;

// --- TEST 9: Public-only sitemap and IndexNow filtering ---
$GLOBALS['cache']->forums = array(
    1 => array('fid' => 1, 'type' => 'c', 'active' => 1, 'password' => '', 'parentlist' => '1'),
    2 => array('fid' => 2, 'name' => 'Public Forum', 'type' => 'f', 'active' => 1, 'password' => '', 'parentlist' => '1,2'),
    3 => array('fid' => 3, 'type' => 'f', 'active' => 1, 'password' => 'secret', 'parentlist' => '1,3'),
    4 => array('fid' => 4, 'type' => 'f', 'active' => 1, 'password' => '', 'parentlist' => '1,4'),
    5 => array('fid' => 5, 'type' => 'f', 'active' => 1, 'password' => '', 'parentlist' => '1,5'),
    6 => array('fid' => 6, 'type' => 'f', 'active' => 1, 'password' => '', 'parentlist' => '1,6'),
    7 => array('fid' => 7, 'type' => 'c', 'active' => 1, 'password' => 'parent-secret', 'parentlist' => '7'),
    8 => array('fid' => 8, 'type' => 'f', 'active' => 1, 'password' => '', 'parentlist' => '7,8'),
    9 => array('fid' => 9, 'type' => 'f', 'active' => 1, 'password' => '', 'parentlist' => '1,9', 'linkto' => 'https://example.net/')
);
$GLOBALS['test_inactive_forums'] = '4';
$GLOBALS['test_forum_permissions'] = array(
    2 => array('canview' => 1, 'canviewthreads' => 1),
    3 => array('canview' => 1, 'canviewthreads' => 1),
    4 => array('canview' => 1, 'canviewthreads' => 1),
    5 => array('canview' => 1, 'canviewthreads' => 1, 'canonlyviewownthreads' => 1),
    6 => array('canview' => 0, 'canviewthreads' => 0),
    8 => array('canview' => 1, 'canviewthreads' => 1),
    9 => array('canview' => 1, 'canviewthreads' => 1)
);
$sitemap = new InspectableSitemap($url);
$t->assert($sitemap->getPublicForumIds() === array(2), 'Sitemap includes only guest-visible active forums');
$t->assert(VonSEO_Utils::forumHasPasswordBarrier($GLOBALS['cache']->forums[8], $GLOBALS['cache']->forums), 'Child forum inherits its parent password barrier');
$t->assert(VonSEO_Utils::forumHasPasswordBarrier(array('fid' => 9, 'password' => '', 'parentlist' => '7,bad,9'), $GLOBALS['cache']->forums), 'Malformed parent chain fails closed for guest-facing URLs');

$GLOBALS['db']->threads = array(
    10 => array('tid' => 10, 'fid' => 2, 'visible' => 1, 'closed' => ''),
    11 => array('tid' => 11, 'fid' => 3, 'visible' => 1, 'closed' => ''),
    12 => array('tid' => 12, 'fid' => 2, 'visible' => 0, 'closed' => ''),
    13 => array('tid' => 13, 'fid' => 2, 'visible' => 1, 'closed' => 'moved|14'),
    14 => array('tid' => 14, 'fid' => 8, 'visible' => 1, 'closed' => '')
);
$recordingIndexNow = new RecordingIndexNow($url);
$t->assert($recordingIndexNow->submitThread(10) === true && $recordingIndexNow->submitted === array($url->thread(10)), 'IndexNow submits a public visible thread');
$recordingIndexNow->submitted = array();
$t->assert($recordingIndexNow->submitThread(11) === false && !$recordingIndexNow->submitted, 'IndexNow rejects a password-protected forum thread');
$t->assert($recordingIndexNow->submitThread(12) === false && !$recordingIndexNow->submitted, 'IndexNow rejects an unapproved thread');
$t->assert($recordingIndexNow->submitThread(13) === false && !$recordingIndexNow->submitted, 'IndexNow rejects a moved-thread stub');
$t->assert($recordingIndexNow->submitThread(14) === false && !$recordingIndexNow->submitted, 'IndexNow rejects a thread below a passworded parent');
$queuedIndexNow = new RecordingQueuedIndexNow($url);
$t->assert($queuedIndexNow->queueThread(10) === true && array_slice($queuedIndexNow->queued, 0, 3) === array(10, $url->thread(10), 'current'), 'IndexNow content hooks queue a public thread identity without submitting inline');
$queuedIndexNow->queued = array();
$t->assert($queuedIndexNow->queueThread(11) === false && !$queuedIndexNow->queued, 'IndexNow queue rejects a protected thread before storing it');
$runtimeDb = $GLOBALS['db'];
$queueDb = new MockQueueDb();
$queueDb->threads = array(
    10 => array('tid' => 10, 'fid' => 2, 'visible' => 1, 'closed' => '', 'subject' => 'Current Thread'),
    15 => array('tid' => 15, 'fid' => 2, 'visible' => 1, 'closed' => '', 'subject' => 'Second Thread'),
    16 => array('tid' => 16, 'fid' => 2, 'visible' => 1, 'closed' => '', 'subject' => 'Retry Thread')
);
$queueDb->posts = array(
    101 => array('pid' => 101, 'tid' => 10, 'visible' => 1),
    102 => array('pid' => 102, 'tid' => 10, 'visible' => 0)
);
$queueDb->rows = array(
    1 => array('qid' => 1, 'tid' => 10, 'generation' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'attempts' => 0, 'available_at' => TIME_NOW),
    2 => array('qid' => 2, 'tid' => 15, 'generation' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'attempts' => 1, 'available_at' => TIME_NOW)
);
$GLOBALS['db'] = $queueDb;
$GLOBALS['cache']->data = array();
VonSEO_State::resetRuntime();
$processingIndexNow = new RecordingProcessingIndexNow($url);
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '1';
$processed = $processingIndexNow->processQueue(25);
$t->assert($processed['processed'] === 2 && $processed['success'] && $processed['remaining'] === 0 && !$queueDb->rows &&
    $processingIndexNow->submitted[0] === 'https://example.com/forum/t-10-current-thread',
    'Successful IndexNow batch re-resolves current slugs and removes its selected generations');
$t->assert(isset($queueDb->history[10]['last_submitted_at']) && $queueDb->history[10]['last_submitted_at'] === TIME_NOW,
    'Successful delivery records the per-thread cooldown timestamp');
$queueDb->rows = array(
    3 => array('qid' => 3, 'tid' => 16, 'generation' => 'cccccccccccccccccccccccccccccccc', 'attempts' => 0, 'available_at' => TIME_NOW)
);
$processingIndexNow->submitResult = false;
$failedBatch = $processingIndexNow->processQueue(25);
$queueState = VonSEO_State::get();
$t->assert(!$failedBatch['success'] && $failedBatch['remaining'] === 1 && $queueDb->rows[3]['attempts'] === 1 && $queueDb->rows[3]['retry_not_before'] > TIME_NOW && !empty($queueState['indexnow_last_failure']), 'Failed IndexNow task batch stays queued with backoff and diagnostic state');
$remoteRetryBoundary = $queueDb->rows[3]['retry_not_before'];
$remoteError = $queueDb->rows[3]['last_error'];
$backoffIndexNow = new VonSEO_IndexNow($url);
$t->assert($backoffIndexNow->queueThread(16, 'reply') && $queueDb->rows[3]['attempts'] === 1 &&
    $queueDb->rows[3]['retry_not_before'] === $remoteRetryBoundary && $queueDb->rows[3]['last_error'] === $remoteError,
    'New content cannot reset a 5xx retry boundary or its attempt count');
$queueDb->rows = array(
    5 => array('qid' => 5, 'tid' => 16, 'generation' => 'abababababababababababababababab', 'event_type' => 'current', 'url' => '', 'attempts' => 3, 'available_at' => TIME_NOW)
);
$processingIndexNow->submissionResult = array('success' => false, 'class' => 'config_error', 'message' => 'Missing key.', 'http_status' => 403, 'retry_after' => 300, 'consume_attempt' => false);
$configFailure = $processingIndexNow->processQueue(25);
$t->assert(!$configFailure['success'] && $configFailure['status'] === 'config_error' && $queueDb->rows[5]['attempts'] === 3 && $queueDb->rows[5]['retry_not_before'] === TIME_NOW + 300,
    'IndexNow configuration errors retain the queue without burning retry attempts');
$configRetryBoundary = $queueDb->rows[5]['retry_not_before'];
$t->assert($backoffIndexNow->queueThread(16, 'edit') && $queueDb->rows[5]['attempts'] === 3 &&
    $queueDb->rows[5]['retry_not_before'] === $configRetryBoundary && $queueDb->rows[5]['last_error'] === 'Missing key.',
    'New content cannot resume a queue paused by an IndexNow configuration error');
$queueDb->rows[5]['available_at'] = TIME_NOW;
$queueDb->rows[5]['retry_not_before'] = TIME_NOW;
$processingIndexNow->submissionResult = array('success' => false, 'class' => 'rate_limited', 'message' => 'Rate limited.', 'http_status' => 429, 'retry_after' => 1800, 'consume_attempt' => false);
$rateLimited = $processingIndexNow->processQueue(25);
$t->assert(!$rateLimited['success'] && $rateLimited['status'] === 'rate_limited' && $queueDb->rows[5]['attempts'] === 3 && $queueDb->rows[5]['retry_not_before'] === TIME_NOW + 1800,
    'IndexNow HTTP 429 honours Retry-After without consuming retries');
$rateRetryBoundary = $queueDb->rows[5]['retry_not_before'];
$t->assert($backoffIndexNow->queueThread(16, 'reply') && $queueDb->rows[5]['attempts'] === 3 &&
    $queueDb->rows[5]['retry_not_before'] === $rateRetryBoundary && $queueDb->rows[5]['last_error'] === 'Rate limited.',
    'New content cannot shorten an active HTTP 429 Retry-After boundary');
$processingIndexNow->submissionResult = null;
$queueDb->rows = array(
    4 => array('qid' => 4, 'tid' => 16, 'generation' => 'dddddddddddddddddddddddddddddddd', 'attempts' => 9, 'available_at' => TIME_NOW)
);
$retryCap = $processingIndexNow->processQueue(25);
$t->assert(!$retryCap['success'] && $retryCap['dropped'] === 1 && !$queueDb->rows, 'IndexNow drops a row after the bounded retry limit instead of retrying forever');
$queueDb->rows = array(
    9 => array('qid' => 9, 'tid' => 10, 'generation' => 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', 'attempts' => 0, 'available_at' => TIME_NOW)
);
$concurrentIndexNow = new ConcurrentProcessingIndexNow($url);
$concurrentIndexNow->queueDb = $queueDb;
$concurrentIndexNow->processQueue(25);
$t->assert(isset($queueDb->rows[9]) && $queueDb->rows[9]['generation'] === 'ffffffffffffffffffffffffffffffff' && $queueDb->rows[9]['available_at'] === TIME_NOW + 300, 'A fresh concurrent generation survives and is cooled down after an older successful batch');
$queueDb->rows = array(
    12 => array('qid' => 12, 'tid' => 15, 'generation' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'event_type' => 'current', 'url' => '', 'attempts' => 2, 'available_at' => TIME_NOW, 'retry_not_before' => 0)
);
$concurrentFailureIndexNow = new ConcurrentFailureProcessingIndexNow($url);
$concurrentFailureIndexNow->queueDb = $queueDb;
$concurrentFailureIndexNow->processQueue(25);
$t->assert(isset($queueDb->rows[12]) && $queueDb->rows[12]['generation'] === '12121212121212121212121212121212' &&
    $queueDb->rows[12]['attempts'] === 3 && $queueDb->rows[12]['retry_not_before'] > TIME_NOW,
    'A concurrent content generation survives a failed delivery without bypassing its retry boundary');
$queueDb->history[17] = array('tid' => 17, 'last_public_url' => 'https://example.com/forum/t-17-removed-thread', 'last_submitted_at' => TIME_NOW - 60, 'updated_at' => TIME_NOW - 60);
$processingIndexNow->submitResult = true;
$queueDb->rows = array(
    11 => array('qid' => 11, 'tid' => 17, 'generation' => '17171717171717171717171717171717', 'event_type' => 'removed', 'url' => 'https://example.com/forum/t-17-removed-thread', 'attempts' => 0, 'available_at' => TIME_NOW)
);
$removedDelivery = $processingIndexNow->processQueue(25);
$t->assert($removedDelivery['success'] && $processingIndexNow->submitted === array('https://example.com/forum/t-17-removed-thread') && !isset($queueDb->history[17]) && !$queueDb->rows,
    'Removed public thread URL is submitted without requiring the deleted entity and then clears its history');
$queueDb->rows = array(
    10 => array('qid' => 10, 'tid' => 999, 'generation' => '99999999999999999999999999999999', 'attempts' => 0, 'available_at' => TIME_NOW)
);
$processingIndexNow->submitResult = true;
$staleEntity = $processingIndexNow->processQueue(25);
$t->assert($staleEntity['dropped'] === 1 && !$queueDb->rows, 'IndexNow drops a deleted or no-longer-public thread before submission');
$queueDb->history[10] = array('tid' => 10, 'last_public_url' => $url->thread(10), 'last_submitted_at' => TIME_NOW - 60, 'updated_at' => TIME_NOW - 60);
$cooldownIndexNow = new RecordingQueuedIndexNow($url);
$t->assert($cooldownIndexNow->queueThread(10, 'reply') && $cooldownIndexNow->queued[3] === TIME_NOW + 240,
    'Reply/edit events use the remaining five-minute same-thread cooldown');
$cooldownIndexNow->queued = array();
$t->assert($cooldownIndexNow->queueThread(10, 'new_thread') && $cooldownIndexNow->queued[3] === TIME_NOW,
    'New thread events remain eligible for immediate delivery');
$t->assert($cooldownIndexNow->queuePost(101, 'reply') && !$cooldownIndexNow->queuePost(102, 'reply'),
    'Public replies queue their parent while unapproved replies do not ping IndexNow');
$classifier = new InspectableIndexNow($url);
$t->assert($classifier->classify(403)['class'] === 'config_error' && !$classifier->classify(403)['consume_attempt'], 'HTTP 403 is classified as a retained configuration error');
$t->assert($classifier->classify(429, 1200)['class'] === 'rate_limited' && $classifier->classify(429, 1200)['retry_after'] === 1200, 'HTTP 429 is classified separately and preserves Retry-After');
$t->assert($classifier->classify(503)['class'] === 'remote_error' && $classifier->classify(503)['consume_attempt'], 'HTTP 5xx is classified as a transient remote failure');
$GLOBALS['db'] = $runtimeDb;
$GLOBALS['cache']->data = array(
    'vonseo_state_active_redirects' => array('value' => 0),
    'vonseo_state_indexnow_queue_depth' => array('value' => 0)
);
VonSEO_State::resetRuntime();
VonSEO_State::get(); // Simulate request A reading an old snapshot.
$GLOBALS['cache']->data['vonseo_state_active_redirects'] = array('value' => 1); // Request B update.
VonSEO_State::merge(array('indexnow_queue_depth' => 5)); // Request A writes another field.
VonSEO_State::resetRuntime();
$isolatedState = VonSEO_State::get();
$t->assert($isolatedState['active_redirects'] === 1 && $isolatedState['indexnow_queue_depth'] === 5, 'Independent state keys prevent a stale request from overwriting another subsystem');
$GLOBALS['db']->threads[10]['subject'] = 'Modern SEO Discussion';
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '1';
$t->assert($recordingIndexNow->submitThread(10) === true && $recordingIndexNow->submitted === array('https://example.com/forum/t-10-modern-seo-discussion'), 'IndexNow submits the keyword canonical URL for a public thread');
$previousScriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : null;
$_SERVER['SCRIPT_NAME'] = '/showthread.php';
$GLOBALS['raw_thread_cache'][10] = array('tid' => 10, 'fid' => 2, 'subject' => 'Café & Teh');
$GLOBALS['thread'] = array('tid' => 10, 'fid' => 2, 'subject' => 'Caf*** &amp; Teh', 'visible' => 1, 'closed' => '', 'firstpost' => 0);
$GLOBALS['forum'] = $GLOBALS['cache']->forums[2];
$displayContext = (new VonSEO_Context($url))->resolve();
$t->assert($displayContext->canonical === 'https://example.com/forum/t-10-caf%C3%A9-teh', 'Thread canonical uses MyBB cached raw subject, not a filtered display subject');
$GLOBALS['raw_thread_cache'][14] = array('tid' => 14, 'fid' => 8, 'subject' => 'Hidden Child Thread');
$GLOBALS['thread'] = array('tid' => 14, 'fid' => 8, 'subject' => 'Hidden Child Thread', 'visible' => 1, 'closed' => '', 'firstpost' => 0);
$GLOBALS['forum'] = $GLOBALS['cache']->forums[8];
$privateContext = (new VonSEO_Context($url))->resolve();
$t->assert($privateContext->indexable === false, 'Thread below a passworded parent is not indexable');
$privateHtml = (new VonSEO_Core())->render('<html><head><title>Access required</title></head><body>Protected</body></html>');
$t->assert(strpos($privateHtml, '<title>Access required</title>') !== false && strpos($privateHtml, 'Hidden Child Thread') === false && strpos($privateHtml, 'rel="canonical"') === false, 'Protected page keeps its access title and gets no keyword canonical');
$_SERVER['SCRIPT_NAME'] = '/forumdisplay.php';
$privateForumContext = (new VonSEO_Context($url))->resolve();
$t->assert($privateForumContext->indexable === false, 'Forum below a passworded parent is not indexable');
$GLOBALS['raw_thread_cache'][10]['subject'] = 'Price $1.00 \\1 $0';
$GLOBALS['thread'] = array('tid' => 10, 'fid' => 2, 'subject' => 'Price $1.00 \\1 $0', 'visible' => 1, 'closed' => '', 'firstpost' => 0);
$GLOBALS['forum'] = $GLOBALS['cache']->forums[2];
$_SERVER['SCRIPT_NAME'] = '/showthread.php';
$literalHtml = (new VonSEO_Core())->render('<html><head><title>Old</title></head><body>Public</body></html>');
$t->assert(strpos($literalHtml, '<title>Price $1.00 \\1 $0 - Community Forum</title>') !== false &&
    substr_count($literalHtml, '<head>') === 1 && substr_count($literalHtml, '</head>') === 1,
    'Dynamic metadata containing replacement tokens stays literal and cannot corrupt the head');
$scriptSafeContext = new VonSEO_Context($url);
$scriptSafeContext->type = 'announcement';
$scriptSafeContext->indexable = true;
$scriptSafeContext->title = 'Script boundary check';
$scriptSafeContext->description = 'Safe JSON-LD';
$scriptSafeContext->canonical = 'https://example.com/forum/announcements.php?aid=99';
$scriptSafeContext->data = array(
    'announcement' => array('aid' => 99, 'subject' => 'Script boundary check', 'startdate' => TIME_NOW, 'username' => 'Tester'),
    'announcement_text' => '</script><script>alert(1)</script>'
);
$scriptSafeMeta = (new VonSEO_Meta(new VonSEO_Robots(), new VonSEO_Social(), new VonSEO_Schema($url)))->render($scriptSafeContext);
$t->assert(strpos($scriptSafeMeta, '</script><script>') === false && strpos($scriptSafeMeta, '\\u003C/script\\u003E') !== false,
    'JSON-LD hex-escapes tag boundaries without slash-escape source patterns');
$previousPage = isset($GLOBALS['page']) ? $GLOBALS['page'] : null;
$GLOBALS['page'] = 2;
$GLOBALS['mybb']->input = array('fid' => 2, 'page' => 999999);
$_SERVER['SCRIPT_NAME'] = '/forumdisplay.php';
$resolvedForumPage = (new VonSEO_Context($url))->resolve();
$t->assert($resolvedForumPage->canonical === 'https://example.com/forum/f-2-public-forum--p2', 'Forum canonical trusts the page MyBB actually resolved');
$t->assert($resolvedForumPage->title === 'Public Forum - Page 2 - Community Forum', 'Forum pagination title includes the resolved page number');
$GLOBALS['page'] = 3;
$GLOBALS['mybb']->input = array('page' => 999999);
$_SERVER['SCRIPT_NAME'] = '/portal.php';
$resolvedPortalPage = (new VonSEO_Context($url))->resolve();
$t->assert($resolvedPortalPage->canonical === 'https://example.com/forum/portal.php?page=3', 'Portal pagination has a distinct canonical based on MyBB resolved page');
$t->assert($resolvedPortalPage->title === 'Portal - Page 3 - Community Forum', 'Portal pagination title includes the resolved page number');
$GLOBALS['mybb']->settings['vonseo_home_title'] = 'Retired duplicate title';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$resolvedHome = (new VonSEO_Context($url))->resolve();
$t->assert($resolvedHome->title === 'Community Forum', 'Homepage title always uses the trimmed MyBB Board Name and ignores a stale legacy override');
unset($GLOBALS['mybb']->settings['vonseo_home_title']);
if($previousPage === null)
{
    unset($GLOBALS['page']);
}
else
{
    $GLOBALS['page'] = $previousPage;
}
if($previousScriptName === null)
{
    unset($_SERVER['SCRIPT_NAME']);
}
else
{
    $_SERVER['SCRIPT_NAME'] = $previousScriptName;
}
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '0';

// --- TEST 10: Public-resource bounds ---
$errorDb = new Mock404Db();
$GLOBALS['db'] = $errorDb;
$GLOBALS['cache']->data = array();
VonSEO_State::resetRuntime();
$_SERVER['SCRIPT_NAME'] = '/showthread.php';
$_SERVER['REQUEST_URI'] = '/forum/showthread.php?tid=123';
$t->assert(VonSEO_Errors::requestPath() === '/showthread.php?tid=123', '404 identity preserves a native thread query string');
$_SERVER['SCRIPT_NAME'] = '/forumdisplay.php';
$_SERVER['REQUEST_URI'] = '/forum/forumdisplay.php?fid=4&page=2';
$t->assert(VonSEO_Errors::requestPath() === '/forumdisplay.php?fid=4&page=2', '404 identity preserves forum pagination query parameters');
$_SERVER['SCRIPT_NAME'] = '/member.php';
$_SERVER['REQUEST_URI'] = '/forum/member.php?action=profile&uid=9';
$t->assert(VonSEO_Errors::requestPath() === '/member.php?action=profile&uid=9', '404 identity preserves member profile query parameters');
$_SERVER['REQUEST_URI'] = '/forum/member.php?action=resetpassword&token=private-value&uid=9';
$t->assert(VonSEO_Errors::requestPath() === '/member.php', '404 identity discards non-public actions and all associated secret-bearing query values');
$_SERVER['SCRIPT_NAME'] = '/showthread.php';
$_SERVER['REQUEST_URI'] = '/forum/showthread.php?utm_source=test&page=2&token=secret&tid=123';
$t->assert(VonSEO_Errors::requestPath() === '/showthread.php?tid=123&page=2', '404 identity uses deterministic public-field order and discards tracking or secret fields');
$_SERVER['SCRIPT_NAME'] = '/calendar.php';
$_SERVER['REQUEST_URI'] = '/forum/calendar.php?action=event&eid=14&token=secret&calendar=2';
$t->assert(VonSEO_Errors::requestPath() === '/calendar.php?action=event&eid=14&calendar=2', '404 identity keeps only public calendar event fields and discards secret values');
$_SERVER['SCRIPT_NAME'] = '/showthread.php';
$GLOBALS['mybb']->input = array('tid' => 123);
$GLOBALS['lang'] = new stdClass();
$GLOBALS['lang']->error_invalidthread = 'The specified thread does not exist.';
$GLOBALS['lang']->error_invalidevent = 'The specified event does not exist.';
$GLOBALS['lang']->invalid_calendar = 'The specified calendar does not exist.';
$GLOBALS['mybb']->settings['vonseo_404_monitor'] = '0';
unset($GLOBALS['vonseo_missing_error_seen'], $GLOBALS['vonseo_forced_status'], $GLOBALS['vonseo_no_permission']);
$GLOBALS['vonseo_error_seen'] = false;
VonSEO_Errors::markError('A validation error unrelated to entity existence.');
$genericStatus = VonSEO_Errors::prepareResponse();
$t->assert($genericStatus === 0, 'Unrelated MyBB errors are noindexed without being mislabeled HTTP 404', (string)$genericStatus);
unset($GLOBALS['vonseo_missing_error_seen']);
$GLOBALS['vonseo_error_seen'] = false;
VonSEO_Errors::markError($GLOBALS['lang']->error_invalidthread);
$missingStatus = VonSEO_Errors::prepareResponse();
$t->assert($missingStatus === 404, 'Known missing-thread errors receive a real HTTP 404 response', (string)$missingStatus);
unset($GLOBALS['vonseo_missing_error_seen'], $GLOBALS['vonseo_error_seen']);
$_SERVER['SCRIPT_NAME'] = '/calendar.php';
$GLOBALS['mybb']->input = array('action' => 'event', 'eid' => 14);
VonSEO_Errors::markError($GLOBALS['lang']->error_invalidevent);
$missingEventStatus = VonSEO_Errors::prepareResponse();
$t->assert($missingEventStatus === 404, 'Known missing-event errors receive a real HTTP 404 response', (string)$missingEventStatus);
unset($GLOBALS['vonseo_missing_error_seen'], $GLOBALS['vonseo_error_seen']);
$GLOBALS['mybb']->settings['vonseo_404_monitor'] = '1';
$_SERVER['REQUEST_URI'] = '/forum/unique-missing-page';
$errorDb->count = 9999;
VonSEO_Errors::log404();
$t->assert(count($errorDb->inserted) === 1 && $errorDb->inserted[0]['path'] === '/unique-missing-page'
    && $errorDb->locks === 1 && $errorDb->releases === 1 && $errorDb->count === 10000,
    '404 monitor serializes and records a normal board-relative missing path');
$errorDb->count = 10000;
$_SERVER['REQUEST_URI'] = '/forum/another-unique-page';
VonSEO_Errors::log404();
$t->assert(count($errorDb->inserted) === 2 && $errorDb->pruned === 1 && $errorDb->count === 9500
    && $errorDb->locks === 2 && $errorDb->releases === 2,
    '404 monitor prunes under its admission lock before inserting at the hard cap');
$errorDb->existingHash = hash('sha256', '/unique-missing-page');
$_SERVER['REQUEST_URI'] = '/forum/unique-missing-page';
$locksBeforeExisting = $errorDb->locks;
VonSEO_Errors::log404();
$t->assert($errorDb->updated === 1 && $errorDb->locks === $locksBeforeExisting,
    '404 monitor increments an existing row without entering the new-row lock');
$errorDb->existingHash = '';
$errorDb->lockAvailable = false;
$_SERVER['REQUEST_URI'] = '/forum/busy-admission-lock';
$insertsBeforeBusyLock = count($errorDb->inserted);
VonSEO_Errors::log404();
$t->assert(count($errorDb->inserted) === $insertsBeforeBusyLock && $errorDb->releases === 2,
    '404 monitor drops optional new-path telemetry when the non-blocking admission lock is busy');
$errorDb->lockAvailable = true;
$selectsBeforeOversize = $errorDb->selects;
$_SERVER['REQUEST_URI'] = '/forum/'.str_repeat('x', 769);
VonSEO_Errors::log404();
$t->assert($errorDb->selects === $selectsBeforeOversize && count($errorDb->inserted) === 2, 'Oversized 404 path is rejected before database queries');

$sitemapDb = new MockSitemapDb();
$sitemapDb->announcementRows = array(
    array('aid' => 70),
    array('aid' => 71)
);
$sitemapDb->calendarRows = array(array('cid' => 2), array('cid' => 3));
$sitemapDb->calendarPermissions = array(3 => 0);
$sitemapDb->eventCount = 1;
$sitemapDb->eventRows = array(array('eid' => 80, 'dateline' => TIME_NOW - 60));
$GLOBALS['db'] = $sitemapDb;
$GLOBALS['mybb']->input = array('type' => 'threads', 'page' => '2147483647');
ob_start();
(new VonSEO_Sitemap($url))->output();
$largePageXml = ob_get_clean();
$t->assert(!$sitemapDb->offsets && strpos($largePageXml, '<urlset') !== false && $GLOBALS['vonseo_sitemap_status'] === 404, 'Out-of-range sitemap page returns 404 XML without a deep-offset query');
$GLOBALS['mybb']->input['page'] = '2';
ob_start();
(new VonSEO_Sitemap($url))->output();
$validPageXml = ob_get_clean();
$t->assert($sitemapDb->offsets === array(1000) && strpos($validPageXml, 'showthread.php?tid=10') !== false, 'Valid sitemap page keeps public thread output');
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '1';
$GLOBALS['mybb']->input['page'] = '1';
ob_start();
(new VonSEO_Sitemap($url))->output();
$keywordPageXml = ob_get_clean();
$t->assert(strpos($keywordPageXml, 't-10-modern-seo-discussion') !== false, 'Thread sitemap uses the same keyword canonical URL');
$GLOBALS['mybb']->input = array('type' => 'forums');
ob_start();
(new VonSEO_Sitemap($url))->output();
$keywordForumXml = ob_get_clean();
$t->assert(strpos($keywordForumXml, 'f-2-public-forum') !== false && strpos($keywordForumXml, 'f-3-') === false, 'Forum sitemap uses a keyword URL only for a guest-visible forum');
$t->assert(strpos($keywordForumXml, 'f-8-') === false, 'Forum sitemap excludes a child below a passworded parent');
$t->assert(strpos($keywordForumXml, 'f-9-') === false, 'Forum sitemap excludes link forums even when guest permissions allow them');
$GLOBALS['mybb']->input = array('type' => 'announcements', 'page' => '1');
ob_start();
(new VonSEO_Sitemap($url))->output();
$announcementXml = ob_get_clean();
$announcementWhere = implode(' ', $sitemapDb->announcementConditions);
$t->assert(strpos($announcementXml, 'announcements.php?aid=70') !== false &&
    strpos($announcementXml, 'announcements.php?aid=71') !== false,
    'Announcement sitemap emits active global and guest-visible announcement URLs');
$t->assert(strpos($announcementWhere, 'fid<=0 OR fid IN (2)') !== false &&
    strpos($announcementWhere, "startdate<='".TIME_NOW."'") !== false &&
    strpos($announcementWhere, "enddate>='".TIME_NOW."'") !== false,
    'Announcement sitemap query is bounded to active global or guest-visible forums');
$GLOBALS['mybb']->input = array('type' => 'index');
ob_start();
(new VonSEO_Sitemap($url))->output();
$announcementIndexXml = ob_get_clean();
$t->assert(strpos($announcementIndexXml, 'type=announcements&amp;page=1') !== false,
    'Sitemap index advertises the announcement sitemap through the shared endpoint');
$t->assert(strpos($announcementIndexXml, 'type=calendars') !== false &&
    strpos($announcementIndexXml, 'type=events&amp;page=1') !== false,
    'Sitemap index advertises public calendars and public event chunks');
$GLOBALS['mybb']->input = array('type' => 'calendars');
ob_start();
(new VonSEO_Sitemap($url))->output();
$calendarXml = ob_get_clean();
$t->assert(strpos($calendarXml, 'calendar.php?calendar=2') !== false &&
    strpos($calendarXml, 'calendar.php?calendar=3') === false,
    'Calendar sitemap includes only calendars visible to the Guest group');
$GLOBALS['mybb']->input = array('type' => 'events', 'page' => '1');
ob_start();
(new VonSEO_Sitemap($url))->output();
$eventXml = ob_get_clean();
$eventWhere = implode(' ', $sitemapDb->eventConditions);
$t->assert(strpos($eventXml, 'calendar.php?action=event&amp;eid=80') !== false &&
    strpos($eventXml, '<lastmod>') === false,
    'Event sitemap omits an untrustworthy lastmod because MyBB stores only creation time');
$t->assert(strpos($eventWhere, "visible='1'") !== false &&
    strpos($eventWhere, "private='0'") !== false && strpos($eventWhere, 'cid IN (2)') !== false,
    'Event sitemap query excludes unapproved, private and guest-denied calendar events');
$eventOffsetsBefore = count($sitemapDb->eventOffsets);
$GLOBALS['mybb']->input = array('type' => 'events', 'page' => '2');
ob_start();
(new VonSEO_Sitemap($url))->output();
$outOfRangeEventXml = ob_get_clean();
$t->assert(count($sitemapDb->eventOffsets) === $eventOffsetsBefore &&
    $GLOBALS['vonseo_sitemap_status'] === 404 && strpos($outOfRangeEventXml, '<urlset') !== false,
    'Out-of-range event sitemap page returns 404 without an offset query');
$announcementOffsetsBefore = count($sitemapDb->announcementOffsets);
$GLOBALS['mybb']->input = array('type' => 'announcements', 'page' => '2');
ob_start();
(new VonSEO_Sitemap($url))->output();
$outOfRangeAnnouncementXml = ob_get_clean();
$t->assert(count($sitemapDb->announcementOffsets) === $announcementOffsetsBefore &&
    $GLOBALS['vonseo_sitemap_status'] === 404 && strpos($outOfRangeAnnouncementXml, '<urlset') !== false,
    'Out-of-range announcement sitemap page returns 404 without an offset query');
$GLOBALS['cache']->data = array();
$sitemapDb->announcementCountQueries = 0;
$inspectableSitemap = new InspectableSitemap($url);
$inspectableSitemap->getAnnouncementCount("startdate<='100' AND fid IN (2)", array(2));
$inspectableSitemap->getAnnouncementCount("startdate<='101' AND fid IN (2)", array(2));
$inspectableSitemap->getAnnouncementCount("startdate<='102' AND fid IN (2,4)", array(4, 2));
$announcementCacheKeys = preg_grep('/^vonseo_sitemap_announcement_count/', array_keys($GLOBALS['cache']->data));
$t->assert($sitemapDb->announcementCountQueries === 2 && count($announcementCacheKeys) === 1,
    'Announcement count cache reuses one key across timestamps and refreshes only when guest forum scope changes');
$GLOBALS['mybb']->input = array('type' => 'garbage');
ob_start();
(new VonSEO_Sitemap($url))->output();
$invalidSitemapXml = ob_get_clean();
$t->assert($GLOBALS['vonseo_sitemap_status'] === 404 && strpos($invalidSitemapXml, '<sitemapindex') === false, 'Unknown sitemap types return 404 empty XML instead of the sitemap index');
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '0';

// --- TEST 11: Plugin installation and upgrade repair (mock database) ---
require_once MYBB_ROOT.'inc/plugins/vonseo.php';
$t->assert(vonseo_info()['version'] === '1.0.2', 'Source plugin metadata matches the 1.0.2 release');
$pluginSource = file_get_contents(MYBB_ROOT.'inc/plugins/vonseo.php');
$t->assert(strpos($pluginSource, "add_hook('postbit_announcement', 'vonseo_capture_guest_announcement'") !== false,
    'Announcement text capture is registered on MyBB postbit_announcement');
$t->assert(strpos($pluginSource, "add_hook('calendar_end', 'vonseo_capture_guest_calendar'") !== false &&
    strpos($pluginSource, "add_hook('calendar_event_end', 'vonseo_capture_guest_event'") !== false,
    'Calendar and event capture hooks are registered before MyBB replaces their data arrays with HTML');
$adminSource = file_get_contents(MYBB_ROOT.'admin/modules/config/vonseo.php');
$t->assert(substr_count($adminSource, "verify_post_check(\$mybb->get_input('my_post_key'));") === 7,
    'Every VonSEO ACP mutation keeps an explicit local post-token check in addition to MyBB global protection');
$t->assert(strpos($adminSource, 'Review SEO status on the Overview page') !== false &&
    strpos($adminSource, 'Open sitemap (raw XML)') !== false &&
    strpos($adminSource, 'Open robots rules (plain text)') !== false &&
    strpos($adminSource, 'vs-preview-source') !== false && strpos($adminSource, 'vs-preview-target') !== false,
    'ACP guidance names endpoint response types and redirect URL previews clearly');
$permissionMap = vonseo_admin_permissions(array());
$t->assert(isset($permissionMap['vonseo'], $permissionMap['vonseo_settings']) &&
    strpos($permissionMap['vonseo_settings'], 'Site Details') !== false &&
    strpos($permissionMap['vonseo_settings'], 'IndexNow key') !== false,
    'ACP exposes a separate permission for Site Details and IndexNow key rotation');

$announcementScriptBefore = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : null;
$announcementInputBefore = $GLOBALS['mybb']->input;
$announcementUserBefore = $GLOBALS['mybb']->user;
$announcementForumBefore = isset($GLOBALS['forum']) ? $GLOBALS['forum'] : null;
$_SERVER['SCRIPT_NAME'] = '/announcements.php';
$GLOBALS['mybb']->input = array('aid' => 30);
$GLOBALS['mybb']->user = array('uid' => 0);
$GLOBALS['forum'] = $GLOBALS['cache']->forums[2];
$GLOBALS['announcementarray'] = array(
    'aid' => 30,
    'fid' => 2,
    'uid' => 7,
    'username' => 'Vondereich',
    'subject' => 'Maintenance Notice',
    'message' => '[b]Raw MyCode must not become schema text[/b]',
    'startdate' => TIME_NOW - 3600,
    'enddate' => TIME_NOW + 86400
);
$renderedAnnouncement = array(
    'aid' => 30,
    'message' => '<p>Scheduled maintenance starts tonight.</p><blockquote>Public details remain available.</blockquote>'
);
$t->assert(vonseo_capture_guest_announcement($renderedAnnouncement) === $renderedAnnouncement,
    'Announcement postbit capture leaves MyBB data unchanged');
$announcementContext = (new VonSEO_Context($url))->resolve();
$announcementSchema = $schema->build($announcementContext);
$announcementArticle = isset($announcementSchema['@graph'][0]) ? $announcementSchema['@graph'][0] : array();
$announcementTags = $social->tags($announcementContext);
$announcementTagMap = array();
foreach($announcementTags as $tag)
{
    $announcementTagMap[$tag[1]] = $tag[2];
}
$t->assert($announcementContext->indexable && $announcementContext->type === 'announcement' &&
    $announcementContext->canonical === 'https://example.com/forum/announcements.php?aid=30' &&
    $announcementContext->title === 'Maintenance Notice - Community Forum',
    'Public active announcement receives canonical metadata through the shared URL resolver');
$t->assert(isset($announcementContext->data['announcement_text']) &&
    $announcementContext->data['announcement_text'] === 'Scheduled maintenance starts tonight. Public details remain available.' &&
    strpos($announcementContext->description, 'Raw MyCode') === false,
    'Announcement description uses guest-rendered text instead of raw database MyCode');
$t->assert(isset($announcementArticle['@type'], $announcementArticle['articleBody'], $announcementArticle['author']['name']) &&
    $announcementArticle['@type'] === 'Article' &&
    $announcementArticle['articleBody'] === $announcementContext->data['announcement_text'] &&
    $announcementArticle['author']['name'] === 'Vondereich' && !isset($announcementArticle['image']),
    'Announcement Article schema contains public text and author without mislabeling the default image');
$t->assert(isset($announcementTagMap['og:type'], $announcementTagMap['article:published_time'],
    $announcementTagMap['article:section'], $announcementTagMap['article:author']) &&
    $announcementTagMap['og:type'] === 'article' && $announcementTagMap['article:section'] === 'Public Forum' &&
    $announcementTagMap['article:author'] === 'Vondereich',
    'Announcement emits article social metadata for its public forum');

$GLOBALS['mybb']->input = array('aid' => 31);
$GLOBALS['forum'] = $GLOBALS['cache']->forums[3];
$GLOBALS['announcementarray'] = array(
    'aid' => 31, 'fid' => 3, 'uid' => 7, 'username' => 'Vondereich',
    'subject' => 'Protected Notice', 'message' => 'Secret',
    'startdate' => TIME_NOW - 3600, 'enddate' => 0
);
vonseo_capture_guest_announcement(array('aid' => 31, 'message' => '<p>Protected announcement text.</p>'));
$protectedAnnouncementContext = (new VonSEO_Context($url))->resolve();
$t->assert(!$protectedAnnouncementContext->indexable &&
    empty($protectedAnnouncementContext->data['announcement_text']) &&
    !$schema->build($protectedAnnouncementContext),
    'Password-protected forum announcement cannot leak into metadata or schema');

$GLOBALS['mybb']->input = array('aid' => 32);
$GLOBALS['forum'] = array();
$GLOBALS['announcementarray'] = array(
    'aid' => 32, 'fid' => -1, 'uid' => 7, 'username' => 'Vondereich',
    'subject' => 'Future Global Notice', 'message' => 'Not active',
    'startdate' => TIME_NOW + 3600, 'enddate' => 0
);
$futureAnnouncementContext = (new VonSEO_Context($url))->resolve();
$t->assert(!$futureAnnouncementContext->indexable && !$schema->build($futureAnnouncementContext),
    'Future global announcement stays non-indexable until MyBB makes it active');

$GLOBALS['mybb']->input = array('aid' => 33);
$GLOBALS['announcementarray'] = array(
    'aid' => 33, 'fid' => -1, 'uid' => 7, 'username' => 'Vondereich',
    'subject' => 'Global Notice', 'message' => 'Raw global notice',
    'startdate' => TIME_NOW - 7200, 'enddate' => 0
);
vonseo_capture_guest_announcement(array('aid' => 33, 'message' => '<p>Public board-wide announcement.</p>'));
$globalAnnouncementContext = (new VonSEO_Context($url))->resolve();
$globalAnnouncementSchema = $schema->build($globalAnnouncementContext);
$t->assert($globalAnnouncementContext->indexable &&
    $globalAnnouncementContext->data['announcement_text'] === 'Public board-wide announcement.' &&
    !empty($globalAnnouncementSchema['@graph']),
    'Active global announcement is indexable without a forum permission shortcut');

$GLOBALS['mybb']->input = array('aid' => 34);
$GLOBALS['announcementarray'] = array(
    'aid' => 34, 'fid' => -1, 'uid' => 7, 'username' => 'Vondereich',
    'subject' => 'Expired Notice', 'message' => 'Expired',
    'startdate' => TIME_NOW - 7200, 'enddate' => TIME_NOW - 1
);
$expiredAnnouncementContext = (new VonSEO_Context($url))->resolve();
$t->assert(!$expiredAnnouncementContext->indexable && !$schema->build($expiredAnnouncementContext),
    'Expired announcement is removed from indexable metadata');

unset($GLOBALS['announcementarray']);
$GLOBALS['mybb']->input = $announcementInputBefore;
$GLOBALS['mybb']->user = $announcementUserBefore;
if($announcementForumBefore === null)
{
    unset($GLOBALS['forum']);
}
else
{
    $GLOBALS['forum'] = $announcementForumBefore;
}
if($announcementScriptBefore === null)
{
    unset($_SERVER['SCRIPT_NAME']);
}
else
{
    $_SERVER['SCRIPT_NAME'] = $announcementScriptBefore;
}

$calendarScriptBefore = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : null;
$calendarInputBefore = $GLOBALS['mybb']->input;
$calendarUserBefore = $GLOBALS['mybb']->user;
$_SERVER['SCRIPT_NAME'] = '/calendar.php';
$GLOBALS['mybb']->user = array('uid' => 0);
$publicCalendar = array('cid' => 2, 'name' => 'Community Calendar');
$calendarPermissions = array('canviewcalendar' => 1, 'canmoderateevents' => 0);
$GLOBALS['mybb']->input = array('calendar' => 2, 'year' => 2026, 'month' => 9);
VonSEO_Context::captureGuestCalendar($publicCalendar, $calendarPermissions, 2026, 9, 'September 2026', true);
$calendarContext = (new VonSEO_Context($url))->resolve();
$calendarSchema = $schema->build($calendarContext);
$t->assert($calendarContext->indexable && $calendarContext->type === 'calendar' &&
    $calendarContext->canonical === 'https://example.com/forum/calendar.php?calendar=2&year=2026&month=9' &&
    $calendarContext->title === 'Community Calendar - September 2026 - Community Forum',
    'Guest-visible monthly calendar receives a resolved canonical and clear page title');
$t->assert(isset($calendarSchema['@graph'][0]['@type']) &&
    $calendarSchema['@graph'][0]['@type'] === 'CollectionPage',
    'Public monthly calendar emits CollectionPage schema with a breadcrumb');
$GLOBALS['mybb']->input = array('calendar' => 2, 'year' => 2026, 'month' => 3);
VonSEO_Context::captureGuestCalendar($publicCalendar, $calendarPermissions, 2026, 3, 'March 2026', true);
$currentCalendarContext = (new VonSEO_Context($url))->resolve();
$t->assert($currentCalendarContext->indexable &&
    $currentCalendarContext->canonical === 'https://example.com/forum/calendar.php?calendar=2',
    'Explicit current-month calendar consolidates onto the stable base canonical');
$GLOBALS['mybb']->user = array('uid' => 42);
$GLOBALS['mybb']->input = array('calendar' => 2, 'year' => 2026, 'month' => 9);
VonSEO_Context::captureGuestCalendar($publicCalendar, $calendarPermissions, 2026, 9, 'September 2026', true);
$loggedCalendarContext = (new VonSEO_Context($url))->resolve();
$t->assert($loggedCalendarContext->indexable &&
    $loggedCalendarContext->canonical === 'https://example.com/forum/calendar.php?calendar=2&year=2026&month=9',
    'Logged-in visitors receive the same public calendar indexability and canonical identity');
$GLOBALS['mybb']->user = array('uid' => 0);

$GLOBALS['mybb']->input = array('action' => 'event', 'eid' => 50);
$publicEvent = array(
    'eid' => 50,
    'cid' => 2,
    'uid' => 7,
    'username' => 'Vondereich',
    'name' => 'Open Community Meetup',
    'description' => '<p>Guest-rendered event details.</p>',
    'visible' => 1,
    'private' => 0,
    'starttime' => TIME_NOW + 3600,
    'endtime' => TIME_NOW + 7200,
    'dateline' => TIME_NOW - 60
);
VonSEO_Context::captureGuestEvent($publicEvent, $publicCalendar, $calendarPermissions);
$eventContext = (new VonSEO_Context($url))->resolve();
$eventSchema = $schema->build($eventContext);
$eventNode = isset($eventSchema['@graph'][0]) ? $eventSchema['@graph'][0] : array();
$t->assert($eventContext->indexable && $eventContext->type === 'event' &&
    $eventContext->canonical === 'https://example.com/forum/calendar.php?action=event&eid=50' &&
    $eventContext->data['event_text'] === 'Guest-rendered event details.',
    'Public event metadata uses only the description rendered by MyBB for a guest');
$t->assert(isset($eventNode['@type'], $eventNode['startDate'], $eventNode['endDate'],
    $eventNode['organizer']['name']) && $eventNode['@type'] === 'Event' &&
    $eventNode['organizer']['name'] === 'Vondereich' && count($eventSchema['@graph']) === 2,
    'Public event emits bounded Event schema and a calendar breadcrumb without fabricated location or offers');
$GLOBALS['mybb']->user = array('uid' => 42);
VonSEO_Context::captureGuestEvent($publicEvent, $publicCalendar, $calendarPermissions);
$loggedEventContext = (new VonSEO_Context($url))->resolve();
$loggedEventSchema = $schema->build($loggedEventContext);
$t->assert($loggedEventContext->indexable &&
    $loggedEventContext->canonical === 'https://example.com/forum/calendar.php?action=event&eid=50' &&
    $loggedEventContext->data['event_text'] === '' && !empty($loggedEventSchema['@graph']),
    'Logged-in public event keeps canonical/schema identity without reusing member-rendered text');
$GLOBALS['mybb']->user = array('uid' => 0);

$blockedEvent = $publicEvent;
$blockedEvent['private'] = 1;
VonSEO_Context::captureGuestEvent($blockedEvent, $publicCalendar, $calendarPermissions);
$privateEventContext = (new VonSEO_Context($url))->resolve();
$t->assert(!$privateEventContext->indexable && !$schema->build($privateEventContext),
    'Private event text cannot leak into metadata or schema');
$blockedEvent['private'] = 0;
$blockedEvent['visible'] = 0;
VonSEO_Context::captureGuestEvent($blockedEvent, $publicCalendar, $calendarPermissions);
$unapprovedEventContext = (new VonSEO_Context($url))->resolve();
$t->assert(!$unapprovedEventContext->indexable && !$schema->build($unapprovedEventContext),
    'Unapproved event remains non-indexable for guests');
$blockedEvent['visible'] = 1;
$sitemapDb->calendarPermissions[2] = 0;
VonSEO_Context::captureGuestEvent($blockedEvent, $publicCalendar, $calendarPermissions);
$deniedEventContext = (new VonSEO_Context($url))->resolve();
$t->assert(!$deniedEventContext->indexable && !$schema->build($deniedEventContext),
    'Event in a guest-denied calendar remains non-indexable');
unset($sitemapDb->calendarPermissions[2]);

$GLOBALS['mybb']->input = array('action' => 'dayview', 'calendar' => 2, 'year' => 2026, 'month' => 9, 'day' => 27);
$calendarDayContext = (new VonSEO_Context($url))->resolve();
$t->assert(!$calendarDayContext->indexable && $calendarDayContext->canonical === '',
    'Calendar day and utility views stay noindex to avoid duplicate archive surfaces');
$GLOBALS['mybb']->input = $calendarInputBefore;
$GLOBALS['mybb']->user = $calendarUserBefore;
if($calendarScriptBefore === null)
{
    unset($_SERVER['SCRIPT_NAME']);
}
else
{
    $_SERVER['SCRIPT_NAME'] = $calendarScriptBefore;
}

$moderationDbBefore = $GLOBALS['db'];
$moderationDb = new MockQueueDb();
$moderationDb->threads = array(
    20 => array('tid' => 20, 'fid' => 2, 'visible' => 0, 'closed' => '', 'subject' => 'Moderated Thread'),
    21 => array('tid' => 21, 'fid' => 2, 'visible' => 1, 'closed' => '', 'subject' => 'Public Parent'),
    22 => array('tid' => 22, 'fid' => 3, 'visible' => 0, 'closed' => '', 'subject' => 'Protected Transition'),
    23 => array('tid' => 23, 'fid' => 2, 'visible' => 0, 'closed' => '', 'subject' => 'Never Public Secret'),
    24 => array('tid' => 24, 'fid' => 2, 'visible' => -1, 'closed' => '', 'subject' => 'Never Public Soft Delete')
);
$moderationDb->posts = array(
    201 => array('pid' => 201, 'tid' => 21, 'visible' => 0),
    202 => array('pid' => 202, 'tid' => 21, 'visible' => 1)
);
$GLOBALS['db'] = $moderationDb;
$moderationDb->threads[20]['visible'] = 1;
vonseo_indexnow_approve_threads(array(20));
$moderationRow = reset($moderationDb->rows);
$t->assert($moderationRow && $moderationRow['event_type'] === 'current' && $moderationRow['available_at'] === TIME_NOW,
    'Moderated thread approval enters the IndexNow queue immediately');
$approvedUrl = $moderationRow['url'];
$moderationDb->threads[20]['subject'] = 'Hidden Renamed Subject';
$moderationDb->threads[20]['visible'] = 0;
vonseo_indexnow_unapprove_threads(array(20));
$moderationRow = reset($moderationDb->rows);
$t->assert($moderationRow && $moderationRow['event_type'] === 'removed' && $moderationRow['url'] === $approvedUrl &&
    strpos($moderationRow['url'], 'hidden-renamed-subject') === false,
    'Unapproving a public thread queues only its recorded prior public URL');
$moderationDb->rows = array();
vonseo_indexnow_unapprove_threads(array(22));
$t->assert(!$moderationDb->rows, 'Protected forum transitions never create an IndexNow removal URL');
$moderationDb->rows = array();
vonseo_indexnow_unapprove_threads(array(23, 24));
$t->assert(!$moderationDb->rows, 'Never-public moderated and soft-deleted threads cannot create IndexNow removal URLs');
$legacyRemoval = (new VonSEO_IndexNow($url))->queueRemoval(23, '', true);
$t->assert(!$legacyRemoval && !$moderationDb->rows, 'Legacy transition lookup argument cannot reconstruct a hidden thread URL');
$moderationDb->posts[201]['visible'] = 1;
vonseo_indexnow_approve_posts(array(201));
$approvedPostRow = reset($moderationDb->rows);
$t->assert($approvedPostRow && (int)$approvedPostRow['tid'] === 21 && $approvedPostRow['event_type'] === 'current',
    'Approving a moderated reply queues its public parent thread');
$moderationDb->rows = array();
$unapprovedHandler = new stdClass();
$unapprovedHandler->return_values = array('pid' => 201, 'visible' => 0);
$unapprovedHandler->pid = 201;
$moderationDb->posts[201]['visible'] = 0;
vonseo_indexnow_new_post($unapprovedHandler);
$t->assert(!$moderationDb->rows, 'New unapproved reply does not queue IndexNow');
$capturedPid = vonseo_indexnow_delete_post_start(202);
unset($moderationDb->posts[202]);
vonseo_indexnow_delete_post($capturedPid);
$deletedPostRow = reset($moderationDb->rows);
$t->assert($deletedPostRow && (int)$deletedPostRow['tid'] === 21, 'Deleting a public reply queues the parent thread after deletion');
$moderationDb->rows = array();
vonseo_indexnow_delete_thread_start(21);
unset($moderationDb->threads[21]);
vonseo_indexnow_delete_thread(21);
$deletedThreadRow = reset($moderationDb->rows);
$t->assert($deletedThreadRow && $deletedThreadRow['event_type'] === 'removed' && strpos($deletedThreadRow['url'], 'tid=21') !== false,
    'Deleting a public thread submits the safely captured old URL rather than dropping it');
$moderationDb->rows = array();
vonseo_indexnow_delete_thread_start(23);
unset($moderationDb->threads[23]);
vonseo_indexnow_delete_thread(23);
$t->assert(!$moderationDb->rows, 'Permanently deleting a never-public thread cannot create a removal URL');
$GLOBALS['db'] = $moderationDbBefore;

$previousScriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : null;
$_SERVER['SCRIPT_NAME'] = '/showthread.php';
$GLOBALS['mybb']->settings['vonseo_thread_attachment_image'] = '0';
$GLOBALS['mybb']->input = array('page' => 1);
$GLOBALS['mybb']->user['uid'] = 0;
$GLOBALS['raw_thread_cache'][10] = array('tid' => 10, 'fid' => 2, 'subject' => 'Modern SEO Discussion');
$GLOBALS['thread'] = array('tid' => 10, 'fid' => 2, 'subject' => 'Modern SEO Discussion', 'visible' => 1, 'closed' => '', 'firstpost' => 101, 'username' => 'Vondereich', 'dateline' => 1774260000);
$GLOBALS['forum'] = $GLOBALS['cache']->forums[2];
$renderedPost = array('pid' => 101, 'visible' => 1, 'message' => '<p>First paragraph &amp; detail.</p><blockquote>Quoted full text</blockquote><pre>Code sample</pre>'.str_repeat('<p>More public discussion.</p>', 10), 'edittime' => 1774263600);
$t->assert(vonseo_capture_guest_first_post($renderedPost) === $renderedPost, 'Postbit capture leaves MyBB post data unchanged');
$capturedContext = (new VonSEO_Context($url))->resolve();
$capturedSchema = $schema->build($capturedContext);
$expectedPostText = 'First paragraph & detail. Quoted full text Code sample'.str_repeat(' More public discussion.', 10);
$t->assert($capturedContext->data['first_post_text'] === $expectedPostText, 'Schema source preserves full MyBB-rendered guest text, including quote and code');
$t->assert($capturedSchema['@graph'][0]['text'] === $capturedContext->data['first_post_text'], 'Guest-rendered post text reaches JSON-LD');
$t->assert(VonSEO_Utils::strlen($capturedContext->description) <= 161 && $capturedContext->description !== $capturedContext->data['first_post_text'], 'Meta description remains a separate short summary');
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '1';
$GLOBALS['mybb']->input['page'] = 2;
$keywordPageTwo = (new VonSEO_Context($url))->resolve();
$keywordPageTwoPosting = $schema->build($keywordPageTwo)['@graph'][0];
$t->assert($keywordPageTwoPosting['url'] === 'https://example.com/forum/t-10-modern-seo-discussion' && $keywordPageTwo->canonical === 'https://example.com/forum/t-10-modern-seo-discussion--p2', 'Keyword page-two posting URL points to the stable first page while page canonical remains paginated');
$GLOBALS['mybb']->input = array('pid' => 101);
$GLOBALS['page'] = 3;
$postTargetContext = (new VonSEO_Context($url))->resolve();
$t->assert($postTargetContext->canonical === 'https://example.com/forum/t-10-modern-seo-discussion--p3', 'Post-target rendering uses the containing page MyBB calculated for its canonical');
unset($GLOBALS['page']);
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '0';
$GLOBALS['mybb']->input = array('page' => 1);
$GLOBALS['mybb']->user['uid'] = 5;
vonseo_capture_guest_first_post(array('pid' => 101, 'visible' => 1, 'message' => 'Member-only text'));
$memberContext = (new VonSEO_Context($url))->resolve();
$t->assert($memberContext->data['first_post_text'] === $expectedPostText, 'Logged-in postbit cannot replace guest schema text');
$GLOBALS['mybb']->user['uid'] = 0;
vonseo_capture_guest_first_post(array('pid' => 101, 'visible' => 0, 'message' => 'Unapproved post text'));
$unapprovedPostContext = (new VonSEO_Context($url))->resolve();
$t->assert($unapprovedPostContext->data['first_post_text'] === $expectedPostText, 'Unapproved postbit cannot replace guest schema text');
$GLOBALS['thread'] = array('tid' => 14, 'fid' => 8, 'subject' => 'Hidden Child Thread', 'visible' => 1, 'closed' => '', 'firstpost' => 141);
$GLOBALS['forum'] = $GLOBALS['cache']->forums[8];
vonseo_capture_guest_first_post(array('pid' => 141, 'visible' => 1, 'message' => 'Protected post text'));
$protectedContext = (new VonSEO_Context($url))->resolve();
$t->assert($protectedContext->indexable === false && empty($protectedContext->data['first_post_text']) && !$schema->build($protectedContext), 'Passworded parent cannot leak captured post text or schema');
if($previousScriptName === null)
{
    unset($_SERVER['SCRIPT_NAME']);
}
else
{
    $_SERVER['SCRIPT_NAME'] = $previousScriptName;
}
$GLOBALS['mybb']->settings['vonseo_thread_attachment_image'] = '1';

// MyBB's forumdisplay_thread_end hook runs after the row's stock link is set.
// Only the public, visible title link should switch to a keyword URL.
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '1';
$GLOBALS['foruminfo'] = $GLOBALS['cache']->forums[2];
$GLOBALS['threadcache'] = array(10 => array('subject' => 'Café & Teh'));
$GLOBALS['thread'] = array('tid' => 10, 'visible' => 1, 'closed' => '', 'threadlink' => 'showthread.php?tid=10');
vonseo_keyword_thread_listing_link();
$t->assert($GLOBALS['thread']['threadlink'] === 'https://example.com/forum/t-10-caf%C3%A9-teh', 'Public forum thread title links to the keyword canonical');
$GLOBALS['thread']['visible'] = 0;
$GLOBALS['thread']['threadlink'] = 'showthread.php?tid=10';
vonseo_keyword_thread_listing_link();
$t->assert($GLOBALS['thread']['threadlink'] === 'showthread.php?tid=10', 'Unapproved thread keeps its stock link');
$GLOBALS['thread']['visible'] = 1;
$GLOBALS['thread']['closed'] = 'moved|11';
vonseo_keyword_thread_listing_link();
$t->assert($GLOBALS['thread']['threadlink'] === 'showthread.php?tid=10', 'Moved-thread stub keeps its stock link');
$GLOBALS['thread']['closed'] = '';
$GLOBALS['foruminfo'] = $GLOBALS['cache']->forums[3];
vonseo_keyword_thread_listing_link();
$t->assert($GLOBALS['thread']['threadlink'] === 'showthread.php?tid=10', 'Password-protected forum keeps stock links');
$GLOBALS['foruminfo'] = $GLOBALS['cache']->forums[8];
vonseo_keyword_thread_listing_link();
$t->assert($GLOBALS['thread']['threadlink'] === 'showthread.php?tid=10', 'Forum below a passworded parent keeps stock links');
$GLOBALS['foruminfo'] = $GLOBALS['cache']->forums[2];
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '0';
vonseo_keyword_thread_listing_link();
$t->assert($GLOBALS['thread']['threadlink'] === 'showthread.php?tid=10', 'Turning keyword URLs off leaves the stock link unchanged');

// --- TEST 12: Keyword navigation, automatic migration and rollback ---
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '1';
$GLOBALS['raw_thread_cache'][10] = array('tid' => 10, 'fid' => 2, 'subject' => 'Café & Teh', 'visible' => 1, 'closed' => '');
$GLOBALS['raw_thread_cache'][11] = array('tid' => 11, 'fid' => 3, 'subject' => 'Private', 'visible' => 1, 'closed' => '');
$GLOBALS['raw_thread_cache'][12] = array('tid' => 12, 'fid' => 2, 'subject' => 'Pending', 'visible' => 0, 'closed' => '');
$GLOBALS['raw_thread_cache'][13] = array('tid' => 13, 'fid' => 2, 'subject' => 'Moved', 'visible' => 1, 'closed' => 'moved|14');
$GLOBALS['raw_thread_cache'][14] = array('tid' => 14, 'fid' => 8, 'subject' => 'Parent Private', 'visible' => 1, 'closed' => '');
$GLOBALS['raw_post_cache'][101] = array('pid' => 101, 'tid' => 10, 'visible' => 1);
$GLOBALS['raw_post_cache'][102] = array('pid' => 102, 'tid' => 11, 'visible' => 1);
$GLOBALS['raw_post_cache'][103] = array('pid' => 103, 'tid' => 10, 'visible' => 0);
$keyword = new VonSEO_Keyword($url);
$publicThread = $GLOBALS['raw_thread_cache'][10];
$publicForum = $GLOBALS['cache']->forums[2];
$redirect = $keyword->redirectDecision('/forum/showthread.php?tid=10', 'GET', 'showthread.php', $publicThread, $publicForum);
$t->assert($redirect === array('location' => 'https://example.com/forum/t-10-caf%C3%A9-teh', 'status' => 302), 'Native thread alias temporarily migrates to the keyword canonical without caching a rollback loop');
$redirect = $keyword->redirectDecision('/forum/t-10-old-title--p2', 'HEAD', 'showthread.php', $publicThread, $publicForum);
$t->assert($redirect === array('location' => 'https://example.com/forum/t-10-caf%C3%A9-teh--p2', 'status' => 301), 'Old thread slug preserves pagination and redirects after a rename');
$redirect = $keyword->redirectDecision('/forum/f-2-old-forum-name--p2', 'GET', 'forumdisplay.php', $publicForum);
$t->assert($redirect === array('location' => 'https://example.com/forum/f-2-public-forum--p2', 'status' => 301), 'Old forum slug redirects after a rename');
$t->assert($keyword->redirectDecision('/forum/t-10-caf%C3%A9-teh', 'GET', 'showthread.php', $publicThread, $publicForum) === false, 'Canonical keyword URL does not loop');
$t->assert($keyword->redirectDecision('/forum/t-10-caf%C3%A9-teh--p1', 'GET', 'showthread.php', $publicThread, $publicForum)['location'] === 'https://example.com/forum/t-10-caf%C3%A9-teh', 'Redundant page-one suffix redirects to page one');
$t->assert($keyword->redirectDecision('/forum/t-10-old-title', 'POST', 'showthread.php', $publicThread, $publicForum) === false, 'POST never changes method through keyword redirect');
$t->assert($keyword->redirectDecision('/forum/t-10-old-title', 'GET', 'showthread.php', $GLOBALS['raw_thread_cache'][12], $publicForum) === false, 'Mismatched thread ID does not redirect');
$t->assert($keyword->redirectDecision('/forum/t-12-pending', 'GET', 'showthread.php', $GLOBALS['raw_thread_cache'][12], $publicForum) === false, 'Unapproved thread does not redirect');
$t->assert($keyword->redirectDecision('/forum/t-13-moved', 'GET', 'showthread.php', $GLOBALS['raw_thread_cache'][13], $publicForum) === false, 'Moved thread stub does not redirect');
$t->assert($keyword->redirectDecision('/forum/t-11-private', 'GET', 'showthread.php', $GLOBALS['raw_thread_cache'][11], $GLOBALS['cache']->forums[3]) === false, 'Private thread does not redirect');
$t->assert($keyword->redirectDecision('/forum/t-14-parent-private', 'GET', 'showthread.php', $GLOBALS['raw_thread_cache'][14], $GLOBALS['cache']->forums[8]) === false, 'Thread below passworded parent does not redirect');
$t->assert($keyword->redirectDecision('/forum/f-3-private', 'GET', 'forumdisplay.php', $GLOBALS['cache']->forums[3]) === false, 'Private forum does not redirect');
$t->assert($keyword->redirectDecision('/forum/t-999-missing', 'GET', 'showthread.php', array(), $publicForum) === false, 'Missing thread does not redirect');
$lastPostAction = $keyword->redirectDecision('/forum/showthread.php?tid=10&action=lastpost', 'GET', 'showthread.php', $publicThread, $publicForum);
$t->assert($lastPostAction === array('location' => 'https://example.com/forum/t-10-caf%C3%A9-teh--lastpost', 'status' => 302), 'Native last-post action migrates to the keyword family');
$t->assert($keyword->redirectDecision('https://attacker.example/forum/t-10-old-title', 'GET', 'showthread.php', $publicThread, $publicForum) === false, 'Foreign host cannot control redirect');
$t->assert($keyword->redirectDecision('http://example.com/forum/t-10-old-title', 'GET', 'showthread.php', $publicThread, $publicForum) === false, 'Same-host scheme mismatch cannot control keyword redirect');
$defaultPortRedirect = $keyword->redirectDecision('https://example.com:443/forum/t-10-old-title', 'GET', 'showthread.php', $publicThread, $publicForum);
$t->assert(is_array($defaultPortRedirect) && $defaultPortRedirect['status'] === 301, 'Explicit default HTTPS port is treated as the same keyword origin');
$prettyMigration = $keyword->redirectDecision('/forum/thread-10-page-2.html', 'GET', 'showthread.php', $publicThread, $publicForum);
$t->assert($prettyMigration === array('location' => 'https://example.com/forum/t-10-caf%C3%A9-teh--p2', 'status' => 302), 'MyBB native pretty alias temporarily migrates to keyword pagination');
$postMigration = $keyword->redirectDecision('/forum/thread-10-post-101.html', 'GET', 'showthread.php', $publicThread, $publicForum);
$t->assert($postMigration === array('location' => 'https://example.com/forum/t-10-caf%C3%A9-teh--post-101#pid101', 'status' => 302), 'MyBB thread-post alias migrates to an exact keyword post target');
$queryPostMigration = $keyword->redirectDecision('/forum/showthread.php?tid=10&pid=101', 'GET', 'showthread.php', $publicThread, $publicForum);
$t->assert($queryPostMigration === $postMigration, 'MyBB query-form thread-post alias uses the same keyword target');
$t->assert($keyword->redirectDecision('/forum/t-10-caf%C3%A9-teh--post-101', 'GET', 'showthread.php', $publicThread, $publicForum) === false, 'Current keyword post target does not loop');
$stalePost = $keyword->redirectDecision('/forum/t-10-old-title--post-101', 'GET', 'showthread.php', $publicThread, $publicForum);
$t->assert($stalePost === array('location' => 'https://example.com/forum/t-10-caf%C3%A9-teh--post-101#pid101', 'status' => 301), 'Old thread slug keeps its exact post target during migration');
$t->assert($keyword->redirectDecision('/forum/thread-10-post-102.html', 'GET', 'showthread.php', $publicThread, $publicForum) === false, 'Post from another thread is not rewritten');
$t->assert($keyword->redirectDecision('/forum/thread-10-post-103.html', 'GET', 'showthread.php', $publicThread, $publicForum) === false, 'Unapproved post target is not rewritten');
$actionPaths = array(
    'lastpost' => 'thread-10-lastpost.html',
    'newpost' => 'thread-10-newpost.html',
    'nextnewest' => 'thread-10-nextnewest.html',
    'nextoldest' => 'thread-10-nextoldest.html'
);
foreach($actionPaths as $action => $path)
{
    $actionRedirect = $keyword->redirectDecision('/forum/'.$path, 'GET', 'showthread.php', $publicThread, $publicForum);
    $t->assert($actionRedirect === array('location' => 'https://example.com/forum/t-10-caf%C3%A9-teh--'.$action, 'status' => 302), 'Native '.$action.' pretty action migrates to the keyword family');
    $t->assert($keyword->redirectDecision('/forum/t-10-caf%C3%A9-teh--'.$action, 'GET', 'showthread.php', $publicThread, $publicForum) === false, 'Current '.$action.' keyword action does not loop');
}

$navHtml = '<a href="forumdisplay.php?fid=2">Forum</a><a href="showthread.php?tid=10&amp;page=2#pid101">Thread</a>'
    .'<a href="thread-10.html">Pretty</a><a href="showthread.php?tid=10&amp;action=lastpost">Last</a>'
    .'<a href="thread-10-newpost.html">New</a><a href="thread-10-nextnewest.html">Next</a><a href="thread-10-nextoldest.html">Previous</a>'
    .'<a href="thread-10-post-101.html#pid101">Exact post</a><a href="showthread.php?tid=10&amp;pid=101#pid101">Query post</a>'
    .'<a href="showthread.php?mode=threaded&amp;tid=10&amp;pid=101#pid101">Threaded mode</a>'
    .'<a href="showthread.php?pid=101">Post-only action</a><a href="showthread.php?tid=11">Private</a>'
    .'<a href="showthread.php?tid=12">Pending</a><a href="showthread.php?tid=13">Moved</a>'
    .'<a href="https://attacker.example/forum/showthread.php?tid=10">Foreign</a>';
$rewritten = $keyword->rewriteHtml($navHtml, 'index.php');
$t->assert(strpos($rewritten, 'href="https://example.com/forum/f-2-public-forum"') !== false &&
    strpos($rewritten, 'href="https://example.com/forum/t-10-caf%C3%A9-teh--p2#pid101"') !== false &&
    strpos($rewritten, 'href="https://example.com/forum/t-10-caf%C3%A9-teh"') !== false &&
    substr_count($rewritten, 'href="https://example.com/forum/t-10-caf%C3%A9-teh--post-101#pid101"') === 2 &&
    strpos($rewritten, 'href="https://example.com/forum/t-10-caf%C3%A9-teh--lastpost"') !== false &&
    strpos($rewritten, 'href="https://example.com/forum/t-10-caf%C3%A9-teh--newpost"') !== false &&
    strpos($rewritten, 'href="https://example.com/forum/t-10-caf%C3%A9-teh--nextnewest"') !== false &&
    strpos($rewritten, 'href="https://example.com/forum/t-10-caf%C3%A9-teh--nextoldest"') !== false, 'Forum, thread, exact-post and stock action navigation use keyword URLs', $rewritten);
$t->assert(strpos($rewritten, 'href="showthread.php?mode=threaded&amp;tid=10&amp;pid=101#pid101"') !== false &&
    strpos($rewritten, 'href="showthread.php?pid=101"') !== false &&
    strpos($rewritten, 'href="showthread.php?tid=11"') !== false &&
    strpos($rewritten, 'href="showthread.php?tid=12"') !== false &&
    strpos($rewritten, 'href="showthread.php?tid=13"') !== false &&
    strpos($rewritten, 'href="https://attacker.example/forum/showthread.php?tid=10"') !== false, 'Display-mode, post-only, hidden and external links remain untouched');
$t->assert($keyword->rewriteHtml($navHtml, 'search.php') === $navHtml, 'Unreviewed MyBB page contexts are not rewritten');

// Post eligibility must depend on both IDs, not the first link using a PID.
$GLOBALS['raw_thread_cache'][7001] = array('tid' => 7001, 'fid' => 2, 'subject' => 'Thread A', 'visible' => 1, 'closed' => '');
$GLOBALS['raw_thread_cache'][7002] = array('tid' => 7002, 'fid' => 2, 'subject' => 'Thread B', 'visible' => 1, 'closed' => '');
$GLOBALS['raw_thread_cache'][7003] = array('tid' => 7003, 'fid' => 3, 'subject' => 'Private Thread', 'visible' => 1, 'closed' => '');
$GLOBALS['raw_post_cache'][8001] = array('pid' => 8001, 'tid' => 7001, 'visible' => 1);
$GLOBALS['raw_post_cache'][8002] = array('pid' => 8002, 'tid' => 7001, 'visible' => 0);
$GLOBALS['raw_post_cache'][8004] = array('pid' => 8004);
$validPostLink = '<a href="showthread.php?tid=7001&amp;pid=8001#pid8001">Valid</a>';
$mismatchedPostLink = '<a href="showthread.php?tid=7002&amp;pid=8001#pid8001">Mismatched</a>';
$validKeywordPost = '<a href="https://example.com/forum/t-7001-thread-a--post-8001#pid8001">Valid</a>';
$forwardPostWorker = new VonSEO_Keyword($url);
$GLOBALS['test_get_post_count'] = 0;
$t->assert($forwardPostWorker->rewriteHtml($validPostLink.$mismatchedPostLink, 'index.php') === $validKeywordPost.$mismatchedPostLink,
    'A cached valid post cannot rewrite a later mismatched thread target');
$t->assert($GLOBALS['test_get_post_count'] === 1, 'Valid-first post targets share one lookup per PID');
$reversePostWorker = new VonSEO_Keyword($url);
$GLOBALS['test_get_post_count'] = 0;
$t->assert($reversePostWorker->rewriteHtml($mismatchedPostLink.$validPostLink, 'index.php') === $mismatchedPostLink.$validKeywordPost,
    'A rejected thread-post mismatch cannot poison a later valid target');
$t->assert($GLOBALS['test_get_post_count'] === 1, 'Mismatch-first post targets share one lookup per PID');
$t->assert($forwardPostWorker->redirectDecision('/forum/showthread.php?tid=7002&pid=8001', 'GET', 'showthread.php',
    $GLOBALS['raw_thread_cache'][7002], $publicForum) === false,
    'Redirect decisions recheck thread identity after a rendered post cache hit');
$redirectPostWorker = new VonSEO_Keyword($url);
$redirectPostWorker->redirectDecision('/forum/showthread.php?tid=7002&pid=8001', 'GET', 'showthread.php',
    $GLOBALS['raw_thread_cache'][7002], $publicForum);
$t->assert($redirectPostWorker->redirectDecision('/forum/showthread.php?tid=7001&pid=8001', 'GET', 'showthread.php',
    $GLOBALS['raw_thread_cache'][7001], $publicForum) === array(
        'location' => 'https://example.com/forum/t-7001-thread-a--post-8001#pid8001', 'status' => 302),
    'A rejected redirect target does not suppress the matching post redirect');
$t->assert($redirectPostWorker->redirectDecision('/forum/thread-7002-post-8001.html', 'GET', 'showthread.php',
    $GLOBALS['raw_thread_cache'][7002], $publicForum) === false,
    'Cached post eligibility also rejects a mismatched native pretty target');
$unapprovedPostLinks = '<a href="showthread.php?tid=7001&amp;pid=8002">Pending</a>'
    .'<a href="showthread.php?tid=7002&amp;pid=8002">Pending mismatch</a>';
$t->assert((new VonSEO_Keyword($url))->rewriteHtml($unapprovedPostLinks, 'index.php') === $unapprovedPostLinks,
    'Unapproved post targets remain native across repeated cache hits');
$privatePostLink = '<a href="showthread.php?tid=7003&amp;pid=8001">Private</a>';
$t->assert($forwardPostWorker->rewriteHtml($privatePostLink, 'index.php') === $privatePostLink,
    'An approved cached post cannot bypass a private forum gate');
$missingPostLinks = '<a href="showthread.php?tid=7001&amp;pid=8004">Malformed</a>'
    .'<a href="showthread.php?tid=7002&amp;pid=8004">Malformed mismatch</a>'
    .'<a href="showthread.php?tid=7001&amp;pid=8999">Missing</a>'
    .'<a href="showthread.php?tid=7002&amp;pid=8999">Missing mismatch</a>';
$GLOBALS['test_get_post_count'] = 0;
$t->assert((new VonSEO_Keyword($url))->rewriteHtml($missingPostLinks, 'index.php') === $missingPostLinks &&
    $GLOBALS['test_get_post_count'] === 2, 'Missing and malformed posts fail closed and reuse negative cache entries');
$boundedPostLinks = '';
for($lookupPid = 8005; $lookupPid <= 8015; ++$lookupPid)
{
    $GLOBALS['raw_post_cache'][$lookupPid] = array('pid' => $lookupPid, 'tid' => 7001, 'visible' => 1);
    $boundedPostLinks .= '<a href="showthread.php?tid=7001&amp;pid='.$lookupPid.'">Post</a>';
}
$boundedPostWorker = new VonSEO_Keyword($url);
$GLOBALS['test_get_post_count'] = 0;
$boundedPostOutput = $boundedPostWorker->rewriteHtml($boundedPostLinks, 'index.php');
$t->assert($GLOBALS['test_get_post_count'] === 10 &&
    strpos($boundedPostOutput, 't-7001-thread-a--post-8014#pid8014') !== false &&
    strpos($boundedPostOutput, 'href="showthread.php?tid=7001&amp;pid=8015"') !== false,
    'Post lookup budget stays bounded at ten distinct PIDs');
$cachedBoundedLink = '<a href="showthread.php?tid=7001&amp;pid=8005">Cached</a>';
$t->assert($boundedPostWorker->rewriteHtml($cachedBoundedLink, 'index.php') ===
    '<a href="https://example.com/forum/t-7001-thread-a--post-8005#pid8005">Cached</a>' &&
    $GLOBALS['test_get_post_count'] === 10, 'Cached valid targets remain usable after the post lookup budget is exhausted');
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '0';
$GLOBALS['test_get_post_count'] = 0;
$t->assert((new VonSEO_Keyword($url))->rewriteHtml($validPostLink.$mismatchedPostLink, 'index.php') ===
    $validPostLink.$mismatchedPostLink && $GLOBALS['test_get_post_count'] === 0,
    'Keyword URLs off preserves both post targets without post lookups');
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '1';
foreach(array(7001, 7002, 7003) as $fixtureTid)
{
    unset($GLOBALS['raw_thread_cache'][$fixtureTid]);
}
foreach(array_merge(array(8001, 8002, 8004), range(8005, 8015)) as $fixturePid)
{
    unset($GLOBALS['raw_post_cache'][$fixturePid]);
}

$lookupHtml = '';
for($lookupTid = 100; $lookupTid < 200; $lookupTid++)
{
    $lookupHtml .= '<a href="showthread.php?tid='.$lookupTid.'">Unknown</a>';
}
$GLOBALS['test_get_thread_count'] = 0;
$keyword->rewriteHtml($lookupHtml, 'index.php');
$t->assert($GLOBALS['test_get_thread_count'] <= 10, 'Keyword fallback thread lookups stay bounded on link-heavy pages');

$benchmarkKeywordRender = function($enabled, $html, $iterations) use ($url) {
    $GLOBALS['mybb']->settings['vonseo_keyword_urls'] = $enabled ? '1' : '0';
    $worker = new VonSEO_Keyword($url);
    // PHP 7.1/7.2 have no hrtime; this informational benchmark also runs there.
    $highResolution = function_exists('hrtime');
    $start = $highResolution ? hrtime(true) : microtime(true);
    for($i = 0; $i < $iterations; $i++)
    {
        $worker->rewriteHtml($html, 'index.php');
    }
    return $highResolution ? (hrtime(true) - $start) / 1000000 : (microtime(true) - $start) * 1000;
};
$benchmarkIterations = 100;
$benchmarkDenseHtml = $navHtml.$lookupHtml.$lookupHtml;
$keywordBenchmark = array(
    'off_normal_ms' => $benchmarkKeywordRender(false, $navHtml, $benchmarkIterations),
    'on_normal_ms' => $benchmarkKeywordRender(true, $navHtml, $benchmarkIterations),
    'off_dense_ms' => $benchmarkKeywordRender(false, $benchmarkDenseHtml, $benchmarkIterations),
    'on_dense_ms' => $benchmarkKeywordRender(true, $benchmarkDenseHtml, $benchmarkIterations)
);
echo sprintf(
    " [INFO] Keyword render benchmark (%d runs): normal OFF %.2f ms / ON %.2f ms; dense OFF %.2f ms / ON %.2f ms\n",
    $benchmarkIterations,
    $keywordBenchmark['off_normal_ms'],
    $keywordBenchmark['on_normal_ms'],
    $keywordBenchmark['off_dense_ms'],
    $keywordBenchmark['on_dense_ms']
);
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '1';

$_SERVER['SCRIPT_NAME'] = '/showthread.php';
$GLOBALS['thread'] = $publicThread;
$GLOBALS['forum'] = $publicForum;
$GLOBALS['foruminfo'] = $publicForum;
$threadPages = array('url' => 'thread-10-page-{page}.html');
$keyword->rewriteMultipage($threadPages);
$t->assert($threadPages['url'] === 'https://example.com/forum/t-10-caf%C3%A9-teh--p{page}', 'MyBB thread multipage template becomes keyword pagination');
$forumPages = array('url' => 'forum-2-page-{page}.html');
$keyword->rewriteMultipage($forumPages);
$t->assert($forumPages['url'] === 'https://example.com/forum/f-2-public-forum--p{page}', 'Forum breadcrumb multipage changes independently of thread pagination');
$filteredPages = array('url' => 'forumdisplay.php?fid=2&sortby=subject&page={page}');
$keyword->rewriteMultipage($filteredPages);
$t->assert($filteredPages['url'] === 'forumdisplay.php?fid=2&sortby=subject&page={page}', 'Filtered pagination stays native');

$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '0';
$rollback = $keyword->redirectDecision('/forum/t-10-old-title--p2', 'GET', 'showthread.php', $publicThread, $publicForum);
$t->assert($rollback === array('location' => 'https://example.com/forum/showthread.php?tid=10&page=2', 'status' => 302), 'Disabling keyword URLs temporarily rolls old links back to MyBB URLs');
$postRollback = $keyword->redirectDecision('/forum/t-10-old-title--post-101', 'GET', 'showthread.php', $publicThread, $publicForum);
$t->assert($postRollback === array('location' => 'https://example.com/forum/showthread.php?tid=10&pid=101#pid101', 'status' => 302), 'Disabling keyword URLs rolls exact post targets back without losing the anchor');
$actionRollback = $keyword->redirectDecision('/forum/t-10-old-title--lastpost', 'GET', 'showthread.php', $publicThread, $publicForum);
$t->assert($actionRollback === array('location' => 'https://example.com/forum/showthread.php?tid=10&action=lastpost', 'status' => 302), 'Disabling keyword URLs rolls dynamic actions back to native MyBB URLs');
$t->assert($keyword->redirectDecision('/forum/showthread.php?tid=10', 'GET', 'showthread.php', $publicThread, $publicForum) === false, 'Stock URL remains stable after rollback');
$t->assert($keyword->rewriteHtml($navHtml, 'index.php') === $navHtml, 'Disabling keyword URLs stops navigation rewriting');
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '1';
$t->assert($keyword->redirectDecision('/forum/t-10-old-title', 'GET', 'showthread.php', $publicThread, $publicForum)['status'] === 301, 'Re-enabling resumes old-slug migration without a database table');
$GLOBALS['mybb']->settings['vonseo_keyword_urls'] = '0';
if($previousScriptName === null)
{
    unset($_SERVER['SCRIPT_NAME']);
}
else
{
    $_SERVER['SCRIPT_NAME'] = $previousScriptName;
}

$installDb = new MockInstallDb();
$GLOBALS['db'] = $installDb;
$GLOBALS['cache']->data = array();
VonSEO_State::resetRuntime();
$boardUrlBeforeInstall = $GLOBALS['mybb']->settings['bburl'];
$GLOBALS['mybb']->settings['bburl'] = "https://example.com/forum'o";
vonseo_install();
$GLOBALS['mybb']->settings['bburl'] = $boardUrlBeforeInstall;
$freshCount = count($installDb->settings);
$t->assert(isset($installDb->groups['vonseo']) && $freshCount === 25, 'Fresh install creates one group and all 25 retained settings');
$t->assert(isset($installDb->settings['vonseo_site_description']) && (int)$installDb->settings['vonseo_site_description']['gid'] === 0 && !isset($installDb->settings['vonseo_home_title']), 'Site Description stays cached but hidden from Advanced Settings and the duplicate homepage title is absent');
$t->assert(isset($installDb->tables['vonseo_redirects']) && isset($installDb->tables['vonseo_404_log']) && isset($installDb->tables['vonseo_indexnow_queue']) && isset($installDb->tables['vonseo_indexnow_threads']) && isset($installDb->tables['vonseo_meta']), 'Fresh install creates all VonSEO data and metadata tables');
$t->assert(VonSEO_State::schemaVersion() === 5 && !isset($installDb->tasks['vonseo_indexnow']), 'Fresh install records schema 5 without scheduling work before activation');
$t->assert($installDb->settings['vonseo_searchbox_schema']['value'] === '0', 'Fresh install disables legacy SearchAction by default');
$t->assert($installDb->settings['vonseo_keyword_urls']['value'] === '0', 'Fresh install leaves experimental keyword URLs disabled');
$t->assert(strpos($installDb->settings['vonseo_robots_txt']['value'], "Disallow: /forum'o/admin/") !== false &&
    strpos($installDb->settings['vonseo_robots_txt']['value'], '/search.php') === false,
    'Fresh robots defaults use the board subfolder and allow crawlers to read search-page noindex');
$t->assert(strpos($installDb->settingInsertPayloads['vonseo_robots_txt']['value'], "Disallow: /forum\\'o/admin/") !== false &&
    strpos($installDb->settings['vonseo_robots_txt']['value'], "Disallow: /forum'o/admin/") !== false,
    'Fresh setting insertion escapes dynamic values exactly once while preserving the stored value');

$installDb->settings['vonseo_searchbox_schema']['value'] = '1';
$installDb->settings['vonseo_indexnow_key']['value'] = 'saved-indexnow-key';
$installDb->settings['vonseo_searchbox_schema']['description'] = 'Old help text';
$installDb->settings['vonseo_site_description']['gid'] = 7;
$installDb->settings['vonseo_site_description']['value'] = 'Saved site description';
$installDb->settings['vonseo_home_title'] = array('gid' => 7, 'name' => 'vonseo_home_title', 'value' => 'Old duplicate title');
$installDb->redirectRows[] = array('source_path' => '/saved-rule');
vonseo_activate();
$t->assert(isset($installDb->tasks['vonseo_indexnow']) && (int)$installDb->tasks['vonseo_indexnow']['enabled'] === 1 && $GLOBALS['cache']->taskUpdates === 1, 'Activation registers the IndexNow task and refreshes MyBB task cache');
$t->assert(count($installDb->settings) === $freshCount && count($installDb->groups) === 1, 'Activation repair is idempotent');
$t->assert($installDb->settings['vonseo_searchbox_schema']['value'] === '1' && $installDb->settings['vonseo_indexnow_key']['value'] === 'saved-indexnow-key', 'Activation preserves existing choices and IndexNow key');
$t->assert($installDb->settings['vonseo_site_description']['value'] === 'Saved site description' && (int)$installDb->settings['vonseo_site_description']['gid'] === 0 && !isset($installDb->settings['vonseo_home_title']), 'Activation migrates Site Description out of Advanced Settings and removes the retired title without losing the description');
$t->assert(strpos($installDb->settings['vonseo_searchbox_schema']['description'], 'retired') !== false && count($installDb->redirectRows) === 1, 'Activation refreshes help text without changing redirect data');
$rebuildsBeforeDeactivate = $GLOBALS['test_rebuild_count'];
vonseo_deactivate();
$t->assert(count($installDb->settings) === $freshCount && count($installDb->redirectRows) === 1 && count($installDb->tables) === 5 &&
    (int)$installDb->tasks['vonseo_indexnow']['enabled'] === 0 && $GLOBALS['cache']->taskUpdates === 2 && $GLOBALS['test_rebuild_count'] === $rebuildsBeforeDeactivate + 1,
    'Deactivation keeps VonSEO data and disables its scheduled worker');

unset($installDb->tables['vonseo_indexnow_queue'], $installDb->tasks['vonseo_indexnow']);
VonSEO_State::setSchemaVersion(1);
vonseo_activate();
$t->assert(isset($installDb->tables['vonseo_indexnow_queue']) && isset($installDb->tables['vonseo_indexnow_threads']) && isset($installDb->tasks['vonseo_indexnow']) && VonSEO_State::schemaVersion() === 5 && count($installDb->redirectRows) === 1, 'Legacy beta schema upgrades through retry-safe delivery without changing redirect data');
unset($installDb->tables['vonseo_indexnow_queue'], $installDb->tasks['vonseo_indexnow']);
vonseo_activate();
$t->assert(isset($installDb->tables['vonseo_indexnow_queue']) && isset($installDb->tables['vonseo_indexnow_threads']) && isset($installDb->tasks['vonseo_indexnow']) && VonSEO_State::schemaVersion() === 5, 'Repeated activation repairs a missing queue table and task without changing the version marker');
$installDb->type = 'pgsql';
$t->assert(vonseo_database_supported() === false, 'Unsupported non-MySQL database families fail the explicit support check');
$installDb->type = 'mysqli';

$supportedDb = $GLOBALS['db'];
$unsupportedDb = new MockInstallDb();
$unsupportedDb->type = 'pgsql';
$GLOBALS['db'] = $unsupportedDb;
$taskUpdatesBeforeUnsupported = $GLOBALS['cache']->taskUpdates;
$unsupportedInstallRejected = false;
try
{
    vonseo_install();
}
catch(RuntimeException $exception)
{
    $unsupportedInstallRejected = strpos($exception->getMessage(), 'requires MySQL or MariaDB') !== false;
}
$t->assert($unsupportedInstallRejected && !$unsupportedDb->groups && !$unsupportedDb->settings && !$unsupportedDb->tables && !$unsupportedDb->tasks &&
    $GLOBALS['cache']->taskUpdates === $taskUpdatesBeforeUnsupported,
    'Unsupported fresh installation aborts before creating settings, tables, tasks or cache state');
$unsupportedActivationRejected = false;
try
{
    vonseo_activate();
}
catch(RuntimeException $exception)
{
    $unsupportedActivationRejected = strpos($exception->getMessage(), 'requires MySQL or MariaDB') !== false;
}
$t->assert($unsupportedActivationRejected && !$unsupportedDb->groups && !$unsupportedDb->settings && !$unsupportedDb->tables && !$unsupportedDb->tasks &&
    $GLOBALS['cache']->taskUpdates === $taskUpdatesBeforeUnsupported,
    'Unsupported activation aborts before MyBB task registration or plugin-owned state changes');
$GLOBALS['db'] = $supportedDb;

// --- TEST 12: Uninstall removes only VonSEO-owned database state ---
$installDb->groups['other'] = array('gid' => 9, 'name' => 'other');
$installDb->settings['other_setting'] = array('gid' => 9, 'name' => 'other_setting', 'value' => 'keep');
$rebuildsBeforeUninstall = $GLOBALS['test_rebuild_count'];
vonseo_uninstall();
$t->assert(!isset($installDb->groups['vonseo']) && isset($installDb->groups['other']), 'Uninstall removes VonSEO settings group without touching another group');
$t->assert(count($installDb->settings) === 1 && isset($installDb->settings['other_setting']), 'Uninstall removes every VonSEO setting and preserves unrelated settings');
$t->assert(!isset($installDb->tables['vonseo_redirects']) && !isset($installDb->tables['vonseo_404_log']) && !isset($installDb->tables['vonseo_indexnow_queue']) && !isset($installDb->tables['vonseo_indexnow_threads']) && !isset($installDb->tables['vonseo_meta']) && !$installDb->redirectRows && !$installDb->tasks, 'Uninstall drops all VonSEO tables, queue task and their rows');
$t->assert($GLOBALS['test_rebuild_count'] === $rebuildsBeforeUninstall + 1, 'Uninstall rebuilds MyBB settings cache');
$t->assert($GLOBALS['cache']->taskUpdates >= 5, 'Activation, deactivation and uninstall refresh MyBB scheduled-task cache');
$installDb->settings['vonseo_orphan'] = array('gid' => 7, 'name' => 'vonseo_orphan', 'value' => 'stale');
vonseo_uninstall();
$t->assert(count($installDb->removedTables) === 5 && !isset($installDb->settings['vonseo_orphan']) && isset($installDb->settings['other_setting']), 'Repeated uninstall removes orphaned VonSEO settings and leaves unrelated data intact');

// Output report
$allPassed = $t->report();
exit($allPassed ? 0 : 1);
