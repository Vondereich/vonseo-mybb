<?php
/** Portable CLI verification used locally and by GitHub Actions. */
if(PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$mode = isset($argv[1]) ? $argv[1] : 'all';
if(!in_array($mode, array('all', 'lint', 'tests'), true))
{
    fwrite(STDERR, "Usage: php scripts/check.php [all|lint|tests]\n");
    exit(1);
}
function runCheck(string $arguments): void
{
    // Retain the runtime's configured extensions. On Linux, even ctype/json
    // may be shared modules; -n would silently remove the CI configuration.
    $command = escapeshellarg(PHP_BINARY).' '.$arguments;
    passthru($command, $code);
    if($code !== 0)
    {
        exit($code);
    }
}
if($mode !== 'tests')
{
    $count = 0;
    // Explicit source directories avoid scanning hidden clones, caches or vendor code.
    foreach(array('admin', 'inc', 'extras', 'tests', 'scripts') as $directory)
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
        foreach($files as $file)
        {
            if($file->isFile() && $file->getExtension() === 'php')
            {
                runCheck('-l '.escapeshellarg($file->getPathname()));
                ++$count;
            }
        }
    }
    echo 'PHP lint: '.$count.' files passed'.PHP_EOL;
}
if($mode !== 'lint')
{
    foreach(array('ctype_digit', 'json_encode', 'hash') as $requiredFunction)
    {
        if(!function_exists($requiredFunction))
        {
            fwrite(STDERR, 'Enable the PHP extension providing '.$requiredFunction.' before running tests.'.PHP_EOL);
            exit(1);
        }
    }
    runCheck(escapeshellarg($root.'/tests/run_tests.php'));
    runCheck(escapeshellarg($root.'/tests/redirect_targets.php'));
    runCheck(escapeshellarg($root.'/tests/acp_lists.php'));
    runCheck(escapeshellarg($root.'/tests/acp_import.php'));
    runCheck(escapeshellarg($root.'/tests/acp_redirect_inspector.php'));
    $cases = array();
    foreach(array('redirect_save', 'redirect_delete', 'redirects_import', 'notfound_clear', 'notfound_clear_all') as $action)
    {
        foreach(array('get', 'head', 'post', 'csrf') as $method)
        {
            $cases[] = array($action, $method);
        }
    }
    foreach(array('site_details_save', 'indexnow_rotate_key') as $action)
    {
        foreach(array('get', 'head', 'post', 'csrf', 'denied') as $method)
        {
            $cases[] = array($action, $method);
        }
    }
    $cases[] = array('site_details_save', 'invalid');
    $cases[] = array('site_details_save', 'sql');
    foreach(array('warnings', 'report', 'report_head', 'report_expired') as $mode)
    {
        $cases[] = array('redirects_import', $mode);
    }
    foreach(array('get', 'head', 'post', 'denied', 'invalid') as $mode)
    {
        $cases[] = array('redirect_inspect', $mode);
    }
    foreach($cases as $case)
    {
        runCheck(escapeshellarg($root.'/tests/acp_redirect_csrf.php').' '.escapeshellarg($case[0]).' '.escapeshellarg($case[1]));
    }
    echo 'ACP request matrix: '.count($cases).' cases passed'.PHP_EOL;
}
