<?php
/**
 * VonSEO for MyBB 1.8
 *
 * SEO subsystem for MyBB inspired by VonCMS SEO:
 * - one canonical URL resolver for canonical/OG/schema/sitemap
 * - server-rendered metadata
 * - public/guest permission baseline for indexing
 * - no core edits
 *
 * @package VonSEO
 * @version 1.0.2
 */

if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

require_once MYBB_ROOT.'inc/plugins/vonseo/Utils.php';
require_once MYBB_ROOT.'inc/plugins/vonseo/State.php';

/** @var pluginSystem $plugins */
global $plugins;

if(is_object($plugins))
{
    $plugins->add_hook('global_end', 'vonseo_request_start', 5);
    $plugins->add_hook('global_end', 'vonseo_keyword_action_redirect_early', 10);
    $plugins->add_hook('error', 'vonseo_error_hook', 1);
    $plugins->add_hook('no_permission', 'vonseo_no_permission_hook', 1);
    $plugins->add_hook('pre_output_page', 'vonseo_render_page', 20);
    $plugins->add_hook('postbit', 'vonseo_capture_guest_first_post', 1000);
    $plugins->add_hook('postbit_announcement', 'vonseo_capture_guest_announcement', 1000);
    $plugins->add_hook('calendar_end', 'vonseo_capture_guest_calendar', 1000);
    $plugins->add_hook('calendar_event_end', 'vonseo_capture_guest_event', 1000);
    $plugins->add_hook('forumdisplay_thread_end', 'vonseo_keyword_thread_listing_link');
    $plugins->add_hook('forumdisplay_end', 'vonseo_keyword_redirect_current');
    $plugins->add_hook('showthread_start', 'vonseo_keyword_redirect_current');
    $plugins->add_hook('multipage', 'vonseo_keyword_multipage');
    $plugins->add_hook('misc_start', 'vonseo_misc_router', 5);
    $plugins->add_hook('datahandler_post_insert_thread_end', 'vonseo_indexnow_new_thread');
    $plugins->add_hook('datahandler_post_insert_post_end', 'vonseo_indexnow_new_post');
    $plugins->add_hook('datahandler_post_update_end', 'vonseo_indexnow_update_post');
    $plugins->add_hook('class_moderation_delete_thread_start', 'vonseo_indexnow_delete_thread_start');
    $plugins->add_hook('class_moderation_delete_thread', 'vonseo_indexnow_delete_thread');
    $plugins->add_hook('class_moderation_approve_threads', 'vonseo_indexnow_approve_threads');
    $plugins->add_hook('class_moderation_unapprove_threads', 'vonseo_indexnow_unapprove_threads');
    $plugins->add_hook('class_moderation_soft_delete_threads', 'vonseo_indexnow_unapprove_threads');
    $plugins->add_hook('class_moderation_restore_threads', 'vonseo_indexnow_restore_threads');
    $plugins->add_hook('class_moderation_delete_post_start', 'vonseo_indexnow_delete_post_start');
    $plugins->add_hook('class_moderation_delete_post', 'vonseo_indexnow_delete_post');
    $plugins->add_hook('class_moderation_approve_posts', 'vonseo_indexnow_approve_posts');
    $plugins->add_hook('class_moderation_unapprove_posts', 'vonseo_indexnow_unapprove_posts');
    $plugins->add_hook('class_moderation_soft_delete_posts', 'vonseo_indexnow_unapprove_posts');
    $plugins->add_hook('class_moderation_restore_posts', 'vonseo_indexnow_approve_posts');

    if(defined('IN_ADMINCP'))
    {
        $plugins->add_hook('admin_config_menu', 'vonseo_admin_menu');
        $plugins->add_hook('admin_config_action_handler', 'vonseo_admin_action_handler');
        $plugins->add_hook('admin_config_permissions', 'vonseo_admin_permissions');
    }
}

function vonseo_info()
{
    return array(
        'name'          => 'VonSEO for MyBB',
        'description'   => 'SEO subsystem for MyBB: canonical/meta/schema, sitemap, redirects, real 404/410 responses, bounded 404 monitoring, queued IndexNow and crawler controls.',
        'website'       => 'https://github.com/Vondereich/',
        'author'        => 'Vondereich',
        'authorsite'    => 'https://github.com/Vondereich/',
        'version'       => VONSEO_VERSION,
        'codename'      => 'vonseo',
        'compatibility' => '18*'
    );
}

function vonseo_is_installed()
{
    global $db;

    $query = $db->simple_select('settinggroups', 'gid', "name='vonseo'", array('limit' => 1));
    return (bool)$db->fetch_field($query, 'gid');
}

