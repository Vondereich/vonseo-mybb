<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

class VonSEO_Redirects
{
    const MAX_CSV_BYTES = 2097152;
    const MAX_CSV_ROWS = 5000;
    /**
     * @var VonSEO_Url
     */
    protected $url;

    /**
     * @var array
     */
    protected $allowedCodes = array(301, 302, 307, 308, 410);

    public function __construct(?VonSEO_Url $url = null)
    {
        $this->url = $url ?: new VonSEO_Url();
    }

    public function handleRequest()
    {
        global $mybb;

        if(defined('IN_ADMINCP') || !VonSEO_Utils::setting('vonseo_redirects_enabled', 1))
        {
            return false;
        }

        if(class_exists('VonSEO_State') && VonSEO_State::activeRedirects() === 0)
        {
            return false;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        $candidates = $this->requestCandidates();
        if(empty($candidates))
        {
            return false;
        }

        $rule = $this->findRule($candidates);
        if(!$rule)
        {
            return false;
        }

        $code = (int)$rule['status_code'];

        // 301/302 are not allowed to silently transform POST into GET.
        if(!in_array($method, array('GET', 'HEAD')) && in_array($code, array(301, 302)))
        {
            return false;
        }

        if($code === 410)
        {
            $this->recordHit((int)$rule['rid']);
            $GLOBALS['vonseo_forced_status'] = 410;
            $GLOBALS['vonseo_error_seen'] = true;
            error('The requested resource has been permanently removed.', 'Gone');
            return true;
        }

        $target = $this->normalizeTarget($rule['target_url']);
        if($target === false)
        {
            return false;
        }

        $current = $this->currentAbsoluteUrl();
        if($this->normalizeForCompare($target) === $this->normalizeForCompare($current))
        {
            // Runtime loop guard. Never redirect a URL to itself.
            return false;
        }

        if(headers_sent())
        {
            return false;
        }

        $this->recordHit((int)$rule['rid']);
        header('Location: '.$target, true, $code);
        header('X-VonSEO-Redirect: '.$code);
        exit;
    }

    public function findRule(array $sources)
    {
        global $db;

        // Frontend requests must not read durable migration metadata. A known
        // positive cached count also avoids a table-existence metadata query.
        $activeRedirects = class_exists('VonSEO_State') ? VonSEO_State::activeRedirects() : null;
        if($activeRedirects === 0 || ($activeRedirects === null && !$db->table_exists('vonseo_redirects')))
        {
            return false;
        }

        foreach($sources as $source)
        {
            $source = $this->normalizeSource($source);
            if($source === false)
            {
                continue;
            }

            $hash = hash('sha256', $source);
            $query = $db->simple_select(
                'vonseo_redirects',
                '*',
                "source_hash='".$db->escape_string($hash)."' AND enabled='1'",
                array('limit' => 1)
            );
            $row = $db->fetch_array($query);
            if($row)
            {
                return $row;
            }
        }

        return false;
    }

    /**
     * @param string $source
     * @param string $target
     * @param int $statusCode
     * @param int $ignoreRid
     * @return array
     */
    public function validateRule($source, $target, $statusCode, $ignoreRid = 0)
    {
        $errors = array();
        $source = $this->normalizeSource($source);
        $code = (int)$statusCode;

        if($source === false || $source === '/')
        {
            $errors[] = 'Source must be a valid board-relative path and cannot be the board root.';
        }

        if(!in_array($code, $this->allowedCodes))
        {
            $errors[] = 'Unsupported redirect status code.';
        }

        if($code === 410)
        {
            $target = '';
        }
        else
        {
            $normalizedTarget = $this->normalizeTarget($target);
            if($normalizedTarget === false)
            {
                $errors[] = 'Destination URL is invalid or not allowed by VonSEO security settings.';
            }
            else
            {
                $target = $normalizedTarget;
            }
        }

        if(empty($errors) && $code !== 410)
        {
            $sourceAbsolute = $this->url->absolute(ltrim($source, '/'));
            if($this->normalizeForCompare($sourceAbsolute) === $this->normalizeForCompare($target))
            {
                $errors[] = 'Redirect loop detected: source and destination resolve to the same URL.';
            }
            elseif($this->wouldLoop($source, $target, $ignoreRid))
            {
                $errors[] = 'Redirect loop detected through an existing redirect chain.';
            }
        }

        return array(
            'errors' => $errors,
            'source' => $source,
            'target' => $target,
            'status_code' => $code
        );
    }

    /**
     * @param string $source
     * @param string $target
     * @param int $statusCode
     * @param int $enabled
     * @param int $rid
     * @return array
     */
    public function saveRule($source, $target, $statusCode, $enabled = 1, $rid = 0)
    {
        global $db;

        $rid = (int)$rid;
        if($rid > 0)
        {
            $existing = $db->simple_select('vonseo_redirects', 'rid', "rid='{$rid}'", array('limit' => 1));
            if((int)$db->fetch_field($existing, 'rid') !== $rid)
            {
                return array(
                    'errors' => array('The redirect rule no longer exists. Reload the list before editing.'),
                    'source' => '', 'target' => '', 'status_code' => (int)$statusCode, 'rid' => 0
                );
            }
        }

        $validated = $this->validateRule($source, $target, $statusCode, $rid);
        if(!empty($validated['errors']))
        {
            return $validated;
        }

        $source = $validated['source'];
        $target = $validated['target'];
        $code = $validated['status_code'];
        $hash = hash('sha256', $source);

        $condition = "source_hash='".$db->escape_string($hash)."'";
        if($rid > 0)
        {
            $condition .= " AND rid!='".(int)$rid."'";
        }

        $query = $db->simple_select('vonseo_redirects', 'rid', $condition, array('limit' => 1));
        if($db->fetch_field($query, 'rid'))
        {
            $validated['errors'][] = 'A redirect rule already exists for this source URL.';
            return $validated;
        }

        $data = array(
            'source_hash' => $db->escape_string($hash),
            'source_path' => $db->escape_string($source),
            'target_url' => $db->escape_string($target),
            'status_code' => $code,
            'enabled' => $enabled ? 1 : 0,
            'updated_at' => TIME_NOW
        );

        if($rid > 0)
        {
            $db->update_query('vonseo_redirects', $data, "rid='".(int)$rid."'");
            $validated['rid'] = (int)$rid;
        }
        else
        {
            $data['hits'] = 0;
            $data['first_hit'] = 0;
            $data['last_hit'] = 0;
            $data['created_at'] = TIME_NOW;
            $validated['rid'] = (int)$db->insert_query('vonseo_redirects', $data);
        }

        if(class_exists('VonSEO_State'))
        {
            VonSEO_State::refreshRedirectCount();
        }

        return $validated;
    }

    /**
     * @param string $source
     * @return string|false
     */
    public function normalizeSource($source)
    {
        $source = trim(html_entity_decode((string)$source, ENT_QUOTES, 'UTF-8'));
        if($source === '' || preg_match('/[\r\n]/', $source))
        {
            return false;
        }

        if(preg_match('#^https?://#i', $source))
        {
            $parts = @parse_url($source);
            $base = @parse_url($this->url->base());
            if(!$this->sameOrigin($parts, $base))
            {
                return false;
            }
            $source = isset($parts['path']) ? $parts['path'] : '/';
            if(!empty($parts['query']))
            {
                $source .= '?'.$parts['query'];
            }
        }
        elseif(strpos($source, '//') === 0 || preg_match('#^[a-z][a-z0-9+.-]*:#i', $source))
        {
            return false;
        }

        $parts = @parse_url($source);
        if($parts === false)
        {
            return false;
        }

        $path = isset($parts['path']) ? $parts['path'] : '/';
        $path = '/'.ltrim(preg_replace('#/{2,}#', '/', $path), '/');
        $path = $this->stripBoardBasePath($path);
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    /**
     * @param string $target
     * @return string|false
     */
    public function normalizeTarget($target)
    {
        $target = trim(html_entity_decode((string)$target, ENT_QUOTES, 'UTF-8'));
        if($target === '' || preg_match('/[\r\n]/', $target) || strpos($target, '//') === 0)
        {
            return false;
        }

        if(preg_match('#^https?://#i', $target))
        {
            $parts = @parse_url($target);
            if(!$parts || empty($parts['host']) || empty($parts['scheme']))
            {
                return false;
            }

            $scheme = strtolower($parts['scheme']);
            if(!in_array($scheme, array('http', 'https')))
            {
                return false;
            }

            if(isset($parts['user']) || isset($parts['pass']))
            {
                return false;
            }

            $base = @parse_url($this->url->base());
            if(!$this->sameOrigin($parts, $base) && !VonSEO_Utils::setting('vonseo_external_redirects', 0))
            {
                return false;
            }

            return $target;
        }

        if(preg_match('#^[a-z][a-z0-9+.-]*:#i', $target))
        {
            return false;
        }

        return $this->url->absolute(ltrim($target, '/'));
    }

    /**
     * @param string $source
     * @param string $target
     * @param int $ignoreRid
     * @return bool
     */
    protected function wouldLoop($source, $target, $ignoreRid = 0)
    {
        $targetSource = $this->targetToSource($target);
        if($targetSource === false)
        {
            return false; // External destination cannot loop through local rules.
        }

        $visited = array($source => true);
        $current = $targetSource;

        for($depth = 0; $depth < 12; $depth++)
        {
            if(isset($visited[$current]))
            {
                return true;
            }
            $visited[$current] = true;

            $row = $this->findRule(array($current));
            if(!$row || (int)$row['rid'] === (int)$ignoreRid || (int)$row['status_code'] === 410)
            {
                return false;
            }

            $next = $this->normalizeTarget($row['target_url']);
            if($next === false)
            {
                return false;
            }

            $current = $this->targetToSource($next);
            if($current === false)
            {
                return false;
            }
        }

        // Very long chains are treated as unsafe even without a proven cycle.
        return true;
    }

    /**
     * @param string $target
     * @return string|false
     */
    protected function targetToSource($target)
    {
        $parts = @parse_url($target);
        $base = @parse_url($this->url->base());
        if(!$this->sameOrigin($parts, $base))
        {
            return false;
        }

        $source = isset($parts['path']) ? $parts['path'] : '/';
        if(!empty($parts['query']))
        {
            $source .= '?'.$parts['query'];
        }
        return $this->normalizeSource($source);
    }

    protected function requestCandidates()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if($uri === '')
        {
            return array();
        }

        $parts = @parse_url($uri);
        if(!$parts)
        {
            return array();
        }

        $path = isset($parts['path']) ? $parts['path'] : '/';
        $path = '/'.ltrim(preg_replace('#/{2,}#', '/', $path), '/');
        $path = $this->stripBoardBasePath($path);

        // Redirect rules are exact by default. A path-only rule must never act
        // as an implicit wildcard for every query string on that script.
        if(!empty($parts['query']))
        {
            return array($path.'?'.$parts['query']);
        }

        return array($path);
    }

    /**
     * Compare URL origins using scheme, hostname and effective port.
     *
     * @param array|false $left
     * @param array|false $right
     * @return bool
     */
    protected function sameOrigin($left, $right)
    {
        if(!is_array($left) || !is_array($right) || empty($left['scheme']) || empty($right['scheme']) ||
           empty($left['host']) || empty($right['host']))
        {
            return false;
        }

        $leftScheme = strtolower($left['scheme']);
        $rightScheme = strtolower($right['scheme']);
        if($leftScheme !== $rightScheme || strcasecmp($left['host'], $right['host']) !== 0)
        {
            return false;
        }

        return $this->effectivePort($left) === $this->effectivePort($right);
    }

    /**
     * @param array $parts
     * @return int
     */
    protected function effectivePort(array $parts)
    {
        if(isset($parts['port']))
        {
            return (int)$parts['port'];
        }

        return strtolower($parts['scheme']) === 'https' ? 443 : 80;
    }

    /**
     * @param string $path
     * @return string
     */
    protected function stripBoardBasePath($path)
    {
        $base = @parse_url($this->url->base());
        $basePath = !empty($base['path']) ? '/'.trim($base['path'], '/') : '';
        if($basePath !== '' && ($path === $basePath || strpos($path, $basePath.'/') === 0))
        {
            $path = substr($path, strlen($basePath));
            if($path === '')
            {
                $path = '/';
            }
        }
        return '/'.ltrim($path, '/');
    }

    protected function currentAbsoluteUrl()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        return $this->url->absolute(ltrim($this->stripBoardBasePath(parse_url($uri, PHP_URL_PATH) ?: '/'), '/'))
            .((parse_url($uri, PHP_URL_QUERY)) ? '?'.parse_url($uri, PHP_URL_QUERY) : '');
    }

