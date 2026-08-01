<?php
declare(strict_types=1);

namespace App\Render;

/**
 * Rendu HTML de l'assistant d'installation (install.php).
 *
 * Contient les fonctions de rendu des 3 étapes du wizard d'installation :
 *  - pageCss()              : CSS inline (balise <style>)
 *  - renderStepper()        : indicateur d'étapes (1/2/3)
 *  - renderMessages()       : bandeau messages succès + erreur
 *  - renderStep1()          : vérification des prérequis
 *  - renderStep2()          : formulaire de configuration
 *  - renderStep3()          : confirmation + installation
 *  - renderPage()           : compose et affiche la page complète
 *
 * Les fonctions utilitaires inst_h(), inst_generate_csrf(),
 * inst_csrf_field() restent définies dans install.php (autonomie du
 * wizard — il ne dépend ni de helpers.php ni de config.php à l'exécution).
 */
final class InstallRenderer
{
    /**
     * CSS inline spécifique à l'assistant d'installation.
     */
    public function pageCss(): string
    {
        return <<<'CSS'
                    /* ── Reset & Base ──────────────────────────────────────── */
                    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
                    html { scroll-behavior: smooth; }
                    body { font-family: "Marianne", Arial, sans-serif; background: #f5f5fe; color: #1e1e1e; min-height: 100vh; display: flex; flex-direction: column; }

                    /* ── Bandeau ──────────────────────────────────────────── */
                    .bandeau { background: #003189; color: #fff; padding: .75rem 2rem; font-size: .85rem; margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; }
                    .bandeau a { color: #b3c8f0; font-size: .8rem; text-decoration: none; }

                    /* ── Container ────────────────────────────────────────── */
                    .container { max-width: 800px; margin: 0 auto; padding: 0 1rem 2rem; width: 100%; flex: 1; }

                    /* ── Typography ───────────────────────────────────────── */
                    h1 { font-size: 1.4rem; color: #003189; margin-bottom: 1.25rem; }
                    h2 { font-size: 1.1rem; color: #003189; border-bottom: 2px solid #003189; padding-bottom: .5rem; margin-bottom: 1rem; }

                    /* ── Cards ────────────────────────────────────────────── */
                    .card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 1.5rem; margin-bottom: 1.5rem; }
                    .card h2 { font-size: 1.1rem; color: #003189; border-bottom: 2px solid #003189; padding-bottom: .5rem; margin-bottom: 1.25rem; }

                    /* ── Buttons ──────────────────────────────────────────── */
                    .btn { padding: .5rem 1rem; border: none; border-radius: 3px; font-size: .85rem; font-family: inherit; cursor: pointer; text-decoration: none; display: inline-block; }
                    .btn-primary { background: #003189; color: #fff; }
                    .btn-primary:hover { background: #002270; }
                    .btn-secondary { background: #f0f0f0; color: #333; }
                    .btn-secondary:hover { background: #e0e0e0; }
                    .btn-danger { background: #c0392b; color: #fff; }
                    .btn-danger:hover { background: #a93226; }
                    .btn-test { background: #27ae60; color: #fff; }
                    .btn-test:hover { background: #219a52; }
                    .btn:disabled { opacity: .55; cursor: not-allowed; }

                    /* ── Messages ─────────────────────────────────────────── */
                    .msg-success { background: #e8f5e9; border: 1px solid #27ae60; border-radius: 3px; padding: .75rem 1rem; margin-bottom: 1rem; color: #1a6b3c; }
                    .msg-error { background: #ffebee; border: 1px solid #c0392b; border-radius: 3px; padding: .75rem 1rem; margin-bottom: 1rem; color: #c0392b; }
                    .msg-info { background: #e3f2fd; border: 1px solid #1976d2; border-radius: 3px; padding: .75rem 1rem; margin-bottom: 1rem; color: #1565c0; }

                    /* ── Form fields ──────────────────────────────────────── */
                    .field { display: flex; flex-direction: column; gap: .3rem; margin-bottom: 1rem; }
                    label { font-size: .85rem; font-weight: bold; color: #444; }
                    .hint { font-size: .75rem; color: #888; font-weight: normal; }
                    .req { color: #c0392b; margin-left: 2px; }
                    input[type="text"], input[type="date"], input[type="number"], input[type="password"], input[type="email"], select, textarea {
                        width: 100%; padding: .5rem .75rem; border: 1px solid #aaa;
                        border-radius: 3px; font-size: .9rem; font-family: inherit; background: #fff; color: #1e1e1e;
                    }
                    input:focus, select:focus, textarea:focus { outline: 2px solid #003189; outline-offset: 1px; border-color: #003189; }
                    .field-error { border-color: #c0392b !important; background: #fff5f5; }

                    /* ── Form actions ─────────────────────────────────────── */
                    .form-actions { display: flex; gap: .5rem; margin-top: 1rem; flex-wrap: wrap; }

                    /* ── Stepper ──────────────────────────────────────────── */
                    .stepper { display: flex; align-items: center; justify-content: center; margin-bottom: 2rem; gap: 0; }
                    .step-item { display: flex; flex-direction: column; align-items: center; min-width: 100px; max-width: 180px; flex: 1; text-align: center; position: relative; padding: 0 .5rem; }
                    .step-item:not(:last-child)::after { content: ''; position: absolute; top: 18px; right: -50%; width: 100%; height: 3px; z-index: 0; }
                    .step-item.step-done:not(:last-child)::after { background: #1a6b3c; }
                    .step-item.step-active:not(:last-child)::after { background: #b45309; }
                    .step-item.step-upcoming:not(:last-child)::after { background: #ccc; }
                    .step-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .95rem; font-weight: bold; z-index: 1; margin-bottom: .5rem; }
                    .step-done .step-icon { background: #1a6b3c; color: #fff; }
                    .step-active .step-icon { background: #003189; color: #fff; }
                    .step-upcoming .step-icon { background: #ccc; color: #666; }
                    .step-label { font-size: .78rem; font-weight: bold; color: #333; margin-bottom: .15rem; line-height: 1.3; }
                    .step-upcoming .step-label { color: #999; }

                    /* ── Prerequisite checks ──────────────────────────────── */
                    .check-list { list-style: none; padding: 0; }
                    .check-item { display: flex; align-items: flex-start; gap: .75rem; padding: .75rem 0; border-bottom: 1px solid #eee; font-size: .9rem; }
                    .check-item:last-child { border-bottom: none; }
                    .check-icon { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .85rem; font-weight: bold; flex-shrink: 0; margin-top: 1px; }
                    .check-ok .check-icon { background: #1a6b3c; color: #fff; }
                    .check-fail .check-icon { background: #c0392b; color: #fff; }
                    .check-label { font-weight: bold; color: #333; }
                    .check-detail { font-size: .8rem; color: #888; margin-top: .15rem; }

                    /* ── Config preview (step 3) ──────────────────────────── */
                    .config-preview { background: #f7f7fb; border: 1px solid #ddd; border-radius: 4px; padding: 1.25rem; font-family: "Consolas", "Monaco", monospace; font-size: .82rem; line-height: 1.7; overflow-x: auto; white-space: pre-wrap; word-break: break-all; }
                    .config-preview .config-key { color: #003189; font-weight: bold; }
                    .config-preview .config-val { color: #1a6b3c; }

                    /* ── Warning box ──────────────────────────────────────── */
                    .warn-box { background: #fff3e0; border-left: 4px solid #b45309; padding: 1rem 1.25rem; margin-bottom: 1rem; border-radius: 0 4px 4px 0; font-size: .9rem; color: #7c4700; }

                    /* ── Footer ───────────────────────────────────────────── */
                    .footer { text-align: center; padding: 1.5rem; font-size: .78rem; color: #999; margin-top: auto; border-top: 1px solid #eee; }

                    /* ── Responsive ───────────────────────────────────────── */
                    @media (max-width: 600px) {
                        .stepper { gap: 0; }
                        .step-item { min-width: 70px; padding: 0 .25rem; }
                        .step-label { font-size: .7rem; }
                        .bandeau { padding: .5rem 1rem; font-size: .78rem; }
                    }
            CSS;
    }

    /**
     * Indicateur d'étapes (stepper) du wizard d'installation.
     */
    public function renderStepper(int $step): string
    {
        ob_start();
        ?>
    <div class="stepper">
        <div class="step-item <?= $step > 1 ? 'step-done' : 'step-active' ?>">
            <div class="step-icon"><?= $step > 1 ? '✓' : '1' ?></div>
            <div class="step-label">Prérequis</div>
        </div>
        <div class="step-item <?= $step >= 2 ? ($step > 2 ? 'step-done' : 'step-active') : 'step-upcoming' ?>">
            <div class="step-icon"><?= $step > 2 ? '✓' : '2' ?></div>
            <div class="step-label">Configuration</div>
        </div>
        <div class="step-item <?= $step >= 3 ? 'step-active' : 'step-upcoming' ?>">
            <div class="step-icon">3</div>
            <div class="step-label">Installation</div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
    }

    /**
     * Bandeau de messages succès et erreur.
     */
    /**
     * @param array<int, string> $messages
     * @param array<int, string> $error_messages
     */
    public function renderMessages(array $messages, array $error_messages): string
    {
        ob_start();
        foreach ($messages as $msg): ?>
        <div class="msg-success" role="status" aria-live="polite"><?= inst_h($msg) ?></div>
    <?php endforeach; ?>

    <?php foreach ($error_messages as $error_message): ?>
        <div class="msg-error" role="alert" aria-live="assertive"><?= inst_h($error_message) ?></div>
    <?php endforeach;
        return (string) ob_get_clean();
    }

    /**
     * Rendu de l'étape 1 — vérification des prérequis.
     */
    /**
     * @param array<int, array{ok: bool, label: string, detail: string}> $prerequisites
     */
    public function renderStep1(array $prerequisites, bool $all_prereqs_ok): string
    {
        ob_start();
        ?>
    <!-- ════════════════════════════════════════════════════════════
         ÉTAPE 1 : Vérification des prérequis
         ════════════════════════════════════════════════════════════ -->
    <div class="card">
        <h2>Étape 1 — Vérification des prérequis</h2>
        <p class="caption-2">
            L'assistant vérifie que votre environnement répond aux exigences minimales pour faire fonctionner <?= \App\Core\App::html()->escape(NavigationRenderer::getAppName()) ?>.
        </p>
        <ul class="check-list">
            <?php foreach ($prerequisites as $prerequisite): ?>
            <li class="check-item <?= $prerequisite['ok'] ? 'check-ok' : 'check-fail' ?>">
                <div class="check-icon"><?= $prerequisite['ok'] ? '✓' : '✗' ?></div>
                <div>
                    <div class="check-label"><?= inst_h($prerequisite['label']) ?></div>
                    <div class="check-detail"><?= inst_h($prerequisite['detail']) ?></div>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php if ($all_prereqs_ok): ?>
    <form method="POST">
        <?= inst_csrf_field() ?>
        <input type="hidden" name="action" value="to_step2">
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Continuer vers la configuration →</button>
        </div>
    </form>
    <?php else: ?>
    <div class="warn-box">
        <span aria-hidden="true">⚠</span> Certains prérequis ne sont pas satisfaits. Corrigez les problèmes ci-dessus puis rechargez cette page.
    </div>
    <form method="GET">
        <div class="form-actions">
            <button type="submit" class="btn btn-secondary">↻ Recharger la page</button>
        </div>
    </form>
    <?php endif;
        return (string) ob_get_clean();
    }

    /**
     * Rendu de l'étape 2 — formulaire de configuration + test SMTP.
     */
    /**
     * @param array<string, mixed> $d
     */
    public function renderStep2(array $d): string
    {
        $default_base_url       = $d['base_url'] ?? '';
        $default_smtp_host      = $d['smtp_host'] ?? '';
        $default_smtp_port      = $d['smtp_port'] ?? 25;
        $default_smtp_from      = $d['smtp_from'] ?? '';
        $default_smtp_from_name = $d['smtp_from_name'] ?? '';
        $default_admin_email    = $d['admin_email'] ?? '';
        $default_delai_relance_h = $d['delai_relance_h'] ?? 48;

        ob_start();
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
    <?php
    return (string) ob_get_clean();
    }

    /**
     * Rendu de l'étape 3 — confirmation et installation.
     */
    /**
     * @param array<string, mixed>|null $confirm_config
     */
    public function renderStep3(?array $confirm_config, string $install_dir = ''): string
    {
        ob_start();
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
                'DEFAULT_DB_PATH'=> '__DIR__ . \'/db/workflow.db\'',
                'DB_PATH'        => 'DEFAULT_DB_PATH',
                'SETTINGS_DEFAULTS → smtp_host'        => $confirm_config['smtp_host'],
                'SETTINGS_DEFAULTS → smtp_port'        => (string) $confirm_config['smtp_port'],
                'SETTINGS_DEFAULTS → smtp_from'        => $confirm_config['smtp_from'],
                'SETTINGS_DEFAULTS → smtp_from_name'   => $confirm_config['smtp_from_name'],
                'SETTINGS_DEFAULTS → delai_relance_h'  => (string) $confirm_config['delai_relance_h'],
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

    <?php endif;
        return (string) ob_get_clean();
    }

    /**
     * Compose et affiche la page complète de l'assistant d'installation.
     */
    /**
     * @param array{step: int, messages: list<string>, error_messages: list<string>, prerequisites: list<array{ok: bool, label: string, detail: string}>, all_prereqs_ok: bool, confirm_config: array<string, mixed>|null, defaults: array<string, mixed>, install_dir: string} $p
     */
    public function renderPage(array $p): void
    {
        $step             = (int) ($p['step'] ?? 1);
        $messages         = $p['messages'] ?? [];
        $error_messages   = $p['error_messages'] ?? [];
        $prerequisites    = $p['prerequisites'] ?? [];
        $all_prereqs_ok   = (bool) ($p['all_prereqs_ok'] ?? false);
        $confirm_config   = $p['confirm_config'] ?? null;
        $defaults         = $p['defaults'] ?? [];
        $install_dir      = (string) ($p['install_dir'] ?? '');

        ?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Installation — <?= \App\Core\App::html()->escape(NavigationRenderer::getAppName()) ?></title>
    <?= NavigationRenderer::favicon() ?>
    <style nonce="<?= \App\Core\App::security()->getScriptNonce() ?>">
        <?= $this->pageCss() ?>
    </style>
</head>
<body class="page-install">

<div class="bandeau">
    <strong>DREETS</strong> — Assistant d'installation
</div>

<div class="container">

    <!-- Stepper -->
    <?= $this->renderStepper($step) ?>

    <h1><span aria-hidden="true">🔧</span> Installation de <?= \App\Core\App::html()->escape(NavigationRenderer::getAppName()) ?></h1>

    <?= $this->renderMessages($messages, $error_messages) ?>

    <?php
    if ($step === 1) {
        echo $this->renderStep1($prerequisites, $all_prereqs_ok);
    } elseif ($step === 2) {
        echo $this->renderStep2($defaults);
    } elseif ($step === 3) {
        echo $this->renderStep3($confirm_config, $install_dir);
    }
        ?>

</div>

<div class="footer">
    <?= \App\Core\App::html()->escape(NavigationRenderer::getAppName()) ?> — Assistant d'installation · Version 3.0.0
</div>

</body>
</html>
    <?php
    }
}
