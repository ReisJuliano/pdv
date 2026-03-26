<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();
$pageTitle = 'Relatórios';
$db = getDB();

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? date('Y-m-d');

// Sales by day
$salesByDay = $db->prepare("SELECT DATE(created_at) as day, SUM(total) as total, SUM(profit) as profit, COUNT(*) as qty FROM sales WHERE DATE(created_at) BETWEEN ? AND ? AND status='finalizada' GROUP BY DATE(created_at) ORDER BY day");
$salesByDay->execute([$dateFrom,$dateTo]);
$salesByDay = $salesByDay->fetchAll();

// Sales by payment method
$byPayment = $db->prepare("SELECT payment_method, SUM(total) as total, COUNT(*) as qty FROM sales WHERE DATE(created_at) BETWEEN ? AND ? AND status='finalizada' GROUP BY payment_method");
$byPayment->execute([$dateFrom,$dateTo]);
$byPayment = $byPayment->fetchAll();

// Top products
$topProducts = $db->prepare("SELECT p.name, SUM(si.quantity) as qty, SUM(si.total) as revenue, SUM(si.profit) as profit FROM sale_items si JOIN products p ON si.product_id=p.id JOIN sales s ON si.sale_id=s.id WHERE DATE(s.created_at) BETWEEN ? AND ? AND s.status='finalizada' GROUP BY p.id ORDER BY revenue DESC LIMIT 10");
$topProducts->execute([$dateFrom,$dateTo]);
$topProducts = $topProducts->fetchAll();

// Summary totals
$summary = $db->prepare("SELECT COUNT(*) as qty, COALESCE(SUM(total),0) as total, COALESCE(SUM(profit),0) as profit, COALESCE(SUM(cost_total),0) as cost FROM sales WHERE DATE(created_at) BETWEEN ? AND ? AND status='finalizada'");
$summary->execute([$dateFrom,$dateTo]);
$summary = $summary->fetch();

// Top categories
$topCats = $db->prepare("SELECT c.name, SUM(si.total) as revenue FROM sale_items si JOIN products p ON si.product_id=p.id JOIN categories c ON p.category_id=c.id JOIN sales s ON si.sale_id=s.id WHERE DATE(s.created_at) BETWEEN ? AND ? AND s.status='finalizada' GROUP BY c.id ORDER BY revenue DESC");
$topCats->execute([$dateFrom,$dateTo]);
$topCats = $topCats->fetchAll();

include __DIR__.'/../includes/header.php';
?>

<!-- Filter bar -->
<div class="card" style="margin-bottom:16px">
    <div class="card-body" style="padding:14px 20px">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div class="form-group" style="margin:0">
                <label class="form-label">Período De</label>
                <input type="date" name="from" class="form-control" value="<?= $dateFrom ?>">
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Até</label>
                <input type="date" name="to" class="form-control" value="<?= $dateTo ?>">
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-chart-bar"></i> Gerar Relatório</button>
            <button type="button" class="btn btn-outline" onclick="printReport()"><i class="fas fa-print"></i> Imprimir</button>
            <!-- Quick range buttons -->
            <div style="display:flex;gap:6px;margin-left:8px">
                <?php
                $ranges = [
                    'Hoje' => [date('Y-m-d'), date('Y-m-d')],
                    'Semana' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
                    'Mês' => [date('Y-m-01'), date('Y-m-d')],
                    'Mês Ant.' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
                ];
                foreach ($ranges as $label => [$f,$t]):
                ?>
                <a href="?from=<?= $f ?>&to=<?= $t ?>" class="btn btn-outline btn-sm <?= ($dateFrom==$f&&$dateTo==$t)?'btn-primary':'' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
        </form>
    </div>
</div>

<!-- Summary cards -->
<div class="stats-grid" style="margin-bottom:20px">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-receipt"></i></div>
        <div class="stat-info"><div class="stat-label">Total de Vendas</div><div class="stat-value"><?= $summary['qty'] ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-dollar-sign"></i></div>
        <div class="stat-info"><div class="stat-label">Faturamento</div><div class="stat-value"><?= formatMoney($summary['total']) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-box"></i></div>
        <div class="stat-info"><div class="stat-label">Custo Total</div><div class="stat-value"><?= formatMoney($summary['cost']) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-sack-dollar"></i></div>
        <div class="stat-info">
            <div class="stat-label">Lucro Total</div>
            <div class="stat-value"><?= formatMoney($summary['profit']) ?></div>
            <div class="stat-change <?= $summary['profit']>=0?'up':'down' ?>">
                <i class="fas fa-percent"></i> Margem: <?= $summary['total']>0?number_format($summary['profit']/$summary['total']*100,1).'%':'0%' ?>
            </div>
        </div>
    </div>
</div>

<div class="grid-2" style="margin-bottom:20px">
    <!-- Sales chart -->
    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fas fa-chart-line"></i> Vendas por Dia</div></div>
        <div class="card-body">
            <canvas id="salesChart" height="200"></canvas>
        </div>
    </div>
    <!-- Payment methods -->
    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fas fa-credit-card"></i> Por Forma de Pagamento</div></div>
        <div class="card-body">
            <canvas id="payChart" height="200"></canvas>
        </div>
    </div>
</div>

