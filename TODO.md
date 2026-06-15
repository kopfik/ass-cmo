# ASS-CMO TODO / Sanity Check Backlog

This file tracks findings from internal and external AI-assisted code reviews.  
The goal is to keep ASS-CMO clean, maintainable and aligned with its intended scope:

- inventory overview
- connection launcher
- server/admin overview
- not an RMM
- not a C2 system

## Legend

- `[ ]` open
- `[x]` done
- `critical` must be solved before any public release
- `hardening` should be solved before wider internal use
- `cleanup` improves maintainability
- `docs` clarifies assumptions and limitations

---

## v0.8.0 public GitHub release gate

These items are tracked after the final internal `v0.7.5` pre-public review snapshot. They must be resolved or explicitly deferred before the clean public GitHub `v0.8.0` import.

### Blockers

- [x] `security` Stop generating token-bearing agent configs inside the repository/web-served `agents/` tree.
  - `install.sh` must not create or sync real `agents/linux/agent.conf` or `agents/windows/agent.conf.ps1`.
  - Fresh public installs must use enrollment-generated host-local config only.
  - `/agents/` must contain only secret-free installers, scripts, examples and helper files.

- [x] `security` Harden `/agents/` web serving to avoid accidental secret exposure.
  - Prefer an allowlist of known secret-free files instead of serving the whole directory tree.
  - Keep `agent.conf` and `agent.conf.ps1` blocked, but do not rely only on exact-name 404 rules.

- [x] `security` Add no-store/no-cache headers to enrollment JSON responses.
  - Applies especially to responses carrying `poll_token` or one-time `agent_secret`.
  - Include `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`, `Pragma: no-cache`, `Expires: 0`, and `X-Content-Type-Options: nosniff`.

- [x] `security` Avoid exposing Linux enrollment and agent secrets through process arguments.
  - Do not pass `X-Poll-Token` or `X-Agent-Secret` via visible `curl -H ...` command-line arguments.
  - Use a private temporary curl config/header file, stdin-based config, or equivalent safe mechanism.
  - Ensure temporary files are mode `0600` and cleaned up reliably.

- [x] `docs` Finish public README/SECURITY/AGENTS release-gate wording review.
  - Fresh public installs already use admin-approved enrollment and per-host agent secrets in public docs.
  - Legacy shared inventory token is already documented as compatibility/private migration only and disabled by default.
  - Remaining work is final public-facing review for stale release-gate wording, SECURITY reporting/import notes, and AGENTS public contributor guidance.

- [x] `release-hygiene` Remove private deployment fingerprints from public code and docs.
  - Remove hardcoded private network mapping such as `10.254.2.` / `vpn-pater`.
  - Replace private clone URLs and paths such as `gitea.kopfik.org`, `kopfik.org`, `/Docker/ass-cmo`, and `~/git/kopfik.org/ass-cmo`.
  - Replace private deployment hostnames in public docs/changelog with neutral wording.

- [x] `release-hygiene` Clean public TODO/release-gate wording before import.
  - Public `TODO.md` must not claim unresolved “must fix before public release” items after the import.
  - Stale statements about fresh install being disabled or secret distribution being undecided must be removed, completed, or reframed as historical/private migration notes.

- [x] `views` Move Linux bulk-update SQL view out of default-installed dashboard views.
  - Keep it as an explicit opt-in manual action example if retained.
  - Do not install it by default in public v0.8.

### Should-fix before public import

- [x] `security` Make dashboard SQL view execution fail closed if read-only dashboard DB credentials are missing.
  - Avoid falling back silently to the full application DB user.
  - Keep dashboard custom SQL protected by the dedicated read-only PostgreSQL role.

- [x] `security` Make dashboard DB role setup consistent.
  - Either use one fixed dashboard role name such as `ass_dashboarder`, or safely parameterize the SQL setup script.
  - Add verification that the dashboard role can read `inventory` but cannot read enrollment/auth secret-bearing tables.

- [x] `security` Decide and document the admin boundary for mutable dashboard actions.
  - Enrollment approve/deny and agent secret revoke actions currently depend on protected dashboard access plus CSRF.
  - Either document the required VPN/reverse-proxy/IP allowlist/auth boundary strongly, or add a small application-level admin gate.

- [ ] `hardening` Add basic enrollment spam controls.
  - Consider a per-IP pending cap, global pending cap, or explicit enrollment arming/bootstrap mechanism.
  - Add an admin cleanup path for obvious false pending enrollment requests.

- [ ] `hardening` Lock enrollment request rows earlier during approval.
  - Load and lock the request inside the transaction with `FOR UPDATE`.
  - Keep existing UID uniqueness and revoked-auth replacement behavior.

