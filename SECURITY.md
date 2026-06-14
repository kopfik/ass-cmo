# ASS-CMO Security Notes

ASS-CMO is an inventory and admin-launcher system. It is not intended to be an RMM or C2 system.

## Reporting security issues

For public deployments or forks, do not post secrets, tokens, private hostnames, database dumps, or vulnerable live URLs in public issues. Report security-sensitive findings through the maintainer's preferred private contact channel for the public repository, or open a minimal public issue that asks for a private contact path without disclosing operational details.

## Trust boundaries

### Trust boundary model

ASS-CMO assumes a self-hosted, single-operator (or small trusted admin team) trust model. It is not a multi-tenant SaaS application and does not provide tenant isolation. The following components are explicitly operator-owned and trusted, not hostile-input boundaries:

- `config.local/` is the trusted runtime environment, owned and controlled by the operator. Its contents (env file, secrets, nginx config, dashboard views, scripts) are assumed authentic.
- nginx configuration is a deployment-owned security boundary. TLS, access control, source-IP allowlists, and exposure decisions live here and are the operator's responsibility.
- `/scripts/` is an intentionally exposed administrative execution surface backed by `config.local/scripts/`. It is public to anyone who can reach the web server and must never contain secrets.
- Adminer is an internal administrative tool and must not be exposed publicly.
- The overall model assumes self-hosted operator trust, not multi-tenant SaaS isolation.

### Inventory ingest

Agents send inventory data to the server over HTTPS. The current authentication model uses a per-host secret provisioned during enrollment (`ASSCMO_AGENT_SECRET`), validated against the `agent_auth` table.

The inventory protocol is intended to be one-way:

- agents send current machine state to the server,
- the server stores the latest inventory snapshot,
- the server does not send interactive commands back to agents through the inventory protocol.

The legacy shared inventory token (`ASSCMO_INVENTORY_TOKEN`) is deprecated and disabled by default. It remains as a migration-only compatibility path and must be explicitly re-enabled with `ASSCMO_LEGACY_SHARED_INVENTORY_TOKEN_ENABLED=true`.

### Agent updater

Automatic agent updater is not shipped in public v0.8.0. It was removed/deferred until a secure update channel, integrity/signature verification model, rollback behavior, and trust boundaries are designed.

### URI handlers

URI handlers run on the admin workstation and open local tools such as SSH, RDP, or the default browser.

Handler inputs must be treated as untrusted. Handlers must validate targets before launching local applications and must not construct shell command strings from untrusted URI input.

### Agent configuration files

Agent configuration files containing secrets must not be served from public HTTP(S) paths.

Only secret-free installer and helper scripts may be exposed through `/agents/`.

`/scripts/` is an intentional admin-managed public script area backed by `config.local/scripts/`. It is meant for administrator-approved post-install, bootstrap, normalization, or hardening scripts that may be fetched from new systems with curl. Everything in this directory is public to anyone who can reach the ASS-CMO web server. Never place secrets, private keys, tokens, passwords, production configs, database dumps, or editor backup files in this directory.

Token-bearing configuration must be distributed out-of-band or generated locally during installation.

### Enrollment

Agent enrollment uses a two-phase flow: the installer initiates a pending request, and an admin reviews and approves it in the dashboard UI.

