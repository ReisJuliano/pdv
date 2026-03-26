<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();
$pageTitle = 'Histórico de Estoque';
$db = getDB();

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? date('Y-m-d');
$type     = $_GET['type'] ?? '';
$search   = $_GET['q']    ?? '';

$sql = "SELECT sm.*,p.name as product_name,p.code,p.unit,u.name as user_name FROM stock_movements sm JOIN products p ON sm.product_id=p.id LEFT JOIN users u ON sm.user_id=u.id WHERE DATE(sm.created_at) BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];
if ($type) { $sql.=" AND sm.type=?"; $params[]=$type; }
if ($search) { $sql.=" AND (p.name LIKE ? OR p.code LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; }
$sql.=" ORDER BY sm.created_at DESC LIMIT 500";
$stmt = $db->prepare($sql); $stmt->execute($params);
$movements = $stmt->fetchAll();

include __DIR__.'/../includes/header.php';
?>

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
                <label class="form-label">Tipo</label>
                <select name="type" class="form-control">
                    <option value="">Todos</option>
                    <option value="entrada" <?= $type=='entrada'?'selected':'' ?>>Entrada</option>
                    <option value="saida" <?= $type=='saida'?'selected':'' ?>>Saída manual</option>
                    <option value="venda" <?= $type=='venda'?'selected':'' ?>>Venda</option>
                    <option value="ajuste" <?= $type=='ajuste'?'selected':'' ?>>Ajuste</option>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Produto</label>
                <input type="text" name="q" class="form-control" placeholder="Buscar..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
            <a href="<?= url('pages/stock_history.php') ?>" class="btn btn-outline">Limpar</a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-timeline"></i> Movimentações (<?= count($movements) ?>)</div>
    </div>
    <?php
    $typeLabels = ['entrada'=>'Entrada','saida'=>'Saída','venda'=>'Venda','ajuste'=>'Ajuste'];
    $typeBadge  = ['entrada'=>'badge-success','saida'=>'badge-danger','venda'=>'badge-primary','ajuste'=>'badge-warning'];
    if ($movements): ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Data/Hora</th><th>Produto</th><th>Tipo</th><th>Quantidade</th><th>Custo Unit.</th><th>Total Custo</th><th>Referência</th><th>Usuário</th></tr>
            </thead>
            <tbody>
            <?php foreach ($movements as $m): $tp = $m['type']; ?>
            <tr>
                <td style="font-size:12px"><?= formatDateTime($m['created_at']) ?></td>
                <td class="td-name"><?= htmlspecialchars($m['product_name']) ?> <span style="font-size:11px;color:var(--text-muted)">(<?= $m['code'] ?>)</span></td>
                <td><span class="badge <?= $typeBadge[$tp] ?? 'badge-secondary' ?>"><?= $typeLabels[$tp] ?? $tp ?></span></td>
                <td style="font-weight:700;color:<?= in_array($tp,['entrada']) ? 'var(--success)' : 'var(--danger)' ?>">
                    <?= in_array($tp,['entrada']) ? '+' : '−' ?><?= number_format($m['quantity'],3) ?> <?= $m['unit'] ?>
                </td>
                <td><?= $m['unit_cost'] > 0 ? formatMoney($m['unit_cost']) : '—' ?></td>
                <td><?= $m['total_cost'] > 0 ? formatMoney($m['total_cost']) : '—' ?></td>
                <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($m['reference'] ?: '—') ?></td>
                <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($m['user_name'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-timeline"></i></div>
        <div class="empty-title">Nenhuma movimentação encontrada</div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