function vonseo_install()
{
    global $db;

    vonseo_require_supported_database();

    $query = $db->simple_select('settinggroups', 'gid', "name='vonseo'", array('limit' => 1));
    $gid = (int)$db->fetch_field($query, 'gid');

    if($gid <= 0)
    {
        $group = array(
            'name'        => 'vonseo',
            'title'       => 'VonSEO',
            'description' => 'Search engine, social metadata, schema and crawler settings for VonSEO.',
            'disporder'   => 35,
            'isdefault'   => 0
        );

        $gid = (int)$db->insert_query('settinggroups', $group);
    }

    $settings = array(
        array(
            'name'        => 'vonseo_enabled',
            'title'       => 'Enable VonSEO',
            'description' => 'Master switch for all VonSEO HTML metadata output and crawler endpoints.',
            'optionscode' => 'onoff',
            'value'       => '1',
            'disporder'   => 1
        ),
        array(
            'name'        => 'vonseo_site_description',
            'title'       => 'Site Description',
            'description' => 'Default meta description used for the board homepage and as a fallback when a page has no useful description.',
            'optionscode' => 'textarea',
            'value'       => '',
            'disporder'   => 2,
            // Stored in MyBB's settings cache, but edited only from VonSEO Site Details.
            'hidden'      => true
        ),
        array(
            'name'        => 'vonseo_title_separator',
            'title'       => 'Title Separator',
            'description' => 'Separator used between page titles and the board name.',
            'optionscode' => 'text',
            'value'       => ' - ',
            'disporder'   => 4
        ),
        array(
            'name'        => 'vonseo_default_image',
            'title'       => 'Default Social Image',
            'description' => 'Absolute or board-relative image URL used when a page has no suitable image.',
            'optionscode' => 'text',
            'value'       => '',
            'disporder'   => 5
        ),
        array(
            'name'        => 'vonseo_organization_logo',
            'title'       => 'Organization Logo',
            'description' => 'Optional dedicated logo URL for Organization structured data. Leave blank if the social image is not your official logo.',
            'optionscode' => 'text',
            'value'       => '',
            'disporder'   => 6
        ),
        array(
            'name'        => 'vonseo_thread_attachment_image',
            'title'       => 'Use First Thread Image Attachment',
            'description' => 'Use the first visible image attachment in a public thread before falling back to the default social image.',
            'optionscode' => 'onoff',
            'value'       => '1',
            'disporder'   => 7
        ),
        array(
            'name'        => 'vonseo_replace_existing_meta',
            'title'       => 'Own SEO Meta Tags',
            'description' => 'Remove matching canonical, description, robots, Open Graph and Twitter tags before VonSEO injects its own values. Helps avoid duplicate tags from themes.',
            'optionscode' => 'onoff',
            'value'       => '1',
            'disporder'   => 8
        ),
        array(
            'name'        => 'vonseo_profile_index',
            'title'       => 'Index Member Profiles',
            'description' => 'Allow public member profile pages to be indexed. Disabled by default.',
            'optionscode' => 'onoff',
            'value'       => '0',
            'disporder'   => 9
        ),
        array(
            'name'        => 'vonseo_portal_index',
            'title'       => 'Index Portal',
            'description' => 'Allow portal.php to be indexed.',
            'optionscode' => 'onoff',
            'value'       => '1',
            'disporder'   => 10
        ),
        array(
            'name'        => 'vonseo_sitemap_enabled',
            'title'       => 'Enable XML Sitemap',
            'description' => 'Expose the VonSEO sitemap endpoint through misc.php?action=vonseo_sitemap.',
            'optionscode' => 'onoff',
            'value'       => '1',
            'disporder'   => 11
        ),
        array(
            'name'        => 'vonseo_sitemap_chunk',
            'title'       => 'Sitemap Thread Chunk Size',
            'description' => 'Threads per sitemap page. VonCMS-style default is 1000. Maximum 50000.',
            'optionscode' => 'numeric',
            'value'       => '1000',
            'disporder'   => 12
        ),
        array(
            'name'        => 'vonseo_redirects_enabled',
            'title'       => 'Enable Redirect Engine',
            'description' => 'Apply VonSEO redirect rules before MyBB renders the requested page.',
            'optionscode' => 'onoff',
            'value'       => '1',
            'disporder'   => 13
        ),
        array(
            'name'        => 'vonseo_external_redirects',
            'title'       => 'Allow External Redirect Targets',
            'description' => 'Allow redirect rules to point to another hostname. Disabled by default for safety.',
            'optionscode' => 'onoff',
            'value'       => '0',
            'disporder'   => 14
        ),
        array(
            'name'        => 'vonseo_404_enabled',
            'title'       => 'Enable 404 Status Guard',
            'description' => 'Convert missing content error pages to real HTTP 404 responses instead of successful 200 responses.',
            'optionscode' => 'onoff',
            'value'       => '1',
            'disporder'   => 15
        ),
        array(
            'name'        => 'vonseo_404_message',
            'title'       => 'Custom 404 Message',
            'description' => 'Message shown when the optional VonSEO custom 404 endpoint is used.',
            'optionscode' => 'textarea',
            'value'       => 'The requested page could not be found.',
            'disporder'   => 16
        ),
        array(
            'name'        => 'vonseo_404_monitor',
            'title'       => 'Enable 404 Monitor',
            'description' => 'Aggregate missing public URLs in the VonSEO Admin CP without storing visitor IP addresses.',
            'optionscode' => 'onoff',
            'value'       => '1',
            'disporder'   => 17
        ),
        array(
            'name'        => 'vonseo_robots_enabled',
            'title'       => 'Enable Robots Output',
            'description' => 'Expose crawler rules through misc.php?action=vonseo_robots. An optional rewrite can map /robots.txt to this endpoint.',
            'optionscode' => 'onoff',
            'value'       => '1',
            'disporder'   => 18
        ),
        array(
            'name'        => 'vonseo_robots_txt',
            'title'       => 'Robots Rules',
            'description' => 'Rules placed before the Sitemap line. Custom Crawl-delay directives are preserved. Paths are generated for the configured MyBB subfolder on new installations.',
            'optionscode' => 'textarea',
            'value'       => vonseo_default_robots_rules(),
            'disporder'   => 19
        ),
        array(
            'name'        => 'vonseo_searchbox_schema',
            'title'       => 'Enable Legacy SearchAction Schema',
            'description' => 'Optional WebSite SearchAction JSON-LD. Google retired the Sitelinks Search Box result in 2024; this setting does not restore it.',
            'optionscode' => 'onoff',
            'value'       => '0',
            'disporder'   => 20
        ),
        array(
            'name'        => 'vonseo_block_ai_crawlers',
            'title'       => 'Block AI Training & Scraper Crawlers',
            'description' => 'Block training and scraper crawlers such as GPTBot, ClaudeBot, Google-Extended, Bytespider and CCBot. This does not block ChatGPT search.',
            'optionscode' => 'onoff',
            'value'       => '0',
            'disporder'   => 21
        ),
        array(
            'name'        => 'vonseo_block_ai_search_crawlers',
            'title'       => 'Block AI Search Crawlers',
            'description' => 'Optionally block OAI-SearchBot and PerplexityBot. Leave off if you want public forum pages eligible for AI search results.',
            'optionscode' => 'onoff',
            'value'       => '0',
            'disporder'   => 22
        ),
        array(
            'name'        => 'vonseo_indexnow_enabled',
            'title'       => 'Enable IndexNow Background Indexing',
            'description' => 'Queue public thread URLs and submit them to participating search engines through a MyBB background task.',
            'optionscode' => 'onoff',
            'value'       => '1',
            'disporder'   => 23
        ),
        array(
            'name'        => 'vonseo_indexnow_key',
            'title'       => 'IndexNow API Key',
            'description' => 'The 32-character hexadecimal key for IndexNow verification. Generated on installation; submissions stop if this setting is cleared.',
            'optionscode' => 'text',
            'value'       => VonSEO_Utils::randomHex(16),
            'disporder'   => 24
        ),
        array(
            'name'        => 'vonseo_indexnow_auto_ping',
            'title'       => 'Auto-Ping on New Content',
            'description' => 'Queue thread URLs after creation, reply, or update. Visitor requests do not wait for the external IndexNow service.',
            'optionscode' => 'onoff',
            'value'       => '1',
            'disporder'   => 25
        ),
        array(
            'name'        => 'vonseo_keyword_urls',
            'title'       => 'Keyword Thread and Forum URLs (Experimental)',
            'description' => 'Use ID-based keyword URLs for public forum/thread canonical links, navigation, exact post targets, stock thread actions, sitemaps and IndexNow. Requires the matching Apache-style or Nginx keyword rules supplied in extras/. Test direct visits on your board before enabling; filtered/display-mode links remain native.',
            'optionscode' => 'onoff',
            'value'       => '0',
            'disporder'   => 26
        )
    );

    $existing = array();
    $query = $db->simple_select('settings', 'name,value', "gid='{$gid}'");
    while($row = $db->fetch_array($query))
    {
        $existing[$row['name']] = $row;
    }

    // The description lives outside the visible group so Advanced Settings
    // contains technical controls only. Load it separately on later repairs.
    $hiddenQuery = $db->simple_select('settings', 'name,value', "name='vonseo_site_description'", array('limit' => 1));
    $hiddenRow = $db->fetch_array($hiddenQuery);
    if($hiddenRow)
    {
        $existing[$hiddenRow['name']] = $hiddenRow;
    }

    foreach($settings as $setting)
    {
        $targetGid = !empty($setting['hidden']) ? 0 : $gid;
        unset($setting['hidden']);

        if(isset($existing[$setting['name']]))
        {
            // Refresh presentation metadata without resetting an administrator's saved value.
            $metadata = array(
                'gid' => $targetGid,
                'title' => $db->escape_string($setting['title']),
                'description' => $db->escape_string($setting['description']),
                'optionscode' => $db->escape_string($setting['optionscode']),
                'disporder' => (int)$setting['disporder']
            );
            if($setting['name'] === 'vonseo_robots_txt' && vonseo_is_legacy_default_robots($existing[$setting['name']]['value']))
            {
                $metadata['value'] = $db->escape_string($setting['value']);
            }
            $db->update_query('settings', $metadata, "name='".$db->escape_string($setting['name'])."'");
            continue;
        }

        $setting['gid'] = $targetGid;
        foreach($setting as $field => $value)
        {
            if(is_string($value))
            {
                $setting[$field] = $db->escape_string($value);
            }
        }
        $db->insert_query('settings', $setting);
    }

    // Remove the retired duplicate title source during install, activation or upgrade.
    $db->delete_query('settings', "name='vonseo_home_title'");

    vonseo_run_migrations();
    VonSEO_State::refreshRedirectCount();
    VonSEO_State::refresh404Count();
    VonSEO_State::refreshQueueDepth();

    rebuild_settings();
    return true;
}

