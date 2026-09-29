<?php
/**
 * Read-only guest smoke test for an installed MyBB board using query URLs.
 *
 * Usage: php tests/live_query_url_smoke.php http://localhost/forum/ 4 1
 * The forum and thread IDs must be publicly visible to guests. This script
 * sends GET requests only; it does not log in, post, or write to the database.
 */

if(PHP_SAPI !== 'cli' || count($argv) < 4)
{
    fwrite(STDERR, "Usage: php tests/live_query_url_smoke.php <board-url> <public-fid> <public-tid> [--skip-indexnow]\n");
    exit(2);
}

$base = rtrim($argv[1], '/').'/';
$fid = $argv[2];
$tid = $argv[3];
$skipIndexNow = in_array('--skip-indexnow', $argv, true);
$parts = parse_url($base);

if(!filter_var($base, FILTER_VALIDATE_URL)
    || !in_array(isset($parts['scheme']) ? strtolower($parts['scheme']) : '', array('http', 'https'), true)
    || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])
    || !ctype_digit($fid) || (int)$fid < 1 || !ctype_digit($tid) || (int)$tid < 1)
{
    fwrite(STDERR, "Invalid board URL or fixture IDs.\n");
    exit(2);
}

if(!class_exists('DOMDocument'))
{
    fwrite(STDERR, "The PHP DOM extension is required for sitemap checks.\n");
    exit(2);
}

$checks = 0;
$failures = 0;

function smokeCheck($name, $ok, $detail = '')
{
    global $checks, $failures;
    $checks++;
    if(!$ok)
    {
        $failures++;
    }
    echo ($ok ? 'PASS ' : 'FAIL ').$name.($ok || $detail === '' ? '' : ' - '.$detail)."\n";
}

function smokeGet($url)
{
    $context = stream_context_create(array('http' => array(
        'method' => 'GET',
        'header' => "Accept: text/html, application/xml, text/plain\r\nUser-Agent: VonSEO-readonly-smoke/1.0\r\n",
        'timeout' => 10,
        'ignore_errors' => true,
        'follow_location' => 0
    )));
    $stream = @fopen($url, 'rb', false, $context);
    $body = '';
    $rawHeaders = array();
    if(is_resource($stream))
    {
        $metadata = stream_get_meta_data($stream);
        $rawHeaders = !empty($metadata['wrapper_data']) && is_array($metadata['wrapper_data'])
            ? $metadata['wrapper_data'] : array();
        $body = stream_get_contents($stream);
        fclose($stream);
    }
    $headers = array();
    foreach($rawHeaders as $line)
    {
        $colon = strpos($line, ':');
        if($colon !== false)
        {
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
    }
    $status = isset($rawHeaders[0]) && preg_match('~^HTTP/\S+\s+(\d{3})~', $rawHeaders[0], $match)
        ? (int)$match[1] : 0;
    return array('status' => $status, 'headers' => $headers, 'body' => $body === false ? '' : $body);
}

function smokeHeader($response, $name)
{
    $name = strtolower($name);
    return isset($response['headers'][$name]) ? $response['headers'][$name] : '';
}

function smokeResolvePublicUrl($response, $requestedUrl, $base)
{
    if($response['status'] === 200)
    {
        return array('response' => $response, 'url' => $requestedUrl, 'valid' => true);
    }

    $location = smokeHeader($response, 'Location');
    if(!in_array($response['status'], array(301, 302), true)
        || $location === '' || strpos($location, $base) !== 0)
    {
        return array('response' => $response, 'url' => $requestedUrl, 'valid' => false);
    }

    return array('response' => smokeGet($location), 'url' => $location, 'valid' => true);
}

function smokeCanonical($html)
{
    if(preg_match('/<link rel="canonical" href="([^"]+)"/i', $html, $match))
    {
        return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    }
    return '';
}

function smokeXmlLocs($xml)
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $valid = $document->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if(!$valid)
    {
        return false;
    }
    $xpath = new DOMXPath($document);
    $locs = array();
    foreach($xpath->query('//*[local-name()="loc"]') as $node)
    {
        $locs[] = trim($node->textContent);
    }
    return $locs;
}

$home = smokeGet($base);
$forumUrl = $base.'forumdisplay.php?fid='.(int)$fid;
$threadUrl = $base.'showthread.php?tid='.(int)$tid;
$forumResult = smokeResolvePublicUrl(smokeGet($forumUrl), $forumUrl, $base);
$threadResult = smokeResolvePublicUrl(smokeGet($threadUrl), $threadUrl, $base);
$forum = $forumResult['response'];
$thread = $threadResult['response'];
$forumCanonical = $forumResult['url'];
$threadCanonical = $threadResult['url'];

