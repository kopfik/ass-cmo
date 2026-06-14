# ASS-CMO Windows Agent Uninstaller
# Removes the ASS-CMO scheduled task and installed agent files.

param(
    [string]$InstallDir = "$env:ProgramData\ASS-CMO",
    [string]$TaskName = "ASS-CMO-Agent",
    [switch]$KeepConfig
)

$ErrorActionPreference = "Stop"

function Test-IsAdministrator {
    $Identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $Principal = New-Object Security.Principal.WindowsPrincipal($Identity)
    return $Principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not (Test-IsAdministrator)) {
    Write-Error "This uninstaller must be run as Administrator."
    exit 1
}

$ConfigPath = Join-Path $InstallDir "agent.conf.ps1"
$AgentPath = Join-Path $InstallDir "ass-cmo-agent.ps1"

Write-Host "Uninstalling ASS-CMO Windows agent..."

$ExistingTask = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($ExistingTask) {
    Write-Host "Removing scheduled task: $TaskName"
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
} else {
    Write-Host "Scheduled task not found: $TaskName"
}

if (Test-Path $AgentPath) {
    Write-Host "Removing agent script: $AgentPath"
    Remove-Item -Force $AgentPath
}

if (-not $KeepConfig) {
    if (Test-Path $ConfigPath) {
        Write-Host "Removing config: $ConfigPath"
        Remove-Item -Force $ConfigPath
    }

    if (Test-Path $InstallDir) {
        $Remaining = Get-ChildItem -Path $InstallDir -Force -ErrorAction SilentlyContinue
        if (-not $Remaining) {
            Write-Host "Removing empty directory: $InstallDir"
            Remove-Item -Force $InstallDir
        } else {
            Write-Host "Install directory is not empty, leaving it in place: $InstallDir"
        }
    }
} else {
    Write-Host "Keeping config because -KeepConfig was used: $ConfigPath"
}

Write-Host "OK - ASS-CMO Windows agent uninstalled."
exit 0
