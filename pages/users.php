<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();
if (currentUser()['role'] !== 'admin') { redirect('index.php'); exit; }
$pageTitle = 'Usuários';
$db = getDB();

if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';
    if ($action === 'save') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id   = $data['id'] ?? null;
        if (!$data['name'] || !$data['username']) {
            echo json_encode(['success'=>false,'message'=>'Nome e usuário são obrigatórios']); exit;
        }
        // Username só letras, números, underscore
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $data['username'])) {
            echo json_encode(['success'=>false,'message'=>'Usuário só pode conter letras, números e _']); exit;
        }
        // Verifica duplicata
        $stmt = $db->prepare("SELECT id FROM users WHERE username=? AND id!=?");
        $stmt->execute([$data['username'], $id ?? 0]);
        if ($stmt->fetch()) { echo json_encode(['success'=>false,'message'=>'Nome de usuário já existe']); exit; }

        if ($id) {
            if ($data['password']) {
                if (strlen($data['password']) < 6) { echo json_encode(['success'=>false,'message'=>'Senha deve ter mínimo 6 caracteres']); exit; }
                $db->prepare("UPDATE users SET name=?,username=?,role=?,password=? WHERE id=?")
                   ->execute([$data['name'],$data['username'],$data['role'],password_hash($data['password'],PASSWORD_DEFAULT),$id]);
            } else {
                $db->prepare("UPDATE users SET name=?,username=?,role=? WHERE id=?")
                   ->execute([$data['name'],$data['username'],$data['role'],$id]);
            }
            echo json_encode(['success'=>true,'message'=>'Usuário atualizado!']);
        } else {
            if (!$data['password']) { echo json_encode(['success'=>false,'message'=>'Senha obrigatória para novo usuário']); exit; }
            if (strlen($data['password']) < 6) { echo json_encode(['success'=>false,'message'=>'Senha deve ter mínimo 6 caracteres']); exit; }
            $db->prepare("INSERT INTO users (name,username,password,role) VALUES (?,?,?,?)")
               ->execute([$data['name'],$data['username'],password_hash($data['password'],PASSWORD_DEFAULT),$data['role']]);
            echo json_encode(['success'=>true,'message'=>'Usuário criado!']);
        }
        exit;
    }
    if ($action === 'toggle') {
        $id = intval($_GET['id']);
        if ($id == $_SESSION['user_id']) { echo json_encode(['success'=>false,'message'=>'Não pode desativar a si mesmo']); exit; }
        $db->prepare("UPDATE users SET active=NOT active WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true,'message'=>'Status alterado.']);
        exit;
    }
    exit;
    if ($action === 'reset') {
        $id = intval($_GET['id']);
        $db->prepare("UPDATE users SET password=?, must_change_password=1 WHERE id=?")
           ->execute([password_hash('admin', PASSWORD_DEFAULT), $id]);
        echo json_encode(['success'=>true,'message'=>'Senha redefinida para "admin". O usuário deverá trocar no próximo acesso.']);
        exit;
    }
    exit;
}

$users = $db->query("SELECT * FROM users ORDER BY name")->fetchAll();
include __DIR__.'/../includes/header.php';
?>

<div class="actions-bar">
    <div class="card-title"><i class="fas fa-user-gear"></i> Usuários do Sistema</div>
    <button class="btn btn-primary" onclick="openUserModal()"><i class="fas fa-plus"></i> Novo Usuário</button>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Nome</th><th>Usuário</th><th>Perfil</th><th>Status</th><th>Cadastro</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
            <tr id="usr-<?= $u['id'] ?>">
                <td>
                    <div style="display:flex;align-items:center;gap:10px">
                        <div style="width:32px;height:32px;background:var(--primary-light);color:var(--primary);border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px">
                            <?= strtoupper(substr($u['name'],0,2)) ?>
                        </div>
                        <span class="td-name"><?= htmlspecialchars($u['name']) ?></span>
                    </div>
                </td>
                <td>
                    <code style="background:var(--bg);padding:3px 8px;border-radius:6px;font-size:12px">
                        <?= htmlspecialchars($u['username']) ?>
                    </code>
                </td>
                <td><span class="badge <?= $u['role']==='admin'?'badge-primary':'badge-secondary' ?>"><?= $u['role']==='admin'?'Administrador':'Operador' ?></span></td>
                <td><span class="badge <?= $u['active']?'badge-success':'badge-danger' ?>"><?= $u['active']?'Ativo':'Inativo' ?></span></td>
                <td style="font-size:12px;color:var(--text-muted)"><?= formatDate($u['created_at']) ?></td>
                <td>
                    <div style="display:flex;gap:4px">
                        <button class="btn btn-ghost btn-sm" title="Editar" onclick="openUserModal(<?= htmlspecialchars(json_encode($u)) ?>)">
                            <i class="fas fa-pen"></i>
                        </button>
                        <button class="btn btn-ghost btn-sm" title="Redefinir senha" onclick="resetPassword(<?= $u['id'] ?>, '<?= htmlspecialchars($u['name']) ?>')">
    <i class="fas fa-key" style="color:var(--warning)"></i>
