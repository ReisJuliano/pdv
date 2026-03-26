<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();
$pageTitle = 'Entrada de Estoque';
$db = getDB();

if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';

    if ($action === 'search') {
        $term = $_GET['term'] ?? '';
        $stmt = $db->prepare("SELECT id,code,name,barcode,cost_price,stock_quantity,unit FROM products WHERE active=1 AND (name LIKE ? OR code=? OR barcode=?) LIMIT 10");
        $stmt->execute(["%$term%",$term,$term]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($action === 'save') {
        $data = json_decode(file_get_contents('php://input'), true);
        $items = $data['items'] ?? [];
        if (!$items) { echo json_encode(['success'=>false,'message'=>'Sem itens']); exit; }

        $db->beginTransaction();
        try {
            $num = 'ENT-'.date('Ymd').'-'.str_pad($db->query("SELECT COUNT(*)+1 FROM purchases WHERE DATE(created_at)=CURDATE()")->fetchColumn(),4,'0',STR_PAD_LEFT);
            $total = array_sum(array_map(fn($i) => $i['qty'] * $i['cost'], $items));
            $stmt = $db->prepare("INSERT INTO purchases (purchase_number,supplier_id,user_id,total,notes,purchase_date,status) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$num, $data['supplier_id']??null, $_SESSION['user_id'], $total, $data['notes']??'', $data['date']??date('Y-m-d'), 'recebido']);
            $purchId = $db->lastInsertId();

            foreach ($items as $it) {
            $db->prepare("INSERT INTO purchase_items (purchase_id,product_id,quantity,unit_cost,total) VALUES (?,?,?,?,?)")->execute([$purchId,$it['id'],intval($it['qty']),$it['cost'],intval($it['qty'])*$it['cost']]);
$db->prepare("UPDATE products SET stock_quantity=stock_quantity+?, cost_price=? WHERE id=?")->execute([intval($it['qty']),$it['cost'],$it['id']]);
$db->prepare("INSERT INTO stock_movements (product_id,type,quantity,unit_cost,total_cost,reference,user_id) VALUES (?,?,?,?,?,?,?)")->execute([$it['id'],'entrada',intval($it['qty']),$it['cost'],intval($it['qty'])*$it['cost'],$num,$_SESSION['user_id']]);
            }

            $db->commit();
            echo json_encode(['success'=>true,'message'=>"Entrada registrada! $num — $".number_format($total,2)]);
        } catch(Exception $e) {
            $db->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }
    exit;
}

$suppliers = $db->query("SELECT * FROM suppliers WHERE active=1 ORDER BY name")->fetchAll();
$recentEntries = $db->query("SELECT p.*,s.name as sup_name,u.name as user_name FROM purchases p LEFT JOIN suppliers s ON p.supplier_id=s.id LEFT JOIN users u ON p.user_id=u.id ORDER BY p.created_at DESC LIMIT 10")->fetchAll();

include __DIR__.'/../includes/header.php';
?>

<div class="grid-2" style="align-items:start">
    <!-- Entry form -->
    <div style="display:flex;flex-direction:column;gap:16px">
        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fas fa-arrow-down"></i> Nova Entrada de Estoque</div></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Fornecedor</label>
                        <select id="entSupplier" class="form-control">
                            <option value="">Selecione...</option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Data</label>
                        <input type="date" id="entDate" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Observações / Nº NF</label>
                    <input type="text" id="entNotes" class="form-control" placeholder="Número da nota fiscal, observações...">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><div class="card-title"><i class="fas fa-search"></i> Buscar Produto</div></div>
            <div class="card-body">
                <div style="display:flex;gap:8px">
                    <div class="search-bar" style="flex:1">
                        <i class="fas fa-barcode"></i>
                        <input type="text" id="entSearch" class="form-control" placeholder="Bipe o EAN ou nome do produto..." autocomplete="off" oninput="searchEntProduct()" onkeydown="if(event.key==='Enter') searchEntProduct(true)">
                    </div>
                </div>
                <div id="entResults" style="display:none;margin-top:8px;border:1px solid var(--border);border-radius:8px;overflow:hidden;max-height:220px;overflow-y:auto"></div>
            </div>
        </div>

        <!-- Items list -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-list"></i> Itens da Entrada</div>
                <span id="entItemCount" style="font-size:13px;color:var(--text-muted)">0 itens</span>
            </div>
            <div id="entItemsArea">
                <div class="empty-state" style="padding:32px">
                    <div class="empty-icon" style="font-size:32px"><i class="fas fa-boxes-stacked"></i></div>
                    <div class="empty-title">Adicione produtos</div>
                </div>
            </div>
            <div id="entTotalBar" style="display:none;padding:14px 20px;background:var(--bg);border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
                <span style="font-weight:700;color:var(--text-secondary)">Total da Entrada:</span>
                <span id="entTotal" style="font-size:20px;font-weight:800;color:var(--primary)">R$ 0,00</span>
            </div>
        </div>

        <button class="btn btn-success btn-block btn-lg" onclick="saveEntry()" id="entSaveBtn" disabled>
            <i class="fas fa-check"></i> Confirmar Entrada de Estoque
        </button>
    </div>

    <!-- Recent entries -->
    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fas fa-history"></i> Entradas Recentes</div></div>
        <?php if ($recentEntries): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Nº</th><th>Data</th><th>Fornecedor</th><th>Total</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($recentEntries as $e): ?>
                <tr>
                    <td><code style="font-size:12px"><?= $e['purchase_number'] ?></code></td>
                    <td style="font-size:12px"><?= formatDate($e['purchase_date']) ?></td>
                    <td class="td-name"><?= htmlspecialchars($e['sup_name'] ?: '—') ?></td>
                    <td><strong><?= formatMoney($e['total']) ?></strong></td>
                    <td><span class="badge badge-success">Recebido</span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px">Nenhuma entrada registrada</div>
        <?php endif; ?>
    </div>
</div>

<script>
let entItems = [];
let searchTm = null;

document.getElementById('entSearch').addEventListener('input', () => {
    clearTimeout(searchTm);
    searchTm = setTimeout(() => searchEntProduct(false), 200);
});

async function searchEntProduct(exact = false) {
    const term = document.getElementById('entSearch').value.trim();
    if (!term) return;
    const results = await apiCall(`${BASE_PATH}/pages/stock_in.php?action=search&term=${encodeURIComponent(term)}`);
    if (!Array.isArray(results)) return;
    if (exact && results.length === 1) { addEntItem(results[0]); document.getElementById('entSearch').value=''; document.getElementById('entResults').style.display='none'; return; }
    const el = document.getElementById('entResults');
    if (!results.length) { el.innerHTML='<div style="padding:12px;color:var(--text-muted);font-size:13px">Não encontrado</div>'; el.style.display='block'; return; }
    el.innerHTML = results.map(p=>`
        <div onclick="addEntItem(${JSON.stringify(p).replace(/"/g,'&quot;')})" style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border-light);display:flex;justify-content:space-between" onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background=''">
            <div><div style="font-weight:600;font-size:13px">${p.name}</div><div style="font-size:11px;color:var(--text-muted)">Estoque: ${p.stock_quantity} ${p.unit} · Custo: ${formatMoney(p.cost_price)}</div></div>
        </div>`).join('');
    el.style.display='block';
}

function addEntItem(product) {
    const idx = entItems.findIndex(i=>i.id===product.id);
    if (idx>=0) { entItems[idx].qty = parseInt(entItems[idx].qty) + 1; }
    else { entItems.push({id:product.id,name:product.name,unit:product.unit,qty:1,cost:parseFloat(product.cost_price)||0}); }
    renderEntItems();
    document.getElementById('entSearch').focus();
}

function renderEntItems() {
    const area = document.getElementById('entItemsArea');
    const totalBar = document.getElementById('entTotalBar');
    const saveBtn = document.getElementById('entSaveBtn');
    const countEl = document.getElementById('entItemCount');

    if (!entItems.length) {
        area.innerHTML='<div class="empty-state" style="padding:32px"><div class="empty-icon" style="font-size:32px"><i class="fas fa-boxes-stacked"></i></div><div class="empty-title">Adicione produtos</div></div>';
        totalBar.style.display='none';
        saveBtn.disabled=true;
        countEl.textContent='0 itens';
        return;
    }

    area.innerHTML=`<div class="table-wrapper"><table><thead><tr><th>Produto</th><th style="width:110px">Qtd</th><th style="width:130px">Custo Unit.</th><th style="width:110px">Total</th><th style="width:36px"></th></tr></thead><tbody>
        ${entItems.map((it,i)=>`<tr>
            <td class="td-name">${it.name}</td>
            <td><input type="number" value="${it.qty}" min="1" step="1" class="form-control" style="width:80px;text-align:center" onchange="entQty(${i},this.value)"></td>
            <td><input type="number" value="${it.cost}" min="0" step="0.01" class="form-control" style="width:100px" onchange="entCost(${i},this.value)"></td>
            <td style="font-weight:700">${formatMoney(it.qty*it.cost)}</td>
            <td><button class="btn btn-ghost btn-sm" onclick="entRemove(${i})"><i class="fas fa-xmark" style="color:var(--danger)"></i></button></td>
        </tr>`).join('')}
    </tbody></table></div>`;

    const total = entItems.reduce((s,i)=>s+i.qty*i.cost,0);
    document.getElementById('entTotal').textContent = formatMoney(total);
    totalBar.style.display='flex';
    saveBtn.disabled=false;
    countEl.textContent=entItems.length+' item(ns)';
}

function entQty(i,v){ entItems[i].qty=parseInt(v)||1; renderEntItems(); }
function entCost(i,v){ entItems[i].cost=parseFloat(v)||0; renderEntItems(); }
function entRemove(i){ entItems.splice(i,1); renderEntItems(); }

async function saveEntry() {
    if (!entItems.length) return;
    const btn = document.getElementById('entSaveBtn');
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Salvando...';
    const res = await apiCall(BASE_PATH+'/pages/stock_in.php?action=save', {
        items: entItems,
        supplier_id: document.getElementById('entSupplier').value||null,
        date: document.getElementById('entDate').value,
        notes: document.getElementById('entNotes').value
    });
    btn.innerHTML='<i class="fas fa-check"></i> Confirmar Entrada de Estoque';
    if (res.success) { showToast(res.message,'success'); entItems=[]; renderEntItems(); setTimeout(()=>location.reload(),1200); }
    else { showToast(res.message,'error'); btn.disabled=false; }
}

document.addEventListener('click',e=>{ if(!e.target.closest('#entSearch')&&!e.target.closest('#entResults')) document.getElementById('entResults').style.display='none'; });
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>
