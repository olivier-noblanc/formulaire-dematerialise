<?php
declare(strict_types=1);

/**
 * Section de documentation - extraite de docs.php (P-DOCS refactor).
 * Renvoie le HTML rendu de la section via render_docs_section_roles().
 */

function render_docs_section_roles(): string
{
    ob_start();
    ?>
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 6. RÔLES ET PERMISSIONS                                    -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="roles-permissions">
    <h2>6. Rôles et permissions</h2>

    <p>L'application distingue quatre profils. Voici ce que chacun peut faire :</p>

    <h3><span class="role-badge role-agent">Agent</span> L'agent</h3>
    <p>L'agent est la personne qui remplit et soumet un formulaire (par exemple, un agent qui déclare son arrivée).</p>
    <ul>
      <li><span aria-hidden="true">✅</span> Remplir un formulaire et soumettre une demande</li>
      <li><span aria-hidden="true">✅</span> Suivre l'avancement de <strong>ses propres</strong> demandes (page « Mes demandes »)</li>
      <li><span aria-hidden="true">✅</span> Annuler une de ses demandes en cours</li>
      <li><span aria-hidden="true">✅</span> Consulter les détails de ses demandes</li>
      <li><span aria-hidden="true">❌</span> Ne peut pas voir les demandes des autres agents</li>
      <li><span aria-hidden="true">❌</span> Ne peut pas configurer les formulaires ni les étapes</li>
    </ul>

    <h3><span class="role-badge role-validator">Validateur</span> Le validateur</h3>
    <p>Le validateur reçoit les demandes à traiter. Il n'a pas besoin d'être sur le réseau DREETS.</p>
    <ul>
      <li><span aria-hidden="true">✅</span> Valider ou refuser une demande (via le lien email)</li>
      <li><span aria-hidden="true">✅</span> Consulter les détails d'une demande à valider</li>
      <li><span aria-hidden="true">✅</span> Déléguer sa validation à un autre validateur</li>
      <li><span aria-hidden="true">✅</span> Suivre ses validations en attente et passées (page « Mes validations »)</li>
      <li><span aria-hidden="true">✅</span> Ajouter un commentaire lors de la validation ou du refus</li>
      <li><span aria-hidden="true">❌</span> Ne peut pas modifier une demande déjà envoyée</li>
      <li><span aria-hidden="true">❌</span> Ne peut pas accéder au tableau de bord administrateur</li>
    </ul>

    <h3><span class="role-badge role-admin">Admin</span> L'administrateur</h3>
    <p>L'administrateur configure et supervise l'application. Il a accès à toutes les fonctions de gestion.</p>
    <ul>
      <li><span aria-hidden="true">✅</span> Tout ce que peut faire un agent + un validateur</li>
      <li><span aria-hidden="true">✅</span> Voir le tableau de bord (toutes les demandes)</li>
      <li><span aria-hidden="true">✅</span> Créer, modifier et désactiver des formulaires</li>
      <li><span aria-hidden="true">✅</span> Configurer les étapes et les destinataires</li>
      <li><span aria-hidden="true">✅</span> Configurer les alertes de deadline</li>
      <li><span aria-hidden="true">✅</span> Consulter les statistiques et la surveillance</li>
      <li><span aria-hidden="true">✅</span> Gérer la conformité RGPD (export, suppression)</li>
      <li><span aria-hidden="true">✅</span> Sauvegarder et restaurer la base de données</li>
      <li><span aria-hidden="true">✅</span> Relancer manuellement un validateur</li>
      <li><span aria-hidden="true">✅</span> Annuler n'importe quelle demande en cours</li>
      <li><span aria-hidden="true">❌</span> Ne peut pas gérer les administrateurs</li>
      <li><span aria-hidden="true">❌</span> Ne peut pas modifier les paramètres SMTP et webhooks</li>
    </ul>

    <h3><span class="role-badge role-superadmin">Super admin</span> Le super administrateur</h3>
    <p>Le super administrateur a tous les droits. Il y en a généralement un seul dans l'organisation.</p>
    <ul>
      <li><span aria-hidden="true">✅</span> Tout ce que peut faire un administrateur</li>
      <li><span aria-hidden="true">✅</span> Approuver ou refuser les demandes d'accès administrateur</li>
      <li><span aria-hidden="true">✅</span> Gérer la liste des administrateurs (ajouter, supprimer)</li>
      <li><span aria-hidden="true">✅</span> Configurer les paramètres SMTP (serveur, port, expéditeur)</li>
      <li><span aria-hidden="true">✅</span> Configurer les webhooks (URL, événements)</li>
    </ul>

    <h3>Résumé des permissions</h3>
    <table class="perm-table">
      <thead>
        <tr>
          <th>Action</th>
          <th><span class="role-badge role-agent">Agent</span></th>
          <th><span class="role-badge role-validator">Validateur</span></th>
          <th><span class="role-badge role-admin">Admin</span></th>
          <th><span class="role-badge role-superadmin">Super admin</span></th>
        </tr>
      </thead>
      <tbody>
        <tr><td>Soumettre un formulaire</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Suivre ses demandes</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Annuler sa demande</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Valider / refuser</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Déléguer une validation</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Tableau de bord (toutes les demandes)</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Créer / modifier des formulaires</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Configurer les alertes</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Statistiques / surveillance</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Conformité RGPD</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Sauvegarde / restauration</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Gérer les administrateurs</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td></tr>
        <tr><td>Paramètres SMTP / webhooks</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td></tr>
      </tbody>
    </table>
  </div>
    <?php
    // rtrim supprime les espaces de l indentation avant la balise fermante
    return rtrim((string) ob_get_clean(), " \t");
}
