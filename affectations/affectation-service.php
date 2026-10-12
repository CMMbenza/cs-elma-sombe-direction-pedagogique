<?php
// /directeur/affectations/service.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_directeur();

$action = $_GET['action'] ?? ($_POST['form_action'] ?? '');

// ==========================================
// 1. SUPPRESSION (DELETE)
// ==========================================
if ($action === 'delete') {
    $affect_id = (int)($_GET['id'] ?? 0);
    if ($affect_id > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM affectation_prof_classe WHERE id = ?");
            $stmt->execute([$affect_id]);
            header("Location: index.php?msg=deleted");
            exit();
        } catch (Throwable $e) {
            header("Location: index.php?error=" . urlencode("Erreur lors de la suppression."));
            exit();
        }
    }
}

// ==========================================
// 2. CREATION (INSERT)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $agent_id          = (int)($_POST['agent_id'] ?? 0);
    $classe_ids        = (array)($_POST['classe_ids'] ?? []);
    $date_affect       = trim((string)($_POST['date_affect'] ?? ''));
    $selected_existing = (array)($_POST['existing_intitules'] ?? []);
    $new_intitules_raw = trim((string)($_POST['new_intitules'] ?? ''));

    $classe_ids = array_values(array_unique(array_filter(array_map('intval', $classe_ids), fn($x) => $x > 0)));

    $new_intitules = [];
    if ($new_intitules_raw !== '') {
        foreach (explode(',', $new_intitules_raw) as $tok) {
            $t = trim($tok);
            if ($t !== '') $new_intitules[] = $t;
        }
    }

    $intitules = array_values(array_unique(array_merge($selected_existing, $new_intitules)));

    if ($agent_id <= 0 || empty($classe_ids) || empty($intitules) || $date_affect === '') {
        header("Location: index.php?error=" . urlencode("Veuillez remplir tous les champs obligatoires."));
        exit();
    }

    try {
        $pdo->beginTransaction();

        foreach ($classe_ids as $cid) {
            foreach ($intitules as $intitule) {
                // Trouver / Créer le cours
                $stmt = $pdo->prepare("SELECT id FROM cours WHERE classe_id = ? AND intitule = ?");
                $stmt->execute([$cid, $intitule]);
                $c = $stmt->fetch();

                if ($c) {
                    $cours_id = (int)$c['id'];
                } else {
                    $ins = $pdo->prepare("INSERT INTO cours (intitule, classe_id, created_at) VALUES (?,?, NOW())");
                    $ins->execute([$intitule, $cid]);
                    $cours_id = (int)$pdo->lastInsertId();
                }

                // Créer l'affectation si non existante
                $chk = $pdo->prepare("
                    SELECT id FROM affectation_prof_classe
                    WHERE agent_id = ? AND classe_id = ? AND cours_id = ?
                    LIMIT 1
                ");
                $chk->execute([$agent_id, $cid, $cours_id]);
                if (!$chk->fetch()) {
                    $ins_aff = $pdo->prepare("
                        INSERT INTO affectation_prof_classe (agent_id, classe_id, cours_id, date_affect, created_at)
                        VALUES (?,?,?,?, NOW())
                    ");
                    $ins_aff->execute([$agent_id, $cid, $cours_id, $date_affect]);
                }
            }
        }

        $pdo->commit();
        header("Location: index.php?msg=created");
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: index.php?error=" . urlencode("Échec de l’enregistrement : " . $e->getMessage()));
        exit();
    }
}

// ==========================================
// 3. MODIFICATION (UPDATE)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update') {
    $affect_id   = (int)($_POST['affect_id'] ?? 0);
    $agent_id    = (int)($_POST['agent_id'] ?? 0);
    $classe_id   = (int)($_POST['classe_id'] ?? 0);
    $date_affect = trim((string)($_POST['date_affect'] ?? ''));

    if ($affect_id <= 0 || $agent_id <= 0 || $classe_id <= 0 || $date_affect === '') {
        header("Location: index.php?error=" . urlencode("Données invalides pour la modification."));
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE affectation_prof_classe 
            SET agent_id = ?, classe_id = ?, date_affect = ?
            WHERE id = ?
        ");
        $stmt->execute([$agent_id, $classe_id, $date_affect, $affect_id]);
        header("Location: index.php?msg=updated");
        exit();
    } catch (Throwable $e) {
        header("Location: index.php?error=" . urlencode("Échec de la mise à jour."));
        exit();
    }
}

// Si aucune action valide
header("Location: index.php");
exit();