<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();
$pageTitle = 'Vendas';
$db = getDB();

if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';
    if ($action === 'cancel') {
        $id = intval($_GET['id']);
        $sale = $db->prepare("SELECT * FROM sales WHERE id=? AND status='finalizada'")->execute([$id]);
        // Restore stock
        $items = $db->prepare("SELECT * FROM sale_items WHERE sale_id=?")->execute([$id]);
        // ... simplified cancel
        $db->prepare("UPDATE sales SET status='cancelada' WHERE id=?")->execute([$id]);
        // Restore stock for each item
        foreach ($db->query("SELECT product_id, quantity FROM sale_items WHERE sale_id=$id") as $it) {
            $db->prepare("UPDATE products SET stock_quantity=stock_quantity+? WHERE id=?")->execute([$it['quantity'],$it['product_id']]);
        }
        echo json_encode(['success'=>true,'message'=>'Venda cancelada e estoque restaurado.']);
        exit;
    }
    if ($action === 'detail') {
        $id = intval($_GET['id']);
        $sale = $db->prepare("SELECT s.*,u.name as user_name,c.name as customer_name FROM sales s LEFT JOIN users u ON s.user_id=u.id LEFT JOIN customers c ON s.customer_id=c.id WHERE s.id=?")->execute([$id])?? null;
        $sale = $db->query("SELECT s.*,u.name as user_name,c.name as customer_name FROM sales s LEFT JOIN users u ON s.user_id=u.id LEFT JOIN customers c ON s.customer_id=c.id WHERE s.id=$id")->fetch();
        $items = $db->query("SELECT si.*,p.name,p.code,p.barcode FROM sale_items si JOIN products p ON si.product_id=p.id WHERE si.sale_id=$id")->fetchAll();
        echo json_encode(['sale'=>$sale,'items'=>$items]);
        exit;
    }
    exit;
}

// Filters
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? date('Y-m-d');
$payment  = $_GET['pay']  ?? '';

$sql = "SELECT s.*, u.name as user_name, c.name as customer_name FROM sales s LEFT JOIN users u ON s.user_id=u.id LEFT JOIN customers c ON s.customer_id=c.id WHERE DATE(s.created_at) BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];
if ($payment) { $sql .= " AND s.payment_method=?"; $params[] = $payment; }
$sql .= " ORDER BY s.created_at DESC";
$stmt = $db->prepare($sql); $stmt->execute($params);
$sales = $stmt->fetchAll();

$totalVendas = array_sum(array_column($sales, 'total'));
$totalLucro  = array_sum(array_column($sales, 'profit'));

include __DIR__.'/../includes/header.php';
?>

