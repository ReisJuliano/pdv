<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();
$pageTitle = 'Ajuste de Estoque';
$db = getDB();

if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);

    $id  = intval($data['product_id'] ?? 0);
    $qty = floatval($data['quantity'] ?? 0);
    $notes = $data['notes'] ?? '';
    $type = $data['type'] ?? 'ajuste';

    if (!$id) { echo json_encode(['success'=>false,'message'=>'Produto inválido']); exit; }

    $prod = $db->prepare("SELECT * FROM products WHERE id=?")->execute([$id]) ? $db->query("SELECT * FROM products WHERE id=$id")->fetch() : null;
    if (!$prod) { echo json_encode(['success'=>false,'message'=>'Produto não encontrado']); exit; }

    $oldQty = $prod['stock_quantity'];
    $diff = $type === 'ajuste' ? ($qty - $oldQty) : ($type === 'saida' ? -abs($qty) : abs($qty));

    $db->prepare("UPDATE products SET stock_quantity=? WHERE id=?")->execute([$type === 'ajuste' ? $qty : $oldQty + $diff, $id]);
    $db->prepare("INSERT INTO stock_movements (product_id,type,quantity,notes,user_id) VALUES (?,?,?,?,?)")->execute([$id,$type,abs($diff),$notes,$_SESSION['user_id']]);

    echo json_encode(['success'=>true,'message'=>'Estoque ajustado! Anterior: '.$oldQty.' → Novo: '.($type==='ajuste'?$qty:$oldQty+$diff)]);
    exit;
}

$products = $db->query("SELECT p.*,c.name as cat_name FROM products p LEFT JOIN categories c ON p.category_id=c.id WHERE p.active=1 ORDER BY p.name")->fetchAll();
$categories = $db->query("SELECT * FROM categories WHERE active=1 ORDER BY name")->fetchAll();

include __DIR__.'/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-sliders"></i> Ajuste de Estoque</div>
        <a href="<?= url('pages/stock_history.php') ?>" class="btn btn-outline btn-sm"><i class="fas fa-timeline"></i> Ver Histórico</a>
    </div>
    <div class="card-body" style="padding:14px 16px">
        <div class="search-bar" style="margin-bottom:12px">
            <i class="fas fa-search"></i>
            <input type="text" id="adjSearch" class="form-control" placeholder="Filtrar produtos..." oninput="filterAdj()" style="max-width:320px">
        </div>
    </div>
    <div class="table-wrapper">
        <table id="adjTable">
            <thead>
                <tr><th>Código</th><th>Produto</th><th>Categoria</th><th>Estoque Atual</th><th>Mínimo</th><th>Un.</th><th>Ajustar</th></tr>
            </thead>
            <tbody>
            <?php foreach ($products as $p):
                $sc = $p['stock_quantity'] <= 0 ? 'stock-zero' : ($p['stock_quantity'] <= $p['min_stock'] ? 'stock-low' : 'stock-ok');
            ?>
            <tr>
                <td><code style="font-size:12px"><?= htmlspecialchars($p['code']) ?></code></td>
                <td class="td-name"><?= htmlspecialchars($p['name']) ?></td>
                <td><span class="badge badge-secondary"><?= htmlspecialchars($p['cat_name'] ?: '—') ?></span></td>
                <td class="<?= $sc ?>"><strong><?= number_format($p['stock_quantity'],3,'.','.') ?></strong></td>
                <td style="color:var(--text-muted)"><?= number_format($p['min_stock'],0) ?></td>
                <td><?= $p['unit'] ?></td>
                <td>
                    <button class="btn btn-primary btn-sm" onclick='openAdj(<?= json_encode(['id'=>$p['id'],'name'=>$p['name'],'stock'=>$p['stock_quantity'],'unit'=>$p['unit']]) ?>)'>
                        <i class="fas fa-pen"></i> Ajustar
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Adjust Modal -->
<div class="modal modal-sm" id="adjModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Ajuste de Estoque</div>
            <button class="modal-close" onclick="closeModal('adjModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="adjProdId">
            <div style="background:var(--bg);border-radius:8px;padding:12px;margin-bottom:16px">
                <div id="adjProdName" style="font-weight:700;font-size:15px;margin-bottom:4px"></div>
                <div style="font-size:13px;color:var(--text-secondary)">Estoque atual: <strong id="adjCurrent" style="color:var(--primary)"></strong></div>
            </div>
            <div class="form-group">
                <label class="form-label">Tipo de Ajuste</label>
                <select id="adjType" class="form-control" onchange="updateAdjLabel()">
                    <option value="ajuste">Ajuste direto (definir quantidade)</option>
                    <option value="entrada">Adicionar ao estoque</option>
                    <option value="saida">Retirar do estoque</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" id="adjLabel">Nova quantidade em estoque</label>
                <input type="number" id="adjQty" class="form-control" step="1" min="0" placeholder="0">
            </div>
            <div class="form-group">
                <label class="form-label">Motivo / Observação</label>
                <input type="text" id="adjNotes" class="form-control" placeholder="Ex: Contagem física, quebra, etc.">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('adjModal')">Cancelar</button>
            <button class="btn btn-primary" onclick="saveAdj()"><i class="fas fa-check"></i> Confirmar Ajuste</button>
        </div>
    </div>
</div>

<script>
function openAdj(prod) {
    document.getElementById('adjProdId').value = prod.id;
    document.getElementById('adjProdName').textContent = prod.name;
    document.getElementById('adjCurrent').textContent = prod.stock + ' ' + prod.unit;
    document.getElementById('adjQty').value = prod.stock;
    document.getElementById('adjNotes').value = '';
    document.getElementById('adjType').value = 'ajuste';
    updateAdjLabel();
    openModal('adjModal');
    document.getElementById('adjQty').focus();
}

function updateAdjLabel() {
    const labels = { ajuste:'Nova quantidade em estoque', entrada:'Quantidade a adicionar', saida:'Quantidade a retirar' };
    document.getElementById('adjLabel').textContent = labels[document.getElementById('adjType').value];
}

async function saveAdj() {
    const res = await apiCall(BASE_PATH+'/pages/stock_adjust.php', {
        product_id: document.getElementById('adjProdId').value,
        quantity: parseFloat(document.getElementById('adjQty').value) || 0,
        type: document.getElementById('adjType').value,
        notes: document.getElementById('adjNotes').value
    });
    if (res.success) { showToast(res.message,'success'); closeModal('adjModal'); setTimeout(()=>location.reload(),800); }
    else showToast(res.message,'error');
}

function filterAdj() {
    const term = document.getElementById('adjSearch').value.toLowerCase();
    document.querySelectorAll('#adjTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
    });
}
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>
