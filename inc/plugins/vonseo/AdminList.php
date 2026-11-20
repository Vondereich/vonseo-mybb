<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

/** Read-only ACP list queries. This class is loaded only by the admin module. */
class VonSEO_AdminList
{
    /** @var string */
    public $type;
    /** @var string */
    public $search;
    /** @var string */
    public $status;
    /** @var string */
    public $enabled;
    /** @var string IndexNow queue state: due, retry or scheduled. */
    public $state = '';
    /** @var string IndexNow queue event: current or removed. */
    public $event = '';
    /** @var string */
    public $sort;
    /** @var string */
    public $direction;
    /** @var int */
    public $page;
    /** @var int */
    public $pages = 1;
    /** @var int */
    public $total = 0;
    /** @var int */
    public $perPage = 50;

    /**
     * @param string $type
     * @param array $input
     */
    public function __construct($type, array $input)
    {
        if(!in_array($type, array('redirects', 'notfound', 'indexnow'), true))
        {
            throw new InvalidArgumentException('Unknown VonSEO list.');
        }
        $this->type = $type;
        $value = static function(string $key, string $fallback = '') use ($input): string
        {
            return isset($input[$key]) && is_scalar($input[$key]) ? (string)$input[$key] : $fallback;
        };
        $this->search = VonSEO_Utils::substr(trim($value('search')), 0, 200);
        $this->status = $type === 'redirects' && in_array($value('status'), array('301', '302', '307', '308', '410'), true) ? $value('status') : '';
        $this->enabled = $type === 'redirects' && in_array($value('enabled'), array('0', '1'), true) ? $value('enabled') : '';
        if($type === 'indexnow')
        {
            $this->state = in_array($value('state'), array('due', 'retry', 'scheduled'), true) ? $value('state') : '';
            $this->event = in_array($value('event'), array('current', 'removed'), true) ? $value('event') : '';
        }
        $defaults = array('redirects' => 'newest', 'notfound' => 'hits', 'indexnow' => 'next');
        $default = $defaults[$type];
        $this->sort = array_key_exists($value('sort'), $this->sorts()) ? $value('sort') : $default;
        $this->direction = in_array($value('direction'), array('asc', 'desc'), true)
            ? $value('direction') : (in_array($this->sort, array('path', 'next'), true) ? 'asc' : 'desc');
        $this->page = max(1, (int)$value('page', '1'));
    }

    /** @return array */
    public function sorts()
    {
        if($this->type === 'indexnow')
        {
            return array('next' => 'Next attempt', 'attempts' => 'Attempts', 'updated' => 'Last change', 'path' => 'URL');
        }
        return $this->type === 'redirects'
            ? array('newest' => 'Created', 'path' => 'Source URL', 'hits' => 'Hits', 'last_hit' => 'Last hit')
            : array('hits' => 'Hits', 'path' => 'Missing URL', 'first_seen' => 'First seen', 'last_seen' => 'Last seen');
    }

    /** @return int */
    protected function now()
    {
        return defined('TIME_NOW') ? (int)TIME_NOW : time();
    }

