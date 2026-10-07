param(
    [string]$Version = '1.0.7'
)

$ErrorActionPreference = 'Stop'
if($Version -notmatch '^\d+\.\d+\.\d+(?:-(?:alpha|beta|rc)\.\d+)?$')
{
    throw 'Version must be a stable or prerelease identifier such as 0.6.0 or 0.6.0-rc.1.'
}
$root = Split-Path -Parent $PSScriptRoot
$pluginSource = Get-Content -LiteralPath (Join-Path $root 'inc/plugins/vonseo.php') -Raw
$utilsSource = Get-Content -LiteralPath (Join-Path $root 'inc/plugins/vonseo/Utils.php') -Raw
$versionPattern = "(?m)define\(\s*'VONSEO_VERSION'\s*,\s*'$([regex]::Escape($Version))'\s*\)"
if($pluginSource -notmatch "(?m)'version'\s*=>\s*VONSEO_VERSION" -or
   $utilsSource -notmatch $versionPattern)
{
    throw "Plugin metadata does not match requested package version $Version."
}
foreach($relative in @('README.md', 'CHANGELOG.md'))
{
    if((Get-Content -LiteralPath (Join-Path $root $relative) -Raw) -notmatch [regex]::Escape($Version))
    {
        throw "Release documentation does not mention $Version`: $relative"
    }
}

# Public upload manifest: no test harnesses, IDE stubs, or internal progress notes.
$files = @(
    'admin/modules/config/vonseo.php',
    'inc/plugins/vonseo.php',
    'inc/plugins/vonseo/AdminImport.php',
    'inc/plugins/vonseo/AdminList.php',
    'inc/plugins/vonseo/AdminRedirects.php',
    'inc/plugins/vonseo/Context.php',
    'inc/plugins/vonseo/Core.php',
    'inc/plugins/vonseo/Errors.php',
    'inc/plugins/vonseo/IndexNow.php',
    'inc/plugins/vonseo/Keyword.php',
    'inc/plugins/vonseo/Meta.php',
    'inc/plugins/vonseo/Redirects.php',
    'inc/plugins/vonseo/Robots.php',
    'inc/plugins/vonseo/Schema.php',
    'inc/plugins/vonseo/Sitemap.php',
    'inc/plugins/vonseo/Social.php',
    'inc/plugins/vonseo/State.php',
    'inc/plugins/vonseo/Url.php',
    'inc/plugins/vonseo/Utils.php',
    'inc/plugins/vonseo/index.html',
    'inc/tasks/vonseo_indexnow.php',
    'inc/languages/english/vonseo.lang.php',
    'inc/languages/english/admin/vonseo.lang.php',
    'inc/languages/english/admin/index.html',
    'extras/htaccess-vonseo.txt',
    'extras/htaccess-vonseo-host-root.txt',
    'extras/nginx-vonseo.conf',
    'extras/nginx-vonseo-subfolder.conf',
    'README.md',
    'CHANGELOG.md',
    'LICENSE.txt',
    'NOTICE.txt'
)

$destination = Join-Path $root "vonseo-mybb-$Version.zip"
$buildPath = Join-Path $root ("vonseo-mybb-$Version." + [guid]::NewGuid().ToString('N') + '.tmp')
$rootFull = [System.IO.Path]::GetFullPath($root).TrimEnd('\')
if([System.IO.Path]::GetDirectoryName([System.IO.Path]::GetFullPath($destination)).TrimEnd('\') -ne $rootFull)
{
    throw 'Refusing to write release ZIP outside the repository root.'
}
if(Test-Path -LiteralPath $destination)
{
    $existing = Get-Item -LiteralPath $destination
    if(($existing.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -or $existing.PSIsContainer)
    {
        throw "Refusing to replace an unexpected release path: $destination"
    }
}

$license = Get-Content -LiteralPath (Join-Path $root 'LICENSE.txt') -Raw
$notice = Get-Content -LiteralPath (Join-Path $root 'NOTICE.txt') -Raw
if($license -notmatch 'GNU LESSER GENERAL PUBLIC LICENSE' -or
   $license -notmatch 'GNU GENERAL PUBLIC LICENSE' -or
   $license -notmatch 'END OF TERMS AND CONDITIONS' -or
   $notice -notmatch 'SPDX-License-Identifier: LGPL-3\.0-only')
{
    throw 'Complete LGPL-3.0-only license text and notice are required.'
}

foreach($relative in $files)
{
    if(!(Test-Path -LiteralPath (Join-Path $root $relative) -PathType Leaf))
    {
        throw "Missing package file: $relative"
    }
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [System.IO.Compression.ZipFile]::Open($buildPath, [System.IO.Compression.ZipArchiveMode]::Create)
try
{
    foreach($relative in $files)
    {
        $source = Join-Path $root $relative
        [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $archive,
            $source,
            $relative.Replace('\', '/'),
            [System.IO.Compression.CompressionLevel]::Optimal
        )
    }
}
finally
{
    $archive.Dispose()
}

$readback = [System.IO.Compression.ZipFile]::OpenRead($buildPath)
try
{
    if($readback.Entries.Count -ne $files.Count)
    {
        throw "Package entry count mismatch: $($readback.Entries.Count) instead of $($files.Count)."
    }

    $sha = [System.Security.Cryptography.SHA256]::Create()
    try
    {
        foreach($relative in $files)
        {
            $entry = $readback.GetEntry($relative.Replace('\', '/'))
            if($null -eq $entry)
            {
                throw "Missing package entry: $relative"
            }

            $stream = $entry.Open()
            try
            {
                $archiveHash = [BitConverter]::ToString($sha.ComputeHash($stream)).Replace('-', '')
            }
            finally
            {
                $stream.Dispose()
            }
            $sourceHash = (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $root $relative)).Hash
            if($archiveHash -ne $sourceHash)
            {
                throw "Package content mismatch: $relative"
            }
        }
    }
    finally
    {
        $sha.Dispose()
    }
}
finally
{
    $readback.Dispose()
}

if(Test-Path -LiteralPath $destination)
{
    $backup = Join-Path $root ("vonseo-mybb-$Version." + [guid]::NewGuid().ToString('N') + '.bak')
    [System.IO.File]::Replace($buildPath, $destination, $backup, $true)
    Remove-Item -LiteralPath $backup -Force
}
else
{
    Move-Item -LiteralPath $buildPath -Destination $destination
}

Write-Output "Verified $destination ($($files.Count) byte-matching files)"

# Prune only prior VonSEO release/test ZIPs in this exact repository root.
# The new package must pass the readback above before anything is removed.
$oldArchives = @(Get-ChildItem -LiteralPath $root -Filter 'vonseo-mybb-*.zip' -File |
    Where-Object {
        $_.Name -match '^vonseo-mybb-\d+\.\d+\.\d+(?:-(?:alpha|beta|rc)\.\d+)?(?:-test-\d{8})?\.zip$' -and
        $_.FullName -ne $destination
    })
foreach($oldArchive in $oldArchives)
{
    $oldFull = [System.IO.Path]::GetFullPath($oldArchive.FullName)
    if([System.IO.Path]::GetDirectoryName($oldFull).TrimEnd('\') -ne $rootFull -or
       ($oldArchive.Attributes -band [System.IO.FileAttributes]::ReparsePoint))
    {
        throw "Refusing to remove archive outside the repository root or a reparse point: $oldFull"
    }

}
foreach($oldArchive in $oldArchives)
{
    Remove-Item -LiteralPath $oldArchive.FullName -Force
    Write-Output "Removed old release ZIP: $($oldArchive.FullName)"
}
