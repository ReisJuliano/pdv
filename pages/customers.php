<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();
$pageTitle = 'Clientes';
$db = getDB();

if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';
    if ($action === 'save') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;
        $fields = ['name','cpf_cnpj','phone','email','address','credit_limit'];
        $vals = []; foreach ($fields as $f) $vals[$f] = $data[$f] ?? null;
        if (!$vals['name']) { echo json_encode(['success'=>false,'message'=>'Nome obrigatório']); exit; }
        if ($id) {
            $db->prepare("UPDATE customers SET ".implode(',',array_map(fn($k)=>"$k=:$k",$fields))." WHERE id=:id")->execute(array_merge($vals,['id'=>$id]));
            echo json_encode(['success'=>true,'message'=>'Cliente atualizado!']);
        } else {
            $db->prepare("INSERT INTO customers (".implode(',',$fields).") VALUES (".implode(',',array_map(fn($k)=>":$k",$fields)).")")->execute($vals);
            echo json_encode(['success'=>true,'message'=>'Cliente cadastrado!']);
        }
        exit;
    }
    if ($action === 'get') {
        $stmt = $db->prepare("SELECT * FROM customers WHERE id=?"); $stmt->execute([$_GET['id']]);
        echo json_encode($stmt->fetch() ?: []);
        exit;
    }
    if ($action === 'delete') {
        $db->prepare("UPDATE customers SET active=0 WHERE id=?")->execute([$_GET['id']]);
        echo json_encode(['success'=>true,'message'=>'Cliente removido.']);
        exit;
    }
    exit;
}

$customers = $db->query("SELECT * FROM customers WHERE active=1 ORDER BY name")->fetchAll();
include __DIR__.'/../includes/header.php';
?>

<div class="actions-bar">
    <div class="actions-left">
        <div class="search-bar">
            <i class="fas fa-search"></i>
            <input type="text" id="s" class="form-control" placeholder="Buscar cliente..." oninput="filterTable('s','tbl')" style="width:260px">
        </div>
    </div>
    <button class="btn btn-primary" onclick="openModal('custModal')"><i class="fas fa-plus"></i> Novo Cliente</button>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-users"></i> Clientes (<?= count($customers) ?>)</div></div>
    <?php if ($customers): ?>
    <div class="table-wrapper">
        <table id="tbl">
            <thead><tr><th>Nome</th><th>CPF/CNPJ</th><th>Telefone</th><th>E-mail</th><th>Limite Fiado</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($customers as $c): ?>
            <tr id="cust-<?= $c['id'] ?>">
                <td class="td-name"><?= htmlspecialchars($c['name']) ?></td>
                <td style="font-family:'JetBrains Mono',monospace;font-size:12px"><?= htmlspecialchars($c['cpf_cnpj'] ?: '—') ?></td>
                <td><?= htmlspecialchars($c['phone'] ?: '—') ?></td>
                <td style="font-size:12px"><?= htmlspecialchars($c['email'] ?: '—') ?></td>
                <td><?= $c['credit_limit'] > 0 ? formatMoney($c['credit_limit']) : '—' ?></td>
                <td>
                    <div style="display:flex;gap:4px">
                        <button class="btn btn-ghost btn-sm" onclick="editCust(<?= $c['id'] ?>)"><i class="fas fa-pen"></i></button>
                        <button class="btn btn-ghost btn-sm" onclick="deleteRecord('/pages/customers.php?action=delete&id=<?= $c['id'] ?>','cust-<?= $c['id'] ?>','cliente')"><i class="fas fa-trash" style="color:var(--danger)"></i></button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon"><i class="fas fa-users"></i></div><div class="empty-title">Nenhum cliente</div><button class="btn btn-primary" onclick="openModal('custModal')"><i class="fas fa-plus"></i> Novo</button></div>
    <?php endif; ?>
</div>

<div class="modal modal-md" id="custModal">
    <div class="modal-box">
        <div class="modal-header"><div class="modal-title">Cliente</div><button class="modal-close" onclick="closeModal('custModal')"><i class="fas fa-xmark"></i></button></div>
        <div class="modal-body">
            <input type="hidden" id="cId">
            <div class="form-group"><label class="form-label">Nome *</label><input type="text" id="cName" class="form-control" placeholder="Nome completo"></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">CPF / CNPJ</label><input type="text" id="cDoc" class="form-control" placeholder="000.000.000-00"></div>
                <div class="form-group"><label class="form-label">Telefone</label><input type="text" id="cPhone" class="form-control" placeholder="(00) 00000-0000"></div>
            </div>
            <div class="form-group"><label class="form-label">E-mail</label><input type="email" id="cEmail" class="form-control" placeholder="email@exemplo.com"></div>
            <div class="form-group"><label class="form-label">Endereço</label><input type="text" id="cAddr" class="form-control" placeholder="Rua, número, bairro..."></div>
            <div class="form-group"><label class="form-label">Limite de Crédito (Fiado)</label><input type="number" id="cLimit" class="form-control" step="0.01" min="0" placeholder="0.00"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('custModal')">Cancelar</button>
            <button class="btn btn-primary" onclick="saveCust()"><i class="fas fa-check"></i> Salvar</button>
        </div>
    </div>
</div>

<script>
function clearCust(){document.getElementById('cId').value='';document.getElementById('cName').value='';document.getElementById('cDoc').value='';document.getElementById('cPhone').value='';document.getElementById('cEmail').value='';document.getElementById('cAddr').value='';document.getElementById('cLimit').value='';}
document.getElementById('custModal').addEventListener('click',()=>{});

async function editCust(id){
    const r=await apiCall(`${BASE_PATH}/pages/customers.php?action=get&id=${id}`);
    document.getElementById('cId').value=r.id;document.getElementById('cName').value=r.name;document.getElementById('cDoc').value=r.cpf_cnpj||'';
    document.getElementById('cPhone').value=r.phone||'';document.getElementById('cEmail').value=r.email||'';document.getElementById('cAddr').value=r.address||'';document.getElementById('cLimit').value=r.credit_limit||'';
    openModal('custModal');
}
async function saveCust(){
    const res=await apiCall(BASE_PATH+'/pages/customers.php?action=save',{id:document.getElementById('cId').value||null,name:document.getElementById('cName').value,cpf_cnpj:document.getElementById('cDoc').value,phone:document.getElementById('cPhone').value,email:document.getElementById('cEmail').value,address:document.getElementById('cAddr').value,credit_limit:parseFloat(document.getElementById('cLimit').value)||0});
    if(res.success){showToast(res.message,'success');closeModal('custModal');setTimeout(()=>location.reload(),800);}else showToast(res.message,'error');
}

// Reset form when opening modal
document.querySelector('[onclick="openModal(\'custModal\')"]')?.addEventListener('click',clearCust);
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>
