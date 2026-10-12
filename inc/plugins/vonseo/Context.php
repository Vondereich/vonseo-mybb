<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

class VonSEO_Context
{
    /** @var array */
    protected static $guestFirstPost = array();

    /** @var array */
    protected static $guestAnnouncement = array();

    /** @var array */
    protected static $guestCalendar = array();

    /** @var array */
    protected static $guestEvent = array();

    public $type = 'other';
    public $script = '';
    public $id = 0;
    public $page = 1;
    public $indexable = false;
    public $title = '';
    public $description = '';
    public $canonical = '';
    public $socialImage = '';
    public $data = array();
    /** @var bool Guest-visible content, independent of an SEO noindex choice. */
    public $publicContent = false;
    /** @var array */
    public $seoSources = array('title' => 'Automatic', 'description' => 'Automatic',
        'socialImage' => 'Global fallback', 'indexable' => 'Guest permissions and existing policy');
    /** @var array */
    public $seoWarnings = array();
    /** @var bool */
    protected $applySeoOverrides = true;

    /**
     * @var VonSEO_Url
     */
    protected $url;

    public function __construct(VonSEO_Url $url)
    {
        $this->url = $url;
    }

    /**
     * MyBB's postbit hook supplies the already-rendered public post. Capture
     * only the original post on a guest thread page, never raw database MyCode.
     */
    public static function captureGuestFirstPost(array $post)
    {
        global $mybb, $thread, $forum;

        if(VonSEO_Utils::currentScript() !== 'showthread.php' ||
           !empty($mybb->user['uid']) || !is_array($thread) || !is_array($forum) ||
           empty($thread['tid']) || empty($thread['firstpost']) ||
           !isset($thread['visible']) || (int)$thread['visible'] !== 1 ||
           (isset($thread['closed']) && strpos((string)$thread['closed'], 'moved|') === 0) ||
           !isset($post['pid'], $post['visible'], $post['message']) ||
           (isset($post['tid']) && (int)$post['tid'] !== (int)$thread['tid']) ||
           (int)$post['pid'] !== (int)$thread['firstpost'] || (int)$post['visible'] !== 1)
        {
            return;
        }

        $context = new self(new VonSEO_Url());
        if(!$context->isForumPublic($forum))
        {
            return;
        }

        $text = VonSEO_Utils::renderedPostText($post['message']);
        if($text === '')
        {
            return;
        }

        self::$guestFirstPost = array(
            'tid' => (int)$thread['tid'],
            'pid' => (int)$post['pid'],
            'text' => $text,
            'edittime' => !empty($post['edittime']) ? (int)$post['edittime'] : 0
        );
    }

    /**
     * Capture only the announcement text MyBB rendered for a guest. Raw
     * announcement MyCode is not used for descriptions or structured data.
     *
     * @param array $post
     * @return void
     */
    public static function captureGuestAnnouncement(array $post)
    {
        global $mybb, $announcementarray, $forum;

        if(VonSEO_Utils::currentScript() !== 'announcements.php' ||
           !empty($mybb->user['uid']) || !is_array($announcementarray) ||
           empty($announcementarray['aid']) || !isset($post['message']))
        {
            return;
        }

        $context = new self(new VonSEO_Url());
        $forumData = is_array($forum) ? $forum : array();
        if(!$context->isAnnouncementPublic($announcementarray, $forumData))
        {
            return;
        }

        $text = VonSEO_Utils::renderedPostText($post['message']);
        if($text === '')
        {
            return;
        }

        self::$guestAnnouncement = array(
            'aid' => (int)$announcementarray['aid'],
            'text' => $text
        );
    }

