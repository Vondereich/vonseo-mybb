<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

class VonSEO_Url
{
    /** Keyword slug length in user-perceived characters. */
    const SLUG_MAX_CHARACTERS = 100;
    /** Upper bound for the percent-encoded slug. */
    const SLUG_MAX_ENCODED = 1000;

    /** @var string */
    protected $baseUrl = '';

    /** @var string */
    protected $basePath = '';

    /**
     * @param string|null $baseUrl
     */
    public function __construct($baseUrl = null)
    {
        global $mybb;

        if($baseUrl === null)
        {
            $baseUrl = isset($mybb->settings['bburl']) ? $mybb->settings['bburl'] : '';
        }

        $this->baseUrl = $this->normalizeBase($baseUrl);

        $parts = @parse_url($this->baseUrl);
        $this->basePath = isset($parts['path']) ? rtrim($this->normalizePath($parts['path']), '/') : '';
    }

    /** @return string */
    public function base()
    {
        return $this->baseUrl;
    }

    /** @return string */
    public function home()
    {
        return $this->baseUrl.'/';
    }

    /**
     * @param string $url
     * @return string
     */
    public function absolute($url)
    {
        $url = html_entity_decode(trim((string)$url), ENT_QUOTES, 'UTF-8');

        if($url === '')
        {
            return $this->home();
        }

        if(preg_match('#^https?://#i', $url))
        {
            return $this->normalizeAbsolute($url);
        }

        if(strpos($url, '//') === 0)
        {
            $scheme = 'https';
            $parts = @parse_url($this->baseUrl);
            if(!empty($parts['scheme']))
            {
                $scheme = $parts['scheme'];
            }

            return $this->normalizeAbsolute($scheme.':'.$url);
        }

        $fragment = '';
        if(false !== ($hash = strpos($url, '#')))
        {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        $query = '';
        if(false !== ($q = strpos($url, '?')))
        {
            $query = substr($url, $q);
            $url = substr($url, 0, $q);
        }

        $path = $this->normalizePath('/'.ltrim($url, '/'));

        // If a plugin/theme gives a board-root path on a subfolder install,
        // strip the already-present base path before joining to bburl.
        if($this->basePath !== '' && ($path === $this->basePath || strpos($path, $this->basePath.'/') === 0))
        {
            $path = substr($path, strlen($this->basePath));
            if($path === '')
            {
                $path = '/';
            }
        }

        return $this->normalizeAbsolute($this->baseUrl.'/'.ltrim($path, '/').$query.$fragment);
    }

    /**
     * Resolve public image settings without publishing credentials or turning
     * unsupported schemes into broken board-relative paths. Never fetch URLs.
     * Relative paths retain absolute()'s existing board-root semantics.
     *
     * @param string $url
     * @return string
     */
    public function image($url)
    {
        $url = VonSEO_Utils::htmlAttribute(trim((string)$url, ' '));
        if($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) ||
           preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $url))
        {
            return '';
        }
        $parts = @parse_url($url);
        if(!is_array($parts) || isset($parts['user']) || isset($parts['pass']) ||
           (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), array('http', 'https'), true)))
        {
            return '';
        }
        // Do not disguise slash-prefixed schemes as local image paths.
        if(!isset($parts['host']) && preg_match('~^[a-z][a-z0-9+.-]*:~i', ltrim($url, '/')))
        {
            return '';
        }
        $resolved = $this->absolute($url);
        $parts = @parse_url($resolved);
        if(!is_array($parts) || empty($parts['host']) || empty($parts['scheme']) ||
           !in_array(strtolower($parts['scheme']), array('http', 'https'), true) ||
           isset($parts['user']) || isset($parts['pass']) ||
           filter_var($resolved, FILTER_VALIDATE_URL) === false)
        {
            return '';
        }
        return $resolved;
    }

    /**
     * @param int|string $tid
     * @param int|string $page
     * @param string|null $subject
     * @return string
     */
    public function thread($tid, $page = 0, $subject = null)
    {
        $tid = (int)$tid;
        $page = (int)$page;
        $page = $page > 1 ? $page : 0;

        if($tid > 0 && $this->keywordUrlsEnabled())
        {
            if($subject === null && function_exists('get_thread'))
            {
                $thread = get_thread($tid);
                $subject = is_array($thread) && isset($thread['subject']) ? $thread['subject'] : null;
            }

            if($subject !== null)
            {
                // Flat paths preserve MyBB's board-relative asset and action
                // links on direct visits; virtual directories would break them.
                $path = 't-'.$tid.'-'.$this->keywordSlug($subject, 'thread');
                if($page > 1)
                {
                    $path .= '--p'.$page;
                }
                return $this->normalizeAbsolute($this->baseUrl.'/'.$path);
            }
        }

        return $this->absolute(get_thread_link($tid, $page));
    }

    /**
     * Build a stable navigation URL for one post while retaining the thread
     * subject in keyword mode. This is not a separate canonical page: MyBB
     * uses pid to calculate the containing page and the fragment selects the
     * exact post after the page loads.
     *
     * @param int|string $tid
     * @param int|string $pid
     * @param string|null $subject
     * @return string
     */
    public function threadPost($tid, $pid, $subject = null)
    {
        $tid = (int)$tid;
        $pid = (int)$pid;

        if($tid > 0 && $pid > 0 && $this->keywordUrlsEnabled())
        {
            if($subject === null && function_exists('get_thread'))
            {
                $thread = get_thread($tid);
                $subject = is_array($thread) && isset($thread['subject']) ? $thread['subject'] : null;
            }

            if($subject !== null)
            {
                return $this->normalizeAbsolute($this->baseUrl.'/t-'.$tid.'-'.
                    $this->keywordSlug($subject, 'thread').'--post-'.$pid.'#pid'.$pid);
            }
        }

        if(function_exists('get_post_link'))
        {
            return $this->absolute(get_post_link($pid, $tid).'#pid'.$pid);
        }

        return $this->absolute('showthread.php?tid='.$tid.'&pid='.$pid.'#pid'.$pid);
    }

    /**
     * Preserve MyBB's dynamic thread navigation semantics behind the same
     * flat keyword family. These URLs are navigation helpers, not canonicals.
     *
     * @param int|string $tid
     * @param string $action
     * @param string|null $subject
     * @return string
     */
    public function threadAction($tid, $action, $subject = null)
    {
        $tid = (int)$tid;
        $action = strtolower((string)$action);
        $allowed = array('lastpost', 'newpost', 'nextnewest', 'nextoldest');

        if($tid <= 0 || !in_array($action, $allowed, true))
        {
            return '';
        }

        if($this->keywordUrlsEnabled())
        {
            if($subject === null && function_exists('get_thread'))
            {
                $thread = get_thread($tid);
                $subject = is_array($thread) && isset($thread['subject']) ? $thread['subject'] : null;
            }

            if($subject !== null)
            {
                return $this->normalizeAbsolute($this->baseUrl.'/t-'.$tid.'-'.
                    $this->keywordSlug($subject, 'thread').'--'.$action);
            }
        }

        return $this->absolute(get_thread_link($tid, 0, $action));
    }

    /**
     * @param int|string $fid
     * @param int|string $page
     * @param string|null $name
     * @return string
     */
    public function forum($fid, $page = 0, $name = null)
    {
        $fid = (int)$fid;
        $page = (int)$page;
        $page = $page > 1 ? $page : 0;

        if($fid > 0 && $this->keywordUrlsEnabled())
        {
            if($name === null && function_exists('get_forum'))
            {
                $forum = get_forum($fid);
                $name = is_array($forum) && isset($forum['name']) ? $forum['name'] : null;
            }

            if($name !== null)
            {
                $path = 'f-'.$fid.'-'.$this->keywordSlug($name, 'forum');
                if($page > 1)
                {
                    $path .= '--p'.$page;
                }
                return $this->normalizeAbsolute($this->baseUrl.'/'.$path);
            }
        }

        return $this->absolute(get_forum_link($fid, $page));
    }

    /**
     * @param int|string $aid
     * @return string
     */
    public function announcement($aid)
    {
        $aid = (int)$aid;
        $link = function_exists('get_announcement_link')
            ? get_announcement_link($aid)
            : 'announcements.php?aid='.$aid;

        return $this->absolute($link);
    }

    /**
     * @param int|string $eid
     * @return string
     */
    public function event($eid)
    {
        $eid = (int)$eid;
        $link = function_exists('get_event_link')
            ? get_event_link($eid)
            : 'calendar.php?action=event&eid='.$eid;

        return $this->absolute($link);
    }

    /**
     * @param int|string $cid
     * @param int|string $year
     * @param int|string $month
     * @param int|string $day
     * @return string
     */
    public function calendar($cid, $year = 0, $month = 0, $day = 0)
    {
        $cid = (int)$cid;
        $year = (int)$year;
        $month = (int)$month;
        $day = (int)$day;
        $link = function_exists('get_calendar_link')
            ? get_calendar_link($cid, $year, $month, $day)
            : 'calendar.php?calendar='.$cid
                .($year > 0 ? '&year='.$year : '')
                .($month > 0 ? '&month='.$month : '')
                .($day > 0 ? '&day='.$day : '');

        return $this->absolute($link);
    }

    /** @return bool */
    public function keywordUrlsEnabled()
    {
        return (bool)VonSEO_Utils::setting('vonseo_keyword_urls', 0);
    }

    /**
     * @param mixed $title
     * @param string $fallback
     * @return string
     */
    public function keywordSlug($title, $fallback = 'item')
    {
        // Slugs deliberately keep plainText(): it drops "[TAG]" and URLs, and
        // changing it would move every published keyword URL. Titles use labelText().
        $title = VonSEO_Utils::plainText($title);
        if(class_exists('Normalizer', false))
        {
            // Accents typed as separate combining marks get the same slug as precomposed ones.
            $normalized = Normalizer::normalize($title, Normalizer::FORM_C);
            if(is_string($normalized))
            {
                $title = $normalized;
            }
        }
        // Lowercasing "İ" (Turkish dotted capital I) yields "i" plus a combining
        // dot, which used to split the word ("i-stanbul").
        $title = str_replace("\xC4\xB0", 'I', $title);
        if(VonSEO_Utils::setting('vonseo_keyword_ascii_slugs', 0))
        {
            $title = self::asciiLatin($title);
        }
        $title = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
        // Emoji presentation selectors and the keycap mark are marks too, but
        // invisible in a URL ("I ❤️ PHP", "1️⃣"); marks that do not follow a
        // letter (after a space or emoji) have nothing to attach to.
        $cleaned = preg_replace(array('/[\x{FE00}-\x{FE0F}\x{20E3}\x{E0100}-\x{E01EF}]/u', '/(?<![\p{L}\p{M}])\p{M}+/u'), '', $title);
        if(is_string($cleaned))
        {
            $title = $cleaned;
        }
        // Combining marks stay with their letter (\p{M}), e.g. Hindi/Thai vowel signs.
        $slug = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', $title);
        if($slug === null)
        {
            $slug = preg_replace('/[^a-z0-9]+/i', '-', $title);
        }
        $slug = trim((string)$slug, '-');
        if($slug === '')
        {
            $slug = $fallback;
        }

        // Count user-perceived characters (\X), so a letter is never cut from its marks.
        $characters = preg_match_all('/\X/u', $slug, $matches) ? $matches[0] : array($slug);
        if(count($characters) > self::SLUG_MAX_CHARACTERS)
        {
            $characters = array_slice($characters, 0, self::SLUG_MAX_CHARACTERS);
        }
        // Keep the encoded slug well inside the 1536-character URL columns even
        // for scripts that need 4 bytes (12 encoded characters) per letter.
        while(count($characters) > 1 && strlen(rawurlencode(implode('', $characters))) > self::SLUG_MAX_ENCODED)
        {
            array_pop($characters);
        }
        // A single cluster can still be huge (a letter with dozens of marks): keep its base only.
        if(count($characters) === 1 && strlen(rawurlencode($characters[0])) > self::SLUG_MAX_ENCODED &&
           preg_match('/^./su', $characters[0], $base))
        {
            $characters = array($base[0]);
        }
        $slug = trim(implode('', $characters), '-');
        if($slug === '')
        {
            $slug = $fallback;
        }

        return rawurlencode($slug);
    }

    /**
     * Replace Latin letters with diacritics and Latin ligatures by ASCII
     * ("Kâğıt Çiçek" -> "Kagit Cicek", "Straße" -> "Strasse"). Other scripts
     * (Arabic, Cyrillic, Greek, CJK, Thai, ...) are left unchanged. Uses a
     * static table, so the result never depends on intl or the server locale.
     *
     * @param string $text
     * @return string
     */
    public static function asciiLatin($text)
    {
        static $table = null;
        if($table === null)
        {
            $table = require __DIR__.'/Transliterate.php';
            if(!is_array($table))
            {
                $table = array();
            }
        }
        $text = strtr((string)$text, $table);
        // Without intl, decomposed input ("a" + U+0302) is not composed first,
        // and a mapped letter may still carry extra marks: drop combining marks
        // that follow an ASCII letter. Marks on other scripts are kept.
        $stripped = preg_replace('/([A-Za-z])\p{Mn}+/u', '$1', $text);
        return is_string($stripped) ? $stripped : $text;
    }

    /**
     * @param int|string $uid
     * @return string
     */
    public function profile($uid)
    {
        return $this->absolute(get_profile_link((int)$uid));
    }

    /**
     * Author links are optional and must use the guest baseline, not the
     * current administrator/member's permissions. Missing cache fails closed.
     *
     * @param int|string $uid
     * @return string
     */
    public function publicProfile($uid)
    {
        if((int)$uid <= 0 || !VonSEO_Utils::setting('vonseo_profile_index', 0) || !VonSEO_Utils::guestCanViewProfiles())
        {
            return '';
        }
        return $this->profile($uid);
    }

    /**
     * @param string $action
     * @param array $params
     * @return string
     */
    public function misc($action, array $params = array())
    {
        $params = array_merge(array('action' => $action), $params);
        return $this->absolute('misc.php?'.http_build_query($params, '', '&'));
    }

    /**
     * @param string $url
     * @return string
     */
    protected function normalizeBase($url)
    {
        $url = trim((string)$url);
        $url = preg_replace('#/+$#', '', $url);

        return $this->normalizeAbsolute($url);
    }

    /**
     * @param string $url
     * @return string
     */
    protected function normalizeAbsolute($url)
    {
        $parts = @parse_url($url);
        if(!$parts || empty($parts['scheme']) || empty($parts['host']))
        {
            return preg_replace('#(?<!:)/{2,}#', '/', $url);
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $user = isset($parts['user']) ? $parts['user'] : '';
        $pass = isset($parts['pass']) ? ':'.$parts['pass'] : '';
        $auth = $user !== '' ? $user.$pass.'@' : '';
        $path = isset($parts['path']) ? $this->normalizePath($parts['path']) : '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#'.$parts['fragment'] : '';

        if($path !== '/')
        {
            $path = rtrim($path, '/');
        }

        return $scheme.'://'.$auth.$host.$port.$path.$query.$fragment;
    }

    /**
     * @param string $path
     * @return string
     */
    protected function normalizePath($path)
    {
        $path = preg_replace('#/{2,}#', '/', (string)$path);
        if($path === '')
        {
            return '';
        }

        return '/'.ltrim($path, '/');
    }
}