/**
 * VonSEO supports the same database family as its tested atomic queries.
 * Test doubles without a declared type are accepted by the self-contained suite.
 *
 * @return bool
 */
function vonseo_database_supported()
{
    global $db;

    if(!is_object($db))
    {
        return false;
    }

    $type = isset($db->type) ? strtolower((string)$db->type) : '';
    if($type !== '')
    {
        return in_array($type, array('mysql', 'mysqli'), true);
    }

    $class = strtolower(get_class($db));
    return strpos($class, 'pgsql') === false && strpos($class, 'sqlite') === false;
}

/**
 * Stop installation or activation before MyBB can register partial state on an
 * unsupported database. MyBB 1.8 ignores plugin callback return values, so an
 * explicit redirect/exception is required instead of returning false.
 *
 * @return void
 * @throws RuntimeException
 */
function vonseo_require_supported_database()
{
    if(vonseo_database_supported())
    {
        return;
    }

    $message = 'VonSEO requires MySQL or MariaDB because its migration and atomic logging queries use that database family.';
    if(defined('IN_ADMINCP') && function_exists('flash_message') && function_exists('admin_redirect'))
    {
        flash_message($message, 'error');
        admin_redirect('index.php?module=config-plugins');
    }

    throw new RuntimeException($message);
}

/** @return string */
function vonseo_default_robots_rules()
{
    global $mybb;

    $path = '';
    if(isset($mybb->settings['bburl']))
    {
        $parts = @parse_url($mybb->settings['bburl']);
        if(is_array($parts) && !empty($parts['path']))
        {
            $path = '/'.trim($parts['path'], '/');
        }
    }

    return "User-agent: *\n"
        .'Disallow: '.$path."/admin/\n"
        .'Disallow: '.$path."/usercp.php\n"
        .'Disallow: '.$path."/private.php\n"
        .'Disallow: '.$path."/modcp.php";
}

/** @param string $value @return bool */
function vonseo_is_legacy_default_robots($value)
{
    $value = str_replace("\r\n", "\n", trim((string)$value));
    $legacy = "User-agent: *\nDisallow: /admin/\nDisallow: /usercp.php\nDisallow: /private.php\nDisallow: /search.php\nDisallow: /modcp.php";
    return $value === $legacy;
}

/**
 * Apply each database change once while keeping beta-era data intact.
 *
 * @return void
 */