    /**
     * Capture the resolved public calendar month before MyBB replaces its
     * calendar array with the rendered page HTML.
     *
     * @param array $calendarData
     * @param array $permissions
     * @param int $year
     * @param int $month
     * @param string $label
     * @param bool $dateExplicit
     * @return void
     */
    public static function captureGuestCalendar(array $calendarData, array $permissions, $year, $month, $label, $dateExplicit = false)
    {
        global $mybb;

        self::$guestCalendar = array();
        if(VonSEO_Utils::currentScript() !== 'calendar.php' ||
           VonSEO_Utils::input('action') !== '')
        {
            return;
        }

        $context = new self(new VonSEO_Url());
        if(!$context->isCalendarPublic($calendarData, null))
        {
            return;
        }

        $year = (int)$year;
        $month = (int)$month;
        if($year < 1901 || $month < 1 || $month > 12)
        {
            return;
        }

        self::$guestCalendar = array(
            'cid' => (int)$calendarData['cid'],
            'calendar' => $calendarData,
            'year' => $year,
            'month' => $month,
            'label' => VonSEO_Utils::plainText($label),
            'date_explicit' => (bool)$dateExplicit
        );
    }

    /**
     * Capture the event description after MyBB parses it for a guest. Private
     * and unapproved events are cleared before any metadata can be resolved.
     *
     * @param array $eventData
     * @param array $calendarData
     * @param array $permissions
     * @return void
     */
    public static function captureGuestEvent(array $eventData, array $calendarData, array $permissions)
    {
        global $mybb;

        self::$guestEvent = array();
        if(VonSEO_Utils::currentScript() !== 'calendar.php' ||
           VonSEO_Utils::input('action') !== 'event')
        {
            return;
        }

        $context = new self(new VonSEO_Url());
        if(!$context->isEventPublic($eventData, $calendarData, null))
        {
            return;
        }

        self::$guestEvent = array(
            'eid' => (int)$eventData['eid'],
            'event' => $eventData,
            'calendar' => $calendarData,
            // Only guest-rendered text may enter description/schema. Logged-in
            // views keep the same public identity and use the safe fallback.
            'text' => empty($mybb->user['uid']) && isset($eventData['description'])
                ? VonSEO_Utils::renderedPostText($eventData['description']) : ''
        );
    }

    public function resolve()
    {
        global $mybb, $thread, $forum, $memprofile, $announcementarray, $db, $page;

        $this->script = VonSEO_Utils::currentScript();
        $this->page = max(1, VonSEO_Utils::intInput('page', 1));
        // MyBB calculates the containing page from pid without writing it
        // back to the request input. At render time the global page is the
        // authoritative value for post-target and "last post" navigation.
        if(in_array($this->script, array('showthread.php', 'forumdisplay.php', 'portal.php'), true) &&
           isset($page) && is_numeric($page) && (int)$page > 0)
        {
            $this->page = (int)$page;
        }

        switch($this->script)
        {
            case 'index.php':
                return $this->resolveHome();

            case 'forumdisplay.php':
                $fid = !empty($forum['fid']) ? (int)$forum['fid'] : VonSEO_Utils::intInput('fid');
                return $this->resolveForum($fid, is_array($forum) ? $forum : array());

            case 'showthread.php':
                $tid = !empty($thread['tid']) ? (int)$thread['tid'] : VonSEO_Utils::intInput('tid');
                return $this->resolveThread($tid, is_array($thread) ? $thread : array(), is_array($forum) ? $forum : array());

            case 'announcements.php':
                $aid = !empty($announcementarray['aid']) ? (int)$announcementarray['aid'] : VonSEO_Utils::intInput('aid');
                return $this->resolveAnnouncement($aid, is_array($announcementarray) ? $announcementarray : array(),
                    is_array($forum) ? $forum : array());

            case 'calendar.php':
                $calendarAction = VonSEO_Utils::input('action');
                if($calendarAction === 'event')
                {
                    return $this->resolveEvent(VonSEO_Utils::intInput('eid'));
                }
                if($calendarAction === '')
                {
                    $cid = !empty(self::$guestCalendar['cid'])
                        ? (int)self::$guestCalendar['cid'] : VonSEO_Utils::intInput('calendar');
                    return $this->resolveCalendar($cid);
                }
                return $this->resolveNoindex('calendar');

            case 'member.php':
                $action = VonSEO_Utils::input('action');
                if($action === 'profile')
                {
                    $uid = !empty($memprofile['uid']) ? (int)$memprofile['uid'] : VonSEO_Utils::intInput('uid');
                    return $this->resolveProfile($uid, is_array($memprofile) ? $memprofile : array());
                }
                return $this->resolveNoindex('member');

            case 'portal.php':
                return $this->resolvePortal();

            default:
                return $this->resolveNoindex('other');
        }
    }

