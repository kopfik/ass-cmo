# Changelog

## v0.7.5 - internal pre-public review snapshot

### Changed

- Marked the final internal review snapshot before the planned clean public `v0.8.0` GitHub import.
- Consolidated About content into the dashboard modal and removed the legacy standalone About page.
- Refined public About wording around security model and project scope.
- Kept `v0.8.0` as the planned public release milestone.

## v0.8.0 - unreleased

### Added

- Added admin UI enrollment approval view (`?view=enrollment`) in the existing dashboard sidebar.
- Enrollment requests display `pairing_code` prominently for admin visual comparison with the target machine console. The pairing code is display-only and is not an authentication factor.
- Admin approve and deny actions are CSRF-protected per-session form submissions. Approve requires an explicit confirmation checkbox that the pairing code matches before the request is processed.
- Duplicate active UID approval is handled gracefully: if a UID already has an active `agent_auth` entry, the new request is automatically denied with a stored `denial_reason` and the admin sees an actionable message without needing to inspect server logs.
- Denied enrollment requests are retained for audit trail and are never physically deleted.
- Enrollment start response now includes `verification_url` pointing to `/?view=enrollment&request_id=N` for navigation and filtering. The URL contains only the request ID and never includes `poll_token`, `pairing_code`, `agent_secret`, or any hash.
- Enrollment polling now sends `poll_token` in the `X-Poll-Token` header instead of URL query parameters.
- Linux and Windows installers print `verification_url` from the enrollment start response when present, falling back to the admin UI base URL when absent.
- Fresh installs now generate `ASSCMO_ENROLLMENT_PEPPER` and `ASSCMO_ENROLLMENT_APPROVE_TOKEN`, and runtime rejects the public enrollment placeholder values.
- Stale pending enrollment requests now expire server-side, and approved-but-uncollected one-time `agent_secret` values are cleared when the request expires.
- Enrollment approval now handles duplicate UID races cleanly, explicitly records `agent_secret_hash_algorithm`, and validates enrollment UIDs with the same 64-character limit as inventory/auth storage.
- Dashboard readonly grants are now allowlisted so enrollment/auth secret-bearing tables are not readable by the dashboard readonly role.
- Automatic agent updater is not shipped in public `v0.8.0`; it was removed/deferred until a secure update channel, integrity/signature verification model, rollback behavior, and trust boundaries are designed.
- Inventory submissions reject disabled or revoked per-host secrets with a generic `403`.
- Added a synthetic `Agent auth` dashboard view with explicit host-row revoke controls for active or disabled per-host secrets.
- Approved-but-uncollected enrollment expiry now archives and removes the safe orphan `agent_auth` row created from the same request when it was never used.
- Revoked hosts can now fresh re-enroll with a new per-host secret while preserving the old revoked `agent_auth` row in archive history, and legacy shared-token fallback rejects disabled/revoked UIDs when a matching `agent_auth` row exists.

### Fixed
- Linux public agent installer and systemd unit files are now compatible with older curl (no `--retry-all-errors`) and older systemd targets (removed `Restart=`/`RestartSec=` from oneshot service, removed `StartLimitIntervalSec`/`StartLimitBurst`, removed `RandomizedDelaySec` from timer).
- Linux agent now exits 0 with an informative message when the server returns HTTP 401 or 403, so a revoked or disabled host does not cause pacman/apt package-manager hooks to report an error. Network errors and server 5xx responses still exit non-zero.

### Security
- Hidden raw exception details from enrollment, agent-auth, and dashboard view load failure responses; detailed errors now go to server logs only and generic messages are shown in the UI.
- Removed PCRE `grep -P` usage from Linux agent interface and IP address detection; replaced with POSIX-compatible alternatives to avoid hard `grep` dependency on PCRE support.
- Added server-side validation of host and SSH-user values before rendering `assssh://` and `assrdp://` launcher links; malformed or unsafe targets are silently omitted instead of rendered as active URIs.
- Dashboard SQL view execution now requires `POSTGRES_DASHBOARD_USER` and `POSTGRES_DASHBOARD_PASSWORD` to be explicitly configured; the view query no longer falls back to the main admin database user when read-only credentials are missing, and a generic misconfiguration error is shown in the UI while the configuration detail goes to server logs only.

### Remaining scope before public GitHub release

- Agent VERSION bump and bundle alignment for public release.
- Public-release hardening, secret scan, and clean GitHub repository creation.

