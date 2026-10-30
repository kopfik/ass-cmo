<?php
declare(strict_types=1);

function display_column_label(string $column): string {
    return match (strtolower($column)) {
        'upgrade_age_status' => 'UPG_AGE',
        'days_since_upgrade' => 'SINCE_UPG',
        default => $column,
    };
}

function display_cell_value(string $column, mixed $value): mixed {
    if (!is_string($value)) {
        return $value;
    }

    if (strtolower($column) === 'os_name') {
        return preg_replace('/\s+\(Debian GNU\/Linux \d+(?: \([^)]+\))?\)$/', '', $value) ?? $value;
    }

    return $value;
}

function visible_table_columns(array $rows, array $hiddenColumns): array {
    if ($rows === []) {
        return [];
    }

    return array_values(array_filter(
        array_keys($rows[0]),
        fn($column) => !in_array((string)$column, $hiddenColumns, true)
    ));
}

function render_table_tools(int $rowCount): void {
    ?>
            <div class="table-tools">
                <label class="filter-label" for="table-filter">Filter</label>
                <input id="table-filter" class="filter-input" type="search" placeholder="Type to filter rows..." autocomplete="off">
                <span id="table-row-count" class="row-count"><?= h($rowCount) ?> / <?= h($rowCount) ?> rows</span>
            </div>
    <?php
}

/**
 * Current ass-roads flag of a row: 'on' for "roads": true, 'off' for
 * "roads": false, 'auto' when the key is missing or not a boolean.
 */
function roads_toggle_state(array $row): string {
    $tags = json_decode((string)($row['tags'] ?? ''), true);
    $flag = is_array($tags) ? ($tags['roads'] ?? null) : null;

    if ($flag === true) {
        return 'on';
    }

    return $flag === false ? 'off' : 'auto';
}

function render_roads_toggle(array $row): string {
    $uid = (string)($row['uid'] ?? '');

    if ($uid === '') {
        return '<span class="muted">-</span>';
    }

    // In 'auto' the host is on ass-roads exactly when its notes hold a web link,
    // the same rule as the roads_hosts view. Shown as an outline on ON.
    $linked = notes_app_links((string)($row['notes'] ?? '')) !== [];

    // The ON/OFF labels come from CSS, so they stay out of the row text that the
    // table filter searches.
    return '<span class="roads-toggle" data-uid="' . h($uid) . '" data-state="' . h(roads_toggle_state($row)) . '"'
        . ($linked ? ' data-linked="1"' : '') . '>'
        . '<button type="button" class="roads-toggle-on" data-roads-set="on" aria-label="ON" title="Always list on ass-roads"></button>'
        . '<button type="button" class="roads-toggle-off" data-roads-set="off" aria-label="OFF" title="Never list on ass-roads"></button>'
        . '</span>';
}

function render_dashboard_table(array $rows, array $columns, array $ctx, string $groupBy = '', string $roadsCsrfToken = ''): void {
    // The group value travels as a row attribute rather than being read back out
    // of a rendered cell, so the grouping column does not have to be displayed.
    $grouped = $groupBy !== '' && $rows !== [] && array_key_exists($groupBy, $rows[0]);

    // A CSRF token is passed only for views with "-- roads-toggle: yes".
    $roadsToggle = $roadsCsrfToken !== '';

    ?>
            <div class="table-wrap">
                <table id="dashboard-table"<?= $grouped ? ' data-grouped="1"' : '' ?><?= $roadsToggle ? ' data-roads-csrf="' . h($roadsCsrfToken) . '"' : '' ?>>
                    <thead>
                    <tr>
                        <th class="actions-col" data-sortable="0">Actions</th>
                        <?php foreach ($columns as $col): ?>
                            <th class="sortable" data-sortable="1"><?= h(display_column_label((string)$col)) ?></th>
                        <?php endforeach; ?>
                        <?php if ($roadsToggle): ?>
                            <th class="roads-col" data-sortable="0">Roads</th>
                        <?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr<?= $grouped ? ' data-group="' . h((string)($row[$groupBy] ?? '')) . '"' : '' ?>>
                            <td class="actions-col"><?= render_action_buttons($row, $ctx) ?></td>
                            <?php foreach ($columns as $col): ?>
                                <td><?= h(display_cell_value((string)$col, $row[$col] ?? '')) ?></td>
                            <?php endforeach; ?>
                            <?php if ($roadsToggle): ?>
                                <td class="roads-col"><?= render_roads_toggle($row) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
    <?php
}
