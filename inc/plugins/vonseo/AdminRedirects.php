<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

/** ACP-only inspection of saved rules. Never sends HTTP or records a hit. */
class VonSEO_AdminRedirects extends VonSEO_Redirects
{
    const MAX_LOOKUPS = 12;
    const MAX_SOURCE_BYTES = 2048;

    /** @return array */
    public static function methods(): array
    {
        return array('GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS');
    }

    /**
     * @param mixed $source
     * @return array
     */
    public function inspect($source, string $method = 'GET'): array
    {
        global $db;
        $method = strtoupper($method);
        $result = array('status' => 'invalid_source', 'method' => $method, 'source' => '',
            'steps' => array(), 'hops' => 0, 'final_url' => '', 'suggestion' => false,
            'engine_enabled' => (bool)VonSEO_Utils::setting('vonseo_enabled', 1) &&
                (bool)VonSEO_Utils::setting('vonseo_redirects_enabled', 1));
        if(!in_array($method, self::methods(), true))
        {
            $result['status'] = 'invalid_method';
            return $result;
        }
        $current = $this->inspectionSource($source);
        if($current === false)
        {
            return $result;
        }
        $result['source'] = $current;
        $decodedSource = trim(html_entity_decode($source, ENT_QUOTES, 'UTF-8'));
        if($this->legacyRoutingAmbiguity($decodedSource))
        {
            // Do not erase the disputed identity through Url::absolute() or
            // pretend that legacy frontend normalization is an exact lookup.
            $result['status'] = 'routing_uncertain';
            $result['source'] = $decodedSource;
            $result['final_url'] = preg_match('#^https?://#i', $decodedSource) ? $decodedSource :
                rtrim($this->url->base(), '/').'/'.ltrim($current, '/');
            return $result;
        }
        $result['final_url'] = $this->url->absolute(ltrim($current, '/'));
        if(!$db->table_exists('vonseo_redirects'))
        {
            $result['status'] = 'missing_table';
            return $result;
        }

        $visited = array();
        $permanent = true;
        for($lookup = 0; $lookup < self::MAX_LOOKUPS; ++$lookup)
        {
            if(isset($visited[$current]))
            {
                $result['status'] = 'loop';
                return $result;
            }
            $visited[$current] = true;
            // Read disabled rules too, without trusting the frontend count cache.
            $hash = $db->escape_string(hash('sha256', $current));
            $query = $db->simple_select('vonseo_redirects', 'rid,source_path,target_url,status_code,enabled',
                "source_hash='{$hash}'", array('limit' => 1));
            $rule = $db->fetch_array($query);
            if(!$rule)
            {
                $result['status'] = 'no_rule';
                $result['suggestion'] = $result['hops'] > 1 && $permanent;
                return $result;
            }
            $step = array('rid' => (int)$rule['rid'], 'source' => (string)$rule['source_path'],
                'target' => (string)$rule['target_url'], 'code' => (int)$rule['status_code'],
                'state' => 'Enabled');
            $result['steps'][] = $step;
            $index = count($result['steps']) - 1;
            if($step['source'] !== $current)
            {
                $result['status'] = 'invalid_rule';
                $result['steps'][$index]['state'] = 'Invalid source identity';
                return $result;
            }
            if(empty($rule['enabled']))
            {
                $result['status'] = 'disabled';
                $result['steps'][$index]['state'] = 'Disabled';
                return $result;
            }
            if(!in_array($step['code'], $this->allowedCodes, true))
            {
                $result['status'] = 'invalid_rule';
                $result['steps'][$index]['state'] = 'Unsupported response';
                return $result;
            }
            if(!in_array($method, array('GET', 'HEAD'), true) && in_array($step['code'], array(301, 302), true))
            {
                $result['status'] = 'method_blocked';
                $result['steps'][$index]['state'] = 'Not applied to '.$method;
                return $result;
            }
            if($step['code'] === 410)
            {
                $result['status'] = 'gone';
                $result['steps'][$index]['target'] = '';
                return $result;
            }
            $target = $this->normalizeTarget($step['target']);
            if($target === false || preg_match('/[\x00-\x20\x7F\\\\]/', $target))
            {
                $result['status'] = 'invalid_target';
                $result['steps'][$index]['state'] = 'Invalid or disallowed target';
                return $result;
            }
            $result['steps'][$index]['target'] = $target;
            // Match the runtime's self-redirect comparison, including fragments.
            if($this->normalizeForCompare($target) === $this->normalizeForCompare($this->url->absolute(ltrim($current, '/'))))
            {
                $result['status'] = 'loop';
                $result['steps'][$index]['state'] = 'Self-redirect blocked';
                return $result;
            }
            ++$result['hops'];
            $permanent = $permanent && in_array($step['code'], array(301, 308), true);
            $result['final_url'] = $target;
            $parts = @parse_url($target);
            if(!$this->sameOrigin($parts, @parse_url($this->url->base())))
            {
                $result['status'] = 'external';
                return $result;
            }
            if($this->ambiguousPath($target))
            {
                $result['status'] = 'routing_uncertain';
                return $result;
            }
            if(!$this->insideBoard($target))
            {
                $result['status'] = 'outside_board';
                return $result;
            }
            if($this->legacyRoutingAmbiguity($target))
            {
                $result['status'] = 'routing_uncertain';
                return $result;
            }
            $current = $this->targetToSource($target);
            if($current === false)
            {
                $result['status'] = 'invalid_target';
                return $result;
            }
        }
        $result['status'] = isset($visited[$current]) ? 'loop' : 'limit';
        return $result;
    }