    protected function resolveHome()
    {
        global $mybb;

        $this->type = 'home';
        $this->indexable = true;
        $this->title = $this->boardName();

        $this->description = VonSEO_Utils::truncateDescription(VonSEO_Utils::setting('vonseo_site_description'));
        $this->canonical = $this->url->home();
        $this->socialImage = $this->defaultImage();

        return $this;
    }

    protected function resolvePortal()
    {
        global $mybb;

        $this->type = 'portal';
        $this->indexable = (bool)VonSEO_Utils::setting('vonseo_portal_index', 1);
        $portalTitle = $this->page > 1 ? 'Portal - Page '.$this->page : 'Portal';
        $this->title = $this->withBoardName($portalTitle);
        $this->description = VonSEO_Utils::truncateDescription(VonSEO_Utils::setting('vonseo_site_description'));
        $this->canonical = $this->url->absolute('portal.php'.($this->page > 1 ? '?page='.$this->page : ''));
        $this->socialImage = $this->defaultImage();

        return $this;
    }

    /** ACP snapshot: no network calls, raw MyCode rendering or global request mutation. @return $this */
    public function resolveEntity(string $type, int $id, int $page = 1, bool $automaticOnly = false)
    {
        $this->page = max(1, min(1000000, $page));
        $this->applySeoOverrides = !$automaticOnly;
        if($type === 'forum')
        {
            $forum = get_forum($id);
            $forum = is_array($forum) ? $forum : array();
            $this->normalizeSnapshotPage('forum', $id, $forum, $forum);
            return $this->resolveForum($id, $forum);
        }
        if($type === 'thread')
        {
            $thread = get_thread($id);
            $thread = is_array($thread) ? $thread : array();
            $forum = !empty($thread['fid']) ? get_forum((int)$thread['fid']) : array();
            $forum = is_array($forum) ? $forum : array();
            $this->normalizeSnapshotPage('thread', $id, $thread, $forum);
            $result = $this->resolveThread($id, $thread, $forum);
            if($this->publicContent && empty($this->data['first_post_text']) && $this->page === 1)
            {
                $this->seoWarnings[] = 'ACP cannot capture a real guest-rendered first post. The automatic description here is a fallback snapshot; verify the guest thread page when no description override is used.';
                $this->seoWarnings[] = 'ACP schema snapshot cannot include DiscussionForumPosting without a real guest-rendered first post. A custom description does not supply that post; verify schema on the actual guest thread page.';
            }
            return $result;
        }
        return $this->resolveNoindex('other');
    }