</button>
                        <?php if ($u['id'] != $_SESSION['user_id']): ?>
                        <button class="btn btn-ghost btn-sm" title="<?= $u['active']?'Desativar':'Ativar' ?>" onclick="toggleUser(<?= $u['id'] ?>)">
                            <i class="fas fa-<?= $u['active']?'ban':'circle-check' ?>" style="color:<?= $u['active']?'var(--danger)':'var(--success)' ?>"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal modal-sm" id="userModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title" id="userModalTitle">Novo Usuário</div>
            <button class="modal-close" onclick="closeModal('userModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="uId">
            <div class="form-group">
                <label class="form-label">Nome completo *</label>
                <input type="text" id="uName" class="form-control" placeholder="Ex: João Silva">
            </div>
            <div class="form-group">
                <label class="form-label">Usuário (login) *</label>
                <input type="text" id="uUsername" class="form-control" placeholder="Ex: joao"
                       oninput="this.value=this.value.replace(/[^a-zA-Z0-9_]/g,'').toLowerCase()"
                       autocomplete="off">
                <div class="form-hint">Só letras, números e _ (sem espaços)</div>
            </div>
            <div class="form-group">
                <label class="form-label">Perfil</label>
                <select id="uRole" class="form-control">
                    <option value="operator">Operador</option>
                    <option value="admin">Administrador</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">
                    Senha
                    <span id="passHint" style="color:var(--text-muted);font-weight:400;font-size:12px"> — deixe em branco para não alterar</span>
                </label>
                <input type="password" id="uPass" class="form-control" placeholder="Mínimo 6 caracteres" autocomplete="new-password">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('userModal')">Cancelar</button>
            <button class="btn btn-primary" onclick="saveUser()"><i class="fas fa-check"></i> Salvar</button>
        </div>
    </div>
</div>

<script>
function openUserModal(u = null) {
    document.getElementById('userModalTitle').textContent = u ? 'Editar Usuário' : 'Novo Usuário';
    document.getElementById('passHint').style.display = u ? '' : 'none';
    document.getElementById('uId').value       = u?.id       ?? '';
    document.getElementById('uName').value     = u?.name     ?? '';
    document.getElementById('uUsername').value = u?.username ?? '';
    document.getElementById('uRole').value     = u?.role     ?? 'operator';
    document.getElementById('uPass').value     = '';
    openModal('userModal');
    setTimeout(() => document.getElementById(u ? 'uPass' : 'uName').focus(), 100);
}

async function saveUser() {
    const payload = {
        id:       document.getElementById('uId').value || null,
        name:     document.getElementById('uName').value.trim(),
        username: document.getElementById('uUsername').value.trim(),
        role:     document.getElementById('uRole').value,
        password: document.getElementById('uPass').value,
    };
    if (!payload.name || !payload.username) { showToast('Nome e usuário são obrigatórios', 'warning'); return; }
    const res = await apiCall(`${BASE_PATH}/pages/users.php?action=save`, payload);
    if (res.success) { showToast(res.message, 'success'); closeModal('userModal'); setTimeout(() => location.reload(), 800); }
    else showToast(res.message, 'error');
}

async function toggleUser(id) {
    const res = await apiCall(`${BASE_PATH}/pages/users.php?action=toggle&id=${id}`);
    if (res.success) { showToast(res.message, 'success'); setTimeout(() => location.reload(), 600); }
    else showToast(res.message, 'error');
}

async function resetPassword(id, nome) {
    if (!confirm(`Redefinir senha de "${nome}" para "admin"? O usuário deverá trocar no próximo acesso.`)) return;
    const res = await apiCall(`${BASE_PATH}/pages/users.php?action=reset&id=${id}`);
    if (res.success) showToast(res.message, 'success');
    else showToast(res.message, 'error');
}

</script>

<?php include __DIR__.'/../includes/footer.php'; ?>
