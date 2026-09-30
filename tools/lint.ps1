# Lints every .php file in the project.
#
# Usage:
#   powershell -NoProfile -ExecutionPolicy Bypass -File tools\lint.ps1
#   $env:PHP_BIN = 'C:\path\to\php.exe'; powershell -File tools\lint.ps1
#
# PHP is located in this order:
#   1. $env:PHP_BIN, if set
#   2. php on the PATH
#   3. common Windows install locations
# There is no hardcoded machine-specific path: this file is committed, so it
# has to work on any machine that clones the repository.

$ErrorActionPreference = 'Stop'

function Resolve-PhpBinary {
    $candidates = @()

    if ($env:PHP_BIN) {
        $candidates += $env:PHP_BIN
    }

    $onPath = Get-Command 'php' -ErrorAction SilentlyContinue
    if ($onPath) {
        $candidates += $onPath.Source
    }

    $candidates += @(
        'C:\php\php.exe',
        'C:\xampp\php\php.exe',
        'C:\laragon\bin\php\php.exe',
        'C:\wamp64\bin\php\php.exe',
        'C:\Program Files\PHP\php.exe',
        '/usr/bin/php',
        '/usr/local/bin/php'
    )

    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path -LiteralPath $candidate)) {
            return $candidate
        }
    }

    return $null
}

$php = Resolve-PhpBinary
if (-not $php) {
    Write-Host 'Could not find a PHP CLI binary.' -ForegroundColor Red
    Write-Host ''
    Write-Host 'Install PHP 8.1+ and either put php on the PATH, or point at it explicitly:'
    Write-Host '    $env:PHP_BIN = ''C:\path\to\php.exe'''
    Write-Host '    powershell -NoProfile -ExecutionPolicy Bypass -File tools\lint.ps1'
    exit 1
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

Write-Host "OK: all $count PHP file(s) parse cleanly (using $php)." -ForegroundColor Green
