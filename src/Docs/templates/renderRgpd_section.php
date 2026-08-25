
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 8. RGPD ET MENTIONS LÉGALES                                -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="rgpd-legal">
    <h2>8. RGPD et mentions légales</h2>

    <div class="rgpd-box">
      <h3><span aria-hidden="true">📜</span> Mentions légales</h3>
      <?php if ($legal_mentions !== '' && $legal_mentions !== '0'): ?>
        <p><?= nl2br(\App\Core\App::html()->escape($legal_mentions)) ?></p>
      <?php else: ?>
        <p>Les données collectées sont traitées dans le cadre de la dématérialisation des procédures internes de la DREETS. Conformément au RGPD, vous disposez d'un droit d'accès, de rectification et d'effacement de vos données. Contact : <?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('rgpd_contact', 'CIL DREETS')) ?>. Durée de conservation : <?= (int) \App\Core\App::settings()->get('retention_months', '24') ?> mois après clôture.</p>
      <?php endif; ?>
    </div>

    <h3><span aria-hidden="true">🔒</span> Protection des données</h3>

    <p>L'application est conçue pour respecter le Règlement Général sur la Protection des Données (RGPD). Voici les mesures en place :</p>

    <h4>Mesures techniques</h4>
    <ul>
      <li><strong>Liens de validation sécurisés</strong> — Chaque lien de validation contient un token cryptographique unique (64 caractères) à usage unique, impossible à deviner.</li>
      <li><strong>Zéro JavaScript</strong> — L'application ne nécessite aucun JavaScript côté client, réduisant les risques de sécurité liés aux scripts malveillants.</li>
      <li><strong>Protection CSRF</strong> — Tous les formulaires sont protégés contre les attaques de type « Cross-Site Request Forgery ».</li>
      <li><strong>Limitation des requêtes</strong> — Un système de rate limiting empêche les usages abusifs de l'application.</li>
      <li><strong>Authentification Windows</strong> — L'accès aux pages sensibles est protégé par l'authentification intégrée Windows.</li>
      <li><strong>Pièces jointes sécurisées</strong> — Les fichiers sont stockés en base de données et accessibles uniquement via des liens sécurisés.</li>
    </ul>

    <h4>Droits des personnes</h4>
    <ul>
      <li><strong>Droit d'accès</strong> (article 15 RGPD) — Chaque utilisateur peut consulter ses données.</li>
      <li><strong>Droit de rectification</strong> (article 16 RGPD) — Contactez l'administrateur pour corriger des données.</li>
      <li><strong>Droit à l'effacement</strong> (article 17 RGPD) — L'administrateur peut anonymiser les données d'un utilisateur.</li>
      <li><strong>Droit à la limitation du traitement</strong> (article 18 RGPD) — En annulant une demande, le traitement est stoppé.</li>
    </ul>

    <h4>Durée de conservation</h4>
    <p>
      Les données sont conservées pendant la durée configurée par l'administrateur (par défaut : <strong>24 mois après la clôture</strong> de la demande).
      Au-delà de ce délai, les données sont automatiquement supprimées par la purge automatique.
      L'administrateur peut modifier cette durée dans la page RGPD.
    </p>
    <p>
      <strong><span aria-hidden="true">♻️</span> Purge automatisée</strong> — Depuis la v10.0.9, la purge RGPD est <strong>automatisée</strong> : elle s'exécute sans intervention manuelle via le mécanisme de <strong>lazy cron</strong> intégré (une fois par jour, au premier accès à la base de données). Aucune tâche planifiée Windows n'est requise. Chaque exécution est tracée dans le journal d'audit (déclencheur <code>rgpd_purge</code>), garantissant la conformité à l'article 5.1.e du RGPD (limitation de la conservation).
    </p>

    <h4>Responsable de traitement</h4>
    <p>
      Le responsable de traitement est la DREETS (Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités).
      Pour toute question relative à la protection de vos données, contactez le <strong><?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('rgpd_contact', 'CIL DREETS')) ?></strong> (Correspondant Informatique et Libertés).
    </p>

    <h4>Accessibilité (RGAA)</h4>
    <p>
      CircuitDémat s'efforce d'être conforme au <strong>Référentiel Général d'Amélioration de l'Accessibilité (RGAA 4.1)</strong>.
      La <strong>déclaration d'accessibilité</strong> détaille le niveau de conformité, les non-conformités connues et les voies de recours ; elle est disponible dans le fichier <code>docs/declaration-rgaa.md</code> livré avec l'application.
    </p>
  </div>