foreach(array(
    'home' => array($home, $base, true),
    'forum' => array($forum, $forumCanonical, $forumResult['valid']),
    'thread' => array($thread, $threadCanonical, $threadResult['valid'])
) as $name => $fixture)
{
    $response = $fixture[0];
    $expected = $fixture[1];
    smokeCheck($name.' public route resolves', $fixture[2] && $response['status'] === 200, 'HTTP '.$response['status']);
    smokeCheck($name.' one canonical', substr_count($response['body'], '<link rel="canonical"') === 1);
    smokeCheck($name.' canonical URL', smokeCanonical($response['body']) === $expected, smokeCanonical($response['body']));
}

$headStart = stripos($thread['body'], '<head>');
$headEnd = stripos($thread['body'], '</head>');
$marker = strpos($thread['body'], '<!-- VonSEO -->');
smokeCheck('thread SEO block inside head', $headStart !== false && $headEnd !== false && $marker !== false
    && $headStart < $marker && $marker < $headEnd);
smokeCheck('thread OG URL matches canonical', strpos($thread['body'], '<meta property="og:url" content="'.$threadCanonical.'"') !== false);
$schemaValid = false;
if(preg_match('~<script type="application/ld\+json" class="vonseo-schema">(.*?)</script>~s', $thread['body'], $match))
{
    $schema = json_decode($match[1], true);
    $schemaValid = is_array($schema) && isset($schema['@graph']);
}
smokeCheck('thread JSON-LD parses', $schemaValid);

$sitemapUrl = $base.'misc.php?action=vonseo_sitemap';
$index = smokeGet($sitemapUrl);
$forums = smokeGet($sitemapUrl.'&type=forums');
$threads = smokeGet($sitemapUrl.'&type=threads&page=1');
$outOfRange = smokeGet($sitemapUrl.'&type=threads&page=2147483647');
$indexLocs = smokeXmlLocs($index['body']);
$forumLocs = smokeXmlLocs($forums['body']);
$threadLocs = smokeXmlLocs($threads['body']);
$outOfRangeLocs = smokeXmlLocs($outOfRange['body']);

smokeCheck('sitemap index HTTP/XML', $index['status'] === 200 && stripos(smokeHeader($index, 'Content-Type'), 'application/xml') === 0 && $indexLocs !== false);
smokeCheck('sitemap index lists forum and thread pages', is_array($indexLocs)
    && in_array($sitemapUrl.'&type=forums', $indexLocs, true)
    && in_array($sitemapUrl.'&type=threads&page=1', $indexLocs, true));
smokeCheck('forum sitemap contains public forum', $forums['status'] === 200 && is_array($forumLocs)
    && in_array($forumCanonical, $forumLocs, true));
smokeCheck('thread sitemap contains public thread', $threads['status'] === 200 && is_array($threadLocs)
    && in_array($threadCanonical, $threadLocs, true));
smokeCheck('out-of-range sitemap page is 404 empty XML', $outOfRange['status'] === 404
    && is_array($outOfRangeLocs) && count($outOfRangeLocs) === 0
    && stripos(smokeHeader($outOfRange, 'X-Robots-Tag'), 'noindex') !== false);

$robots = smokeGet($base.'misc.php?action=vonseo_robots');
smokeCheck('robots endpoint advertises sitemap', $robots['status'] === 200
    && stripos(smokeHeader($robots, 'Content-Type'), 'text/plain') === 0
    && strpos($robots['body'], 'Sitemap: '.$sitemapUrl) !== false);
smokeCheck('robots endpoint itself is noindex', stripos(smokeHeader($robots, 'X-Robots-Tag'), 'noindex') !== false);

if(!$skipIndexNow)
{
    $key = smokeGet($base.'misc.php?action=vonseo_indexnow_key');
    smokeCheck('IndexNow verification endpoint', $key['status'] === 200
        && stripos(smokeHeader($key, 'Content-Type'), 'text/plain') === 0
        && preg_match('/^[A-Za-z0-9-]{8,128}$/', $key['body']) === 1);
    smokeCheck('IndexNow key response is noindex', stripos(smokeHeader($key, 'X-Robots-Tag'), 'noindex') !== false);
}

echo $checks.' checks, '.$failures." failures\n";
exit($failures ? 1 : 0);
