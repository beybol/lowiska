<#
.SYNOPSIS
    Konwertuje plik Markdown na PDF (z wbudowanym stylem) obok pliku źródłowego.

.DESCRIPTION
    Najpierw generuje samodzielny HTML przez `md2html.ps1` (styl `github-markdown.css` wbudowany
    inline, tytuł z nazwy pliku: myślniki → spacje, pierwsza litera duża), a następnie drukuje go
    do PDF przez Edge/Chrome w trybie headless. PDF ląduje obok źródła, ta sama nazwa, rozszerzenie
    .pdf. Istniejące pliki .html i .pdf zostają nadpisane.

    Wymaga: pandoc (winget install JohnMacFarlane.Pandoc) oraz Microsoft Edge lub Google Chrome.

.PARAMETER MdFile
    Ścieżka do pliku .md (względna lub bezwzględna).

.EXAMPLE
    .\docs\md2pdf.ps1 docs\upgrade\laravel-12-regresja-manualna-raport.md
    # → docs\upgrade\laravel-12-regresja-manualna-raport.pdf
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [string]$MdFile
)

$ErrorActionPreference = 'Stop'

# 1. Wygeneruj HTML (z wbudowanym stylem i poprawnym tytułem)
& "$PSScriptRoot\md2html.ps1" $MdFile
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

# 2. Ścieżki
$src  = (Resolve-Path -LiteralPath $MdFile).Path
$html = [System.IO.Path]::ChangeExtension($src, '.html')
$pdf  = [System.IO.Path]::ChangeExtension($src, '.pdf')

# 3. Przeglądarka — Edge, fallback Chrome
$browser = @(
    "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe",
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
    "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe"
) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
if (-not $browser) {
    Write-Error "Nie znaleziono Edge ani Chrome (msedge.exe / chrome.exe)."
    exit 1
}

# 4. HTML -> PDF (headless). Osobny user-data-dir, żeby nie kolidować z otwartą przeglądarką.
$url = 'file:///' + ($html -replace '\\', '/')
$tmp = Join-Path $env:TEMP ('md2pdf_' + [guid]::NewGuid().ToString('N'))
if (Test-Path -LiteralPath $pdf) { Remove-Item -LiteralPath $pdf -Force }

& $browser --headless=new --disable-gpu --no-pdf-header-footer `
    --run-all-compositor-stages-before-draw --virtual-time-budget=5000 `
    --user-data-dir="$tmp" --print-to-pdf="$pdf" $url | Out-Null
$code = $LASTEXITCODE

Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue

if (-not (Test-Path -LiteralPath $pdf)) {
    Write-Error "Nie udało się wygenerować PDF (przeglądarka zwróciła kod $code)."
    exit 1
}

Write-Host "OK -> $pdf" -ForegroundColor Green
