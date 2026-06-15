# ASS-CMO Troubleshooting

Operational checks and failure diagnosis for an ASS-CMO deployment.

For installation, see [INSTALL.md](INSTALL.md). For the security model, see [SECURITY.md](SECURITY.md).

All commands below are read-only checks or safe repair steps. Replace `asscmo`, `inventory_db`, and `ass-cmo.example.com` with your actual values, and run the listed commands from the repository root.

## Contents

1. [Container and service checks](#container-and-service-checks)
2. [nginx and TLS checks](#nginx-and-tls-checks)
3. [Endpoint checks](#endpoint-checks)
4. [Database checks](#database-checks)
5. [Dashboard access checks](#dashboard-access-checks)
6. [Enrollment troubleshooting](#enrollment-troubleshooting)
7. [Agent reporting troubleshooting](#agent-reporting-troubleshooting)
8. [URI handler troubleshooting](#uri-handler-troubleshooting)
9. [Where to look for logs](#where-to-look-for-logs)
10. [Manual repair and partial reinstall](#manual-repair-and-partial-reinstall)

---

## Container and service checks

Show container status:

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

If a container is missing, restarting, or crashing, inspect the logs:

```bash
docker compose --env-file config.local/.env logs
```

To follow logs for a single service:

```bash
docker compose --env-file config.local/.env logs -f nginx
```

---

## nginx and TLS checks

If the site does not load or shows a TLS error:

- Verify that the certificate name in `ASSCMO_TLS_CERT_NAME` matches a directory in `/etc/letsencrypt/live/`.
- Verify that `config.local/nginx/` points to the correct certificate paths, for example:

```nginx
ssl_certificate /etc/letsencrypt/live/example.com/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;
```

- Confirm that the example hostnames in `config.local/nginx/` were replaced with your real DNS names.

The core nginx layout exposes:

```text
https://ass-cmo.example.com/health.php
https://ass-cmo.example.com/inventory.php      (agent ingest endpoint)
https://ass-cmo.example.com/agents/            (public agent installer scripts only)
https://ass-cmo.example.com/scripts/           (admin-managed public script area)
https://adminer.example.com/                   (database admin UI)
```

---

## Endpoint checks

Check that the public endpoints respond (replace the hostname):

```bash
curl -Is https://ass-cmo.example.com/health.php | head -1
curl -Is https://ass-cmo.example.com/agents/linux/install-ass-cmo-agent.sh | head -1
curl -Is https://ass-cmo.example.com/agents/windows/install-ass-cmo-agent.ps1 | head -1
```

These should return `HTTP/... 200`. If any returns a non-200 status, check nginx configuration and file placement.

---

## Database checks

Verify that the core tables exist. This uses the default installer values unless you have exported different `POSTGRES_USER` or `POSTGRES_DB` values:

```bash
docker exec ass-postgres psql -U "${POSTGRES_USER:-asscmo}" -d "${POSTGRES_DB:-inventory_db}" -tAc "SELECT to_regclass('public.inventory'), to_regclass('public.agent_enrollment_requests'), to_regclass('public.agent_auth'), to_regclass('public.agent_auth_history')"
```

Expected output — all four table names returned, none `NULL`:

```text
inventory|agent_enrollment_requests|agent_auth|agent_auth_history
```

If any entry shows `NULL`, the schema is incomplete.

The `database/init/*.sql` files run automatically via `docker-entrypoint-initdb.d` on a fresh, empty PostgreSQL volume. On a reinstall where the volume already exists, Docker skips this step. Apply the schema manually (replace `asscmo` and `inventory_db` with your values):

```bash
for f in database/init/*.sql; do docker exec -i ass-postgres psql -U asscmo -d inventory_db < "$f"; done
```

---

## Dashboard access checks

If the dashboard shows no data or a credential error:

- Confirm that `POSTGRES_DASHBOARD_USER` and `POSTGRES_DASHBOARD_PASSWORD` are set in `config.local/.env`.
- Confirm that the read-only dashboard role exists. Re-create it if needed:

```bash
docker exec -i ass-postgres psql \
  -U asscmo -d inventory_db \
  -v dashboard_user="$(grep '^POSTGRES_DASHBOARD_USER=' config.local/.env | cut -d= -f2-)" \
  -v dashboard_password="'$(grep '^POSTGRES_DASHBOARD_PASSWORD=' config.local/.env | cut -d= -f2-)'" \
  < database/scripts/002_dashboard_readonly_user.sql
```

Dashboard SQL view execution requires the read-only dashboard credentials to be configured; it does not fall back to the main admin database user. If they are missing, the dashboard shows a generic misconfiguration error and the detail goes to the server log only.

---

## Enrollment troubleshooting

- Enrollment requests appear in the dashboard under `?view=enrollment`. Compare the pairing code shown on the managed host console with the one shown in the admin UI before approving.
- A request can be denied; denied requests are retained as an audit trail with a stored reason and are not deleted.
- If a UID already has an active `agent_auth` entry, a new approval is automatically rejected and the pending request is denied with a stored reason. Inspect the `agent_enrollment_requests` and `agent_auth` tables in Adminer to confirm.
- The one-time agent secret is delivered exactly once to the installer after approval. If it is not collected before the request expires, re-run enrollment on the managed host.

---

## Agent reporting troubleshooting

If a host stops reporting inventory:

**Inventory submission returns 401 or 403:** the host's `agent_auth` row may be disabled or revoked. Revoked per-host secrets are permanently rejected; recovery is a fresh re-enrollment, not a re-enable. Check the `agent_auth` row in Adminer.

**Linux** — run the agent manually and inspect the timer and logs:

```bash
/usr/local/sbin/ass-cmo-agent
systemctl status ass-cmo-agent.timer
systemctl list-timers '*ass-cmo*' --all
journalctl -u ass-cmo-agent.service -n 50 --no-pager
```

A revoked or disabled host exits 0 with an informative message so it does not break apt/pacman package hooks. Network errors and server 5xx responses still exit non-zero.

**Windows** — run PowerShell as Administrator:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\ProgramData\ASS-CMO\ass-cmo-agent.ps1"
Get-ScheduledTask -TaskName "ASS-CMO-Agent"
Start-ScheduledTask -TaskName "ASS-CMO-Agent"
```

---

## URI handler troubleshooting

If dashboard `assssh://`, `assrdp://`, or `assweb://` links do nothing when clicked:

- Confirm that the URI handlers are installed on the workstation (see [INSTALL.md](INSTALL.md)).
- The handler installer protects existing local handler customizations by default. If you have older or customized handlers, reinstall with the overwrite flag:

```bash
ASSCMO_OVERWRITE_HANDLERS=1 sh install-ass-cmo-uri-handlers.sh
```

On Windows, set the same variable before running the handler installer:

```powershell
$env:ASSCMO_OVERWRITE_HANDLERS = "1"
```

- For SSH, confirm the workstation can already reach the target with a plain `ssh` command. The handler only launches the local client; it does not provide credentials.
- For RDP on domain-managed Windows clients, saving credentials may require local or domain policy to allow saved credentials for the relevant `TERMSRV/...` targets.
- Malformed or unsafe targets are intentionally omitted from the dashboard rather than rendered as active links.

---

## Where to look for logs

```text
docker compose --env-file config.local/.env logs           full stack
docker compose --env-file config.local/.env logs nginx      reverse proxy / TLS
docker compose --env-file config.local/.env logs php        dashboard and ingest endpoint
docker compose --env-file config.local/.env logs postgres   database
journalctl -u ass-cmo-agent.service                         Linux agent (on the managed host)
```

Detailed application errors (enrollment, agent auth, dashboard view loading) are written to the server log only; the UI shows generic messages by design.

---

## Manual repair and partial reinstall

These steps set up or repair individual components without running the full installer. They are useful for partial reinstalls or understanding what each step does.

Prepare local configuration directories and templates:

```bash
mkdir -p config.local/nginx config.local/dashboard-views config.local/branding/logo config.local/backups config.local/scripts
cp .env.example config.local/.env
ln -sf config.local/.env .env
cp config.example/sites.example.json config.local/sites.json
cp -r config.example/nginx/* config.local/nginx/
cp -n config.example/dashboard-views/*.sql config.local/dashboard-views/
```

Key environment variables to set in `config.local/.env`:

```text
ASSCMO_INSTANCE_NAME          your real hostname, e.g. ass-cmo.example.com
ASSCMO_BASE_URL               full HTTPS URL, e.g. https://ass-cmo.example.com
ASSCMO_TLS_CERT_NAME          Let's Encrypt cert name, e.g. example.com
POSTGRES_DB                   database name
POSTGRES_USER                 database superuser
POSTGRES_DASHBOARD_USER       read-only dashboard user
POSTGRES_DASHBOARD_PASSWORD   read-only dashboard password
```

Define your network sites in `config.local/sites.json` and validate the JSON:

```bash
jq . config.local/sites.json >/dev/null && echo OK
```

Create the Docker network and start the core stack manually:

```bash
docker network create ass-net 2>/dev/null || true
docker compose --env-file config.local/.env up -d postgres adminer php nginx
```

To apply the schema and recreate the dashboard role manually, use the commands in [Database checks](#database-checks) and [Dashboard access checks](#dashboard-access-checks).
