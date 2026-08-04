<?php
declare(strict_types=1);

namespace App\Render;

/**
 * Rendu HTML de l'assistant d'installation (install.php).
 *
 * Délègue le rendu des étapes du wizard à des templates séparés
 * dans src/Render/templates/install/.
 *
 * Les fonctions utilitaires inst_h(), inst_generate_csrf(),
 * inst_csrf_field() restent définies dans install.php (autonomie du
 * wizard — il ne dépend ni de helpers.php ni de config.php à l'exécution).
 */
final class InstallRenderer
{
    private string $tplDir;

    public function __construct()
    {
        $this->tplDir = __DIR__ . '/templates/install/';
    }

    /**
     * CSS inline spécifique à l'assistant d'installation.
     */
    public function pageCss(): string
    {
        return (string) file_get_contents($this->tplDir . 'page_css.php');
    }

    /**
     * Indicateur d'étapes (stepper) du wizard d'installation.
     */
    public function renderStepper(int $step): string
    {
        return $this->loadTemplate('render_stepper.php', compact('step'));
    }

    /**
     * Bandeau de messages succès et erreur.
     *
     * @param array<int, string> $messages
     * @param array<int, string> $error_messages
     */
    public function renderMessages(array $messages, array $error_messages): string
    {
        return $this->loadTemplate('render_messages.php', compact('messages', 'error_messages'));
    }

    /**
     * Rendu de l'étape 1 — vérification des prérequis.
     *
     * @param array<int, array{ok: bool, label: string, detail: string}> $prerequisites
     */
    public function renderStep1(array $prerequisites, bool $all_prereqs_ok): string
    {
        return $this->loadTemplate('render_step1.php', compact('prerequisites', 'all_prereqs_ok'));
    }

    /**
     * Rendu de l'étape 2 — formulaire de configuration + test SMTP.
     *
     * @param array<string, mixed> $d
     */
    public function renderStep2(array $d): string
    {
        return $this->loadTemplate('render_step2.php', compact('d'));
    }

    /**
     * Rendu de l'étape 3 — confirmation et installation.
     *
     * @param array<string, mixed>|null $confirm_config
     */
    public function renderStep3(?array $confirm_config, string $install_dir = ''): string
    {
        return $this->loadTemplate('render_step3.php', compact('confirm_config', 'install_dir'));
    }

    /**
     * Compose et affiche la page complète de l'assistant d'installation.
     *
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

    /**
     * Charge un template PHP depuis le répertoire templates/install/.
     *
     * Les variables passées via $data sont extraites dans la portée du template.
     * Le template est exécuté via include (permet le PHP inline) et son output est
     * capturé via le buffer de sortie.
     *
     * @param array<string, mixed> $data
     */
    private function loadTemplate(string $filename, array $data = []): string
    {
        $filepath = $this->tplDir . $filename;
        if (!file_exists($filepath)) {
            throw new \RuntimeException("Template not found: {$filepath}");
        }
        extract($data, \EXTR_SKIP);
        ob_start();
        include $filepath;
        return (string) ob_get_clean();
    }
}