function vonseo_run_migrations()
{
    VonSEO_State::ensureSchemaStore();
    $version = VonSEO_State::schemaVersion();

    if($version < 1)
    {
        vonseo_migrate_schema_1();
        VonSEO_State::setSchemaVersion(1);
        $version = 1;
    }
    else
    {
        vonseo_migrate_schema_1();
    }

    if($version < 2)
    {
        vonseo_migrate_schema_2();
        VonSEO_State::setSchemaVersion(2);
    }
    else
    {
        vonseo_migrate_schema_2();
    }

    if($version < 3)
    {
        vonseo_migrate_schema_3();
        VonSEO_State::setSchemaVersion(3);
    }
    else
    {
        vonseo_migrate_schema_3();
    }

    if($version < 4)
    {
        vonseo_migrate_schema_4();
        VonSEO_State::setSchemaVersion(4);
    }
    else
    {
        vonseo_migrate_schema_4();
    }

    if($version < 5)
    {
        vonseo_migrate_schema_5();
        VonSEO_State::setSchemaVersion(5);
    }
    else
    {
        vonseo_migrate_schema_5();
    }
}

/**
 * Establish the redirect and 404 tables used by the earlier beta series.
 *
 * @return void
 */
function vonseo_migrate_schema_1()
{
    global $db;

    if(!$db->table_exists('vonseo_redirects'))
    {
        $collation = $db->build_create_table_collation();
        $db->write_query("CREATE TABLE ".TABLE_PREFIX."vonseo_redirects (
            rid INT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_hash CHAR(64) NOT NULL,
            source_path VARCHAR(768) NOT NULL,
            target_url VARCHAR(1536) NOT NULL DEFAULT '',
            status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
            first_hit INT UNSIGNED NOT NULL DEFAULT 0,
            last_hit INT UNSIGNED NOT NULL DEFAULT 0,
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (rid),
            UNIQUE KEY source_hash (source_hash),
            KEY enabled (enabled),
            KEY last_hit (last_hit)
        ) {$collation};");
    }

    if(!$db->table_exists('vonseo_404_log'))
    {
        $collation = $db->build_create_table_collation();
        $db->write_query("CREATE TABLE ".TABLE_PREFIX."vonseo_404_log (
            path_hash CHAR(64) NOT NULL,
            path VARCHAR(768) NOT NULL,
            hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
            first_seen INT UNSIGNED NOT NULL DEFAULT 0,
            last_seen INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (path_hash),
            KEY last_seen (last_seen),
            KEY hits (hits)
        ) {$collation};");
    }
}

/**
 * Add the durable IndexNow queue. Task registration belongs to activation.
 *
 * @return void
 */
function vonseo_migrate_schema_2()
{
    global $db;

    if(!$db->table_exists('vonseo_indexnow_queue'))
    {
        $collation = $db->build_create_table_collation();
        $db->write_query("CREATE TABLE ".TABLE_PREFIX."vonseo_indexnow_queue (
            qid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tid INT UNSIGNED NOT NULL DEFAULT 0,
            generation CHAR(32) NOT NULL DEFAULT '',
            event_type VARCHAR(16) NOT NULL DEFAULT 'current',
            url_hash CHAR(64) NOT NULL,
            url VARCHAR(1536) NOT NULL,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            available_at INT UNSIGNED NOT NULL DEFAULT 0,
            retry_not_before INT UNSIGNED NOT NULL DEFAULT 0,
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (qid),
            UNIQUE KEY tid (tid),
            UNIQUE KEY url_hash (url_hash),
            KEY available_at (available_at),
            KEY retry_not_before (retry_not_before),
            KEY attempts (attempts)
        ) {$collation};");
    }
}

/** Upgrade beta queues to entity-keyed delivery and discard unverifiable rows. */
function vonseo_migrate_schema_3()
{
    global $db;

    if(!$db->table_exists('vonseo_indexnow_queue'))
    {
        vonseo_migrate_schema_2();
        return;
    }

    if(method_exists($db, 'field_exists') && !$db->field_exists('tid', 'vonseo_indexnow_queue'))
    {
        $db->write_query("ALTER TABLE ".TABLE_PREFIX."vonseo_indexnow_queue ADD tid INT UNSIGNED NOT NULL DEFAULT 0 AFTER qid");
        // Old rows contain only a URL snapshot and cannot be safely revalidated.
        $db->delete_query('vonseo_indexnow_queue', "tid='0'");
    }

    if(method_exists($db, 'field_exists') && !$db->field_exists('generation', 'vonseo_indexnow_queue'))
    {
        $db->write_query("ALTER TABLE ".TABLE_PREFIX."vonseo_indexnow_queue ADD generation CHAR(32) NOT NULL DEFAULT '' AFTER tid");
        $db->delete_query('vonseo_indexnow_queue', "generation='' OR tid='0'");
    }

    if(method_exists($db, 'index_exists') && !$db->index_exists('vonseo_indexnow_queue', 'tid'))
    {
        $db->write_query("ALTER TABLE ".TABLE_PREFIX."vonseo_indexnow_queue ADD UNIQUE KEY tid (tid)");
    }
}

/** Add moderation-removal events and per-thread delivery cooldown history. */
function vonseo_migrate_schema_4()
{
    global $db;

    if(!$db->table_exists('vonseo_indexnow_queue'))
    {
        vonseo_migrate_schema_2();
    }

    if(method_exists($db, 'field_exists') && !$db->field_exists('event_type', 'vonseo_indexnow_queue'))
    {
        $db->write_query("ALTER TABLE ".TABLE_PREFIX."vonseo_indexnow_queue ADD event_type VARCHAR(16) NOT NULL DEFAULT 'current' AFTER generation");
    }

    if(!$db->table_exists('vonseo_indexnow_threads'))
    {
        $collation = $db->build_create_table_collation();
        $db->write_query("CREATE TABLE ".TABLE_PREFIX."vonseo_indexnow_threads (
            tid INT UNSIGNED NOT NULL,
            last_public_url VARCHAR(1536) NOT NULL DEFAULT '',
            last_submitted_at INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (tid),
            KEY last_submitted_at (last_submitted_at),
            KEY updated_at (updated_at)
        ) {$collation};");
    }
}

