# ASS-CMO Installation

This document describes how to install and verify ASS-CMO.

## Contents

1. [Requirements](#requirements)
2. [Quick install](#quick-install)
3. [What the installer does](#what-the-installer-does)
4. [Post-install verification](#post-install-verification)
5. [Adminer login](#adminer-login)
6. [Agent management](#agent-management)
7. [Upgrade hooks](#upgrade-hooks)
8. [Timer behavior](#timer-behavior)
9. [Client URI handlers](#client-uri-handlers)
10. [Optional compose layers](#optional-compose-layers)
11. [Backup](#backup)
12. [Security notes](#security-notes)
13. [Manual reference](#manual-reference)
14. [Troubleshooting](#troubleshooting)
15. [Roadmap](#roadmap)
16. [Git hygiene](#git-hygiene)

---

## Requirements

Server:

- Linux host
- Docker with Docker Compose plugin
- Git
- Valid DNS records pointing to the server
- Valid TLS certificate — for example from Let's Encrypt — must already exist before running the installer

The nginx container mounts `/etc/letsencrypt` from the host. The installer looks for existing certificate names in `/etc/letsencrypt/live/` and prompts to select one. It does not automate DNS or certificate issuance.

Ports 80 and 443 must be reachable from managed hosts for agent inventory uploads and client access.

---

## Quick install

Clone the repository:

```bash
git clone <repo-url> ass-cmo
cd ass-cmo
```

Run the installer as root:

```bash
sudo ./install.sh
```

The installer is interactive. It prompts for instance hostname, database names, TLS certificate name, optional SSH user, and confirms before starting containers. All generated secrets are stored only in `config.local/.env` and are never committed.

On a reinstall, the installer keeps existing values and only fills in missing or weak ones.

<picture>
  <source media="(prefers-color-scheme: light)" srcset="docs/images/install/light/01-installer-prompts.png">
  <img alt="Installer running: hostname, database, TLS certificate, and SSH user prompts followed by secret generation and DH parameter preparation" src="docs/images/install/dark/01-installer-prompts.png">
</picture>

The numbered markers in the screenshots highlight terminal prompts where the operator provides input. They are not the same as the numbered installer behavior list below.

---

## What the installer does

On a fresh install, the installer:

1. Creates `config.local/` with all required subdirectories.
2. Copies `.env.example` to `config.local/.env` if not already present.
3. Creates a root `.env` symlink pointing to `config.local/.env` for compose compatibility.
4. Copies example nginx configuration to `config.local/nginx/` and dashboard view templates to `config.local/dashboard-views/`.
5. Prompts for instance hostname, Adminer URL, TLS certificate name, PostgreSQL database and user names, and optional default SSH user.
6. Generates strong random secrets for enrollment, application DB password, and dashboard read-only DB password. Existing strong values are preserved on reinstall.
7. Generates Diffie-Hellman parameters for nginx TLS (can take a few minutes on slow machines).
8. Creates the external Docker network (`ass-net`) if not already present.
9. Optionally starts the core stack: `postgres adminer php nginx`.
10. Waits for PostgreSQL to be ready, then applies the core database schema from `database/init/*.sql` idempotently.
11. Creates and configures the read-only dashboard database role (`POSTGRES_DASHBOARD_USER`).
12. Optionally prints first-login credentials.

The installer does not configure Grafana, InfluxDB, Telegraf, Mosquitto, or TIM/TIGM overlays. Those optional layers are advanced/lab-oriented until hardened and must be configured separately before use.

<picture>
  <source media="(prefers-color-scheme: light)" srcset="docs/images/install/light/02-credentials-and-start.png">
  <img alt="Installer printing first-login credentials, starting the core stack, and applying the database schema" src="docs/images/install/dark/02-credentials-and-start.png">
</picture>

---

## Post-install verification

Check container status:

```bash
docker compose --env-file config.local/.env ps
```

Expected running containers:

```text
ass-postgres
ass-adminer
ass-php
ass-nginx
```

<picture>
  <source media="(prefers-color-scheme: light)" srcset="docs/images/install/light/03-finished-and-verify.png">
  <img alt="Installer finished: dashboard role setup complete, config summary printed, and docker compose ps showing all four containers running" src="docs/images/install/dark/03-finished-and-verify.png">
</picture>

Verify core database tables. The command below uses the default installer values unless you already exported different `POSTGRES_USER` or `POSTGRES_DB` values:

```bash
docker exec ass-postgres psql -U "${POSTGRES_USER:-asscmo}" -d "${POSTGRES_DB:-inventory_db}" -tAc "SELECT to_regclass('public.inventory'), to_regclass('public.agent_enrollment_requests'), to_regclass('public.agent_auth'), to_regclass('public.agent_auth_history')"
```

Expected output — all four table names returned, none `NULL`:

```text
inventory|agent_enrollment_requests|agent_auth|agent_auth_history
```

If any entry shows `NULL`, the schema is incomplete. See [Troubleshooting](#troubleshooting).

Check endpoints (replace `ass-cmo.example.com` with your real hostname):

```bash
curl -Is https://ass-cmo.example.com/health.php | head -1
curl -Is https://ass-cmo.example.com/agents/linux/install-ass-cmo-agent.sh | head -1
curl -Is https://ass-cmo.example.com/agents/windows/install-ass-cmo-agent.ps1 | head -1
```

These should return `HTTP/... 200`.

Verify that token-bearing agent config paths return 404:

```bash
curl -Is https://ass-cmo.example.com/agents/linux/agent.conf | head -1
curl -Is https://ass-cmo.example.com/agents/windows/agent.conf.ps1 | head -1
```

These must return 404. If they return 200, check nginx configuration and file placement immediately.

---

## Adminer login

Adminer is the web-based database admin UI.

Open your configured Adminer URL, for example `https://adminer.example.com/`.

Use:

```text
System:   PostgreSQL
Server:   postgres
Username: value of POSTGRES_USER from config.local/.env
Password: value of POSTGRES_PASSWORD from config.local/.env
Database: value of POSTGRES_DB from config.local/.env
```

---

## Agent management

Agent configuration files containing secrets are not served over HTTP. `/agents/linux/agent.conf` and `/agents/windows/agent.conf.ps1` are expected to return 404. Only the secret-free installer scripts are served publicly.

### Provision or update a Linux agent

For fresh installs on a managed host, the agent installer initiates an enrollment request, displays a pairing code on the managed host console, and waits for admin approval in the dashboard UI. After approval, the per-host secret is written locally to `/etc/ass-cmo/agent.conf`.

For hosts that already have `/etc/ass-cmo/agent.conf`, the installer updates agent files and runs the first inventory report immediately.

Run as root on the managed Linux host (replace the hostname):

```bash
tmp="$(mktemp)" && trap 'rm -f "$tmp"' EXIT && curl -fsSL https://ass-cmo.example.com/agents/linux/install-ass-cmo-agent.sh -o "$tmp" && sh "$tmp" --base-url https://ass-cmo.example.com
```

Successful output includes:

```text
OK - Inventory updated for UID: ...
```

### Verify Linux agent

Run the agent manually:

```bash
/usr/local/sbin/ass-cmo-agent
```

Check timer:

```bash
systemctl status ass-cmo-agent.timer
```

List timer schedule:

```bash
systemctl list-timers '*ass-cmo*' --all
```

Check logs:

```bash
journalctl -u ass-cmo-agent.service -n 50 --no-pager
```

### Files installed on a managed Linux host

Common files:

```text
/etc/ass-cmo/agent.conf
/usr/local/sbin/ass-cmo-agent
/etc/systemd/system/ass-cmo-agent.service
/etc/systemd/system/ass-cmo-agent.timer
```

Debian / Ubuntu / Proxmox / PBS / PMG / PDM / OMV:

```text
/etc/apt/apt.conf.d/99ass-cmo-agent
```

Arch Linux:

```text
/etc/pacman.d/hooks/ass-cmo-agent.hook
```

### Remove Linux agent from a managed host

Run as root:

```bash
systemctl disable --now ass-cmo-agent.timer 2>/dev/null || true
```

```bash
rm -f /etc/systemd/system/ass-cmo-agent.timer /etc/systemd/system/ass-cmo-agent.service /usr/local/sbin/ass-cmo-agent /etc/ass-cmo/agent.conf /etc/apt/apt.conf.d/99ass-cmo-agent /etc/pacman.d/hooks/ass-cmo-agent.hook
```

```bash
systemctl daemon-reload
```

Optionally remove config directory:

```bash
rmdir /etc/ass-cmo 2>/dev/null || true
```

### Provision or update a Windows agent

Run PowerShell as Administrator.

Fresh Windows enrollment starts without local config, displays a pairing code on the console, and writes `%ProgramData%\ASS-CMO\agent.conf.ps1` locally after admin approval. An existing config is preserved on reinstall.

Replace `ass-cmo.example.com` with your real ASS-CMO hostname:

```powershell
Invoke-WebRequest -UseBasicParsing "https://ass-cmo.example.com/agents/windows/install-ass-cmo-agent.ps1" -OutFile "$env:TEMP\install-ass-cmo-agent.ps1"; powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:TEMP\install-ass-cmo-agent.ps1" -BaseUrl "https://ass-cmo.example.com"
```

This installs or updates:

```text
C:\ProgramData\ASS-CMO\agent.conf.ps1
C:\ProgramData\ASS-CMO\ass-cmo-agent.ps1
```

and creates the scheduled task `ASS-CMO-Agent`. The installer runs the first inventory report immediately.

### Verify Windows agent

Run PowerShell as Administrator.

Run the agent manually:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\ProgramData\ASS-CMO\ass-cmo-agent.ps1"
```

Check the scheduled task:

```powershell
Get-ScheduledTask -TaskName "ASS-CMO-Agent"
```

Run the scheduled task manually:

```powershell
Start-ScheduledTask -TaskName "ASS-CMO-Agent"
```

The Windows agent is inventory-only. It does not provide remote shell execution.

### Remove Windows agent from a managed host

Run PowerShell as Administrator:

```powershell
Invoke-WebRequest -UseBasicParsing "https://ass-cmo.example.com/agents/windows/uninstall-ass-cmo-agent.ps1" -OutFile "$env:TEMP\uninstall-ass-cmo-agent.ps1"; powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:TEMP\uninstall-ass-cmo-agent.ps1"
```

To keep the local config file:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:TEMP\uninstall-ass-cmo-agent.ps1" -KeepConfig
```

---

## Upgrade hooks

The Linux agent is triggered automatically after package transactions.

Debian-based systems use `/etc/apt/apt.conf.d/99ass-cmo-agent`.

Arch systems use `/etc/pacman.d/hooks/ass-cmo-agent.hook`.

The apt hook is non-blocking and does not break package upgrades if the ASS-CMO server is unavailable.

---

## Timer behavior

The default timer runs:

```text
30 seconds after boot
at 00:01 and 12:01
```

Timer file:

```text
/etc/systemd/system/ass-cmo-agent.timer
```

Example timer section:

```ini
[Timer]
OnBootSec=30sec
OnCalendar=*-*-* 00,12:01:00
RandomizedDelaySec=0sec
Persistent=true
Unit=ass-cmo-agent.service
```

For larger fleets, consider a randomized delay to spread server load:

```ini
RandomizedDelaySec=30min
```

---

## Client URI handlers

The dashboard generates client-side connection links:

```text
assssh://10.20.30.10
assrdp://10.20.30.20
```

ASS-CMO does not store SSH keys, RDP passwords, or remote credentials. The local workstation handles these links through registered URI handlers.

Linux desktop installer:

```bash
curl -fsSL https://ass-cmo.example.com/agents/handlers/linux/install-ass-cmo-uri-handlers.sh | sh
```

Windows installer:

```powershell
Invoke-WebRequest -UseBasicParsing "https://ass-cmo.example.com/agents/handlers/windows/install-ass-cmo-uri-handlers.ps1" -OutFile "$env:TEMP\install-ass-cmo-uri-handlers.ps1"; powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:TEMP\install-ass-cmo-uri-handlers.ps1"
```

Linux `assssh://` uses the local terminal and OpenSSH client. Linux `assrdp://` uses Remmina when available and falls back to FreeRDP.

Windows `assssh://` uses Windows Terminal and OpenSSH. Windows `assrdp://` uses `mstsc.exe`.

For SSH links, the dashboard can optionally prepend a fixed username:

```env
ASSCMO_DASHBOARD_SSH_USER=root
```

After changing this value, recreate the PHP container:

```bash
docker compose --env-file config.local/.env up -d --force-recreate php
```

For RDP saved credentials on domain-managed Windows clients, local or domain GPO may need to allow saved credentials for the relevant `TERMSRV/...` targets.

---

## Optional compose layers

The core stack (`postgres adminer php nginx`) is started by the installer. Optional layers are started separately.

Core + Grafana:

```bash
docker compose --env-file config.local/.env -f compose.yml -f compose.grafana.yml up -d
```

Core + Grafana + TIM (Telegraf, InfluxDB, Mosquitto):

```bash
docker compose --env-file config.local/.env -f compose.yml -f compose.grafana.yml -f compose.tigm.yml up -d
```

Grafana, InfluxDB, Telegraf, and Mosquitto are not configured by the core installer. Set their values in `config.local/.env` before starting these layers.

### Grafana setup

Prepare Grafana local configuration:

```bash
mkdir -p config.local/grafana/dashboards config.local/grafana/provisioning
```

For default local admin login, keep LDAP disabled in `config.local/.env`:

```env
GRAFANA_LDAP_ENABLED=false
```

For LDAP/Active Directory login, copy and edit the example template:

```bash
cp config.example/grafana/ldap.toml config.local/grafana/ldap.toml
```

then set in `config.local/.env`:

```env
GRAFANA_LDAP_ENABLED=true
GRAFANA_ROOT_URL=https://grafana.example.com
```

Admin login uses `GRAFANA_ADMIN_USER` and `GRAFANA_ADMIN_PASSWORD` from `config.local/.env`.

---

## Backup

PostgreSQL dump (replace `asscmo` and `inventory_db` with your actual values):

```bash
docker exec ass-postgres pg_dump -U asscmo inventory_db > config.local/backups/ass-cmo-postgres.sql
```

---

## Security notes

- HTTPS is required. Agents reject insecure connections by default.
- Agents authenticate using a per-host secret provisioned during enrollment. Secrets are stored only in the managed host local config (`/etc/ass-cmo/agent.conf` or `C:\ProgramData\ASS-CMO\agent.conf.ps1`). They are never served over HTTP.
- Agents only send inventory data to the server. The server does not provide arbitrary remote command execution.
- The legacy shared inventory token (`ASSCMO_INVENTORY_TOKEN`) is deprecated and disabled by default. It remains in the codebase only as a migration-only compatibility path for private internal deployments that have not yet migrated to per-host secrets.
- `/agents/linux/agent.conf` and `/agents/windows/agent.conf.ps1` must return 404. Verify this after install.
- Do not write agent configs containing secrets into the public-served `/agents/` path.
- The `/scripts/` path is an intentional admin-managed public script area backed by `config.local/scripts/`. Everything placed there is public to anyone who can reach the web server. Never place secrets, private keys, tokens, passwords, production configs, database dumps, or editor backup files there.
- Do not commit `config.local/.env`, real nginx config, real certificates, tokens, or secrets.
- Do not disable TLS verification in agents.

**Existing operators reviewing this deployment before public exposure:**

Review `config.local/.env` for stale, weak, or legacy values — for example, placeholder passwords (`changeme`), or `ASSCMO_LEGACY_SHARED_INVENTORY_TOKEN_ENABLED=true` if the legacy token was explicitly re-enabled. Review `config.local/nginx/` against the current example templates for drift. Confirm that `POSTGRES_DASHBOARD_USER` and `POSTGRES_DASHBOARD_PASSWORD` are set and that the dashboard read-only role is in place.

---

## Manual reference

The sections below describe how to set up or repair individual components without running the full installer. Useful for troubleshooting, partial reinstalls, or understanding what each step does.

### Prepare local configuration

```bash
mkdir -p config.local/nginx config.local/dashboard-views config.local/branding/logo config.local/backups config.local/scripts
cp .env.example config.local/.env
ln -sf config.local/.env .env
cp config.example/sites.example.json config.local/sites.json
cp -r config.example/nginx/* config.local/nginx/
cp -n config.example/dashboard-views/*.sql config.local/dashboard-views/
```

### Generate secrets manually

```bash
sed -i "s|^ASSCMO_ENROLLMENT_PEPPER=.*|ASSCMO_ENROLLMENT_PEPPER=$(openssl rand -base64 48)|" config.local/.env
sed -i "s|^ASSCMO_ENROLLMENT_APPROVE_TOKEN=.*|ASSCMO_ENROLLMENT_APPROVE_TOKEN=$(openssl rand -base64 48)|" config.local/.env
sed -i "s|^POSTGRES_PASSWORD=.*|POSTGRES_PASSWORD=$(openssl rand -base64 32)|" config.local/.env
sed -i "s|^POSTGRES_DASHBOARD_PASSWORD=.*|POSTGRES_DASHBOARD_PASSWORD=$(openssl rand -base64 32)|" config.local/.env
```

Check for remaining placeholder values:

```bash
grep -nE '^(ASSCMO_ENROLLMENT_PEPPER|ASSCMO_ENROLLMENT_APPROVE_TOKEN|POSTGRES_PASSWORD|POSTGRES_DASHBOARD_PASSWORD)=(|change-.*)$' config.local/.env
```

If this command prints nothing, those values are filled.

### Key environment variables

Set these in `config.local/.env`:

```text
ASSCMO_INSTANCE_NAME          your real hostname, e.g. ass-cmo.example.com
ASSCMO_BASE_URL               full HTTPS URL, e.g. https://ass-cmo.example.com
ASSCMO_TLS_CERT_NAME          Let's Encrypt cert name, e.g. example.com
POSTGRES_DB                   database name
POSTGRES_USER                 database superuser
POSTGRES_DASHBOARD_USER       read-only dashboard user
POSTGRES_DASHBOARD_PASSWORD   generated above
```

### Configure nginx

Edit the nginx config in `config.local/nginx/`. At minimum, replace the example hostnames with your real DNS names and update certificate paths:

```nginx
ssl_certificate /etc/letsencrypt/live/example.com/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;
```

The core nginx layout exposes:

```text
https://ass-cmo.example.com/health.php
https://ass-cmo.example.com/inventory.php      (agent ingest endpoint)
https://ass-cmo.example.com/agents/            (public agent installer scripts only)
https://ass-cmo.example.com/scripts/           (admin-managed public script area)
https://adminer.example.com/                   (database admin UI)
```

### Configure sites

Edit `config.local/sites.json` to describe your network sites and subnets. The installer copies `config.example/sites.example.json` as a starting template.

Example entry:

```json
[
  {
    "site_id": "example-office",
    "location": "office",
    "display_name": "Example Office",
    "environment": "office",
    "network_segment": "internal",
    "subnets": ["192.168.1."],
    "ipv6_prefixes": ["2001:db8:1:"],
    "tags": ["office"]
  }
]
```

Validate the JSON:

```bash
jq . config.local/sites.json >/dev/null && echo OK
```

### Create the Docker network

```bash
docker network create ass-net 2>/dev/null || true
```

### Start the core stack manually

```bash
docker compose --env-file config.local/.env up -d postgres adminer php nginx
```

### Apply database schema manually

The database schema is normally applied by the installer or by `docker-entrypoint-initdb.d` on a fresh PostgreSQL volume. To apply manually (replace `asscmo` and `inventory_db` with your values):

```bash
for f in database/init/*.sql; do docker exec -i ass-postgres psql -U asscmo -d inventory_db < "$f"; done
```

### Set up dashboard read-only role manually

```bash
docker exec -i ass-postgres psql \
  -U asscmo -d inventory_db \
  -v dashboard_user="$(grep '^POSTGRES_DASHBOARD_USER=' config.local/.env | cut -d= -f2-)" \
  -v dashboard_password="'$(grep '^POSTGRES_DASHBOARD_PASSWORD=' config.local/.env | cut -d= -f2-)'" \
  < database/scripts/002_dashboard_readonly_user.sql
```

---

## Troubleshooting

**Containers not starting or crashing:**

```bash
docker compose --env-file config.local/.env logs
```

**PostgreSQL schema missing:**

The `database/init/*.sql` files run automatically via `docker-entrypoint-initdb.d` on a fresh empty volume. On a reinstall where the volume already exists, this step is skipped by Docker. Run the manual schema application step above if any table is missing.

**Dashboard shows no data or credential error:**

Check that `POSTGRES_DASHBOARD_USER` and `POSTGRES_DASHBOARD_PASSWORD` are set in `config.local/.env` and that the dashboard read-only role has been created. Re-run the dashboard role setup step above if needed.

**Agent returns 401 or 403:**

The host's `agent_auth` row may be disabled or revoked. Re-enrollment is required for revoked agents. Check the enrollment and auth tables in Adminer.

**Nginx TLS errors:**

Verify that the certificate name in `ASSCMO_TLS_CERT_NAME` matches a directory in `/etc/letsencrypt/live/` and that `config.local/nginx/` points to the correct certificate paths.

---

## Roadmap

```text
v0.7.x  internal releases and hotfixes
v0.8.0  secure enrollment, per-host secrets, revocation, legacy shared-token migration, clean public GitHub release
v1.0.0  later stable release
```

---

## Git hygiene

Before committing, check what will be tracked:

```bash
git status --short
```

Search for secrets before commit:

```bash
grep -RInE 'TOKEN=|PASSWORD=|ASSCMO_INVENTORY_TOKEN|POSTGRES_PASSWORD|INFLUXDB_ADMIN_TOKEN' . --exclude-dir=.git
```

The search may find placeholders in `.env.example` or documentation. It must not find real secrets in files that will be committed.
