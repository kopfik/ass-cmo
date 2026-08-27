(function () {
    function isCustomProtocol(href) {
        return href.startsWith('assssh://') || href.startsWith('assrdp://') || href.startsWith('assweb://');
    }

    // UI-only detection of PWA/standalone display. Not a security signal — it only
    // decides whether web actions route through assweb:// or open HTTP(S) directly.
    function isPwaStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches
            || window.matchMedia('(display-mode: fullscreen)').matches
            || window.matchMedia('(display-mode: minimal-ui)').matches
            || window.navigator.standalone === true;
    }

    function openWebAction(url) {
        if (typeof url !== 'string' || !/^https?:\/\//i.test(url)) {
            return;
        }

        window.open(url, '_blank', 'noopener,noreferrer');
    }

    function openCustomProtocol(href) {
        const iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        iframe.src = href;
        document.body.appendChild(iframe);

        window.setTimeout(() => {
            iframe.remove();
        }, 1500);
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
        return Promise.resolve();
    }

    document.addEventListener('click', event => {
        const copyButton = event.target.closest('[data-copy-command]');
        if (copyButton) {
            event.preventDefault();

            const copyLabel = copyButton.querySelector('.copy-command-button');
            const originalText = copyLabel ? copyLabel.textContent : copyButton.textContent;

            copyText(copyButton.dataset.copyCommand || '').then(() => {
                if (copyLabel) {
                    copyLabel.textContent = 'Copied';
                } else {
                    copyButton.textContent = 'Copied';
                }

                window.setTimeout(() => {
                    if (copyLabel) {
                        copyLabel.textContent = originalText;
                    } else {
                        copyButton.textContent = originalText;
                    }
                }, 1200);
            });
            return;
        }

        const link = event.target.closest('a[href^="assssh://"], a[href^="assrdp://"], a[href^="assweb://"]');
        if (!link) {
            return;
        }

        // Web actions: in a normal browser tab open the raw HTTP(S) target directly
        // (faster, no OS protocol round-trip). Keep assweb:// only in PWA/standalone
        // mode. SSH/RDP always use their custom protocol.
        const webUrl = link.dataset.webUrl;
        if (link.href.startsWith('assweb://') && webUrl && !isPwaStandalone()) {
            event.preventDefault();
            openWebAction(webUrl);
            return;
        }

        event.preventDefault();
        openCustomProtocol(link.href);
    });

    const aboutModal = document.getElementById('about-modal');

    function openAboutModal() {
        if (!aboutModal) {
            return;
        }

        aboutModal.hidden = false;
        document.body.classList.add('modal-open');

        const closeButton = aboutModal.querySelector('[data-about-close]');
        if (closeButton) {
            closeButton.focus();
        }
    }

    function closeAboutModal() {
        if (!aboutModal) {
            return;
        }

        aboutModal.hidden = true;
        document.body.classList.remove('modal-open');
    }

    document.addEventListener('click', event => {
        if (event.target.closest('[data-about-open]')) {
            event.preventDefault();
            openAboutModal();
            return;
        }

        if (event.target.closest('[data-about-close]')) {
            event.preventDefault();
            closeAboutModal();
        }
    });

    // ── Narrator / "Not boring" toggle ──────────────────────────────────────

    const NARRATOR_KEY = 'asscmo.notBoring';

    function isNotBoring() {
        const stored = window.localStorage.getItem(NARRATOR_KEY);
        return stored === null ? true : stored !== 'false';
    }

    function applyNotBoring(enabled) {
        document.documentElement.dataset.notBoring = enabled ? '1' : '0';

        const toggle = document.getElementById('narrator-toggle');
        if (toggle) {
            toggle.checked = enabled;
        }

        const hint = document.getElementById('narrator-hint');
        if (hint) {
            hint.textContent = enabled
                ? 'Adds harmless dry comments to quiet corners of the UI. Does not affect security decisions, scheduling, inventory, or reality. You may now start searching for the easter eggs that may or may not exist. Ten points to the house of your choice for each one you find.'
                : 'The interface will now pretend to be a corporate application.';
        }
    }

    applyNotBoring(isNotBoring());

    document.addEventListener('change', event => {
        const toggle = event.target.closest('#narrator-toggle');
        if (!toggle) {
            return;
        }

        const enabled = toggle.checked;
        window.localStorage.setItem(NARRATOR_KEY, enabled ? 'true' : 'false');

        if (enabled) {
            const hint = document.getElementById('narrator-hint');
            if (hint) {
                hint.textContent = 'The interface has resumed having opinions...';
            }
            window.setTimeout(() => { applyNotBoring(true); }, 3000);
        } else {
            applyNotBoring(false);
        }
    });

    // ────────────────────────────────────────────────────────────────────────

    const table = document.getElementById('dashboard-table');
    const filter = document.getElementById('table-filter');
    const rowCount = document.getElementById('table-row-count');
    const launcher = document.getElementById('command-launcher');
    const launcherInput = document.getElementById('command-launcher-input');
    const launcherResults = document.getElementById('command-launcher-results');

    // ── Agent-auth filter ─────────────────────────────────────────────────────

    const agentAuthFilter = document.getElementById('agent-auth-filter');
    const agentAuthCountEl = document.getElementById('agent-auth-count');
    const agentAuthList = document.getElementById('agent-auth-list');

    function filterCmd(raw) {
        const v = raw.trim().toLowerCase();
        if (v === 'all') return 'all';
        if (v === 'date') return 'date';
        return v;
    }

    function isInteractiveEl(el) {
        if (!el || el.nodeType !== 1) return false;
        const tag = el.tagName.toLowerCase();
        if (['button', 'a', 'input', 'textarea', 'select', 'label', 'form', 'summary'].includes(tag)) return true;
        if (el.getAttribute('role') === 'button') return true;
        const ti = el.getAttribute('tabindex');
        return ti !== null && ti !== '-1';
    }

    function hasInteractiveAncestor(el, stopAt) {
        let node = el;
        while (node && node !== stopAt) {
            if (isInteractiveEl(node)) return true;
            node = node.parentElement;
        }
        return false;
    }

    function setAgentAuthCardVisible(card, visible) {
        card.hidden = !visible;
        card.style.display = visible ? '' : 'none';
    }

    function applyAgentAuthFilter() {
        if (!agentAuthList) return;
        const cards = Array.from(agentAuthList.querySelectorAll('[data-agent-auth-card]'));
        const cmd = filterCmd(agentAuthFilter ? agentAuthFilter.value : '');
        const minChars = Number(agentAuthList.dataset.minChars || 3);

        if (cmd === 'all') {
            cards.sort((a, b) =>
                (a.dataset.authLabel || '').localeCompare(b.dataset.authLabel || '', undefined, { sensitivity: 'base' })
            );
            cards.forEach(c => agentAuthList.appendChild(c));
            cards.forEach(c => setAgentAuthCardVisible(c, true));
        } else if (cmd === 'date') {
            cards.sort((a, b) => {
                const da = a.dataset.authCreated || '';
                const db = b.dataset.authCreated || '';
                if (da > db) return -1;
                if (da < db) return 1;
                return 0;
            });
            cards.forEach(c => agentAuthList.appendChild(c));
            cards.forEach(c => setAgentAuthCardVisible(c, true));
        } else if (cmd.length >= minChars) {
            for (const card of cards) {
                const text = (card.textContent || '').toLowerCase();
                setAgentAuthCardVisible(card, text.includes(cmd));
            }
        } else {
            for (const card of cards) {
                setAgentAuthCardVisible(card, false);
            }
        }

        if (agentAuthCountEl) {
            const visible = cards.filter(c => !c.hidden).length;
            agentAuthCountEl.textContent = visible + ' / ' + cards.length;
        }
    }

    if (agentAuthFilter) {
        agentAuthFilter.addEventListener('input', applyAgentAuthFilter);
        applyAgentAuthFilter();
    }

    if (agentAuthList) {
        agentAuthList.addEventListener('click', event => {
            const card = event.target.closest('[data-agent-auth-card]');
            if (!card) return;
            if (hasInteractiveAncestor(event.target, card)) return;
            if (agentAuthFilter) agentAuthFilter.focus();
        });
    }

    // ── Auto-focus view filter on page load ───────────────────────────────────

    if (agentAuthFilter) {
        agentAuthFilter.focus();
    } else if (filter) {
        filter.focus();
    }

    if (!table) {
        return;
    }

    let launcherCommands = [];
    let launcherSelectedIndex = 0;

    function normalizeValue(value) {
        return value.trim().toLowerCase();
    }

    function cellText(row, index) {
        return (row.children[index]?.textContent || '').trim();
    }

    function launcherActionPriority(type) {
        if (['pve', 'pbs', 'pdm', 'pmg', 'omv'].includes(type)) {
            return 100;
        }

        if (type === 'shell') {
            return 80;
        }

        if (type === 'rdp') {
            return 40;
        }

        if (type === 'ssh') {
            return 20;
        }

        return 50;
    }

    function buildLauncherCommands() {
        const headers = Array.from(table.querySelectorAll('thead th')).map(header => normalizeValue(header.textContent || ''));
        const hostnameIndex = headers.findIndex(header => header === 'hostname' || header === 'host');
        const ipIndex = headers.findIndex(header => header === 'ip' || header.includes('ipv4'));

        launcherCommands = [];

        for (const row of table.querySelectorAll('tbody tr')) {
            const rowText = row.textContent || '';
            const hostname = hostnameIndex >= 0 ? cellText(row, hostnameIndex) : cellText(row, 1);
            const ip = ipIndex >= 0 ? cellText(row, ipIndex) : '';
            const actions = Array.from(row.querySelectorAll('.actions-col a.action'));

            for (const action of actions) {
                const actionLabel = (action.textContent || '').trim();
                const href = action.getAttribute('href') || '';
                const type = normalizeValue(actionLabel);
                const title = hostname || actionLabel;
                const actionClass = Array.from(action.classList).find(name => name.startsWith('action-') && name !== 'action') || '';

                launcherCommands.push({
                    title,
                    label: actionLabel,
                    subtitle: [ip, href].filter(Boolean).join(' · '),
                    href,
                    webUrl: action.dataset.webUrl || '',
                    type,
                    actionClass,
                    priority: launcherActionPriority(type),
                    keywords: normalizeValue([actionLabel, hostname, ip, href, rowText].join(' '))
                });
            }
        }
    }

    function launcherScore(command, query) {
        if (query === '') {
            return 1;
        }

        const title = normalizeValue(command.title);
        const type = normalizeValue(command.type);
        const keywords = command.keywords;

        if (title === query) {
            return 1000;
        }

        if (title.startsWith(query)) {
            return 800;
        }

        if (keywords.includes(query)) {
            let score = 200;

            for (const part of query.split(/\s+/).filter(Boolean)) {
                if (type === part) {
                    score += 120;
                } else if (title.includes(part)) {
                    score += 80;
                } else if (keywords.includes(part)) {
                    score += 30;
                } else {
                    return 0;
                }
            }

            return score;
        }

        return 0;
    }

    function launcherMatches(query) {
        return launcherCommands
            .map(command => ({ command, score: launcherScore(command, query) }))
            .filter(item => item.score > 0)
            .sort((a, b) => b.score - a.score || b.command.priority - a.command.priority || a.command.title.localeCompare(b.command.title) || a.command.type.localeCompare(b.command.type))
            .slice(0, 12)
            .map(item => item.command);
    }

    function renderLauncherResults() {
        if (!launcherResults || !launcherInput) {
            return;
        }

        const query = normalizeValue(launcherInput.value);
        const matches = launcherMatches(query);
        launcherSelectedIndex = Math.min(launcherSelectedIndex, Math.max(matches.length - 1, 0));
        launcherResults.innerHTML = '';

        if (matches.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'command-launcher-empty';
            empty.textContent = 'No matching actions';
            launcherResults.appendChild(empty);
            return;
        }

        matches.forEach((command, index) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'command-launcher-item' + (index === launcherSelectedIndex ? ' selected' : '');
            item.dataset.index = String(index);

            const title = document.createElement('div');
            title.className = 'command-launcher-item-title';

            const badge = document.createElement('span');
            badge.className = ['command-launcher-badge', command.actionClass].filter(Boolean).join(' ');
            badge.textContent = command.label || command.type || 'ACTION';

            const titleText = document.createElement('span');
            titleText.className = 'command-launcher-title-text';
            titleText.textContent = command.title;

            title.appendChild(badge);
            title.appendChild(titleText);

            const subtitle = document.createElement('div');
            subtitle.className = 'command-launcher-item-subtitle';
            subtitle.textContent = command.subtitle;

            item.appendChild(title);
            item.appendChild(subtitle);
            item.addEventListener('click', () => runLauncherCommand(command));
            launcherResults.appendChild(item);
        });
    }

    function openLauncher() {
        if (!launcher || !launcherInput) {
            return;
        }

        buildLauncherCommands();
        launcher.hidden = false;
        launcherSelectedIndex = 0;
        launcherInput.value = '';
        renderLauncherResults();
        launcherInput.focus();
    }

    function closeLauncher() {
        if (!launcher) {
            return;
        }

        launcher.hidden = true;
    }

    function currentLauncherCommand() {
        if (!launcherInput) {
            return null;
        }

        return launcherMatches(normalizeValue(launcherInput.value))[launcherSelectedIndex] || null;
    }

    function runLauncherCommand(command) {
        if (!command || !command.href) {
            return;
        }

        closeLauncher();

        // Web actions open the raw HTTP(S) target directly outside PWA/standalone mode.
        if (command.href.startsWith('assweb://') && command.webUrl && !isPwaStandalone()) {
            openWebAction(command.webUrl);
            return;
        }

        if (isCustomProtocol(command.href)) {
            openCustomProtocol(command.href);
            return;
        }

        openWebAction(command.href);
    }

    document.addEventListener('click', event => {
        if (event.target.closest('[data-launcher-close]')) {
            closeLauncher();
        }
    });

    document.addEventListener('keydown', event => {
        const isLauncherShortcut = (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k';

        if (event.key === 'Escape' && aboutModal && !aboutModal.hidden) {
            event.preventDefault();
            closeAboutModal();
            return;
        }

        if (isLauncherShortcut) {
            event.preventDefault();
            openLauncher();
            return;
        }

        if (!launcher || launcher.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeLauncher();
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            launcherSelectedIndex += 1;
            renderLauncherResults();
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            launcherSelectedIndex = Math.max(launcherSelectedIndex - 1, 0);
            renderLauncherResults();
            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            runLauncherCommand(currentLauncherCommand());
        }
    });

    if (launcherInput) {
        launcherInput.addEventListener('input', () => {
            launcherSelectedIndex = 0;
            renderLauncherResults();
        });
    }

    const tbody = table.querySelector('tbody');
    const headers = Array.from(table.querySelectorAll('thead th'));
    let sortState = { index: null, direction: 1 };

    // Views can opt into collapsible groups with a "-- group-by: column" header.
    // Without it, isGrouped stays false and every function below behaves exactly
    // as it did before grouping existed.
    const isGrouped = table.dataset.grouped === '1';
    const collapsedGroups = new Set();
    // Decided once from the data, see detectHoistedActions().
    let hoistActions = false;

    function dataRows() {
        return Array.from(tbody.querySelectorAll('tr')).filter(row => !row.classList.contains('group-row'));
    }

    function groupValue(row) {
        return row.dataset.group || '';
    }

    function actionsMarkup(row) {
        return (row.querySelector('.actions-col')?.innerHTML || '').trim();
    }

    /**
     * Decides whether the row actions belong on the group header instead of on
     * every row, without the view having to say so.
     *
     * Grouping by host puts rows that all target that one host in a group, so
     * their action buttons are identical and repeating them on each row is noise.
     * Grouping by something like a network segment puts several different hosts
     * in one group, and each row then needs its own buttons.
     *
     * Identical actions across every multi-row group is exactly that difference.
     * Groups of one carry no evidence either way, so they are skipped for the
     * decision but still follow it, which keeps one view looking consistent.
     */
    function detectHoistedActions() {
        const groups = new Map();

        for (const row of dataRows()) {
            const value = groupValue(row);
            if (!groups.has(value)) {
                groups.set(value, []);
            }
            groups.get(value).push(row);
        }

        let decided = false;

        for (const rows of groups.values()) {
            if (rows.length < 2) {
                continue;
            }

            decided = true;
            const first = actionsMarkup(rows[0]);

            if (rows.some(row => actionsMarkup(row) !== first)) {
                return false;
            }
        }

        return decided;
    }

    function visibleRows() {
        return dataRows().filter(row => row.style.display !== 'none');
    }

    function updateRowCount() {
        if (!rowCount) {
            return;
        }

        const total = dataRows().length;
        const visible = visibleRows().length;
        rowCount.textContent = visible + ' / ' + total + ' rows';
    }

    function numericValue(value) {
        const cleaned = value.replace(/[^0-9.,-]/g, '').replace(',', '.');
        if (cleaned === '' || cleaned === '-' || cleaned === '.') {
            return null;
        }

        const number = Number(cleaned);
        return Number.isFinite(number) ? number : null;
    }

    function compareValues(a, b) {
        const an = numericValue(a);
        const bn = numericValue(b);

        if (an !== null && bn !== null) {
            return an - bn;
        }

        return normalizeValue(a).localeCompare(normalizeValue(b), undefined, {
            numeric: true,
            sensitivity: 'base'
        });
    }

    function buildGroupRows() {
        for (const row of Array.from(tbody.querySelectorAll('tr.group-row'))) {
            row.remove();
        }

        let currentValue = null;

        for (const row of dataRows()) {
            const value = groupValue(row);
            row.dataset.group = value;

            if (value === currentValue) {
                continue;
            }

            currentValue = value;

            const groupRow = document.createElement('tr');
            groupRow.className = 'group-row';
            groupRow.dataset.group = value;

            // Mirrors the data rows so the sticky Actions column keeps lining up.
            const actionsCell = document.createElement('td');
            actionsCell.className = 'actions-col';

            if (hoistActions) {
                // Copied, never moved: this runs again after every sort, and the row
                // that happens to be first changes. The data rows keep their own
                // buttons in the DOM and CSS hides them instead.
                actionsCell.innerHTML = actionsMarkup(row);
            }

            groupRow.appendChild(actionsCell);

            const cell = document.createElement('td');
            cell.colSpan = Math.max(headers.length - 1, 1);

            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'group-toggle';
            toggle.textContent = value === '' ? '(none)' : value;
            cell.appendChild(toggle);

            const count = document.createElement('span');
            count.className = 'group-count';
            cell.appendChild(count);

            groupRow.appendChild(cell);
            tbody.insertBefore(groupRow, row);
        }
    }

    function updateGroupVisibility() {
        const members = new Map();

        for (const row of dataRows()) {
            const value = row.dataset.group || '';
            if (!members.has(value)) {
                members.set(value, []);
            }
            members.get(value).push(row);
        }

        for (const groupRow of tbody.querySelectorAll('tr.group-row')) {
            const value = groupRow.dataset.group || '';
            const collapsed = collapsedGroups.has(value);
            const matching = (members.get(value) || []).filter(row => row.style.display !== 'none');

            if (collapsed) {
                for (const row of matching) {
                    row.style.display = 'none';
                }
            }

            groupRow.style.display = matching.length === 0 ? 'none' : '';
            groupRow.classList.toggle('is-collapsed', collapsed);

            const count = groupRow.querySelector('.group-count');
            if (count) {
                count.textContent = matching.length === 1 ? '1 row' : matching.length + ' rows';
            }
        }
    }

    function applyFilter() {
        const query = filter ? normalizeValue(filter.value) : '';

        for (const row of dataRows()) {
            const text = normalizeValue(row.textContent || '');
            row.style.display = query === '' || text.includes(query) ? '' : 'none';
        }

        // Counted before collapsing, so folding a group does not look like the
        // filter removed rows.
        updateRowCount();

        if (isGrouped) {
            updateGroupVisibility();
        }
    }

    function clearSortMarkers() {
        for (const header of headers) {
            header.classList.remove('sort-asc', 'sort-desc');
        }
    }

    function sortByColumn(index) {
        const rows = dataRows();

        if (sortState.index === index) {
            sortState.direction *= -1;
        } else {
            sortState.index = index;
            sortState.direction = 1;
        }

        rows.sort((rowA, rowB) => {
            // Sorting must not tear groups apart, so the group value always wins
            // and the clicked column only orders rows inside a group.
            if (isGrouped) {
                const groupCompare = compareValues(groupValue(rowA), groupValue(rowB));
                if (groupCompare !== 0) {
                    return groupCompare;
                }
            }

            const a = rowA.children[index]?.textContent || '';
            const b = rowB.children[index]?.textContent || '';
            return compareValues(a, b) * sortState.direction;
        });

        for (const row of rows) {
            tbody.appendChild(row);
        }

        if (isGrouped) {
            buildGroupRows();
        }

        clearSortMarkers();
        headers[index].classList.add(sortState.direction === 1 ? 'sort-asc' : 'sort-desc');
        applyFilter();
    }

    headers.forEach((header, index) => {
        if (header.dataset.sortable !== '1') {
            return;
        }

        header.addEventListener('click', () => sortByColumn(index));
        header.title = 'Click to sort';
    });

    if (filter) {
        filter.addEventListener('input', applyFilter);
    }

    if (isGrouped) {
        tbody.addEventListener('click', event => {
            // Restricted to the toggle itself: with hoisted actions the header row
            // also carries links and buttons that must not fold the group.
            const toggle = event.target.closest('.group-toggle');
            if (!toggle) {
                return;
            }

            const groupRow = toggle.closest('tr.group-row');
            if (!groupRow) {
                return;
            }

            const value = groupRow.dataset.group || '';

            if (collapsedGroups.has(value)) {
                collapsedGroups.delete(value);
            } else {
                collapsedGroups.add(value);
            }

            // Re-runs the filter first, so un-collapsing restores exactly the rows
            // the current filter allows.
            applyFilter();
        });

        // Rows arrive in whatever order the view's SQL produced. Sorting by the
        // group value first guarantees each group is contiguous.
        const initial = dataRows();
        initial.sort((rowA, rowB) => compareValues(groupValue(rowA), groupValue(rowB)));
        for (const row of initial) {
            tbody.appendChild(row);
        }

        hoistActions = detectHoistedActions();
        table.classList.toggle('has-group-actions', hoistActions);

        buildGroupRows();
    }

    applyFilter();
})();
