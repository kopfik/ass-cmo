# ASS-CMO Windows Agent Installer
# Installs the ASS-CMO Windows agent and registers a scheduled task.

param(
    [string]$BaseUrl = "https://ass-cmo.example.com",
    [string]$InstallDir = "$env:ProgramData\ASS-CMO",
    [string]$TaskName = "ASS-CMO-Agent",
    [switch]$NoRun
)

$ErrorActionPreference = "Stop"
$DefaultAgentName = "windows-powershell"
$DefaultAgentChannel = "stable"
$DefaultPollInterval = 5
$DefaultEnrollTimeout = 1800

function Test-IsAdministrator {
    $Identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $Principal = New-Object Security.Principal.WindowsPrincipal($Identity)
    return $Principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Download-AssCmoFile {
    param(
        [Parameter(Mandatory = $true)][string]$Uri,
        [Parameter(Mandatory = $true)][string]$OutFile,
        [int]$Retries = 3,
        [int]$TimeoutSec = 120
    )

    for ($Attempt = 1; $Attempt -le $Retries; $Attempt++) {
        try {
            Write-Host "Downloading $Uri"
            Invoke-WebRequest -UseBasicParsing -Uri $Uri -OutFile $OutFile -TimeoutSec $TimeoutSec
            $Item = Get-Item -LiteralPath $OutFile -ErrorAction Stop
            if ($Item.Length -le 0) {
                throw "Downloaded file is empty: $OutFile"
            }
            return
        } catch {
            if ($Attempt -ge $Retries) {
                throw
            }
            Write-Warning "Download failed, retrying ($Attempt/$Retries): $Uri"
            Start-Sleep -Seconds 2
        }
    }
}

function Set-AssCmoSecureAcl {
    param(
        [Parameter(Mandatory = $true)][string]$Path
    )

    $Item = Get-Item -LiteralPath $Path -ErrorAction Stop
    $Acl = Get-Acl -LiteralPath $Path

    # Disable inherited writable permissions. Keep ASS-CMO agent files writable only by
    # LocalSystem and the built-in Administrators group. The config file is dot-sourced
    # by the scheduled task, so write access to this directory is code execution.
    $Acl.SetAccessRuleProtection($true, $false)

    foreach ($Rule in @($Acl.Access)) {
        [void]$Acl.RemoveAccessRuleAll($Rule)
    }

    $SystemSid = New-Object System.Security.Principal.SecurityIdentifier("S-1-5-18")
    $AdminsSid = New-Object System.Security.Principal.SecurityIdentifier("S-1-5-32-544")

    if ($Item.PSIsContainer) {
        $InheritanceFlags = [System.Security.AccessControl.InheritanceFlags]"ContainerInherit,ObjectInherit"
    } else {
        $InheritanceFlags = [System.Security.AccessControl.InheritanceFlags]"None"
    }

    $PropagationFlags = [System.Security.AccessControl.PropagationFlags]"None"
    $AccessType = [System.Security.AccessControl.AccessControlType]"Allow"
    $Rights = [System.Security.AccessControl.FileSystemRights]"FullControl"

    $Acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule($SystemSid, $Rights, $InheritanceFlags, $PropagationFlags, $AccessType)))
    $Acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule($AdminsSid, $Rights, $InheritanceFlags, $PropagationFlags, $AccessType)))

    Set-Acl -LiteralPath $Path -AclObject $Acl
}

function Protect-AssCmoInstallTree {
    param(
        [Parameter(Mandatory = $true)][string]$InstallDir,
        [string[]]$KnownFiles = @()
    )

    Set-AssCmoSecureAcl -Path $InstallDir

    foreach ($File in $KnownFiles) {
        if (Test-Path -LiteralPath $File) {
            Set-AssCmoSecureAcl -Path $File
        }
    }
}

function Get-AssCmoHttpStatusCode {
    param(
        [Parameter(Mandatory = $true)]$Exception
    )

    if ($null -ne $Exception.Response -and $null -ne $Exception.Response.StatusCode) {
        return [int]$Exception.Response.StatusCode
    }

    if ($null -ne $Exception.Exception -and $null -ne $Exception.Exception.Response -and $null -ne $Exception.Exception.Response.StatusCode) {
        return [int]$Exception.Exception.Response.StatusCode
    }

    return 0
}

