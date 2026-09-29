<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

class VonSEO_Errors
{
    /**
     * @param mixed $message
     * @return mixed
     */
    public static function markError($message = '')
    {
        $GLOBALS['vonseo_error_seen'] = true;
        $GLOBALS['vonseo_error_message'] = is_string($message) ? $message : '';
        if(self::isMissingEntityMessage($message))
        {
            $GLOBALS['vonseo_missing_error_seen'] = true;
        }
        return $message;
    }

    public static function markNoPermission()
    {
        $GLOBALS['vonseo_no_permission'] = true;
    }

    public static function prepareResponse()
    {
        $status = 0;

        if(!empty($GLOBALS['vonseo_forced_status']))
        {
            $status = (int)$GLOBALS['vonseo_forced_status'];
        }
        elseif(!empty($GLOBALS['vonseo_no_permission']))
        {
            $status = 403;
        }
        elseif(VonSEO_Utils::setting('vonseo_404_enabled', 1) && self::isExplicit404Route())
        {
            $status = 404;
        }
        elseif(VonSEO_Utils::setting('vonseo_404_enabled', 1) && !empty($GLOBALS['vonseo_missing_error_seen']) && self::isSafeMissingRequest())
        {
            $status = 404;
        }
        else
        {
            $current = http_response_code();
            if($current >= 400)
            {
                $status = $current;
            }
        }

        if($status >= 400)
        {
            if(!headers_sent())
            {
                http_response_code($status);
                header('X-Robots-Tag: noindex, follow', true);
            }

            if($status === 404 && VonSEO_Utils::setting('vonseo_404_monitor', 1))
            {
                self::log404();
            }
        }

        return $status;
    }

    public static function isErrorResponse()
    {
        if(!empty($GLOBALS['vonseo_forced_status']) || !empty($GLOBALS['vonseo_no_permission']) || !empty($GLOBALS['vonseo_error_seen']))
        {
            return true;
        }

        $code = http_response_code();
        return $code >= 400;
    }

    public static function log404()
    {
        global $db;

        if((!class_exists('VonSEO_State') || VonSEO_State::schemaVersion() < 1) && !$db->table_exists('vonseo_404_log'))
        {
            return;
        }

        $path = self::requestPath();
        // The path column is VARCHAR(768); do not truncate a distinct URL into another key.
        if($path === '' || strlen($path) > 768 || !self::shouldLogPath($path))
        {
            return;
        }

        $hash = hash('sha256', $path);
        $escapedHash = $db->escape_string($hash);
        $escapedPath = $db->escape_string($path);
        $now = defined('TIME_NOW') ? (int)TIME_NOW : time();

        // Existing rows do not need the admission lock. hits always changes, so
        // affected_rows() reliably distinguishes an update from a missing hash.
        if(self::updateExisting404($escapedHash, $escapedPath, $now))
        {
            return;
        }

        $lockName = self::acquire404AdmissionLock();
        if($lockName === false)
        {
            // Telemetry must never delay or break a public error response.
            return;
        }

        try
        {
            // Another request may have inserted the same hash while this
            // request was waiting to enter the serialized admission path.
            if(self::updateExisting404($escapedHash, $escapedPath, $now))
            {
                return;
            }

            $query = $db->simple_select('vonseo_404_log', 'COUNT(path_hash) AS total');
            $count = (int)$db->fetch_field($query, 'total');
            self::prune404Log($count);

            $db->write_query(
                "INSERT INTO ".TABLE_PREFIX."vonseo_404_log (path_hash,path,hits,first_seen,last_seen) "
                ."VALUES ('{$escapedHash}','{$escapedPath}',1,{$now},{$now})"
            );

            if(class_exists('VonSEO_State'))
            {
                VonSEO_State::refresh404Count();
            }
        }
        finally
        {
            self::release404AdmissionLock($lockName);
        }
    }

    public static function requestPath()
    {
        global $mybb;

        // REQUEST_URI retains the native MyBB query identity. REDIRECT_URL is
        // only a fallback because Apache commonly strips its query string.
        $uri = isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] !== ''
            ? $_SERVER['REQUEST_URI']
            : (!empty($_SERVER['REDIRECT_URL']) ? $_SERVER['REDIRECT_URL'] : '');
        $parts = @parse_url($uri);
        if(!$parts)
        {
            return '';
        }

