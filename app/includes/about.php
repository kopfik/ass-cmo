<?php
declare(strict_types=1);

function render_about_modal(): void {
    ?>
<div id="about-modal" class="about-modal" hidden>
    <div class="about-modal-backdrop" data-about-close="1"></div>
    <section class="about-modal-panel" role="dialog" aria-modal="true" aria-labelledby="about-modal-title">
        <header class="about-modal-header">
            <div>
                <h2 id="about-modal-title">About ASS-CMO</h2>
                <p>Project overview, usage notes and architecture.</p>
            </div>
            <button type="button" class="about-modal-close" data-about-close="1" aria-label="Close about dialog">×</button>
        </header>

        <div class="about-modal-content">
            <section class="about-card">
                <h3>What is ASS-CMO?</h3>
                <p><strong>ASS-CMO</strong> means <strong>Admins Secure Server Connection Manager &amp; Overview</strong>.</p>
                <p>ASS-CMO is an inventory, overview and connection dashboard for administrators who manage Linux servers, Windows servers, virtual machines and appliance-style systems such as Proxmox VE, Proxmox Backup Server, Proxmox Mail Gateway, Proxmox Datacenter Manager or OpenMediaVault.</p>
                <p>The goal is to provide a practical daily dashboard: what machines exist, where they are, when they were last seen, what operating system and kernel they run, whether they need attention, and how to open the right local admin tool quickly.</p>
                <p>ASS-CMO is intentionally <strong>not a full RMM</strong> and not a command-and-control platform. It does not provide arbitrary remote command execution from the dashboard.</p>
            </section>

            <section class="about-card">
                <h3>Quick usage tips</h3>
                <ul>
                    <li>Press <strong>Ctrl+K</strong> or <strong>Cmd+K</strong> to open the command launcher.</li>
                    <li>Type a hostname, IP address or action name such as <code>ssh</code>, <code>rdp</code>, <code>pve</code>, <code>pbs</code>, <code>shell</code>, <code>update</code>, <code>gitea</code> or <code>omv</code>.</li>
                    <li>Press <strong>Enter</strong> to run the selected action.</li>
                    <li>Dashboard action buttons use local URI handlers for SSH/RDP and optionally for web links.</li>
                    <li>SQL views in the left sidebar are read-only SQL files loaded from <code>config.local/dashboard-views</code>.</li>
                </ul>

                <div class="about-action-samples" aria-label="Action button examples">
                    <span class="action action-ssh">SSH</span>
                    <span class="action action-rdp">RDP</span>
                    <span class="action action-pve">PVE</span>
                    <span class="action action-update">UPDATE</span>
                    <span class="action action-shell">SHELL</span>
                    <span class="action action-web">GITEA</span>
                </div>
            </section>
            <section class="about-card">
                <h3>Application options</h3>
                <p>Not boring adds harmless dry comments to quiet corners of the UI. It does not affect security decisions, scheduling, inventory, or reality. This preference is local to this browser and is never sent to the server.</p>
                <div class="about-pref-row">
                    <label class="about-pref-label" for="narrator-toggle">
                        <input type="checkbox" id="narrator-toggle" class="about-pref-checkbox">
                        Not boring
                    </label>
                    <span id="narrator-hint" class="about-pref-hint"></span>
                </div>
            </section>

            <section class="about-card">
                <h3>URI handlers</h3>
                <p>ASS-CMO can generate local URI links such as:</p>
                <pre><code>assssh://user@host
assrdp://host
assweb://https%3A%2F%2Fpve.example.local%3A8006%2F</code></pre>
                <p>These links are handled by scripts installed on the administrator’s workstation, not on the managed server.</p>
                <p>This means the dashboard does not open a remote shell itself. It asks the admin’s local computer to open the preferred SSH client, RDP client or default browser.</p>
                <p>The handlers are optional, but they are a major part of the daily workflow. Without them, ASS-CMO is mostly an inventory overview and web-link dashboard.</p>
            </section>

            <section class="about-card">
                <h3>Notes syntax</h3>
                <p>The <code>notes</code> field is intended for manual administrator notes and custom action links.</p>
                <p>Lines in this format create dashboard action buttons:</p>
                <pre><code>LABEL: https://example.local/</code></pre>
                <p>Examples:</p>
                <pre><code>GITEA: https://gitea.example.local/
OMV: https://nas.example.local/
UPDATE: https://router.example.local/updates
shell: https://pve.example.local:8006/#v1:0:=node%2Fpve:4:=jsconsole::::::</code></pre>
                <p>Special labels such as <code>shell</code> and <code>UPDATE</code> get their own visual action style.</p>
                <pre><code>ssh_user: root
ssh-user: root</code></pre>
                <p><code>ssh_user</code> or <code>ssh-user</code> overrides the SSH username for a specific host. This is useful when most systems use the admin’s local username, but a specific appliance requires <code>root</code> or another account.</p>
                <p><code>notes</code> are not used for automatic agent state, updater status or machine health. They are a manual admin layer.</p>
            </section>

            <section class="about-card">
                <h3>Architecture</h3>
                <p>ASS-CMO uses a small core stack:</p>
                <ul>
                    <li>a PostgreSQL inventory database,</li>
                    <li>a PHP inventory endpoint and dashboard frontend,</li>
                    <li>SQL files for customizable dashboard views,</li>
                    <li>native Linux and Windows agents,</li>
                    <li>optional local URI handlers on the administrator’s workstation.</li>
                </ul>
                <p>Agents collect inventory using native operating system tools such as Bash on Linux and PowerShell on Windows. They send JSON inventory data to the server, where it is parsed and stored in PostgreSQL.</p>
                <p>The database stores the current inventory overview. It is not designed as a historical monitoring system. In practical terms, ASS-CMO shows the latest known state of each machine.</p>
                <p>Dashboard views are ordinary SQL queries. They can be customized by editing or adding SQL files in the dashboard views directory.</p>
            </section>

            <section class="about-card">
                <h3>Inventory model</h3>
                <p>ASS-CMO is focused on the <strong>latest known inventory state</strong>.</p>
                <p>It is not currently a historical time-series inventory system. The normal dashboard shows the latest inventory update for each known machine. If a host reports new data, its current record is updated.</p>
                <p>Historical inventory, long-term metrics, alerting and graphing may be considered in the future, but they are not part of the current core design.</p>
            </section>

            <section class="about-card">
                <h3>Dashboard views</h3>
                <p>Dashboard views are ordinary SQL files.</p>
                <pre><code>config.local/dashboard-views/</code></pre>
                <p>Each view may include metadata comments:</p>
                <pre><code>-- label: Linux overview
-- description: Linux hosts grouped by update and reboot state.</code></pre>
                <p>The dashboard only allows simple read-only <code>SELECT</code> or <code>WITH</code> queries. This is intentional: dashboard views should display data, not modify it.</p>
                <p>The <code>notes</code> column may be selected by a view even if it is not displayed. The dashboard hides it as a table column, but uses it to generate custom action buttons.</p>
            </section>

            <section class="about-card">
                <h3>What ASS-CMO is not</h3>
                <p>ASS-CMO is not a replacement for monitoring, logging or alerting.</p>
                <p>It is not a full RMM.</p>
                <p>It is not intended to run arbitrary commands on all machines from a central web interface.</p>
                <p>It is not designed as a command-and-control center.</p>
            </section>

            <section class="about-card">
                <h3>Why not just PuTTY, RDM or full RMM?</h3>
                <p>PuTTY and classic connection managers are good at storing connection targets, but they usually do not know what is currently installed on the machine, when it last reported, what OS it runs or whether it needs attention.</p>
                <p>Full RMM tools are powerful, but they often introduce a much larger security and operational surface: remote command execution, policy engines, remote script deployment, background automation and complex agent privileges.</p>
                <p>ASS-CMO is intentionally in the middle:</p>
                <ul>
                    <li>more aware than a static connection manager,</li>
                    <li>simpler and safer than a full RMM,</li>
                    <li>focused on inventory, overview and local admin actions,</li>
                    <li>designed for administrators who still want to control what they open and when.</li>
                </ul>
            </section>

            <section class="about-card">
                <h3>Security model</h3>
                <p>ASS-CMO is designed around a small security surface: HTTPS transport, enrolled agents, local agent configuration and a dashboard that does not expose remote command execution.</p>
                <p>The dashboard is intentionally read-oriented. It shows inventory and opens local administrator tools; it is not a command-and-control interface.</p>
                <p>Agent configuration and host-specific credentials are runtime configuration and must not be overwritten by application or agent bundle updates.</p>
                <p>Future security improvements may include signed agent bundles, checksums and stricter public release verification.</p>
            </section>

            <section class="about-card">
                <h3>Project scope</h3>
                <p>ASS-CMO is an application in active development. It is not trying to be a universal, full-featured, out-of-the-box product for every possible environment.</p>
                <p>The direction of the project is driven by concrete real-world administration problems, needs and use cases. Some choices may be opinionated, some workflows may be intentionally simple, and some features may not fit every administrator.</p>
                <p>Public releases are practical, reviewed snapshots of that work. The project is shaped by operational needs, not by a promise to implement every requested feature.</p>
            </section>
        </div>
    </section>
</div>
    <?php
}