/** Keep transport retry boundaries separate from content debounce timing. */
function vonseo_migrate_schema_5()
{
    global $db;

    if(!$db->table_exists('vonseo_indexnow_queue'))
    {
        vonseo_migrate_schema_2();
    }

    if(method_exists($db, 'field_exists') && !$db->field_exists('retry_not_before', 'vonseo_indexnow_queue'))
    {
        $db->write_query("ALTER TABLE ".TABLE_PREFIX."vonseo_indexnow_queue ADD retry_not_before INT UNSIGNED NOT NULL DEFAULT 0 AFTER available_at");
    }

    if(method_exists($db, 'index_exists') && !$db->index_exists('vonseo_indexnow_queue', 'retry_not_before'))
    {
        $db->write_query("ALTER TABLE ".TABLE_PREFIX."vonseo_indexnow_queue ADD KEY retry_not_before (retry_not_before)");
    }
}

/** Register or repair the scheduled worker when the plugin is active. */
function vonseo_enable_indexnow_task()
{
    global $db, $cache;

    $now = defined('TIME_NOW') ? (int)TIME_NOW : time();
    $query = $db->simple_select('tasks', 'tid', "file='vonseo_indexnow'", array('limit' => 1));
    $tid = (int)$db->fetch_field($query, 'tid');
    $data = array(
        'title' => 'VonSEO IndexNow Queue',
        'description' => 'Submits queued public URLs to IndexNow outside visitor requests.',
        'minute' => '*', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*',
        'nextrun' => $now + 60,
        'enabled' => 1,
        'logging' => 1,
        'locked' => 0
    );

    if($tid > 0)
    {
        $db->update_query('tasks', $data, "tid='{$tid}'");
    }
    else
    {
        $data['file'] = 'vonseo_indexnow';
        $data['lastrun'] = 0;
        $db->insert_query('tasks', $data);
    }

    if(is_object($cache) && method_exists($cache, 'update_tasks'))
    {
        $cache->update_tasks();
    }
}

/** Stop the worker while preserving its schedule for a later reactivation. */
function vonseo_disable_indexnow_task()
{
    global $db, $cache;
    $db->update_query('tasks', array('enabled' => 0, 'locked' => 0), "file='vonseo_indexnow'");
    if(is_object($cache) && method_exists($cache, 'update_tasks'))
    {
        $cache->update_tasks();
    }
}

/** Remove the task and its logs during uninstall. */
function vonseo_remove_indexnow_task()
{
    global $db, $cache;
    $query = $db->simple_select('tasks', 'tid', "file='vonseo_indexnow'");
    while($task = $db->fetch_array($query))
    {
        $tid = (int)$task['tid'];
        if($tid > 0)
        {
            $db->delete_query('tasklog', "tid='{$tid}'");
        }
    }
    $db->delete_query('tasks', "file='vonseo_indexnow'");
    if(is_object($cache) && method_exists($cache, 'update_tasks'))
    {
        $cache->update_tasks();
    }
}

function vonseo_uninstall()
{
    global $db;

    $query = $db->simple_select('settinggroups', 'gid', "name='vonseo'", array('limit' => 1));
    $gid = (int)$db->fetch_field($query, 'gid');

    if($gid > 0)
    {
        $db->delete_query('settings', "gid='{$gid}'");
    }

    // Also remove orphaned VonSEO settings if an older install lost its group.
    $query = $db->simple_select('settings', 'name', "name LIKE 'vonseo_%'");
    $orphanNames = array();
    while($row = $db->fetch_array($query))
    {
        if(strpos($row['name'], 'vonseo_') === 0)
        {
            $orphanNames[] = $row['name'];
        }
    }
    foreach($orphanNames as $name)
    {
        $db->delete_query('settings', "name='".$db->escape_string($name)."'");
    }

    $db->delete_query('settinggroups', "name='vonseo'");

    if($db->table_exists('vonseo_redirects'))
    {
        $db->drop_table('vonseo_redirects');
    }
    if($db->table_exists('vonseo_404_log'))
    {
        $db->drop_table('vonseo_404_log');
    }
    if($db->table_exists('vonseo_indexnow_queue'))
    {
        $db->drop_table('vonseo_indexnow_queue');
    }
    if($db->table_exists('vonseo_indexnow_threads'))
    {
        $db->drop_table('vonseo_indexnow_threads');
    }
    if($db->table_exists('vonseo_meta'))
    {
        $db->drop_table('vonseo_meta');
    }
    vonseo_remove_indexnow_task();
    VonSEO_State::forget();

    rebuild_settings();
}

function vonseo_activate()
{
    vonseo_require_supported_database();
    // Also repairs/extends settings when upgrading from an older VonSEO build.
    vonseo_install();
    vonseo_enable_indexnow_task();
}

function vonseo_deactivate()
{
    vonseo_disable_indexnow_task();
    rebuild_settings();
}

function vonseo_require_core()
{
    static $loaded = false;

    if($loaded)
    {
        return;
    }

    $base = MYBB_ROOT.'inc/plugins/vonseo/';

    require_once $base.'Utils.php';
    require_once $base.'State.php';
    require_once $base.'Url.php';
    require_once $base.'Keyword.php';
    require_once $base.'Redirects.php';
    require_once $base.'Errors.php';
    require_once $base.'Context.php';
    require_once $base.'Robots.php';
    require_once $base.'Social.php';
    require_once $base.'Schema.php';
    require_once $base.'Meta.php';
    require_once $base.'Sitemap.php';
    require_once $base.'IndexNow.php';
    require_once $base.'Core.php';

    $loaded = true;
}

/**
 * @param string $page
 * @return string
 */