- The `verification_url` returned by the enrollment start response contains only the `request_id` for navigation and filtering. It never includes `poll_token`, `pairing_code`, `agent_secret`, or any hash.
- The `poll_token` is held by the installer and is required to poll for approval status. It is never shown in the admin UI and must not be placed in URLs, logs, shell history, or debug output.
- The `pairing_code` is a short display code shown on the target machine console and in the admin UI for visual confirmation only. It is not an authentication factor. Authentication for installer polling uses `request_id + poll_token`; authentication for the approval action uses admin UI access control plus a per-session CSRF token.
- The `agent_secret` is generated server-side after approval and delivered exactly once through the approved poll response. It is never shown in the admin UI or included in any URL. If it is not collected before the enrollment request expires, the pending one-time value is cleared server-side.
- The approved-but-uncollected expiry path clears the one-time `agent_secret` value and also archives/removes the matching safe orphan `agent_auth` row only when it still points to the same enrollment request and has never been used for auth or inventory.
- Disabled or revoked `agent_auth` rows reject future inventory submissions with the same generic `403` used for other authentication failures. Revoked per-host secrets are permanently rejected; recovery is fresh re-enrollment, not re-enable. Legacy shared-token fallback follows the same fail-closed rule when a matching disabled/revoked `agent_auth` row already exists for the submitted UID.
- Denied enrollment requests are retained as an audit trail and are not physically deleted. The `denial_reason` field records why a request was denied.
- If a UID already has an active `agent_auth` entry, new enrollment approval is automatically rejected and the pending request is denied with a stored reason, preventing accidental duplicate registrations.

## Current known risks

- The shared inventory token (`ASSCMO_INVENTORY_TOKEN`) is deprecated and disabled by default. It is a migration-only compatibility path for private internal deployments that have not yet migrated to per-host secrets.
- Automatic agent updater is not shipped in public v0.8.0.
- URI handler target validation needs continued hardening.
- Inventory payload schema validation needs continued hardening.
- Adminer exposure must be controlled in production deployments.
- TIM/MQTT/Telegraf/Grafana components are optional/lab-oriented until hardened. Do not expose MQTT listeners, Grafana/Adminer endpoints, or Telegraf Docker socket monitoring publicly without authentication, access control, and an explicit threat-model review.

## Deployment exposure model

ASS-CMO is designed as an internal inventory and administration launcher. It is not intended to be deployed as a directly public-facing application and it does not try to provide a complete security boundary for arbitrary internet exposure.

Recommended deployment models are:

- private LAN or VPN-only access,
- reverse proxy access restricted by explicit source IP allowlists,
- public reverse proxy only for narrowly scoped ingest paths, with source IP allowlists for known agents or sites,
- optional tunneling/VPN from public infrastructure back to an internal reverse proxy.

The server UI, Adminer, Grafana and similar administrative endpoints should be treated as internal-only tools. Do not expose them directly to the internet without an external protection layer such as VPN, IP allowlisting, strong authentication, or disabling the endpoint entirely.

Mutable dashboard actions, including enrollment approval/denial and agent secret revocation, rely on this operator-controlled admin boundary around the web UI. CSRF tokens protect browser-originated form submissions, but CSRF is not an authentication layer. Do not expose these dashboard routes directly to the internet without VPN, IP allowlisting, reverse-proxy authentication, SSO/basic auth, or equivalent protection.

If ASS-CMO is used to collect inventory from public VPS or remote sites, the intended model is to expose only the minimum required ingest endpoint through a controlled reverse proxy and restrict it to known source addresses where possible. ASS-CMO itself is not a general-purpose public SaaS application and is not intended to secure someone else's internet-facing deployment by itself.

## Dashboard SQL view boundary

Dashboard SQL files are intended to be read-only views over inventory data. The application-level `sql_is_reasonably_safe()` check is a sanity guard for accidental or obviously unsafe SQL in dashboard view files. It is not the security boundary.

The real boundary is the PostgreSQL dashboard role. Production-like deployments should use `POSTGRES_DASHBOARD_USER` and `POSTGRES_DASHBOARD_PASSWORD` with a database role that has read-only access, such as the `ass_dashboarder` role created by `database/scripts/002_dashboard_readonly_user.sql`.

Expected properties of the dashboard role:

- it can connect to the inventory database,
- it can read tables/views required by the dashboard,
- it cannot insert, update, delete, create tables, alter schema, grant privileges, or manage roles.

Operators should verify this boundary on their own deployment by confirming that the dashboard role can run read-only inventory queries, such as `SELECT count(*) FROM inventory`, but cannot create tables or otherwise modify the `public` schema.