function Invoke-AssCmoJsonRequest {
    param(
        [Parameter(Mandatory = $true)][string]$Uri,
        [Parameter(Mandatory = $true)][ValidateSet("GET", "POST")][string]$Method,
        [string]$Body = "",
        [hashtable]$Headers = @{}
    )

    try {
        if ($Method -eq "POST") {
            $Response = Invoke-WebRequest -UseBasicParsing -Uri $Uri -Method Post -Body $Body -ContentType "application/json; charset=utf-8" -Headers $Headers -TimeoutSec 30
        } else {
            $Response = Invoke-WebRequest -UseBasicParsing -Uri $Uri -Method Get -Headers $Headers -TimeoutSec 30
        }
    } catch {
        $StatusCode = Get-AssCmoHttpStatusCode -Exception $_
        return [pscustomobject]@{
            StatusCode = $StatusCode
            Content    = ""
            Json       = $null
        }
    }

    $Content = if ($null -ne $Response.Content) { [string]$Response.Content } else { "" }
    $Json = $null

    if (-not [string]::IsNullOrWhiteSpace($Content)) {
        try {
            $Json = $Content | ConvertFrom-Json -ErrorAction Stop
        } catch {
            $Json = $null
        }
    }

    return [pscustomobject]@{
        StatusCode = [int]$Response.StatusCode
        Content    = $Content
        Json       = $Json
    }
}

function ConvertTo-AssCmoConfigString {
    param(
        [Parameter(Mandatory = $true)][AllowEmptyString()][string]$Value
    )

    return $Value.Replace('`', '``').Replace('"', '`"').Replace('$', '`$')
}

function Get-AssCmoEnrollmentIdentity {
    $Csp = $null
    try {
        $Csp = Get-CimInstance Win32_ComputerSystemProduct -ErrorAction Stop
    } catch {
        $Csp = $null
    }

    $Uid = if ($Csp -and $Csp.UUID -and $Csp.UUID -ne "FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF") {
        [string]$Csp.UUID
    } elseif ($Csp -and $Csp.IdentifyingNumber) {
        [string]$Csp.IdentifyingNumber
    } else {
        [string]$env:COMPUTERNAME
    }

    $Hostname = [string]$env:COMPUTERNAME.ToLower()
    $Domain = [string]$env:USERDNSDOMAIN
    $Fqdn = if ($Domain -and $Domain.Trim() -ne "") {
        "$Hostname.$($Domain.ToLower())"
    } else {
        $Hostname
    }

    return [pscustomobject]@{
        Uid      = $Uid
        Hostname = $Hostname
        Fqdn     = $Fqdn
    }
}

function Write-AssCmoAgentConfig {
    param(
        [Parameter(Mandatory = $true)][string]$ConfigPath,
        [Parameter(Mandatory = $true)][string]$BaseUrl,
        [Parameter(Mandatory = $true)][string]$AgentSecret
    )

    $ConfigDir = Split-Path -Parent $ConfigPath
    $TempPath = Join-Path $ConfigDir ("agent.conf.ps1.tmp." + [guid]::NewGuid().ToString())
    $EscapedBaseUrl = ConvertTo-AssCmoConfigString -Value $BaseUrl
    $EscapedAgentSecret = ConvertTo-AssCmoConfigString -Value $AgentSecret
    $EscapedAgentName = ConvertTo-AssCmoConfigString -Value $DefaultAgentName
    $EscapedAgentChannel = ConvertTo-AssCmoConfigString -Value $DefaultAgentChannel
    $ConfigTemplate = @'
# ASS-CMO Windows agent configuration.
# Written by the ASS-CMO Windows installer after successful enrollment.

$ASSCMO_BASE_URL = "{0}"
$ASSCMO_AGENT_SECRET = "{1}"
$ASSCMO_INVENTORY_TOKEN = ""

$ASSCMO_AGENT_CHANNEL = "{2}"
$ASSCMO_AGENT_NAME = "{3}"
'@
    $ConfigContent = [string]::Format($ConfigTemplate, $EscapedBaseUrl, $EscapedAgentSecret, $EscapedAgentChannel, $EscapedAgentName)

    try {
        Set-Content -LiteralPath $TempPath -Value $ConfigContent -Encoding UTF8 -NoNewline
        Move-Item -LiteralPath $TempPath -Destination $ConfigPath -Force
    } catch {
        Remove-Item -LiteralPath $TempPath -Force -ErrorAction SilentlyContinue
        throw
    }
}

