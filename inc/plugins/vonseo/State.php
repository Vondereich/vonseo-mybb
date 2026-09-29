<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

/**
 * Operational counters use independent cache keys so concurrent requests do
 * not overwrite unrelated state. Schema state is durable database metadata.
 */
class VonSEO_State
{
    const CACHE_KEY = 'vonseo_state';
    const CACHE_PREFIX = 'vonseo_state_';
    const SCHEMA_VERSION = 5;

    /** @var array|null */
    protected static $runtime = null;

    /** @var int|null */
    protected static $schemaRuntime = null;

    /** @var array */
    protected static $fields = array(
        'active_redirects', 'not_found_rows', 'indexnow_queue_depth',
        'indexnow_last_attempt', 'indexnow_last_count', 'indexnow_last_success',
        'indexnow_last_failure', 'indexnow_last_error', 'indexnow_last_dropped',
        'indexnow_status', 'indexnow_last_http'
    );

    public static function get()
    {
        global $cache;
        if(self::$runtime !== null)
        {
            return self::$runtime;
        }

        $state = array();
        $legacy = array();
        if(is_object($cache) && method_exists($cache, 'read'))
        {
            $cachedLegacy = $cache->read(self::CACHE_KEY);
            if(is_array($cachedLegacy))
            {
                $legacy = $cachedLegacy;
            }
            foreach(self::$fields as $field)
            {
                $cached = $cache->read(self::CACHE_PREFIX.$field);
                if(is_array($cached) && array_key_exists('value', $cached))
                {
                    $state[$field] = $cached['value'];
                }
                elseif(array_key_exists($field, $legacy))
                {
                    $state[$field] = $legacy[$field];
                }
            }
        }
        self::$runtime = $state;
        return $state;
    }

    public static function merge(array $changes)
    {
        global $cache;
        $state = self::get();
        foreach($changes as $field => $value)
        {
            if(!in_array($field, self::$fields, true))
            {
                continue;
            }
            $state[$field] = $value;
            if(is_object($cache) && method_exists($cache, 'update'))
            {
                $cache->update(self::CACHE_PREFIX.$field, array('value' => $value));
            }
        }
        self::$runtime = $state;
        return $state;
    }

    public static function ensureSchemaStore()
    {
        global $db, $cache;
        if(!is_object($db) || !method_exists($db, 'table_exists'))
        {
            return false;
        }
        if(!$db->table_exists('vonseo_meta'))
        {
            $legacyVersion = 0;
            if(is_object($cache) && method_exists($cache, 'read'))
            {
                $legacy = $cache->read(self::CACHE_KEY);
                if(is_array($legacy) && isset($legacy['schema_version']))
                {
                    $legacyVersion = max(0, (int)$legacy['schema_version']);
                }
            }
            $collation = method_exists($db, 'build_create_table_collation') ? $db->build_create_table_collation() : '';
            $db->write_query("CREATE TABLE ".TABLE_PREFIX."vonseo_meta (
                meta_key VARCHAR(64) NOT NULL,
                meta_value VARCHAR(255) NOT NULL DEFAULT '',
                updated_at INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (meta_key)
            ) {$collation};");
            if($legacyVersion > 0)
            {
                self::writeMeta('schema_version', (string)$legacyVersion);
            }
        }
        return $db->table_exists('vonseo_meta');
    }

    /**
     * @param int|string $version
     * @return void
     */
    public static function setSchemaVersion($version)
    {
        $version = max(0, (int)$version);
        if(self::ensureSchemaStore())
        {
            self::writeMeta('schema_version', (string)$version);
            self::$schemaRuntime = $version;
        }
    }

    public static function schemaVersion()
    {
        global $db, $cache;
        if(self::$schemaRuntime !== null)
        {
            return self::$schemaRuntime;
        }
        if(is_object($db) && method_exists($db, 'table_exists') && $db->table_exists('vonseo_meta'))
        {
            $query = $db->simple_select('vonseo_meta', 'meta_value', "meta_key='schema_version'", array('limit' => 1));
            self::$schemaRuntime = max(0, (int)$db->fetch_field($query, 'meta_value'));
            return self::$schemaRuntime;
        }
        if(is_object($cache) && method_exists($cache, 'read'))
        {
            $legacy = $cache->read(self::CACHE_KEY);
            if(is_array($legacy) && isset($legacy['schema_version']))
            {
                return max(0, (int)$legacy['schema_version']);
            }
        }
        return 0;
    }

