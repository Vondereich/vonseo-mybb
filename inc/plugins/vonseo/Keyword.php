<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

/**
 * Conservative bridge between MyBB's native links and VonSEO keyword URLs.
 * Only plain, guest-visible forum/thread destinations, exact thread-post
 * targets and the four stock dynamic thread actions are changed. Post-only
 * links, display/search filters and moderation links stay native.
 */
class VonSEO_Keyword
{
    /** @var VonSEO_Url */
    protected $url;

    /** @var array */
    protected $forums = array();

    /** @var array */
    protected $threads = array();

    /** @var array */
    protected $posts = array();

    /** @var int */
    protected $threadLookups = 0;

    /** @var int */
    protected $postLookups = 0;

    public function __construct(?VonSEO_Url $url = null)
    {
        $this->url = $url ?: new VonSEO_Url();
    }

    /**
     * Return a redirect decision without sending headers; safe to test.
     *
     * @param string $requestUri
     * @param string $method
     * @param string $script
     * @param array $entity
     * @param array $forum
     * @return array|false
     */
    public function redirectDecision($requestUri, $method, $script, array $entity, array $forum = array())
    {
        if(!in_array(strtoupper((string)$method), array('GET', 'HEAD'), true))
        {
            return false;
        }

        $request = $this->parseLink($requestUri);
        $kind = $script === 'showthread.php' ? 'thread' : ($script === 'forumdisplay.php' ? 'forum' : '');
        $idKey = $kind === 'thread' ? 'tid' : 'fid';
        if(!$request || $kind === '' || $request['kind'] !== $kind ||
           empty($entity[$idKey]) || (int)$entity[$idKey] !== $request['id'])
        {
            return false;
        }

        if($kind === 'thread')
        {
            if(!isset($entity['visible']) || (int)$entity['visible'] !== 1 ||
               (!empty($entity['closed']) && strpos((string)$entity['closed'], 'moved|') === 0) ||
               !$this->publicForum($forum))
            {
                return false;
            }
            if(!empty($request['post']) && !$this->publicPost($request['post'], $request['id']))
            {
                return false;
            }

            $subject = isset($entity['subject']) ? $entity['subject'] : '';
            if(function_exists('get_thread'))
            {
                $rawThread = get_thread($request['id']);
                if(is_array($rawThread) && isset($rawThread['subject']))
                {
                    $subject = $rawThread['subject'];
                }
            }
            if(!empty($request['post']))
            {
                $canonical = $this->url->threadPost($request['id'], $request['post'], $subject);
            }
            elseif(!empty($request['action']))
            {
                $canonical = $this->url->threadAction($request['id'], $request['action'], $subject);
            }
            else
            {
                $canonical = $this->url->thread($request['id'], $request['page'], $subject);
            }
        }
        else
        {
            if(!$this->publicForum($entity))
            {
                return false;
            }
            $canonical = $this->url->forum($request['id'], $request['page'], isset($entity['name']) ? $entity['name'] : '');
        }

        $keywordEnabled = $this->url->keywordUrlsEnabled();
        if($request['source'] === 'native' && !$keywordEnabled)
        {
            return false;
        }

        $currentPath = @parse_url($requestUri, PHP_URL_PATH);
        $targetPath = @parse_url($canonical, PHP_URL_PATH);
        $currentQuery = @parse_url($requestUri, PHP_URL_QUERY);
        if($request['source'] === 'keyword' && $currentPath === $targetPath &&
           ($currentQuery === null || $currentQuery === false || $currentQuery === ''))
        {
            return false;
        }

        return array(
            'location' => $canonical,
            // A disabled keyword layer is a reversible rollback, not a
            // permanent promise that keyword URLs will never return.
            // Native aliases must stay temporary while the beta can be
            // rolled back. A cached native->keyword 301 followed by the
            // disabled keyword->native redirect would form a client loop.
            'status' => $keywordEnabled && $request['source'] === 'keyword' ? 301 : 302
        );
    }

    /**
     * MyBB has no filter for every get_forum_link()/get_thread_link() call.
     * Adapt only hrefs in public navigation pages, with exact URL parsing.
     *
     * @param string $html
     * @param string $script
     * @return string
     */
    public function rewriteHtml($html, $script)
    {
        if(!$this->url->keywordUrlsEnabled() ||
           !in_array($script, array('index.php', 'forumdisplay.php', 'showthread.php'), true))
        {
            return $html;
        }

        return preg_replace_callback('#(<a\b[^>]*?\shref\s*=\s*)(["\'])(.*?)\2#is', function($match)
        {
            $link = $this->parseLink(html_entity_decode($match[3], ENT_QUOTES, 'UTF-8'));
            if(!$link)
            {
                return $match[0];
            }

            $destination = $this->publicDestination($link);
            if($destination === '')
            {
                return $match[0];
            }

            // threadPost() supplies a verified fragment derived from pid.
            // Other navigation retains the fragment MyBB originally emitted.
            $fragment = !empty($link['post']) ? '' : $link['fragment'];
            return $match[1].$match[2].VonSEO_Utils::h($destination.$fragment).$match[2];
        }, $html);
    }

