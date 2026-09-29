<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

class VonSEO_IndexNow
{
    const MAX_ATTEMPTS = 10;
    const THREAD_COOLDOWN = 300;
    const CONFIG_RETRY_DELAY = 300;
    const RATE_LIMIT_RETRY_DELAY = 900;

    /** @var VonSEO_Url */
    protected $url;

    /** @param VonSEO_Url|null $url */
    public function __construct(?VonSEO_Url $url = null)
    {
        $this->url = $url ?: new VonSEO_Url();
    }

    /** @return string */
    public function getKey()
    {
        return trim((string)VonSEO_Utils::setting('vonseo_indexnow_key', ''));
    }

    /** @return string */
    public function generateKey()
    {
        return VonSEO_Utils::randomHex(16);
    }

    /** @return string */
    public function getKeyLocation()
    {
        return $this->url->misc('vonseo_indexnow_key');
    }

    /** @return void */
    public function outputKey()
    {
        $key = $this->getKey();
        if($key === '')
        {
            if(!headers_sent())
            {
                http_response_code(404);
                header('X-Robots-Tag: noindex, nofollow', true);
            }
            return;
        }

        if(!headers_sent())
        {
            header('Content-Type: text/plain; charset=UTF-8');
            header('X-Robots-Tag: noindex, nofollow', true);
        }
        echo $key;
    }

    /**
     * Submit a public thread immediately. Intended for explicit/manual use.
     *
     * @param int|string $tid
     * @return bool
     */
    public function submitThread($tid)
    {
        $threadUrl = $this->publicThreadUrl($tid);
        return $threadUrl !== false && $this->submitUrls(array($threadUrl));
    }

    /**
     * Queue a public thread. New/approved/restored threads are due immediately;
     * reply and edit events respect a five-minute per-thread delivery cooldown.
     *
     * @param int|string $tid
     * @param string $reason
     * @return bool
     */
    public function queueThread($tid, $reason = 'update')
    {
        $tid = (int)$tid;
        $threadUrl = $this->publicThreadUrl($tid);
        if($threadUrl === false)
        {
            return false;
        }

        $this->rememberPublicUrl($tid, $threadUrl);
        $now = $this->now();
        $immediate = in_array($reason, array('new_thread', 'approved_thread', 'restored_thread'), true);
        $history = $this->threadHistory($tid);
        $lastSubmitted = !empty($history['last_submitted_at']) ? (int)$history['last_submitted_at'] : 0;
        $availableAt = (!$immediate && $lastSubmitted > 0)
            ? max($now, $lastSubmitted + self::THREAD_COOLDOWN)
            : $now;

        return $this->enqueueThread($tid, $threadUrl, 'current', $availableAt);
    }

    /**
     * Queue a post change only when that post is currently public.
     *
     * @param int|string $pid
     * @param string $reason
     * @return bool
     */
    public function queuePost($pid, $reason = 'reply')
    {
        global $db;

        $pid = (int)$pid;
        if($pid <= 0)
        {
            return false;
        }
        $query = $db->simple_select('posts', 'pid,tid,visible', "pid='{$pid}'", array('limit' => 1));
        $post = $db->fetch_array($query);
        if(!$post || (int)$post['visible'] !== 1)
        {
            return false;
        }
        return $this->queueThread((int)$post['tid'], $reason);
    }

    /**
     * Queue the public parent after moderation removed visible content.
     *
     * @param int|string $pid
     * @param string $reason
     * @return bool
     */
    public function queuePostParent($pid, $reason = 'moderation')
    {
        global $db;

        $pid = (int)$pid;
        if($pid <= 0)
        {
            return false;
        }
        $query = $db->simple_select('posts', 'pid,tid', "pid='{$pid}'", array('limit' => 1));
        $post = $db->fetch_array($query);
        return $post && !empty($post['tid'])
            ? $this->queueThread((int)$post['tid'], $reason)
            : false;
    }

    /**
     * Return a URL only when the thread is public at capture time.
     *
     * @param int|string $tid
     * @return string|false
     */
    public function capturePublicThreadUrl($tid)
    {
        return $this->publicThreadUrl($tid);
    }