    /** Stock guest pagination only, without admin visibility or request mutation. @return void */
    protected function normalizeSnapshotPage(string $type, int $id, array $entity, array $forum)
    {
        global $db;
        if($this->page === 1 || !$entity || !$this->isForumPublic($forum) ||
           ($type === 'thread' && (!isset($entity['visible']) || (int)$entity['visible'] !== 1 ||
            (isset($entity['closed']) && strpos((string)$entity['closed'], 'moved|') === 0))))
        {
            return;
        }
        $requested = $this->page;
        $perPage = (int)VonSEO_Utils::setting($type === 'thread' ? 'postsperpage' : 'threadsperpage', 20);
        if($perPage < 1)
        {
            $perPage = 20;
        }
        $permissions = forum_permissions((int)$forum['fid'], 0, 1);
        $notices = !empty($permissions['canviewdeletionnotice']);
        if($type === 'thread')
        {
            if($notices)
            {
                $query = $db->simple_select('posts', 'COUNT(*) AS total', "tid='{$id}' AND visible IN (1,-1)");
                $count = (int)$db->fetch_field($query, 'total');
            }
            elseif(isset($entity['replies']))
            {
                $count = max(0, (int)$entity['replies']) + 1;
            }
            else
            {
                $this->page = 1;
                $this->seoWarnings[] = 'Guest page counters are unavailable. The snapshot uses page 1; check the actual guest page for pagination.';
                return;
            }
        }
        else
        {
            $days = isset($forum['defaultdatecut']) ? (int)$forum['defaultdatecut'] : 9999;
            if($days > 0 && $days !== 9999)
            {
                $now = defined('TIME_NOW') ? TIME_NOW : time();
                $cutoff = (int)($now - $days * 86400);
                $states = $notices ? '1,-1' : '1';
                $query = $db->simple_select('threads', 'COUNT(*) AS total',
                    "fid='{$id}' AND visible IN ({$states}) AND (lastpost >= '{$cutoff}' OR sticky='1')");
                $count = (int)$db->fetch_field($query, 'total');
            }
            else
            {
                // MyBB reads fresh forum counters rather than the forum cache.
                $query = $db->simple_select('forums', 'threads,deletedthreads', "fid='{$id}'", array('limit' => 1));
                $counters = $db->fetch_array($query);
                $count = is_array($counters) && isset($counters['threads']) ? max(0, (int)$counters['threads']) : 0;
                if($notices && is_array($counters) && isset($counters['deletedthreads']))
                {
                    $count += max(0, (int)$counters['deletedthreads']);
                }
            }
        }
        if($requested > max(1, (int)ceil($count / $perPage)))
        {
            // Stock MyBB returns out-of-range requests to page 1, not the last page.
            $this->page = 1;
            $this->seoWarnings[] = 'Requested page '.$requested.' is outside the guest-visible page range. The snapshot uses page 1.';
        }
    }

    /** @return string */
    public function formatSeoTitle(string $title)
    {
        if($this->page > 1)
        {
            $title .= ' - Page '.$this->page;
        }
        return $this->withBoardName($title);
    }

    /** @return void */
    protected function applyOverrides()
    {
        $this->publicContent = $this->indexable;
        if($this->applySeoOverrides && class_exists('VonSEO_Overrides'))
        {
            VonSEO_Overrides::apply($this);
        }
    }

    /**
     * @param int $fid
     * @param array $forumData
     * @return $this
     */
    protected function resolveForum($fid, array $forumData)
    {
        global $db;

        $this->type = 'forum';
        $this->id = (int)$fid;

        if($this->id <= 0)
        {
            return $this->resolveNoindex('forum');
        }

        if(empty($forumData))
        {
            $forumData = get_forum($this->id);
        }

        if(!$forumData || !is_array($forumData))
        {
            return $this->resolveNoindex('forum');
        }

        $this->data = $forumData;
        $this->indexable = $this->isForumPublic($forumData);

        $name = isset($forumData['name']) ? VonSEO_Utils::plainText($forumData['name']) : '';
        $forumTitle = $this->page > 1 ? $name.' - Page '.$this->page : $name;
        $this->title = $this->withBoardName($forumTitle);
        $this->description = VonSEO_Utils::truncateDescription(isset($forumData['description']) ? $forumData['description'] : '');
        if($this->description === '')
        {
            $this->description = VonSEO_Utils::truncateDescription(VonSEO_Utils::setting('vonseo_site_description'));
            $this->seoSources['description'] = 'Global fallback';
        }

        $this->canonical = $this->url->forum($this->id, $this->page, isset($forumData['name']) ? $forumData['name'] : '');
        $this->socialImage = $this->defaultImage();
        $this->applyOverrides();

        return $this;
    }

