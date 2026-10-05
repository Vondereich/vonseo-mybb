<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

if(!defined('VONSEO_VERSION'))
{
    define('VONSEO_VERSION', '1.0.2');
}

class VonSEO_Utils
{
    /**
     * @param int|string $bytes
     * @return string
     */
    public static function randomHex($bytes = 16)
    {
        $bytes = max(16, (int)$bytes);
        if(function_exists('random_bytes'))
        {
            try
            {
                return bin2hex(random_bytes($bytes));
            }
            catch(Exception $e)
            {
                // Fall through for older or constrained PHP installations.
            }
        }
        if(function_exists('openssl_random_pseudo_bytes'))
        {
            $strong = false;
            $value = openssl_random_pseudo_bytes($bytes, $strong);
            if($value !== false && $strong)
            {
                return bin2hex($value);
            }
        }
        throw new RuntimeException('A secure random source is required to generate the IndexNow key.');
    }

    /**
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public static function setting($name, $default = '')
    {
        global $mybb;

        return isset($mybb->settings[$name]) ? $mybb->settings[$name] : $default;
    }

    /**
     * A child with no password can still be behind a passworded parent.
     * MyBB checks the entire parentlist on access; crawler output must do so
     * as a guest, without relying on the current user's password cookies.
     *
     * @param array|false $forum
     * @param array|false|null $forums
     * @return bool
     */
    public static function forumHasPasswordBarrier($forum, $forums = null)
    {
        global $cache;

        if(!is_array($forum) || empty($forum['fid']) || !isset($forum['parentlist']) ||
           !array_key_exists('password', $forum))
        {
            return true;
        }

        if(!empty($forum['password']))
        {
            return true;
        }

        $fid = (int)$forum['fid'];
        $parents = array();
        foreach(explode(',', (string)$forum['parentlist']) as $parentValue)
        {
            $parentValue = trim($parentValue);
            if($parentValue === '' || !ctype_digit($parentValue) || (int)$parentValue <= 0)
            {
                return true;
            }
            $parents[] = (int)$parentValue;
        }
        if(!in_array($fid, $parents, true))
        {
            return true;
        }

        foreach($parents as $parentId)
        {
            if($parentId === $fid)
            {
                continue;
            }

            if(!is_array($forums))
            {
                $forums = is_object($cache) ? $cache->read('forums') : false;
                if(!is_array($forums) && function_exists('cache_forums'))
                {
                    $forums = cache_forums();
                }
            }

            if(!is_array($forums) || !isset($forums[$parentId]) || !is_array($forums[$parentId]) ||
               !array_key_exists('password', $forums[$parentId]) ||
               !empty($forums[$parentId]['password']))
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve calendar visibility for MyBB's Guest group, independent of the
     * current visitor. A calendar-specific permission overrides the base
     * usergroup value exactly as MyBB's calendar permission resolver does.
     *
     * @param int|string $cid
     * @return bool
     */
    public static function guestCanViewCalendar($cid)
    {
        global $cache, $db;

        $cid = (int)$cid;
        if($cid <= 0 || !is_object($db))
        {
            return false;
        }

        $guestCanView = null;
        $groups = is_object($cache) && method_exists($cache, 'read') ? $cache->read('usergroups') : false;
        if(is_array($groups) && isset($groups[1]) && is_array($groups[1]) &&
           array_key_exists('canviewcalendar', $groups[1]))
        {
            $guestCanView = (int)$groups[1]['canviewcalendar'] === 1;
        }

        if($guestCanView === null)
        {
            $groupQuery = $db->simple_select('usergroups', 'canviewcalendar', "gid='1'", array('limit' => 1));
            $group = $db->fetch_array($groupQuery);
            if(is_array($group) && array_key_exists('canviewcalendar', $group))
            {
                $guestCanView = (int)$group['canviewcalendar'] === 1;
            }
        }

        $permissionQuery = $db->simple_select(
            'calendarpermissions',
            'canviewcalendar',
            "cid='{$cid}' AND gid='1'",
            array('limit' => 1)
        );
        $permission = $db->fetch_array($permissionQuery);
        if(is_array($permission) && array_key_exists('canviewcalendar', $permission))
        {
            return (int)$permission['canviewcalendar'] === 1;
        }

        return $guestCanView === true;
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function plainText($value)
    {
        $value = (string)$value;

        // Remove common MyCode blocks that produce poor search snippets.
        $value = preg_replace('#\[quote(?:=[^\]]+)?\].*?\[/quote\]#is', ' ', $value);
        $value = preg_replace('#\[code\].*?\[/code\]#is', ' ', $value);
        $value = preg_replace('#\[php\].*?\[/php\]#is', ' ', $value);
        $value = preg_replace('#\[img(?:=[^\]]+)?\].*?\[/img\]#is', ' ', $value);
        $value = preg_replace('#\[url(?:=[^\]]+)?\](.*?)\[/url\]#is', '$1', $value);
        $value = preg_replace('#\[[a-z0-9_\-*]+(?:=[^\]]+)?\]#i', ' ', $value);
        $value = preg_replace('#\[/[a-z0-9_\-*]+\]#i', ' ', $value);

        $value = html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8');
        $value = preg_replace('~https?://\S+~iu', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    /**
     * Preserve all guest-rendered post text for forum structured data. Unlike
     * plainText(), this must not truncate the post or discard quotes/code.
     *
     * @param mixed $html
     * @return string
     */
    public static function renderedPostText($html)
    {
        $html = (string)$html;
        $html = preg_replace('#<!--.*?-->#s', ' ', $html);
        $html = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $html = preg_replace('#<(?:br|hr)\b[^>]*/?>#i', ' ', $html);
        $html = preg_replace('#</(?:p|div|li|blockquote|pre|h[1-6]|tr)>#i', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return is_string($text) ? trim($text) : '';
    }

    /**
     * @param mixed $value
     * @param int $limit
     * @return string
     */
    public static function truncateDescription($value, $limit = 160)
    {
        $value = self::plainText($value);

        if($value === '')
        {
            return '';
        }

        if(self::strlen($value) <= $limit)
        {
            return $value;
        }

        $candidate = self::substr($value, 0, $limit + 1);

        // Prefer a sentence boundary reasonably close to the limit.
        $sentenceCut = 0;
        foreach(array('. ', '! ', '? ') as $needle)
        {
            $pos = self::strrpos($candidate, $needle);
            if($pos !== false && $pos >= (int)($limit * 0.55))
            {
                $sentenceCut = max($sentenceCut, $pos + 1);
            }
        }

        if($sentenceCut > 0)
        {
            return rtrim(self::substr($candidate, 0, $sentenceCut));
        }

        $space = self::strrpos($candidate, ' ');
        if($space !== false && $space > (int)($limit * 0.60))
        {
            $candidate = self::substr($candidate, 0, $space);
        }
        else
        {
            $candidate = self::substr($candidate, 0, $limit);
        }

        return rtrim($candidate, " \t\n\r\0\x0B.,;:-").'…';
    }

    /**
     * @param string $value
     * @return int
     */
    public static function strlen($value)
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /**
     * @param string $value
     * @param int $start
     * @param int|null $length
     * @return string
     */
    public static function substr($value, $start, $length = null)
    {
        if(function_exists('mb_substr'))
        {
            return $length === null
                ? mb_substr($value, $start, null, 'UTF-8')
                : mb_substr($value, $start, $length, 'UTF-8');
        }

        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }

    /**
     * @param string $haystack
     * @param string $needle
     * @return int|false
     */
    public static function strrpos($haystack, $needle)
    {
        return function_exists('mb_strrpos')
            ? mb_strrpos($haystack, $needle, 0, 'UTF-8')
            : strrpos($haystack, $needle);
    }

    /** @return string */
    public static function currentScript()
    {
        if(defined('THIS_SCRIPT'))
        {
            return strtolower(THIS_SCRIPT);
        }

        if(!empty($_SERVER['SCRIPT_NAME']))
        {
            return strtolower(basename($_SERVER['SCRIPT_NAME']));
        }

        return '';
    }

    /**
     * @param string $name
     * @param int $default
     * @return int
     */
    public static function intInput($name, $default = 0)
    {
        global $mybb;

        if(method_exists($mybb, 'get_input'))
        {
            return (int)$mybb->get_input($name, MyBB::INPUT_INT);
        }

        return isset($mybb->input[$name]) ? (int)$mybb->input[$name] : (int)$default;
    }

    /**
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public static function input($name, $default = '')
    {
        global $mybb;

        if(method_exists($mybb, 'get_input'))
        {
            $value = $mybb->get_input($name);
            return $value !== '' ? $value : $default;
        }

        return isset($mybb->input[$name]) ? $mybb->input[$name] : $default;
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function xml($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * @param int|string $timestamp
     * @return string
     */
    public static function isoDate($timestamp)
    {
        $timestamp = (int)$timestamp;
        if($timestamp <= 0)
        {
            return '';
        }

        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