    /**
     * Queue an old public URL so participating engines recrawl its 404/redirect.
     * The URL must be a captured public URL or a remembered public URL. Never
     * reconstruct a removal URL from a thread that is already hidden.
     *
     * @param int|string $tid
     * @param string $capturedUrl
     * @param bool $allowTransitionLookup Deprecated compatibility argument;
     *     transition lookups are intentionally ignored.
     * @return bool
     */
    public function queueRemoval($tid, $capturedUrl = '', $allowTransitionLookup = false)
    {
        $tid = (int)$tid;
        $url = trim((string)$capturedUrl);
        if($tid <= 0)
        {
            return false;
        }
        if($url === '')
        {
            $history = $this->threadHistory($tid);
            $url = !empty($history['last_public_url']) ? (string)$history['last_public_url'] : '';
        }
        if($url === '' || !$this->isBoardUrl($url))
        {
            return false;
        }
        return $this->enqueueThread($tid, $url, 'removed', $this->now());
    }

    /** URL-only rows cannot be safely revalidated. */
    public function enqueueUrls(array $urls)
    {
        return false;
    }

    /**
     * Queue one entity generation without a synchronous COUNT.
     *
     * @param int|string $tid
     * @param string $url
     * @param string $eventType
     * @param int|null $availableAt
     * @return bool
     */
    protected function enqueueThread($tid, $url, $eventType = 'current', $availableAt = null)
    {
        global $db;

        $tid = (int)$tid;
        $url = trim((string)$url);
        $eventType = $eventType === 'removed' ? 'removed' : 'current';
        if($tid <= 0 || !$db->table_exists('vonseo_indexnow_queue') || !$this->isBoardUrl($url))
        {
            return false;
        }

        $now = $this->now();
        $availableAt = $availableAt === null ? $now : max($now, (int)$availableAt);
        $hash = hash('sha256', $url);
        try
        {
            $generation = VonSEO_Utils::randomHex(16);
        }
        catch(Exception $e)
        {
            return false;
        }

        $db->write_query(
            "INSERT INTO ".TABLE_PREFIX."vonseo_indexnow_queue "
            ."(tid,generation,event_type,url_hash,url,attempts,available_at,retry_not_before,created_at,updated_at,last_error) VALUES ("
            ."{$tid},'".$db->escape_string($generation)."','".$db->escape_string($eventType)."','".$db->escape_string($hash)."','".$db->escape_string($url)."',0,{$availableAt},0,{$now},{$now},'') "
            ."ON DUPLICATE KEY UPDATE generation=VALUES(generation),event_type=VALUES(event_type),url_hash=VALUES(url_hash),url=VALUES(url),"
            ."available_at=LEAST(available_at,VALUES(available_at)),updated_at=VALUES(updated_at)"
        );
        return true;
    }