if (-not (Test-IsAdministrator)) {
    Write-Error "This installer must be run as Administrator."
    exit 1
}

$BaseUrl = $BaseUrl.TrimEnd("/")
$AgentUrl = "$BaseUrl/agents/windows/ass-cmo-agent.ps1"
$VersionUrl = "$BaseUrl/agents/windows/VERSION"

$ConfigPath = Join-Path $InstallDir "agent.conf.ps1"
$AgentPath = Join-Path $InstallDir "ass-cmo-agent.ps1"
$VersionPath = Join-Path $InstallDir "VERSION"

try {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
} catch {
    # Ignore on newer PowerShell/.NET versions where this may be unnecessary.
}

Write-Host "Installing ASS-CMO Windows agent..."
Write-Host "Base URL: $BaseUrl"
Write-Host "Install directory: $InstallDir"

New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
Protect-AssCmoInstallTree -InstallDir $InstallDir

if (Test-Path $ConfigPath) {
    Write-Host "Keeping existing config: $ConfigPath"
} else {
    $Identity = Get-AssCmoEnrollmentIdentity
    $AgentVersion = "unknown-install"

    try {
        $VersionResponse = Invoke-WebRequest -UseBasicParsing -Uri $VersionUrl -TimeoutSec 30
        if ($null -ne $VersionResponse.Content -and "$($VersionResponse.Content)".Trim() -ne "") {
            $AgentVersion = [string]$VersionResponse.Content.Trim()
        }
    } catch {
        Write-Warning "Could not fetch agent version before enrollment, continuing with fallback version value."
    }

    $StartPayload = @{
        uid           = [string]$Identity.Uid
        hostname      = [string]$Identity.Hostname
        fqdn          = [string]$Identity.Fqdn
        os_type       = "windows"
        agent_version = [string]$AgentVersion
    } | ConvertTo-Json -Depth 5 -Compress

    $StartResponse = Invoke-AssCmoJsonRequest -Uri "$BaseUrl/enroll.php" -Method POST -Body $StartPayload
    if ($StartResponse.StatusCode -ne 200) {
        Write-Error "Enrollment start failed (HTTP $($StartResponse.StatusCode))"
        exit 1
    }

    $RequestId = if ($StartResponse.Json -and $null -ne $StartResponse.Json.request_id) { [string]$StartResponse.Json.request_id } else { "" }
    $PollToken = if ($StartResponse.Json -and $null -ne $StartResponse.Json.poll_token) { [string]$StartResponse.Json.poll_token } else { "" }
    $PairingCode = if ($StartResponse.Json -and $null -ne $StartResponse.Json.pairing_code) { [string]$StartResponse.Json.pairing_code } else { "" }
    $PollInterval = $DefaultPollInterval
    $EnrollTimeout = $DefaultEnrollTimeout

    if ($StartResponse.Json -and $null -ne $StartResponse.Json.poll_interval) {
        try {
            $PollInterval = [int]$StartResponse.Json.poll_interval
        } catch {
            $PollInterval = $DefaultPollInterval
        }
    }

    if ($StartResponse.Json -and $null -ne $StartResponse.Json.expires_in) {
        try {
            $EnrollTimeout = [int]$StartResponse.Json.expires_in
        } catch {
            $EnrollTimeout = $DefaultEnrollTimeout
        }
    }

    if ([string]::IsNullOrWhiteSpace($RequestId) -or [string]::IsNullOrWhiteSpace($PollToken) -or [string]::IsNullOrWhiteSpace($PairingCode)) {
        Write-Error "Enrollment start response is missing required fields."
        exit 1
    }

    if ($PollInterval -le 0) {
        $PollInterval = $DefaultPollInterval
    }
    if ($EnrollTimeout -le 0) {
        $EnrollTimeout = $DefaultEnrollTimeout
    }

    $VerificationUrl = if ($StartResponse.Json -and $null -ne $StartResponse.Json.verification_url) { [string]$StartResponse.Json.verification_url } else { "" }

    Write-Host "Enrollment pairing code: $PairingCode"
    if (-not [string]::IsNullOrWhiteSpace($VerificationUrl)) {
        Write-Host "Approve this enrollment at: $VerificationUrl"
    } else {
        Write-Host "Approve this pending enrollment in the ASS-CMO admin UI for $BaseUrl"
    }

    $Deadline = (Get-Date).AddSeconds($EnrollTimeout)
    $PollRequestId = [uri]::EscapeDataString($RequestId)
    $PollUri = "$BaseUrl/enroll.php?action=poll&request_id=$PollRequestId"

    while ($true) {
        $PollResponse = Invoke-AssCmoJsonRequest -Uri $PollUri -Method GET -Headers @{ "X-Poll-Token" = $PollToken }

        switch ($PollResponse.StatusCode) {
            200 {
                $Status = if ($PollResponse.Json -and $null -ne $PollResponse.Json.status) { [string]$PollResponse.Json.status } else { "" }
                switch ($Status) {
                    "pending" {
                        if ((Get-Date) -ge $Deadline) {
                            Write-Error "Enrollment approval timed out."
                            exit 1
                        }
                        Start-Sleep -Seconds $PollInterval
                    }
                    "denied" {
                        Write-Error "Enrollment request was denied."
                        exit 1
                    }
                    "approved" {
                        $AgentSecret = if ($PollResponse.Json -and $null -ne $PollResponse.Json.agent_secret) { [string]$PollResponse.Json.agent_secret } else { "" }
                        if ([string]::IsNullOrWhiteSpace($AgentSecret)) {
                            Write-Error "Approved enrollment response did not include agent_secret."
                            exit 1
                        }
                        Write-AssCmoAgentConfig -ConfigPath $ConfigPath -BaseUrl $BaseUrl -AgentSecret $AgentSecret
                        Protect-AssCmoInstallTree -InstallDir $InstallDir -KnownFiles @($ConfigPath)
                        Write-Host "Enrollment approved and local agent config created: $ConfigPath"
                        break
                    }
                    default {
                        Write-Error "Unexpected enrollment poll status."
                        exit 1
                    }
                }
            }
            404 {
                Write-Error "Enrollment request expired or was not found."
                exit 1
            }
            default {
                Write-Error "Enrollment poll failed (HTTP $($PollResponse.StatusCode))."
                exit 1
            }
        }

        if (Test-Path $ConfigPath) {
            break
        }
    }
}