function vonseo_render_page($page)
{
    global $mybb;

    if(empty($mybb->settings['vonseo_enabled']))
    {
        return $page;
    }

    vonseo_require_core();

    $engine = new VonSEO_Core();
    $rendered = $engine->render($page);
    if(VonSEO_Errors::isErrorResponse())
    {
        return $rendered;
    }

    $keyword = new VonSEO_Keyword();
    return $keyword->rewriteHtml($rendered, VonSEO_Utils::currentScript());
}

/** Redirect only a successfully opened public entity and an ordinary GET/HEAD URL. */
function vonseo_keyword_action_redirect_early()
{
    global $mybb;

    if(empty($mybb->settings['vonseo_enabled']) || empty($_SERVER['REQUEST_URI']))
    {
        return;
    }

    vonseo_require_core();
    if(VonSEO_Utils::currentScript() !== 'showthread.php')
    {
        return;
    }

    $keyword = new VonSEO_Keyword();
    $request = $keyword->parseLink($_SERVER['REQUEST_URI']);
    if(!$request || $request['kind'] !== 'thread' || empty($request['action']))
    {
        return;
    }

    $threadData = function_exists('get_thread') ? get_thread($request['id']) : false;
    $forumData = is_array($threadData) && !empty($threadData['fid']) && function_exists('get_forum')
        ? get_forum((int)$threadData['fid']) : false;
    if(!is_array($threadData) || !is_array($forumData))
    {
        return;
    }

    $decision = $keyword->redirectDecision($_SERVER['REQUEST_URI'],
        isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
        'showthread.php', $threadData, $forumData);
    vonseo_keyword_send_redirect($decision);
}

/** Redirect only a successfully opened public entity and an ordinary GET/HEAD URL. */
function vonseo_keyword_redirect_current()
{
    global $mybb, $thread, $forum, $foruminfo;

    if(empty($mybb->settings['vonseo_enabled']) || empty($_SERVER['REQUEST_URI']))
    {
        return;
    }

    $script = VonSEO_Utils::currentScript();
    if($script !== 'showthread.php' && $script !== 'forumdisplay.php')
    {
        return;
    }

    vonseo_require_core();
    $entity = $script === 'showthread.php' ? $thread : $foruminfo;
    if(!is_array($entity))
    {
        return;
    }

    $keyword = new VonSEO_Keyword();
    $decision = $keyword->redirectDecision($_SERVER['REQUEST_URI'],
        isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
        $script, $entity, is_array($forum) ? $forum : array());
    vonseo_keyword_send_redirect($decision);
}

/**
 * @param array|false $decision
 * @return void
 */
function vonseo_keyword_send_redirect($decision)
{
    if(!$decision || headers_sent())
    {
        return;
    }

    if($decision['status'] === 302)
    {
        header('Cache-Control: no-store, max-age=0', true);
    }
    header('Location: '.$decision['location'], true, $decision['status']);
    exit;
}

/**
 * MyBB passes the URL template by reference inside the multipage hook.
 *
 * @param array $args
 * @return void
 */
function vonseo_keyword_multipage(&$args)
{
    global $mybb;

    if(empty($mybb->settings['vonseo_enabled']) || !is_array($args))
    {
        return;
    }

    vonseo_require_core();
    $keyword = new VonSEO_Keyword();
    $keyword->rewriteMultipage($args);
}

/**
 * @param array $post
 * @return array
 */
function vonseo_capture_guest_first_post($post)
{
    global $mybb;

    if(!empty($mybb->settings['vonseo_enabled']) && is_array($post))
    {
        vonseo_require_core();
        VonSEO_Context::captureGuestFirstPost($post);
    }

    return $post;
}

/**
 * @param array $post
 * @return array
 */
function vonseo_capture_guest_announcement($post)
{
    global $mybb;

    if(!empty($mybb->settings['vonseo_enabled']) && is_array($post))
    {
        vonseo_require_core();
        VonSEO_Context::captureGuestAnnouncement($post);
    }

    return $post;
}

/** Capture the resolved guest-visible calendar month before template rendering. */
function vonseo_capture_guest_calendar()
{
    global $mybb, $calendar, $calendar_permissions, $year, $month, $monthnames;

    if(empty($mybb->settings['vonseo_enabled']) || !is_array($calendar) ||
       !is_array($calendar_permissions))
    {
        return;
    }

    vonseo_require_core();
    $monthNumber = (int)$month;
    $yearNumber = (int)$year;
    $monthName = is_array($monthnames) && isset($monthnames[$monthNumber])
        ? $monthnames[$monthNumber] : gmdate('F', gmmktime(0, 0, 0, $monthNumber, 1, $yearNumber));
    $dateExplicit = !empty($mybb->input['year']) && !empty($mybb->input['month']);
    VonSEO_Context::captureGuestCalendar(
        $calendar,
        $calendar_permissions,
        $yearNumber,
        $monthNumber,
        $monthName.' '.$yearNumber,
        $dateExplicit
    );
}

/** Capture MyBB's guest-rendered public event before template rendering. */
function vonseo_capture_guest_event()
{
    global $mybb, $event, $calendar, $calendar_permissions;

    if(empty($mybb->settings['vonseo_enabled']) || !is_array($event) ||
       !is_array($calendar) || !is_array($calendar_permissions))
    {
        return;
    }

    vonseo_require_core();
    VonSEO_Context::captureGuestEvent($event, $calendar, $calendar_permissions);
}

/**
 * Use the keyword URL for the main thread-title link in a public forum list.
 * MyBB has already prepared the row, but still has the original subject in
 * threadcache. Other links and protected/moderator rows keep MyBB's URL.
 */
