# ASS-CMO

**Admins Secure Server Connection Manager & Overview**

ASS-CMO is a small server inventory, overview and connection dashboard for administrators.

The long-term idea is to grow into a practical replacement for tools such as PuTTY session lists and Remote Desktop Manager-style connection catalogs. Those tools are useful, but they usually know very little about the machines behind the saved connections. ASS-CMO takes the opposite approach: every machine reports fresh inventory data first, and the dashboard then uses that data to provide useful connection actions, status visibility and fast navigation.

ASS-CMO is intentionally not a full RMM. It does not provide arbitrary remote command execution from the server by default. Agents report inventory data to the server, and administrators connect to machines from their own workstation using local SSH/RDP clients and registered URI handlers.

## Current status

Current version is tracked in the meta/VERSION file and in [CHANGELOG.md](CHANGELOG.md).

The core stack is installable and usable:

- PostgreSQL inventory database
- PHP inventory endpoint and dashboard frontend
- nginx reverse proxy
- Adminer for database inspection
- Linux inventory agent
- Windows inventory agent
- systemd timer for Linux agents
- apt and pacman package hooks
- Windows Scheduled Task for Windows agents
- read-only dashboard SQL views
- dashboard table filtering and sorting
- command launcher
- PWA metadata for standalone browser-app installation
- SSH/RDP/application action buttons
- Linux and Windows URI handler installers for `assssh://` and `assrdp://`

Optional Grafana and TIM stack overlays are present as project skeletons, but they are advanced/lab-oriented until hardened and are not the primary focus of the current version.

## How it works

ASS-CMO is built around one central PostgreSQL inventory table.

Linux and Windows agents collect system information locally and submit it to the server over HTTPS using a per-host secret provisioned during enrollment. Agents are intentionally simple: the Linux agent is shell-based, and the Windows agent is PowerShell-based.

Typical inventory data includes:

- hostname and FQDN
- primary IP address and gateway
- all detected IPv4/IPv6 addresses
- DNS servers
- listening ports
- operating system and kernel/version
- reboot-required status
- pending update count where available
- CPU, RAM and disk usage
- Docker presence/version
- uptime and last upgrade time
- location and network segment derived from `sites.json`

The dashboard is a presentation layer over the database. It loads predefined or custom read-only SQL views from:

```text
config.local/dashboard-views/*.sql
```

Each SQL view becomes a dashboard page. This makes the frontend simple: the database contains the inventory, SQL defines the views, and the browser displays the result.

## Dashboard

The dashboard is a single-page frontend with selectable SQL views.

Implemented UI features include:

- view selector
- sortable table columns
- live row filtering
- compact status columns
- theme switcher
- PWA/app metadata
- command launcher

The command launcher opens with:

```text
Ctrl+K / Cmd+K
```

It searches the current dashboard view and lets the administrator quickly run available actions for a machine, such as SSH, RDP or custom application links parsed from inventory notes.

## Connection actions

ASS-CMO does not store SSH private keys, RDP passwords or remote machine credentials.

Instead, the dashboard generates local connection links:

```text
assssh://server-or-user-at-server
assrdp://server
```

The administrator registers URI handlers on their own workstation. When a dashboard action is clicked, the local workstation opens the matching SSH or RDP client.

Linux handlers use local terminal/OpenSSH and Remmina or FreeRDP for RDP.

Windows handlers use Windows Terminal/OpenSSH and `mstsc.exe`.

## Workstation requirements

For SSH actions, the workstation must already be able to connect to the target machine.

On Linux this usually means standard OpenSSH configuration in:

```text
~/.ssh/
```

On Windows, ASS-CMO expects OpenSSH-compatible key files in the user profile as well, for example:

```text
C:\Users\<user>\.ssh\id_ed25519
C:\Users\<user>\.ssh\config
C:\Users\<user>\.ssh\known_hosts
```

In practice, Windows administrators should use normal OpenSSH key format, not PuTTY `.ppk` format.

RDP credentials are handled by Windows itself. On domain-managed Windows clients, saving RDP credentials may require local or domain policy changes for the relevant `TERMSRV/...` targets.

## Agent deployment

Secret-free agent installer and helper scripts are served from the ASS-CMO web server:

```text
/agents/linux/
/agents/windows/
```

Agent configuration files are not served from the web root. These URLs return 404:

```text
/agents/linux/agent.conf
/agents/windows/agent.conf.ps1
```

Agent configuration is generated locally on the managed host during enrollment. The installer initiates a pending request, the admin approves it in the dashboard UI, and the agent writes its per-host secret locally after approval.

Automatic agent updater is not shipped in public v0.8.0. It was removed/deferred until a secure update channel, integrity/signature verification model, rollback behavior, and trust boundaries are designed.

Linux agents are installed with:

- `/etc/ass-cmo/agent.conf`
- `/usr/local/sbin/ass-cmo-agent`
- `ass-cmo-agent.service`
- `ass-cmo-agent.timer`
- apt or pacman hook where applicable

Windows agents are installed with:

- `C:\ProgramData\ASS-CMO\agent.conf.ps1`
- `C:\ProgramData\ASS-CMO\ass-cmo-agent.ps1`
- Scheduled Task `ASS-CMO-Agent`

## Installation

See [INSTALL.md](INSTALL.md).

The core installer is:

```bash
./install.sh
```

The installer prepares local runtime configuration, creates server-side secrets, configures nginx examples, prepares dashboard views and starts the core stack. Agent configuration is not published from the web server; it is generated locally on each managed host during enrollment.

Runtime configuration is stored in:

```text
config.local/.env
```

The root `.env` should be a symlink to `config.local/.env`.

## Optional overlays

### Grafana

A Grafana Compose overlay exists in the project as a future visualization layer. It is available as a skeleton, but the current project does not yet provide a mature set of dashboards or usage templates.

### TIM stack

The optional TIM stack overlay contains the project skeleton for:

- Telegraf
- InfluxDB
- Mosquitto MQTT

This is currently present for future expansion and experimentation. It is not required for the ASS-CMO core dashboard and does not yet have a polished usage template.

## Security

See [SECURITY.md](SECURITY.md).

ASS-CMO is designed as an inventory and connection overview tool, not as a remote execution platform. Keep the inventory endpoint behind HTTPS, protect agent secrets and enrollment tokens, and restrict access to the dashboard.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Roadmap

```text
v0.5.x  first installable and usable ASS-CMO version
v0.6.x  dashboard and launcher refinements
v0.7.x  internal release and hotfixes
v0.8.0  secure enrollment, per-host secrets, revocation, shared-token migration and clean public GitHub release gate
v1.0.0  later stable release
```

## Optional assweb:// web handler

ASS-CMO can optionally render notes web actions through the local `assweb://` URI handler instead of normal `https://` links.

This is useful when ASS-CMO runs as an installed PWA and web administration links should open in the system default browser instead of replacing the PWA window.

Enable it in `.env`:

    ASSCMO_ASSWEB_PROTOCOL=true

Default is disabled:

    ASSCMO_ASSWEB_PROTOCOL=false

When enabled, notes entries such as:

    PVE: https://pve.example.local:8006/

are rendered as an `assweb://` action. The local handler decodes the target URL and opens it with the system default browser.

The bundled handler installer also supports local customization protection. If existing handler scripts are found, the installer exits without changing anything. To replace all local handlers with the bundled versions, run it with:

    ASSCMO_OVERWRITE_HANDLERS=1 sh install-ass-cmo-uri-handlers.sh

On Windows, set:

    $env:ASSCMO_OVERWRITE_HANDLERS = "1"

before running the handler installer.