## v0.7.3 - 2026-06-12

### Added
- Added server-side inventory ingest authentication using `uid` plus per-host `X-Agent-Secret` validated against `agent_auth`, with legacy shared-token fallback gated behind an explicit environment flag.
- Added Linux fresh-install enrollment bootstrap in the bundled installer, creating local `ASSCMO_AGENT_SECRET` config only after approved enrollment polling succeeds.
- Added Windows fresh-install enrollment bootstrap in the bundled installer, starting enrollment and polling for admin approval when `%ProgramData%\ASS-CMO\agent.conf.ps1` is absent; writes local `ASSCMO_AGENT_SECRET` config after approval.

### Changed
- Bundled Linux and Windows agents now prefer `ASSCMO_AGENT_SECRET` for inventory submissions and fall back to the legacy shared inventory token only when the per-host secret is not configured.

### Notes
- Real-host Linux validation confirmed the current fresh-enrollment path can display a pairing code, accept manual approval, create a local `ASSCMO_AGENT_SECRET` config without downloading a token-bearing file, preserve that config on reinstall, and authenticate successful inventory submissions with populated `agent_auth` activity timestamps.
- Real-host Windows validation on a disposable Windows 11 VM confirmed fresh enrollment creates a pending request, displays a pairing code, creates local `ASSCMO_AGENT_SECRET` config after approval, preserves config on reinstall, and authenticates successful inventory submissions with populated `agent_auth` activity timestamps.
- v0.7.3 is an internal checkpoint release. v0.8.0 remains the planned public/security-gate milestone for admin UI enrollment polish, revocation, migration, and public-release hardening.

## v0.7.2 - 2026-06-12

### Added
- Added internal server-side enrollment foundation: enrollment auth schema and helpers, start and poll endpoints, bearer-protected approval, one-time agent secret delivery, and a dev smoke test script.
- Added v0.8.0 planning/tracking notes for public-release reliability review, future connection import, and multi-agent workflow maintenance.

### Fixed
- Fixed enrollment runtime include loading and PHP container environment mapping for enrollment pepper and approve-token variables.

### Notes
- Manual smoke testing on internal deployments verified enrollment start, pending poll, bearer-protected approve, one-time agent secret delivery, and 404 after the used request state.
- v0.7.2 is an internal stabilization release; v0.8.0 remains the future public/security gate.

## v0.7.1 - 2026-06-11

### Changed
- Refreshed TODO backlog and documentation to reflect post-v0.7.0 internal maintenance state.
- Clarified INSTALL.md wording around the 0.7.x agent provisioning and update model.
- Updated AGENTS.md with multi-agent workflow documentation, user interaction preferences, and Czech shorthand status words.
- Optimized Linux agent installer to download distro-specific upgrade hooks only for the detected distribution.
- Removed redundant `fastcgi_index` directive from the Nginx example configuration for the inventory endpoint.

### Fixed
- Resolved potential PDO issue by avoiding reused named parameters for agent versioning in the inventory ingest SQL.
- Tightened inventory ingest string normalization limits to match PostgreSQL column sizes, preventing overlength values from causing failures.

### Notes
- v0.7.1 is an internal maintenance release; public release remains gated by the v0.8.0 secure enrollment milestone.

## v0.7.0 - 2026-06-10

### Changed
- Clarified that fresh public agent installation was temporarily blocked during the 0.7.x transition to secure enrollment while existing agents with local configuration could still be updated.
- Documented `/scripts/` as an intentional admin-managed public script area for bootstrap, post-install, normalization, or hardening scripts.
- Moved installer credential output to a final first-login prompt and stopped printing internal service tokens by default.
- Added a proxy-oriented nginx SSL snippet without CSP for Grafana/Adminer reverse-proxy vhosts.
- Installer now auto-selects a detected Let's Encrypt certificate instead of prompting when a matching certificate is available.
- Installer now expands dashboard view placeholders for base URL, dashboard SSH user and bundled Linux agent version when preparing `config.local/dashboard-views`.
- Improved installer terminal output with clearer colored step, warning, value and input prompts.
- Renamed runtime private configuration paths from `local/` to `config.local/` while keeping versioned examples under `config.example/`.
- Refreshed the TODO backlog with a focused v0.7.0 release checklist and marked completed manual update/layout cleanup items.
- Documented that Agent versions view expected version constants must stay in sync with bundled Linux and Windows agent version files before release.
- Tuned Proxmox-related action button colors toward a darker variant of the original Proxmox logo orange `#e57000`.
- Renamed manual agent update copy buttons to `AGENT UPDATE ONELINER` and styled them separately from appliance `UPDATE` links.
- Added manual agent update copy actions for outdated Linux and Windows agents in the Agent versions dashboard view.
- Added backend command helpers for manual Linux and Windows agent update actions without enabling the optional updater.
- Moved app version metadata from root `VERSION` to `meta/VERSION` and mounted it as a directory to avoid Docker single-file bind mount inode issues after `git pull`.
- Moved PHP public webroot from `server/php/html` to `app/public`.
- Moved internal PHP includes from public webroot to `app/includes` and mounted them outside `/var/www/html`.
- Moved PHP Docker build context from `server/php` to `docker/php`.
- Moved PostgreSQL first-init SQL from `server/sql` to `database/init`.
- Moved explicit/admin database SQL scripts from `server/sql` to `database/scripts`.

