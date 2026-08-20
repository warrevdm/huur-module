param(
    [switch]$NoStartup
)

$ErrorActionPreference = 'Stop'

if (-not $IsWindows) {
    throw 'Deze installer moet op Windows worden uitgevoerd.'
}

$viewerCandidates = @(
    "$env:ProgramFiles\Belgium Identity Card\EidViewer\eIDViewerBackend.dll",
    "${env:ProgramFiles(x86)}\Belgium Identity Card\EidViewer\eIDViewerBackend.dll"
) | Where-Object { $_ -and (Test-Path $_) }

if (-not $viewerCandidates) {
    Write-Host ''
    Write-Host 'Belgische eID Viewer-backend niet gevonden.' -ForegroundColor Red
    Write-Host 'Installeer eerst de officiële eID Middleware én eID Viewer via https://eid.belgium.be/.'
    Write-Host 'Controleer daarna in de eID Viewer of de DIGIPASS 905 de kaart kan lezen.'
    exit 2
}

if (-not (Get-Command dotnet -ErrorAction SilentlyContinue)) {
    Write-Host '.NET 8 SDK is nodig om de lokale bridge éénmalig te bouwen.' -ForegroundColor Red
    Write-Host 'Installeer .NET 8 SDK en voer dit script daarna opnieuw uit.'
    exit 3
}

$project = Join-Path $PSScriptRoot 'AabEidBridge.csproj'
$publishDir = Join-Path $PSScriptRoot 'dist'
$installDir = Join-Path $env:LOCALAPPDATA 'AertsActionBike\EidBridge'

if (Test-Path $publishDir) {
    Remove-Item $publishDir -Recurse -Force
}
New-Item -ItemType Directory -Path $publishDir -Force | Out-Null
New-Item -ItemType Directory -Path $installDir -Force | Out-Null

Write-Host 'AAB eID Bridge bouwen…' -ForegroundColor Cyan
dotnet publish $project -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -o $publishDir
if ($LASTEXITCODE -ne 0) {
    throw 'Build van AAB eID Bridge is mislukt.'
}

Get-Process -Name 'AAB-eID-Bridge' -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
Copy-Item (Join-Path $publishDir '*') $installDir -Recurse -Force

$exe = Join-Path $installDir 'AAB-eID-Bridge.exe'
if (-not (Test-Path $exe)) {
    throw "Bridge executable ontbreekt na installatie: $exe"
}

if (-not $NoStartup) {
    $startup = [Environment]::GetFolderPath('Startup')
    $shortcutPath = Join-Path $startup 'AAB eID Bridge.lnk'
    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($shortcutPath)
    $shortcut.TargetPath = $exe
    $shortcut.WorkingDirectory = $installDir
    $shortcut.Description = 'Lokale Belgische eID bridge voor Aerts Action Bike verhuurmodule'
    $shortcut.WindowStyle = 7
    $shortcut.Save()
    Write-Host "Automatische start ingesteld: $shortcutPath" -ForegroundColor Green
}

Start-Process -FilePath $exe -WorkingDirectory $installDir -WindowStyle Minimized
Start-Sleep -Seconds 2

try {
    $health = Invoke-RestMethod -Uri 'http://127.0.0.1:17895/v1/health' -TimeoutSec 4
    Write-Host ''
    Write-Host 'AAB eID Bridge is actief.' -ForegroundColor Green
    Write-Host ('Kaartlezers: ' + (($health.readers | ForEach-Object { $_ }) -join ', '))
    if (-not $health.readers -or $health.readers.Count -eq 0) {
        Write-Host 'Nog geen kaartlezer gedetecteerd. Controleer USB/driver en test de DIGIPASS 905 in de officiële eID Viewer.' -ForegroundColor Yellow
    }
}
catch {
    Write-Host 'De bridge werd geïnstalleerd, maar de health-check antwoordde nog niet.' -ForegroundColor Yellow
    Write-Host "Start handmatig: $exe"
}

Write-Host ''
Write-Host 'Open daarna Nieuwe verhuur en klik op eID uitlezen.' -ForegroundColor Cyan