- [x] `hardening` Replace raw dashboard exception messages with generic UI errors.
  - Log detailed errors server-side.
  - Show generic admin-safe messages in enrollment, agent-auth and dashboard view load failures.

- [x] `hardening` Validate generated SSH/RDP custom protocol targets server-side before rendering links.
  - Keep handler-side validation too.
  - Do not emit malformed or unsafe `assssh://` / `assrdp://` links from inventory/view data.

- [x] `hardening` Validate `ASSCMO_ADMINER_URL`.
  - Accept only `http://` and `https://`.
  - Hide the link or show a config warning for unsupported schemes.

- [x] `hardening` Reject known placeholder legacy shared tokens if legacy mode is explicitly enabled.
  - Examples: `changeme`, `change-me`, `change-this`.
  - Make legacy token examples empty and clearly compatibility-only.

- [x] `hardening` Make Linux installer JSON generation use `jq -n`.
  - Avoid hand-built JSON where `jq` is already required.

- [ ] `hardening` Add Linux agent dependency checks or avoid fragile dependencies.
  - `grep -P` (PCRE) removed from Linux agent interface and IP address detection; replaced with POSIX-compatible alternatives.
  - Remaining: check or document `jq`, `ip`, `ss`, `free`, and systemd assumptions where appropriate.
  - Fail early with a clear message on unsupported minimal systems.

- [ ] `privacy` Review Linux agent collection of `/root/.ssh/authorized_keys` comments.
  - SSH key comments may contain names, emails, laptop names, or private labels.
  - Make it opt-in, sanitize to counts, or document clearly.

- [ ] `windows` Fix Windows scheduled task naming consistency.
  - Use `ASS-CMO-Agent` consistently in installer, uninstaller, README and INSTALL verification commands.

- [ ] `windows` Validate Windows installer `-BaseUrl`.
  - Require explicit non-placeholder URL for direct execution.
  - Accept only `http://`/`https://`, require host, and reject query/fragment.

- [x] `docs` Restructure INSTALL into a public quickstart and advanced sections.
  - Fresh public install.
  - Server configuration.
  - Agent enrollment.
  - URI handlers.
  - Advanced/legacy private migration appendix.

- [x] `docs` Fix INSTALL correctness bugs.
  - Broken/malformed URL code fence.
  - Grafana LDAP filename mismatch.
  - `config.local/.env` vs `.env` compose command drift.
  - Server/Linux/Windows prerequisites.
  - Old private git/tag examples.
  - Internal QA diary phrasing.

- [x] `docs` Update SECURITY.md.
  - Lead with enrollment plus per-host secrets.
  - Keep legacy shared token as explicit compatibility-only fallback.
  - Add public security reporting guidance.
  - Replace internal “verified on current deployments” phrasing with operator verification guidance.

- [x] `docs` Trim or rewrite AGENTS.md for public use.
  - Remove private paths, private orchestration notes, and personal shorthand workflow.
  - Keep only public AI/contributor guidance, project scope, safety rules and validation expectations.

- [x] `config` Update stale nginx enrollment comments and translate public example comments to English.
  - Do not claim approve auth is missing.
  - Clarify dashboard/reverse-proxy exposure assumptions.

- [x] `config` Mark optional Grafana/TIM/MQTT/Telegraf overlays as advanced/lab-only until hardened.
  - Warn about anonymous MQTT, exposed ports, and Docker socket monitoring.

- [ ] `frontend` Move enrollment highlight inline script into `dashboard.js` or remove it.
  - Keep CSP without `unsafe-inline`.

- [ ] `frontend` Rename “Agent auth” UI wording if desired.
  - Prefer “Agent secrets” or “Agent authentication”.
  - Keep wording aligned with public docs.

### Final public-import checks

- [x] `release` Run a clean secret scan before public import.
- [x] `release` Verify no generated `agent.conf`, `agent.conf.ps1`, `.env`, `config.local/`, certificates, dumps, archives or runtime secrets are present in the public tree.
- [x] `release` Verify `/agents/` contains only secret-free files.
- [x] `release` Test fresh server install from public docs.
- [x] `release` Test fresh Linux enrollment.
- [x] `release` Test fresh Windows enrollment.
- [x] `release` Test disabled/revoked host rejection.
- [x] `release` Test one-time secret delivery and second-poll behavior.
- [x] `release` Test dashboard read-only DB role cannot read enrollment/auth secret-bearing tables.

### v0.8.0 enrollment design: device-style pairing

Default model is internal/VPN-first. Public/internet-hardened profile is deferred to a later roadmap item.