    /**
     * Submit one due batch from the MyBB scheduled task.
     *
     * @param int|string $limit
     * @return array
     */
    public function processQueue($limit = 25)
    {
        global $db;

        $result = array(
            'processed' => 0, 'success' => true, 'remaining' => 0,
            'dropped' => 0, 'status' => 'ready', 'http_status' => 0
        );
        if(!$db->table_exists('vonseo_indexnow_queue'))
        {
            return $result;
        }

        $limit = max(1, min(100, (int)$limit));
        $now = $this->now();
        $query = $db->simple_select(
            'vonseo_indexnow_queue',
            'qid,tid,generation,event_type,url,attempts,retry_not_before',
            "available_at<='{$now}' AND retry_not_before<='{$now}'",
            array('order_by' => 'available_at,qid', 'order_dir' => 'ASC', 'limit' => $limit)
        );

        $rows = array();
        $urls = array();
        while($row = $db->fetch_array($query))
        {
            $condition = "qid='".(int)$row['qid']."' AND generation='".$db->escape_string($row['generation'])."'";
            $eventType = isset($row['event_type']) && $row['event_type'] === 'removed' ? 'removed' : 'current';
            $submitUrl = $eventType === 'removed'
                ? trim((string)$row['url'])
                : $this->publicThreadUrl((int)$row['tid']);

            if($submitUrl === false || !$this->isBoardUrl($submitUrl))
            {
                $db->delete_query('vonseo_indexnow_queue', $condition);
                ++$result['dropped'];
                continue;
            }

            $row['event_type'] = $eventType;
            $row['submit_url'] = $submitUrl;
            $row['condition'] = $condition;
            $rows[] = $row;
            $urls[] = $submitUrl;
        }

        $result['processed'] = count($rows) + $result['dropped'];
        if($result['processed'] === 0)
        {
            $result['remaining'] = class_exists('VonSEO_State') ? VonSEO_State::refreshQueueDepth() : 0;
            return $result;
        }

        if(empty($rows))
        {
            if(class_exists('VonSEO_State'))
            {
                VonSEO_State::recordIndexNowResult(true, 0, '', $result['dropped'], 'ready', 0);
                $result['remaining'] = VonSEO_State::refreshQueueDepth();
            }
            return $result;
        }

        $submission = $this->submitUrlsResult($urls);
        $success = !empty($submission['success']);
        $result['success'] = $success;
        $result['status'] = isset($submission['class']) ? $submission['class'] : 'remote_error';
        $result['http_status'] = isset($submission['http_status']) ? (int)$submission['http_status'] : 0;

        if($success)
        {
            foreach($rows as $row)
            {
                $deleted = $this->deleteSelectedRow($row['condition']);
                if($row['event_type'] === 'removed')
                {
                    if($deleted)
                    {
                        $this->forgetThreadHistory((int)$row['tid']);
                    }
                    continue;
                }

                $this->markThreadSubmitted((int)$row['tid'], $row['submit_url'], $now);
                if(!$deleted)
                {
                    // A reply/edit arrived during delivery. Keep its generation,
                    // but do not immediately send the same hot thread again.
                    $this->deferConcurrentCurrent((int)$row['tid'], $now + self::THREAD_COOLDOWN);
                }
            }
        }
        else
        {
            $consumeAttempt = !empty($submission['consume_attempt']);
            $retryAfter = !empty($submission['retry_after']) ? (int)$submission['retry_after'] : 0;
            $message = !empty($submission['message']) ? (string)$submission['message'] : 'IndexNow request failed.';
            foreach($rows as $row)
            {
                $attempts = (int)$row['attempts'] + ($consumeAttempt ? 1 : 0);
                $rowRetryAfter = $retryAfter;
                if($rowRetryAfter <= 0)
                {
                    $rowRetryAfter = min(86400, 60 * pow(2, min(10, max(0, $attempts - 1))));
                }
                if($consumeAttempt && $attempts >= self::MAX_ATTEMPTS)
                {
                    $db->delete_query('vonseo_indexnow_queue', $row['condition']);
                    ++$result['dropped'];
                    continue;
                }
                $retryNotBefore = $now + max(60, (int)$rowRetryAfter);
                $retryData = array(
                    'attempts' => $attempts,
                    'retry_not_before' => $retryNotBefore,
                    'updated_at' => $now,
                    'last_error' => $db->escape_string(substr($message, 0, 255))
                );
                $db->update_query('vonseo_indexnow_queue', $retryData, $row['condition']);
                if(method_exists($db, 'affected_rows') && (int)$db->affected_rows() === 0)
                {
                    // A new content generation arrived while the request was in
                    // flight. Keep it, but do not let it bypass the transport
                    // failure observed for the same board and thread.
                    $db->update_query('vonseo_indexnow_queue', $retryData, "tid='".(int)$row['tid']."'");
                }
            }
        }

        if(class_exists('VonSEO_State'))
        {
            $message = $success ? '' : (string)$submission['message'];
            if(!$success && $result['dropped'] > 0)
            {
                $message .= ' Retry limit reached for one or more URLs.';
            }
            VonSEO_State::recordIndexNowResult(
                $success,
                count($rows),
                trim($message),
                $result['dropped'],
                $result['status'],
                $result['http_status']
            );
            $result['remaining'] = VonSEO_State::refreshQueueDepth();
        }
        return $result;
    }

    /** Public compatibility API; worker uses the detailed result internally. */
    public function submitUrls(array $urls)
    {
        $result = $this->submitUrlsResult($urls);
        return !empty($result['success']);
    }