    /**
     * @param int $tid
     * @param array $threadData
     * @param array $forumData
     * @return $this
     */
    protected function resolveThread($tid, array $threadData, array $forumData)
    {
        global $db;

        $this->type = 'thread';
        $this->id = (int)$tid;

        if($this->id <= 0)
        {
            return $this->resolveNoindex('thread');
        }

        if(empty($threadData))
        {
            $threadData = get_thread($this->id);
        }

        if(!$threadData || !is_array($threadData))
        {
            return $this->resolveNoindex('thread');
        }

        if(empty($forumData) && !empty($threadData['fid']))
        {
            $forumData = get_forum((int)$threadData['fid']);
        }

        $this->data = array(
            'thread' => $threadData,
            'forum'  => is_array($forumData) ? $forumData : array()
        );

        $visible = isset($threadData['visible']) ? (int)$threadData['visible'] : 0;
        $moved = isset($threadData['closed']) && strpos((string)$threadData['closed'], 'moved|') === 0;

        $this->indexable = ($visible === 1 && !$moved && $this->isForumPublic($forumData));

        $subject = isset($threadData['subject']) ? VonSEO_Utils::plainText($threadData['subject']) : '';
        $this->title = $this->withBoardName($subject);
        if($this->page > 1)
        {
            $this->title = $subject.' - Page '.$this->page.$this->separator().$this->boardName();
        }

        $firstPost = self::$guestFirstPost;
        if($this->page === 1 && !empty($firstPost['text']) &&
           (int)$firstPost['tid'] === $this->id &&
           !empty($threadData['firstpost']) &&
           (int)$firstPost['pid'] === (int)$threadData['firstpost'] && $this->indexable)
        {
            $this->data['first_post_text'] = $firstPost['text'];
            $this->data['first_post_edittime'] = (int)$firstPost['edittime'];
            $this->description = VonSEO_Utils::truncateDescription($firstPost['text']);
        }
        if($this->description === '')
        {
            $this->description = VonSEO_Utils::truncateDescription(VonSEO_Utils::setting('vonseo_site_description'));
        }

        // showthread.php replaces the global subject with an escaped,
        // badword-filtered display string. MyBB's get_thread() cache retains
        // the database subject used by sitemap, IndexNow and forum listings.
        $urlSubject = isset($threadData['subject']) ? $threadData['subject'] : '';
        if(function_exists('get_thread'))
        {
            $rawThread = get_thread($this->id);
            if(is_array($rawThread) && isset($rawThread['subject']))
            {
                $urlSubject = $rawThread['subject'];
            }
        }
        $this->canonical = $this->url->thread($this->id, $this->page, $urlSubject);
        $this->data['first_page_url'] = $this->url->thread($this->id, 0, $urlSubject);
        $this->socialImage = $this->threadSocialImage($threadData);
        if(empty($this->data['first_post_text']))
        {
            $this->seoSources['description'] = 'Global fallback';
        }
        $this->applyOverrides();

        return $this;
    }

    /**
     * @param int $aid
     * @param array $announcementData
     * @param array $forumData
     * @return $this
     */
    protected function resolveAnnouncement($aid, array $announcementData, array $forumData)
    {
        global $db;

        $this->type = 'announcement';
        $this->id = (int)$aid;
        if($this->id <= 0)
        {
            return $this->resolveNoindex('announcement');
        }

        if(empty($announcementData) && is_object($db))
        {
            $query = $db->simple_select('announcements', '*', "aid='{$this->id}'", array('limit' => 1));
            $announcementData = $db->fetch_array($query);
        }
        if(!$announcementData || !is_array($announcementData))
        {
            return $this->resolveNoindex('announcement');
        }

        $fid = isset($announcementData['fid']) ? (int)$announcementData['fid'] : 0;
        if($fid > 0 && (empty($forumData['fid']) || (int)$forumData['fid'] !== $fid))
        {
            $forumData = function_exists('get_forum') ? get_forum($fid) : array();
        }

        $this->data = array(
            'announcement' => $announcementData,
            'forum' => is_array($forumData) ? $forumData : array()
        );
        $this->indexable = $this->isAnnouncementPublic($announcementData, $forumData);

        $subject = isset($announcementData['subject'])
            ? VonSEO_Utils::plainText($announcementData['subject']) : '';
        $this->title = $this->withBoardName($subject);

        $rendered = self::$guestAnnouncement;
        if($this->indexable && !empty($rendered['text']) && (int)$rendered['aid'] === $this->id)
        {
            $this->data['announcement_text'] = $rendered['text'];
            $this->description = VonSEO_Utils::truncateDescription($rendered['text']);
        }
        if($this->description === '')
        {
            $this->description = VonSEO_Utils::truncateDescription(VonSEO_Utils::setting('vonseo_site_description'));
        }

        $this->canonical = $this->url->announcement($this->id);
        $this->socialImage = $this->defaultImage();

        return $this;
    }

