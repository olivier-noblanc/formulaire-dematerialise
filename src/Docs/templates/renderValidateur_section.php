
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 3. GUIDE DU VALIDATEUR                                     -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="guide-validateur">
    <h2>3. Guide du validateur — Valider une demande</h2>

    <p>En tant que validateur, vous recevez des demandes à traiter. Voici comment ça fonctionne, étape par étape.</p>

    <!-- ── Recevoir un email ── -->
    <h3><span aria-hidden="true">📧</span> Je reçois un email de validation</h3>

    <p>Quand une demande nécessite votre intervention, vous recevez un email de <strong><?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('smtp_from', 'workflow@exemple.invalid')) ?></strong> avec l'objet :</p>

    <div class="info-box">
      <p><code>[Action requise] Nom du formulaire — Nom de l'étape</code></p>
    </div>

    <p>Cet email contient :</p>
    <ul>
      <li>Un <strong>résumé des informations</strong> du formulaire rempli par l'agent</li>
      <li>Un <strong>bouton ou lien</strong> pour accéder à la page de validation</li>
    </ul>

    <!-- ── Email notification mockup ── -->
    <div class="email-mockup">
      <div class="email-header">CircuitDémat — Action requise</div>
      <div class="email-body">
        <p class="u-fs-base-mb-075">Bonjour,</p>
        <p class="u-fs-base-mb-075">Une nouvelle demande nécessite votre validation pour l'étape <strong>Informatique</strong>.</p>
        <table>
          <tr><td>Agent</td><td>Dupont Marie</td></tr>
          <tr><td>Service</td><td>Service Emploi</td></tr>
          <tr><td>Date de prise de poste</td><td>15/03/2025</td></tr>
          <tr><td>Type de poste</td><td>Portable + double écran</td></tr>
        </table>
        <span class="email-btn">✓ Marquer comme effectué</span>
      </div>
      <div class="email-footer"><span aria-hidden="true">🔒</span> Lien à usage unique — Ce lien ne fonctionnera plus après validation ou refus.</div>
    </div>

    <div class="tip-box">
      <p>Vous n'avez pas besoin d'être sur le réseau DREETS pour valider. Le lien dans l'email fonctionne depuis n'importe quel poste, sans connexion particulière.</p>
    </div>

    <!-- ── Cliquer sur le lien ── -->
    <h3>🔗 Je clique sur le lien de validation</h3>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Cliquez sur le bouton ou le lien</strong> dans l'email. Vous accédez à la page de validation.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Lisez les informations</strong> — Vous verrez le libellé de l'étape concernée (ex : « Informatique », « Ressources Humaines ») et les détails du formulaire rempli par l'agent.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Prenez le temps de vérifier</strong> — Les cases cochées sont marquées ✓. Si vous avez des questions, contactez l'agent qui a soumis la demande.</p>
      </div>
    </div>

    <!-- ── Valider ou refuser ── -->
    <h3><span aria-hidden="true">✅</span> Valider ou <span aria-hidden="true">❌</span> Refuser</h3>

    <p>Vous avez deux options :</p>

    <div class="step-row">
      <span class="step-num">A</span>
      <div class="step-text">
        <p><strong><span aria-hidden="true">✅</span> Valider</strong> — Confirme que l'étape est traitée. Le système passe automatiquement à l'étape suivante et envoie un email au validateur suivant.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">B</span>
      <div class="step-text">
        <p><strong><span aria-hidden="true">❌</span> Refuser</strong> — Bloque la demande. Elle est immédiatement clôturée avec le statut « Refusé ». Les étapes suivantes ne seront pas déclenchées.</p>
      </div>
    </div>

    <p>Dans les deux cas, vous pouvez ajouter un <strong>commentaire</strong> (facultatif mais recommandé) pour expliquer votre décision.</p>

    <div class="warn-box">
      <p><strong><span aria-hidden="true">⚠</span> Important :</strong> Le lien de validation est à <strong>usage unique</strong>. Une fois que vous avez cliqué sur Valider ou Refuser, le lien ne fonctionne plus. Si vous voyez « Déjà validé », cela signifie que l'action a déjà été effectuée (par vous ou par un collègue partageant la même adresse email).</p>
    </div>

    <img src="index.php?p=screenshot&f=16_validate.png" alt="Page de validation — boutons Valider et Refuser" class="screenshot">
    <p class="screenshot-caption">Page de validation — le validateur peut valider ou refuser l'étape avec un commentaire</p>

    <img src="index.php?p=screenshot&f=17_submission_view.png" alt="Vue détaillée d'une demande — progression du circuit de validation, délégation et historique" class="screenshot">
    <p class="screenshot-caption">Vue détaillée d'une demande — progression du circuit de validation, options de délégation et historique des validations</p>

    <!-- ── Après la validation ── -->
    <h3><span aria-hidden="true">➡️</span> Que se passe-t-il après ?</h3>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Si vous avez validé</strong> — Le système envoie automatiquement un email au(x) validateur(s) de l'étape suivante. Vous n'avez rien d'autre à faire.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Si vous avez refusé</strong> — La demande est clôturée et les étapes suivantes ne seront pas déclenchées. L'agent en est informé.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Dernière étape validée</strong> — Quand toutes les étapes sont validées, la demande est clôturée automatiquement avec le statut « Validé ».</p>
      </div>
    </div>

    <!-- ── Déléguer ── -->
    <h3><span aria-hidden="true">🔄</span> Déléguer ma validation</h3>

    <p>Si vous n'êtes pas la bonne personne pour valider, vous pouvez <strong>déléguer</strong> la validation à un collègue :</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Allez sur « Mes validations »</strong> (<code>index.php?p=my_validations</code>) — Cette page liste toutes les demandes qui attendent votre validation.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Trouvez la demande</strong> à déléguer et cliquez sur le bouton <strong>« Déléguer »</strong>.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Entrez l'adresse email</strong> de la personne à qui vous déléguez la validation.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">4</span>
      <div class="step-text">
        <p><strong>Ajoutez un motif</strong> (facultatif) — Expliquez pourquoi vous déléguez.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">5</span>
      <div class="step-text">
        <p><strong>Validez la délégation</strong> — Votre lien de validation est annulé et un nouveau lien est envoyé au délégataire. Il peut alors valider à votre place.</p>
      </div>
    </div>

    <div class="tip-box">
      <p>La délégation est tracée dans l'historique. L'administrateur peut voir qui a délégué à qui et pourquoi.</p>
    </div>

    <!-- ── Suivi des validations ── -->
    <h3><span aria-hidden="true">📋</span> Suivre mes validations</h3>

    <p>La page <strong>« Mes validations »</strong> (<code>index.php?p=my_validations</code>) vous permet de :</p>
    <ul>
      <li>Voir les <strong>demandes en attente</strong> de votre validation</li>
      <li>Consulter l'<strong>historique</strong> de vos validations passées</li>
      <li><strong>Déléguer</strong> une validation à un collègue</li>
      <li>Accéder directement au <strong>lien de validation</strong></li>
    </ul>

    <img src="index.php?p=screenshot&f=06_my_validations.png" alt="Page Mes validations — demandes en attente et historique" class="screenshot">
    <p class="screenshot-caption">Page « Mes validations » — vue des demandes en attente et de l'historique de validation</p>
  </div>
        