<div class="grid-2" style="margin-bottom:20px">
    <!-- Top products -->
    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fas fa-trophy"></i> Top Produtos</div></div>
        <?php if ($topProducts): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>#</th><th>Produto</th><th>Qtd</th><th>Faturamento</th><th>Lucro</th></tr></thead>
                <tbody>
                <?php foreach ($topProducts as $i => $p): ?>
                <tr>
                    <td style="font-weight:800;color:<?= ['#f59e0b','#94a3b8','#cd7f32'][$i] ?? 'var(--text-muted)' ?>"><?= $i+1 ?></td>
                    <td class="td-name"><?= htmlspecialchars($p['name']) ?></td>
                    <td><?= number_format($p['qty'],0) ?></td>
                    <td><?= formatMoney($p['revenue']) ?></td>
                    <td class="<?= $p['profit']>=0?'profit-positive':'profit-negative' ?>"><?= formatMoney($p['profit']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding:24px;text-align:center;color:var(--text-muted)">Sem dados</div>
        <?php endif; ?>
    </div>

    <!-- By category -->
    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fas fa-tags"></i> Por Categoria</div></div>
        <?php if ($topCats): ?>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Categoria</th><th>Faturamento</th><th>%</th></tr></thead>
                <tbody>
                <?php $totalCat = array_sum(array_column($topCats,'revenue')); ?>
                <?php foreach ($topCats as $c): $pct = $totalCat > 0 ? ($c['revenue']/$totalCat*100) : 0; ?>
                <tr>
                    <td class="td-name"><?= htmlspecialchars($c['name']) ?></td>
                    <td><?= formatMoney($c['revenue']) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div style="flex:1;height:6px;background:var(--border);border-radius:3px;overflow:hidden">
                                <div style="width:<?= $pct ?>%;height:100%;background:var(--primary);border-radius:3px"></div>
                            </div>
                            <span style="font-size:12px;font-weight:700;color:var(--text-muted);width:38px"><?= number_format($pct,1) ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding:24px;text-align:center;color:var(--text-muted)">Sem dados</div>
        <?php endif; ?>
    </div>
</div>

<!-- Daily breakdown table -->
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-table"></i> Resumo Diário</div></div>
    <?php if ($salesByDay): ?>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Data</th><th>Qtd Vendas</th><th>Faturamento</th><th>Lucro</th><th>Margem</th></tr></thead>
            <tbody>
            <?php foreach ($salesByDay as $d):
                $margin = $d['total'] > 0 ? ($d['profit']/$d['total']*100) : 0;
            ?>
            <tr>
                <td class="td-name"><?= date('d/m/Y (D)',strtotime($d['day'])) ?></td>
                <td><?= $d['qty'] ?></td>
                <td><?= formatMoney($d['total']) ?></td>
                <td class="<?= $d['profit']>=0?'profit-positive':'profit-negative' ?>"><?= formatMoney($d['profit']) ?></td>
                <td><?= number_format($margin,1) ?>%</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon"><i class="fas fa-chart-bar"></i></div><div class="empty-title">Sem vendas no período</div></div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.color = '#64748b';

const salesData = <?= json_encode($salesByDay) ?>;
const payData   = <?= json_encode($byPayment) ?>;
const pmL = {dinheiro:'Dinheiro',cartao_credito:'Crédito',cartao_debito:'Débito',pix:'Pix',fiado:'Fiado'};

// Sales chart
if (salesData.length) {
    new Chart(document.getElementById('salesChart'), {
        type: 'bar',
        data: {
            labels: salesData.map(d => new Date(d.day+'T00:00:00').toLocaleDateString('pt-BR',{day:'2-digit',month:'short'})),
            datasets: [
                {label:'Faturamento',data:salesData.map(d=>parseFloat(d.total)),backgroundColor:'rgba(26,86,219,0.15)',borderColor:'#1a56db',borderWidth:2,borderRadius:6},
                {label:'Lucro',data:salesData.map(d=>parseFloat(d.profit)),backgroundColor:'rgba(5,150,105,0.15)',borderColor:'#059669',borderWidth:2,borderRadius:6}
            ]
        },
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top'}},scales:{y:{beginAtZero:true,ticks:{callback:v=>'R$'+v.toLocaleString('pt-BR')}}}}
    });
} else {
    document.getElementById('salesChart').parentElement.innerHTML='<div style="text-align:center;padding:48px;color:var(--text-muted)"><i class="fas fa-chart-bar fa-2x" style="margin-bottom:12px;display:block;opacity:0.3"></i>Sem dados para o período</div>';
}

// Payment chart
if (payData.length) {
    new Chart(document.getElementById('payChart'), {
        type: 'doughnut',
        data: {
            labels: payData.map(p=>pmL[p.payment_method]||p.payment_method),
            datasets:[{data:payData.map(p=>parseFloat(p.total)),backgroundColor:['#1a56db','#059669','#d97706','#7c3aed','#dc2626'],borderWidth:0,hoverOffset:8}]
        },
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right'},tooltip:{callbacks:{label:(c)=>c.label+': R$'+parseFloat(c.parsed).toLocaleString('pt-BR',{minimumFractionDigits:2})}}}}
    });
} else {
    document.getElementById('payChart').parentElement.innerHTML='<div style="text-align:center;padding:48px;color:var(--text-muted)"><i class="fas fa-chart-pie fa-2x" style="margin-bottom:12px;display:block;opacity:0.3"></i>Sem dados para o período</div>';
}

function printReport() {
    window.print();
}
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>
