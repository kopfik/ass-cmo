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
                <p><strong>ASS-CMO</strong> stands for <strong>Admins Secure Server Connection Manager &amp; Overview</strong>.</p>
                <p>It is a small inventory, overview and connection dashboard for administrators who manage Linux servers, Windows servers, virtual machines and appliances such as Proxmox VE, PBS, PMG, PDM or OpenMediaVault. It shows what machines exist, where they are, when they were last seen, what they run, whether they need attention, and how to open the right local admin tool quickly.</p>
                <p>ASS-CMO is intentionally <strong>not a full RMM</strong> and not a command-and-control platform: it does not run arbitrary remote commands from the dashboard.</p>
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
                <p>Adds harmless dry comments to quiet corners of the UI. It does not affect security, scheduling, inventory or data, and the preference stays local to this browser and is never sent to the server.</p>
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
                <p>These links are handled by scripts on the administrator’s workstation, not on the managed server. The dashboard never opens a remote shell itself — it asks the local computer to launch the preferred SSH client, RDP client or default browser.</p>
                <p>Handlers are optional but central to the daily workflow; without them, ASS-CMO is mostly an inventory and web-link dashboard.</p>
            </section>

            <section class="about-card">
                <h3>Notes syntax</h3>
                <p>The <code>notes</code> field holds manual administrator notes and custom action links. Lines in this format become dashboard action buttons:</p>
                <pre><code>LABEL: https://example.local/</code></pre>
                <p>Examples:</p>
                <pre><code>GITEA: https://gitea.example.local/
OMV: https://nas.example.local/
UPDATE: https://router.example.local/updates
shell: https://pve.example.local:8006/#v1:0:=node%2Fpve:4:=jsconsole::::::</code></pre>
                <p>Special labels such as <code>shell</code> and <code>UPDATE</code> get their own visual style.</p>
                <pre><code>ssh_user: root
ssh-user: root</code></pre>
                <p><code>ssh_user</code> (or <code>ssh-user</code>) overrides the SSH username for a specific host — useful when one appliance needs <code>root</code> while most systems use your local username.</p>
                <p><code>notes</code> are a manual admin layer; they are not used for automatic agent state or machine health.</p>
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
                <p>Agents collect inventory with native tools — Bash on Linux, PowerShell on Windows — and send JSON to the server, which stores it in PostgreSQL. The database holds the latest known state of each machine; it is not a historical time-series system.</p>
            </section>

            <section class="about-card">
                <h3>Inventory model</h3>
                <p>ASS-CMO focuses on the <strong>latest known state</strong> of each machine. When a host reports new data, its current record is updated. Long-term history, metrics and alerting may come later, but they are not part of the current core.</p>
            </section>

            <section class="about-card">
                <h3>Dashboard views</h3>
                <p>Dashboard views are ordinary read-only SQL files in <code>config.local/dashboard-views/</code>. Each view may include metadata comments:</p>
                <pre><code>-- label: Linux overview
-- description: Linux hosts grouped by update and reboot state.</code></pre>
                <p>Only simple read-only <code>SELECT</code> or <code>WITH</code> queries are allowed — views display data, they do not modify it. A view may select the <code>notes</code> column even when it is hidden as a table column, using it to generate custom action buttons.</p>
            </section>

            <section class="about-card">
                <h3>What ASS-CMO is not</h3>
                <ul>
                    <li>not a replacement for monitoring, logging or alerting,</li>
                    <li>not a full RMM,</li>
                    <li>not a way to run arbitrary commands on machines from a central web interface,</li>
                    <li>not a command-and-control center.</li>
                </ul>
            </section>

            <section class="about-card">
                <h3>Why not just PuTTY, RDM or a full RMM?</h3>
                <p>Classic connection managers store targets well but usually do not know what is installed, when a host last reported, what it runs or whether it needs attention. Full RMM tools are powerful but add a much larger surface: remote command execution, policy engines, script deployment and broad agent privileges.</p>
                <p>ASS-CMO sits in the middle — more aware than a static connection manager, simpler and safer than a full RMM, focused on inventory, overview and local admin actions for administrators who still choose what they open and when.</p>
            </section>

            <section class="about-card">
                <h3>Security model</h3>
                <p>ASS-CMO keeps a small security surface: HTTPS transport, enrolled agents, local agent configuration, and a dashboard that does not expose remote command execution. The dashboard is read-oriented — it shows inventory and opens local admin tools rather than acting as a command-and-control interface.</p>
                <p>Agent configuration and host-specific credentials are runtime configuration and must not be overwritten by application or agent-bundle updates. Future hardening may include signed agent bundles and checksums.</p>
            </section>

            <section class="about-card">
                <h3>Project scope</h3>
                <p>ASS-CMO is in active development and does not try to be a universal, out-of-the-box product for every environment. Its direction is driven by concrete real-world administration needs, so some choices are opinionated and some workflows are intentionally simple. Public releases are practical, reviewed snapshots of that work.</p>
            </section>
        </div>
    </section>
</div>
    <?php
}