<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-receipt"></i></div>
        <div class="stat-info"><div class="stat-label">Total Vendas</div><div class="stat-value"><?= count($sales) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-dollar-sign"></i></div>
        <div class="stat-info"><div class="stat-label">Faturamento</div><div class="stat-value"><?= formatMoney($totalVendas) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-sack-dollar"></i></div>
        <div class="stat-info"><div class="stat-label">Lucro Total</div><div class="stat-value"><?= formatMoney($totalLucro) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-percent"></i></div>
        <div class="stat-info"><div class="stat-label">Margem Média</div><div class="stat-value"><?= $totalVendas > 0 ? number_format($totalLucro/$totalVendas*100,1).'%' : '0%' ?></div></div>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px">
    <div class="card-body" style="padding:14px 20px">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div class="form-group" style="margin:0">
                <label class="form-label">De</label>
                <input type="date" name="from" class="form-control" value="<?= $dateFrom ?>">
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Até</label>
                <input type="date" name="to" class="form-control" value="<?= $dateTo ?>">
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Pagamento</label>
                <select name="pay" class="form-control">
                    <option value="">Todos</option>
                    <option value="dinheiro" <?= $payment=='dinheiro'?'selected':'' ?>>Dinheiro</option>
                    <option value="pix" <?= $payment=='pix'?'selected':'' ?>>Pix</option>
                    <option value="cartao_debito" <?= $payment=='cartao_debito'?'selected':'' ?>>Débito</option>
                    <option value="cartao_credito" <?= $payment=='cartao_credito'?'selected':'' ?>>Crédito</option>
                    <option value="fiado" <?= $payment=='fiado'?'selected':'' ?>>Fiado</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
            <a href="<?= url('pages/sales.php') ?>" class="btn btn-outline">Limpar</a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-receipt"></i> Vendas (<?= count($sales) ?>)</div>
        <a href="<?= url('pages/pdv.php') ?>" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nova Venda</a>
    </div>
    <?php
    $pmLabels = ['dinheiro'=>'Dinheiro','cartao_credito'=>'Crédito','cartao_debito'=>'Débito','pix'=>'Pix','fiado'=>'Fiado'];
    $pmBadge  = ['dinheiro'=>'badge-success','cartao_credito'=>'badge-primary','cartao_debito'=>'badge-secondary','pix'=>'badge-warning','fiado'=>'badge-danger'];
    if ($sales): ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>#</th><th>Data/Hora</th><th>Cliente</th><th>Subtotal</th><th>Desconto</th><th>Total</th><th>Lucro</th><th>Pgto</th><th>Status</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php foreach ($sales as $s):
                $pm = $s['payment_method'];
            ?>
            <tr id="sale-<?= $s['id'] ?>">
                <td><code style="font-size:12px"><?= htmlspecialchars($s['sale_number']) ?></code></td>
                <td style="font-size:12px"><?= formatDateTime($s['created_at']) ?></td>
                <td class="td-name"><?= htmlspecialchars($s['customer_name'] ?: 'Balcão') ?></td>
                <td><?= formatMoney($s['subtotal']) ?></td>
                <td><?= $s['discount'] > 0 ? '<span style="color:var(--danger)">-'.formatMoney($s['discount']).'</span>' : '-' ?></td>
                <td><strong><?= formatMoney($s['total']) ?></strong></td>
                <td class="<?= $s['profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>"><?= formatMoney($s['profit']) ?></td>
                <td><span class="badge <?= $pmBadge[$pm] ?? 'badge-secondary' ?>"><?= $pmLabels[$pm] ?? $pm ?></span></td>
                <td>
                    <?php if ($s['status'] === 'finalizada'): ?>
                    <span class="badge badge-success"><i class="fas fa-check"></i> OK</span>
                    <?php elseif ($s['status'] === 'cancelada'): ?>
                    <span class="badge badge-danger"><i class="fas fa-xmark"></i> Cancelada</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;gap:4px">
                        <button class="btn btn-ghost btn-sm" title="Detalhes" onclick="showDetail(<?= $s['id'] ?>)"><i class="fas fa-eye"></i></button>
                        <?php if ($s['status']==='finalizada' && currentUser()['role']==='admin'): ?>
                        <button class="btn btn-ghost btn-sm" title="Cancelar" onclick="cancelSale(<?= $s['id'] ?>)"><i class="fas fa-ban" style="color:var(--danger)"></i></button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-receipt"></i></div>
        <div class="empty-title">Nenhuma venda encontrada</div>
        <a href="<?= url('pages/pdv.php') ?>" class="btn btn-primary"><i class="fas fa-cash-register"></i> Abrir PDV</a>
    </div>
    <?php endif; ?>
</div>

<!-- Detail Modal -->
<div class="modal modal-lg" id="detailModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Detalhes da Venda</div>
            <button class="modal-close" onclick="closeModal('detailModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="detailContent">Carregando...</div>
    </div>
</div>

