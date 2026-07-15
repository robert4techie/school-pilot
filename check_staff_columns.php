<?php
/**
 * check_staff_columns.php
 * Drop this file one level above /api/ (same folder as view_staff.php).
 * Open it in your browser once, then DELETE it immediately after.
 */
require_once 'conn.php';

$required = [
    'date_of_birth'  => "DATE          NULL",
    'marital_status' => "VARCHAR(20)   NULL",
    'nationality'    => "VARCHAR(100)  NULL",
    'address'        => "TEXT          NULL",
    'nin'            => "VARCHAR(50)   NULL",
    'tin'            => "VARCHAR(50)   NULL",
    'nssf'           => "VARCHAR(50)   NULL",
];

// Fetch existing columns from the staff table
$existing = [];
$res = $conn->query("SHOW COLUMNS FROM staff");
while ($row = $res->fetch_assoc()) {
    $existing[] = strtolower($row['Field']);
}

$missing = array_filter($required, fn($_, $col) => !in_array(strtolower($col), $existing), ARRAY_FILTER_USE_BOTH);

echo "<pre style='font-family:monospace;font-size:14px;line-height:1.7'>";

if (empty($missing)) {
    echo "✅  All required columns already exist. No ALTER TABLE needed.\n";
    echo "    The 500 error is caused by something else — check your PHP error log.\n";
} else {
    echo "⚠️  Missing columns found. Run this SQL on your database:\n\n";
    echo "ALTER TABLE staff\n";
    $lines = [];
    foreach ($missing as $col => $def) {
        $lines[] = "    ADD COLUMN `{$col}` {$def}";
    }
    echo implode(",\n", $lines) . ";\n\n";
    echo "---\n";
    echo "Columns present:  " . implode(', ', $existing) . "\n";
    echo "Columns missing:  " . implode(', ', array_keys($missing)) . "\n";
}

echo "</pre>";
$conn->close();
