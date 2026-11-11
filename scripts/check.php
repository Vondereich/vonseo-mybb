<?php
/** Public-source syntax and encoding checks. No forum or database is loaded. */
if(PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$mode = isset($argv[1]) ? $argv[1] : 'all';
if(!in_array($mode, array('all', 'lint', 'encoding'), true))
{
    fwrite(STDERR, "Usage: php scripts/check.php [all|lint|encoding]\n");
    exit(1);
}
$files = array();
foreach(array('admin', 'inc', 'extras', 'scripts', '.github/workflows') as $directory)
{
    if(!is_dir($root.'/'.$directory)) { continue; }
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach($entries as $file)
    {
        if(!$file->isFile()) { continue; }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        // Optional local IDE material is neither public source nor runtime code.
        if($relative === 'extras/ide_stubs.php') { continue; }
        if(in_array(strtolower($file->getExtension()), array('php', 'html', 'txt', 'conf', 'ps1', 'yml', 'yaml'), true))
        {
            $files[$relative] = $file->getPathname();
        }
    }
}
foreach(array('README.md', 'CHANGELOG.md', 'LICENSE.txt', 'NOTICE.txt', '.gitignore') as $relative)
{
    if(is_file($root.'/'.$relative)) { $files[$relative] = $root.'/'.$relative; }
}
ksort($files);
if($mode !== 'lint')
{
    $errors = array();
    foreach($files as $relative => $path)
    {
        $bytes = file_get_contents($path);
        foreach(array("\x00\x00\xFE\xFF", "\xFF\xFE\x00\x00", "\xEF\xBB\xBF", "\xFF\xFE", "\xFE\xFF") as $bom)
        {
            if(strncmp($bytes, $bom, strlen($bom)) === 0)
            {
                $errors[] = $relative.': byte order mark';
                break;
            }
        }
        if(preg_match('//u', $bytes) !== 1) { $errors[] = $relative.': invalid UTF-8'; }
        if(strpos($bytes, "\0") !== false) { $errors[] = $relative.': literal NUL byte'; }
        if(substr($relative, -4) === '.php')
        {
            if(strncmp($bytes, '<?php', 5) !== 0) { $errors[] = $relative.': output before PHP opening tag'; }
            if(preg_match('/\?>\s*$/D', $bytes)) { $errors[] = $relative.': unnecessary PHP closing tag'; }
        }
    }
    if($errors)
    {
        fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL);
        exit(1);
    }
    echo 'Source encoding: '.count($files).' files passed (UTF-8, no BOM/NUL or PHP boundary output)'.PHP_EOL;
}
if($mode !== 'encoding')
{
    $count = 0;
    foreach($files as $relative => $path)
    {
        if(substr($relative, -4) !== '.php') { continue; }
        // Keep the runtime's configured extensions; this also works with Linux
        // distributions that package standard extensions as shared modules.
        $command = escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path);
        passthru($command, $code);
        if($code !== 0)
        {
            exit($code);
        }
        ++$count;
    }
    echo 'PHP lint: '.$count.' public-source files passed'.PHP_EOL;
}
