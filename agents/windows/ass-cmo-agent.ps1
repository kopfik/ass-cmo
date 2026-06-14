# ASS-CMO Windows Agent
# Inventory-only agent for Windows hosts.
# No remote command execution. No TLS validation bypass.

$ErrorActionPreference = "Stop"

# --- Load config from the same directory as this script ---

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ConfigFile = Join-Path $ScriptDir "agent.conf.ps1"

if (-not (Test-Path $ConfigFile)) {
    Write-Error "Config file not found: $ConfigFile"
    exit 1
}

. $ConfigFile

$VersionFile = Join-Path $ScriptDir "VERSION"

if (Test-Path $VersionFile) {
    $AgentVersion = (Get-Content $VersionFile -Raw).Trim()
} else {
    $AgentVersion = "unknown"
}

$AgentName = if ($ASSCMO_AGENT_NAME) { [string]$ASSCMO_AGENT_NAME } else { "windows-powershell" }
$AgentChannel = if ($ASSCMO_AGENT_CHANNEL) { [string]$ASSCMO_AGENT_CHANNEL } else { "stable" }
$AgentUpdateTime = $null

if (-not $ASSCMO_BASE_URL) {
    Write-Error "ASSCMO_BASE_URL is not set in $ConfigFile"
    exit 1
}

$AgentSecret = if ($ASSCMO_AGENT_SECRET) { [string]$ASSCMO_AGENT_SECRET } else { "" }
$InventoryToken = if ($ASSCMO_INVENTORY_TOKEN) { [string]$ASSCMO_INVENTORY_TOKEN } else { "" }

if ([string]::IsNullOrWhiteSpace($AgentSecret) -and [string]::IsNullOrWhiteSpace($InventoryToken)) {
    Write-Error "ASSCMO_AGENT_SECRET or ASSCMO_INVENTORY_TOKEN must be set in $ConfigFile"
    exit 1
}

$InvUrl = ($ASSCMO_BASE_URL.TrimEnd("/")) + "/inventory.php"

# --- Helpers ---

function Get-FirstOrNull {
    param (
        [Parameter(ValueFromPipeline = $true)]
        $Value
    )

    process {
        if ($null -ne $Value) {
            return $Value
        }
    }

    end {
        return $null
    }
}

function To-StringArray {
    param (
        $Value
    )

    if ($null -eq $Value) {
        return @()
    }

    if ($Value -is [array]) {
        return @($Value | Where-Object { $_ -ne $null -and "$_".Trim() -ne "" } | ForEach-Object { [string]$_ })
    }

    if ("$Value".Trim() -eq "") {
        return @()
    }

    return @([string]$Value)
}

function Test-RebootRequired {
    $Paths = @(
        "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired",
        "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending",
        "HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager"
    )

    foreach ($Path in $Paths) {
        try {
            if ($Path -eq "HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager") {
                $PendingRename = Get-ItemProperty -Path $Path -Name "PendingFileRenameOperations" -ErrorAction SilentlyContinue
                if ($null -ne $PendingRename) {
                    return $true
                }
            } else {
                if (Test-Path $Path) {
                    return $true
                }
            }
        } catch {
            # Ignore registry access errors and continue.
        }
    }

    return $false
}