UX direction: the installer initiates enrollment and the pairing code appears on the target machine console. The admin then approves a pending request in the web UI. The admin does not pre-generate a code to type into the installer; that is a secondary/later option.

- [x] `0.8.0` Enrollment start endpoint creates a pending enrollment request on the server.
- [x] `0.8.0` Fresh Linux installer with no local agent config creates a pending enrollment request on the server.
- [x] `0.8.0` Fresh Windows installer with no local agent config creates a pending enrollment request on the server.
- [x] `0.8.0` Server returns `request_id`, `poll_token`, and a short human-readable pairing code (e.g. `ABC-123`) for enrollment polling.
- [x] `0.8.0` Server returns a verification URL pointing to `/?view=enrollment&request_id=N`; URL contains only the request ID, never secrets or hashes.
- [x] `0.8.0` Enrollment poll action returns `pending` before approval.
- [x] `0.8.0` Linux installer displays the pairing code on the target machine console and polls for approval.
- [x] `0.8.0` Real Linux host validation confirmed fresh enrollment creates local `ASSCMO_AGENT_SECRET`, preserves config on reinstall, and reaches successful authenticated inventory submission after approval.
- [x] `0.8.0` Windows installer displays the pairing code on the target machine console and polls for approval.
- [x] `0.8.0` Enrollment approve/deny actions require protected admin UI access plus CSRF and update pending requests.
- [x] `0.8.0` Admin opens the enrollment page (`?view=enrollment`), reviews the pending request, confirms the pairing code matches the target machine console display, and approves or denies via CSRF-protected UI form. Duplicate active UID approval is automatically denied with an actionable UI message and stored `denial_reason`.
- [ ] `later` Pre-generated install code (admin generates a code, passes it to the installer) is a secondary UX option; not the default v0.8.0 design.
- [x] `0.8.0` Short pairing code is display-only; it is shown on the target machine console and in the admin UI for visual confirmation. It is not an authentication factor and not the long-term agent secret.
- [x] `0.8.0` Server stores the short pairing code as display-only visual confirmation data. It is not an authentication factor and must never be treated as a secret.
- [x] `0.8.0` After approval, server generates a long random per-host agent secret.
- [x] `0.8.0` Server delivers the generated agent secret exactly once through approved enrollment poll.
- [x] `0.8.0` Linux installer writes the received secret to local agent config.
- [x] `0.8.0` Windows installer writes the received secret to local agent config.
- [x] `0.8.0` Real Windows host validation of fresh enrollment: pairing code display, approval, local `ASSCMO_AGENT_SECRET` config creation, preserve on reinstall, and authenticated inventory submission.
- [x] `0.8.0` Inventory ingest endpoint authenticates `uid` + per-host agent secret against `agent_auth` by default.
- [x] `0.8.0` Bundled Linux and Windows agents can use `ASSCMO_AGENT_SECRET` for inventory submissions with legacy shared-token fallback.
- [ ] `0.8.0` All future inventory submissions authenticate with uid + per-host secret.
- [x] `0.8.0` Initial DB schema uses `agent_enrollment_requests` + `agent_auth` tables; do not build a broad identity subsystem unless later design requires it.
- [ ] `0.8.0` Existing 0.7.x hosts with shared-token config follow the migration path, not re-enrollment.

### v0.8.0 pre-public polish: agents and URI handlers

- [ ] `0.8.0` Review Linux and Windows agents plus URI handlers for reliability before public release; prioritize Windows agent and Windows URI handlers.
  - Investigate reported Windows ASS protocol handler issues: PowerShell console/window appears again when clicking ASS protocol links, and one Windows workstation takes roughly 1-5 seconds before anything happens.
  - Compare with Linux handler behavior, where launch is roughly sub-second.
  - Review startup overhead, windowing behavior, quoting, and protocol argument handling in both URI handlers and agent scripts.
  - Treat this as a roadmap/review item, not an immediate bugfix task unless the issue is reproduced.

### Future adoption roadmap

- [ ] `docs/architecture` Document reverse-proxy / gateway-fronted deployment mode as a future first-class install path, including DNS/allowlist expectations and trusted public/client-facing TLS requirements.

- [ ] `later` Support importing connections from existing connection managers because manual re-entry can block adoption.
  - Target import sources include RDCMan, OpenSSH `ssh_config` files from Linux/Unix users, PuTTY saved sessions, and RDM exports if users export to text formats such as JSON/XML.
  - Treat this as a future adoption feature / roadmap note, not part of the immediate v0.8.0 secure enrollment gate unless explicitly promoted later.