    /**
     * @param string $url
     * @return string
     */
    protected function normalizeForCompare($url)
    {
        $parts = @parse_url($url);
        if(!$parts)
        {
            return rtrim(strtolower($url), '/');
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $effectivePort = $this->effectivePort($parts);
        $port = ($scheme === 'https' && $effectivePort === 443) || ($scheme === 'http' && $effectivePort === 80)
            ? '' : ':'.$effectivePort;
        $path = isset($parts['path']) ? preg_replace('#/{2,}#', '/', $parts['path']) : '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$port.rtrim($path, '/').$query;
    }

    /**
     * @param int $rid
     * @return void
     */
    protected function recordHit($rid)
    {
        global $db;

        $rid = (int)$rid;
        if($rid <= 0)
        {
            return;
        }

        $timeNow = defined('TIME_NOW') ? TIME_NOW : time();
        $db->write_query(
            "UPDATE ".TABLE_PREFIX."vonseo_redirects SET hits=hits+1, "
            ."first_hit=IF(first_hit=0,".(int)$timeNow.",first_hit), last_hit=".(int)$timeNow
            ." WHERE rid='{$rid}'"
        );
    }

    /**
     * Export all redirect rules as CSV string.
     *
     * @return string
     */
    public function exportCsv()
    {
        global $db;

        if(!$db->table_exists('vonseo_redirects'))
        {
            return "source_path,target_url,status_code,enabled\n";
        }

        $out = fopen('php://temp', 'r+');
        fputcsv($out, array('source_path', 'target_url', 'status_code', 'enabled'), ',', '"', '');

        $query = $db->simple_select('vonseo_redirects', 'source_path,target_url,status_code,enabled', '', array('order_by' => 'rid', 'order_dir' => 'ASC'));
        while($row = $db->fetch_array($query))
        {
            fputcsv($out, array(
                $row['source_path'],
                $row['target_url'],
                $row['status_code'],
                $row['enabled']
            ), ',', '"', '');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv !== false ? $csv : '';
    }

    /**
     * Import redirect rules from CSV text.
     *
     * @param string $csvContent
     * @param bool $overwrite
     * @return array
     */
    public function importCsv($csvContent, $overwrite = false)
    {
        $csvContent = (string)$csvContent;
        if(strlen($csvContent) > self::MAX_CSV_BYTES)
        {
            return $this->emptyImportResult('CSV exceeds the 2 MiB import limit.');
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csvContent);
        rewind($stream);
        $result = $this->importCsvStream($stream, $overwrite);
        fclose($stream);
        return $result;
    }

    /**
     * @param string $path
     * @param bool $overwrite
     * @return array
     */
    public function importCsvFile($path, $overwrite = false)
    {
        $source = @fopen($path, 'rb');
        if(!$source)
        {
            return $this->emptyImportResult('CSV file could not be read.');
        }
        $stream = fopen('php://temp', 'r+');
        $bytes = stream_copy_to_stream($source, $stream, self::MAX_CSV_BYTES + 1);
        fclose($source);
        if($bytes === false || $bytes > self::MAX_CSV_BYTES)
        {
            fclose($stream);
            return $this->emptyImportResult('CSV exceeds the 2 MiB import limit.');
        }
        rewind($stream);
        $result = $this->importCsvStream($stream, $overwrite);
        fclose($stream);
        return $result;
    }

    /**
     * @param resource $stream
     * @param bool $overwrite
     * @return array
     */
    protected function importCsvStream($stream, $overwrite)
    {
        global $db;

        $rows = array();
        $rowNum = 0;
        while(($cols = fgetcsv($stream, 0, ',', '"', '')) !== false)
        {
            ++$rowNum;
            if($cols === array(null) || (count($cols) === 1 && trim((string)$cols[0]) === ''))
            {
                continue;
            }
            $first = strtolower(trim((string)$cols[0]));
            if($first === 'source_path' || $first === 'source')
            {
                continue;
            }
            if(count($rows) >= self::MAX_CSV_ROWS)
            {
                return $this->emptyImportResult('CSV exceeds the 5,000-row import limit.');
            }
            $rows[] = array('number' => $rowNum, 'columns' => $cols);
        }

        if(empty($rows))
        {
            return $this->emptyImportResult('CSV content is empty.');
        }

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = array();
        foreach($rows as $csvRow)
        {
            $rowNum = $csvRow['number'];
            $cols = $csvRow['columns'];
            if(count($cols) < 2)
            {
                ++$skipped;
                continue;
            }
            $source = trim((string)$cols[0]);
            $target = trim((string)$cols[1]);
            $statusCode = isset($cols[2]) && (int)$cols[2] > 0 ? (int)$cols[2] : 301;
            $enabled = isset($cols[3]) ? (int)$cols[3] : 1;
            $normalizedSource = $this->normalizeSource($source);
            if($normalizedSource === false)
            {
                $errors[] = "Row {$rowNum}: invalid source URL '{$source}'";
                ++$skipped;
                continue;
            }

            $hash = hash('sha256', $normalizedSource);
            $existingQuery = $db->simple_select('vonseo_redirects', 'rid', "source_hash='".$db->escape_string($hash)."'", array('limit' => 1));
            $existingRid = (int)$db->fetch_field($existingQuery, 'rid');
            if($existingRid > 0 && !$overwrite)
            {
                ++$skipped;
                continue;
            }

            $save = $this->saveRule($source, $target, $statusCode, $enabled, $existingRid);
            if(!empty($save['errors']))
            {
                $errors[] = "Row {$rowNum} ('{$source}'): ".implode(', ', $save['errors']);
                ++$skipped;
            }
            elseif($existingRid > 0)
            {
                ++$updated;
            }
            else
            {
                ++$imported;
            }
        }

        return array('imported' => $imported, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors);
    }

    /**
     * @param string $error
     * @return array
     */
    protected function emptyImportResult($error)
    {
        return array('imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array($error));
    }
}