<script>
async function showDetail(id) {
    openModal('detailModal');
    document.getElementById('detailContent').innerHTML = '<div style="text-align:center;padding:32px"><i class="fas fa-spinner fa-spin fa-2x" style="color:var(--primary)"></i></div>';
    const res = await apiCall(`${BASE_PATH}/pages/sales.php?action=detail&id=${id}`);
    const s = res.sale;
    const items = res.items;
    const pmL = {dinheiro:'Dinheiro',cartao_credito:'Crédito',cartao_debito:'Débito',pix:'Pix',fiado:'Fiado'};
    document.getElementById('detailContent').innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
            <div><strong>Nº Venda:</strong> ${s.sale_number}</div>
            <div><strong>Data:</strong> ${new Date(s.created_at).toLocaleString('pt-BR')}</div>
            <div><strong>Cliente:</strong> ${s.customer_name || 'Balcão'}</div>
            <div><strong>Operador:</strong> ${s.user_name}</div>
            <div><strong>Pagamento:</strong> ${pmL[s.payment_method]||s.payment_method}</div>
            <div><strong>Status:</strong> ${s.status}</div>
        </div>
        <table style="width:100%;border-collapse:collapse">
            <thead><tr style="background:var(--bg)">
                <th style="padding:8px 12px;text-align:left;font-size:11px;color:var(--text-muted);border-bottom:1px solid var(--border)">Produto</th>
                <th style="padding:8px 12px;text-align:right;font-size:11px;color:var(--text-muted);border-bottom:1px solid var(--border)">Qtd</th>
                <th style="padding:8px 12px;text-align:right;font-size:11px;color:var(--text-muted);border-bottom:1px solid var(--border)">Unit.</th>
                <th style="padding:8px 12px;text-align:right;font-size:11px;color:var(--text-muted);border-bottom:1px solid var(--border)">Total</th>
                <th style="padding:8px 12px;text-align:right;font-size:11px;color:var(--text-muted);border-bottom:1px solid var(--border)">Lucro</th>
            </tr></thead>
            <tbody>${items.map(it=>`
                <tr>
                    <td style="padding:10px 12px;font-weight:600;font-size:13px">${it.name}</td>
                    <td style="padding:10px 12px;text-align:right">${it.quantity}</td>
                    <td style="padding:10px 12px;text-align:right">${formatMoney(it.unit_price)}</td>
                    <td style="padding:10px 12px;text-align:right;font-weight:700">${formatMoney(it.total)}</td>
                    <td style="padding:10px 12px;text-align:right;color:${it.profit>=0?'var(--success)':'var(--danger)'};font-weight:700">${formatMoney(it.profit)}</td>
                </tr>`).join('')}
            </tbody>
        </table>
        <div style="background:var(--bg);border-radius:8px;padding:16px;margin-top:16px">
            <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:13px"><span>Subtotal:</span><span>${formatMoney(s.subtotal)}</span></div>
            ${s.discount>0?`<div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:13px;color:var(--danger)"><span>Desconto:</span><span>- ${formatMoney(s.discount)}</span></div>`:''}
            <div style="display:flex;justify-content:space-between;font-size:18px;font-weight:800;margin-top:8px;padding-top:8px;border-top:1px solid var(--border)"><span>Total:</span><span style="color:var(--primary)">${formatMoney(s.total)}</span></div>
            <div style="display:flex;justify-content:space-between;margin-top:6px;font-size:13px;color:var(--success);font-weight:700"><span>Lucro:</span><span>${formatMoney(s.profit)}</span></div>
        </div>`;
}

async function cancelSale(id) {
    const ok = await showConfirm({
        title: 'Cancelar venda?',
        message: 'O estoque dos produtos será <strong>restaurado</strong>.',
        type: 'warning', icon: '↩️', confirmText: 'Sim, cancelar'
    });
    if (!ok) return;
    const res = await apiCall(`${BASE_PATH}/pages/sales.php?action=cancel&id=${id}`, null, 'GET');
    if (res.success) { showToast(res.message, 'success'); location.reload(); }
    else showToast(res.message, 'error');
}
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>