- [ ] `later` Evaluate whether a separate temporary host-row disable UI is still useful once revoke plus fresh re-enrollment workflow is complete.
- [ ] `later` Optional helper for private/shared-token deployments: document or script manual migration from 0.7.x shared inventory token configs to per-host secrets. This is not a public v0.8.0 release blocker.
- [ ] `later` Redesign Agent auth view for larger deployments: move from card-per-host layout to a searchable/filterable table with row-level Revoke actions and explicit named host confirmation before revoke. Preserve visibility of auth-health anomalies such as never-authenticated or long-inactive credentials. Current card layout is intentional for the small-fleet v0.8.0 case; this is a post-public scalability improvement.
- [ ] `later` Light Enrollment approvals UX polish: keep the pairing-code-first card workflow and the prominent pairing code display (visual confirmation is the security intent, not convenience). Consider adding a pending request count or badge and an improved empty state for when no requests are waiting.

---

## Pre-v1.0.0 roadmap

### Grafana / TIM / TIGM / InfluxDB / Telegraf / MQTT overlay deprecation

**Decision:** Grafana, TIM, TIGM, InfluxDB, Telegraf, and MQTT overlays are no longer part of the official ASS-CMO core direction. They were historically useful during development and for home-lab monitoring, but they will not be maintained or advertised as a supported ASS-CMO stack going forward.

The official ASS-CMO core is:

- inventory collection and storage
- enrollment and per-host agent secrets
- admin overview dashboard
- local SSH / RDP / web connection launchers

This is a planned pre-v1.0.0 cleanup item. No immediate code removal is required now.

This supersedes the older "advanced/lab-only until hardened" wording: these overlays are no longer planned as part of the supported core stack.

- [x] `pre-1.0.0` `cleanup` Update `README.md` to stop presenting Grafana / TIM / TIGM overlays as supported or current ASS-CMO features. Reframe them as historical/optional if mentioned at all.
- [x] `pre-1.0.0` `cleanup` Update `INSTALL.md` to remove any guidance that implies the Grafana / TIM / TIGM / InfluxDB / Telegraf / MQTT stack is part of the standard install path.
- [ ] `pre-1.0.0` `cleanup` Review `.env.example` and remove or clearly mark as optional any variables that exist solely for the Grafana / TIM / TIGM overlay, unless they are also required by the core stack.
- [ ] `pre-1.0.0` `cleanup` Either remove `compose.grafana.yml` and `compose.tigm.yml` from the repository, or move them under an `examples/` or `experiments/` directory and mark them explicitly as unsupported optional extensions, not part of the supported ASS-CMO stack.
- [x] `pre-1.0.0` `docs` Add a brief note in a visible location (e.g. README or CHANGELOG) that these overlays have been moved out of the supported core path.

Note: `CHANGELOG.md` historical entries that mention Grafana / TIM / TIGM are intentionally kept as-is, similar to how historical shared-inventory-token entries are preserved for context.

### Connection catalog import (PuTTY / RDCMan / OpenSSH config)

**Goal:** help administrators migrate their existing connection catalogs into ASS-CMO. This is a pre-v1.0.0 roadmap item, not an implementation task.

Initial scope — only these formats for now:

- PuTTY session import
- RDCMan `.rdg` import
- OpenSSH `config` import for Linux/Unix admins

No other import formats are planned at this stage.

Design constraints:

- Import is for migrating existing admin connection catalogs, not for populating inventory.
- Imported entries are connection hints / catalog data, **not** trusted inventory.
- Imported data must stay separate from agent-reported inventory until a matching/merge design exists.
- Import must **never** import credentials, passwords, private keys, credential blobs, or any secrets.
- Import must offer a preview / dry-run before writing anything.
- Supported initial import fields: display name, hostname / FQDN / IP, protocol, port, folder / group, optional username, and notes.

- [ ] `pre-1.0.0` `import` Define the connection-catalog data model kept separate from agent inventory.
- [ ] `pre-1.0.0` `import` Add PuTTY session import (display name, host, protocol, port, username, notes).
- [ ] `pre-1.0.0` `import` Add RDCMan `.rdg` import (display name, host, port, folder/group, notes), skipping any stored credential blobs.
- [ ] `pre-1.0.0` `import` Add OpenSSH `config` import (Host alias, HostName, Port, User) for Linux/Unix admins.
- [ ] `pre-1.0.0` `import` Provide a preview / dry-run step before any write, and never persist credentials or secrets.

### Application login / access control

**Decision:** Application-level login and access control are planned, but probably not a hard v1.0.0 requirement. The current intended deployment model is a trusted admin network / VPN / restricted reverse proxy, not public internet exposure.

