<!-- ═══════════════════════════════════════════════════════════ -->
<!-- SECTION 4 : Test email                                    -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card mt-15-2" id="section-email-send">
    <h2>Test d'envoi d'email</h2>
    <p class="caption-2">Envoyer un email de test à votre adresse (<?= \App\Core\App::html()->escape(\App\Core\App::auth()->getUser()) ?>) pour vérifier la configuration SMTP.</p>
    <?php if ($mail_dry_run === '1'): ?>
        <div class="warning-box mb-1">
            <strong>Mode Dry-Run actif</strong> — L'email sera journalisé mais <strong>pas réellement envoyé</strong>.
            Désactivez le Dry-Run pour effectuer un envoi réel.
        </div>
    <?php endif; ?>
    <form method="POST">
        <?= \App\Core\App::security()->csrfField() ?>
        <input type="hidden" name="action" value="test_email">
        <button type="submit" class="btn btn-test">Envoyer un email de test</button>
    </form>
</div>
