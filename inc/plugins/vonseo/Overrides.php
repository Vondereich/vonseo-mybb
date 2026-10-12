<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

/** Entity metadata and indexing policy. Guest visibility remains in Context. */
class VonSEO_Overrides
{
    /** @var bool|null */
    protected static $ready = null;
    /** @var array */
    protected static $rows = array();

    /** @return void */
    public static function resetRuntime()
    {
        self::$ready = null;
        self::$rows = array();
    }

    /** @return bool */
    public static function ready()
    {
        global $db, $cache;
        if(self::$ready !== null)
        {
            return self::$ready;
        }
        $flag = is_object($cache) ? $cache->read('vonseo_override_schema') : false;
        if(is_array($flag) && !empty($flag['ready']))
        {
            return self::$ready = true;
        }
        // A cold/missing cache must not silently disable durable noindex rules.
        self::$ready = is_object($db) && method_exists($db, 'table_exists') && $db->table_exists('vonseo_overrides');
        if(self::$ready && is_object($cache) && method_exists($cache, 'update'))
        {
            $cache->update('vonseo_override_schema', array('ready' => true));
        }
        return self::$ready;
    }

    /** @param string $type @param int|string $id @return array */
    public static function get($type, $id)
    {
        global $db;
        $id = (int)$id;
        if(!in_array($type, array('forum', 'thread'), true) || $id <= 0 || !self::ready())
        {
            return array();
        }
        $key = $type.':'.$id;
        if(!array_key_exists($key, self::$rows))
        {
            $query = $db->simple_select('vonseo_overrides', '*', "entity_type='{$type}' AND entity_id='{$id}'", array('limit' => 1));
            $row = $db->fetch_array($query);
            self::$rows[$key] = is_array($row) ? $row : array();
        }
        return self::$rows[$key];
    }

    /** @param int|string $tid @param int|string $fid @return bool */
    public static function noindexThread($tid, $fid)
    {
        return self::noindexForum($fid) || self::isNoindex(self::get('thread', $tid));
    }

    /** @param int|string $fid @return bool */
    public static function noindexForum($fid)
    {
        return self::isNoindex(self::get('forum', $fid));
    }

    /** @return bool */
    protected static function isNoindex(array $row)
    {
        return isset($row['index_mode']) && $row['index_mode'] === 'noindex';
    }

    /** @return void */
    public static function apply(VonSEO_Context $context, ?array $draft = null)
    {
        $context->publicContent = $context->indexable;
        if(!$context->publicContent || !in_array($context->type, array('forum', 'thread'), true))
        {
            return;
        }
        $row = $draft !== null ? $draft : self::get($context->type, $context->id);
        $fid = $context->type === 'forum' ? $context->id : (int)$context->data['thread']['fid'];
        if(self::isNoindex($row) || ($context->type === 'thread' && self::noindexForum($fid)))
        {
            $context->indexable = false;
            $context->seoSources['indexable'] = self::isNoindex($row) ? 'Entity noindex override' : 'Containing forum noindex';
        }
        if(isset($row['seo_title']) && $row['seo_title'] !== '')
        {
            $context->title = $context->formatSeoTitle($row['seo_title']);
            $context->seoSources['title'] = 'Entity override';
        }
        if(isset($row['seo_description']) && $row['seo_description'] !== '')
        {
            $context->description = VonSEO_Utils::truncateDescription($row['seo_description']);
            $context->seoSources['description'] = 'Entity override';
        }
        if(isset($row['seo_image']) && $row['seo_image'] !== '')
        {
            $image = (new VonSEO_Url())->image($row['seo_image']);
            if($image !== '')
            {
                $context->socialImage = $image;
                $context->seoSources['socialImage'] = 'Entity override';
            }
            else
            {
                $context->seoWarnings[] = 'The saved image override is invalid; the automatic image fallback remains in use.';
            }
        }
    }