- The privacy and internal-visibility risk of visible enrollment/inventory data is acknowledged. Within the intended deployment boundary it is treated as an access-control and deployment-boundary issue, not an application-layer secrecy feature.
- If an unauthorized person can observe or use the internal admin dashboard during host enrollment, that already implies a workplace / internal-network compromise beyond the scope of a read-only inventory table.
- Detailed exposure/trust-boundary wording lives in `SECURITY.md`.

- [ ] `pre-1.0.0` `access-control` Decide whether application-level login is a v1.0.0 requirement or a later milestone.
- [ ] `later` `access-control` Design optional application-level login / access control that does not weaken the trusted-network deployment assumption.

### Agent auto-updater decision

**Decision:** A server-driven automatic agent updater is not part of the supported core workflow now. Agent updates are intentionally operator-triggered by rerunning the installer/update command from the dashboard helper links.

- It was deferred because a safe updater requires careful security design: integrity/signature verification, rollback behavior, trust boundaries, and compromise-impact analysis.
- This is acceptable for now because agents change rarely, and for several upcoming versions agent changes are expected to mostly affect collection behavior rather than the inventory submission format, so older agents should remain tolerable for a while.
- Future requirement: any return of automatic updating must ship with a verified update channel before it is enabled.

- [ ] `later` `updater` Re-introduce automatic agent updates only with signature/checksum verification, rollback, and explicit trust boundaries.

## Immediate security hardening

These items are intentionally small and surgical.  
They should be fixed before larger cleanup/refactor work.

### P0: Inventory ingest must fail closed when token is missing

- [x] In `inventory.php`, reject startup/request if `ASSCMO_INVENTORY_TOKEN` is missing or empty.
- [x] Compare provided token with `hash_equals()`.
- [x] Allow only `POST`.
- [x] Require JSON-ish `Content-Type`, for example `application/json`.
- [x] Add request body size limit before parsing JSON.
- [x] Return generic 500 on DB errors.
- [x] Log DB exception details server-side only.

Acceptance:

- [x] Request without configured token returns 500.
- [x] Request without token header returns 403 when token is configured.
- [x] Request with wrong token returns 403.
- [x] Request with correct token and valid JSON succeeds.
- [x] GET/PUT requests return 405.
- [x] Oversized request returns 413.
- [x] DB error response does not expose DSN, SQL, username, table names or exception text.

### P0: Do not serve agent configs containing secrets

- [x] Ensure `/agents/linux/agent.conf` is never downloadable.
- [x] Ensure `/agents/windows/agent.conf.ps1` is never downloadable.
- [x] Serve only secret-free scripts through `/agents/`.
- [x] Document that config files with inventory tokens must be distributed out-of-band.

Acceptance:

- [x] `GET /agents/linux/agent.conf` returns 403 or 404.
- [x] `GET /agents/windows/agent.conf.ps1` returns 403 or 404.
- [x] Agent install/update scripts remain downloadable if intentionally exposed.
- [x] No generated token-bearing config is written into a public web-served path.

### P1: URI handler target validation

- [x] Add shared validation rules for SSH/RDP targets.
- [x] Reject targets beginning with `-`.
- [x] Reject whitespace, newlines, control characters, quotes and shell metacharacters.
- [x] Allow only `host`, `user@host`, `host:port`, `user@host:port`.
- [x] Remove or harden Windows `cmd.exe /k "ssh $Target"` fallback.
- [x] Add injection regression tests.

Acceptance:

- [x] `assssh://-oProxyCommand=...` is rejected.
- [x] `assssh://host;calc.exe` is rejected.
- [x] `assssh://host%0acalc.exe` is rejected.
- [x] `assssh://admin@example.local:22` is accepted.
- [x] `assrdp://server01.example.local:3389` is accepted.
- [x] Handler never constructs shell command strings from untrusted target input.

### P1: Notes URL allowlist

- [x] Keep current `http://` and `https://` only behavior.
- [x] Add explicit comment explaining why non-web schemes are rejected.
- [x] Add `parse_url()` double-check.
- [x] Add tests for `javascript:`, `data:`, malformed URLs and normal HTTPS links.

Acceptance:

- [x] `javascript:alert(1)` is rejected.
- [x] `data:text/html,...` is rejected.
- [x] `https://example.local/path` is accepted.
- [x] Future parser changes cannot silently widen accepted schemes.

### P1: Ingest endpoint hardening

- [x] Add basic payload schema validation.
- [x] Enforce max string lengths.
- [x] Validate IP addresses.
- [x] Validate integer ranges.
- [x] Limit array sizes.
- [x] Avoid storing obviously malformed payload sections.

Acceptance:

- [x] Malformed JSON returns 400.
- [x] Missing required `uid` returns 400.
- [x] Invalid IP fields are rejected or normalized to null.
- [x] Very large arrays/strings are rejected or truncated intentionally.