function vonseo_keyword_thread_listing_link()
{
    global $mybb, $thread, $threadcache, $foruminfo;

    if(empty($mybb->settings['vonseo_enabled']) || empty($mybb->settings['vonseo_keyword_urls']) ||
       !is_array($thread) || !is_array($threadcache) || !is_array($foruminfo) ||
       empty($foruminfo['fid']) || empty($thread['tid']) ||
       !isset($thread['visible']) || (int)$thread['visible'] !== 1 ||
       (!empty($thread['closed']) && strpos((string)$thread['closed'], 'moved|') === 0))
    {
        return;
    }

    $tid = (int)$thread['tid'];
    if(empty($threadcache[$tid]['subject']))
    {
        return;
    }

    vonseo_require_core();

    static $publicForums = array();
    $fid = (int)$foruminfo['fid'];
    if(!array_key_exists($fid, $publicForums))
    {
        $context = new VonSEO_Context(new VonSEO_Url());
        $publicForums[$fid] = $context->isForumPublic($foruminfo);
    }

    if(!$publicForums[$fid])
    {
        return;
    }

    $url = new VonSEO_Url();
    $thread['threadlink'] = VonSEO_Utils::h($url->thread($tid, 0, $threadcache[$tid]['subject']));
}

function vonseo_misc_router()
{
    global $mybb;

    if(empty($mybb->settings['vonseo_enabled']))
    {
        return;
    }

    $action = $mybb->get_input('action');

    if($action !== 'vonseo_sitemap' && $action !== 'vonseo_robots' && $action !== 'vonseo_404' && $action !== 'vonseo_route' && $action !== 'vonseo_indexnow_key')
    {
        return;
    }

    vonseo_require_core();

    if($action === 'vonseo_indexnow_key')
    {
        if(empty($mybb->settings['vonseo_indexnow_enabled']))
        {
            error_no_permission();
        }

        $indexnow = new VonSEO_IndexNow();
        $indexnow->outputKey();
    }

    if($action === 'vonseo_sitemap')
    {
        if(empty($mybb->settings['vonseo_sitemap_enabled']))
        {
            error_no_permission();
        }

        $sitemap = new VonSEO_Sitemap();
        $sitemap->output();
    }

    if($action === 'vonseo_route')
    {
        if(empty($mybb->settings['vonseo_404_enabled']))
        {
            error_no_permission();
        }

        $GLOBALS['vonseo_forced_status'] = 404;
        $GLOBALS['vonseo_error_seen'] = true;
        $message = trim(VonSEO_Utils::setting('vonseo_404_message', 'The requested page could not be found.'));
        if($message === '')
        {
            $message = 'The requested page could not be found.';
        }
        error($message, 'Page Not Found');
    }

    if($action === 'vonseo_404')
    {
        if(empty($mybb->settings['vonseo_404_enabled']))
        {
            error_no_permission();
        }

        $GLOBALS['vonseo_forced_status'] = 404;
        $GLOBALS['vonseo_error_seen'] = true;
        $message = trim(VonSEO_Utils::setting('vonseo_404_message', 'The requested page could not be found.'));
        if($message === '')
        {
            $message = 'The requested page could not be found.';
        }
        error($message, 'Page Not Found');
    }

    if($action === 'vonseo_robots')
    {
        if(empty($mybb->settings['vonseo_robots_enabled']))
        {
            error_no_permission();
        }

        $robots = new VonSEO_Robots();
        $robots->outputTxt();
    }

    exit;
}

function vonseo_request_start()
{
    global $mybb;

    if(empty($mybb->settings['vonseo_enabled']) || empty($mybb->settings['vonseo_redirects_enabled']))
    {
        return;
    }

    vonseo_require_core();
    $redirects = new VonSEO_Redirects();
    $redirects->handleRequest();
}

/**
 * @param string $message
 * @return string
 */
function vonseo_error_hook($message)
{
    global $mybb;

    if(!empty($mybb->settings['vonseo_enabled']))
    {
        vonseo_require_core();
        return VonSEO_Errors::markError($message);
    }

    return $message;
}

function vonseo_no_permission_hook()
{
    global $mybb;

    if(!empty($mybb->settings['vonseo_enabled']))
    {
        vonseo_require_core();
        VonSEO_Errors::markNoPermission();
    }
}

/**
 * @param array $sub_menu
 * @return array
 */
function vonseo_admin_menu($sub_menu)
{
    $sub_menu['185'] = array(
        'id' => 'vonseo',
        'title' => 'VonSEO',
        'link' => 'index.php?module=config-vonseo'
    );
    return $sub_menu;
}

/**
 * @param array $actions
 * @return array
 */
function vonseo_admin_action_handler($actions)
{
    $actions['vonseo'] = array(
        'active' => 'vonseo',
        'file' => 'vonseo.php'
    );
    return $actions;
}

/**
 * @param array $permissions
 * @return array
 */
function vonseo_admin_permissions($permissions)
{
    $permissions['vonseo'] = 'Can view VonSEO health and manage redirects and the 404 monitor?';
    $permissions['vonseo_settings'] = 'Can change VonSEO Site Details and rotate the IndexNow key?';
    return $permissions;
}

/**
 * @param object $dh
 * @return void
 */
function vonseo_indexnow_new_thread(&$dh)
{
    if(!vonseo_indexnow_auto_enabled())
    {
        return;
    }

    if(!isset($dh->tid) || (int)$dh->tid <= 0)
    {
        return;
    }

    vonseo_require_core();
    $indexnow = new VonSEO_IndexNow();
    $indexnow->queueThread((int)$dh->tid, 'new_thread');
}

/**
 * @param object $dh
 * @return void
 */
function vonseo_indexnow_new_post(&$dh)
{
    if(!vonseo_indexnow_auto_enabled())
    {
        return;
    }

    $visible = isset($dh->return_values['visible']) ? (int)$dh->return_values['visible'] : null;
    $pid = isset($dh->return_values['pid']) ? (int)$dh->return_values['pid'] : (isset($dh->pid) ? (int)$dh->pid : 0);
    if($visible !== 1 || $pid <= 0)
    {
        return;
    }

    vonseo_require_core();
    $indexnow = new VonSEO_IndexNow();
    $indexnow->queuePost($pid, 'reply');
}

/**
 * @param object $dh
 * @return void
 */
