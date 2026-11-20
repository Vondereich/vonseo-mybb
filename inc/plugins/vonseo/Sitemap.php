<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

class VonSEO_Sitemap
{
    /** MyBB threads.tid is an unsigned INT. */
    const MAX_THREAD_ID = 4294967295;

    /**
     * @var VonSEO_Url
     */
    protected $url;

    /**
     * @var array|null
     */
    protected $publicForums = null;

    /**
     * @var array|null
     */
    protected $publicCalendars = null;

    public function __construct(?VonSEO_Url $url = null)
    {
        $this->url = $url ?: new VonSEO_Url();
    }

    public function output()
    {
        if(!VonSEO_Utils::guestCanAccessBoard())
        {
            $this->outputInvalid();
            return;
        }
        $type = strtolower(VonSEO_Utils::input('type', 'index'));

        if($type === 'forums')
        {
            $this->outputForums();
            return;
        }

        if($type === 'threads')
        {
            $this->outputThreads(max(1, VonSEO_Utils::intInput('page', 1)));
            return;
        }

        if($type === 'announcements')
        {
            $this->outputAnnouncements(max(1, VonSEO_Utils::intInput('page', 1)));
            return;
        }

        if($type === 'calendars')
        {
            $this->outputCalendars();
            return;
        }

        if($type === 'events')
        {
            $this->outputEvents(max(1, VonSEO_Utils::intInput('page', 1)));
            return;
        }

        if($type === 'index' || $type === '')
        {
            $this->outputIndex();
            return;
        }

        $this->outputInvalid();
    }

    protected function outputIndex()
    {
        global $db;

        $this->xmlHeader();

        $chunk = $this->chunkSize();
        $fids = $this->publicForumIds();

        $threadPages = !empty($fids) ? $this->threadPages($fids, $this->threadWhere($fids), $chunk) : array();
        $announcementCount = $this->announcementCount($this->announcementWhere($fids), $fids);
        $announcementPages = $announcementCount > 0 ? (int)ceil($announcementCount / $chunk) : 0;
        $calendarIds = $this->publicCalendarIds();
        $eventCount = $this->eventCount($this->eventWhere($calendarIds), $calendarIds);
        $eventPages = $eventCount > 0 ? (int)ceil($eventCount / $chunk) : 0;

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        echo $this->sitemapIndexNode($this->url->misc('vonseo_sitemap', array('type' => 'forums')));

        foreach($threadPages as $page)
        {
            echo $this->sitemapIndexNode($this->url->misc('vonseo_sitemap', array(
                'type' => 'threads',
                'page' => $page
            )));
        }

        for($page = 1; $page <= $announcementPages; $page++)
        {
            echo $this->sitemapIndexNode($this->url->misc('vonseo_sitemap', array(
                'type' => 'announcements',
                'page' => $page
            )));
        }

        if(!empty($calendarIds))
        {
            echo $this->sitemapIndexNode($this->url->misc('vonseo_sitemap', array('type' => 'calendars')));
        }

        for($page = 1; $page <= $eventPages; $page++)
        {
            echo $this->sitemapIndexNode($this->url->misc('vonseo_sitemap', array(
                'type' => 'events',
                'page' => $page
            )));
        }

        echo "</sitemapindex>\n";
    }

    protected function outputForums()
    {
        global $cache;

        $this->xmlHeader();

        $forums = is_object($cache) ? $cache->read('forums') : false;
        if(!is_array($forums))
        {
            if(function_exists('cache_forums'))
            {
                $forums = cache_forums();
            }
            elseif(is_object($cache) && method_exists($cache, 'update_forums'))
            {
                $cache->update_forums();
                $forums = $cache->read('forums');
            }
            if(!is_array($forums))
            {
                $forums = array();
            }
        }

        $allowed = array_flip($this->publicForumIds());
        if(class_exists('VonSEO_Overrides'))
        {
            $allowed = array_flip(VonSEO_Overrides::indexedForumIds(array_keys($allowed)));
        }

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        echo $this->urlNode($this->url->home(), '');

        foreach($forums as $fid => $forum)
        {
            $fid = (int)$fid;

            if(!isset($allowed[$fid]))
            {
                continue;
            }

            echo $this->urlNode($this->url->forum($fid, 0, isset($forum['name']) ? $forum['name'] : ''), '');
        }

        echo "</urlset>\n";
    }

