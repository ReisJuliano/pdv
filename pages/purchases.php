<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();
$pageTitle = 'Compras / Notas Fiscais';
$db = getDB();

$purchases = $db->query("SELECT p.*,s.name as sup_name,u.name as user_name,(SELECT COUNT(*) FROM purchase_items WHERE purchase_id=p.id) as item_count FROM purchases p LEFT JOIN suppliers s ON p.supplier_id=s.id LEFT JOIN users u ON p.user_id=u.id ORDER BY p.created_at DESC LIMIT 100")->fetchAll();

include __DIR__.'/../includes/header.php';
?>

<div class="actions-bar">
    <div class="card-title"><i class="fas fa-truck"></i> Compras e Entradas de NF</div>
    <a href="<?= url('pages/stock_in.php') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Nova Entrada</a>
</div>

<div class="card">
    <?php if ($purchases): ?>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Número</th><th>Data</th><th>Fornecedor</th><th>Itens</th><th>Total</th><th>Operador</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($purchases as $p): ?>
            <tr>
                <td><code style="font-size:12px"><?= $p['purchase_number'] ?></code></td>
                <td style="font-size:12px"><?= formatDate($p['purchase_date']) ?></td>
                <td class="td-name"><?= htmlspecialchars($p['sup_name'] ?: 'Sem fornecedor') ?></td>
                <td><span class="badge badge-secondary"><?= $p['item_count'] ?> produto(s)</span></td>
                <td><strong><?= formatMoney($p['total']) ?></strong></td>
                <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($p['user_name']) ?></td>
                <td><span class="badge badge-success"><i class="fas fa-check"></i> Recebido</span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-truck"></i></div>
        <div class="empty-title">Nenhuma compra registrada</div>
        <a href="<?= url('pages/stock_in.php') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Registrar Entrada</a>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__.'/../includes/footer.php'; ?>
