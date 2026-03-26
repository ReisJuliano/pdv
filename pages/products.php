<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();

$db = getDB();
$pageTitle = 'Produtos';

// Handle API calls
if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($action === 'save') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;
        $fields = ['code','name','description','category_id','supplier_id','unit','cost_price','sale_price','min_stock','barcode'];
        $vals = [];
        foreach ($fields as $f) $vals[$f] = $data[$f] ?? null;

        if (!$vals['name']) { echo json_encode(['success'=>false,'message'=>'Nome obrigatório']); exit; }

        if ($id) {
            $sql = "UPDATE products SET ".implode(',', array_map(fn($k)=>"$k=:$k", $fields)).",updated_at=NOW() WHERE id=:id";
            $vals['id'] = $id;
            $db->prepare($sql)->execute($vals);
            echo json_encode(['success'=>true,'message'=>'Produto atualizado!']);
        } else {
            if (!$vals['code']) {
                $last = $db->query("SELECT MAX(CAST(code AS UNSIGNED)) FROM products WHERE code REGEXP '^[0-9]+$'")->fetchColumn();
                $vals['code'] = str_pad(($last ?: 0) + 1, 6, '0', STR_PAD_LEFT);
            }
            $sql = "INSERT INTO products (".implode(',', $fields).") VALUES (".implode(',', array_map(fn($k)=>":$k", $fields)).")";
            $db->prepare($sql)->execute($vals);
            echo json_encode(['success'=>true,'message'=>'Produto cadastrado!','id'=>$db->lastInsertId(),'code'=>$vals['code']]);
        }
        exit;
    }

    if ($action === 'get') {
        $stmt = $db->prepare("SELECT p.*, c.name as cat_name, s.name as sup_name FROM products p LEFT JOIN categories c ON p.category_id=c.id LEFT JOIN suppliers s ON p.supplier_id=s.id WHERE p.id=?");
        $stmt->execute([$_GET['id']]);
        echo json_encode($stmt->fetch() ?: []);
        exit;
    }

    if ($action === 'delete') {
        $id = $_GET['id'] ?? 0;
        $db->prepare("UPDATE products SET active=0 WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true,'message'=>'Produto desativado.']);
        exit;
    }

    if ($action === 'search_barcode') {
        $code = $_GET['code'] ?? '';
        $stmt = $db->prepare("SELECT * FROM products WHERE (barcode=? OR code=?) AND active=1 LIMIT 1");
        $stmt->execute([$code, $code]);
        $p = $stmt->fetch();
        echo json_encode($p ?: ['found'=>false]);
        exit;
    }
    exit;
}

// Load data
$search = $_GET['q'] ?? '';
$catFilter = $_GET['cat'] ?? '';
$sql = "SELECT p.*, c.name as cat_name FROM products p LEFT JOIN categories c ON p.category_id=c.id WHERE p.active=1";
$params = [];
if ($search) { $sql .= " AND (p.name LIKE ? OR p.code LIKE ? OR p.barcode LIKE ?)"; $params = ["%$search%","%$search%","%$search%"]; }
if ($catFilter) { $sql .= " AND p.category_id=?"; $params[] = $catFilter; }
$sql .= " ORDER BY p.name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $db->query("SELECT * FROM categories WHERE active=1 ORDER BY name")->fetchAll();
$suppliers  = $db->query("SELECT * FROM suppliers WHERE active=1 ORDER BY name")->fetchAll();

include __DIR__.'/../includes/header.php';
?>

