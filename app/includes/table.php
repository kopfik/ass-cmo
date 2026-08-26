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

function render_dashboard_table(array $rows, array $columns, array $ctx, string $groupBy = '', bool $groupActions = false): void {
    // The group value travels as a row attribute rather than being read back out
    // of a rendered cell, so the grouping column does not have to be displayed.
    $grouped = $groupBy !== '' && $rows !== [] && array_key_exists($groupBy, $rows[0]);

    ?>
            <div class="table-wrap">
                <table id="dashboard-table"<?= $grouped ? ' data-grouped="1"' : '' ?><?= $grouped && $groupActions ? ' data-group-actions="1"' : '' ?>>
                    <thead>
                    <tr>
                        <th class="actions-col" data-sortable="0">Actions</th>
                        <?php foreach ($columns as $col): ?>
                            <th class="sortable" data-sortable="1"><?= h(display_column_label((string)$col)) ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr<?= $grouped ? ' data-group="' . h((string)($row[$groupBy] ?? '')) . '"' : '' ?>>
                            <td class="actions-col"><?= render_action_buttons($row, $ctx) ?></td>
                            <?php foreach ($columns as $col): ?>
                                <td><?= h(display_cell_value((string)$col, $row[$col] ?? '')) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
    <?php
}
