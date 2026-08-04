              <tr>
                <td><span class="styled-box-9"><?= $form_label ?></span></td>
                <td><strong><?= $nom ?></strong></td>
                <td class="u-whi <?= $deadline_urgency ?>"><?= $deadline_val ?></td>
                <td>
                  <div class="token-grid">
                    <?= $tokens_html ?>
                  </div>
                </td>
                <td class="u-whi"><?= $submitted ?></td>
                <td><?= $etat ?><?= $admin_comment_html ?><?= $validator_badge ?></td>
                <td><a href="<?= $view_url ?>" class="u-col-fon-tex-2">voir</a></td>
              </tr>
              <tr>
                <td colspan="7">
                  <details>
                    <summary>Détails de la demande — <?= $detail_summary ?></summary>
                    <div class="detail-content">
<?= $detail ?>
                    </div>
                  </details>
                </td>
              </tr>
