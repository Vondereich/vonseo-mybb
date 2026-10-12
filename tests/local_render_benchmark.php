<?php
/** Read-only native MyBB/SQL render benchmark; source root selects the compared release. */
if(PHP_SAPI !== 'cli' || !isset($argv[1],$argv[2],$argv[3])) { exit(2); }
$board=realpath($argv[1]); $source=realpath($argv[2]); $tid=(int)$argv[3];
$iterations=isset($argv[4]) ? max(30,min(500,(int)$argv[4])) : 150;
if(!$board || !$source || $tid<=0 || strcasecmp(str_replace('\\','/',$board),'C:/laragon/www/forum')!==0 || !is_file($source.'/inc/plugins/vonseo/Core.php')) { exit(2); }
define('IN_MYBB',1); define('NO_PLUGINS',1); define('THIS_SCRIPT','showthread.php');
$_SERVER['SCRIPT_NAME']='/forum/showthread.php'; $_SERVER['REQUEST_METHOD']='GET';
require $board.'/inc/init.php';
foreach(array('Utils','State','Url','Html','Overrides','Keyword','Context','Robots','Social','Schema','Meta','Sitemap','Redirects','IndexNow','Errors','Core') as $class) {
    if(is_file($source.'/inc/plugins/vonseo/'.$class.'.php')) { require_once $source.'/inc/plugins/vonseo/'.$class.'.php'; }
}
$mybb->user=array('uid'=>0); $mybb->input=array('tid'=>$tid);
$thread=get_thread($tid); $forum=get_forum((int)$thread['fid']);
if(!$thread || !$forum || !(new VonSEO_Context(new VonSEO_Url()))->isForumPublic($forum) || (int)$thread['visible']!==1) { throw new RuntimeException('Expected an approved guest-public thread'); }
$post=$db->fetch_array($db->simple_select('posts','pid,tid,visible,message',"pid='".(int)$thread['firstpost']."'"));
$post['message']='<p>Read-only benchmark guest-rendered text.</p>';
$cacheSnapshot=$cache->cache; $settingsSnapshot=$mybb->settings;
$entities=array(); $query=$db->simple_select('threads','tid,subject',"visible='1'",array('limit'=>20));
while($row=$db->fetch_array($query)) { $entities[]=$row; }
if(!$entities) { throw new RuntimeException('Expected at least one approved thread anchor target'); }
function renderPercentile(array $values,$fraction) { sort($values); return $values[(int)floor((count($values)-1)*$fraction)]; }
$results=array();
foreach(array(0,1) as $keywords) {
    foreach(array(0,500) as $links) {
        $durations=array(); $queries=array(); $peakExtra=0;
        $body='<html><head><title>Native benchmark</title></head><body><p>Benchmark article</p>';
        for($i=0;$i<$links;++$i) { $entity=$entities[$i%count($entities)]; $body.='<a href="showthread.php?tid='.(int)$entity['tid'].'">'.htmlspecialchars($entity['subject'],ENT_QUOTES,'UTF-8').'</a>'; }
        $body.='</body></html>';
        for($i=0;$i<$iterations+10;++$i) {
            $mybb->settings=$settingsSnapshot; $mybb->settings['vonseo_keyword_urls']=$keywords;
            $cache->cache=$cacheSnapshot; VonSEO_State::resetRuntime();
            if(class_exists('VonSEO_Overrides')) { VonSEO_Overrides::resetRuntime(); }
            VonSEO_Context::captureGuestFirstPost($post);
            $before=$db->query_count; $memory=memory_get_usage(); $start=microtime(true);
            $output=(new VonSEO_Core())->render($body);
            if($keywords) { $output=(new VonSEO_Keyword(new VonSEO_Url()))->rewriteHtml($output,'showthread.php'); }
            $elapsed=(microtime(true)-$start)*1000;
            if($i>=10) { $durations[]=$elapsed; $queries[]=$db->query_count-$before; $peakExtra=max($peakExtra,memory_get_usage()-$memory); }
        }
        $results[]=array('keywords'=>$keywords,'internal_links'=>$links,'distinct_thread_targets'=>$links ? min($links,count($entities)) : 0,'iterations'=>$iterations,
            'median_ms'=>round(renderPercentile($durations,.5),3),'p95_ms'=>round(renderPercentile($durations,.95),3),
            'queries_per_render'=>array_values(array_unique($queries)),'retained_memory_delta_bytes'=>$peakExtra);
    }
}
echo json_encode(array('version'=>VONSEO_VERSION,'runtime'=>PHP_VERSION,'database'=>$db->get_version(),
    'scope'=>'Warm native Guest metadata rendering + optional keyword anchor rewriting. Native MyBB helper caches are warmed; VonSEO runtime caches reset per render. The 500 anchors repeat the available thread targets, not 500 distinct threads. Excludes full HTTP/template/DB bootstrap, cold OS caches and concurrent load.',
    'results'=>$results),JSON_PRETTY_PRINT).PHP_EOL;
