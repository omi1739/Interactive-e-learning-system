# Lints every .php file in the project with the portable PHP CLI.
# Usage:  powershell -File tools\lint.ps1
$ErrorActionPreference = 'Stop'

$php = 'C:\Users\seyam\AppData\Local\Temp\opencode\php\runtime\php.exe'
if (-not (Test-Path $php)) {
    Write-Error "Portable PHP not found at $php"
}

$root = Split-Path -Parent $PSScriptRoot
$failures = 0
$count = 0

Get-ChildItem -Path $root -Recurse -Filter *.php -File |
    Where-Object { $_.FullName -notlike '*\vendor\*' } |
    ForEach-Object {
        $count++
        $output = & $php -l $_.FullName 2>&1
        if ($LASTEXITCODE -ne 0) {
            $failures++
            Write-Host "FAIL $($_.FullName)" -ForegroundColor Red
            $output | ForEach-Object { Write-Host "     $_" -ForegroundColor Red }
        }
    }

if ($failures -gt 0) {
    Write-Host "`n$failures of $count file(s) failed lint." -ForegroundColor Red
    exit 1
}

Write-Host "OK: all $count PHP file(s) parse cleanly." -ForegroundColor Green
