<#
.SYNOPSIS
    Konwertuje plik Markdown na samodzielny HTML (z wbudowanym stylem) obok pliku źródłowego.

.DESCRIPTION
    Bierze plik .md (z dowolnego podkatalogu), generuje plik o tej samej nazwie z rozszerzeniem
    .html w tej samej ścieżce co źródło. Styl `github-markdown.css` (obok tego skryptu, w `docs/`)
    jest **wbudowany inline** w HTML (`--embed-resources`), więc plik jest samodzielny.
    Istniejący plik HTML zostaje nadpisany.

    Wymaga pandoc:  winget install JohnMacFarlane.Pandoc

.PARAMETER MdFile
    Ścieżka do pliku .md (względna lub bezwzględna).

.EXAMPLE
    .\docs\md2html.ps1 docs\upgrade\laravel-12-regresja-manualna-raport.md
    # → docs\upgrade\laravel-12-regresja-manualna-raport.html (styl wbudowany)
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [string]$MdFile
)

$ErrorActionPreference = 'Stop'

# 1. pandoc dostępny?
if (-not (Get-Command pandoc -ErrorAction SilentlyContinue)) {
    Write-Error "Nie znaleziono 'pandoc'. Zainstaluj: winget install JohnMacFarlane.Pandoc"
    exit 1
}

# 2. Plik źródłowy
if (-not (Test-Path -LiteralPath $MdFile -PathType Leaf)) {
    Write-Error "Nie znaleziono pliku: $MdFile"
    exit 1
}
$src = (Resolve-Path -LiteralPath $MdFile).Path
if ([System.IO.Path]::GetExtension($src).ToLower() -ne '.md') {
    Write-Error "Plik wejściowy musi mieć rozszerzenie .md: $src"
    exit 1
}

# 3. Arkusz stylów — obok tego skryptu (w docs/)
$css = Join-Path $PSScriptRoot 'github-markdown.css'
if (-not (Test-Path -LiteralPath $css -PathType Leaf)) {
    Write-Error "Nie znaleziono arkusza stylów: $css"
    exit 1
}
$css = (Resolve-Path -LiteralPath $css).Path

# 4. HTML obok źródła — ta sama nazwa, rozszerzenie .html (nadpisywany)
$html = [System.IO.Path]::ChangeExtension($src, '.html')

# Tytuł: z nazwy pliku — myślniki na spacje, pierwsza litera duża
$title = [System.IO.Path]::GetFileNameWithoutExtension($src) -replace '-', ' '
if ($title.Length -gt 0) { $title = $title.Substring(0, 1).ToUpper() + $title.Substring(1) }

# 5. Konwersja (GFM → tabele, task-listy, emoji; styl wbudowany inline)
pandoc --from gfm --to html5 --standalone --embed-resources `
    --css="$css" --metadata title="$title" `
    --output "$html" "$src"

if ($LASTEXITCODE -ne 0) {
    Write-Error "pandoc zwrócił błąd (kod $LASTEXITCODE)."
    exit $LASTEXITCODE
}

Write-Host "OK -> $html" -ForegroundColor Green
