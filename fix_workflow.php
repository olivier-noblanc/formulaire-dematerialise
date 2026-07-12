<?php
$content = file_get_contents('src/Workflow/WorkflowEngine.php');

$old = '                foreach ($groupe as $step) {
                    // Évaluer la condition
                    if (!$this->conditionEvaluator->evaluate(';

$new = '                foreach ($groupe as $step) {
                    // Étape déjà démarrée (a au moins un token) → ne pas créer de doublon
                    if (isset($tokensByStep[$step[\'step_id\']])) {
                        continue;
                    }

                    // Évaluer la condition
                    if (!$this->conditionEvaluator->evaluate(';

$content = str_replace($old, $new, $content);
file_put_contents('src/Workflow/WorkflowEngine.php', $content);
echo "Fixed WorkflowEngine\n";