    /**
     * MyBB's multipage hook receives a URL template by reference. Keep
     * filtered/special-action pagination native; use keyword paths only for
     * the ordinary forum or thread page set.
     */
    public function rewriteMultipage(array &$args)
    {
        global $thread, $forum, $foruminfo;

        if(!$this->url->keywordUrlsEnabled() || empty($args['url']) ||
           !is_string($args['url']))
        {
            return;
        }

        $native = html_entity_decode($args['url'], ENT_QUOTES, 'UTF-8');
        $script = VonSEO_Utils::currentScript();
        if($script === 'showthread.php' && defined('THREAD_URL_PAGED') &&
           is_array($thread) && !empty($thread['tid']) &&
           is_array($forum) && $this->publicThread($thread, $forum))
        {
            $expected = str_replace('{tid}', (int)$thread['tid'], THREAD_URL_PAGED);
            if($native === html_entity_decode($expected, ENT_QUOTES, 'UTF-8'))
            {
                $rawThread = function_exists('get_thread') ? get_thread((int)$thread['tid']) : false;
                $subject = is_array($rawThread) && isset($rawThread['subject']) ? $rawThread['subject'] : $thread['subject'];
                $args['url'] = $this->url->thread((int)$thread['tid'], 0, $subject).'--p{page}';
                return;
            }
        }
        if(defined('FORUM_URL_PAGED') &&
               ($script === 'forumdisplay.php' || $script === 'showthread.php') &&
               is_array($script === 'forumdisplay.php' ? $foruminfo : $forum))
        {
            $currentForum = $script === 'forumdisplay.php' ? $foruminfo : $forum;
            if(!empty($currentForum['fid']) && $this->publicForum($currentForum))
            {
                $expected = str_replace('{fid}', (int)$currentForum['fid'], FORUM_URL_PAGED);
                if($native === html_entity_decode($expected, ENT_QUOTES, 'UTF-8'))
                {
                    $args['url'] = $this->url->forum((int)$currentForum['fid'], 0,
                        isset($currentForum['name']) ? $currentForum['name'] : '').'--p{page}';
                }
            }
        }
    }

    protected function publicDestination(array $link)
    {
        if($link['kind'] === 'forum')
        {
            if(!array_key_exists($link['id'], $this->forums))
            {
                $forum = function_exists('get_forum') ? get_forum($link['id']) : false;
                $this->forums[$link['id']] = $this->publicForum($forum) ? $forum : false;
            }

            $forum = $this->forums[$link['id']];
            return $forum ? $this->url->forum($link['id'], $link['page'], $forum['name']) : '';
        }

        if(!array_key_exists($link['id'], $this->threads))
        {
            global $threadcache;
            $thread = isset($threadcache[$link['id']]) && is_array($threadcache[$link['id']])
                ? $threadcache[$link['id']] : false;
            if(!is_array($thread) || empty($thread['tid']) || empty($thread['fid']) ||
               !isset($thread['visible']) || !isset($thread['subject']))
            {
                // MyBB already has listing rows in threadcache. Bound only
                // additional database lookups on custom theme links.
                if($this->threadLookups >= 10)
                {
                    return '';
                }
                $this->threadLookups++;
                $thread = function_exists('get_thread') ? get_thread($link['id']) : false;
            }
            $forum = is_array($thread) && !empty($thread['fid']) && function_exists('get_forum')
                ? get_forum((int)$thread['fid']) : false;
            $this->threads[$link['id']] = $this->publicThread($thread, $forum) ? $thread : false;
        }

        $thread = $this->threads[$link['id']];
        if(!$thread)
        {
            return '';
        }
        if(!empty($link['post']) && !$this->publicPost($link['post'], $link['id']))
        {
            return '';
        }

        if(!empty($link['post']))
        {
            return $this->url->threadPost($link['id'], $link['post'], $thread['subject']);
        }
        if(!empty($link['action']))
        {
            return $this->url->threadAction($link['id'], $link['action'], $thread['subject']);
        }

        return $this->url->thread($link['id'], $link['page'], $thread['subject']);
    }

    /**
     * @param array|false $thread
     * @param array|false $forum
     * @return bool
     */
    protected function publicThread($thread, $forum)
    {
        return is_array($thread) && !empty($thread['tid']) && isset($thread['subject']) &&
            isset($thread['visible']) && (int)$thread['visible'] === 1 &&
            (empty($thread['closed']) || strpos((string)$thread['closed'], 'moved|') !== 0) &&
            $this->publicForum($forum);
    }