    /**
     * @param int $page
     * @return void
     */
    protected function outputThreads($page)
    {
        global $db;

        $chunk = $this->chunkSize();
        $fids = $this->publicForumIds();

        // Page N covers the fixed thread-ID range ((N-1)*chunk, N*chunk]. A
        // primary-key range reads at most one chunk of rows, unlike a deep
        // OFFSET, and a page's content does not shift when older threads are
        // hidden or deleted. Empty ranges are omitted from the index.
        $rows = array();
        $first = ($page - 1) * $chunk + 1;
        if(!empty($fids) && $first <= self::MAX_THREAD_ID)
        {
            $last = min(self::MAX_THREAD_ID, $page * $chunk);
            $query = $db->simple_select(
                'threads',
                'tid,subject,lastpost,dateline',
                $this->threadWhere($fids)." AND tid BETWEEN {$first} AND {$last}",
                array(
                    'order_by' => 'tid',
                    'order_dir' => 'ASC',
                    'limit' => $chunk
                )
            );
            while($thread = $db->fetch_array($query))
            {
                $rows[] = $thread;
            }
        }

        $GLOBALS['vonseo_sitemap_status'] = 200;
        if(!$rows)
        {
            $GLOBALS['vonseo_sitemap_status'] = 404;
            if(!headers_sent())
            {
                http_response_code(404);
                header('X-Robots-Tag: noindex, follow', true);
            }
        }

        $this->xmlHeader();

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        foreach($rows as $thread)
        {
            $lastmod = !empty($thread['lastpost']) ? VonSEO_Utils::isoDate($thread['lastpost']) : VonSEO_Utils::isoDate($thread['dateline']);
            echo $this->urlNode($this->url->thread((int)$thread['tid'], 0, isset($thread['subject']) ? $thread['subject'] : ''), $lastmod);
        }

        echo "</urlset>\n";
    }

    /**
     * @param int $page
     * @return void
     */
    protected function outputAnnouncements($page)
    {
        global $db;

        $chunk = $this->chunkSize();
        $fids = $this->publicForumIds();
        $where = $this->announcementWhere($fids);
        $count = $this->announcementCount($where, $fids);
        $pages = $count > 0 ? (int)ceil($count / $chunk) : 0;

        $GLOBALS['vonseo_sitemap_status'] = 200;
        if($page > $pages)
        {
            $GLOBALS['vonseo_sitemap_status'] = 404;
            if(!headers_sent())
            {
                http_response_code(404);
                header('X-Robots-Tag: noindex, follow', true);
            }
        }

        $this->xmlHeader();
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        if($page <= $pages)
        {
            $query = $db->simple_select('announcements', 'aid', $where, array(
                'order_by' => 'aid',
                'order_dir' => 'ASC',
                'limit_start' => ($page - 1) * $chunk,
                'limit' => $chunk
            ));
            while($announcement = $db->fetch_array($query))
            {
                echo $this->urlNode($this->url->announcement((int)$announcement['aid']), '');
            }
        }

        echo "</urlset>\n";
    }

    protected function outputCalendars()
    {
        $this->xmlHeader();
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach($this->publicCalendarIds() as $cid)
        {
            echo $this->urlNode($this->url->calendar($cid), '');
        }
        echo "</urlset>\n";
    }

    /**
     * @param int $page
     * @return void
     */
    protected function outputEvents($page)
    {
        global $db;

        $chunk = $this->chunkSize();
        $calendarIds = $this->publicCalendarIds();
        $where = $this->eventWhere($calendarIds);
        $count = $this->eventCount($where, $calendarIds);
        $pages = $count > 0 ? (int)ceil($count / $chunk) : 0;

        $GLOBALS['vonseo_sitemap_status'] = 200;
        if($page > $pages)
        {
            $GLOBALS['vonseo_sitemap_status'] = 404;
            if(!headers_sent())
            {
                http_response_code(404);
                header('X-Robots-Tag: noindex, follow', true);
            }
        }

        $this->xmlHeader();
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        if($page <= $pages)
        {
            $query = $db->simple_select('events', 'eid', $where, array(
                'order_by' => 'eid',
                'order_dir' => 'ASC',
                'limit_start' => ($page - 1) * $chunk,
                'limit' => $chunk
            ));
            while($event = $db->fetch_array($query))
            {
                // MyBB's event dateline is creation time and is not refreshed
                // on edit, so it cannot truthfully represent sitemap lastmod.
                echo $this->urlNode($this->url->event((int)$event['eid']), '');
            }
        }
        echo "</urlset>\n";
    }

