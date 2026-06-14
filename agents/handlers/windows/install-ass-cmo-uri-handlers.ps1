param(
    [string]$InstallUrl = "",
    [switch]$Overwrite
)

$BaseDir = Join-Path $env:LOCALAPPDATA "ASS-CMO\UriHandlers"
New-Item -ItemType Directory -Force -Path $BaseDir | Out-Null

$SshHandler = Join-Path $BaseDir "assssh-handler.ps1"
$RdpHandler = Join-Path $BaseDir "assrdp-handler.ps1"
$WebHandler = Join-Path $BaseDir "assweb-handler.ps1"

$SshWrapper = Join-Path $BaseDir "assssh-handler.vbs"
$RdpWrapper = Join-Path $BaseDir "assrdp-handler.vbs"
$WebWrapper = Join-Path $BaseDir "assweb-handler.vbs"

$OverwriteHandlers = $Overwrite -or ($env:ASSCMO_OVERWRITE_HANDLERS -eq "1")
$ExistingHandlers = @($SshHandler, $RdpHandler, $WebHandler, $SshWrapper, $RdpWrapper, $WebWrapper) | Where-Object { Test-Path $_ }

if (-not $OverwriteHandlers -and $ExistingHandlers.Count -gt 0) {
    Write-Host "Existing local ASS-CMO URI handler installation found."
    Write-Host "No changes were made."
    Write-Host ""
    $ExistingHandlers | ForEach-Object { Write-Host $_ }
    Write-Host ""
    Write-Host "To keep your local custom handlers, do nothing."
    Write-Host ""
    Write-Host "To replace all local handlers with the bundled version, rerun:"

    if ($InstallUrl) {
        $SafeInstallUrl = $InstallUrl.Replace("'", "''")
        Write-Host ""
        Write-Host "`$p=Join-Path `$env:TEMP 'install-ass-cmo-uri-handlers.ps1'; [Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -UseBasicParsing '$SafeInstallUrl' -OutFile `$p; powershell.exe -NoProfile -ExecutionPolicy Bypass -File `$p -InstallUrl '$SafeInstallUrl' -Overwrite"
    } else {
        Write-Host ""
        Write-Host "powershell.exe -NoProfile -ExecutionPolicy Bypass -File <downloaded-installer-path> -Overwrite"
    }

    exit 0
}


@'
param(
    [Parameter(Mandatory = $true)]
    [string]$Uri
)

$Target = $Uri -replace '^assssh://', ''
$Target = $Target -replace '/.*$', ''
$Target = $Target -replace '\?.*$', ''
$Target = [System.Uri]::UnescapeDataString($Target)


function Test-AssCmoPort {
    param([Parameter(Mandatory = $true)][string]$Port)

    if ($Port -notmatch '^[0-9]+$') {
        return $false
    }

    $Number = [int]$Port
    return ($Number -ge 1 -and $Number -le 65535)
}

function Test-AssCmoUser {
    param([Parameter(Mandatory = $true)][string]$User)

    return ($User -match '^[A-Za-z0-9._-]+$' -and $User -notmatch '^-')
}

function Test-AssCmoIpv6Literal {
    param([Parameter(Mandatory = $true)][string]$Address)

    return (
        $Address -match ':' -and
        $Address -match '^[A-Fa-f0-9:.]+$' -and
        $Address -notmatch ':::'
    )
}