### P1: Updater trust model documentation

- [x] Add `SECURITY.md` note: the updater is not part of the trust boundary of the inventory protocol.
- [x] Document updater as trusted remote code distribution.
- [x] Document that updater must be signed/verified, disabled, or separated before public release.

Suggested wording:

> The updater is not part of the trust boundary of the inventory protocol. It is a trusted deployment mechanism and must be treated as remote code distribution.

## Post-v0.7.5 cleanup

- [ ] `ui` Consider a wiki-like command/help dashboard view for manual or bulk agent update commands.

- [ ] `0.8.0` Re-check clean install flow and refresh `install.sh` before public-release candidate testing.
- [ ] `ops` On existing internal deployments, sync changed default dashboard views into `config.local/dashboard-views` when needed.
- [ ] `now` Smoke-test manual Linux and Windows agent update actions on hosts that already have local agent config.

---

### Manual agent update launcher

- [x] `feature` For Linux outdated agents, provide manual update copy action next to SSH launcher.
- [x] `feature` For Windows outdated agents, copy the PowerShell update command to the admin workstation clipboard next to RDP launcher.
- [x] `ui` Reuse dashboard copy-to-clipboard feedback for manual agent update oneliner actions.
- [x] `docs` Document manual agent update as the default/fallback model. ASS-CMO prepares local admin actions but does not send remote commands to agents.
- [ ] `hardening` If automatic updating returns in a future version, require a secure update channel, integrity/signature verification, rollback behavior, and reviewed trust boundaries before shipping it publicly.

### Future automatic update design

- [ ] `critical` If automatic updating returns, do not execute downloaded agent code without local integrity verification.
- [ ] `critical` If automatic updating returns, verify both downloaded agent script and downloaded systemd unit file.
- [ ] `critical` If automatic updating returns, add signature verification or pinned checksum mechanism for updater artifacts.
- [ ] `docs` If automatic updating returns, document safer enterprise Windows deployment alternatives: GPO, Intune, SCCM or other standard deployment tooling.

### URI handlers

- [x] `critical` Validate SSH target format before launching local SSH client.
- [x] `critical` Reject SSH/RDP targets starting with `-`.
- [x] `critical` Reject SSH/RDP targets containing newlines, shell metacharacters or unsupported whitespace.
- [x] `critical` Add test cases for malicious URI targets:
  - `assssh://-oProxyCommand=...`
  - `assssh://host%20%26%20calc`
  - `assssh://host%0Acommand`
  - `assrdp://host /admin`
- [x] `hardening` Use `ssh -- "$target"` where supported.
- [x] `hardening` Avoid shell invocation in handlers wherever possible.
- [ ] `hardening` Keep bundled handlers generic and put local desktop-specific handlers into examples.

### Notes links / URL safety

- [x] `critical` Add URL scheme allowlist for notes-generated links.
- [x] `critical` Allow only `http://`, `https://` and internally generated `assweb://` where intended.
- [x] `critical` Reject or render as plain text:
  - `javascript:`
  - `data:`
  - `file:`
  - unknown custom schemes
- [x] `hardening` Add tests for notes parser URL handling.

### Adminer exposure

- [x] `critical` Ensure Adminer is not public by default in production-like deployment.
- [ ] `hardening` Add documented deployment options:
  - VPN-only
  - IP allowlist
  - Basic Auth
  - disabled Adminer
- [x] `docs` Add warning that exposing Adminer publicly without additional protection is unsafe.

---

## Short-term hardening

### Inventory ingest endpoint

- [x] `hardening` Add request body size limit for inventory JSON.
- [x] `hardening` Add Nginx `client_max_body_size` for inventory endpoint.
- [x] `hardening` Add Nginx `limit_req` for inventory endpoint.
- [x] `hardening` Return generic error messages to clients.
- [x] `hardening` Log database errors internally instead of echoing PDO exception messages.
- [x] `hardening` Set explicit response `Content-Type`, preferably `text/plain` or `application/json`.
- [x] `hardening` Do not echo raw UID input into a `text/html` response.
- [x] `hardening` Validate minimum required JSON schema before database write.

### Nginx / HTTP security

- [x] `hardening` Remove wildcard `Access-Control-Allow-Origin: *` unless cross-origin access is explicitly required.
- [x] `hardening` If CORS is needed, restrict it to explicit trusted origins.
- [x] `hardening` Add Content-Security-Policy for the dashboard.
- [x] `hardening` Verify whether `http-headers.conf` is actually included by example site configs.
- [x] `hardening` Verify whether `allowlist.conf` is actually included by example site configs.
- [ ] `cleanup` Consolidate duplicated security headers into one canonical snippet.
- [ ] `docs` Document expected deployment profiles:
  - private/VPN
  - lab
  - public-facing with hardening