### Fixed
- Hardened installer handling of `config.local/.env` by enforcing restrictive permissions.
- Removed hardcoded internal DNS resolver values from example nginx snippets.
- Fixed manual installation documentation so local configuration directories are created before copying files.
- Removed legacy documentation and command paths that attempted to download token-bearing `agent.conf` over HTTP.
- Fixed IPv4 site prefix matching to compare whole octets instead of raw string prefixes.
- Made inventory string truncation UTF-8 safe and added `mbstring` to the PHP image.

### Removed
- Removed the legacy `/v2/` compatibility redirect.
- Removed the old `server/` project tree.

### Known limitations
- 0.7.x still uses a temporary shared inventory token model for private/internal deployments.
- Fresh public agent enrollment is intentionally not available in 0.7.x.
- Secure enrollment, per-host agent secrets, revocation, and migration from the shared-token model are planned for 0.8.0.
- 0.7.0 is an internal release, not the planned public GitHub release.

## v0.6.4 - 2026-06-09

### Security
- Hardened `inventory.php` ingest handling:
  - inventory token now fails closed when `ASSCMO_INVENTORY_TOKEN` is missing or empty,
  - token comparison now uses `hash_equals()`,
  - only `POST` requests are accepted,
  - JSON `Content-Type` is required,
  - request body size is limited,
  - malformed JSON is rejected before processing,
  - database exception details are logged server-side only and no longer returned to clients.
- Blocked public download of token-bearing agent config paths in the nginx example configuration:
  - `/agents/linux/agent.conf`
  - `/agents/windows/agent.conf.ps1`
- Added Linux bundled URI handler target validation for SSH and RDP handlers:
  - rejects targets beginning with `-`,
  - rejects whitespace, control characters, quotes and shell metacharacters,
  - validates hostname, username, port and bracketed IPv6 literal targets,
  - prevents SSH option injection by using `ssh --`.
- Added Windows bundled URI handler target validation for SSH and RDP handlers:
  - rejects unsafe SSH/RDP targets before launching local applications,
  - validates hostname, username, port and bracketed IPv6 literal targets,
  - removes the `cmd.exe /k "ssh $Target"` fallback,
  - launches OpenSSH through `Start-Process` with an argument array,
  - adds basic VBS wrapper rejection for quotes and newlines in handler arguments.
- Made notes web URL handling use an explicit `http`/`https` allowlist with a `parse_url()` scheme check, documenting that non-web schemes such as `javascript:`, `data:`, `file:` and custom URI schemes must stay rejected.
- Added conservative inventory payload normalization:
  - validates required `uid`,
  - normalizes invalid optional IP fields to `null`,
  - limits array sizes for DNS, IP, listening port and admin access fields,
  - clamps numeric counters and percentage fields to safe ranges,
  - strips control characters and limits string lengths before database insert.
- Added example Nginx hardening for the inventory ingest endpoint:
  - `client_max_body_size 1m` for `/inventory.php`,
  - `limit_req` rate limiting for inventory POST traffic,
  - tested on both current deployments with valid ingest requests and oversized payload rejection.
- Cleaned up optional Nginx HTTP headers snippet:
  - removed default wildcard CORS from the example snippet,
  - documented that CORS should use explicit trusted origins only when required,
  - kept the snippet optional to avoid duplicating headers already provided by SSL params.