    /**
     * @param int $cid
     * @return $this
     */
    protected function resolveCalendar($cid)
    {
        $this->type = 'calendar';
        $this->id = (int)$cid;
        $captured = self::$guestCalendar;
        if($this->id <= 0 || empty($captured['calendar']) ||
           (int)$captured['cid'] !== $this->id)
        {
            return $this->resolveNoindex('calendar');
        }

        $calendarData = $captured['calendar'];
        $this->data = array(
            'calendar' => $calendarData,
            'year' => (int)$captured['year'],
            'month' => (int)$captured['month']
        );
        $this->indexable = true;

        $name = isset($calendarData['name']) ? VonSEO_Utils::plainText($calendarData['name']) : '';
        $label = isset($captured['label']) ? VonSEO_Utils::plainText($captured['label']) : '';
        $calendarTitle = trim($name.($label !== '' ? ' - '.$label : ''));
        $this->title = $this->withBoardName($calendarTitle);
        $this->description = VonSEO_Utils::truncateDescription(VonSEO_Utils::setting('vonseo_site_description'));
        $isCurrentMonth = $this->isCurrentCalendarMonth(
            (int)$captured['year'],
            (int)$captured['month']
        );
        $this->canonical = !empty($captured['date_explicit']) && !$isCurrentMonth
            ? $this->url->calendar($this->id, (int)$captured['year'], (int)$captured['month'])
            : $this->url->calendar($this->id);
        $this->socialImage = $this->defaultImage();

        return $this;
    }

    /**
     * Compare against the board's guest timezone so canonical identity does not
     * change with the timezone of a logged-in visitor.
     *
     * @param int $year
     * @param int $month
     * @return bool
     */
    protected function isCurrentCalendarMonth($year, $month)
    {
        $offset = (float)VonSEO_Utils::setting('timezoneoffset', 0);
        if((int)VonSEO_Utils::setting('dstcorrection', 0) === 1)
        {
            $offset += 1;
        }
        $now = defined('TIME_NOW') ? (int)TIME_NOW : time();
        $guestNow = $now + (int)round($offset * 3600);
        return (int)$year === (int)gmdate('Y', $guestNow) &&
            (int)$month === (int)gmdate('n', $guestNow);
    }

    /**
     * @param int $eid
     * @return $this
     */
    protected function resolveEvent($eid)
    {
        $this->type = 'event';
        $this->id = (int)$eid;
        $captured = self::$guestEvent;
        if($this->id <= 0 || empty($captured['event']) ||
           (int)$captured['eid'] !== $this->id)
        {
            return $this->resolveNoindex('event');
        }

        $eventData = $captured['event'];
        $calendarData = isset($captured['calendar']) && is_array($captured['calendar'])
            ? $captured['calendar'] : array();
        $text = isset($captured['text']) ? trim((string)$captured['text']) : '';
        $this->data = array(
            'event' => $eventData,
            'calendar' => $calendarData,
            'event_text' => $text
        );
        $this->indexable = true;
        $name = isset($eventData['name']) ? VonSEO_Utils::plainText($eventData['name']) : '';
        $this->title = $this->withBoardName($name);
        $this->description = VonSEO_Utils::truncateDescription($text);
        if($this->description === '')
        {
            $this->description = VonSEO_Utils::truncateDescription(VonSEO_Utils::setting('vonseo_site_description'));
        }
        $this->canonical = $this->url->event($this->id);
        $this->socialImage = $this->defaultImage();

        return $this;
    }

