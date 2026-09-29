<?php
/**
 * Focused ACP mutation-method regression. Run one action/method per process so
 * the admin module can be included once per process.
 */

define('IN_MYBB', 1);
define('TIME_NOW', 1774267200);
define('MYBB_ROOT', dirname(__DIR__).'/');

class MyBB
{
    const INPUT_INT = 1;

    public $request_method;
    public $post_code = 'valid-post-token';
    public $settings = array(
        'bburl' => 'https://example.com/forum',
        'bbname' => 'Community Forum',
        'homename' => 'Example Network',
        'homeurl' => 'https://example.com/',
        'bbdescription' => 'Community description'
    );
    public $input = array(
        'action' => 'redirect_save',
        'rid' => '0',
        'source' => '/csrf-test',
        'target' => '',
        'status_code' => '410',
        'enabled' => '1',
        'site_name' => 'Updated Community',
        'site_url' => 'https://example.com/forum',
        'homepage_name' => 'Example Network',
        'homepage_url' => 'https://example.com/',
        'site_description' => 'Updated community description',
        'my_post_key' => 'valid-post-token'
    );

    public function get_input(string $key, int $type = 0)
    {
        $value = isset($this->input[$key]) ? $this->input[$key] : '';
        return $type === self::INPUT_INT ? (int)$value : $value;
    }
}

class Page
{
    public function add_breadcrumb_item(string $title, string $url)
    {
    }

    public function output_confirm_action(string $url, string $prompt)
    {
        throw new TestAdminConfirm($url);
    }

    public function output_nav_tabs(array $tabs, string $active)
    {
    }

    public function output_header(string $title)
    {
    }

    public function output_footer()
    {
        throw new TestAdminComplete();
    }

    public function output_inline_error(array $errors)
    {
    }
}

class Form
{
    public function __construct(...$args)
    {
    }

    public function generate_file_upload_box(string $name)
    {
        return '';
    }

    public function generate_text_area(string $name, string $value = '', array $options = array())
    {
        return '';
    }

    public function generate_yes_no_radio(string $name, int $value = 0)
    {
        return '';
    }

    public function generate_submit_button(string $label)
    {
        return '';
    }

    public function output_submit_wrapper(array $buttons)
    {
    }

    public function end()
    {
    }
}

class FormContainer
{
    public function __construct(string $title)
    {
    }

    public function output_row(...$args)
    {
    }

    public function end()
    {
    }
}

class TestAdminRedirect extends Exception
{
}

class TestAdminConfirm extends Exception
{
}

class TestAdminComplete extends Exception
{
}

class TestDb
{
    public $inserted = array();
    public $deleted = array();
    public $updated = array();

    public function simple_select(string $table, string $fields, string $conditions = '', array $options = array())
    {
        if($table === 'vonseo_redirects' && $fields === '*' && strpos($conditions, "rid='7'") !== false)
        {
            return array('rid' => 7, 'source_path' => '/old');
        }
        if($table === 'vonseo_redirects' && strpos($fields, 'COUNT(') !== false)
        {
            return array('total' => count($this->inserted));
        }
        return array();
    }

    public function table_exists(string $table)
    {
        return in_array($table, array('vonseo_redirects', 'vonseo_404_log'), true);
    }

    public function fetch_array(array $query)
    {
        return $query ?: false;
    }

    public function fetch_field(array $query, string $field)
    {
        return null;
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

    public function delete_query(string $table, string $conditions = '')
    {
        $this->deleted[] = array($table, $conditions);
    }

    public function update_query(string $table, array $data, string $conditions = '')
    {
        $this->updated[] = array($table, $data, $conditions);
    }
}

class TestCache
{
    public $data = array();

    public function read(string $key)
    {
        return isset($this->data[$key]) ? $this->data[$key] : false;
    }