- Removed the legacy `/old-stuff/v1/` dashboard UI from the active webroot to reduce maintenance and security review scope.
- Moved active dashboard inline JavaScript into static local assets to prepare for a stricter Content Security Policy.
- Added a restrictive Content Security Policy header to the example Nginx SSL params after moving active dashboard JavaScript into static local assets.
- Updated the Content Security Policy to allow ASS-CMO custom URI schemes in `frame-src`, preserving local SSH/RDP/WEB handler launches under CSP.
- Documented the dashboard SQL view security boundary:
  - `sql_is_reasonably_safe()` is only an application-level sanity guard,
  - the real write-protection boundary is the PostgreSQL read-only dashboard role,
  - verified current deployments with `ass_dashboarder` read-only permission checks.
- Applied the example nginx private-network allowlist to web vhosts such as ASS-CMO and Adminer, making the intended internal/VPN exposure model explicit.
- Clarified the v0.6.4 updater trust model: the current agent updater is kept as an internal/VPN deployment helper, while artifact signing or checksum verification remains future hardening.
- Recorded legacy runtime database owner cleanup as a non-blocking follow-up for older deployments still using the historic `assrmm` PostgreSQL role name.
- Decided the default future agent update model:
  - Linux outdated agents will use a manual SSH update launcher,
  - Windows outdated agents will copy a PowerShell update command and open RDP,
  - the automatic updater remains optional/internal-only until signed bundles and checksum verification are implemented.

- Hardened agent installer/updater downloads with retry/timeout handling and empty artifact checks for Linux shell scripts and Windows PowerShell scripts.
- Hardened generated install commands by validating the configured ASS-CMO base URL and consistently quoting URLs for Bash and PowerShell command snippets.
- Hardened the Windows agent installer to restrict `%ProgramData%\ASS-CMO` and installed agent files to LocalSystem and built-in Administrators, protecting the dot-sourced PowerShell config from non-admin modification.
- Fixed the Windows agent uninstaller default scheduled task name to match the installer-created `ASS-CMO-Agent` task.

### Documentation
- Added `SECURITY.md` documenting ASS-CMO trust boundaries:
  - inventory ingest is one-way agent reporting,
  - updater is trusted remote code distribution and not part of the inventory protocol trust boundary,
  - URI handlers must treat input as untrusted,
  - token-bearing agent config files must not be served from public HTTP(S) paths.


## v0.6.3 - 2026-06-08- Improved the Windows URI handler installer existing-installation message so it prints a copy-paste overwrite command using the current installer URL.

### Changed
- Restructured Linux URI handlers into bundled handlers and local customization examples.
- Improved Linux handler installer output and overwrite guidance.
- Fixed Windows URI handler VBS wrapper argument order.
- Registered Windows URI handlers through `wscript.exe` to avoid PowerShell launcher window flashes.

## v0.6.2 - 2026-06-08

### Added
- Added runtime branding overrides for header logo and favicon assets via config.local/branding.
- Added an example Linux bulk agent update dashboard view that generates a copyable SSH loop for outdated Linux agents.
- Added darker Rosé Pine theme.
- Added Rosé Pine Moon theme name for the existing lighter Rosé Pine variant.

### Changed
- Made Adminer respect `ASSCMO_ASSWEB_PROTOCOL`.
- Filled the dashboard About modal with project, handler, notes syntax and architecture information.
- Reduced visual border contrast in Dust Dark.
- Hid Windows URI handler launcher windows using VBS wrappers.
- Cleaned up dashboard theme CSS structure.
- Kept top dashboard metadata visually integrated with the header across themes.

## v0.6.1 - 2026-06-08

### Added
- Added optional `assweb://` URI protocol support for opening notes web actions through the system default browser.
- Added `ASSCMO_ASSWEB_PROTOCOL=false` feature flag for enabling `assweb://` link generation.
- Added Linux and Windows `assweb://` URI handlers.

### Changed
- URI handler installers now protect existing local handler customizations by default.
- Existing local handlers are only replaced when explicitly reinstalling with `ASSCMO_OVERWRITE_HANDLERS=1`.

## v0.6.0 - 2026-06-08

### Added
- Added a redesigned modular dashboard UI as the default interface.
- Added legacy dashboard fallback under `/old-stuff/v1/`.
- Added `/v2/` compatibility redirect to the default dashboard.
- Added dashboard About modal.
- Added Dust Dark and Rosé Pine color themes.
- Added sidebar command panels with full-row copy actions.
- Added sidebar version panel for app and bundled agent versions.