Write-Host "Downloading agent..."
Download-AssCmoFile -Uri $AgentUrl -OutFile $AgentPath

Write-Host "Downloading version..."
Download-AssCmoFile -Uri $VersionUrl -OutFile $VersionPath

$KnownInstallFiles = @($ConfigPath, $AgentPath, $VersionPath)
Protect-AssCmoInstallTree -InstallDir $InstallDir -KnownFiles $KnownInstallFiles

try {
    Unblock-File -Path $ConfigPath -ErrorAction SilentlyContinue
    Unblock-File -Path $AgentPath -ErrorAction SilentlyContinue
    Unblock-File -Path $VersionPath -ErrorAction SilentlyContinue
} catch {
    # Ignore if Unblock-File is unavailable.
}

$ActionArgs = "-NoProfile -ExecutionPolicy Bypass -File `"$AgentPath`""
$Action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument $ActionArgs

$Triggers = @()
$Triggers += New-ScheduledTaskTrigger -AtStartup
$Triggers += New-ScheduledTaskTrigger -Daily -At "00:01"
$Triggers += New-ScheduledTaskTrigger -Daily -At "12:01"

$Settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew

$Principal = New-ScheduledTaskPrincipal `
    -UserId "SYSTEM" `
    -LogonType ServiceAccount `
    -RunLevel Highest

$ExistingTask = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($ExistingTask) {
    Write-Host "Removing existing scheduled task: $TaskName"
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

Write-Host "Registering scheduled task: $TaskName"
Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $Action `
    -Trigger $Triggers `
    -Settings $Settings `
    -Principal $Principal `
    -Description "ASS-CMO Windows inventory agent" | Out-Null

if (-not $NoRun) {
    Write-Host "Running first inventory update..."
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $AgentPath
}

Write-Host "OK - ASS-CMO Windows agent installed."
exit 0
