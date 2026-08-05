<h1><?= $h($tJargon($form['label'])) ?></h1>
<div class="warn-box">
  <p><strong><span aria-hidden="true">⚠</span> Attention :</strong> Vous avez déjà une demande en cours pour ce formulaire (soumise le <?= $date ?>).</p>
  <p>Voulez-vous vraiment en soumettre une nouvelle ?</p>
</div>
<div class="u-mt-15-flex-center-wrap">
  <a href="index.php?p=submission_view&id=<?= $existingId ?>" class="btn btn-secondary">Voir la demande existante</a>
  <a href="<?= $h($confirmUrl) ?>" class="btn btn-primary">Soumettre quand même</a>
  <a href="index.php" class="btn btn-secondary">Annuler</a>
</div>