### Changed
- Refactored dashboard PHP into reusable includes for layout, views, actions, commands, tables and common helpers.
- Promoted the fixed app-frame layout so the header/sidebar stay in place while content scrolls.
- Unified compact button and badge styling across actions, theme controls, launcher badges and copy controls.
- Improved command launcher, modal and sidebar visual polish.

### Notes
- The previous dashboard UI remains available temporarily at `/old-stuff/v1/`.

## v0.5.11 - Dashboard QoL polish

- Added root `VERSION` file and displayed app and agent bundle versions in the dashboard sidebar.
- Mounted agent bundle files into the PHP container for dashboard-side version display.
- Replaced hardcoded README current version with a pointer to `VERSION` and `CHANGELOG.md`.
- Added CSS cache busting for the dashboard stylesheet.
- Added notes metadata support for per-host SSH user overrides using `ssh_user:` or `ssh-user:`.
- Added dedicated `SHELL` action handling for `shell:` notes links.
- Added colored action badges to the command launcher.
- Improved command launcher result ordering to prefer appliance/admin actions over generic SSH.
- Polished command launcher badge colors and sidebar version footer layout.

## v0.5.10 - Linux installer cleanup

- Added `agents/linux/install-ass-cmo-agent.sh` for Linux agent installation.
- Replaced long Linux dashboard install commands with short installer bootstrap commands.
- Added `--with-updater` support to the Linux installer.
- Preserved existing `/etc/ass-cmo/agent.conf` during Linux reinstall.
- Verified Linux and Windows updater installs preserve existing runtime configs.

## v0.5.9 - Hotfixes

- Passed dashboard base URL and Adminer URL environment variables into the PHP container.
- Set `agent_update_time` server-side when the reported agent bundle version changes.
- Updated README current version.
- Added missing `webmanifest` MIME type to the nginx example.
- Fixed the Windows URI handler install command shown in the dashboard sidebar.
- Stopped the Linux updater from downloading unused runtime config.
- Preserved existing Windows agent config during reinstall.
- Preserved existing Linux agent config in dashboard install commands.

## v0.5.8 - Optional agent updaters

- Added optional Linux agent updater service and timer.
- Added optional Windows agent updater scheduled task.
- Added dashboard sidebar install commands for agents with and without updater.
- Split sidebar copy commands into Agents and Handlers groups.
- Linux and Windows updaters compare local bundle VERSION with the server-served VERSION file.
- Agent updaters preserve local agent configuration and update only bundle files.
- Linux agent bundle updated to 0.4.3.
- Windows agent bundle updated to 0.4.2.
- Hardened Linux agent JSON payload generation for empty or invalid values.
- Added retry behavior to the Linux agent systemd service after failed inventory upload.
- Fixed nginx template enabled-site entry to be a symlink to sites-available instead of a copied file.

## v0.5.7 - Unreleased - First installable version polish

- Reworked README with a fuller project overview and usage model.
- Documented the database-centered inventory design.
- Documented dashboard views, command launcher and connection actions.
- Documented workstation SSH/RDP expectations and URI handler usage.
- Clarified optional Grafana and TIM stack overlay status.
- Fixed installer environment handling and agent base URL propagation.

## v0.5.6 - Unreleased - Dashboard command launcher

- Added Ctrl+K / Cmd+K dashboard command launcher.
- Added keyboard-driven SSH, RDP and web action execution from the current dashboard view.
- Added compact dashboard table labels and shortened OS display values.
- Renamed ASS-CMO expansion to Admins Secure Server Connection Manager & Overview.

## v0.5.5 - Unreleased - PWA app metadata

- Added PWA manifest metadata for installing ASS-CMO as a standalone app.
- Added ASS-CMO app icons and favicon assets.
- Configured manifest and browser icon links.
- Fixed nginx example MIME type for webmanifest files.
- Improved custom SSH/RDP protocol link handling for browser/PWA use.

## v0.5.4 - Unreleased - Dashboard theme modes

- Added dashboard theme switcher with browser-local preference.
- Added Auto, Light, Dark, Midnight, Retro and Dust dashboard themes.
- Added neutral dark theme and colorful Midnight theme.
- Tuned dashboard search input colors for light and dark themes.

## v0.5.3 - Unreleased - Dashboard table usability

- Added live dashboard table filtering.
- Added client-side sortable table columns.
- Added visible row count for filtered dashboard views.

