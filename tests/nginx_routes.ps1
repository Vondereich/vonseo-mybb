param(
    [Parameter(Mandatory = $true)][string]$Nginx,
    [Parameter(Mandatory = $true)][string]$PhpCgi,
    [int]$RootPort = 19081,
    [int]$SubfolderPort = 19082,
    [int]$FastCgiPort = 19083
)

# Isolated route fixtures, not a live MyBB deployment. Never touches port 80,
# the user's Nginx configuration, forum data, or an existing server process.
$ErrorActionPreference = 'Stop'
foreach($binary in @($Nginx, $PhpCgi))
{
    if(!(Test-Path -LiteralPath $binary -PathType Leaf)) { throw "Missing binary: $binary" }
}
$ports = @($RootPort, $SubfolderPort, $FastCgiPort)
if(($ports | Select-Object -Unique).Count -ne 3) { throw 'Choose three different ports.' }
foreach($port in $ports)
{
    if($port -lt 1024 -or $port -gt 65535) { throw 'Test ports must be between 1024 and 65535.' }
    $listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Loopback, $port)
    try { $listener.Start() } finally { $listener.Stop() }
}
$project = Split-Path -Parent $PSScriptRoot
$tempRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath()).TrimEnd('\')
$work = Join-Path $tempRoot ('vonseo-nginx-' + [guid]::NewGuid().ToString('N'))
$work = [System.IO.Path]::GetFullPath($work)
if([System.IO.Path]::GetDirectoryName($work).TrimEnd('\') -ne $tempRoot) { throw 'Invalid temporary path.' }
[void](New-Item -ItemType Directory -Path $work)
$utf8 = New-Object System.Text.UTF8Encoding($false)
$processes = @()
$passed = 0
try
{
    foreach($directory in @('logs', 'temp', 'www', 'www/forum', 'www/assets', 'www/forum/assets'))
    {
        [void](New-Item -ItemType Directory -Path (Join-Path $work $directory) -Force)
    }
    $fixture = @'
<?php
header('Content-Type: application/json');
echo json_encode(array('query' => $_GET, 'script' => $_SERVER['SCRIPT_NAME'],
    'uri' => $_SERVER['REQUEST_URI'], 'method' => $_SERVER['REQUEST_METHOD']));
'@
    [System.IO.File]::WriteAllText((Join-Path $work 'www/probe.php'), $fixture, $utf8)
    foreach($file in @('www/assets/probe.txt', 'www/assets/index.html', 'www/forum/assets/probe.txt', 'www/forum/assets/index.html'))
    {
        [System.IO.File]::WriteAllText((Join-Path $work $file), 'existing-file', $utf8)
    }
    $prefix = $work.Replace('\', '/') + '/'
    $documentRoot = $prefix + 'www'
    $fastCgiParams = (Join-Path (Split-Path -Parent $Nginx) 'conf/fastcgi_params').Replace('\', '/')
    $servers = ''
    foreach($pair in @(@($RootPort, 'nginx-vonseo.conf'), @($SubfolderPort, 'nginx-vonseo-subfolder.conf')))
    {
        $snippet = (Join-Path $project ('extras/' + $pair[1])).Replace('\', '/')
        $servers += @"
server {
    listen 127.0.0.1:$($pair[0]);
    server_name localhost;
    root "$documentRoot";
    include "$snippet";
    location ~ \.php$ {
        include "$fastCgiParams";
        fastcgi_param SCRIPT_FILENAME "$documentRoot/probe.php";
        fastcgi_pass 127.0.0.1:$FastCgiPort;
    }
}
"@
    }
    $config = "worker_processes 1;`npid logs/nginx.pid;`nerror_log logs/error.log;`nevents { worker_connections 64; }`nhttp { access_log off; $servers }"
    [System.IO.File]::WriteAllText((Join-Path $work 'nginx.conf'), $config, $utf8)
    $syntax = Start-Process -FilePath $Nginx -ArgumentList @('-p', ('"' + $prefix + '"'), '-c', 'nginx.conf', '-t') -Wait -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $work 'syntax.log')
    if($syntax.ExitCode -ne 0) { throw (Get-Content -LiteralPath (Join-Path $work 'syntax.log') -Raw) }
    $processes += Start-Process -FilePath $PhpCgi -ArgumentList @('-b', "127.0.0.1:$FastCgiPort", '-n') -PassThru -WindowStyle Hidden
    $processes += Start-Process -FilePath $Nginx -ArgumentList @('-p', ('"' + $prefix + '"'), '-c', 'nginx.conf') -PassThru -WindowStyle Hidden
    foreach($port in $ports)
    {
        $ready = $false
        for($attempt = 0; $attempt -lt 30; $attempt++)
        {
            $client = New-Object System.Net.Sockets.TcpClient
            try { $client.Connect('127.0.0.1', $port); $ready = $true } catch { Start-Sleep -Milliseconds 100 } finally { $client.Dispose() }
            if($ready) { break }
        }
        if(!$ready) { throw "Test listener did not start: $port" }
    }

    function Check-Route([int]$port, [string]$path, [string]$script, [hashtable]$query, [string]$method = 'GET')
    {
        $response = Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$port$path" -Method $method -TimeoutSec 10
        $actual = $response.Content | ConvertFrom-Json
        if($actual.script -ne $script -or $actual.uri -ne $path -or $actual.method -ne $method) { throw "Identity mismatch: $path ($($response.Content))" }
        $fields = @($actual.query.PSObject.Properties)
        if($fields.Count -ne $query.Count) { throw "Unexpected query fields: $path ($($response.Content))" }
        foreach($key in $query.Keys)
        {
            if([string]$actual.query.$key -ne [string]$query[$key]) { throw "Query mismatch: $path ($($response.Content))" }
        }
        $script:passed++
    }
    foreach($pair in @(@($RootPort, ''), @($SubfolderPort, '/forum')))
    {
        $port = [int]$pair[0]
        $base = $pair[1]
        $poison = '?tid=999&fid=999&pid=999&page=99&action=login'
        Check-Route $port "$base/t-12-a-topic$poison" "$base/showthread.php" @{tid = 12}
        Check-Route $port "$base/t-12-a-topic--p3$poison" "$base/showthread.php" @{tid = 12; page = 3}
        Check-Route $port "$base/t-12-a-topic--post-45$poison" "$base/showthread.php" @{tid = 12; pid = 45}
        foreach($action in @('lastpost', 'newpost', 'nextnewest', 'nextoldest'))
        {
            Check-Route $port "$base/t-12-a-topic--$action$poison" "$base/showthread.php" @{tid = 12; action = $action}
        }
        Check-Route $port "$base/f-9-a-forum$poison" "$base/forumdisplay.php" @{fid = 9}
        Check-Route $port "$base/f-9-a-forum--p4$poison" "$base/forumdisplay.php" @{fid = 9; page = 4}
        Check-Route $port "$base/t-12-a-topic" "$base/showthread.php" @{tid = 12} 'POST'
        Check-Route $port "$base/showthread.php?tid=12&action=lastpost" "$base/showthread.php" @{tid = 12; action = 'lastpost'}
        Check-Route $port "$base/sitemap.xml" "$base/misc.php" @{action = 'vonseo_sitemap'}
        foreach($type in @('forums', 'threads', 'announcements', 'calendars', 'events'))
        {
            Check-Route $port "$base/sitemap.xml?type=$type" "$base/misc.php" @{action = 'vonseo_sitemap'; type = $type}
            Check-Route $port "$base/sitemap.xml?type=$type&page=2" "$base/misc.php" @{action = 'vonseo_sitemap'; type = $type; page = 2}
            Check-Route $port "$base/sitemap.xml?page=2&type=$type" "$base/misc.php" @{action = 'vonseo_sitemap'; type = $type; page = 2}
        }
        foreach($queryString in @('action=login', 'type=threads&page=2&action=login', 'type=users', 'type=threads&page=0'))
        {
            Check-Route $port "$base/sitemap.xml?$queryString" "$base/misc.php" @{action = 'vonseo_sitemap'}
        }
        Check-Route $port "$base/robots.txt?action=login" "$base/misc.php" @{action = 'vonseo_robots'}
        Check-Route $port "$base/missing-path?token=secret&action=login" "$base/misc.php" @{action = 'vonseo_route'}
        foreach($path in @("$base/assets/probe.txt", "$base/assets/"))
        {
            $response = Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$port$path" -TimeoutSec 10
            if($response.Content -ne 'existing-file') { throw "Existing file intercepted: $path" }
            $passed++
        }
    }
    foreach($path in @('/missing-outside', '/robots.txt', '/t-12-topic'))
    {
        try { [void](Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$SubfolderPort$path" -TimeoutSec 10); throw "Subfolder router captured outside request: $path" }
        catch { if(!$_.Exception.Response -or [int]$_.Exception.Response.StatusCode -ne 404) { throw } }
        $passed++
    }
    Write-Output "Nginx root/subfolder fixture routes: $passed passed"
}
finally
{
    if(Test-Path -LiteralPath (Join-Path $work 'logs/nginx.pid'))
    {
        [void](Start-Process -FilePath $Nginx -ArgumentList @('-p', ('"' + $prefix + '"'), '-c', 'nginx.conf', '-s', 'quit') -Wait -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $work 'shutdown.log'))
    }
    foreach($process in $processes)
    {
        if(!$process.HasExited)
        {
            if(!$process.WaitForExit(3000)) { Stop-Process -Id $process.Id -Force }
        }
    }
    # Only this run's verified direct child of the OS temporary directory.
    if([System.IO.Path]::GetDirectoryName($work).TrimEnd('\') -eq $tempRoot -and
        (Split-Path -Leaf $work) -match '^vonseo-nginx-[a-f0-9]{32}$')
    {
        Remove-Item -LiteralPath $work -Recurse -Force
    }
}
