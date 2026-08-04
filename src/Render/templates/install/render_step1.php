<?php
/**
 * @var list<array{ok: bool, label: string, detail: string}> $prerequisites
 * @var bool $all_prereqs_ok
 */
?>
    <!-- ════════════════════════════════════════════════════════════
         ÉTAPE 1 : Vérification des prérequis
         ════════════════════════════════════════════════════════════ -->
    <div class="card">
        <h2>Étape 1 — Vérification des prérequis</h2>
        <p class="caption-2">
            L'assistant vérifie que votre environnement répond aux exigences minimales pour faire fonctionner <?= \App\Core\App::html()->escape(\App\Render\NavigationRenderer::getAppName()) ?>.
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
    <?php endif; ?>