    /**
     * @param int $uid
     * @param array $profile
     * @return $this
     */
    protected function resolveProfile($uid, array $profile)
    {
        global $db;

        $this->type = 'profile';
        $this->id = (int)$uid;
        $this->indexable = (bool)VonSEO_Utils::setting('vonseo_profile_index', 0) && VonSEO_Utils::guestCanViewProfiles();

        if($this->id <= 0 || !$this->indexable)
        {
            return $this->resolveNoindex('profile');
        }

        if(empty($profile))
        {
            $profile = get_user($this->id);
        }

        if(!$profile)
        {
            return $this->resolveNoindex('profile');
        }

        $this->data = $profile;

        $username = isset($profile['username']) ? VonSEO_Utils::plainText($profile['username']) : '';
        $this->title = $this->withBoardName($username);

        $profileText = '';
        if(!empty($profile['usertitle']))
        {
            $profileText = $profile['usertitle'];
        }
        $this->description = VonSEO_Utils::truncateDescription($profileText);
        if($this->description === '')
        {
            $this->description = VonSEO_Utils::truncateDescription(VonSEO_Utils::setting('vonseo_site_description'));
        }

        $this->canonical = $this->url->profile($this->id);

        if(!empty($profile['avatar']))
        {
            $this->socialImage = $this->url->image($profile['avatar']);
        }
        if($this->socialImage === '')
        {
            $this->socialImage = $this->defaultImage();
        }

        return $this;
    }

    /**
     * @param string $type
     * @return $this
     */
    protected function resolveNoindex($type)
    {
        global $mybb;

        $this->type = $type;
        $this->indexable = false;
        $this->title = '';
        $this->description = '';
        $this->canonical = '';
        $this->socialImage = '';

        return $this;
    }

    /**
     * @param array $forumData
     * @return bool
     */
    public function isForumPublic($forumData)
    {
        if(!$forumData || !is_array($forumData) || empty($forumData['fid']) || !VonSEO_Utils::guestCanAccessBoard())
        {
            return false;
        }

        $fid = (int)$forumData['fid'];

        if(isset($forumData['active']) && (int)$forumData['active'] !== 1)
        {
            return false;
        }

        if(VonSEO_Utils::forumHasPasswordBarrier($forumData))
        {
            return false;
        }

        $inactive = function_exists('get_inactive_forums') ? get_inactive_forums() : '';
        if($inactive)
        {
            $inactiveIds = array_filter(array_map('intval', explode(',', $inactive)));
            if(in_array($fid, $inactiveIds))
            {
                return false;
            }
        }

        // Indexability must use the public guest group, not the currently logged-in user.
        $perms = function_exists('forum_permissions') ? forum_permissions($fid, 0, 1) : false;
        if(!$perms || empty($perms['canview']) || empty($perms['canviewthreads']))
        {
            return false;
        }

        if(!empty($perms['canonlyviewownthreads']))
        {
            return false;
        }

        return true;
    }

    /**
     * @param array $announcementData
     * @param array $forumData
     * @return bool
     */
    public function isAnnouncementPublic($announcementData, $forumData = array())
    {
        if(!is_array($announcementData) || empty($announcementData['aid']) ||
           !isset($announcementData['startdate'], $announcementData['enddate']))
        {
            return false;
        }

        $now = defined('TIME_NOW') ? (int)TIME_NOW : time();
        $start = (int)$announcementData['startdate'];
        $end = (int)$announcementData['enddate'];
        if($start > $now || ($end > 0 && $end < $now))
        {
            return false;
        }

        $fid = isset($announcementData['fid']) ? (int)$announcementData['fid'] : 0;
        if($fid <= 0)
        {
            return true;
        }

        return is_array($forumData) && !empty($forumData['fid']) &&
            (int)$forumData['fid'] === $fid && $this->isForumPublic($forumData);
    }

