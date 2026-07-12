<?php
$files = ['src/Controller/SubmissionViewController.php', 'src/Render/AdminFormsRenderer.php'];
foreach ($files as $file) {
    $content = file_get_contents($file);
    // Fix: <?php\n        <?= → <?=
    $content = str_replace('<?php ' . "\n" . '        <?=', '<?=', $content);
    $content = str_replace('<?php' . "\n" . '        <?=', '<?=', $content);
    // Fix: ?>\n        ?> → ?>
    $content = str_replace('?>' . "\n" . '        ?>', '?>', $content);
    // Fix: ?>\n      ?> → ?>
    $content = str_replace('?>' . "\n" . '      ?>', '?>', $content);
    file_put_contents($file, $content);
    echo "Fixed: $file\n";
}
