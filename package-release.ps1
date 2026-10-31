$ErrorActionPreference = 'Stop'

$serverRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$releaseDir = Join-Path $serverRoot 'release'
New-Item -ItemType Directory -Path $releaseDir -Force | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$archivePath = Join-Path $releaseDir "pluschat-release-$stamp.zip"

$sourceFiles = [System.Collections.Generic.List[object]]::new()
foreach ($relativePath in @('public', 'src', 'database')) {
    $sourcePath = Join-Path $serverRoot $relativePath
    if (-not (Test-Path -LiteralPath $sourcePath -PathType Container)) {
        throw "Required release directory is missing: $relativePath"
    }
    foreach ($file in Get-ChildItem -LiteralPath $sourcePath -File -Force -Recurse) {
        if ($file.FullName -match '[\\/]storage[\\/]') { continue }
        if ($file.Name -match '^\.env($|\.)' -or $file.Name -ieq 'config.local.php') {
            throw "Refusing to package a private configuration file: $($file.FullName)"
        }
        $entryName = $file.FullName.Substring($serverRoot.Length + 1).Replace('\', '/')
        $sourceFiles.Add([PSCustomObject]@{ Path = $file.FullName; Entry = $entryName })
    }
}
foreach ($relativePath in @('.env.example', 'README.md')) {
    $sourcePath = Join-Path $serverRoot $relativePath
    if (-not (Test-Path -LiteralPath $sourcePath -PathType Leaf)) {
        throw "Required release file is missing: $relativePath"
    }
    $sourceFiles.Add([PSCustomObject]@{ Path = $sourcePath; Entry = $relativePath })
}

$privateEntries = @($sourceFiles.Entry | Where-Object { $_ -ne '.env.example' -and ($_.ToString() -match '(^|/)\.env($|\.)' -or $_.ToString() -match '(^|/)config\.local\.php$') })
if ($privateEntries.Count -gt 0) {
    throw 'Refusing to package local credentials.'
}
if (-not ($sourceFiles.Entry -contains 'public/.htaccess')) {
    throw 'The public .htaccess file was not included in the release inputs.'
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$stream = [System.IO.File]::Open($archivePath, [System.IO.FileMode]::CreateNew)
$archive = $null
try {
    $archive = [System.IO.Compression.ZipArchive]::new($stream, [System.IO.Compression.ZipArchiveMode]::Create)
    foreach ($file in $sourceFiles) {
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $archive,
            $file.Path,
            $file.Entry,
            [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
}
finally {
    if ($archive) { $archive.Dispose() }
    $stream.Dispose()
}

[PSCustomObject]@{
    Archive = $archivePath
    FileCount = $sourceFiles.Count
    IncludesHtaccess = $sourceFiles.Entry -contains 'public/.htaccess'
    IncludesLocalEnv = $false
}