### Windows handlers

- [x] `hardening` Register Windows URI handlers through `wscript.exe` instead of direct visible PowerShell launcher.
- [x] `hardening` Fix VBS wrapper argument order.
- [x] `hardening` Reduce VBS raw command-string risk or validate URI before command-string construction.
- [x] `hardening` Use PowerShell `Start-Process -ArgumentList` arrays consistently.
- [x] `hardening` Change RDP handler to use `-ArgumentList @("/v:$Target")`.
- [x] `hardening` Avoid `cmd.exe /k "ssh $Target"` fallback unless target validation is strict.

---

## Architecture / scope cleanup

### Non-RMM / non-C2 boundary

- [x] `docs` Add `SECURITY.md` with trust model and threat model.
- [x] `docs` Explicitly document that agents do not accept commands from the server.
- [x] `docs` Explicitly document that URI handlers execute actions locally on admin workstation.
- [x] `docs` Document updater as a temporary exception until final design is chosen.
- [x] `docs` Clarify that ASS-CMO is an inventory overview and connection launcher, not an RMM/C2 platform.

### SQL views

- [x] `docs` Document that `sql_is_reasonably_safe()` is a sanity guard, not a security boundary.
- [x] `hardening` Ensure dashboard DB role is truly read-only.
- [x] `hardening` Verify PostgreSQL permissions for dashboard user.
- [ ] `cleanup` Consider renaming/commenting SQL safety helper to avoid false sense of security.

### Local topology/config

- [ ] `cleanup` Move hardcoded network segments from source code into `config.local/` configuration.
- [ ] `cleanup` Replace prefix matching via `strpos()` with real CIDR matching.
- [ ] `hardening` Add tests for IP/subnet classification edge cases.

---

## Maintainability cleanup

### Legacy frontend files

- [x] `cleanup` Remove `old-stuff/v1` from webroot or move it to archival branch/tag.

### CSS / themes

- [x] `cleanup` Remove temporary theme override blocks from recent UI iterations.
- [x] `cleanup` Keep top metadata unboxed across themes.
- [ ] `cleanup` Reduce theme-specific component overrides where CSS variables are enough.
- [ ] `cleanup` Keep only necessary per-theme exceptions.
- [ ] `cleanup` Consider splitting CSS into logical files if it keeps growing:
  - base/layout
  - components
  - tables
  - modals
  - themes

### Linux handlers

- [x] `cleanup` Split Linux handlers into bundled handlers and local customization examples.
- [x] `cleanup` Add Yakuake SSH handler as local example.
- [x] `cleanup` Add Firefox-focus web handler as local example.
- [x] `hardening` Preserve local custom handlers unless overwrite is explicitly requested.
- [ ] `docs` Document bundled vs examples/custom handler workflow.

### Agents

- [ ] `hardening` Run ShellCheck-style audit on Linux agent scripts.
- [ ] `hardening` Quote shell variables consistently.
- [ ] `hardening` Review `set -e` / `set -u` edge cases.
- [x] `hardening` Review curl/PowerShell download error handling, retry behavior, timeouts and empty artifact checks.
- [ ] `hardening` Run PSScriptAnalyzer-style audit on Windows agent scripts.
- [ ] `cleanup` Replace localized `net localgroup` parsing with a more robust Windows API / PowerShell / CIM method.
- [ ] `hardening` Validate and normalize collected admin access data.

---

## Docker / compose / images

- [ ] `hardening` Pin Adminer image version instead of using `latest`.
- [ ] `hardening` Add PostgreSQL healthcheck.
- [ ] `hardening` Ensure PHP/nginx startup handles PostgreSQL not being ready yet.
- [ ] `docs` Document which compose profiles are production-like and which are lab/demo only.

---

## Documentation

- [x] `docs` Add `SECURITY.md`.
- [ ] `docs` Add deployment hardening checklist.
- [ ] `docs` Add handler threat model.
- [x] `docs` Add updater warning.
- [x] `docs` Add Adminer exposure warning.
- [ ] `docs` Add public release checklist.
- [ ] `docs` Add known limitations:
  - no inventory history yet
  - Grafana/TIM stack is currently minimal
  - Linux handlers tested mainly on Arch/KDE
  - Windows handlers tested on limited VM set

---

## Review sources

- Gemini review: high-level security and maintainability review, June 2026.
- Claude review: high-level code review, June 2026.
- Internal manual testing: Linux/KDE/Yakuake, Windows VM, PWA/assweb workflow.

---

## Review follow-up refinements

### From Gemini/Claude patch follow-ups

