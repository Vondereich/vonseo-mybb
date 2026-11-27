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

    /**
     * href attributes (value, offsets, quote) of opening anchors in document
     * order. Tokenize once and pass the result to rewriteAnchors().
     *
     * @return array
     */
    public static function anchors(string $html)
    {
        $anchors = array();
        foreach(self::tags($html) as $tag)
        {
            if($tag['name'] === 'a' && !$tag['closing'] && isset($tag['attributes']['href']) &&
               $tag['attributes']['href']['length'] > 0)
            {
                $anchors[] = $tag['attributes']['href'];
            }
        }
        return $anchors;
    }

    /**
     * @param string $html
     * @param callable $resolve
     * @param array|null $anchors Result of anchors() for the same $html, to avoid tokenizing twice.
     * @return string
     */
    public static function rewriteAnchors(string $html, callable $resolve, $anchors = null)
    {
        $output = '';
        $cursor = 0;
        foreach(is_array($anchors) ? $anchors : self::anchors($html) as $href)
        {
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
                // HTML5 comment ends: "<!-->" and "<!--->" are complete empty
                // comments; otherwise the first "-->" or "--!>" closes it.
                if(substr($html, $start + 4, 1) === '>')
                {
                    $offset = $start + 5;
                    continue;
                }
                if(substr($html, $start + 4, 2) === '->')
                {
                    $offset = $start + 6;
                    continue;
                }
                $plain = strpos($html, '-->', $start + 4);
                $bang = strpos($html, '--!>', $start + 4);
                if($plain === false && $bang === false)
                {
                    return;
                }
                $offset = ($bang === false || ($plain !== false && $plain < $bang)) ? $plain + 3 : $bang + 4;
                continue;
            }
            // "<![CDATA[" is a real CDATA section only inside SVG/MathML. In HTML
            // content a browser treats it as a bogus comment ending at the first
            // ">", so it falls through to the branch below; waiting for "]]>"
            // exposed later comment text as tags.
            // HTML5 bogus comments ("<!x", "<?x", "</" + non-letter) end at the
            // FIRST ">"; quotes inside them never hide a ">".
            if(substr($html, $start, 2) === '<!' || substr($html, $start, 2) === '<?' ||
               (substr($html, $start, 2) === '</' && !preg_match('~\G</[a-z>]~i', $html, $unused, 0, $start)))
            {
                $end = strpos($html, '>', $start + 2);
                if($end === false)
                {
                    return;
                }
                $offset = $end + 1;
                continue;
            }
            if(!preg_match('~\G<(/?)([a-z][a-z0-9:-]*)(?=[\t\n\f\r />])~i', $html, $match, 0, $start))
            {
                // HTML5 starts a tag at "<" + letter (or "</" + letter) and its
                // name runs to whitespace, "/" or ">", so "<meta<meta name=x>"
                // is ONE unknown element. Skip the whole tag; resuming one byte
                // later would expose a fake inner tag a browser never sees.
                if(preg_match('~\G</?[a-z]~i', $html, $unused, 0, $start))
                {
                    // Quotes inside a tag NAME are ordinary name characters
                    // ("<meta"x>" ends at its first ">"); after the name,
                    // tagEnd() follows the HTML5 attribute states.
                    $nameStart = $start + ($html[$start + 1] === '/' ? 2 : 1);
                    $nameEnd = $nameStart + strcspn($html, "\t\n\f\r />", $nameStart);
                    if($nameEnd >= $length)
                    {
                        return;
                    }
                    $offset = $html[$nameEnd] === '>' ? $nameEnd + 1 : self::tagEnd($html, $nameEnd);
                    if($offset > $length)
                    {
                        return;
                    }
                    continue;
                }
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
                // WHATWG "script data escaped dash dash": "<!-->" and "<!--->"
                // return to script data immediately instead of escaping.
                if(preg_match('~\G-*>~', $html, $closing, 0, $offset))
                {
                    $offset += strlen($closing[0]);
                    continue;
                }
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

    /**
     * End of a tag whose name ends at $offset, following the HTML5 attribute
     * states: a quote opens a value ONLY after "=" (plus optional whitespace).
     * Anywhere else ("x"y", "/"x") a quote is an ordinary attribute-name
     * character, so it can never hide a ">" from the scanner but not a browser.
     *
     * @return int Offset after ">", or strlen + 1 when the tag never ends.
     */
    protected static function tagEnd(string $html, int $offset)
    {
        $length = strlen($html);
        $space = " \t\r\n\f";
        while($offset < $length)
        {
            $offset += strspn($html, $space.'/', $offset);
            if($offset >= $length)
            {
                break;
            }
            if($html[$offset] === '>')
            {
                return $offset + 1;
            }
            // Attribute name: a leading "=" and any quotes or "<" are name characters.
            $offset = self::attributeNameEnd($html, $offset, $length);
            $offset += strspn($html, $space, $offset);
            if($offset < $length && $html[$offset] === '=')
            {
                ++$offset;
                $offset += strspn($html, $space, $offset);
                if($offset < $length && ($html[$offset] === '"' || $html[$offset] === "'"))
                {
                    $close = strpos($html, $html[$offset], $offset + 1);
                    if($close === false)
                    {
                        return $length + 1;
                    }
                    $offset = $close + 1;
                }
                else
                {
                    $offset += strcspn($html, $space.'>', $offset);
                }
            }
        }
        return $length + 1;
    }

    /**
     * HTML5 attribute name: runs to whitespace, "/", ">" or "=", but a "="
     * in the first position belongs to the name.
     *
     * @return int
     */
    protected static function attributeNameEnd(string $html, int $offset, int $limit)
    {
        if($offset + 1 >= $limit)
        {
            return $limit;
        }
        return $offset + 1 + strcspn($html, " \t\r\n\f/>=", $offset + 1, $limit - $offset - 1);
    }

    /** @return array */
    protected static function attributes(string $html, int $offset, int $end)
    {
        $attributes = array();
        $space = " \t\r\n\f";
        while($offset < $end)
        {
            // Same HTML5 states as tagEnd(): "/" between attributes is skipped,
            // and quotes or "<" inside a name are name characters.
            $offset += strspn($html, $space.'/', $offset, $end - $offset);
            if($offset >= $end)
            {
                break;
            }
            $nameEnd = self::attributeNameEnd($html, $offset, $end);
            $name = strtolower(substr($html, $offset, $nameEnd - $offset));
            $offset = $nameEnd;
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