<div class="actions-bar">
    <div class="actions-left">
        <div class="search-bar">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" class="form-control" placeholder="Buscar por nome, código ou EAN..." style="width:280px" value="<?= htmlspecialchars($search) ?>" onkeyup="liveSearch()">
        </div>
        <select class="form-control" id="catFilter" style="width:160px" onchange="applyFilter()">
            <option value="">Todas categorias</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $catFilter==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="actions-right">
        <button class="btn btn-primary" onclick="openProductModal()">
            <i class="fas fa-plus"></i> Novo Produto
        </button>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-boxes-stacked"></i> Produtos (<?= count($products) ?>)</div>
    </div>
    <?php if ($products): ?>
    <div class="table-wrapper">
        <table id="productsTable">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>EAN</th>
                    <th>Produto</th>
                    <th>Categoria</th>
                    <th>Custo</th>
                    <th>Venda</th>
                    <th>Margem</th>
                    <th>Estoque</th>
                    <th>Un.</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $p):
                $margin = $p['cost_price'] > 0 ? (($p['sale_price'] - $p['cost_price']) / $p['cost_price'] * 100) : 0;
                $profit = $p['sale_price'] - $p['cost_price'];
                $stockClass = $p['stock_quantity'] <= 0 ? 'stock-zero' : ($p['stock_quantity'] <= $p['min_stock'] ? 'stock-low' : 'stock-ok');
            ?>
            <tr id="prod-<?= $p['id'] ?>">
                <td><code style="font-size:12px"><?= htmlspecialchars($p['code']) ?></code></td>
                <td style="font-size:12px;color:var(--text-muted)"><?= $p['barcode'] ? '<i class="fas fa-barcode" style="margin-right:4px"></i>'.htmlspecialchars($p['barcode']) : '-' ?></td>
                <td class="td-name"><?= htmlspecialchars($p['name']) ?></td>
                <td><span class="badge badge-secondary"><?= htmlspecialchars($p['cat_name'] ?: 'Sem categoria') ?></span></td>
                <td><?= formatMoney($p['cost_price']) ?></td>
                <td><strong><?= formatMoney($p['sale_price']) ?></strong></td>
                <td class="<?= $profit >= 0 ? 'profit-positive' : 'profit-negative' ?>"><?= number_format($margin,1) ?>%</td>
                <td class="<?= $stockClass ?>"><strong><?= number_format($p['stock_quantity'],0,'.','.') ?></strong>
                    <?php if ($p['stock_quantity'] <= $p['min_stock']): ?><i class="fas fa-triangle-exclamation" style="margin-left:4px;font-size:11px"></i><?php endif; ?>
                </td>
                <td style="color:var(--text-muted)"><?= htmlspecialchars($p['unit']) ?></td>
                <td>
                    <div style="display:flex;gap:4px">
                        <button class="btn btn-ghost btn-sm" title="Editar" onclick="editProduct(<?= $p['id'] ?>)"><i class="fas fa-pen"></i></button>
                        <button class="btn btn-ghost btn-sm" title="Excluir" onclick="deleteRecord('/pages/products.php?action=delete&id=<?= $p['id'] ?>','prod-<?= $p['id'] ?>','produto')"><i class="fas fa-trash" style="color:var(--danger)"></i></button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-box-open"></i></div>
        <div class="empty-title">Nenhum produto encontrado</div>
        <div class="empty-text">Cadastre o primeiro produto para começar</div>
        <button class="btn btn-primary" onclick="openProductModal()"><i class="fas fa-plus"></i> Novo Produto</button>
    </div>
    <?php endif; ?>
</div>

<!-- Product Modal -->
<div class="modal modal-lg" id="productModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title" id="modalTitleText">Novo Produto</div>
            <button class="modal-close" onclick="closeModal('productModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="prodId">

            <!-- Identificação -->
            <div style="background:var(--bg);border-radius:10px;padding:16px;margin-bottom:16px">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);margin-bottom:12px">Identificação</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Código Interno</label>
                        <input type="text" id="prodCode" class="form-control" placeholder="Auto">
                        <div class="form-hint">Gerado automaticamente se vazio</div>
                    </div>
                    <div class="form-group" style="flex:2">
                        <label class="form-label">Código de Barras / EAN <i class="fas fa-barcode" style="color:var(--primary)"></i></label>
                        <div style="display:flex;gap:8px;align-items:center">
                            <input type="text" id="prodBarcode" class="form-control" placeholder="Digite ou bipie o código EAN" style="font-family:'JetBrains Mono',monospace">
                            <button type="button" class="btn btn-outline btn-sm" onclick="focusBarcode()" title="Focar campo para leitura do leitor"><i class="fas fa-barcode"></i></button>
                        </div>
                        <div class="form-hint">Use o leitor de código de barras ou digitar manualmente</div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Nome do Produto <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="prodName" class="form-control" placeholder="Ex: Cerveja Brahma Lata 350ml">
                </div>
                <div class="form-group">
                    <label class="form-label">Descrição</label>
                    <textarea id="prodDesc" class="form-control" rows="2" placeholder="Descrição adicional..."></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Categoria</label>
                        <select id="prodCategory" class="form-control">
                            <option value="">Selecione...</option>
                            <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fornecedor</label>
                        <select id="prodSupplier" class="form-control">
                            <option value="">Selecione...</option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Unidade</label>
                        <select id="prodUnit" class="form-control">
                            <option value="UN">UN (Unidade)</option>
                            <option value="CX">CX (Caixa)</option>
                            <option value="FD">FD (Fardo)</option>
                            <option value="KG">KG</option>
                            <option value="L">L (Litro)</option>
                            <option value="DZ">DZ (Dúzia)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Preços -->
            <div style="background:var(--bg);border-radius:10px;padding:16px;margin-bottom:16px">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);margin-bottom:12px">Preços e Margem</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Custo (R$)</label>
                        <input type="number" id="prodCost" class="form-control" step="0.01" min="0" placeholder="0.00" oninput="updateMarginPreview()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Preço de Venda (R$)</label>
                        <input type="number" id="prodPrice" class="form-control" step="0.01" min="0" placeholder="0.00" oninput="updateMarginPreview()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Estoque Mínimo</label>
                        <input type="number" id="prodMinStock" class="form-control" step="1" min="0" placeholder="0">
                    </div>
                </div>
                <!-- Margin preview -->
                <div style="background:white;border:1px solid var(--border);border-radius:8px;padding:12px;display:flex;gap:24px">
                    <div style="text-align:center;flex:1">
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted)">Lucro Unitário</div>
                        <div id="previewProfit" style="font-size:18px;font-weight:800;margin-top:4px">R$ 0,00</div>
                    </div>
                    <div style="text-align:center;flex:1">
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted)">Margem s/ Custo</div>
                        <div id="previewMargin" style="font-size:18px;font-weight:800;margin-top:4px">0,0%</div>
                    </div>
                    <div style="text-align:center;flex:1">
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted)">Markup</div>
                        <div id="previewMarkup" style="font-size:18px;font-weight:800;margin-top:4px">0,0%</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('productModal')">Cancelar</button>
            <button class="btn btn-primary" onclick="saveProduct()"><i class="fas fa-check"></i> Salvar Produto</button>
        </div>
    </div>
