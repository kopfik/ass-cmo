(function () {
    function isCustomProtocol(href) {
        return href.startsWith('assssh://') || href.startsWith('assrdp://') || href.startsWith('assweb://');
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

        if (isCustomProtocol(command.href)) {
            openCustomProtocol(command.href);
            return;
        }

        window.open(command.href, '_blank', 'noopener,noreferrer');
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

    function visibleRows() {
        return Array.from(tbody.querySelectorAll('tr')).filter(row => row.style.display !== 'none');
    }

    function updateRowCount() {
        if (!rowCount) {
            return;
        }

        const total = tbody.querySelectorAll('tr').length;
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

    function applyFilter() {
        if (!filter) {
            updateRowCount();
            return;
        }

        const query = normalizeValue(filter.value);

        for (const row of tbody.querySelectorAll('tr')) {
            const text = normalizeValue(row.textContent || '');
            row.style.display = query === '' || text.includes(query) ? '' : 'none';
        }

        updateRowCount();
    }

    function clearSortMarkers() {
        for (const header of headers) {
            header.classList.remove('sort-asc', 'sort-desc');
        }
    }

    function sortByColumn(index) {
        const rows = Array.from(tbody.querySelectorAll('tr'));

        if (sortState.index === index) {
            sortState.direction *= -1;
        } else {
            sortState.index = index;
            sortState.direction = 1;
        }

        rows.sort((rowA, rowB) => {
            const a = rowA.children[index]?.textContent || '';
            const b = rowB.children[index]?.textContent || '';
            return compareValues(a, b) * sortState.direction;
        });

        for (const row of rows) {
            tbody.appendChild(row);
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

    updateRowCount();
})();