    protected function publicForumIds()
    {
        global $cache;

        if($this->publicForums !== null)
        {
            return $this->publicForums;
        }

        $forums = is_object($cache) ? $cache->read('forums') : false;
        if(!is_array($forums))
        {
            if(function_exists('cache_forums'))
            {
                $forums = cache_forums();
            }
            elseif(is_object($cache) && method_exists($cache, 'update_forums'))
            {
                $cache->update_forums();
                $forums = $cache->read('forums');
            }
            if(!is_array($forums))
            {
                $forums = array();
            }
        }

        $inactive = function_exists('get_inactive_forums') ? get_inactive_forums() : '';
        $inactiveIds = array();
        if($inactive)
        {
            $inactiveIds = array_flip(array_filter(array_map('intval', explode(',', $inactive))));
        }

        $ids = array();

        foreach($forums as $fid => $forum)
        {
            $fid = (int)$fid;

            if($fid <= 0)
            {
                continue;
            }

            if(isset($forum['type']) && $forum['type'] !== 'f')
            {
                continue;
            }

            if(isset($forum['active']) && (int)$forum['active'] !== 1)
            {
                continue;
            }

            if(!empty($forum['linkto']))
            {
                continue;
            }

            if(isset($inactiveIds[$fid]))
            {
                continue;
            }

            if(VonSEO_Utils::forumHasPasswordBarrier($forum, $forums))
            {
                continue;
            }

            $perms = function_exists('forum_permissions') ? forum_permissions($fid, 0, 1) : false;
            if(!$perms || empty($perms['canview']) || empty($perms['canviewthreads']))
            {
                continue;
            }

            if(!empty($perms['canonlyviewownthreads']))
            {
                continue;
            }

            $ids[] = $fid;
        }

        $this->publicForums = array_values(array_unique(array_map('intval', $ids)));
        return $this->publicForums;
    }

    protected function publicCalendarIds()
    {
        global $db;

        if($this->publicCalendars !== null)
        {
            return $this->publicCalendars;
        }

        $ids = array();
        $query = $db->simple_select('calendars', 'cid', '', array('order_by' => 'cid', 'order_dir' => 'ASC'));
        while($calendar = $db->fetch_array($query))
        {
            $cid = isset($calendar['cid']) ? (int)$calendar['cid'] : 0;
            if($cid > 0 && VonSEO_Utils::guestCanViewCalendar($cid))
            {
                $ids[] = $cid;
            }
        }

        $this->publicCalendars = array_values(array_unique($ids));
        return $this->publicCalendars;
    }

    /**
     * Shared public-thread eligibility for the index and every thread page.
     *
     * @param array $fids
     * @return string
     */
    protected function threadWhere(array $fids)
    {
        $timeNow = defined('TIME_NOW') ? (int)TIME_NOW : time();
        $where = "visible='1' AND dateline<='{$timeNow}' AND fid IN (".implode(',', array_map('intval', $fids)).") AND closed NOT LIKE 'moved|%'";
        if(class_exists('VonSEO_Overrides'))
        {
            $where .= VonSEO_Overrides::threadSitemapPredicate();
        }
        return $where;
    }

    /**
     * Non-empty thread-ID range pages, from one aggregated query cached for
     * ten minutes. Child pages never read this list: they query their own
     * primary-key range and return 404 when it is empty.
     *
     * @param array $fids
     * @param string $where
     * @param int $chunk
     * @return int[]
     */
    protected function threadPages(array $fids, $where, $chunk)
    {
        global $db, $cache;

        $chunk = max(1, (int)$chunk);
        $now = defined('TIME_NOW') ? (int)TIME_NOW : time();
        $key = 'vonseo_sitemap_pages_'.substr(hash('sha256', implode(',', $fids).'|'.$chunk), 0, 16);
        $revision = class_exists('VonSEO_Overrides') ? VonSEO_Overrides::revision() : '';
        if(is_object($cache) && method_exists($cache, 'read'))
        {
            $cached = $cache->read($key);
            if(is_array($cached) && isset($cached['expires'], $cached['pages']) && is_array($cached['pages']) &&
               (int)$cached['expires'] >= $now &&
               (isset($cached['revision']) ? (string)$cached['revision'] : '') === $revision)
            {
                return array_values(array_filter(array_map('intval', $cached['pages']), function($page) {
                    return $page > 0;
                }));
            }
        }

        $query = $db->simple_select('threads', "FLOOR((tid-1)/{$chunk}) AS bucket", $where, array(
            'group_by' => 'bucket',
            'order_by' => 'bucket',
            'order_dir' => 'ASC'
        ));
        $pages = array();
        while($row = $db->fetch_array($query))
        {
            if(isset($row['bucket']) && is_numeric($row['bucket']) && (int)$row['bucket'] >= 0)
            {
                $pages[] = (int)$row['bucket'] + 1;
            }
        }
        $pages = array_values(array_unique($pages));
        sort($pages);
        if(is_object($cache) && method_exists($cache, 'update'))
        {
            $cache->update($key, array('pages' => $pages, 'expires' => $now + 600, 'revision' => $revision));
        }
        return $pages;
    }

