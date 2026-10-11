<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

/**
 * Byte-preserving edits to explicit HTML tags. This is not a DOM serializer:
 * comments, raw-text elements and unrelated markup must remain unchanged.
 * Incomplete/implicit heads are deliberately left alone.
 */
class VonSEO_Html
{
    /** @return bool */
    public static function hasHead(string $html)
    {
        return self::head($html) !== false;
    }

    /**
     * @param string|callable $append
     * @return string
     */
    public static function editHead(string $html, callable $edit, $append = '')
    {
        $head = self::head($html);
        if($head === false)
        {
            return $html;
        }

        $output = substr($html, 0, $head['open_end']);
        $cursor = $head['open_end'];
        foreach($head['tags'] as $tag)
        {
            $output .= substr($html, $cursor, $tag['start'] - $cursor);
            $output .= $edit($tag, substr($html, $tag['start'], $tag['end'] - $tag['start']));
            $cursor = $tag['end'];
        }
        $output .= substr($html, $cursor, $head['close_start'] - $cursor);
        $output .= is_callable($append) ? $append() : $append;
        return $output.substr($html, $head['close_start']);
    }

    /** @return string */
    public static function rewriteAnchors(string $html, callable $resolve)
    {
        $output = '';
        $cursor = 0;
        foreach(self::tags($html) as $tag)
        {
            if($tag['name'] !== 'a' || $tag['closing'] || !isset($tag['attributes']['href']))
            {
                continue;
            }
            $href = $tag['attributes']['href'];
            if($href['length'] === 0)
            {
                continue;
            }
            $destination = $resolve($href['value']);
            if(!is_string($destination) || $destination === '')
            {
                continue;
            }
            $output .= substr($html, $cursor, $href['start'] - $cursor);
            $escaped = VonSEO_Utils::h($destination);
            $output .= $href['quote'] === '' ? '"'.$escaped.'"' : $escaped;
            $cursor = $href['start'] + $href['length'];
        }
        return $output.substr($html, $cursor);
    }

    /** @return array|false */
    protected static function head(string $html)
    {
        $head = false;
        $templateDepth = 0;
        foreach(self::tags($html) as $tag)
        {
            if($tag['name'] === 'template')
            {
                $templateDepth = max(0, $templateDepth + ($tag['closing'] ? -1 : 1));
                continue;
            }
            if($templateDepth > 0)
            {
                continue;
            }
            if($tag['name'] === 'body')
            {
                return false;
            }
            if($tag['name'] === 'head')
            {
                if($tag['closing'])
                {
                    if($head !== false)
                    {
                        $head['close_start'] = $tag['start'];
                        return $head;
                    }
                    return false;
                }
                if($head !== false)
                {
                    return false;
                }
                $head = array('open_end' => $tag['end'], 'tags' => array());
                continue;
            }
            if($head !== false)
            {
                $head['tags'][] = $tag;
            }
        }
        return false;
    }

    /** @return Generator */
    protected static function tags(string $html)
    {
        $length = strlen($html);
        $offset = 0;
        $rawNames = array('script', 'style', 'title', 'textarea', 'xmp', 'iframe',
            'noembed', 'noframes', 'noscript');
        while($offset < $length && ($start = strpos($html, '<', $offset)) !== false)
        {
            if(substr($html, $start, 4) === '<!--')
            {
                $end = strpos($html, '-->', $start + 4);
                if($end === false)
                {
                    return;
                }
                $offset = $end + 3;
                continue;
            }
            if(substr($html, $start, 9) === '<![CDATA[')
            {
                $end = strpos($html, ']]>', $start + 9);
                $offset = $end === false ? $length : $end + 3;
                continue;
            }
            if(substr($html, $start, 2) === '<!' || substr($html, $start, 2) === '<?')
            {
                $offset = self::tagEnd($html, $start + 2);
                continue;
            }
            if(!preg_match('~\G<(/?)([a-z][a-z0-9:-]*)(?=[\t\n\f\r />])~i', $html, $match, 0, $start))
            {
                $offset = $start + 1;
                continue;
            }
            $name = strtolower($match[2]);
            $closing = $match[1] === '/';
            $openEnd = self::tagEnd($html, $start + strlen($match[0]));
            if($openEnd > $length || substr($html, $openEnd - 1, 1) !== '>')
            {
                return;
            }
            $tag = array('name' => $name, 'closing' => $closing, 'start' => $start,
                'end' => $openEnd, 'complete' => true, 'attributes' => $closing ? array() :
                    self::attributes($html, $start + strlen($match[0]), $openEnd - 1));
            if(!$closing && $name === 'plaintext')
            {
                return;
            }
            if(!$closing && in_array($name, $rawNames, true))
            {
                $rawEnd = self::rawEnd($html, $name, $openEnd);
                if($rawEnd !== false)
                {
                    $tag['end'] = $rawEnd;
                }
                else
                {
                    $tag['end'] = $length;
                    $tag['complete'] = false;
                }
            }
            $offset = $tag['end'];
            yield $tag;
        }
    }

