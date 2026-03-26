<?php
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/setup.php';
requireLogin();
$pageTitle = 'Início';
$db = getDB();

// Today stats
$today = date('Y-m-d');
$stmt = $db->prepare("SELECT COUNT(*) as qty, COALESCE(SUM(total),0) as total, COALESCE(SUM(profit),0) as profit FROM sales WHERE DATE(created_at)=? AND status='finalizada'");
$stmt->execute([$today]);
$todaySales = $stmt->fetch();

// Month stats
$month = date('Y-m');
$stmt = $db->prepare("SELECT COUNT(*) as qty, COALESCE(SUM(total),0) as total, COALESCE(SUM(profit),0) as profit FROM sales WHERE DATE_FORMAT(created_at,'%Y-%m')=? AND status='finalizada'");
$stmt->execute([$month]);
$monthSales = $stmt->fetch();

// Stock alerts
$stmt = $db->query("SELECT COUNT(*) FROM products WHERE stock_quantity <= min_stock AND active=1");
$lowStock = $stmt->fetchColumn();

// Total products
$stmt = $db->query("SELECT COUNT(*) FROM products WHERE active=1");
$totalProducts = $stmt->fetchColumn();

// Recent sales
$recentSales = $db->query("SELECT s.*, u.name as user_name, c.name as customer_name FROM sales s LEFT JOIN users u ON s.user_id=u.id LEFT JOIN customers c ON s.customer_id=c.id WHERE s.status='finalizada' ORDER BY s.created_at DESC LIMIT 8")->fetchAll();

// Top products today
$topProducts = $db->prepare("SELECT p.name, SUM(si.quantity) as qty_sold, SUM(si.total) as total_sold FROM sale_items si JOIN products p ON si.product_id=p.id JOIN sales s ON si.sale_id=s.id WHERE DATE(s.created_at)=? AND s.status='finalizada' GROUP BY p.id ORDER BY qty_sold DESC LIMIT 6");
$topProducts->execute([$today]);
$topProducts = $topProducts->fetchAll();

// Sales last 7 days
$last7 = $db->query("SELECT DATE(created_at) as day, SUM(total) as total FROM sales WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY) AND status='finalizada' GROUP BY DATE(created_at) ORDER BY day")->fetchAll();

// Low stock items
$lowStockItems = $db->query("SELECT name, stock_quantity, min_stock, unit FROM products WHERE stock_quantity <= min_stock AND active=1 ORDER BY stock_quantity ASC LIMIT 6")->fetchAll();

include __DIR__.'/includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-cart-shopping"></i></div>
        <div class="stat-info">
            <div class="stat-label">Vendas Hoje</div>
            <div class="stat-value"><?= formatMoney($todaySales['total']) ?></div>
            <div class="stat-change up"><i class="fas fa-receipt"></i> <?= $todaySales['qty'] ?> venda(s)</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-sack-dollar"></i></div>
        <div class="stat-info">
            <div class="stat-label">Lucro Hoje</div>
            <div class="stat-value"><?= formatMoney($todaySales['profit']) ?></div>
            <?php $margin = $todaySales['total'] > 0 ? ($todaySales['profit']/$todaySales['total']*100) : 0; ?>
            <div class="stat-change <?= $margin >= 0 ? 'up' : 'down' ?>"><i class="fas fa-percent"></i> Margem: <?= number_format($margin,1) ?>%</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-calendar-check"></i></div>
        <div class="stat-info">
            <div class="stat-label">Vendas no Mês</div>
            <div class="stat-value"><?= formatMoney($monthSales['total']) ?></div>
            <div class="stat-change up"><i class="fas fa-receipt"></i> <?= $monthSales['qty'] ?> venda(s)</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon teal"><i class="fas fa-boxes-stacked"></i></div>
        <div class="stat-info">
            <div class="stat-label">Produtos Ativos</div>
            <div class="stat-value"><?= $totalProducts ?></div>
            <div class="stat-change <?= $lowStock > 0 ? 'down' : 'up' ?>">
                <i class="fas fa-triangle-exclamation"></i> <?= $lowStock ?> com estoque baixo
            </div>
        </div>
    </div>
</div>

