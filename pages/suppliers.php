<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();
$pageTitle = 'Fornecedores';
$db = getDB();

if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';
    if ($action === 'save') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;
        $fields = ['name','cnpj','phone','email','address','contact_name'];
        $vals = []; foreach ($fields as $f) $vals[$f] = $data[$f] ?? null;
        if (!$vals['name']) { echo json_encode(['success'=>false,'message'=>'Nome obrigatório']); exit; }
        if ($id) {
            $db->prepare("UPDATE suppliers SET ".implode(',',array_map(fn($k)=>"$k=:$k",$fields))." WHERE id=:id")->execute(array_merge($vals,['id'=>$id]));
            echo json_encode(['success'=>true,'message'=>'Fornecedor atualizado!']);
        } else {
            $db->prepare("INSERT INTO suppliers (".implode(',',$fields).") VALUES (".implode(',',array_map(fn($k)=>":$k",$fields)).")")->execute($vals);
            echo json_encode(['success'=>true,'message'=>'Fornecedor cadastrado!']);
        }
        exit;
    }
    if ($action === 'get') {
        $stmt = $db->prepare("SELECT * FROM suppliers WHERE id=?"); $stmt->execute([$_GET['id']]);
        echo json_encode($stmt->fetch() ?: []);
        exit;
    }
    if ($action === 'delete') {
        $db->prepare("UPDATE suppliers SET active=0 WHERE id=?")->execute([$_GET['id']]);
        echo json_encode(['success'=>true,'message'=>'Fornecedor removido.']);
        exit;
    }
    exit;
}

$suppliers = $db->query("SELECT * FROM suppliers WHERE active=1 ORDER BY name")->fetchAll();
include __DIR__.'/../includes/header.php';
?>

<div class="actions-bar">
    <div class="actions-left">
        <div class="search-bar"><i class="fas fa-search"></i><input type="text" id="s" class="form-control" placeholder="Buscar fornecedor..." oninput="filterTable('s','tbl')" style="width:260px"></div>
    </div>
    <button class="btn btn-primary" onclick="clearSup();openModal('supModal')"><i class="fas fa-plus"></i> Novo Fornecedor</button>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-building"></i> Fornecedores (<?= count($suppliers) ?>)</div></div>
    <?php if ($suppliers): ?>
    <div class="table-wrapper">
        <table id="tbl">
            <thead><tr><th>Nome</th><th>CNPJ</th><th>Contato</th><th>Telefone</th><th>E-mail</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($suppliers as $s): ?>
            <tr id="sup-<?= $s['id'] ?>">
                <td class="td-name"><?= htmlspecialchars($s['name']) ?></td>
                <td style="font-family:'JetBrains Mono',monospace;font-size:12px"><?= htmlspecialchars($s['cnpj'] ?: '—') ?></td>
                <td><?= htmlspecialchars($s['contact_name'] ?: '—') ?></td>
                <td><?= htmlspecialchars($s['phone'] ?: '—') ?></td>
                <td style="font-size:12px"><?= htmlspecialchars($s['email'] ?: '—') ?></td>
                <td>
                    <div style="display:flex;gap:4px">
                        <button class="btn btn-ghost btn-sm" onclick="editSup(<?= $s['id'] ?>)"><i class="fas fa-pen"></i></button>
                        <button class="btn btn-ghost btn-sm" onclick="deleteRecord('/pages/suppliers.php?action=delete&id=<?= $s['id'] ?>','sup-<?= $s['id'] ?>','fornecedor')"><i class="fas fa-trash" style="color:var(--danger)"></i></button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon"><i class="fas fa-building"></i></div><div class="empty-title">Nenhum fornecedor</div><button class="btn btn-primary" onclick="clearSup();openModal('supModal')"><i class="fas fa-plus"></i> Novo</button></div>
    <?php endif; ?>
</div>

<div class="modal modal-md" id="supModal">
    <div class="modal-box">
        <div class="modal-header"><div class="modal-title">Fornecedor</div><button class="modal-close" onclick="closeModal('supModal')"><i class="fas fa-xmark"></i></button></div>
        <div class="modal-body">
            <input type="hidden" id="sId">
            <div class="form-group"><label class="form-label">Razão Social / Nome *</label><input type="text" id="sName" class="form-control"></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">CNPJ</label><input type="text" id="sCnpj" class="form-control" placeholder="00.000.000/0000-00"></div>
                <div class="form-group"><label class="form-label">Contato</label><input type="text" id="sContact" class="form-control"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Telefone</label><input type="text" id="sPhone" class="form-control"></div>
                <div class="form-group"><label class="form-label">E-mail</label><input type="email" id="sEmail" class="form-control"></div>
            </div>
            <div class="form-group"><label class="form-label">Endereço</label><input type="text" id="sAddr" class="form-control"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('supModal')">Cancelar</button>
            <button class="btn btn-primary" onclick="saveSup()"><i class="fas fa-check"></i> Salvar</button>
        </div>
    </div>
</div>
<script>
function clearSup(){['sId','sName','sCnpj','sContact','sPhone','sEmail','sAddr'].forEach(id=>document.getElementById(id).value='');}
async function editSup(id){const r=await apiCall(`${BASE_PATH}/pages/suppliers.php?action=get&id=${id}`);document.getElementById('sId').value=r.id;document.getElementById('sName').value=r.name;document.getElementById('sCnpj').value=r.cnpj||'';document.getElementById('sContact').value=r.contact_name||'';document.getElementById('sPhone').value=r.phone||'';document.getElementById('sEmail').value=r.email||'';document.getElementById('sAddr').value=r.address||'';openModal('supModal');}
async function saveSup(){const res=await apiCall(BASE_PATH+'/pages/suppliers.php?action=save',{id:document.getElementById('sId').value||null,name:document.getElementById('sName').value,cnpj:document.getElementById('sCnpj').value,contact_name:document.getElementById('sContact').value,phone:document.getElementById('sPhone').value,email:document.getElementById('sEmail').value,address:document.getElementById('sAddr').value});if(res.success){showToast(res.message,'success');closeModal('supModal');setTimeout(()=>location.reload(),800);}else showToast(res.message,'error');}
</script>
<?php include __DIR__.'/../includes/footer.php'; ?>