    /**
     * @param array $calendarData
     * @param array|null $permissions
     * @return bool
     */
    public function isCalendarPublic($calendarData, $permissions = null)
    {
        if(!is_array($calendarData) || empty($calendarData['cid']) ||
           !VonSEO_Utils::guestCanViewCalendar((int)$calendarData['cid']))
        {
            return false;
        }

        if(is_array($permissions) && array_key_exists('canviewcalendar', $permissions))
        {
            return is_scalar($permissions['canviewcalendar']) && (int)$permissions['canviewcalendar'] === 1;
        }

        return true;
    }

    /**
     * @param array $eventData
     * @param array $calendarData
     * @param array|null $permissions
     * @return bool
     */
    public function isEventPublic($eventData, $calendarData, $permissions = null)
    {
        if(!is_array($eventData) || empty($eventData['eid']) || empty($eventData['cid']) ||
           !isset($eventData['visible'], $eventData['private']) ||
           (int)$eventData['visible'] !== 1 || (int)$eventData['private'] !== 0 ||
           !is_array($calendarData) || empty($calendarData['cid']) ||
           (int)$calendarData['cid'] !== (int)$eventData['cid'])
        {
            return false;
        }

        return $this->isCalendarPublic($calendarData, $permissions);
    }

    protected function threadSocialImage(array $threadData)
    {
        global $db;

        if(!VonSEO_Utils::setting('vonseo_thread_attachment_image', 1))
        {
            return $this->defaultImage();
        }

        $pid = !empty($threadData['firstpost']) ? (int)$threadData['firstpost'] : 0;
        if($pid <= 0)
        {
            return $this->defaultImage();
        }

        if(!empty($threadData['fid']))
        {
            $perms = forum_permissions((int)$threadData['fid'], 0, 1);
            if(!$perms || empty($perms['candlattachments']))
            {
                return $this->defaultImage();
            }
        }

        $where = "pid='{$pid}' AND visible='1' AND filetype LIKE 'image/%'";
        $query = $db->simple_select('attachments', 'aid', $where, array(
            'order_by' => 'aid',
            'order_dir' => 'ASC',
            'limit' => 1
        ));
        $aid = (int)$db->fetch_field($query, 'aid');

        if($aid > 0)
        {
            $this->seoSources['socialImage'] = 'Public first-post attachment';
            return $this->url->absolute('attachment.php?aid='.$aid);
        }

        return $this->defaultImage();
    }

    protected function defaultImage()
    {
        $raw = trim((string)VonSEO_Utils::setting('vonseo_default_image'));
        $image = $this->url->image($raw);
        if($raw !== '' && $image === '')
        {
            $this->seoWarnings[] = 'The configured Default Social Image URL is invalid and is omitted.';
        }
        return $image;
    }

    protected function boardName()
    {
        global $mybb;
        return isset($mybb->settings['bbname']) ? trim(VonSEO_Utils::plainText($mybb->settings['bbname'])) : '';
    }

    protected function separator()
    {
        $separator = (string)VonSEO_Utils::setting('vonseo_title_separator', ' - ');
        return $separator !== '' ? $separator : ' - ';
    }

    /**
     * @param string $title
     * @return string
     */
    protected function withBoardName($title)
    {
        $title = VonSEO_Utils::plainText($title);
        $board = $this->boardName();

        if($title === '')
        {
            return $board;
        }

        if($board === '' || strcasecmp($title, $board) === 0)
        {
            return $title;
        }

        return $title.$this->separator().$board;
    }
}