    /** @return array */
    public static function validate(array $input)
    {
        $values = array();
        $errors = array();
        foreach(array('seo_title' => 255, 'seo_description' => 320, 'seo_image' => 2048) as $field => $limit)
        {
            $raw = isset($input[$field]) ? $input[$field] : '';
            if(!is_string($raw) || strlen($raw) > 16384 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw) || !preg_match('//u', $raw))
            {
                $errors[] = $field.' must contain valid UTF-8 text without binary NUL bytes.';
                continue;
            }
            $value = $field === 'seo_image' ? trim($raw) : trim(VonSEO_Utils::plainText($raw));
            if(VonSEO_Utils::strlen($value) > $limit)
            {
                $errors[] = $field.' exceeds the '.$limit.' character storage limit.';
            }
            $values[$field] = $value;
        }
        $mode = isset($input['index_mode']) ? $input['index_mode'] : 'inherit';
        if(!is_string($mode) || !in_array($mode, array('inherit', 'noindex'), true))
        {
            $errors[] = 'Indexing policy must be inherit or noindex.';
        }
        $values['index_mode'] = is_string($mode) ? $mode : 'inherit';
        if(!empty($values['seo_image']) && (new VonSEO_Url())->image($values['seo_image']) === '')
        {
            $errors[] = 'Social image must be a safe HTTP(S) or board-relative URL without credentials or controls.';
        }
        return array('values' => $values, 'errors' => $errors);
    }

    /** @param string $type @param int $id @param int $uid @return array */
    public static function save($type, $id, array $input, $uid)
    {
        global $db, $cache;
        $validated = self::validate($input);
        if(!in_array($type, array('forum', 'thread'), true) || $id <= 0 || $id > 2147483647 || !self::ready() || !self::entityEligible($type, $id))
        {
            $validated['errors'][] = 'A valid entity and the current override storage are required.';
        }
        if($validated['errors'])
        {
            return $validated;
        }
        $old = self::get($type, $id);
        $values = $validated['values'];
        $indexNow = new VonSEO_IndexNow();
        $captured = $type === 'thread' ? $indexNow->capturePublicThreadUrl($id) : false;
        $policyChanged = (isset($old['index_mode']) ? $old['index_mode'] : 'inherit') !== $values['index_mode'];
        $row = $values + array('entity_type' => $type, 'entity_id' => (int)$id,
            'updated_by' => max(0, (int)$uid), 'updated_at' => defined('TIME_NOW') ? TIME_NOW : time(),
            'pending_mode' => isset($old['pending_mode']) ? $old['pending_mode'] : '',
            'cursor_tid' => isset($old['cursor_tid']) ? (int)$old['cursor_tid'] : 0,
            'job_generation' => VonSEO_Utils::randomHex());
        if($type === 'forum' && $policyChanged)
        {
            $row['pending_mode'] = $values['index_mode'] === 'noindex' ? 'removed' : 'current';
            $row['cursor_tid'] = 0;
        }
        foreach($row as $field => $value)
        {
            if(is_string($value))
            {
                $row[$field] = $db->escape_string($value);
            }
        }
        $revision = VonSEO_Utils::randomHex();
        $db->replace_query('vonseo_overrides', $row);
        self::resetRuntime();
        if(is_object($cache) && method_exists($cache, 'update'))
        {
            $cache->update('vonseo_override_revision', array('value' => $revision));
        }
        if($type === 'thread' && VonSEO_Utils::setting('vonseo_indexnow_enabled', 0) && VonSEO_Utils::setting('vonseo_enabled', 0))
        {
            $thread = function_exists('get_thread') ? get_thread($id) : false;
            if(is_array($thread) && self::noindexThread($id, (int)$thread['fid']))
            {
                $indexNow->queueRemoval($id, $captured !== false ? $captured : '');
            }
            else
            {
                $indexNow->queueThread($id, 'seo_override');
            }
        }
        return $validated;
    }

    /** Only existing ordinary, guest-visible entities may be edited. @return bool */
    public static function entityEligible(string $type, int $id)
    {
        if($id <= 0 || !in_array($type, array('forum', 'thread'), true) || !function_exists('get_forum'))
        {
            return false;
        }
        $thread = $type === 'thread' && function_exists('get_thread') ? get_thread($id) : array();
        if($type === 'thread' && (!is_array($thread) || empty($thread['tid']) || (int)$thread['tid'] !== $id ||
           !isset($thread['visible']) || (int)$thread['visible'] !== 1 ||
           (isset($thread['closed']) && strpos((string)$thread['closed'], 'moved|') === 0)))
        {
            return false;
        }
        $fid = $type === 'forum' ? $id : (int)$thread['fid'];
        $forum = get_forum($fid);
        return is_array($forum) && isset($forum['type']) && $forum['type'] === 'f' && empty($forum['linkto']) &&
            (new VonSEO_Context(new VonSEO_Url()))->isForumPublic($forum);
    }

    /** @return string */
    public static function revision()
    {
        global $cache;
        $value = is_object($cache) ? $cache->read('vonseo_override_revision') : false;
        return is_array($value) && isset($value['value']) ? (string)$value['value'] : '';
    }

    /** Controlled table identifiers only; excludes forum policy and thread policy. @return string */
    public static function threadSitemapPredicate()
    {
        if(!self::ready())
        {
            return '';
        }
        $table = TABLE_PREFIX.'threads';
        $overrides = TABLE_PREFIX.'vonseo_overrides';
        return " AND NOT EXISTS (SELECT 1 FROM {$overrides} vso WHERE vso.entity_type='thread' AND vso.entity_id={$table}.tid AND vso.index_mode='noindex')"
            ." AND NOT EXISTS (SELECT 1 FROM {$overrides} vsf WHERE vsf.entity_type='forum' AND vsf.entity_id={$table}.fid AND vsf.index_mode='noindex')";
    }

    /** One bounded query per sitemap, not one query per forum. @return array */
    public static function indexedForumIds(array $ids)
    {
        global $db;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function($id) { return $id > 0; })));
        if(!$ids || !self::ready())
        {
            return $ids;
        }
        $query = $db->simple_select('vonseo_overrides', 'entity_id', "entity_type='forum' AND index_mode='noindex' AND entity_id IN (".implode(',', $ids).')', array('limit' => count($ids)));
        $excluded = array();
        while($row = $db->fetch_array($query))
        {
            $excluded[(int)$row['entity_id']] = true;
        }
        return array_values(array_filter($ids, function($id) use ($excluded) { return !isset($excluded[$id]); }));
    }

    /** Reconcile one forum policy job per worker invocation, at most 25 threads. @return int */
    public static function reconcile(VonSEO_IndexNow $indexNow)
    {
        global $db;
        if(!self::ready())
        {
            return 0;
        }
        $query = $db->simple_select('vonseo_overrides', 'entity_id,pending_mode,cursor_tid,job_generation', "entity_type='forum' AND pending_mode IN ('current','removed')", array('order_by' => 'entity_id', 'limit' => 1));
        $job = $db->fetch_array($query);
        if(!$job || !is_array($job))
        {
            return 0;
        }
        $fid = (int)$job['entity_id'];
        $cursor = (int)$job['cursor_tid'];
        $rows = $db->simple_select('threads', 'tid', "fid='{$fid}' AND tid>'{$cursor}'", array('order_by' => 'tid', 'order_dir' => 'ASC', 'limit' => 25));
        $count = 0;
        $paused = false;
        while($thread = $db->fetch_array($rows))
        {
            $tid = (int)$thread['tid'];
            if($job['pending_mode'] === 'removed')
            {
                // History is the only removal source, even for now-hidden threads.
                if($indexNow->hasPublicHistory($tid) && !$indexNow->queueRemoval($tid))
                {
                    $paused = true;
                    break;
                }
            }
            else
            {
                if($indexNow->capturePublicThreadUrl($tid) !== false && !$indexNow->queueThread($tid, 'seo_override'))
                {
                    $paused = true;
                    break;
                }
            }
            $cursor = $tid;
            ++$count;
        }
        $data = array('cursor_tid' => $cursor);
        if($count < 25 && !$paused)
        {
            $data['pending_mode'] = '';
        }
        $db->update_query('vonseo_overrides', $data, "entity_type='forum' AND entity_id='{$fid}' AND job_generation='".$db->escape_string($job['job_generation'])."'");
        return $count;
    }
}
