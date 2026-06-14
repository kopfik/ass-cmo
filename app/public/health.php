<?php
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'status' => 'ok',
    'app' => 'ass-cmo',
    'instance' => getenv('ASSCMO_INSTANCE_NAME') ?: 'ASS CMO',
    'time' => gmdate('c')
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

echo "\n";