    /**
     * @param array $fids
     * @return string
     */
    protected function announcementWhere(array $fids)
    {
        $now = defined('TIME_NOW') ? (int)TIME_NOW : time();
        $scope = 'fid<=0';
        if(!empty($fids))
        {
            $scope = '(fid<=0 OR fid IN ('.implode(',', array_map('intval', $fids)).'))';
        }

        return "startdate<='{$now}' AND (enddate>='{$now}' OR enddate='0') AND {$scope}";
    }

    /**
     * @param string $where
     * @param array $fids
     * @return int
     */
    protected function announcementCount($where, array $fids = array())
    {
        global $db, $cache;

        $now = defined('TIME_NOW') ? (int)TIME_NOW : time();
        $fids = array_values(array_unique(array_map('intval', $fids)));
        sort($fids, SORT_NUMERIC);
        $scope = hash('sha256', implode(',', $fids));
        // Keep one durable cache row. The SQL contains the current timestamp,
        // so hashing the full WHERE clause would create a new key every second.
        $key = 'vonseo_sitemap_announcement_count';
        if(is_object($cache) && method_exists($cache, 'read'))
        {
            $cached = $cache->read($key);
            if(is_array($cached) && isset($cached['scope'], $cached['expires'], $cached['count']) &&
               hash_equals($scope, (string)$cached['scope']) && (int)$cached['expires'] >= $now)
            {
                return max(0, (int)$cached['count']);
            }
        }

        $query = $db->simple_select('announcements', 'COUNT(aid) AS total', $where);
        $count = max(0, (int)$db->fetch_field($query, 'total'));
        if(is_object($cache) && method_exists($cache, 'update'))
        {
            $cache->update($key, array('scope' => $scope, 'count' => $count, 'expires' => $now + 600));
        }
        return $count;
    }

    /**
     * @param array $calendarIds
     * @return string
     */
    protected function eventWhere(array $calendarIds)
    {
        if(empty($calendarIds))
        {
            return '1=0';
        }

        return "visible='1' AND private='0' AND cid IN (".implode(',', array_map('intval', $calendarIds)).')';
    }

    /**
     * @param string $where
     * @param array $calendarIds
     * @return int
     */
    protected function eventCount($where, array $calendarIds = array())
    {
        global $db, $cache;

        if(empty($calendarIds))
        {
            return 0;
        }

        $now = defined('TIME_NOW') ? (int)TIME_NOW : time();
        $calendarIds = array_values(array_unique(array_map('intval', $calendarIds)));
        sort($calendarIds, SORT_NUMERIC);
        $scope = hash('sha256', implode(',', $calendarIds));
        $key = 'vonseo_sitemap_event_count';
        if(is_object($cache) && method_exists($cache, 'read'))
        {
            $cached = $cache->read($key);
            if(is_array($cached) && isset($cached['scope'], $cached['expires'], $cached['count']) &&
               hash_equals($scope, (string)$cached['scope']) && (int)$cached['expires'] >= $now)
            {
                return max(0, (int)$cached['count']);
            }
        }

        $query = $db->simple_select('events', 'COUNT(eid) AS total', $where);
        $count = max(0, (int)$db->fetch_field($query, 'total'));
        if(is_object($cache) && method_exists($cache, 'update'))
        {
            $cache->update($key, array('scope' => $scope, 'count' => $count, 'expires' => $now + 600));
        }
        return $count;
    }

    protected function outputInvalid()
    {
        $GLOBALS['vonseo_sitemap_status'] = 404;
        if(!headers_sent())
        {
            http_response_code(404);
            header('X-Robots-Tag: noindex, follow', true);
        }
        $this->xmlHeader();
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\"></urlset>\n";
    }

    protected function chunkSize()
    {
        $chunk = (int)VonSEO_Utils::setting('vonseo_sitemap_chunk', 1000);

        if($chunk < 100)
        {
            $chunk = 100;
        }
        if($chunk > 50000)
        {
            $chunk = 50000;
        }

        return $chunk;
    }

    /**
     * @param string $loc
     * @return string
     */
    protected function sitemapIndexNode($loc)
    {
        return "  <sitemap>\n"
            ."    <loc>".VonSEO_Utils::xml($loc)."</loc>\n"
            ."  </sitemap>\n";
    }

    /**
     * @param string $loc
     * @param string $lastmod
     * @return string
     */
    protected function urlNode($loc, $lastmod)
    {
        $xml = "  <url>\n"
            ."    <loc>".VonSEO_Utils::xml($loc)."</loc>\n";

        if($lastmod !== '')
        {
            $xml .= "    <lastmod>".VonSEO_Utils::xml($lastmod)."</lastmod>\n";
        }

        $xml .= "  </url>\n";
        return $xml;
    }

    protected function xmlHeader()
    {
        if(!headers_sent())
        {
            header('Content-Type: application/xml; charset=UTF-8');
        }
    }
}
