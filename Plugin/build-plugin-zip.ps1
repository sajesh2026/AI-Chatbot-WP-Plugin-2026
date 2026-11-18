# Build WordPress Plugin Installer ZIP for AI Chatbot v1.3.5
$ErrorActionPreference = "Stop"

$PluginDir = $PSScriptRoot
if (-not $PluginDir) {
    $PluginDir = Get-Location
}

$DistRoot = Join-Path $env:TEMP "ai-chatbot-dist"
$StageDir = Join-Path $DistRoot "ai-chatbot"
$ZipName = "ai-chatbot-v1.3.5.zip"
$ZipOutput = Join-Path $PluginDir $ZipName

Write-Host "Creating clean staging directory at: $StageDir"
if (Test-Path $DistRoot) {
    Remove-Item -Recurse -Force $DistRoot
}
if (Test-Path $ZipOutput) {
    Remove-Item -Force $ZipOutput
}

New-Item -ItemType Directory -Path $StageDir -Force | Out-Null

Write-Host "Copying core plugin files..."
Copy-Item (Join-Path $PluginDir "ai-chatbot.php") $StageDir
Copy-Item (Join-Path $PluginDir "readme.txt") $StageDir
Copy-Item (Join-Path $PluginDir "uninstall.php") $StageDir

if (Test-Path (Join-Path $PluginDir "CHANGELOG.md")) {
    Copy-Item (Join-Path $PluginDir "CHANGELOG.md") $StageDir
}

if (Test-Path (Join-Path $PluginDir "README.md")) {
    Copy-Item (Join-Path $PluginDir "README.md") $StageDir
}

# Copy includes
Copy-Item -Recurse (Join-Path $PluginDir "includes") (Join-Path $StageDir "includes")

# Copy public assets
Copy-Item -Recurse (Join-Path $PluginDir "public") (Join-Path $StageDir "public")

# Copy admin build and src
$AdminStage = Join-Path $StageDir "admin"
New-Item -ItemType Directory -Path $AdminStage -Force | Out-Null
Copy-Item -Recurse (Join-Path $PluginDir "admin\build") (Join-Path $AdminStage "build")
Copy-Item -Recurse (Join-Path $PluginDir "admin\src") (Join-Path $AdminStage "src")

# Stage clean data directory with empty template
$DataStage = Join-Path $StageDir "data"
New-Item -ItemType Directory -Path $DataStage -Force | Out-Null
Set-Content -Path (Join-Path $DataStage "url_registry.json") -Value "{\`n    \`"last_updated\`": \`"2026-11-06 00:00:00\`",\`n    \`"count\`": 0,\`n    \`"urls\`": []\`n}" -Encoding UTF8

# Copy languages if exists
if (Test-Path (Join-Path $PluginDir "languages")) {
    Copy-Item -Recurse (Join-Path $PluginDir "languages") (Join-Path $StageDir "languages")
}

Write-Host "Creating RFC-compliant ZIP with forward slashes..."
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zipArchive = [System.IO.Compression.ZipFile]::Open($ZipOutput, [System.IO.Compression.ZipArchiveMode]::Create)

$allFiles = Get-ChildItem -Path $StageDir -Recurse -File
foreach ($file in $allFiles) {
    $relPath = $file.FullName.Substring($DistRoot.Length + 1).Replace("\", "/")
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zipArchive, $file.FullName, $relPath, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
}
$zipArchive.Dispose()

Write-Host "Cleaning up staging directory..."
Remove-Item -Recurse -Force $DistRoot

$zipInfo = Get-Item $ZipOutput
Write-Host "Successfully generated WordPress Plugin ZIP installer:"
Write-Host "File: $($zipInfo.FullName)"
Write-Host "Size: $([math]::Round($zipInfo.Length / 1KB, 2)) KB"

$DesktopPath = [Environment]::GetFolderPath('Desktop')
if ($DesktopPath -and (Test-Path $DesktopPath)) {
    Copy-Item $ZipOutput (Join-Path $DesktopPath $ZipName) -Force
    Write-Host "Copied to Desktop: $(Join-Path $DesktopPath $ZipName)"
}

$ArtifactDir = "C:\Users\linar\.gemini\antigravity\brain\3e4f1a04-aab1-4735-9553-e4d9cbcc61fd"
if (Test-Path $ArtifactDir) {
    Copy-Item $ZipOutput (Join-Path $ArtifactDir $ZipName) -Force
    Write-Host "Copied to Artifacts: $(Join-Path $ArtifactDir $ZipName)"
}