    /**
     * @param int|string $pid
     * @param int|string $tid
     * @return bool
     */
    protected function publicPost($pid, $tid)
    {
        $pid = (int)$pid;
        $tid = (int)$tid;
        if($pid <= 0 || $tid <= 0)
        {
            return false;
        }

        if(!array_key_exists($pid, $this->posts))
        {
            if($this->postLookups >= 10 || !function_exists('get_post'))
            {
                return false;
            }
            ++$this->postLookups;
            $post = get_post($pid);
            $this->posts[$pid] = is_array($post) && isset($post['tid'], $post['visible']) &&
                (int)$post['tid'] === $tid && (int)$post['visible'] === 1 ? $post : false;
        }

        return (bool)$this->posts[$pid];
    }

    /**
     * @param array|false $forum
     * @return bool
     */
    protected function publicForum($forum)
    {
        if(!is_array($forum) || !empty($forum['linkto']))
        {
            return false;
        }
        $context = new VonSEO_Context($this->url);
        return $context->isForumPublic($forum);
    }

    /**
     * Recognize only board-local, ordinary destinations. All other URLs are
     * left alone. Exact tid+pid targets and MyBB's four stock dynamic thread
     * actions are supported; post-only and filtered URLs stay native.
     *
     * @param string $href
     * @return array|false
     */
    public function parseLink($href)
    {
        $href = html_entity_decode(trim((string)$href), ENT_QUOTES, 'UTF-8');
        $parts = @parse_url($href);
        if(!is_array($parts) || empty($parts['path']))
        {
            return false;
        }

        $base = @parse_url($this->url->base());
        if(!is_array($base))
        {
            return false;
        }
        if(isset($parts['host']))
        {
            $candidateScheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : strtolower($base['scheme']);
            $baseScheme = strtolower($base['scheme']);
            $candidatePort = isset($parts['port']) ? (int)$parts['port'] : ($candidateScheme === 'https' ? 443 : 80);
            $basePort = isset($base['port']) ? (int)$base['port'] : ($baseScheme === 'https' ? 443 : 80);
            if(strcasecmp($parts['host'], $base['host']) !== 0 || $candidateScheme !== $baseScheme || $candidatePort !== $basePort)
            {
                return false;
            }
        }
        if(isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), array('http', 'https'), true))
        {
            return false;
        }
        if(isset($parts['user']) || isset($parts['pass']))
        {
            return false;
        }

        $path = $parts['path'];
        $basePath = isset($base['path']) ? rtrim($base['path'], '/') : '';
        if($path[0] === '/')
        {
            if($basePath !== '' && strpos($path, $basePath.'/') !== 0)
            {
                return false;
            }
            $path = ltrim(substr($path, strlen($basePath)), '/');
        }
        elseif(strpos($path, './') === 0)
        {
            $path = substr($path, 2);
        }
        if($path === '' || strpos($path, '/') !== false || strpos($path, '..') !== false)
        {
            return false;
        }

        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';
        $query = isset($parts['query']) ? $parts['query'] : '';

        if($query === '' && preg_match('~^t-([1-9][0-9]*)-([^/]+)--(lastpost|newpost|nextnewest|nextoldest)$~i', $path, $matches))
        {
            $id = $this->positiveId($matches[1]);
            if($id)
            {
                return array('source' => 'keyword', 'kind' => 'thread',
                    'id' => $id, 'page' => 1, 'post' => 0,
                    'action' => strtolower($matches[3]), 'fragment' => $fragment);
            }
        }

        if($query === '' && preg_match('~^t-([1-9][0-9]*)-([^/]+)--post-([1-9][0-9]*)$~i', $path, $matches))
        {
            $id = $this->positiveId($matches[1]);
            $post = $this->positiveId($matches[3]);
            if($id && $post)
            {
                return array('source' => 'keyword', 'kind' => 'thread',
                    'id' => $id, 'page' => 1, 'post' => $post, 'action' => '', 'fragment' => $fragment);
            }
        }

        if($query === '' && preg_match('~^(t|f)-([1-9][0-9]*)-([^/]+?)(?:--p([1-9][0-9]*))?$~i', $path, $matches))
        {
            $id = $this->positiveId($matches[2]);
            $page = isset($matches[4]) ? $this->positiveId($matches[4]) : 1;
            if($id && $page)
            {
                return array('source' => 'keyword', 'kind' => strtolower($matches[1]) === 't' ? 'thread' : 'forum',
                    'id' => $id, 'page' => $page, 'post' => 0, 'action' => '', 'fragment' => $fragment);
            }
        }

        if($path === 'showthread.php' || $path === 'forumdisplay.php')
        {
            $kind = $path === 'showthread.php' ? 'thread' : 'forum';
            $idKey = $kind === 'thread' ? 'tid' : 'fid';
            $values = $kind === 'thread'
                ? $this->plainThreadQuery($query)
                : $this->plainQuery($query, $idKey, array('page'));
            if($values)
            {
                return array('source' => 'native', 'kind' => $kind,
                    'id' => $values[$idKey], 'page' => isset($values['page']) ? $values['page'] : 1,
                    'post' => isset($values['pid']) ? $values['pid'] : 0,
                    'action' => isset($values['action']) ? $values['action'] : '', 'fragment' => $fragment);
            }
        }

        if($query === '')
        {
            if(preg_match('/^thread-([1-9][0-9]*)-(lastpost|newpost|nextnewest|nextoldest)\.html$/i', $path, $matches))
            {
                $id = $this->positiveId($matches[1]);
                if($id)
                {
                    return array('source' => 'native', 'kind' => 'thread',
                        'id' => $id, 'page' => 1, 'post' => 0,
                        'action' => strtolower($matches[2]), 'fragment' => $fragment);
                }
            }

            foreach(array(
                array('thread', '/^thread-([1-9][0-9]*)-post-([1-9][0-9]*)\.html$/i', true),
                array('thread', '/^thread-([1-9][0-9]*)-page-([1-9][0-9]*)\.html$/i'),
                array('thread', '/^thread-([1-9][0-9]*)\.html$/i'),
                array('forum', '/^forum-([1-9][0-9]*)-page-([1-9][0-9]*)\.html$/i'),
                array('forum', '/^forum-([1-9][0-9]*)\.html$/i')
            ) as $pattern)
            {
                if(preg_match($pattern[1], $path, $matches))
                {
                    $id = $this->positiveId($matches[1]);
                    $page = isset($matches[2]) ? $this->positiveId($matches[2]) : 1;
                    if($id && $page)
                    {
                        $isPost = !empty($pattern[2]);
                        return array('source' => 'native', 'kind' => $pattern[0],
                            'id' => $id, 'page' => $isPost ? 1 : $page,
                            'post' => $isPost ? $page : 0, 'action' => '', 'fragment' => $fragment);
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param string $query
     * @param string $idKey
     * @param array $optionalKeys
     * @return array|false
     */
    protected function plainQuery($query, $idKey, array $optionalKeys = array('page'))
    {
        if($query === '')
        {
            return false;
        }
        $values = array();
        foreach(explode('&', $query) as $part)
        {
            $pair = explode('=', $part, 2);
            if(count($pair) !== 2)
            {
                return false;
            }
            $key = rawurldecode($pair[0]);
            if(($key !== $idKey && !in_array($key, $optionalKeys, true)) || isset($values[$key]))
            {
                return false;
            }
            $values[$key] = $this->positiveId(rawurldecode($pair[1]));
            if(!$values[$key])
            {
                return false;
            }
        }

        if(isset($values['page'], $values['pid']))
        {
            return false;
        }

        return isset($values[$idKey]) ? $values : false;
    }

    /**
     * @param string $query
     * @return array|false
     */
    protected function plainThreadQuery($query)
    {
        if($query === '')
        {
            return false;
        }

        $values = array();
        foreach(explode('&', $query) as $part)
        {
            $pair = explode('=', $part, 2);
            if(count($pair) !== 2)
            {
                return false;
            }

            $key = rawurldecode($pair[0]);
            if(!in_array($key, array('tid', 'page', 'pid', 'action'), true) || isset($values[$key]))
            {
                return false;
            }

            $value = rawurldecode($pair[1]);
            if($key === 'action')
            {
                $value = strtolower($value);
                if(!in_array($value, array('lastpost', 'newpost', 'nextnewest', 'nextoldest'), true))
                {
                    return false;
                }
                $values[$key] = $value;
            }
            else
            {
                $values[$key] = $this->positiveId($value);
                if(!$values[$key])
                {
                    return false;
                }
            }
        }

        $optional = (isset($values['page']) ? 1 : 0) + (isset($values['pid']) ? 1 : 0) +
            (isset($values['action']) ? 1 : 0);
        return isset($values['tid']) && $optional <= 1 ? $values : false;
    }

    /**
     * @param mixed $value
     * @return int
     */
    protected function positiveId($value)
    {
        $value = (string)$value;
        if(!preg_match('/^[1-9][0-9]*$/', $value) || strlen($value) > 19)
        {
            return 0;
        }
        $integer = (int)$value;
        return $integer > 0 && (string)$integer === $value ? $integer : 0;
    }
}
