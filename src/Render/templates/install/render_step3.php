<?php
/**
 * @var array<string, mixed>|null $confirm_config
 * @var string $install_dir
 */
?>
    <!-- ════════════════════════════════════════════════════════════
         ÉTAPE 3 : Confirmation et installation
         ════════════════════════════════════════════════════════════ -->

    <?php if ($confirm_config === null): ?>
    <div class="msg-error" role="alert" aria-live="assertive">
        Aucune configuration trouvée en session. Veuillez recommencer depuis l'étape 1.
    </div>
    <a href="install.php?step=1" class="btn btn-primary">← Recommencer</a>

    <?php else: ?>
    <div class="card">
        <h2>Étape 3 — Confirmation de l'installation</h2>
        <p class="caption-2">
            Vérifiez la configuration ci-dessous. Si tout est correct, cliquez sur « Installer » pour créer le fichier
            <strong>config.php</strong> et lancer l'application.
        </p>

        <div class="config-preview"><?php
            $config_lines = [
                'BASE_URL'       => $confirm_config['base_url'],
                'DEFAULT_DB_PATH' => '__DIR__ . \'/db/workflow.db\'',
                'DB_PATH'        => 'DEFAULT_DB_PATH',
                'SETTINGS_DEFAULTS → smtp_host'        => $confirm_config['smtp_host'],
                'SETTINGS_DEFAULTS → smtp_port'        => (string) $confirm_config['smtp_port'],
                'SETTINGS_DEFAULTS → smtp_from'        => $confirm_config['smtp_from'],
                'SETTINGS_DEFAULTS → smtp_from_name'   => $confirm_config['smtp_from_name'],
                'SETTINGS_DEFAULTS → admin_email'      => $confirm_config['admin_email'],
            ];
        foreach ($config_lines as $key => $val):
            echo '<span class="config-key">' . inst_h($key) . '</span> = <span class="config-val">' . inst_h($val) . "</span>\n";
        endforeach;
        ?></div>
    </div>

    <div class="warn-box">
        <span aria-hidden="true">⚠</span> En cliquant sur « Installer », le fichier <strong>config.php</strong> sera créé dans le répertoire
        <code><?= inst_h($install_dir) ?></code> et le répertoire <strong>db/</strong> sera créé si nécessaire.
        L'application sera alors accessible via <a href="index.php" class="u-col-fon-7">index.php</a>.
    </div>

    <form method="POST">
        <?= inst_csrf_field() ?>
        <input type="hidden" name="action" value="install">
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">✓ Installer</button>
            <a href="install.php?step=2" class="btn btn-secondary">← Modifier la configuration</a>
        </div>
    </form>

    <?php endif; ?>