function Get-LocalAdministrators {
    try {
        $AdminSid = New-Object System.Security.Principal.SecurityIdentifier("S-1-5-32-544")
        $AdminGroup = $AdminSid.Translate([System.Security.Principal.NTAccount])
        $AdminGroupName = $AdminGroup.Value.Split("\")[-1]

        $Raw = net localgroup $AdminGroupName 2>$null

        return @(
            $Raw |
                Where-Object {
                    $_ -and
                    $_ -notmatch "^-+$" -and
                    $_ -notmatch "Alias name|Comment|Members|The command completed|P.íkaz byl" -and
                    $_.Trim() -ne ""
                } |
                ForEach-Object { $_.Trim() }
        )
    } catch {
        return @()
    }
}

function Get-DockerVersion {
    try {
        $DockerCmd = Get-Command docker -ErrorAction SilentlyContinue
        if (-not $DockerCmd) {
            return "none"
        }

        $VersionOutput = docker -v 2>$null
        if (-not $VersionOutput) {
            return "unknown"
        }

        return (($VersionOutput -split " ")[2]).Trim(",")
    } catch {
        return "unknown"
    }
}

function Get-SystemUpgradeTime {
    try {
        $HotFix = Get-CimInstance Win32_QuickFixEngineering -ErrorAction SilentlyContinue |
            Where-Object { $_.InstalledOn } |
            Sort-Object InstalledOn -Descending |
            Select-Object -First 1

        if ($HotFix -and $HotFix.InstalledOn) {
            return ([datetime]$HotFix.InstalledOn).ToString("yyyy-MM-ddTHH:mm:ssK")
        }
    } catch {
        return $null
    }

    return $null
}

# --- Collect system data ---

try {
    $OS = Get-CimInstance Win32_OperatingSystem
    $CS = Get-CimInstance Win32_ComputerSystem
    $CPU = Get-CimInstance Win32_Processor | Select-Object -First 1
    $Disk = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='C:'"
    $Csp = Get-CimInstance Win32_ComputerSystemProduct

    $NetConfig = Get-CimInstance Win32_NetworkAdapterConfiguration |
        Where-Object { $_.IPEnabled -eq $true -and $_.DefaultIPGateway -ne $null } |
        Select-Object -First 1

    $AllIPs = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object {
            $_.IPAddress -notmatch "^127\." -and
            $_.InterfaceAlias -notmatch "Loopback|vEthernet"
        }

    $AllIP6s = Get-NetIPAddress -AddressFamily IPv6 -ErrorAction SilentlyContinue |
        Where-Object {
            $_.IPAddress -notlike "fe80*" -and
            $_.InterfaceAlias -notmatch "Loopback|vEthernet"
        }

    $IP4 = if ($NetConfig) {
        $NetConfig.IPAddress |
            Where-Object { $_ -match "^\d+\.\d+\.\d+\.\d+$" } |
            Select-Object -First 1
    } else {
        $null
    }

    $IP6 = if ($NetConfig) {
        $NetConfig.IPAddress |
            Where-Object { $_ -match ":" -and $_ -notlike "fe80*" } |
            Select-Object -First 1
    } else {
        $null
    }

    $GW4 = if ($NetConfig) {
        $NetConfig.DefaultIPGateway |
            Where-Object { $_ -match "^\d+\.\d+\.\d+\.\d+$" } |
            Select-Object -First 1
    } else {
        $null
    }

    $GW6 = if ($NetConfig) {
        $NetConfig.DefaultIPGateway |
            Where-Object { $_ -match ":" } |
            Select-Object -First 1
    } else {
        $null
    }

    $DNS = @(
        Get-DnsClientServerAddress -ErrorAction SilentlyContinue |
            Where-Object { $_.ServerAddresses -ne $null } |
            ForEach-Object { $_.ServerAddresses } |
            Sort-Object -Unique
    )

    $Ports = @(
        Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue |
            Select-Object -ExpandProperty LocalPort |
            Sort-Object -Unique
    )

    $Culture = [System.Globalization.CultureInfo]::InvariantCulture

    $DiskTotal = if ($Disk -and $Disk.Size) { [math]::Round($Disk.Size / 1GB, 2) } else { 0 }
    $DiskFree = if ($Disk -and $Disk.FreeSpace) { [math]::Round($Disk.FreeSpace / 1GB, 2) } else { 0 }
    $DiskUsed = [math]::Round($DiskTotal - $DiskFree, 2)
    $DiskPerc = if ($DiskTotal -gt 0) { [int](($DiskUsed / $DiskTotal) * 100) } else { 0 }

    $UptimeSec = [int64]((Get-Date) - $OS.LastBootUpTime).TotalSeconds

    $UID = if ($Csp.UUID -and $Csp.UUID -ne "FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF") {
        $Csp.UUID
    } elseif ($Csp.IdentifyingNumber) {
        $Csp.IdentifyingNumber
    } else {
        $env:COMPUTERNAME
    }

    $Hostname = [string]$env:COMPUTERNAME.ToLower()
    $Domain = [string]$env:USERDNSDOMAIN
    $Fqdn = if ($Domain -and $Domain.Trim() -ne "") {
        "$Hostname.$($Domain.ToLower())"
    } else {
        $Hostname
    }

    $MachineKind = if (
        $CS.Model -match "Virtual|VMware|KVM|QEMU|Hyper-V|VirtualBox" -or
        $CS.Manufacturer -match "QEMU|VMware|Microsoft|Google|Amazon|innotek"
    ) {
        "virtual"
    } else {
        "physical"
    }

    $DockerInstalled = [bool](Get-Command docker -ErrorAction SilentlyContinue)
    $DockerVersion = Get-DockerVersion

    # --- Build JSON payload ---

    $PayloadObj = @{
        uid                   = [string]$UID
        hostname              = [string]$Hostname
        fqdn                  = [string]$Fqdn

        primary_interface     = if ($NetConfig) { [string]$NetConfig.Description } else { $null }
        primary_ipv4_addr     = if ($IP4) { [string]$IP4 } else { $null }
        ipv4_gateway          = if ($GW4) { [string]$GW4 } else { $null }
        primary_ipv6_addr     = if ($IP6) { [string]$IP6 } else { $null }
        ipv6_gateway          = if ($GW6) { [string]$GW6 } else { $null }

        dns_servers           = @(To-StringArray $DNS)
        all_ipv4_addr         = @(To-StringArray $AllIPs.IPAddress)
        all_ipv6_addr         = @(To-StringArray $AllIP6s.IPAddress)
        listening_ports       = @($Ports | ForEach-Object { [int]$_ })

        os_name               = [string]$OS.Caption
        os_type               = [string]$MachineKind
        kernel_version        = [string]$OS.Version
        reboot_required       = [bool](Test-RebootRequired)
        pending_updates_count = 0

        cpu_model             = if ($CPU.Name) { [string]$CPU.Name.Trim() } else { $null }
        cpu_cores             = [int]$CS.NumberOfLogicalProcessors
        cpu_architecture      = if ($CPU.AddressWidth -eq 64) { "x86_64" } else { "x86" }
        ram_gb                = [double]([math]::Round($CS.TotalPhysicalMemory / 1GB, 2).ToString($Culture))

        disk_total_gb         = [double]$DiskTotal.ToString($Culture)
        disk_used_gb          = [double]$DiskUsed.ToString($Culture)
        disk_free_gb          = [double]$DiskFree.ToString($Culture)
        disk_usage_percent    = [int]$DiskPerc

        docker_installed      = [bool]$DockerInstalled
        docker_version        = [string]$DockerVersion

        admin_access          = @(Get-LocalAdministrators)

        uptime_seconds        = [int64]$UptimeSec
        system_boot_time      = $OS.LastBootUpTime.ToString("yyyy-MM-ddTHH:mm:ssK")
        system_upgrade_time   = Get-SystemUpgradeTime

        agent_name            = [string]$AgentName
        agent_version         = [string]$AgentVersion
        agent_channel         = [string]$AgentChannel
        agent_update_time     = $AgentUpdateTime
    }

    $PayloadJson = $PayloadObj | ConvertTo-Json -Depth 10 -Compress

    # --- Send inventory ---

    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    } catch {
        # Ignore on newer PowerShell/.NET versions where this may be unnecessary.
    }

    $Headers = @{}
    if (-not [string]::IsNullOrWhiteSpace($AgentSecret)) {
        $Headers["X-Agent-Secret"] = $AgentSecret
    } else {
        $Headers["X-Inventory-Token"] = $InventoryToken
    }

    $Response = Invoke-RestMethod `
        -Uri $InvUrl `
        -Method Post `
        -Body $PayloadJson `
        -Headers $Headers `
        -ContentType "application/json; charset=utf-8"

    Write-Host "OK - Inventory updated for UID: $UID"
    if ($Response) {
        Write-Verbose ($Response | ConvertTo-Json -Depth 5)
    }

    exit 0
} catch {
    Write-Error "ERROR - Inventory update failed: $($_.Exception.Message)"
    exit 1
}