    /** @return string */
    public function conditions()
    {
        global $db;
        $parts = array();
        if($this->search !== '')
        {
            // Treat LIKE metacharacters literally, independently of SQL backslash mode.
            $literal = str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), $this->search);
            $pattern = $db->escape_string('%'.$literal.'%');
            $columns = array('redirects' => '', 'notfound' => 'path', 'indexnow' => 'url');
            $parts[] = $this->type === 'redirects'
                ? "(source_path LIKE '{$pattern}' ESCAPE '!' OR target_url LIKE '{$pattern}' ESCAPE '!')"
                : $columns[$this->type]." LIKE '{$pattern}' ESCAPE '!'";
        }
        if($this->type === 'indexnow')
        {
            // The worker selects rows with both timestamps due; mirror that split.
            $now = $this->now();
            if($this->state === 'due')
            {
                $parts[] = "available_at<={$now} AND retry_not_before<={$now}";
            }
            elseif($this->state === 'retry')
            {
                $parts[] = "retry_not_before>{$now}";
            }
            elseif($this->state === 'scheduled')
            {
                $parts[] = "available_at>{$now} AND retry_not_before<={$now}";
            }
            if($this->event !== '')
            {
                $parts[] = "event_type='".($this->event === 'removed' ? 'removed' : 'current')."'";
            }
        }
        if($this->type === 'redirects')
        {
            if($this->status !== '')
            {
                $parts[] = 'status_code='.(int)$this->status;
            }
            if($this->enabled !== '')
            {
                $parts[] = 'enabled='.(int)$this->enabled;
            }
        }
        return implode(' AND ', $parts);
    }

    /** @return array */
    public function rows()
    {
        global $db;
        $tables = array('redirects' => 'vonseo_redirects', 'notfound' => 'vonseo_404_log', 'indexnow' => 'vonseo_indexnow_queue');
        $table = $tables[$this->type];
        if(!$db->table_exists($table))
        {
            $this->page = 1;
            return array();
        }
        $where = $this->conditions();
        $count = $db->simple_select($table, 'COUNT(*) AS total', $where);
        $this->total = max(0, (int)$db->fetch_field($count, 'total'));
        $this->pages = max(1, (int)ceil($this->total / $this->perPage));
        $this->page = min($this->page, $this->pages);
        if($this->total === 0)
        {
            return array();
        }
        $columnSets = array(
            'redirects' => array('newest' => 'rid', 'path' => 'source_path', 'hits' => 'hits', 'last_hit' => 'last_hit'),
            'notfound' => array('hits' => 'hits', 'path' => 'path', 'first_seen' => 'first_seen', 'last_seen' => 'last_seen'),
            'indexnow' => array('next' => 'GREATEST(available_at,retry_not_before)', 'attempts' => 'attempts',
                'updated' => 'updated_at', 'path' => 'url')
        );
        $columns = $columnSets[$this->type];
        $ties = array('redirects' => 'rid', 'notfound' => 'path_hash', 'indexnow' => 'qid');
        $tie = $ties[$this->type];
        $dir = strtoupper($this->direction);
        $query = $db->simple_select($table, '*', $where, array(
            'order_by' => $columns[$this->sort].' '.$dir.', '.$tie,
            'order_dir' => $dir,
            'limit_start' => ($this->page - 1) * $this->perPage,
            'limit' => $this->perPage
        ));
        $rows = array();
        while($row = $db->fetch_array($query))
        {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array $changes
     * @return string
     */
    public function url(array $changes = array())
    {
        $params = array('module' => 'config-vonseo', 'action' => $this->type, 'search' => $this->search,
            'sort' => $this->sort, 'direction' => $this->direction, 'page' => $this->page);
        if($this->type === 'redirects')
        {
            $params['status'] = $this->status;
            $params['enabled'] = $this->enabled;
        }
        elseif($this->type === 'indexnow')
        {
            $params['state'] = $this->state;
            $params['event'] = $this->event;
        }
        return 'index.php?'.http_build_query(array_merge($params, $changes), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param string $label
     * @param string $sort
     * @return string
     */
    public function heading($label, $sort)
    {
        $active = $this->sort === $sort;
        $direction = $active && $this->direction === 'desc' ? 'asc' : 'desc';
        return '<a href="'.htmlspecialchars_uni($this->url(array('sort' => $sort, 'direction' => $direction, 'page' => 1))).'">'
            .htmlspecialchars_uni($label).($active ? ' <span class="vs-subtle">('.$this->direction.')</span>' : '').'</a>';
    }

    /** @return string */
    public function filters()
    {
        $html = '<form class="vs-list-filters" action="index.php" method="get">'
            .'<input type="hidden" name="module" value="config-vonseo" />'
            .'<input type="hidden" name="action" value="'.$this->type.'" />'
            .'<label for="vs-search">Search URL<input class="text_input" type="search" id="vs-search" name="search" maxlength="200" value="'
            .htmlspecialchars_uni($this->search).'" /></label>';
        if($this->type === 'redirects')
        {
            $html .= $this->select('status', 'Response', array('' => 'All responses', '301' => '301', '302' => '302', '307' => '307', '308' => '308', '410' => '410'), $this->status);
            $html .= $this->select('enabled', 'State', array('' => 'All rules', '1' => 'Enabled', '0' => 'Disabled'), $this->enabled);
        }
        elseif($this->type === 'indexnow')
        {
            $html .= $this->select('state', 'State', array('' => 'All queued URLs', 'due' => 'Due now',
                'retry' => 'Waiting to retry', 'scheduled' => 'Scheduled'), $this->state);
            $html .= $this->select('event', 'Notification', array('' => 'All notifications', 'current' => 'Updated content',
                'removed' => 'Removed content'), $this->event);
        }
        $html .= $this->select('sort', 'Sort by', $this->sorts(), $this->sort)
            .$this->select('direction', 'Order', array('desc' => 'Descending', 'asc' => 'Ascending'), $this->direction)
            .'<button class="button" type="submit">Apply</button>'
            .'<a class="button" href="index.php?module=config-vonseo&amp;action='.$this->type.'">Reset</a></form>';
        return $html;
    }

    /** @return string */
    public function pagination()
    {
        $first = $this->total === 0 ? 0 : ($this->page - 1) * $this->perPage + 1;
        $last = min($this->total, $this->page * $this->perPage);
        $html = '<div class="vs-list-footer"><span>Showing '.my_number_format($first).'-'.my_number_format($last)
            .' of '.my_number_format($this->total).' results</span>';
        if($this->pages > 1)
        {
            $html .= '<nav class="vs-actions" aria-label="List pages">';
            $targets = array(1, max(1, $this->page - 1), min($this->pages, $this->page + 1), $this->pages);
            $labels = array('First', 'Previous', 'Next', 'Last');
            foreach($targets as $i => $target)
            {
                if(($i < 2 && $this->page === 1) || ($i >= 2 && $this->page === $this->pages))
                {
                    continue;
                }
                if($i === 2)
                {
                    $html .= '<span>Page '.$this->page.' of '.$this->pages.'</span>';
                }
                $html .= '<a class="button" href="'.htmlspecialchars_uni($this->url(array('page' => $target))).'">'.$labels[$i].'</a>';
            }
            if($this->page === $this->pages)
            {
                $html .= '<span>Page '.$this->page.' of '.$this->pages.'</span>';
            }
            $html .= '</nav>';
        }
        return $html.'</div>';
    }

    /**
     * @param string $name
     * @param string $label
     * @param array $options
     * @param string $selected
     * @return string
     */
    protected function select($name, $label, array $options, $selected)
    {
        $html = '<label for="vs-'.$name.'">'.$label.'<select id="vs-'.$name.'" name="'.$name.'">';
        foreach($options as $value => $text)
        {
            $html .= '<option value="'.htmlspecialchars_uni((string)$value).'"'.((string)$value === $selected ? ' selected="selected"' : '').'>'
                .htmlspecialchars_uni($text).'</option>';
        }
        return $html.'</select></label>';
    }
}
