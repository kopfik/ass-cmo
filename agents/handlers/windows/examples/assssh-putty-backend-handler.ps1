# =============================================================================
# ASS-CMO — assssh:// PuTTY backend handler  (PROTOTYPE / TEMPLATE — NOT WIRED IN)
# -----------------------------------------------------------------------------
# Status: example/prototype only. This file is NOT registered by the installer
#         (agents/handlers/windows/install-ass-cmo-uri-handlers.ps1), NOT
#         referenced by the dashboard, and NOT documented as a supported flow.
#         It exists so the PuTTY-based launch path can be reviewed in isolation
#         before any decision to wire it into the installer/docs/dashboard.
#
# Purpose: handle the existing assssh:// SSH intent by launching PuTTY
#          (putty.exe) instead of the native Windows OpenSSH client.
#
# Scheme design (important):
#   assssh:// is the stable SSH connection intent emitted by the ASS-CMO
#   dashboard/server. That does NOT change. PuTTY is only a *local Windows
#   handler backend* choice for that same assssh:// scheme — not a separate
#   public protocol. The local handler installer may later offer a backend
#   selection (native OpenSSH vs PuTTY); this file is one such backend.
#   Until then it is an unwired example and parses assssh:// directly.
#
# Scope and safety:
#   - Supports host, optional username, and optional port only.
#   - Does NOT support or pass passwords, keys, or any credential material.
#   - Validates targets at least as strictly as the existing assssh:// handler
#     (leading-dash rejection, shell-metacharacter denylist, user/host/port
#     pattern checks, IPv6 literal checks).
#   - Launches PuTTY via Start-Process with an argument-array (no shell string
#     concatenation, no Invoke-Expression, no cmd.exe).
#   - Writes nothing to the registry. Scheme registration stays in the installer
#     (where assssh:// is registered under HKCU:\Software\Classes\<scheme>),
#     NOT in this handler.
#
# Future options (documented, intentionally NOT implemented here):
#   - Saved PuTTY session launch via `putty.exe -load "<session>"`. Deferred
#     because it needs its own encoding and a session-name allowlist to stay
#     injection-safe; not implemented until that design is reviewed.
#   - Installer backend selection (native OpenSSH vs PuTTY) for assssh://.
#
# Usage (manual test):
#   powershell.exe -NoProfile -ExecutionPolicy Bypass `
#     -File assssh-putty-backend-handler.ps1 "assssh://user@host:22"
#
# Generic URI examples:
#   assssh://host
#   assssh://user@host
#   assssh://user@host:2222
#   assssh://10.0.0.5
#   assssh://user@[2001:db8::1]:22
# =============================================================================