<div class="grid-2" style="margin-bottom:20px">
    <!-- Recent sales -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fas fa-receipt"></i> Últimas Vendas</div>
            <a href="<?= url('pages/sales.php') ?>" class="btn btn-ghost btn-sm">Ver todas <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if ($recentSales): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>#</th><th>Cliente</th><th>Total</th><th>Pgto</th><th>Hora</th></tr></thead>
                <tbody>
                <?php foreach ($recentSales as $s): ?>
                <tr>
                    <td><span style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($s['sale_number']) ?></span></td>
                    <td class="td-name"><?= htmlspecialchars($s['customer_name'] ?: 'Balcão') ?></td>
                    <td><strong><?= formatMoney($s['total']) ?></strong></td>
                    <td>
                        <?php
                        $pmLabels = ['dinheiro'=>'Dinheiro','cartao_credito'=>'Crédito','cartao_debito'=>'Débito','pix'=>'Pix','fiado'=>'Fiado'];
                        $pmBadge  = ['dinheiro'=>'badge-success','cartao_credito'=>'badge-primary','cartao_debito'=>'badge-secondary','pix'=>'badge-warning','fiado'=>'badge-danger'];
                        $pm = $s['payment_method'];
                        ?>
                        <span class="badge <?= $pmBadge[$pm] ?? 'badge-secondary' ?>"><?= $pmLabels[$pm] ?? $pm ?></span>
                    </td>
                    <td style="color:var(--text-muted);font-size:12px"><?= date('H:i', strtotime($s['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-receipt"></i></div>
            <div class="empty-title">Nenhuma venda hoje</div>
            <a href="<?= url('pages/pdv.php') ?>" class="btn btn-primary btn-sm"><i class="fas fa-cash-register"></i> Abrir Caixa</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right column -->
    <div style="display:flex;flex-direction:column;gap:16px">
        <!-- Top products -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-fire"></i> Mais Vendidos Hoje</div>
            </div>
            <?php if ($topProducts): ?>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Produto</th><th>Qtd</th><th>Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($topProducts as $p): ?>
                    <tr>
                        <td class="td-name"><?= htmlspecialchars($p['name']) ?></td>
                        <td><?= number_format($p['qty_sold'],0,'.','.') ?></td>
                        <td><?= formatMoney($p['total_sold']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px">Sem vendas hoje</div>
            <?php endif; ?>
        </div>

        <!-- Low stock alert -->
        <?php if ($lowStockItems): ?>
        <div class="card">
            <div class="card-header">
                <div class="card-title" style="color:var(--warning)"><i class="fas fa-triangle-exclamation"></i> Estoque Baixo</div>
                <a href="<?= url('pages/stock_adjust.php') ?>" class="btn btn-outline btn-sm">Ajustar</a>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Produto</th><th>Atual</th><th>Mínimo</th></tr></thead>
                    <tbody>
                    <?php foreach ($lowStockItems as $p): ?>
                    <tr>
                        <td class="td-name"><?= htmlspecialchars($p['name']) ?></td>
                        <td class="<?= $p['stock_quantity'] <= 0 ? 'stock-zero' : 'stock-low' ?>"><?= number_format($p['stock_quantity'],0) ?> <?= $p['unit'] ?></td>
                        <td style="color:var(--text-muted)"><?= number_format($p['min_stock'],0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Quick actions -->
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-bolt"></i> Ações Rápidas</div></div>
    <div class="card-body" style="display:flex;gap:12px;flex-wrap:wrap">
        <a href="<?= url('pages/pdv.php') ?>" class="btn btn-primary btn-lg"><i class="fas fa-cash-register"></i> Abrir PDV</a>
        <a href="<?= url('pages/products.php') ?>" class="btn btn-outline btn-lg"><i class="fas fa-plus"></i> Novo Produto</a>
        <a href="<?= url('pages/stock_in.php') ?>" class="btn btn-outline btn-lg"><i class="fas fa-arrow-down"></i> Entrada de Estoque</a>
        <a href="<?= url('pages/reports.php') ?>" class="btn btn-outline btn-lg"><i class="fas fa-chart-bar"></i> Relatórios</a>
        <a href="<?= url('pages/purchases.php') ?>" class="btn btn-outline btn-lg"><i class="fas fa-truck"></i> Nova Compra</a>
    </div>
</div>

<?php include __DIR__.'/includes/footer.php'; ?>