    /**
     * @param mixed $source
     * @return string|false
     */
    protected function inspectionSource($source)
    {
        if(!is_string($source) || strlen($source) > self::MAX_SOURCE_BYTES)
        {
            return false;
        }
        $rawSource = $source;
        $source = trim(html_entity_decode($source, ENT_QUOTES, 'UTF-8'));
        if($source === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $source))
        {
            return false;
        }
        if(preg_match('#^https?://#i', $source))
        {
            $parts = @parse_url($source);
            if(!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || !$this->insideBoard($source))
            {
                return false;
            }
        }
        return $this->ambiguousPath($source) ? false : $this->normalizeSource($rawSource);
    }

    protected function insideBoard(string $url): bool
    {
        $parts = @parse_url($url);
        $base = @parse_url($this->url->base());
        if(!$this->sameOrigin($parts, $base))
        {
            return false;
        }
        $path = isset($parts['path']) ? $parts['path'] : '/';
        $basePath = isset($base['path']) ? rtrim($base['path'], '/') : '';
        return $basePath === '' || $path === $basePath || strpos($path, $basePath.'/') === 0;
    }

    protected function ambiguousPath(string $url): bool
    {
        $path = @parse_url($url, PHP_URL_PATH);
        return is_string($path) && (bool)preg_match('#(?:%2f|%5c|%2e)|(?:^|/)\.{1,2}(?:/|$)#i', $path);
    }

    /** Legacy request candidates can drop ?0 or strip the board prefix twice. */
    protected function legacyRoutingAmbiguity(string $url): bool
    {
        $parts = @parse_url($url);
        if(!is_array($parts))
        {
            return false;
        }
        if(isset($parts['query']) && $parts['query'] === '0')
        {
            return true;
        }
        $base = @parse_url($this->url->base());
        $basePath = !empty($base['path']) ? '/'.trim($base['path'], '/') : '';
        if($basePath === '')
        {
            return false;
        }
        $path = isset($parts['path']) ? $parts['path'] : '/';
        $path = '/'.ltrim(preg_replace('#/{2,}#', '/', $path), '/');
        $relative = $this->stripBoardBasePath($path);
        return $relative !== $path && ($relative === $basePath || strpos($relative, $basePath.'/') === 0);
    }

    /** @return string */
    public static function render(array $result): string
    {
        $messages = array(
            'invalid_source' => array('Invalid source URL', 'Enter a board-relative path or a full URL inside the configured Board URL. Maximum 2,048 bytes. Encode spaces; credentials, control characters and ambiguous dot/encoded separator paths are not inspected.'),
            'invalid_method' => array('Invalid method', 'Choose a method from the request-method list.'),
            'missing_table' => array('Redirect table unavailable', 'Check the VonSEO database diagnostics before inspecting rules.'),
            'no_rule' => array($result['hops'] ? 'End of saved rule chain' : 'No matching saved rule', 'No next VonSEO rule was found. This does not prove that the destination returns HTTP 200 or has no other redirects.'),
            'disabled' => array('Disabled rule encountered', 'The disabled rule is shown for reference and is not followed.'),
            'gone' => array('410 Gone', 'The configured resource is retired without a redirect destination.'),
            'method_blocked' => array('Rule not applied to this method', 'VonSEO skips 301/302 rules for methods other than GET and HEAD. 307/308 preserve the method.'),
            'invalid_rule' => array('Invalid saved rule', 'The source identity or response is inconsistent. Review the rule manually.'),
            'invalid_target' => array('Invalid or disallowed destination', 'The destination cannot be safely inspected under the current URL validation and external-target settings.'),
            'loop' => array('Redirect loop detected', 'A source repeats, or the runtime self-redirect guard would block this rule. Review the affected rules manually.'),
            'external' => array('Leaves the board origin', 'The destination uses another host, scheme or port. It was not fetched or followed.'),
            'outside_board' => array('Leaves the board path', 'The destination is on this origin but outside the configured forum path. No unrelated site rules were inspected.'),
            'routing_uncertain' => array('URL normalization needs a live check', 'Legacy redirect normalization can strip repeated board prefixes or treat a query consisting only of 0 as empty. Dot segments or encoded path separators can also change in live routing. Inspection stops here without guessing the next rule or suggesting a shorter mapping.'),
            'limit' => array('Inspection limit reached', 'Inspection stops after at most 12 rule lookups. A longer chain is not automatically classified as a loop.')
        );
        $message = $messages[$result['status']];
        $escape = static function(string $value): string {
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $html = '<section class="vs-inspection" aria-labelledby="vs-inspection-result"><div class="vs-notice">'
            .'<h3 id="vs-inspection-result">'.$message[0].'</h3><p>'.$message[1].'</p>'
            .'<p>Method: <strong>'.$escape($result['method']).'</strong>. Followed redirects: <strong>'.(int)$result['hops'].'</strong>.</p></div>';
        if(!$result['engine_enabled'])
        {
            $html .= '<div class="vs-notice is-warning">The SEO engine or redirect engine is disabled. This is a preview of saved configuration, not active redirect behaviour.</div>';
        }
        if($result['steps'])
        {
            $html .= '<div class="vs-table-wrap vs-table-cards vs-table-inspector"><table><caption>Saved rule trace</caption>'
                .'<thead><tr><th scope="col">Step</th><th scope="col">Source</th><th scope="col">Response / State</th>'
                .'<th scope="col">Destination</th><th scope="col">Rule</th></tr></thead><tbody>';
            foreach($result['steps'] as $i => $step)
            {
                $html .= '<tr><td>'.($i + 1).'</td><td><span class="vs-code">'.$escape($step['source']).'</span></td>'
                    .'<td>'.(int)$step['code'].'<br />'.$escape($step['state']).'</td><td><span class="vs-code">'
                    .($step['code'] === 410 ? 'No destination (410 Gone)' : $escape($step['target'])).'</span></td>'
                    .'<td><a href="index.php?module=config-vonseo&amp;action=redirect_edit&amp;rid='.(int)$step['rid'].'">Review rule #'.(int)$step['rid'].'</a></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        if($result['final_url'] !== '')
        {
            $html .= '<p>Last inspected URL: <span class="vs-code">'.$escape($result['final_url']).'</span></p>';
        }
        if($result['suggestion'])
        {
            $html .= '<div class="vs-notice">Possible shortening: review whether the first rule could point directly to the last URL above. Only permanent saved redirects were followed. Confirm the move intent, permissions and live destination before making any manual change; nothing has been changed for you.</div>';
        }
        return $html.'</section>';
    }
}