        $path = isset($parts['path']) ? preg_replace('#/{2,}#', '/', $parts['path']) : '/';
        $base = @parse_url(isset($mybb->settings['bburl']) ? $mybb->settings['bburl'] : '');
        $basePath = !empty($base['path']) ? '/'.trim($base['path'], '/') : '';
        if($basePath !== '' && ($path === $basePath || strpos($path, $basePath.'/') === 0))
        {
            $path = substr($path, strlen($basePath));
            if($path === '')
            {
                $path = '/';
            }
        }

        $cleanQuery = isset($parts['query']) && $parts['query'] !== '' ? self::sanitizeQuery($parts['query']) : '';
        $query = $cleanQuery !== '' ? '?'.$cleanQuery : '';

        return '/'.ltrim($path, '/').$query;
    }

    /**
     * Retain only route-specific public identity. Unknown, authentication,
     * tracking and secret-bearing fields are discarded rather than redacted.
     *
     * @param string $query
     * @return string
     */
    protected static function sanitizeQuery($query)
    {
        $script = VonSEO_Utils::currentScript();
        $allowed = array();
        if($script === 'showthread.php')
        {
            $allowed = array('tid' => 'positive', 'pid' => 'positive', 'page' => 'positive',
                'action' => array('lastpost', 'newpost', 'nextnewest', 'nextoldest'),
                'mode' => array('linear', 'threaded'));
        }
        elseif($script === 'forumdisplay.php')
        {
            $allowed = array('fid' => 'positive', 'page' => 'positive',
                'sortby' => array('subject', 'lastpost', 'starter', 'replies', 'views', 'icon'),
                'order' => array('asc', 'desc'), 'datecut' => 'nonnegative', 'prefix' => 'positive');
        }
        elseif($script === 'announcements.php')
        {
            $allowed = array('aid' => 'positive');
        }
        elseif($script === 'attachment.php')
        {
            $allowed = array('aid' => 'positive', 'thumbnail' => array('0', '1'));
        }
        elseif($script === 'member.php')
        {
            $allowed = array('action' => array('profile'), 'uid' => 'positive');
        }
        elseif($script === 'calendar.php')
        {
            $allowed = array(
                'action' => array('event'),
                'eid' => 'positive',
                'calendar' => 'positive',
                'year' => 'positive',
                'month' => 'positive'
            );
        }

        if(empty($allowed))
        {
            return '';
        }

        $input = array();
        parse_str((string)$query, $input);
        if($script === 'member.php' && (!isset($input['action']) || strtolower((string)$input['action']) !== 'profile'))
        {
            return '';
        }
        if($script === 'calendar.php' && isset($input['action']) &&
           strtolower((string)$input['action']) !== 'event')
        {
            return '';
        }
        $clean = array();
        foreach($allowed as $name => $rule)
        {
            if(!isset($input[$name]) || is_array($input[$name]))
            {
                continue;
            }
            $value = trim((string)$input[$name]);
            $lower = strtolower($value);
            if($rule === 'positive' && (!ctype_digit($value) || (int)$value <= 0))
            {
                continue;
            }
            if($rule === 'nonnegative' && !ctype_digit($value))
            {
                continue;
            }
            if(is_array($rule) && !in_array($lower, $rule, true))
            {
                continue;
            }
            $clean[$name] = is_array($rule) ? $lower : (string)(int)$value;
        }

        return http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Keep the table useful under crawler noise by removing the oldest,
     * lowest-value rows in a bounded batch once the cap is crossed.
     *
     * @param int $count
     * @return void
     */
    protected static function prune404Log($count)
    {
        global $db;

        $count = (int)$count;
        if($count < 10000)
        {
            return;
        }

        // Leave 9,499 rows so the pending insert finishes at no more than 9,500.
        $remove = max(1, $count - 9499);
        $db->write_query(
            "DELETE FROM ".TABLE_PREFIX."vonseo_404_log WHERE path_hash IN ("
            ."SELECT path_hash FROM (SELECT path_hash FROM ".TABLE_PREFIX."vonseo_404_log "
            ."ORDER BY hits ASC,last_seen ASC LIMIT {$remove}) AS vonseo_prune)"
        );
    }

    /**
     * @param string $escapedHash
     * @param string $escapedPath
     * @param int $now
     * @return bool
     */
    protected static function updateExisting404($escapedHash, $escapedPath, $now)
    {
        global $db;

        $db->write_query(
            "UPDATE ".TABLE_PREFIX."vonseo_404_log SET hits=hits+1,last_seen=".(int)$now
            .",path='{$escapedPath}' WHERE path_hash='{$escapedHash}'"
        );
        return method_exists($db, 'affected_rows') && (int)$db->affected_rows() > 0;
    }

    /**
     * Serialize new-row admission so concurrent crawler noise cannot bypass
     * the table cap. GET_LOCK is supported by the MySQL/MariaDB backends this
     * plugin targets and a zero timeout keeps the public request non-blocking.
     *
     * @return string|false
     */
    protected static function acquire404AdmissionLock()
    {
        global $db;

        $lockName = 'vonseo_404_'.substr(hash('sha256', TABLE_PREFIX.'vonseo_404_log'), 0, 32);
        $escapedLock = $db->escape_string($lockName);
        $query = $db->write_query("SELECT GET_LOCK('{$escapedLock}',0) AS acquired");
        if(!$query || (int)$db->fetch_field($query, 'acquired') !== 1)
        {
            return false;
        }
        return $lockName;
    }

    /**
     * @param string $lockName
     * @return void
     */
    protected static function release404AdmissionLock($lockName)
    {
        global $db;

        $escapedLock = $db->escape_string($lockName);
        $db->write_query("SELECT RELEASE_LOCK('{$escapedLock}')");
    }

    protected static function isExplicit404Route()
    {
        global $mybb;
        return VonSEO_Utils::currentScript() === 'misc.php' && in_array(VonSEO_Utils::input('action'), array('vonseo_404', 'vonseo_route'));
    }

    protected static function isSafeMissingRequest()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        if(!in_array($method, array('GET', 'HEAD')))
        {
            return false;
        }

        $script = VonSEO_Utils::currentScript();
        if(in_array($script, array('showthread.php', 'forumdisplay.php', 'announcements.php', 'attachment.php')))
        {
            return true;
        }

        if($script === 'member.php' && VonSEO_Utils::input('action') === 'profile')
        {
            return true;
        }

        if($script === 'calendar.php' && in_array(VonSEO_Utils::input('action'), array('', 'event'), true))
        {
            return true;
        }

        return false;
    }

    /**
     * @param mixed $message
     * @return bool
     */
    protected static function isMissingEntityMessage($message)
    {
        global $lang;

        if(!is_string($message) || trim($message) === '' || !is_object($lang))
        {
            return false;
        }

        $script = VonSEO_Utils::currentScript();
        $keys = array();
        if($script === 'showthread.php')
        {
            $keys = array('error_invalidthread', 'error_invalidpost');
        }
        elseif($script === 'forumdisplay.php')
        {
            $keys = array('error_invalidforum');
        }
        elseif($script === 'announcements.php')
        {
            $keys = array('error_invalidannouncement');
        }
        elseif($script === 'attachment.php')
        {
            $keys = array('error_invalidattachment');
        }
        elseif($script === 'member.php' && VonSEO_Utils::input('action') === 'profile')
        {
            $keys = array('error_invaliduser');
        }
        elseif($script === 'calendar.php')
        {
            $keys = VonSEO_Utils::input('action') === 'event'
                ? array('error_invalidevent', 'invalid_calendar')
                : array('invalid_calendar');
        }

        foreach($keys as $key)
        {
            if(isset($lang->$key) && trim((string)$lang->$key) === trim($message))
            {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $path
     * @return bool
     */
    protected static function shouldLogPath($path)
    {
        $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH), PATHINFO_EXTENSION));
        if(in_array($ext, array('css','js','png','jpg','jpeg','gif','webp','svg','ico','woff','woff2','ttf','map')))
        {
            return false;
        }

        return true;
    }
}