    /** @return array */
    protected function submitUrlsResult(array $urls)
    {
        if(empty($urls))
        {
            return $this->failure('config_error', 'No valid URLs were supplied to IndexNow.', 0, self::CONFIG_RETRY_DELAY, false);
        }

        $key = $this->getKey();
        if($key === '')
        {
            return $this->failure('config_error', 'IndexNow API key is missing. Queue retained until configuration is fixed.', 0, self::CONFIG_RETRY_DELAY, false);
        }

        $host = $this->extractHost();
        if($host === '')
        {
            return $this->failure('config_error', 'The MyBB board URL has no valid hostname. Queue retained.', 0, self::CONFIG_RETRY_DELAY, false);
        }

        $cleanUrls = array();
        foreach($urls as $u)
        {
            $u = trim((string)$u);
            if($this->isBoardUrl($u))
            {
                $cleanUrls[] = $u;
            }
        }
        $cleanUrls = array_values(array_unique($cleanUrls));
        if(empty($cleanUrls))
        {
            return $this->failure('config_error', 'Queued URLs do not match the configured MyBB board origin. Queue retained.', 0, self::CONFIG_RETRY_DELAY, false);
        }

        return $this->sendPingResult(array(
            'host' => $host,
            'key' => $key,
            'keyLocation' => $this->getKeyLocation(),
            'urlList' => $cleanUrls
        ));
    }

    /** Legacy boolean transport wrapper for subclasses/integrations. */
    protected function sendPing(array $payload)
    {
        $result = $this->sendPingResult($payload);
        return !empty($result['success']);
    }

    /** Send HTTP POST and retain enough evidence to classify safe retries. */
    protected function sendPingResult(array $payload)
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if($json === false)
        {
            return $this->failure('config_error', 'IndexNow payload could not be encoded. Queue retained.', 0, self::CONFIG_RETRY_DELAY, false);
        }

