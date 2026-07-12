<?php
$content = file_get_contents('src/Token/TokenService.php');

$old = '$relanceMax = (int) $this->settingsService->get(\'relance_max\', \'3\');

        $submission = [';

$new = '$relanceMax = (int) $this->settingsService->get(\'relance_max\', \'3\');

        if ($newCount > $relanceMax) {
            return [\'success\' => false, \'message\' => \'Maximum de rappels atteint (\' . $relanceMax . \').\'];
        }

        $submission = [';

$content = str_replace($old, $new, $content);
file_put_contents('src/Token/TokenService.php', $content);
echo "Fixed TokenService relance_max\n";