function Test-AssCmoHandlerTarget {
    param([Parameter(Mandatory = $true)][string]$Target)

    if ([string]::IsNullOrWhiteSpace($Target)) {
        return $false
    }

    if ($Target -match '^-' -or $Target -match '[\s;''"`$\\&|<>(){}]') {
        return $false
    }

    if ($Target -match '^(?:(?<user>[A-Za-z0-9._-]+)@)?\[(?<ipv6>[A-Fa-f0-9:.]+)\](?::(?<port>[0-9]+))?$') {
        if ($Matches.user -and -not (Test-AssCmoUser $Matches.user)) {
            return $false
        }

        if (-not (Test-AssCmoIpv6Literal $Matches.ipv6)) {
            return $false
        }

        if ($Matches.port -and -not (Test-AssCmoPort $Matches.port)) {
            return $false
        }

        return $true
    }

    if ($Target -match '^(?:(?<user>[A-Za-z0-9._-]+)@)?(?<host>[A-Za-z0-9._-]+)(?::(?<port>[0-9]+))?$') {
        if ($Matches.user -and -not (Test-AssCmoUser $Matches.user)) {
            return $false
        }

        if ($Matches.host -match '^-' -or $Matches.host -match '\.\.') {
            return $false
        }

        if ($Matches.port -and -not (Test-AssCmoPort $Matches.port)) {
            return $false
        }

        return $true
    }

    return $false
}

if (-not (Test-AssCmoHandlerTarget $Target)) {
    exit 1
}

$Wt = Get-Command wt.exe -ErrorAction SilentlyContinue

if ($Wt) {
    Start-Process -FilePath $Wt.Source -ArgumentList @("new-tab", "ssh", "--", $Target)
    exit 0
}

$Ssh = Get-Command ssh.exe -ErrorAction SilentlyContinue

if ($Ssh) {
    Start-Process -FilePath $Ssh.Source -ArgumentList @("--", $Target)
    exit 0
}

Add-Type -AssemblyName PresentationFramework
[System.Windows.MessageBox]::Show("Windows Terminal or OpenSSH client was not found.", "ASS-CMO SSH handler") | Out-Null
exit 1
'@ | Set-Content -Encoding UTF8 -Path $SshHandler

@'
param(
    [Parameter(Mandatory = $true)]
    [string]$Uri
)

$Target = $Uri -replace '^assrdp://', ''
$Target = $Target -replace '/.*$', ''
$Target = $Target -replace '\?.*$', ''
$Target = [System.Uri]::UnescapeDataString($Target)


function Test-AssCmoPort {
    param([Parameter(Mandatory = $true)][string]$Port)

    if ($Port -notmatch '^[0-9]+$') {
        return $false
    }

    $Number = [int]$Port
    return ($Number -ge 1 -and $Number -le 65535)
}

function Test-AssCmoUser {
    param([Parameter(Mandatory = $true)][string]$User)

    return ($User -match '^[A-Za-z0-9._-]+$' -and $User -notmatch '^-')
}

function Test-AssCmoIpv6Literal {
    param([Parameter(Mandatory = $true)][string]$Address)

    return (
        $Address -match ':' -and
        $Address -match '^[A-Fa-f0-9:.]+$' -and
        $Address -notmatch ':::'
    )
}

function Test-AssCmoHandlerTarget {
    param([Parameter(Mandatory = $true)][string]$Target)

    if ([string]::IsNullOrWhiteSpace($Target)) {
        return $false
    }

    if ($Target -match '^-' -or $Target -match '[\s;''"`$\\&|<>(){}]') {
        return $false
    }

    if ($Target -match '^(?:(?<user>[A-Za-z0-9._-]+)@)?\[(?<ipv6>[A-Fa-f0-9:.]+)\](?::(?<port>[0-9]+))?$') {
        if ($Matches.user -and -not (Test-AssCmoUser $Matches.user)) {
            return $false
        }

        if (-not (Test-AssCmoIpv6Literal $Matches.ipv6)) {
            return $false
        }

        if ($Matches.port -and -not (Test-AssCmoPort $Matches.port)) {
            return $false
        }

        return $true
    }

    if ($Target -match '^(?:(?<user>[A-Za-z0-9._-]+)@)?(?<host>[A-Za-z0-9._-]+)(?::(?<port>[0-9]+))?$') {
        if ($Matches.user -and -not (Test-AssCmoUser $Matches.user)) {
            return $false
        }

        if ($Matches.host -match '^-' -or $Matches.host -match '\.\.') {
            return $false
        }

        if ($Matches.port -and -not (Test-AssCmoPort $Matches.port)) {
            return $false
        }

        return $true
    }

    return $false
}