param(
    [Parameter(Mandatory = $true)]
    [string]$Uri
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Scheme = 'assssh://'

# Shell-metacharacter denylist, mirrored from the existing assssh:// handler.
$UnsafeChars = '[\s;''"`$\\&|<>(){}]'

function Show-HandlerError {
    # Show a clear, non-scary message. Prefer a GUI dialog, fall back to console.
    param([Parameter(Mandatory = $true)][string]$Message)

    try {
        Add-Type -AssemblyName PresentationFramework -ErrorAction Stop
        [System.Windows.MessageBox]::Show($Message, 'ASS-CMO PuTTY SSH handler') | Out-Null
    } catch {
        Write-Host "ASS-CMO PuTTY SSH handler: $Message"
    }
}

function Assert-SafePort {
    # Numeric 1-65535. Throws on anything else.
    param([Parameter(Mandatory = $true)][string]$Port)

    if ($Port -notmatch '^[0-9]+$') {
        throw "Invalid port '$Port'."
    }

    $number = [int]$Port
    if ($number -lt 1 -or $number -gt 65535) {
        throw "Port out of range '$Port'."
    }
}

function Assert-SafeUser {
    # Conservative username charset, no leading dash. Throws on failure.
    param([Parameter(Mandatory = $true)][string]$User)

    if ($User -notmatch '^[A-Za-z0-9._-]+$' -or $User -match '^-') {
        throw "Invalid username '$User'."
    }
}

function Assert-SafeHost {
    # Accepts a DNS-style hostname/IPv4 or a bare IPv6 literal. Throws on failure.
    param([Parameter(Mandatory = $true)][string]$HostName)

    if ([string]::IsNullOrWhiteSpace($HostName)) {
        throw 'Empty host.'
    }

    if ($HostName -match '^-' -or $HostName -match $UnsafeChars) {
        throw "Unsafe host '$HostName'."
    }

    # IPv6 literal (already unbracketed by the parser).
    if ($HostName -match ':') {
        if ($HostName -notmatch '^[A-Fa-f0-9:.]+$' -or $HostName -match ':::') {
            throw "Invalid IPv6 host '$HostName'."
        }
        return
    }

    # Hostname / IPv4: same charset as the existing handler, no '..' sequences.
    if ($HostName -notmatch '^[A-Za-z0-9._-]+$' -or $HostName -match '\.\.') {
        throw "Invalid host '$HostName'."
    }
}

function Parse-AsscmoSshUri {
    # Parse assssh://[user@](host|[ipv6])[:port] into validated parts.
    # Returns @{ User = <string|null>; HostName = <string>; Port = <string|null> }.
    param([Parameter(Mandatory = $true)][string]$RawUri)

    $target = $RawUri -replace ('^' + [Regex]::Escape($Scheme)), ''
    $target = $target -replace '/.*$', ''      # drop any path
    $target = $target -replace '\?.*$', ''     # drop any query
    $target = [System.Uri]::UnescapeDataString($target)

    if ([string]::IsNullOrWhiteSpace($target)) {
        throw 'Empty target.'
    }

    if ($target -match '^-' -or $target -match $UnsafeChars) {
        throw 'Unsafe target.'
    }

    $user = $null
    $hostName = $null
    $port = $null

    # user@[ipv6]:port  (bracketed IPv6 literal)
    if ($target -match '^(?:(?<user>[A-Za-z0-9._-]+)@)?\[(?<ipv6>[A-Fa-f0-9:.]+)\](?::(?<port>[0-9]+))?$') {
        $user = $Matches.user
        $hostName = $Matches.ipv6
        $port = $Matches.port
    }
    # user@host:port  (hostname or IPv4)
    elseif ($target -match '^(?:(?<user>[A-Za-z0-9._-]+)@)?(?<host>[A-Za-z0-9._-]+)(?::(?<port>[0-9]+))?$') {
        $user = $Matches.user
        $hostName = $Matches.host
        $port = $Matches.port
    }
    else {
        throw 'Target does not match an accepted host pattern.'
    }

    if ($user) { Assert-SafeUser $user }
    Assert-SafeHost $hostName
    if ($port) { Assert-SafePort $port }

    return @{
        User     = $user
        HostName = $hostName
        Port     = $port
    }
}

function Find-PuTTY {
    # Locate putty.exe via PATH first, then common install locations.
    # Returns the full path, or $null if not found.
    $fromPath = Get-Command putty.exe -ErrorAction SilentlyContinue
    if ($fromPath) {
        return $fromPath.Source
    }

    # Build base directories only from env vars that exist and are non-empty,
    # so Join-Path is never called with a null/empty Path under Set-StrictMode.
    $baseDirs = @()
    if ($env:ProgramFiles)        { $baseDirs += $env:ProgramFiles }
    if (${env:ProgramFiles(x86)}) { $baseDirs += ${env:ProgramFiles(x86)} }
    if ($env:LOCALAPPDATA)        { $baseDirs += (Join-Path $env:LOCALAPPDATA 'Programs') }

    foreach ($base in $baseDirs) {
        $candidate = Join-Path $base 'PuTTY\putty.exe'
        if (Test-Path -LiteralPath $candidate) {
            return $candidate
        }
    }

    return $null
}

# ── Main ─────────────────────────────────────────────────────────────────────
try {
    $parsed = Parse-AsscmoSshUri $Uri
} catch {
    Show-HandlerError "Could not open SSH link: $($_.Exception.Message)"
    exit 1
}

$putty = Find-PuTTY
if (-not $putty) {
    Show-HandlerError "PuTTY (putty.exe) was not found. Install PuTTY or add it to PATH, then try again."
    exit 1
}

# Build PuTTY arguments from validated parts only. Host is passed last and is
# guaranteed not to start with '-', so it cannot be read as an option.
$puttyArgs = @('-ssh')
if ($parsed.User) { $puttyArgs += @('-l', $parsed.User) }
if ($parsed.Port) { $puttyArgs += @('-P', $parsed.Port) }
$puttyArgs += $parsed.HostName

try {
    Start-Process -FilePath $putty -ArgumentList $puttyArgs
    exit 0
} catch {
    Show-HandlerError "Could not start PuTTY: $($_.Exception.Message)"
    exit 1
}
