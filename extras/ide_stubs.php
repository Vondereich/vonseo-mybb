<?php
/**
 * IDE Stubs for MyBB 1.8
 *
 * This file is never executed at runtime. It exists solely to provide
 * static analysis (Intelephense / PHP Tools) and IDE autocompletion
 * with definitions for MyBB core functions, constants, and globals.
 */

if(false)
{
    define('IN_MYBB', 1);
    define('IN_ADMINCP', 1);
    define('MYBB_ROOT', './');
    define('TABLE_PREFIX', 'mybb_');
    define('TIME_NOW', 0);

    /** @var pluginSystem $plugins */
    $plugins = new pluginSystem();

    /** @var DB_Base $db */
    $db = new DB_Base();

    /** @var MyBB $mybb */
    $mybb = new MyBB();

    /** @var datacache $cache */
    $cache = new datacache();

    /** @var MyLanguage $lang */
    $lang = new MyLanguage();

    /** @var templates $templates */
    $templates = new templates();

    /** @var Page $page */
    $page = new Page();

    class pluginSystem
    {
        /**
         * @param string $hook
         * @param string $function
         * @param int $priority
         * @param string $file
         * @return bool
         */
        public function add_hook($hook, $function, $priority = 10, $file = '') {}

        /**
         * @param string $hook
         * @param mixed $arguments
         * @return mixed
         */
        public function run_hooks($hook, &$arguments = '') {}
    }

    class MyBB
    {
        const INPUT_INT = 0;
        const INPUT_ARRAY = 1;

        /** @var array */
        public $settings = array();
        /** @var array */
        public $input = array();
        /** @var array */
        public $user = array();
        /** @var string */
        public $request_method = 'get';

        /**
         * @param string $name
         * @param mixed $default
         * @return mixed
         */
        public function get_input($name, $default = null) {}
    }

    class DB_Base
    {
        /** @var string MyBB database driver name, for example mysqli. */
        public $type = '';

        /**
         * @param string $table
         * @param string $fields
         * @param string $conditions
         * @param array $options
         * @return mixed
         */
        public function simple_select($table, $fields = "*", $conditions = "", $options = array()) {}

        /**
         * @param mixed $query
         * @param string $field
         * @param int $row
         * @return mixed
         */
        public function fetch_field($query, $field = "", $row = 0) {}

        /**
         * @param mixed $query
         * @return array|false
         */
        public function fetch_array($query) {}

        /**
         * @param string $table
         * @param array $array
         * @return int|bool
         */
        public function insert_query($table, $array) {}

        /**
         * @param string $table
         * @param array $array
         * @param string $conditions
         * @param int $limit
         * @return bool
         */
        public function update_query($table, $array, $conditions = "", $limit = 0) {}

        /**
         * @param string $table
         * @param array $replacements
         * @param string $default_field
         * @param bool $insert_id
         * @return int|bool
         */
        public function replace_query($table, $replacements = array(), $default_field = "", $insert_id = true) {}

        /**
         * @param string $table
         * @param string $conditions
         * @param int $limit
         * @return bool
         */
        public function delete_query($table, $conditions = "", $limit = 0) {}

        /**
         * @param string $table
         * @param bool $hard
         * @return bool
         */
        public function table_exists($table, $hard = false) {}

        /**
         * @param string $table
         * @param bool $hard
         * @return bool
         */
        public function drop_table($table, $hard = false) {}

        /**
         * @param string $query
         * @return mixed
         */
        public function write_query($query) {}

        /** @return int */
        public function affected_rows() {}

        /**
         * @param string $query
         * @return mixed
         */
        public function query($query) {}

        /**
         * @param string $string
         * @return string
         */
        public function escape_string($string) {}

        /**
         * @return string
         */
        public function build_create_table_collation() {}
    }

    class datacache
    {
        /**
         * @param string $name
         * @param bool $force
         * @return mixed
         */
        public function read($name, $force = false) {}

        public function update_forums() {}
    }

    class MyLanguage
    {
        /**
         * @param string $section
         * @param bool $admin
         * @param bool $force
         */
        public function load($section, $admin = false, $force = false) {}
    }

    class templates
    {
        /**
         * @param string $title
         * @param bool $eslashes
         * @param bool $htmlcomments
         * @return string
         */
        public function get($title, $eslashes = 1, $htmlcomments = 1) {}
    }

    class Page
    {
        /**
         * @param string $name
         * @param string $url
         */
        public function add_breadcrumb_item($name, $url = '') {}

        /**
         * @param array $tabs
         * @param string $active
         */
        public function output_nav_tabs($tabs, $active) {}

        /**
         * @param string $url
         * @param string $message
         */
        public function output_confirm_action($url, $message = '') {}

        /**
         * @param string $title
         */
        public function output_header($title = '') {}

        /**
         * @param array|string $errors
         */
        public function output_inline_error($errors) {}

        public function output_footer() {}
    }

    class Table
    {
        /**
         * @param string $text
         * @param array $extra
         */
        public function construct_header($text, $extra = array()) {}

        /**
         * @param string $data
         * @param array $extra
         */
        public function construct_cell($data, $extra = array()) {}

        /**
         * @param array $extra
         */
        public function construct_row($extra = array()) {}

        /**
         * @return int
         */
        public function num_rows() {}

        /**
         * @param string $heading
         */
        public function output($heading = '') {}
    }

    class Form
    {
        /**
         * @param string $script
         * @param string $method
         * @param string $id
         * @param int $allow_files
         */
        public function __construct($script = '', $method = 'post', $id = '', $allow_files = 0) {}

        /**
         * @param string $value
         * @param array $options
         * @return string
         */
        public function generate_submit_button($value, $options = array()) {}

        /**
         * @param array $buttons
         */
        public function output_submit_wrapper($buttons) {}

        /**
         * @param string $name
         * @param string $value
         * @param array $options
         * @return string
         */
        public function generate_text_box($name, $value = '', $options = array()) {}

        /**
         * @param string $name
         * @param string $value
         * @param array $options
         * @return string
         */
        public function generate_text_area($name, $value = '', $options = array()) {}

        /**
         * @param string $name
         * @param array $options
         * @return string
         */
        public function generate_file_upload_box($name, $options = array()) {}

        /**
         * @param string $name
         * @param array $options
         * @param mixed $selected
         * @param array $extra
         * @return string
         */
        public function generate_select_box($name, $options = array(), $selected = array(), $extra = array()) {}

        /**
         * @param string $name
         * @param int|string $value
         * @param string $label
         * @param array $options
         * @return string
         */
        public function generate_check_box($name, $value = 1, $label = '', $options = array()) {}

        /**
         * @param string $name
         * @param int|string $value
         * @param bool $int
         * @param array $options
         * @return string
         */
        public function generate_yes_no_radio($name, $value = 1, $int = true, $options = array()) {}

        /**
         * @param string $name
         * @param string $value
         * @return string
         */
        public function generate_hidden_field($name, $value = '') {}

        public function end() {}
    }

    class FormContainer
    {
        /**
         * @param string $title
         */
        public function __construct($title = '') {}

        /**
         * @param string $title
         * @param string $description
         * @param string $content
         * @param string $label_for
         * @param array $options
         */
        public function output_row($title, $description, $content, $label_for = '', $options = array()) {}

        public function end() {}
    }

    /**
     * @return void
     */
    function rebuild_settings() {}

    /**
     * @return array
     */
    function cache_forums() {}

    /**
     * @return string
     */
    function get_inactive_forums() {}

    /**
     * @param int $fid
     * @param int $uid
     * @param int $gid
     * @return array|false
     */
    function forum_permissions($fid = 0, $uid = 0, $gid = 0) {}

    /**
     * @param int $fid
     * @param int $recache
     * @return array|false
     */
    function get_forum($fid, $recache = 0) {}

    /**
     * @param int $tid
     * @param int $recache
     * @return array|false
     */
    function get_thread($tid, $recache = 0) {}

    /**
     * @param int $uid
     * @return array|false
     */
    function get_user($uid) {}

    /**
     * @param int $uid
     * @return string
     */
    function get_profile_link($uid = 0) {}

    /**
     * @param int $tid
     * @param int $page
     * @param string $action
     * @return string
     */
    function get_thread_link($tid, $page = 0, $action = '') {}

    /**
     * @param int $fid
     * @param int $page
     * @return string
     */
    function get_forum_link($fid, $page = 0) {}

    /**
     * @param int $aid
     * @return string
     */
    function get_announcement_link($aid = 0) {}

    /**
     * @param string $error
     * @param string $title
     */
    function error($error = "", $title = "") {}

    /**
     * @return void
     */
    function error_no_permission() {}

    /**
     * @param string $message
     * @param string $type
     */
    function flash_message($message, $type = 'success') {}

    /**
     * @param string $url
     */
    function admin_redirect($url) {}

    /**
     * @param string $action
     * @param mixed ...$args
     */
    function log_admin_action($action, ...$args) {}

    /**
     * @param string $message
     * @return string
     */
    function htmlspecialchars_uni($message) {}

    /**
     * @param string $format
     * @param int $stamp
     * @param string $offset
     * @param int $ty
     * @return string
     */
    function my_date($format, $stamp = 0, $offset = '', $ty = 1) {}

    /**
     * @param int|float|string $number
     * @return string
     */
    function my_number_format($number) {}
}