if (-not (Test-AssCmoHandlerTarget $Target)) {
    exit 1
}

Start-Process -FilePath "mstsc.exe" -ArgumentList "/v:$Target"
exit 0
'@ | Set-Content -Encoding UTF8 -Path $RdpHandler

@'
param(
    [Parameter(Mandatory = $true)]
    [string]$Uri
)

$Target = $Uri -replace '^assweb://', ''
$Target = $Target -replace '\?.*$', ''
$Target = [System.Uri]::UnescapeDataString($Target)

if ([string]::IsNullOrWhiteSpace($Target)) {
    exit 1
}

if ($Target -notmatch '^https?://') {
    exit 1
}

Start-Process $Target
exit 0
'@ | Set-Content -Encoding UTF8 -Path $WebHandler

function Write-VbsWrapper {
    param(
        [Parameter(Mandatory = $true)]
        [string]$WrapperPath,

        [Parameter(Mandatory = $true)]
        [string]$ScriptPath
    )

    $Vbs = @'
Set shell = CreateObject("WScript.Shell")
If WScript.Arguments.Count < 2 Then
    WScript.Quit 1
End If

scriptPath = WScript.Arguments(0)
uri = WScript.Arguments(1)

If InStr(scriptPath, """") > 0 Or InStr(scriptPath, vbCr) > 0 Or InStr(scriptPath, vbLf) > 0 Then
    WScript.Quit 1
End If

If InStr(uri, """") > 0 Or InStr(uri, vbCr) > 0 Or InStr(uri, vbLf) > 0 Then
    WScript.Quit 1
End If

cmd = "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & scriptPath & """ """ & uri & """"
shell.Run cmd, 0, False
'@

    $Vbs | Set-Content -Encoding ASCII -Path $WrapperPath
}

Write-VbsWrapper -WrapperPath $SshWrapper -ScriptPath $SshHandler
Write-VbsWrapper -WrapperPath $RdpWrapper -ScriptPath $RdpHandler
Write-VbsWrapper -WrapperPath $WebWrapper -ScriptPath $WebHandler

function Register-UriScheme {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Scheme,

        [Parameter(Mandatory = $true)]
        [string]$DisplayName,

        [Parameter(Mandatory = $true)]
        [string]$HandlerPath,

        [string]$ScriptPath = ""
    )

    $Root = "HKCU:\Software\Classes\$Scheme"
    if ($HandlerPath -like "*.vbs") {
        $WScript = Join-Path $env:SystemRoot "System32\wscript.exe"
        $Command = "`"$WScript`" `"$HandlerPath`" `"$ScriptPath`" `"%1`""
    } else {
        $Command = "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$HandlerPath`" `"%1`""
    }

    New-Item -Force -Path $Root | Out-Null
    New-ItemProperty -Force -Path $Root -Name "(default)" -Value "URL:$DisplayName" -PropertyType String | Out-Null
    New-ItemProperty -Force -Path $Root -Name "URL Protocol" -Value "" -PropertyType String | Out-Null

    New-Item -Force -Path "$Root\shell\open\command" | Out-Null
    New-ItemProperty -Force -Path "$Root\shell\open\command" -Name "(default)" -Value $Command -PropertyType String | Out-Null
}

Register-UriScheme -Scheme "assssh" -DisplayName "ASS-CMO SSH Protocol" -HandlerPath $SshWrapper -ScriptPath $SshHandler
Register-UriScheme -Scheme "assrdp" -DisplayName "ASS-CMO RDP Protocol" -HandlerPath $RdpWrapper -ScriptPath $RdpHandler
Register-UriScheme -Scheme "assweb" -DisplayName "ASS-CMO Web Protocol" -HandlerPath $WebWrapper -ScriptPath $WebHandler

Write-Host "ASS-CMO URI handlers installed for current user."
Write-Host "SSH: assssh://10.20.30.10"
Write-Host "RDP: assrdp://10.20.30.10"
Write-Host "WEB: assweb://https%3A%2F%2Fexample.com%2F"
