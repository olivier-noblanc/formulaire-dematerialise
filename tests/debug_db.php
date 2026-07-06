<?php
$pdo = new PDO("sqlite:/workspace/formulaire-dematerialise/db/workflow_test.db");
echo "=== Tables ===\n";
$result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
while ($row = $result->fetch()) echo "  " . $row[0] . "\n";

echo "\n=== form_fields columns ===\n";
$result = $pdo->query("PRAGMA table_info(form_fields)");
while ($row = $result->fetch()) echo "  " . $row[1] . " (" . $row[2] . ")\n";

echo "\n=== form_fields sample (filled_by, validator_step) ===\n";
$result = $pdo->query("SELECT field_name, filled_by, validator_step FROM form_fields LIMIT 3");
while ($row = $result->fetch()) echo "  {$row['field_name']} | filled_by={$row['filled_by']} | step={$row['validator_step']}\n";
echo "\n=== Count by filled_by ===\n";
$result = $pdo->query("SELECT filled_by, COUNT(*) as cnt FROM form_fields GROUP BY filled_by");
while ($row = $result->fetch()) echo "  {$row['filled_by']}: {$row['cnt']}\n";

echo "\n=== Does submission_validator_data exist? ===\n";
$result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='submission_validator_data'");
echo "  " . ($result->fetch() ? "YES" : "NO") . "\n";