        $endpoint = 'https://api.indexnow.org/indexnow';
        if(function_exists('curl_init'))
        {
            $ch = curl_init($endpoint);
            if($ch)
            {
                $headers = array();
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json; charset=utf-8',
                    'Content-Length: '.strlen($json),
                    'User-Agent: VonSEO-MyBB/'.VONSEO_VERSION.' (IndexNow Ping)'
                ));
                curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $line) use (&$headers) {
                    $headers[] = trim($line);
                    return strlen($line);
                });
                curl_setopt($ch, CURLOPT_TIMEOUT, 4);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

                $response = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = $response === false ? trim((string)curl_error($ch)) : '';
                $curlErrno = $response === false ? (int)curl_errno($ch) : 0;
                curl_close($ch);

                if($response === false)
                {
                    $timedOut = defined('CURLE_OPERATION_TIMEDOUT') && $curlErrno === CURLE_OPERATION_TIMEDOUT;
                    return $this->failure(
                        'remote_error',
                        $timedOut ? 'IndexNow request timed out; queued URLs will retry.' : 'IndexNow network error: '.($curlError !== '' ? $curlError : 'request failed').'.',
                        0,
                        0,
                        true
                    );
                }
                return $this->classifyHttp($httpCode, $this->retryAfterFromHeaders($headers));
            }
        }

        $options = array('http' => array(
            'method' => 'POST',
            'header' => "Content-Type: application/json; charset=utf-8\r\n"
                ."Content-Length: ".strlen($json)."\r\n"
                ."User-Agent: VonSEO-MyBB/".VONSEO_VERSION." (IndexNow Ping)\r\n",
            'content' => $json,
            'timeout' => 4,
            'ignore_errors' => true
        ));
        $context = stream_context_create($options);
        $fp = @fopen($endpoint, 'r', false, $context);
        if(!$fp)
        {
            return $this->failure('remote_error', 'IndexNow network request failed or timed out; queued URLs will retry.', 0, 0, true);
        }

        $metadata = stream_get_meta_data($fp);
        @fclose($fp);
        $status = 0;
        $headers = !empty($metadata['wrapper_data']) && is_array($metadata['wrapper_data']) ? $metadata['wrapper_data'] : array();
        foreach($headers as $header)
        {
            if(preg_match('#^HTTP/\S+\s+(\d{3})\b#i', $header, $matches))
            {
                $status = (int)$matches[1];
            }
        }
        return $this->classifyHttp($status, $this->retryAfterFromHeaders($headers));
    }

    /**
     * @param int|string $status
     * @param int|string $retryAfter
     * @return array
     */
    protected function classifyHttp($status, $retryAfter = 0)
    {
        $status = (int)$status;
        if($status >= 200 && $status < 300)
        {
            return array(
                'success' => true, 'class' => 'ready', 'message' => '',
                'http_status' => $status, 'retry_after' => 0, 'consume_attempt' => false
            );
        }
        if($status === 429)
        {
            return $this->failure(
                'rate_limited',
                'IndexNow rate limited this board (HTTP 429). Queue retained for a later retry.',
                $status,
                $retryAfter > 0 ? $retryAfter : self::RATE_LIMIT_RETRY_DELAY,
                false
            );
        }
        if(in_array($status, array(400, 403, 422), true))
        {
            return $this->failure(
                'config_error',
                'IndexNow rejected the key or payload (HTTP '.$status.'). Queue retained; check the key and board URL.',
                $status,
                self::CONFIG_RETRY_DELAY,
                false
            );
        }
        if($status >= 500 && $status <= 599)
        {
            return $this->failure('remote_error', 'IndexNow remote service error (HTTP '.$status.'); queued URLs will retry.', $status, 0, true);
        }
        if($status >= 400 && $status <= 499)
        {
            return $this->failure('remote_error', 'IndexNow returned HTTP '.$status.'. Queue retained for operator review.', $status, self::RATE_LIMIT_RETRY_DELAY, false);
        }
        return $this->failure('remote_error', 'IndexNow returned no usable HTTP response; queued URLs will retry.', $status, 0, true);
    }

    /**
     * @param string $class
     * @param string $message
     * @param int|string $httpStatus
     * @param int|string $retryAfter
     * @param bool $consumeAttempt
     * @return array
     */
    protected function failure($class, $message, $httpStatus, $retryAfter, $consumeAttempt)
    {
        return array(
            'success' => false,
            'class' => (string)$class,
            'message' => trim((string)$message),
            'http_status' => (int)$httpStatus,
            'retry_after' => max(0, min(86400, (int)$retryAfter)),
            'consume_attempt' => (bool)$consumeAttempt
        );
    }

    protected function retryAfterFromHeaders(array $headers)
    {
        foreach($headers as $header)
        {
            if(stripos($header, 'Retry-After:') !== 0)
            {
                continue;
            }
            $value = trim(substr($header, strlen('Retry-After:')));
            if(ctype_digit($value))
            {
                return max(60, min(86400, (int)$value));
            }
            $time = strtotime($value);
            if($time !== false)
            {
                return max(60, min(86400, $time - $this->now()));
            }
        }
        return 0;
    }

    /**
     * @param int|string $tid
     * @return array
     */
    protected function threadHistory($tid)
    {
        global $db;
        if(!$db->table_exists('vonseo_indexnow_threads'))
        {
            return array();
        }
        $tid = (int)$tid;
        $query = $db->simple_select('vonseo_indexnow_threads', 'tid,last_public_url,last_submitted_at', "tid='{$tid}'", array('limit' => 1));
        $row = $db->fetch_array($query);
        return is_array($row) ? $row : array();
    }

    /**
     * @param int|string $tid
     * @param string $url
     * @return void
     */
    protected function rememberPublicUrl($tid, $url)
    {
        global $db;
        if(!$db->table_exists('vonseo_indexnow_threads') || !$this->isBoardUrl($url))
        {
            return;
        }
        $history = $this->threadHistory($tid);
        $db->replace_query('vonseo_indexnow_threads', array(
            'tid' => (int)$tid,
            'last_public_url' => $db->escape_string($url),
            'last_submitted_at' => !empty($history['last_submitted_at']) ? (int)$history['last_submitted_at'] : 0,
            'updated_at' => $this->now()
        ));
    }

    /**
     * @param int|string $tid
     * @param string $url
     * @param int|string $submittedAt
     * @return void
     */
    protected function markThreadSubmitted($tid, $url, $submittedAt)
    {
        global $db;
        if(!$db->table_exists('vonseo_indexnow_threads'))
        {
            return;
        }
        $db->replace_query('vonseo_indexnow_threads', array(
            'tid' => (int)$tid,
            'last_public_url' => $db->escape_string($url),
            'last_submitted_at' => (int)$submittedAt,
            'updated_at' => (int)$submittedAt
        ));
    }

    /**
     * @param int|string $tid
     * @return void
     */
    protected function forgetThreadHistory($tid)
    {
        global $db;
        if($db->table_exists('vonseo_indexnow_threads'))
        {
            $db->delete_query('vonseo_indexnow_threads', "tid='".(int)$tid."'");
        }
    }

    /**
     * @param string $condition
     * @return bool
     */
    protected function deleteSelectedRow($condition)
    {
        global $db;
        $db->delete_query('vonseo_indexnow_queue', $condition);
        return method_exists($db, 'affected_rows') ? (int)$db->affected_rows() > 0 : true;
    }

    /**
     * @param int|string $tid
     * @param int|string $availableAt
     * @return void
     */
    protected function deferConcurrentCurrent($tid, $availableAt)
    {
        global $db;
        $db->update_query('vonseo_indexnow_queue', array(
            'available_at' => (int)$availableAt,
            'updated_at' => $this->now()
        ), "tid='".(int)$tid."' AND event_type='current' AND available_at<'".(int)$availableAt."'");
    }

    /**
     * Resolve a thread only when it is visible to a guest.
     *
     * @param int|string $tid
     * @return string|false
     */
    protected function publicThreadUrl($tid)
    {
        return $this->threadUrl($tid, true);
    }

    /**
     * @param int|string $tid
     * @param bool $requireVisible
     * @return string|false
     */
    protected function threadUrl($tid, $requireVisible)
    {
        global $db;

        $tid = (int)$tid;
        if($tid <= 0)
        {
            return false;
        }
        $query = $db->simple_select('threads', 'tid,fid,visible,closed,subject', "tid='{$tid}'", array('limit' => 1));
        $thread = $db->fetch_array($query);
        if(!$thread || empty($thread['tid']) || ($requireVisible && (int)$thread['visible'] !== 1) || strpos((string)$thread['closed'], 'moved|') === 0)
        {
            return false;
        }
        if(!$this->isForumPublic((int)$thread['fid']))
        {
            return false;
        }
        return $this->url->thread($tid, 0, isset($thread['subject']) ? $thread['subject'] : '');
    }

    /** @return string */
    protected function extractHost()
    {
        $host = parse_url($this->url->home(), PHP_URL_HOST);
        return $host ? (string)$host : '';
    }

    /**
     * @param string $url
     * @return bool
     */
    protected function isBoardUrl($url)
    {
        if($url === '' || !filter_var($url, FILTER_VALIDATE_URL))
        {
            return false;
        }
        $candidate = @parse_url($url);
        $board = @parse_url($this->url->home());
        if(!is_array($candidate) || !is_array($board) || empty($candidate['scheme']) || empty($candidate['host']))
        {
            return false;
        }
        $candidatePort = isset($candidate['port']) ? (int)$candidate['port'] : (strtolower($candidate['scheme']) === 'https' ? 443 : 80);
        $boardPort = isset($board['port']) ? (int)$board['port'] : (strtolower($board['scheme']) === 'https' ? 443 : 80);
        return strtolower($candidate['scheme']) === strtolower($board['scheme'])
            && strcasecmp($candidate['host'], $board['host']) === 0
            && $candidatePort === $boardPort;
    }

    /**
     * @param int|string $fid
     * @return bool
     */
    protected function isForumPublic($fid)
    {
        global $cache;

        $fid = (int)$fid;
        if($fid <= 0)
        {
            return false;
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
        if(!isset($forums[$fid]) || empty($forums[$fid]['fid']))
        {
            return false;
        }
        $forum = $forums[$fid];
        if(isset($forum['active']) && (int)$forum['active'] === 0)
        {
            return false;
        }
        if(VonSEO_Utils::forumHasPasswordBarrier($forum, $forums))
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
        $perms = function_exists('forum_permissions') ? forum_permissions($fid, 0, 1) : false;
        if(!$perms || empty($perms['canview']) || empty($perms['canviewthreads']) || !empty($perms['canonlyviewownthreads']))
        {
            return false;
        }
        return true;
    }

    protected function now()
    {
        return defined('TIME_NOW') ? (int)TIME_NOW : time();
    }
}