    /**
     * HTML script escape states matter even inside JavaScript strings. In a
     * double-escaped script, </script> returns to escaped data without closing
     * the element. A plain regex would expose fake head/anchor tags too early.
     *
     * @return int|false
     */
    protected static function rawEnd(string $html, string $name, int $offset)
    {
        if($name !== 'script')
        {
            if(!preg_match('~</'.$name.'(?=[\t\n\f\r />])~i', $html, $match, PREG_OFFSET_CAPTURE, $offset))
            {
                return false;
            }
            $end = self::tagEnd($html, $match[0][1] + strlen($match[0][0]));
            return $end <= strlen($html) ? $end : false;
        }

        $state = 'data';
        while($offset < strlen($html))
        {
            $pattern = $state === 'data' ? '~<!--|</script(?=[\t\n\f\r />])~i' :
                ($state === 'escaped' ? '~-->|</?script(?=[\t\n\f\r />])~i' :
                    '~-->|</script(?=[\t\n\f\r />])~i');
            if(!preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE, $offset))
            {
                return false;
            }
            $token = strtolower($match[0][0]);
            $offset = $match[0][1] + strlen($match[0][0]);
            if($token === '<!--')
            {
                $state = 'escaped';
            }
            elseif($token === '-->')
            {
                $state = 'data';
            }
            elseif($token === '<script')
            {
                $state = 'double';
            }
            elseif($state === 'double')
            {
                $state = 'escaped';
            }
            else
            {
                $end = self::tagEnd($html, $offset);
                return $end <= strlen($html) ? $end : false;
            }
        }
        return false;
    }

    /** @return int */
    protected static function tagEnd(string $html, int $offset)
    {
        $length = strlen($html);
        $quote = '';
        for(; $offset < $length; ++$offset)
        {
            $char = $html[$offset];
            if($quote !== '')
            {
                if($char === $quote)
                {
                    $quote = '';
                }
            }
            elseif($char === '"' || $char === "'")
            {
                $quote = $char;
            }
            elseif($char === '>')
            {
                return $offset + 1;
            }
        }
        return $length + 1;
    }

    /** @return array */
    protected static function attributes(string $html, int $offset, int $end)
    {
        $attributes = array();
        $space = " \t\r\n\f";
        while($offset < $end)
        {
            $offset += strspn($html, $space, $offset, $end - $offset);
            if($offset >= $end || $html[$offset] === '/')
            {
                break;
            }
            $size = strcspn($html, $space."\"'<>/=", $offset, $end - $offset);
            if($size === 0)
            {
                break;
            }
            $name = strtolower(substr($html, $offset, $size));
            $offset += $size;
            $offset += strspn($html, $space, $offset, $end - $offset);
            $quote = '';
            $valueStart = $offset;
            $valueLength = 0;
            if($offset < $end && $html[$offset] === '=')
            {
                ++$offset;
                $offset += strspn($html, $space, $offset, $end - $offset);
                if($offset < $end && ($html[$offset] === '"' || $html[$offset] === "'"))
                {
                    $quote = $html[$offset++];
                    $valueStart = $offset;
                    $close = strpos($html, $quote, $offset);
                    if($close === false || $close > $end)
                    {
                        break;
                    }
                    $valueLength = $close - $offset;
                    $offset = $close + 1;
                }
                else
                {
                    $valueStart = $offset;
                    $valueLength = strcspn($html, $space.'>', $offset, $end - $offset);
                    $offset += $valueLength;
                }
            }
            // HTML uses the first occurrence of a duplicate attribute.
            if(!isset($attributes[$name]))
            {
                $attributes[$name] = array('value' => VonSEO_Utils::htmlAttribute(
                    substr($html, $valueStart, $valueLength)),
                    'start' => $valueStart, 'length' => $valueLength, 'quote' => $quote);
            }
        }
        return $attributes;
    }
}