function vonseo_indexnow_update_post(&$dh)
{
    if(!vonseo_indexnow_auto_enabled())
    {
        return;
    }

    $visible = isset($dh->return_values['visible']) ? (int)$dh->return_values['visible'] : null;
    $pid = isset($dh->pid) ? (int)$dh->pid : (isset($dh->data['pid']) ? (int)$dh->data['pid'] : 0);
    if($visible !== 1 || $pid <= 0)
    {
        return;
    }

    vonseo_require_core();
    $indexnow = new VonSEO_IndexNow();
    $indexnow->queuePost($pid, 'edit');
}

/** @return bool */
function vonseo_indexnow_auto_enabled()
{
    global $mybb;
    return !empty($mybb->settings['vonseo_enabled'])
        && !empty($mybb->settings['vonseo_indexnow_enabled'])
        && !empty($mybb->settings['vonseo_indexnow_auto_ping']);
}

/** @param mixed $ids @return array */
function vonseo_indexnow_ids($ids)
{
    if(!is_array($ids))
    {
        $ids = array($ids);
    }
    return array_values(array_unique(array_filter(array_map('intval', $ids))));
}

/**
 * Capture the verified old URL before a permanent thread delete.
 *
 * @param int|string $tid
 * @return void
 */
function vonseo_indexnow_delete_thread_start($tid)
{
    if(!vonseo_indexnow_auto_enabled())
    {
        return;
    }
    vonseo_require_core();
    $url = (new VonSEO_IndexNow())->capturePublicThreadUrl((int)$tid);
    if($url !== false)
    {
        $GLOBALS['vonseo_indexnow_deleted_threads'][(int)$tid] = $url;
    }
}

/**
 * Queue the captured URL after the thread no longer exists.
 *
 * @param int|string $tid
 * @return void
 */
function vonseo_indexnow_delete_thread($tid)
{
    $tid = (int)$tid;
    if(!vonseo_indexnow_auto_enabled())
    {
        unset($GLOBALS['vonseo_indexnow_deleted_threads'][$tid]);
        return;
    }
    $url = isset($GLOBALS['vonseo_indexnow_deleted_threads'][$tid])
        ? $GLOBALS['vonseo_indexnow_deleted_threads'][$tid] : '';
    unset($GLOBALS['vonseo_indexnow_deleted_threads'][$tid]);
    if($url !== '')
    {
        vonseo_require_core();
        (new VonSEO_IndexNow())->queueRemoval($tid, $url);
    }
}

/**
 * Queue newly approved threads immediately.
 *
 * @param array|int|string $tids
 * @return void
 */
function vonseo_indexnow_approve_threads($tids)
{
    if(!vonseo_indexnow_auto_enabled())
    {
        return;
    }
    vonseo_require_core();
    $indexnow = new VonSEO_IndexNow();
    foreach(vonseo_indexnow_ids($tids) as $tid)
    {
        $indexnow->queueThread($tid, 'approved_thread');
    }
}

/**
 * Notify old public URLs after unapprove or soft delete.
 *
 * @param array|int|string $tids
 * @return void
 */
function vonseo_indexnow_unapprove_threads($tids)
{
    if(!vonseo_indexnow_auto_enabled())
    {
        return;
    }
    vonseo_require_core();
    $indexnow = new VonSEO_IndexNow();
    foreach(vonseo_indexnow_ids($tids) as $tid)
    {
        $indexnow->queueRemoval($tid);
    }
}

/**
 * Queue restored public threads immediately.
 *
 * @param array|int|string $tids
 * @return void
 */
function vonseo_indexnow_restore_threads($tids)
{
    if(!vonseo_indexnow_auto_enabled())
    {
        return;
    }
    vonseo_require_core();
    $indexnow = new VonSEO_IndexNow();
    foreach(vonseo_indexnow_ids($tids) as $tid)
    {
        $indexnow->queueThread($tid, 'restored_thread');
    }
}

/**
 * Capture the parent only when the post was public before permanent delete.
 *
 * @param int|string $pid
 * @return int
 */
function vonseo_indexnow_delete_post_start($pid)
{
    global $db;
    $pid = (int)$pid;
    if(!vonseo_indexnow_auto_enabled() || $pid <= 0)
    {
        return $pid;
    }
    $query = $db->simple_select('posts', 'pid,tid,visible', "pid='{$pid}'", array('limit' => 1));
    $post = $db->fetch_array($query);
    if($post && (int)$post['visible'] === 1 && !empty($post['tid']))
    {
        $GLOBALS['vonseo_indexnow_deleted_posts'][$pid] = (int)$post['tid'];
    }
    return $pid;
}

/**
 * Queue a public parent after a previously public post is permanently deleted.
 *
 * @param int|string $pid
 * @return void
 */
function vonseo_indexnow_delete_post($pid)
{
    $pid = (int)$pid;
    $tid = isset($GLOBALS['vonseo_indexnow_deleted_posts'][$pid])
        ? (int)$GLOBALS['vonseo_indexnow_deleted_posts'][$pid] : 0;
    unset($GLOBALS['vonseo_indexnow_deleted_posts'][$pid]);
    if(!vonseo_indexnow_auto_enabled() || $tid <= 0)
    {
        return;
    }
    vonseo_require_core();
    (new VonSEO_IndexNow())->queueThread($tid, 'moderation');
}

/**
 * Queue approved or restored posts only after they are actually public.
 *
 * @param array|int|string $pids
 * @return void
 */
function vonseo_indexnow_approve_posts($pids)
{
    if(!vonseo_indexnow_auto_enabled())
    {
        return;
    }
    vonseo_require_core();
    $indexnow = new VonSEO_IndexNow();
    foreach(vonseo_indexnow_ids($pids) as $pid)
    {
        $indexnow->queuePost($pid, 'moderation');
    }
}

/**
 * Queue the public parent when approved content is hidden or soft-deleted.
 *
 * @param array|int|string $pids
 * @return void
 */
function vonseo_indexnow_unapprove_posts($pids)
{
    if(!vonseo_indexnow_auto_enabled())
    {
        return;
    }
    vonseo_require_core();
    $indexnow = new VonSEO_IndexNow();
    foreach(vonseo_indexnow_ids($pids) as $pid)
    {
        $indexnow->queuePostParent($pid, 'moderation');
    }
}
