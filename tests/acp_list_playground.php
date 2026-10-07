<?php
// Explicitly opted-in, disposable list fixtures for a local MyBB playground.
if(PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if($argc < 3 || !in_array($argv[2], array('seed', 'cleanup'), true))
{
    fwrite(STDERR, "Usage: php acp_list_playground.php /path/to/inc/config.php seed|cleanup [token]\n");
    exit(1);
}
require $argv[1];
$token = $argv[2] === 'seed' ? bin2hex(random_bytes(8)) : (isset($argv[3]) ? $argv[3] : '');
if(!preg_match('/^[a-f0-9]{16}$/D', $token)) { throw new InvalidArgumentException('Invalid fixture token.'); }
$database = $config['database'];
$tablePrefix = $database['table_prefix'];
if(!preg_match('/^[a-zA-Z0-9_]*$/D', $tablePrefix)) { throw new InvalidArgumentException('Invalid table prefix.'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = new mysqli($database['hostname'], $database['username'], $database['password'], $database['database']);
$connection->set_charset('utf8mb4');
$prefix = '/vonseo-list-probe-'.$token.'-';
$connection->begin_transaction();
try
{
    if($argv[2] === 'seed')
    {
        $redirect = $connection->prepare('INSERT INTO '.$tablePrefix.'vonseo_redirects (source_hash,source_path,target_url,status_code,enabled,hits,created_at,last_hit) VALUES (?,?,?,?,?,?,?,?)');
        $missing = $connection->prepare('INSERT INTO '.$tablePrefix.'vonseo_404_log (path_hash,path,hits,first_seen,last_seen) VALUES (?,?,?,?,?)');
        for($i = 1; $i <= 105; $i++)
        {
            $path = $prefix.str_pad((string)$i, 3, '0', STR_PAD_LEFT);
            $hash = hash('sha256', $path);
            $target = $i % 2 ? '/index.php' : '';
            $status = $i % 2 ? 301 : 410;
            $enabled = 0; // Fixtures never redirect visitors.
            $hits = $i;
            $time = time() - 105 + $i;
            $redirect->bind_param('sssiiiii', $hash, $path, $target, $status, $enabled, $hits, $time, $time);
            $redirect->execute();
            $missing->bind_param('ssiii', $hash, $path, $hits, $time, $time);
            $missing->execute();
        }
        echo "Seeded 105 disabled redirects and 105 missing paths. Cleanup token: ".$token."\n";
    }
    else
    {
        foreach(array('vonseo_redirects' => 'source_path', 'vonseo_404_log' => 'path') as $table => $column)
        {
            $statement = $connection->prepare('DELETE FROM '.$tablePrefix.$table.' WHERE '.$column.' LIKE ?');
            $pattern = $prefix.'%';
            $statement->bind_param('s', $pattern);
            $statement->execute();
            echo 'Removed '.$statement->affected_rows.' disposable '.$table." fixtures.\n";
        }
    }
    $connection->commit();
}
catch(Throwable $exception)
{
    $connection->rollback();
    throw $exception;
}
$connection->close();
