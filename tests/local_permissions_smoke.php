<?php
/** Explicit localhost playground test. Temporary staff/content are always cleaned up. */
if(PHP_SAPI !== 'cli' || !isset($argv[1]) || !in_array('--allow-temporary-data',$argv,true)) { exit(2); }
$board=realpath($argv[1]);
if(!$board || strcasecmp(str_replace('\\','/',$board),'C:/laragon/www/forum')!==0) { throw new RuntimeException('Only the authorized localhost/forum playground is supported'); }
define('IN_MYBB',1); define('IN_ADMINCP',1); define('NO_PLUGINS',1);
$_SERVER['REMOTE_ADDR']='127.0.0.1'; $_SERVER['SCRIPT_NAME']='/forum/admin/index.php';
require $board.'/inc/init.php'; require_once $board.'/inc/functions_user.php';
require_once $board.'/inc/datahandler.php'; require_once $board.'/inc/datahandlers/user.php';
$base='http://localhost/forum/'; $admin=$base.'admin/index.php?module=config-vonseo';
$token=bin2hex(random_bytes(6)); $username='vsqc_'.substr($token,0,10); $password='Aa1!'.bin2hex(random_bytes(10));
$uid=0; $forums=array(); $threads=array(); $posts=array(); $passed=0; $failed=0;
$originalFlag=$db->fetch_field($db->simple_select('settings','value',"name='vonseo_indexnow_enabled'"),'value');
function permCheck($condition,$label) {
    if($condition) { ++$GLOBALS['passed']; echo '[PASS] '.$label.PHP_EOL; }
    else { ++$GLOBALS['failed']; echo '[FAIL] '.$label.PHP_EOL; }
}
function permFingerprint($table,$where='') {
    global $db; $rows=array(); $query=$db->simple_select($table,'*',$where);
    while($row=$db->fetch_array($query)) { $rows[]=$row; }
    return hash('sha256',serialize($rows));
}
function permHttp($curl,$url,$data=null) {
    curl_setopt_array($curl,array(CURLOPT_URL=>$url,CURLOPT_POST=>$data!==null,CURLOPT_POSTFIELDS=>$data!==null?http_build_query($data,'','&'):'',
        CURLOPT_HTTPGET=>$data===null,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>4,
        CURLOPT_TIMEOUT=>15,CURLOPT_REFERER=>'http://localhost/forum/admin/index.php',CURLOPT_USERAGENT=>'VonSEO-isolated-permission-smoke'));
    // Set method last: CURLOPT_HTTPGET=false must not change a POST's shape.
    if($data!==null) { curl_setopt($curl,CURLOPT_POST,true); } else { curl_setopt($curl,CURLOPT_HTTPGET,true); }
    $body=curl_exec($curl);
    if($body===false) { throw new RuntimeException('Local HTTP test failed: '.curl_error($curl)); }
    if(preg_match('/Fatal error|Warning:|Uncaught .*Exception/i',$body)) { throw new RuntimeException('PHP error in local HTTP test'); }
    return array('body'=>$body,'status'=>curl_getinfo($curl,CURLINFO_HTTP_CODE));
}
function permSet($uid,$basic,$seo,$site) {
    global $db;
    $permissions=array('config'=>array('vonseo'=>$basic,'vonseo_overrides'=>$seo,'vonseo_settings'=>$site,'settings'=>0));
    $db->update_query('adminoptions',array('permissions'=>$db->escape_string(my_serialize($permissions))),"uid='".(int)$uid."'");
}
function permEscapeRow(array $row) { global $db; foreach($row as &$value) { if(is_string($value)) { $value=$db->escape_string($value); } } unset($value); return $row; }
$curl=curl_init(); curl_setopt($curl,CURLOPT_COOKIEFILE,'');
try {
    $db->update_query('settings',array('value'=>'0'),"name='vonseo_indexnow_enabled'"); rebuild_settings();
    $mybb->user=array('uid'=>0);
    $handler=new UserDataHandler('insert');
    $handler->set_data(array('username'=>$username,'password'=>$password,'password2'=>$password,'email'=>$username.'@example.invalid',
        'email2'=>$username.'@example.invalid','usergroup'=>4,'additionalgroups'=>'','displaygroup'=>0,'usertitle'=>'Disposable permission tester',
        'timezone'=>'+8','language'=>'english','options'=>array('hideemail'=>1,'receivepms'=>0),'user_fields'=>array()));
    if(!$handler->validate_user()) { throw new RuntimeException('Temporary user validation failed: '.implode(',',array_keys($handler->get_errors()))); }
    $user=$handler->insert_user(); $uid=(int)$user['uid'];
    $db->insert_query('adminoptions',array('uid'=>$uid,'permissions'=>$db->escape_string(my_serialize(array('config'=>array('vonseo'=>1,'vonseo_overrides'=>0,'vonseo_settings'=>0,'settings'=>0)))),
        'notes'=>'Disposable VonSEO QC','authsecret'=>'','recovery_codes'=>'','defaultviews'=>'','cpstyle'=>'','cplanguage'=>''));
    // Create only named test forums/threads/posts. Never reuse an existing entity for writes.
    $forumTemplate=$db->fetch_array($db->simple_select('forums','*',"fid='2'"));
    $threadTemplate=$db->fetch_array($db->simple_select('threads','*',"tid='16'"));
    $postTemplate=$db->fetch_array($db->simple_select('posts','*',"pid='".(int)$threadTemplate['firstpost']."'"));
    if(!$forumTemplate || !$threadTemplate || !$postTemplate) { throw new RuntimeException('Expected public playground templates are missing'); }
    foreach(array('public','private','password','unapproved','inactive') as $kind) {
        $forum=$forumTemplate; unset($forum['fid']);
        $forum=array_merge($forum,array('name'=>'VonSEO QC '.$kind.' '.$token,'description'=>'Disposable '.$kind.' forum','pid'=>1,
            'threads'=>1,'posts'=>1,'unapprovedthreads'=>0,'unapprovedposts'=>0,'deletedthreads'=>0,'deletedposts'=>0,
            'active'=>$kind==='inactive'?0:1,'password'=>$kind==='password'?'qc-only-password':'','lastpost'=>0,'lastposter'=>'','lastposteruid'=>0,'lastposttid'=>0,'lastpostsubject'=>''));
        $fid=(int)$db->insert_query('forums',permEscapeRow($forum)); $forums[$kind]=$fid;
        $db->update_query('forums',array('parentlist'=>'1,'.$fid),"fid='{$fid}'");
        if($kind==='private') { $db->insert_query('forumpermissions',array('fid'=>$fid,'gid'=>1,'canview'=>0,'canviewthreads'=>0)); }
        $thread=$threadTemplate; unset($thread['tid']);
        $thread=array_merge($thread,array('fid'=>$fid,'subject'=>'VonSEO QC '.$kind.' thread '.$token,'uid'=>$uid,'username'=>$username,
            'visible'=>$kind==='unapproved'?0:1,'firstpost'=>0,'replies'=>0,'views'=>0,'closed'=>'','lastposter'=>$username,'lastposteruid'=>$uid));
        $tid=(int)$db->insert_query('threads',permEscapeRow($thread)); $threads[$kind]=$tid;
        $post=$postTemplate; unset($post['pid']);
        $post=array_merge($post,array('tid'=>$tid,'fid'=>$fid,'uid'=>$uid,'username'=>$username,'subject'=>$thread['subject'],
            'message'=>'Disposable VonSEO QC content '.$kind.' '.$token,'ipaddress'=>'','visible'=>$kind==='unapproved'?0:1));
        $pid=(int)$db->insert_query('posts',permEscapeRow($post)); $posts[]=$pid;
        $db->update_query('threads',array('firstpost'=>$pid),"tid='{$tid}'");
    }
    $cache->update_forums(); $cache->update_forumpermissions();
    $login=permHttp($curl,$base.'admin/index.php',array('do'=>'login','username'=>$username,'password'=>$password));
    permCheck(strpos($login['body'],$username)!==false && strpos($login['body'],'Logged in as')!==false,'Real ACP login as temporary non-super-admin');
    $identityBefore=permFingerprint('settings'); $queueBefore=permFingerprint('vonseo_indexnow_queue');
    $draft=array('entity_type'=>'thread','entity_id'=>$threads['public'],'seo_page'=>'1','seo_title'=>"QC O'Brien <script>literal</script>",
        'seo_description'=>'Disposable draft description','seo_image'=>'','index_mode'=>'inherit');
    // A valid token from a page the restricted account may access.
    $overview=permHttp($curl,$admin);
    if(!preg_match('/name="my_post_key"[^>]*value="([a-f0-9]{32})"/',$overview['body'],$match)) {
        preg_match('/my_post_key=([a-f0-9]{32})/',$overview['body'],$match);
    }
    if(empty($match[1])) { throw new RuntimeException('Could not obtain test-account token from its own ACP'); }
    $draft['my_post_key']=$match[1];
    permCheck(strpos($overview['body'],'VonSEO')!==false && strpos($overview['body'],'name="site_name"')===false,'Basic permission has overview but no identity editor');
    foreach(array('seo','seo_preview','seo_save','seo_reset','site_details_save','indexnow_rotate_key') as $action) {
        $response=permHttp($curl,$admin.'&action='.$action,$action==='seo'?null:$draft);
        permCheck(strpos($response['body'],'Access Denied')!==false && strpos($response['body'],'name="seo_title"')===false,'Basic permission denies direct '.$action);
    }
    permCheck($identityBefore===permFingerprint('settings') && $queueBefore===permFingerprint('vonseo_indexnow_queue'),'Denied sensitive requests do not change identity/key or queue');
    permSet($uid,0,1,0);
    $response=permHttp($curl,$admin.'&action=seo&entity_type=thread&entity_id='.$threads['public']);
    permCheck(strpos($response['body'],'Access Denied')!==false,'Override permission without base module access is denied');
    permSet($uid,1,1,0);
    $response=permHttp($curl,$admin.'&action=seo&entity_type=thread&entity_id='.$threads['public']);
    permCheck(strpos($response['body'],'name="seo_title"')!==false,'Base plus override permission opens public editor');
    foreach(array('private','password','unapproved','inactive') as $kind) {
        $response=permHttp($curl,$admin.'&action=seo&entity_type=thread&entity_id='.$threads[$kind]);
        permCheck(strpos($response['body'],'guest-inaccessible')!==false && strpos($response['body'],'name="seo_title"')===false &&
            strpos($response['body'],'VonSEO QC '.$kind.' thread '.$token)===false,'Real account inspector excludes '.$kind.' content');
    }
    $beforeOverrides=permFingerprint('vonseo_overrides');
    $preview=permHttp($curl,$admin.'&action=seo_preview',$draft);
    permCheck(strpos($preview['body'],'Unsaved draft')!==false && strpos($preview['body'],'<script>literal</script>')===false &&
        $beforeOverrides===permFingerprint('vonseo_overrides'),'Authorized preview is escaped and makes no override write');
    $invalid=$draft; $invalid['my_post_key']='invalid';
    permHttp($curl,$admin.'&action=seo_save',$invalid);
    permCheck($beforeOverrides===permFingerprint('vonseo_overrides'),'Real invalid-CSRF save is rejected without mutation');
    $draft['index_mode']='noindex';
    $save=permHttp($curl,$admin.'&action=seo_save',$draft);
    $row=$db->fetch_array($db->simple_select('vonseo_overrides','*',"entity_type='thread' AND entity_id='".$threads['public']."'"));
    permCheck($row && $row['seo_title']==="QC O'Brien literal" && $row['index_mode']==='noindex' && (int)$row['updated_by']===$uid,'Authorized save preserves apostrophe and normalized non-executable title under test account');
    $guest=curl_init();
    $page=permHttp($guest,$base.'showthread.php?tid='.$threads['public']);
    permCheck(strpos($page['body'],'name="robots" content="noindex')!==false && strpos($page['body'],'rel="canonical"')===false,'Real guest page applies saved noindex');
    $xml=permHttp($guest,$base.'misc.php?action=vonseo_sitemap&type=threads&page=1');
    $document=new DOMDocument(); $document->loadXML($xml['body']); $locs=array();
    foreach($document->getElementsByTagName('loc') as $node) { $locs[]=$node->textContent; }
    foreach($threads as $kind=>$tid) {
        permCheck(!preg_match('/(?:tid=|\/t-)'.$tid.'(?:[&\-]|$)/',implode("\n",$locs)), 'Guest sitemap excludes '.$kind.' temporary thread under effective policy');
    }
    curl_close($guest);
    $reset=permHttp($curl,$admin.'&action=seo_reset',$draft);
    $row=$db->fetch_array($db->simple_select('vonseo_overrides','*',"entity_type='thread' AND entity_id='".$threads['public']."'"));
    permCheck($row && $row['seo_title']==='' && $row['index_mode']==='inherit','Authorized reset restores automatic policy');
    $forumDraft=$draft; $forumDraft['entity_type']='forum'; $forumDraft['entity_id']=$forums['public'];
    $forumDraft['seo_title']=''; $forumDraft['seo_description']=''; $forumDraft['index_mode']='noindex';
    permHttp($curl,$admin.'&action=seo_save',$forumDraft);
    $guest=curl_init(); $page=permHttp($guest,$base.'showthread.php?tid='.$threads['public']);
    permCheck(strpos($page['body'],'name="robots" content="noindex')!==false,'Real containing-forum noindex restricts direct thread output');
    permHttp($curl,$admin.'&action=seo_reset',$draft);
    $page=permHttp($guest,$base.'showthread.php?tid='.$threads['public']);
    permCheck(strpos($page['body'],'name="robots" content="noindex')!==false,'Thread reset cannot override a containing forum noindex');
    permHttp($curl,$admin.'&action=seo_reset',$forumDraft);
    $page=permHttp($guest,$base.'showthread.php?tid='.$threads['public']);
    permCheck(strpos($page['body'],'rel="canonical"')!==false && strpos($page['body'],'name="robots" content="noindex')===false,'Forum reset restores ordinary guest indexing');
    curl_close($guest);
    permSet($uid,1,0,1);
    $overview=permHttp($curl,$admin);
    $denied=permHttp($curl,$admin.'&action=seo&entity_type=thread&entity_id='.$threads['public']);
    permCheck(strpos($overview['body'],'name="site_name"')!==false && strpos($denied['body'],'Access Denied')!==false,
        'Dedicated Site Details permission does not implicitly grant SEO override access');
    permCheck($identityBefore===permFingerprint('settings') && $queueBefore===permFingerprint('vonseo_indexnow_queue'),'Override scope cannot change site settings or original queue');
} finally {
    // Exact IDs returned by this run, never unbounded wildcard cleanup.
    if($threads) {
        $ids=implode(',',array_map('intval',array_values($threads)));
        $db->delete_query('vonseo_overrides',"entity_type='thread' AND entity_id IN ({$ids})");
        $db->delete_query('vonseo_indexnow_queue',"tid IN ({$ids})");
        $db->delete_query('vonseo_indexnow_threads',"tid IN ({$ids})");
        $db->delete_query('posts',"tid IN ({$ids})"); $db->delete_query('threads',"tid IN ({$ids})");
    }
    if($forums) {
        $ids=implode(',',array_map('intval',array_values($forums)));
        $db->delete_query('vonseo_overrides',"entity_type='forum' AND entity_id IN ({$ids})");
        $db->delete_query('forumpermissions',"fid IN ({$ids})"); $db->delete_query('forums',"fid IN ({$ids})");
        $cache->update_forums(); $cache->update_forumpermissions();
    }
    if($uid>0) {
        $check=$db->fetch_array($db->simple_select('users','uid,username',"uid='{$uid}'"));
        if(!$check || $check['username']!==$username) { throw new RuntimeException('Temporary account ownership check failed'); }
        $mybb->user=array('uid'=>0);
        $deleter=new UserDataHandler('delete'); $deleter->delete_user(array($uid));
        $db->delete_query('adminsessions',"uid='{$uid}'"); $db->delete_query('adminlog',"uid='{$uid}'");
    }
    $db->update_query('settings',array('value'=>$db->escape_string((string)$originalFlag)),"name='vonseo_indexnow_enabled'"); rebuild_settings();
    curl_close($curl);
}
echo 'Local restricted-account smoke: '.$passed.' passed, '.$failed.' failed; test account/content removed, IndexNow restored'.PHP_EOL;
exit($failed?1:0);