</div>

<script>
function openProductModal(data = null) {
    document.getElementById('modalTitleText').textContent = data ? 'Editar Produto' : 'Novo Produto';
    document.getElementById('prodId').value = data?.id ?? '';
    document.getElementById('prodCode').value = data?.code ?? '';
    document.getElementById('prodBarcode').value = data?.barcode ?? '';
    document.getElementById('prodName').value = data?.name ?? '';
    document.getElementById('prodDesc').value = data?.description ?? '';
    document.getElementById('prodCategory').value = data?.category_id ?? '';
    document.getElementById('prodSupplier').value = data?.supplier_id ?? '';
    document.getElementById('prodUnit').value = data?.unit ?? 'UN';
    document.getElementById('prodCost').value = data?.cost_price ?? '';
    document.getElementById('prodPrice').value = data?.sale_price ?? '';
    document.getElementById('prodMinStock').value = data?.min_stock ?? '';
    updateMarginPreview();
    openModal('productModal');
    setTimeout(() => document.getElementById('prodName').focus(), 100);
}

async function editProduct(id) {
    const res = await apiCall(`${BASE_PATH}/pages/products.php?action=get&id=${id}`);
    if (res.id) openProductModal(res);
}

async function saveProduct() {
    const name = document.getElementById('prodName').value.trim();
    if (!name) { showToast('Informe o nome do produto.', 'warning'); return; }
    const data = {
        id: document.getElementById('prodId').value || null,
        code: document.getElementById('prodCode').value,
        barcode: document.getElementById('prodBarcode').value,
        name, description: document.getElementById('prodDesc').value,
        category_id: document.getElementById('prodCategory').value || null,
        supplier_id: document.getElementById('prodSupplier').value || null,
        unit: document.getElementById('prodUnit').value,
        cost_price: parseFloat(document.getElementById('prodCost').value) || 0,
        sale_price: parseFloat(document.getElementById('prodPrice').value) || 0,
        min_stock: parseFloat(document.getElementById('prodMinStock').value) || 0,
    };
    const res = await apiCall(BASE_PATH+'/pages/products.php?action=save', data);
    if (res.success) {
        showToast(res.message, 'success');
        closeModal('productModal');
        setTimeout(() => location.reload(), 800);
    } else {
        showToast(res.message, 'error');
    }
}

function updateMarginPreview() {
    const cost = parseFloat(document.getElementById('prodCost').value) || 0;
    const price = parseFloat(document.getElementById('prodPrice').value) || 0;
    const profit = price - cost;
    const margin = cost > 0 ? (profit / cost * 100) : 0;
    const markup = price > 0 ? (profit / price * 100) : 0;
    const pEl = document.getElementById('previewProfit');
    const mEl = document.getElementById('previewMargin');
    const mkEl = document.getElementById('previewMarkup');
    pEl.textContent = formatMoney(profit);
    pEl.className = profit >= 0 ? 'profit-positive' : 'profit-negative';
    mEl.textContent = margin.toFixed(1) + '%';
    mEl.className = margin >= 0 ? 'profit-positive' : 'profit-negative';
    mkEl.textContent = markup.toFixed(1) + '%';
    mkEl.className = markup >= 0 ? 'profit-positive' : 'profit-negative';
}

function focusBarcode() {
    document.getElementById('prodBarcode').focus();
    showToast('Pronto para leitura! Bipe o código de barras.', 'info');
}

function liveSearch() {
    const term = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('#productsTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
    });
}

function applyFilter() {
    const cat = document.getElementById('catFilter').value;
    const q = document.getElementById('searchInput').value;
    location.href = `${BASE_PATH}/pages/products.php?cat=${cat}&q=${encodeURIComponent(q)}`;
}
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>
