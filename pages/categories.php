<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();
if (currentUser()['role'] !== 'admin') { redirect('index.php'); exit; }
$pageTitle = 'Categorias';
$db = getDB();

if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';
    if ($action === 'save') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;
        if (!$data['name']) { echo json_encode(['success'=>false,'message'=>'Nome obrigatório']); exit; }
        if ($id) {
            $db->prepare("UPDATE categories SET name=?,description=? WHERE id=?")->execute([$data['name'],$data['description']??'',$id]);
            echo json_encode(['success'=>true,'message'=>'Categoria atualizada!']);
        } else {
            $db->prepare("INSERT INTO categories (name,description) VALUES (?,?)")->execute([$data['name'],$data['description']??'']);
            echo json_encode(['success'=>true,'message'=>'Categoria criada!']);
        }
        exit;
    }
    if ($action === 'delete') {
        $db->prepare("UPDATE categories SET active=0 WHERE id=?")->execute([$_GET['id']]);
        echo json_encode(['success'=>true,'message'=>'Removida.']);
        exit;
    }
    exit;
}

$categories = $db->query("SELECT c.*,(SELECT COUNT(*) FROM products WHERE category_id=c.id AND active=1) as prod_count FROM categories c WHERE c.active=1 ORDER BY c.name")->fetchAll();
include __DIR__.'/../includes/header.php';
?>

<div class="actions-bar">
    <div class="card-title"><i class="fas fa-tags"></i> Categorias</div>
    <button class="btn btn-primary" onclick="openModal('catModal');document.getElementById('catId').value='';document.getElementById('catName').value='';document.getElementById('catDesc').value=''"><i class="fas fa-plus"></i> Nova Categoria</button>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Nome</th><th>Descrição</th><th>Produtos</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c): ?>
            <tr id="cat-<?= $c['id'] ?>">
                <td class="td-name"><?= htmlspecialchars($c['name']) ?></td>
                <td style="color:var(--text-muted);font-size:13px"><?= htmlspecialchars($c['description'] ?: '—') ?></td>
                <td><span class="badge badge-primary"><?= $c['prod_count'] ?> produto(s)</span></td>
                <td>
                    <div style="display:flex;gap:4px">
                        <button class="btn btn-ghost btn-sm" onclick="editCat(<?= $c['id'] ?>,'<?= htmlspecialchars(addslashes($c['name'])) ?>','<?= htmlspecialchars(addslashes($c['description']??'')) ?>')"><i class="fas fa-pen"></i></button>
                        <?php if ($c['prod_count'] == 0): ?>
                        <button class="btn btn-ghost btn-sm" onclick="deleteRecord('/pages/categories.php?action=delete&id=<?= $c['id'] ?>','cat-<?= $c['id'] ?>','categoria')"><i class="fas fa-trash" style="color:var(--danger)"></i></button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal modal-sm" id="catModal">
    <div class="modal-box">
        <div class="modal-header"><div class="modal-title">Categoria</div><button class="modal-close" onclick="closeModal('catModal')"><i class="fas fa-xmark"></i></button></div>
        <div class="modal-body">
            <input type="hidden" id="catId">
            <div class="form-group"><label class="form-label">Nome *</label><input type="text" id="catName" class="form-control"></div>
            <div class="form-group"><label class="form-label">Descrição</label><input type="text" id="catDesc" class="form-control"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('catModal')">Cancelar</button>
            <button class="btn btn-primary" onclick="saveCat()"><i class="fas fa-check"></i> Salvar</button>
        </div>
    </div>
</div>
<script>
function editCat(id,name,desc){document.getElementById('catId').value=id;document.getElementById('catName').value=name;document.getElementById('catDesc').value=desc;openModal('catModal');}
async function saveCat(){const res=await apiCall(BASE_PATH+'/pages/categories.php?action=save',{id:document.getElementById('catId').value||null,name:document.getElementById('catName').value,description:document.getElementById('catDesc').value});if(res.success){showToast(res.message,'success');closeModal('catModal');setTimeout(()=>location.reload(),800);}else showToast(res.message,'error');}
</script>
<?php include __DIR__.'/../includes/footer.php'; ?>