    public static function activeRedirects()
    {
        global $cache;
        if(is_object($cache) && method_exists($cache, 'read'))
        {
            $cached = $cache->read(self::CACHE_PREFIX.'active_redirects');
            if(is_array($cached) && array_key_exists('value', $cached))
            {
                return (int)$cached['value'];
            }
        }
        $state = self::get();
        return array_key_exists('active_redirects', $state) ? (int)$state['active_redirects'] : null;
    }

    public static function refreshRedirectCount()
    {
        global $db;
        $count = 0;
        if(is_object($db) && $db->table_exists('vonseo_redirects'))
        {
            $query = $db->simple_select('vonseo_redirects', 'COUNT(rid) AS total', "enabled='1'");
            $count = (int)$db->fetch_field($query, 'total');
        }
        self::merge(array('active_redirects' => $count));
        return $count;
    }

    public static function refresh404Count()
    {
        global $db;
        $count = 0;
        if(is_object($db) && $db->table_exists('vonseo_404_log'))
        {
            $query = $db->simple_select('vonseo_404_log', 'COUNT(path_hash) AS total');
            $count = (int)$db->fetch_field($query, 'total');
        }
        self::merge(array('not_found_rows' => $count));
        return $count;
    }

    public static function refreshQueueDepth()
    {
        global $db;
        $count = 0;
        if(is_object($db) && $db->table_exists('vonseo_indexnow_queue'))
        {
            $query = $db->simple_select('vonseo_indexnow_queue', 'COUNT(qid) AS total');
            $count = (int)$db->fetch_field($query, 'total');
        }
        self::merge(array('indexnow_queue_depth' => $count));
        return $count;
    }

    /**
     * @param bool $success
     * @param int|string $urlCount
     * @param string $error
     * @param int|string $dropped
     * @param string $status
     * @param int|string $httpStatus
     * @return void
     */
    public static function recordIndexNowResult($success, $urlCount, $error = '', $dropped = 0, $status = 'ready', $httpStatus = 0)
    {
        $changes = array(
            'indexnow_last_attempt' => self::now(),
            'indexnow_last_count' => max(0, (int)$urlCount),
            'indexnow_last_dropped' => max(0, (int)$dropped),
            'indexnow_status' => in_array($status, array('ready', 'config_error', 'rate_limited', 'remote_error'), true)
                ? $status : 'remote_error',
            'indexnow_last_http' => max(0, (int)$httpStatus)
        );
        if($success)
        {
            $changes['indexnow_last_success'] = self::now();
            $changes['indexnow_last_error'] = '';
        }
        else
        {
            $changes['indexnow_last_failure'] = self::now();
            $changes['indexnow_last_error'] = substr(trim((string)$error), 0, 255);
        }
        self::merge($changes);
    }

    public static function forget()
    {
        global $cache;
        self::$runtime = array();
        self::$schemaRuntime = null;
        if(is_object($cache) && method_exists($cache, 'delete'))
        {
            $cache->delete(self::CACHE_KEY);
            foreach(self::$fields as $field)
            {
                $cache->delete(self::CACHE_PREFIX.$field);
            }
        }
    }

    public static function resetRuntime()
    {
        self::$runtime = null;
        self::$schemaRuntime = null;
    }

    /**
     * @param string $key
     * @param string $value
     * @return void
     */
    protected static function writeMeta($key, $value)
    {
        global $db;
        $db->replace_query('vonseo_meta', array(
            'meta_key' => $db->escape_string($key),
            'meta_value' => $db->escape_string($value),
            'updated_at' => self::now()
        ));
    }

    protected static function now()
    {
        return defined('TIME_NOW') ? (int)TIME_NOW : time();
    }
}