## v0.5.2 - Unreleased - Core interactive installer

- Added root `install.sh` for interactive core installation.
- Installer prepares local config directories, dashboard views, agent configs and secrets.
- Installer configures nginx hostnames and Let's Encrypt certificate path placeholders.
- Installer starts only the core stack: PostgreSQL, Adminer, PHP and nginx.
- Installer creates or updates the dashboard read-only database user.
- Updated core compose fallback database defaults from legacy `assrmm` values to `inventory_db` and `asscmo`.

## v0.5.1 - Unreleased - Client URI handlers

- Added Linux client URI handler installer for `assssh://` and `assrdp://`.
- Added Windows client URI handler installer for `assssh://` and `assrdp://`.
- Moved client URI handlers to the public `agents/handlers/` path.
- Fixed dashboard SQL safety check for string literals such as status labels.
- Documented client-side SSH/RDP URI handler setup.

## v0.5.0 - 2026-05-29 - Basic dashboard frontend

- Added basic read-only dashboard frontend.
- Added dynamic dashboard SQL views loaded from `config.local/dashboard-views/*.sql`.
- Added example dashboard SQL views under `config.example/dashboard-views/`.
- Added dashboard action buttons for SSH, RDP, and application links.
- Added custom application links parsed from inventory `notes` using `LABEL: URL` lines.
- Added optional `ASSCMO_DASHBOARD_SSH_USER` for generated SSH links.
- Added dashboard read-only PostgreSQL user helper SQL.
- Added dashboard database environment variables.

## v0.4.1 - 2026-05-28 - Installation documentation cleanup

- Cleaned up installation documentation.
- Updated README wording and version references.
- Added ignore rules for local agent configs.
- Removed stray legacy `ass-rmm,` file.
- Fixed README markdown code fence.

## v0.4.0 - 2026-05-27 - Windows agent and cleanup

- Added Windows inventory-only agent.
- Added Windows installer and uninstaller.
- Renamed Linux agent files and paths to ASS-CMO naming.
- Renamed environment variables from ASSRMM_* to ASSCMO_*.

## v0.3.0 - 2026-05-26 - TIM stack layer

- Added optional TIM Compose overlay:
  - Telegraf
  - InfluxDB
  - Mosquitto MQTT
- Renamed Docker Compose project to `ass-stack`.
- Renamed containers to `ass-*`.
- Renamed Docker network to `ass-net`.
- Added example Mosquitto configuration.
- Added example Telegraf configuration.
- Added local TIM configuration directory layout.
- Added documentation for running core + Grafana + TIM stack.
- Project name changed to **ASS-CMO**: **Admins Secure Server Connection Manager & Overview**, which better reflects the purpose of this project.

## v0.2.1 - 2026-05-22  - Documentation repair

- Repaired documentation after the Grafana layer release.
- Cleaned up install instructions and version references.

## v0.2.0 - 2026-05-22  - Grafana layer

- Added optional Grafana Compose overlay.
- Added Grafana environment variables to `.env.example`.
- Added optional LDAP/Active Directory configuration support for Grafana.
- Added Grafana local configuration layout.
- Prepared Grafana provisioning and dashboard directories.

## v0.1.3 - 2026-05-21 - Documentation fixes

- Added missing `v0.1.2` changelog entry.
- Cleaned and restructured `INSTALL.md`.
- Added table of contents to `INSTALL.md`.

## v0.1.2 - 2026-05-21 - Add inventory database schema

- Added missing `server/sql/001_inventory.sql` to the repository.
- Fixed fresh install schema initialization.

## v0.1.1 - 2026-05-21 - Documentation cleanup

- Shortened `README.md` into a project overview.
- Moved detailed installation instructions to `INSTALL.md`.
- Added `CHANGELOG.md`.
- Added `SECURITY.md`.

## v0.1.0 - 2026-05-21 - Core inventory

- Initial core inventory release.
- Added Docker Compose core stack:
  - PostgreSQL 18
  - Adminer
  - nginx
  - PHP 8.5-FPM
- Added JSON inventory endpoint.
- Added Linux inventory agent.
- Added systemd timer.
- Added apt hook for Debian-based systems.
- Added pacman hook for Arch Linux.
- Added OS/appliance detection for:
  - Arch Linux
  - Debian
  - Proxmox VE
  - Proxmox Backup Server
  - Proxmox Mail Gateway
  - Proxmox Datacenter Manager
  - OpenMediaVault
