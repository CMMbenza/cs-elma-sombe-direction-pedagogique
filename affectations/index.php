<?php
// /directeur/affectations/index.php
declare(strict_types=1);

require_once __DIR__.'/../includes/auth.php';
require_directeur();

$msg =$_GET['msg'] ?? '';
$error =$_GET['error'] ?? '';

/* ========= CHARGEMENT DES DONNÉES ========= */
$q = trim((string)($_GET['q'] ?? ''));
$agents =$classes = $intitules_existants =$summary = [];

try {
    $agents =$pdo->query("SELECT id, nom, postnom, prenom FROM agent ORDER BY nom, postnom, prenom")->fetchAll();
    $classes =$pdo->query("SELECT c.id, CONCAT(c.description, ' ' ,cy.description) AS classe, cy.description AS cycle FROM classe c LEFT JOIN cycle cy ON cy.id = c.cycle ORDER BY cy.description, c.description")->fetchAll();
    $intitules_existants =$pdo->query("SELECT DISTINCT intitule FROM cours WHERE intitule IS NOT NULL AND intitule <> '' ORDER BY intitule")->fetchAll(PDO::FETCH_COLUMN);

    if ($q !== '') {
        $stmt =$pdo->prepare("
            SELECT ag.id AS agent_id, CONCAT(ag.nom, ' ', ag.postnom, ' ', ag.prenom) AS prof,
                   COUNT(DISTINCT a.classe_id) AS nb_classes, COUNT(DISTINCT a.cours_id) AS nb_cours, MAX(a.date_affect) AS last_aff
            FROM affectation_prof_classe a
            JOIN agent ag ON ag.id = a.agent_id
            JOIN classe c ON c.id = a.classe_id
            LEFT JOIN cycle cy ON cy.id = c.cycle
            JOIN cours co ON co.id = a.cours_id
            WHERE CONCAT(ag.nom, ' ', ag.postnom, ' ', ag.prenom) LIKE :like OR c.description LIKE :like OR co.intitule LIKE :like OR cy.description LIKE :like
            GROUP BY ag.id, prof ORDER BY prof
        ");
        $stmt->execute([':like' => "\%{$q}%"]);
        $summary =$stmt->fetchAll();
    } else {
        $summary =$pdo->query("
            SELECT ag.id AS agent_id, CONCAT(ag.nom, ' ', ag.postnom, ' ', ag.prenom) AS prof,
                   COUNT(DISTINCT a.classe_id) AS nb_classes, COUNT(DISTINCT a.cours_id) AS nb_cours, MAX(a.date_affect) AS last_aff
            FROM affectation_prof_classe a
            JOIN agent ag ON ag.id = a.agent_id
            GROUP BY ag.id, prof ORDER BY prof
        ")->fetchAll();
    }
} catch (Throwable $e) {$error = "Erreur de chargement des données.";
}

// Détails par agent pour le modal
$details_by_agent = [];
if (!$error &&$summary) {
    $agent_ids = array_map(fn($r) => (int)$r['agent_id'],$summary);
    $placeholders = implode(',', array_fill(0, count($agent_ids), '?'));

    try {
        $stmt =$pdo->prepare("
            SELECT a.id AS affect_id, a.agent_id, a.classe_id, CONCAT(c.description, ' ' ,cy.description) AS classe, cy.description AS cycle, co.intitule AS cours, a.date_affect, a.created_at
            FROM affectation_prof_classe a
            JOIN classe c ON c.id = a.classe_id
            LEFT JOIN cycle cy ON cy.id = c.cycle
            JOIN cours co ON co.id = a.cours_id
            WHERE a.agent_id IN ($placeholders)
            ORDER BY c.description, co.intitule, a.date_affect DESC, a.id DESC
        ");
        $stmt->execute($agent_ids);
        $rows =$stmt->fetchAll();

        foreach ($rows as$r) {
            $aid = (int)$r['agent_id'];
            if (!isset($details_by_agent[$aid])) {
                $details_by_agent[$aid] = ['classes' => [], 'cours' => [], 'rows' => []];
            }
            $cls = $r['classe'] . (!empty($r['cycle']) ? " — Cycle: ".$r['cycle'] : '');$details_by_agent[$aid]['classes'][$cls] = true;
            $details_by_agent[$aid]['cours'][$r['cours']] = true;
            $details_by_agent[$aid]['rows'][] = [
                'affect_id' => (int)$r['affect_id'], 'agent_id' => (int)$r['agent_id'], 'classe_id' => (int)$r['classe_id'],
                'classe' => $r['classe'], 'cycle' => $r['cycle'] ?? null, 'cours' => $r['cours'], 'date_affect' => $r['date_affect'], 'created_at' =>$r['created_at']
            ];
        }

        foreach ($details_by_agent as $aid =>$pack) {
            $details_by_agent[$aid]['classes'] = array_keys($pack['classes']);$details_by_agent[$aid]['cours']   = array_keys($pack['cours']);
        }
    } catch (Throwable $e) {}
}

$details_json = json_encode($details_by_agent, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

require_once __DIR__.'/../layout/header.php';
require_once __DIR__.'/../layout/navbar.php';
?>

<div class="container py-4">
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Affectations (Résumé par professeur)</h1>
        <div class="d-flex gap-2">
            <form class="d-flex" method="get">
                <input class="form-control form-control-sm" type="search" name="q" placeholder="Rechercher..." value="<?= e($q) ?>">
                <button class="btn btn-sm btn-outline-secondary ms-2">OK</button>
            </form>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">➕ Nouvelle affectation</button>
        </div>
    </div>

    <?php if ($msg === 'deleted'): ?><div class="alert alert-success">Affectation supprimée.</div><?php endif; ?>
    <?php if ($msg === 'updated'): ?><div class="alert alert-success">Affectation mise à jour.</div><?php endif; ?>
    <?php if ($msg === 'created'): ?><div class="alert alert-success">Affectation ajoutée.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body table-responsive p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>#</th><th>Prof</th><th>Classes</th><th>Cours</th><th>Dernière affect.</th><th style="width:1%;">Action</th></tr>
                </thead>
                <tbody>
                    <?php if (!$summary): ?>
                        <tr><td colspan="6" class="text-center py-4"><em>Aucune affectation trouvée.</em></td></tr>
                    <?php else: foreach ($summary as$r): ?>
                        <tr>
                            <td><?= e($r['agent_id']) ?></td>
                            <td class="fw-bold"><?= e($r['prof']) ?></td>
                            <td><span class="badge text-bg-light border"><?= (int)$r['nb_classes'] ?></span></td>
                            <td><span class="badge text-bg-light border"><?= (int)$r['nb_cours'] ?></span></td>
                            <td><?= e($r['last_aff']) ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary"
                                    data-bs-toggle="modal" data-bs-target="#detailModal"
                                    data-agent-id="<?= (int)$r['agent_id'] ?>" data-prof="<?= e($r['prof']) ?>">Voir détails</button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL CRÉATION -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form action="affectation-service.php" method="post" class="modal-content">
            <input type="hidden" name="form_action" value="create">
            <div class="modal-header"><h5 class="modal-title fw-bold">Nouvelle affectation</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Professeur *</label>
                    <select name="agent_id" class="form-select" required>
                        <option value="">— Sélectionner —</option>
                        <?php foreach ($agents as$a): ?><option value="<?= $a['id'] ?>"><?= e($a['nom'].' '.$a['postnom'].' '.$a['prenom']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold d-block">Classes *</label>
                    <div class="border rounded p-2" style="max-height: 180px; overflow:auto">
                        <div class="row">
                            <?php foreach ($classes as$c): ?>
                                <div class="col-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="classe_ids[]" value="<?= $c['id'] ?>"> <?= e($c['classe']) ?></label></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Cours existants</label>
                        <select name="existing_intitules[]" class="form-select" multiple size="4">
                            <?php foreach ($intitules_existants as$int): ?><option value="<?= e($int) ?>"><?= e($int) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Nouveaux cours (virgules)</label>
                        <textarea name="new_intitules" rows="4" class="form-control"></textarea>
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label fw-bold">Date *</label>
                    <input type="date" name="date_affect" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary fw-bold">Enregistrer</button></div>
        </form>
    </div>
</div>

<!-- MODAL DÉTAILS -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-light"><h5 class="modal-title fw-bold">Détails — <span id="m-prof">Professeur</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light"><tr><th>#</th><th>Classe</th><th>Cours</th><th>Date</th><th>Actions</th></tr></thead>
                        <tbody id="m-rows"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL MODIFICATION -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="affectation-service.php" method="post" class="modal-content">
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="affect_id" id="edit_affect_id">
            <div class="modal-header bg-warning"><h5 class="modal-title fw-bold">Modifier l'affectation</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Professeur</label>
                    <select name="agent_id" id="edit_agent_id" class="form-select" required>
                        <?php foreach ($agents as$a): ?><option value="<?= $a['id'] ?>"><?= e($a['nom'].' '.$a['postnom'].' '.$a['prenom']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Classe</label>
                    <select name="classe_id" id="edit_classe_id" class="form-select" required>
                        <?php foreach ($classes as$c): ?><option value="<?= $c['id'] ?>"><?= e($c['classe']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Cours</label>
                    <input type="text" id="edit_cours_nom" class="form-control" disabled>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Date</label>
                    <input type="date" name="date_affect" id="edit_date_affect" class="form-control" required>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-warning fw-bold">Mettre à jour</button></div>
        </form>
    </div>
</div>

<script>
const DETAILS = <?= $details_json ?: '{}' ?>;

document.getElementById('detailModal')?.addEventListener('show.bs.modal', (event) => {
    const btn = event.relatedTarget;
    if (!btn) return;
    const agentId = parseInt(btn.getAttribute('data-agent-id') || '0', 10);
    document.getElementById('m-prof').textContent = btn.getAttribute('data-prof') || '';

    const pack = DETAILS[agentId] || { rows: [] };
    const tbody = document.getElementById('m-rows');
    if (pack.rows.length) {
        tbody.innerHTML = pack.rows.map(r => `
            <tr>
                <td>${r.affect_id}</td>
                <td>${r.classe}</td>
                <td class="fw-bold">${r.cours}</td>
                <td>${r.date_affect}</td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-warning edit-btn"
                        data-id="${r.affect_id}" data-agent="${r.agent_id}" data-classe="${r.classe_id}"
                        data-cours="${r.cours}" data-date="${r.date_affect}">✏️</button>
                    <a href="affectation-service.php?action=delete&id=${r.affect_id}" 
                       class="btn btn-sm btn-outline-danger" 
                       onclick="return confirm('Supprimer cette affectation ?');">🗑️</a>
                </td>
            </tr>
        `).join('');
    } else {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center">Aucune affectation.</td></tr>';
    }
});

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('edit-btn')) {
        const btn = e.target;
        document.getElementById('edit_affect_id').value = btn.getAttribute('data-id');
        document.getElementById('edit_agent_id').value = btn.getAttribute('data-agent');
        document.getElementById('edit_classe_id').value = btn.getAttribute('data-classe');
        document.getElementById('edit_cours_nom').value = btn.getAttribute('data-cours');
        document.getElementById('edit_date_affect').value = btn.getAttribute('data-date');

        bootstrap.Modal.getInstance(document.getElementById('detailModal')).hide();
        new bootstrap.Modal(document.getElementById('editModal')).show();
    }
});
</script>

<?php require_once __DIR__.'/../layout/footer.php'; ?>