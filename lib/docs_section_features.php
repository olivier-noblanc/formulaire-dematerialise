<?php
declare(strict_types=1);

/**
 * Section de documentation - extraite de docs.php (P-DOCS refactor).
 * Renvoie le HTML rendu de la section via render_docs_section_features().
 */

function render_docs_section_features(): string
{
    ob_start();
    ?>
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 5. FONCTIONNALITÉS                                         -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="fonctionnalites">
    <h2>5. Fonctionnalités de l'application</h2>

    <p>Voici la liste complète des fonctionnalités de l'application :</p>

    <div class="feature-grid">
      <div class="feature-item">
        <strong><span aria-hidden="true">📝</span> Formulaires dynamiques configurables</strong>
        <p>Créez et configurez vos formulaires sans coder. Champs texte, listes déroulantes, cases à cocher, pièces jointes.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">🔄</span> Circuit de validation séquentiel et parallèle</strong>
        <p>Définissez l'ordre de validation : étape par étape ou plusieurs en même temps, selon les besoins de chaque formulaire.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">🔐</span> Tokens cryptographiques à usage unique</strong>
        <p>Chaque lien de validation est unique et sécurisé. Une fois utilisé, il ne peut plus servir, garantissant l'intégrité du processus.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">📎</span> Pièces jointes sécurisées</strong>
        <p>Les fichiers joints sont stockés de manière sécurisée en base de données. Seules les personnes autorisées peuvent les télécharger.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">🔔</span> Relances automatiques et manuelles</strong>
        <p>Le système relance automatiquement les validateurs en attente. L'administrateur peut aussi relancer manuellement.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">🔄</span> Délégation de validation</strong>
        <p>Un validateur peut transférer sa validation à un collègue. La délégation est tracée dans l'historique.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">⏰</span> Alertes de deadline configurables</strong>
        <p>Recevez une alerte quand une demande approche de sa date limite. Configurable par formulaire et par destinataire.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">📈</span> Statistiques et tableaux de bord</strong>
        <p>Suivez les performances : temps de traitement, taux de validation, répartition par période et par formulaire.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">📋</span> Journal d'audit complet</strong>
        <p>Chaque action est enregistrée : qui a fait quoi et quand. Traçabilité totale pour la conformité et le contrôle.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">🔒</span> Conformité RGPD</strong>
        <p>Export, suppression et purge automatique des données. Durée de conservation configurable. Droit d'accès et d'effacement garantis.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">🔗</span> Webhooks pour intégration SI</strong>
        <p>Connectez l'application à votre système d'information. Notifications automatiques lors des événements clés.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">💚</span> Contrôle de santé pour la surveillance</strong>
        <p>Vérifiez automatiquement l'état de l'application : base de données, configuration email, version PHP.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">💾</span> Sauvegarde et restauration</strong>
        <p>Téléchargez une sauvegarde complète et restaurez-la en cas de besoin. Sécurisez vos données simplement.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">🎨</span> Design Marianne / RGAA accessible</strong>
        <p>Interface conforme au système de design de l'État et aux normes d'accessibilité RGAA. Utilisable par tous.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">🛡️</span> Zéro JavaScript (sécurité maximale)</strong>
        <p>L'application fonctionne entièrement sans JavaScript côté client. Sécurité renforcée, compatible avec les navigateurs les plus restrictifs.</p>
      </div>
    </div>
  </div>
    <?php
    // rtrim supprime les espaces de l indentation avant la balise fermante
    return rtrim((string) ob_get_clean(), " \t");
}