    public function update(string $key, array $value)
    {
        $this->data[$key] = $value;
    }
}

function admin_redirect(string $url)
{
    throw new TestAdminRedirect($url);
}

function log_admin_action(...$args)
{
    $GLOBALS['test_admin_logs'][] = $args;
}

function check_admin_permissions(array $action, bool $error = true)
{
    if(!empty($GLOBALS['test_deny_sensitive']) && $action['module'] === 'config' &&
       in_array($action['action'], array('vonseo_settings', 'settings'), true))
    {
        if($error)
        {
            throw new RuntimeException('Access denied');
        }
        return false;
    }

    return true;
}

function flash_message(string $message, string $type)
{
}

function verify_post_check(string $token)
{
    if($token !== 'valid-post-token')
    {
        throw new RuntimeException('Invalid post token');
    }
}

function rebuild_settings()
{
    $GLOBALS['test_settings_rebuilt'] = true;
}

function vonseo_require_core()
{
    require_once MYBB_ROOT.'inc/plugins/vonseo/Utils.php';
    require_once MYBB_ROOT.'inc/plugins/vonseo/Url.php';
    require_once MYBB_ROOT.'inc/plugins/vonseo/Redirects.php';
    require_once MYBB_ROOT.'inc/plugins/vonseo/Errors.php';
    require_once MYBB_ROOT.'inc/plugins/vonseo/IndexNow.php';
}

$actions = array('redirect_save', 'redirect_delete', 'redirects_import', 'notfound_clear', 'notfound_clear_all', 'site_details_save', 'indexnow_rotate_key');
$action = isset($argv[1]) ? strtolower($argv[1]) : '';
$mode = isset($argv[2]) ? strtolower($argv[2]) : '';
if(!in_array($action, $actions, true) || !in_array($mode, array('get', 'head', 'post', 'csrf', 'invalid', 'sql', 'denied'), true) ||
   (in_array($mode, array('invalid', 'sql'), true) && $action !== 'site_details_save') ||
   ($mode === 'denied' && !in_array($action, array('site_details_save', 'indexnow_rotate_key'), true)))
{
    fwrite(STDERR, "Usage: php tests/acp_redirect_csrf.php action get|head|post|csrf; site_details_save also supports invalid|sql; sensitive actions support denied\n");
    exit(2);
}

$GLOBALS['mybb'] = new MyBB();
$GLOBALS['mybb']->request_method = in_array($mode, array('csrf', 'invalid', 'sql', 'denied'), true) ? 'post' : $mode;
$GLOBALS['mybb']->input['action'] = $action;
if($mode === 'csrf')
{
    $GLOBALS['mybb']->input['my_post_key'] = 'invalid-post-token';
}
if($mode === 'invalid')
{
    $GLOBALS['mybb']->input['site_url'] = "https://example.com/forum\r\nX-Test: injected";
}
if($mode === 'sql')
{
    $GLOBALS['mybb']->input['site_name'] = "O'Brien Forum";
    $GLOBALS['mybb']->input['site_url'] = "https://example.com/forum/o'brien";
    $GLOBALS['mybb']->input['homepage_name'] = "O'Brien Network";
    $GLOBALS['mybb']->input['homepage_url'] = "https://example.com/o'brien";
    $GLOBALS['mybb']->input['site_description'] = "O'Brien's community";
}
if($mode === 'denied')
{
    $GLOBALS['test_deny_sensitive'] = true;
}
if($action === 'redirects_import')
{
    $GLOBALS['mybb']->input['csv_text'] = "source_path,target_url,status_code,enabled\n/import-old,/import-new,301,1\n";
    $GLOBALS['mybb']->input['overwrite'] = '0';
}
if($action === 'redirect_delete')
{
    $GLOBALS['mybb']->input['rid'] = '7';
}
if($action === 'notfound_clear')
{
    $GLOBALS['mybb']->input['hash'] = str_repeat('a', 64);
}
$GLOBALS['page'] = new Page();
$GLOBALS['db'] = new TestDb();
$GLOBALS['cache'] = new TestCache();
$GLOBALS['test_admin_logs'] = array();

$redirected = false;
$confirmed = false;
$completed = false;
$csrfRejected = false;
$accessDenied = false;
ob_start();
try
{
    require MYBB_ROOT.'admin/modules/config/vonseo.php';
    $completed = true;
}
catch(TestAdminRedirect $exception)
{
    if(in_array($action, array('site_details_save', 'indexnow_rotate_key'), true))
    {
        $redirected = $exception->getMessage() === 'index.php?module=config-vonseo';
    }
    else
    {
        $expected = strpos($action, 'redirect') === 0 ? 'redirects' : 'notfound';
        $redirected = $exception->getMessage() === 'index.php?module=config-vonseo&action='.$expected;
    }
}
catch(TestAdminConfirm $exception)
{
    $confirmed = strpos($exception->getMessage(), 'action='.$action) !== false;
}
catch(TestAdminComplete $exception)
{
    $completed = true;
}
catch(RuntimeException $exception)
{
    $csrfRejected = $exception->getMessage() === 'Invalid post token';
    $accessDenied = $exception->getMessage() === 'Access denied';
}
ob_end_clean();

$rows = $GLOBALS['db']->inserted;
$deletes = $GLOBALS['db']->deleted;
$updates = $GLOBALS['db']->updated;
if($mode === 'csrf')
{
    $passed = $csrfRejected && !$redirected && !$confirmed && !$completed && !$rows && !$deletes && !$updates;
}
elseif($mode === 'denied')
{
    $passed = $accessDenied && !$redirected && !$confirmed && !$completed && !$rows && !$deletes && !$updates && empty($GLOBALS['test_admin_logs']);
}
elseif(in_array($action, array('site_details_save', 'indexnow_rotate_key'), true))
{
    $writes = in_array($mode, array('post', 'sql'), true);
    $expectedUpdates = $writes ? ($action === 'site_details_save' ? 5 : 1) : 0;
    $expectedDeletes = $writes && $action === 'site_details_save' ? 1 : 0;
    $passed = $redirected && !$rows && count($deletes) === $expectedDeletes && count($updates) === $expectedUpdates;
    if($mode === 'invalid')
    {
        $passed = $passed && empty($GLOBALS['test_settings_rebuilt']);
    }
    if($writes && $action === 'site_details_save')
    {
        $expectedValues = $mode === 'sql'
            ? array("O\\'Brien Forum", "https://example.com/forum/o\\'brien", "O\\'Brien Network", "https://example.com/o\\'brien", "O\\'Brien\\'s community")
            : array('Updated Community', 'https://example.com/forum', 'Example Network', 'https://example.com/', 'Updated community description');
        $passed = $passed && $updates[0][0] === 'settings' && $updates[0][1]['value'] === $expectedValues[0]
            && $updates[1][1]['value'] === $expectedValues[1]
            && $updates[2][1]['value'] === $expectedValues[2]
            && $updates[3][1]['value'] === $expectedValues[3]
            && $updates[4][1]['value'] === $expectedValues[4]
            && (int)$updates[4][1]['gid'] === 0
            && $deletes[0] === array('settings', "name='vonseo_home_title'")
            && !empty($GLOBALS['test_settings_rebuilt'])
            && isset($GLOBALS['test_admin_logs'][0][0]) && $GLOBALS['test_admin_logs'][0][0] === 'vonseo_site_details_save';
    }
    elseif($mode === 'post')
    {
        $passed = $passed && $updates[0][0] === 'settings'
            && preg_match('/^[a-f0-9]{32}$/', $updates[0][1]['value'])
            && !empty($GLOBALS['test_settings_rebuilt']);
    }
}
elseif($action === 'redirect_save')
{
    $expectedRows = $mode === 'post' ? 1 : 0;
    $passed = $redirected && count($rows) === $expectedRows && !$deletes;
    if($mode === 'post')
    {
        $passed = $passed && $rows[0]['source_path'] === '/csrf-test' && $rows[0]['status_code'] === 410;
    }
}
elseif($action === 'redirects_import')
{
    $expectedRows = $mode === 'post' ? 1 : 0;
    $passed = ($mode === 'post' ? $redirected : $completed) && count($rows) === $expectedRows && !$deletes && !$updates;
    if($mode === 'post')
    {
        $passed = $passed && $rows[0]['source_path'] === '/import-old' && $rows[0]['target_url'] === 'https://example.com/forum/import-new'
            && isset($GLOBALS['test_admin_logs'][0][0]) && $GLOBALS['test_admin_logs'][0][0] === 'vonseo_redirects_import';
    }
}
else
{
    $expectedDeletes = $mode === 'post' ? 1 : 0;
    $passed = ($mode === 'post' ? $redirected : $confirmed) && !$rows && count($deletes) === $expectedDeletes;
    if($mode === 'post')
    {
        $expectedTable = $action === 'redirect_delete' ? 'vonseo_redirects' : 'vonseo_404_log';
        $passed = $passed && $deletes[0][0] === $expectedTable;
        if($action === 'notfound_clear_all')
        {
            $passed = $passed && $deletes[0][1] === '';
        }
        if($action === 'notfound_clear')
        {
            $passed = $passed && isset($GLOBALS['test_admin_logs'][0][0]) && $GLOBALS['test_admin_logs'][0][0] === 'vonseo_404_clear';
        }
    }
}

$expectedLogCount = in_array($mode, array('post', 'sql'), true) ? 1 : 0;
$passed = $passed && count($GLOBALS['test_admin_logs']) === $expectedLogCount;

echo ($passed ? '[PASS] ' : '[FAIL] ').$action.' '.strtoupper($mode).' '.count($rows).'/'.count($deletes).'/'.count($updates)." insert/delete/update writes\n";
exit($passed ? 0 : 1);