- [x] `hardening` Add explicit `parse_url()` scheme check in `notes_app_links()` even though the current regex already allows only `http://` and `https://`.
- [x] `docs` Document that notes-generated links intentionally allow only HTTP(S) URLs and internally generated `assweb://` links.
- [ ] `hardening` Test `ssh -- "$target"` on:
  - Linux OpenSSH
  - Windows OpenSSH
  - Windows Terminal SSH flow
  - Yakuake example handler
- [ ] `hardening` Treat Windows `Start-Process -ArgumentList` as safer than shell invocation, but not as a replacement for target validation.
- [ ] `hardening` Revisit VBS wrapper argument passing; current `wscript.exe` wrapper hides launcher windows, but raw command-string construction still deserves validation or a safer launcher mechanism.
- [x] `hardening` Ensure `limit_req_zone` is configured in the nginx `http` context, not inside a `server` or `location` block.
- [x] `hardening` Test CSP against:
  - dashboard table actions
  - PWA mode
  - hidden iframe URI launcher flow
  - `assssh://`
  - `assrdp://`
  - `assweb://`
- [x] `cleanup` Move inline dashboard JavaScript to external files so CSP can eventually remove `unsafe-inline`.
- [ ] `cleanup` Treat CIDR migration as a config migration, not a silent behavior change.
- [ ] `docs` Document migration from prefix-style network matching to CIDR notation in `sites.json`.
- [ ] `hardening` Add warnings/logging for old non-CIDR subnet entries.
- [ ] `hardening` Verify exact `xfreerdp` and Remmina argument syntax before changing Linux RDP handler behavior.

---

## Additional findings from independent ChatGPT review

### Inventory token / ingest fail-closed behavior

- [x] `critical` Make `inventory.php` fail closed when `ASSCMO_INVENTORY_TOKEN` is missing or empty.
- [x] `critical` Compare inventory token with `hash_equals()`.
- [x] `hardening` Allow only `POST` on `inventory.php`.
- [x] `hardening` Add explicit request size limit before reading/parsing full request body.
- [x] `hardening` Add basic schema validation for incoming inventory payload.
- [x] `hardening` Add sane length limits for string fields such as UID, hostname and OS name.
- [x] `hardening` Validate IP fields with `FILTER_VALIDATE_IP`.
- [x] `hardening` Validate numeric fields with bounded ranges.

### Agent config / secret distribution

- [x] `critical` Ensure real `agents/linux/agent.conf` is never publicly downloadable.
- [x] `critical` Ensure real `agents/windows/agent.conf.ps1` is never publicly downloadable.
- [x] `critical` Serve only secret-free agent scripts from `/agents/`.
- [ ] `hardening` Decide how agent secrets are distributed: manual config, VPN-only channel, per-host token, or deployment tooling.
- [x] `docs` Document that shared inventory token in downloadable config is unsafe.

### Installer command generation

- [x] `hardening` Validate `ASSCMO_BASE_URL` / `$baseUrl` before embedding it into generated shell or PowerShell commands.
- [x] `hardening` Ensure generated install commands quote URLs consistently across Bash and PowerShell.
- [x] `hardening` Prefer `https://` base URLs for generated installer commands.

### Windows agent filesystem ACL

- [x] `hardening` Set strict ACLs on `%ProgramData%\ASS-CMO`.
- [x] `hardening` Allow write access only for Administrators and SYSTEM.
- [x] `hardening` Protect PowerShell config files from modification by non-admin users.
- [x] `docs` Document that dot-sourced PowerShell config files are code execution surfaces.

### TIM / MQTT / Telegraf overlay

- [ ] `hardening` Mark TIM/MQTT stack as lab-only until hardened.
- [ ] `hardening` Do not expose anonymous MQTT listener on `0.0.0.0` by default.
- [ ] `hardening` Add MQTT auth/password-file or bind only to trusted LAN/VPN interface.
- [ ] `docs` Document that Telegraf Docker socket access is a sensitive monitoring surface.
- [ ] `hardening` Review whether Docker socket mount is required for default TIM profile.

### CSS / project structure long-term cleanup

- [ ] `cleanup` Consider splitting `dashboard.css` into multiple buildless CSS files.
- [x] `cleanup` Separate public PHP entrypoints from internal includes using `app/public` and `app/includes`.
- [ ] `cleanup` Keep this as long-term maintainability work, not part of immediate security hardening.
### Legacy runtime database owner cleanup

- [ ] `cleanup` Optionally migrate older deployments that still use the legacy PostgreSQL owner/user name `assrmm` to the current `asscmo` naming. This is not an immediate blocker because the dashboard uses the read-only `ass_dashboarder` role and new/example configs use `asscmo`.
