<?php
/**
 * @var array<string, mixed> $d
 */
$default_base_url       = $d['base_url'] ?? '';
$default_smtp_host      = $d['smtp_host'] ?? '';
$default_smtp_port      = $d['smtp_port'] ?? 25;
$default_smtp_from      = $d['smtp_from'] ?? '';
$default_smtp_from_name = $d['smtp_from_name'] ?? '';
$default_admin_email    = $d['admin_email'] ?? '';
$default_delai_relance_h = $d['delai_relance_h'] ?? 48;
?>
    <!-- ════════════════════════════════════════════════════════════
         ÉTAPE 2 : Formulaire de configuration
         ════════════════════════════════════════════════════════════ -->

    <div class="card">
        <h2>Étape 2 — Configuration de l'application</h2>

        <form method="POST" id="config-form">
            <?= inst_csrf_field() ?>
            <input type="hidden" name="action" value="generate_config">

            <!-- Base URL -->
            <div class="field">
                <label>URL de base <span class="req">*</span> <span class="hint">(auto-détectée, modifiable)</span></label>
                <input type="text" name="base_url" value="<?= inst_h($default_base_url) ?>" placeholder="https://serveur.intra/workflow">
            </div>

            <!-- SMTP -->
            <div class="field">
                <label>Hôte SMTP <span class="req">*</span></label>
                <input type="text" name="smtp_host" value="<?= inst_h($default_smtp_host) ?>" placeholder="<?= inst_h(INST_DEFAULT_SMTP_HOST) ?>">
            </div>

            <div class="field">
                <label>Port SMTP <span class="req">*</span></label>
                <input type="number" name="smtp_port" value="<?= inst_h((string) $default_smtp_port) ?>" min="1" max="65535">
            </div>

            <div class="field">
                <label>Email expéditeur <span class="req">*</span></label>
                <input type="email" name="smtp_from" value="<?= inst_h($default_smtp_from) ?>" placeholder="<?= inst_h(INST_DEFAULT_SMTP_FROM) ?>">
            </div>

            <div class="field">
                <label>Nom de l'expéditeur <span class="req">*</span></label>
                <input type="text" name="smtp_from_name" value="<?= inst_h($default_smtp_from_name) ?>" placeholder="<?= inst_h(INST_DEFAULT_SMTP_FROM_NAME) ?>">
            </div>

            <!-- Admin -->
            <div class="field">
                <label>Email administrateur <span class="req">*</span> <span class="hint">(adresse de l'administrateur principal)</span></label>
                <input type="email" name="admin_email" value="<?= inst_h($default_admin_email) ?>" placeholder="prenom.nom@<?= inst_h(INST_DEFAULT_EMAIL_DOMAIN) ?>" required>
            </div>

            <!-- Délai -->
            <div class="field">
                <label>Délai de relance (heures) <span class="hint">(délai avant envoi d'une relance automatique)</span></label>
                <input type="number" name="delai_relance_h" value="<?= inst_h((string) $default_delai_relance_h) ?>" min="1">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Générer config.php →</button>
                <a href="install.php?step=1" class="btn btn-secondary">← Retour</a>
            </div>
        </form>
        </div>

    <!-- Test SMTP (formulaire séparé) -->
    <div class="card mt-15-2">
        <h2>Test d'envoi SMTP</h2>
        <p class="caption-2">
            Envoyer un email de test pour vérifier que la configuration SMTP est correcte avant de valider l'installation.
            L'email sera envoyé à l'adresse administrateur indiquée ci-dessus.
        </p>
        <form method="POST">
            <?= inst_csrf_field() ?>
            <input type="hidden" name="action" value="test_smtp">
            <input type="hidden" name="base_url" value="<?= inst_h($default_base_url) ?>">
            <input type="hidden" name="smtp_host" value="<?= inst_h($default_smtp_host) ?>">
            <input type="hidden" name="smtp_port" value="<?= inst_h((string) $default_smtp_port) ?>">
            <input type="hidden" name="smtp_from" value="<?= inst_h($default_smtp_from) ?>">
            <input type="hidden" name="smtp_from_name" value="<?= inst_h($default_smtp_from_name) ?>">
            <input type="hidden" name="admin_email" value="<?= inst_h($default_admin_email) ?>">
            <input type="hidden" name="delai_relance_h" value="<?= inst_h((string) $default_delai_relance_h) ?>">
            <button type="submit" class="btn btn-test" <?= $default_admin_email === '' || $default_admin_email === null || $default_admin_email === '0' ? 'disabled' : '' ?>><span aria-hidden="true">📧</span> Envoyer un email de test</button>
            <?php if ($default_admin_email === '' || $default_admin_email === null || $default_admin_email === '0'): ?>
                <span class="hint u-mar">Renseignez l'email administrateur ci-dessus d'abord.</span>
            <?php endif; ?>
        </form>
    </div>
