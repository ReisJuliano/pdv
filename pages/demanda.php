<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();
$pageTitle = 'Relatório de Demanda';
$db = getDB();

// ── Parâmetros ────────────────────────────────────────────────────────────
$dateFrom  = $_GET['from']     ?? date('Y-m-01');
$dateTo    = $_GET['to']       ?? date('Y-m-d');
$catFiltro = intval($_GET['cat']  ?? 0);
$prodBusca = trim($_GET['prod']   ?? '');
$agrupar   = $_GET['group']    ?? 'product';   // product | day | week | month
$fonte     = $_GET['fonte']    ?? 'tudo';       // tudo | pdv | pedidos

$categorias = $db->query("SELECT * FROM categories WHERE active=1 ORDER BY name")->fetchAll();

// ── Condições de filtro ────────────────────────────────────────────────────
$whereExtra = '';
if ($catFiltro) $whereExtra .= " AND p.category_id = $catFiltro";
if ($prodBusca) $whereExtra .= " AND (p.name LIKE ".$db->quote("%$prodBusca%")." OR p.code LIKE ".$db->quote("%$prodBusca%").")";

// ── Query unificada: vendas PDV + pedidos finalizados ─────────────────────
// As vendas do PDV ficam em sale_items (via sales)
// Os pedidos finalizados também geram registros em sale_items quando fechados pelo PDV
// Mas pedidos podem não ter passado pelo PDV — buscamos ambas as fontes

// Fonte 1: sale_items (PDV direto e pedidos fechados via PDV)
$sqlPDV = "
    SELECT
        p.id                       AS product_id,
        p.name                     AS product_name,
        p.code,
        p.unit,
        c.name                     AS categoria,
        si.quantity                AS qty,
        si.total                   AS receita,
        si.profit                  AS lucro,
        si.unit_price              AS preco_unit,
        s.created_at               AS data_venda,
        'pdv'                      AS fonte
    FROM sale_items si
    JOIN sales      s  ON si.sale_id    = s.id
    JOIN products   p  ON si.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE s.status = 'finalizada'
      AND DATE(s.created_at) BETWEEN '$dateFrom' AND '$dateTo'
      $whereExtra
";

// Fonte 2: pedido_items de pedidos finalizados (que NÃO geraram sale_items,
//          ou seja, pedidos que foram cancelados depois do fechamento manual)
// Na prática, pedidos finalizados via PDV já estão no sale_items.
// Pedidos finalizados diretamente (sem PDV) não geram sale_items — buscamos eles aqui.
$sqlPedidos = "
    SELECT
        p.id                       AS product_id,
        p.name                     AS product_name,
        p.code,
        p.unit,
        c.name                     AS categoria,
        pi.quantity                AS qty,
        pi.total                   AS receita,
        (pi.quantity * (pi.unit_price - pi.unit_cost)) AS lucro,
        pi.unit_price              AS preco_unit,
        ped.created_at             AS data_venda,
        'pedido'                   AS fonte
    FROM pedido_items  pi
    JOIN pedidos       ped ON pi.pedido_id   = ped.id
    JOIN products      p   ON pi.product_id  = p.id
    LEFT JOIN categories c ON p.category_id  = c.id
    WHERE ped.status = 'finalizado'
      AND DATE(ped.created_at) BETWEEN '$dateFrom' AND '$dateTo'
      $whereExtra
      -- Exclui pedidos que já foram contabilizados como venda (finalizados via PDV)
      AND NOT EXISTS (
          SELECT 1 FROM sales sv
          WHERE sv.status = 'finalizada'
            AND DATE(sv.created_at) = DATE(ped.created_at)
            AND sv.notes LIKE CONCAT('%', ped.comanda_codigo, '%')
      )
";

// Seleciona a fonte
if ($fonte === 'pdv') {
    $sqlUnion = $sqlPDV;
} elseif ($fonte === 'pedidos') {
    $sqlUnion = $sqlPedidos;
} else {
    $sqlUnion = "($sqlPDV) UNION ALL ($sqlPedidos)";
}

// ── Agrupa por produto (visão de demanda) ─────────────────────────────────
$produtosQuery = "
    SELECT
        product_id,
        product_name,
        code,
        unit,
        categoria,
        SUM(qty)     AS total_qty,
        SUM(receita) AS total_receita,
        SUM(lucro)   AS total_lucro,
        AVG(preco_unit) AS preco_medio,
        COUNT(*)     AS num_transacoes,
        MIN(data_venda) AS primeira_venda,
        MAX(data_venda) AS ultima_venda
    FROM ($sqlUnion) base
    GROUP BY product_id, product_name, code, unit, categoria
    ORDER BY total_qty DESC
";

$produtos = $db->query($produtosQuery)->fetchAll();

// ── Série temporal (para o gráfico) ──────────────────────────────────────
// Top 5 produtos mais vendidos em quantidade
$top5ids = array_slice(array_column($produtos, 'product_id'), 0, 5);

$seriesData = [];
if ($top5ids) {
    $inIds = implode(',', $top5ids);

    // Formato de agrupamento
    $groupFormats = [
        'day'   => ['DATE(data_venda)',         "DATE_FORMAT(data_venda,'%d/%m')"],
        'week'  => ['YEARWEEK(data_venda,3)',    "CONCAT('Sem ',WEEK(data_venda,3),'/',YEAR(data_venda))"],
        'month' => ["DATE_FORMAT(data_venda,'%Y-%m')", "DATE_FORMAT(data_venda,'%m/%Y')"],
    ];
    [$groupExpr, $labelExpr] = $groupFormats[$agrupar === 'day' ? 'day' : ($agrupar === 'week' ? 'week' : 'month')];

    $sqlSeries = "
    SELECT
        product_id,
        ANY_VALUE(product_name) AS product_name,
        $groupExpr              AS periodo_key,
        ANY_VALUE($labelExpr)   AS periodo_label,
        SUM(qty)                AS qty
    FROM ($sqlUnion) base
    WHERE product_id IN ($inIds)
    GROUP BY product_id, $groupExpr
    ORDER BY periodo_key
";
    $rows = $db->query($sqlSeries)->fetchAll();

    // Estrutura: { product_id: { name, data: { periodo: qty } } }
    $periodos = [];
    foreach ($rows as $r) {
        $pid = $r['product_id'];
        if (!isset($seriesData[$pid])) {
            $seriesData[$pid] = ['name' => $r['product_name'], 'data' => []];
        }
        $seriesData[$pid]['data'][$r['periodo_label']] = floatval($r['qty']);
        $periodos[$r['periodo_label']] = true;
    }
    $periodos = array_keys($periodos);
}

// ── Resumo geral ──────────────────────────────────────────────────────────
$totalQty     = array_sum(array_column($produtos, 'total_qty'));
$totalReceita = array_sum(array_column($produtos, 'total_receita'));
$totalLucro   = array_sum(array_column($produtos, 'total_lucro'));
$diasPeriodo  = max(1, (strtotime($dateTo) - strtotime($dateFrom)) / 86400 + 1);

// Atalhos de período
$ranges = [
    'Hoje'      => [date('Y-m-d'), date('Y-m-d')],
    '7 dias'    => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    'Este mês'  => [date('Y-m-01'), date('Y-m-d')],
    'Mês ant.'  => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    '3 meses'   => [date('Y-m-01', strtotime('-2 months')), date('Y-m-d')],
    'Este ano'  => [date('Y-01-01'), date('Y-m-d')],
];

include __DIR__.'/../includes/header.php';
?>

<!-- Filtros -->
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
                <label class="form-label">Categoria</label>
                <select name="cat" class="form-control">
                    <option value="">Todas</option>
                    <?php foreach ($categorias as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $catFiltro==$cat['id']?'selected':'' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Produto</label>
                <input type="text" name="prod" class="form-control" placeholder="Nome ou código..." value="<?= htmlspecialchars($prodBusca) ?>" style="width:180px">
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Agrupar série por</label>
                <select name="group" class="form-control">
                    <option value="day"   <?= $agrupar==='day'?'selected':''   ?>>Dia</option>
                    <option value="week"  <?= $agrupar==='week'?'selected':''  ?>>Semana</option>
                    <option value="month" <?= $agrupar==='month'?'selected':'' ?>>Mês</option>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Fonte</label>
                <select name="fonte" class="form-control">
                    <option value="tudo"    <?= $fonte==='tudo'?'selected':''    ?>>PDV + Pedidos</option>
                    <option value="pdv"     <?= $fonte==='pdv'?'selected':''     ?>>Só PDV</option>
                    <option value="pedidos" <?= $fonte==='pedidos'?'selected':'' ?>>Só Pedidos/Comandas</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-chart-line"></i> Gerar</button>
            <a href="<?= url('pages/demanda.php') ?>" class="btn btn-outline">Limpar</a>
        </form>

        <!-- Atalhos de período -->
        <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">
            <span style="font-size:12px;color:var(--text-muted);line-height:28px;margin-right:4px">Rápido:</span>
            <?php foreach ($ranges as $label => [$f,$t]): ?>
            <a href="?from=<?= $f ?>&to=<?= $t ?>&cat=<?= $catFiltro ?>&prod=<?= urlencode($prodBusca) ?>&group=<?= $agrupar ?>&fonte=<?= $fonte ?>"
               class="btn btn-outline btn-sm <?= ($dateFrom==$f&&$dateTo==$t)?'btn-primary':'' ?>">
               <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Cards de resumo -->
<div class="stats-grid" style="margin-bottom:20px">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-boxes-stacked"></i></div>
        <div class="stat-info">
            <div class="stat-label">Unidades Vendidas</div>
            <div class="stat-value"><?= number_format($totalQty, 0, ',', '.') ?></div>
            <div class="stat-change up"><i class="fas fa-calendar-day"></i> <?= number_format($totalQty/$diasPeriodo,1,',','.') ?>/dia em média</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-dollar-sign"></i></div>
        <div class="stat-info">
            <div class="stat-label">Faturamento</div>
            <div class="stat-value"><?= formatMoney($totalReceita) ?></div>
            <div class="stat-change up"><i class="fas fa-calendar-day"></i> <?= formatMoney($totalReceita/$diasPeriodo) ?>/dia</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-sack-dollar"></i></div>
        <div class="stat-info">
            <div class="stat-label">Lucro Total</div>
            <div class="stat-value"><?= formatMoney($totalLucro) ?></div>
            <div class="stat-change <?= $totalLucro>=0?'up':'down' ?>">
                <i class="fas fa-percent"></i> Margem: <?= $totalReceita>0?number_format($totalLucro/$totalReceita*100,1).'%':'0%' ?>
            </div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-tag"></i></div>
        <div class="stat-info">
            <div class="stat-label">Produtos Diferentes</div>
            <div class="stat-value"><?= count($produtos) ?></div>
            <div class="stat-change up"><i class="fas fa-calendar"></i> <?= $diasPeriodo ?> dia(s) analisados</div>
        </div>
    </div>
</div>

<!-- Gráfico de série temporal (top 5 produtos) -->
<?php if (!empty($seriesData) && !empty($periodos)): ?>
<div class="card" style="margin-bottom:20px">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-chart-line"></i> Evolução dos Top 5 Produtos — Quantidade Vendida por <?= ['day'=>'Dia','week'=>'Semana','month'=>'Mês'][$agrupar] ?? 'Período' ?></div>
    </div>
    <div class="card-body">
        <canvas id="demandaChart" height="120"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- Tabela de produtos -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-table"></i> Demanda por Produto (<?= count($produtos) ?>)</div>
        <button class="btn btn-outline btn-sm" onclick="exportCSV()"><i class="fas fa-download"></i> Exportar CSV</button>
    </div>

    <?php if ($produtos): ?>
    <!-- Filtro rápido na tabela -->
    <div class="card-body" style="padding:10px 16px">
        <div class="search-bar" style="max-width:320px">
            <i class="fas fa-search"></i>
            <input type="text" id="tblSearch" class="form-control" placeholder="Filtrar na tabela..." oninput="filtrarTabela(this.value)">
        </div>
    </div>
    <div class="table-wrapper">
        <table id="demandaTable">
            <thead>
                <tr>
                    <th style="cursor:pointer" onclick="sortTable(0)"># <i class="fas fa-sort" style="font-size:10px;opacity:0.4"></i></th>
                    <th style="cursor:pointer" onclick="sortTable(1)">Produto <i class="fas fa-sort" style="font-size:10px;opacity:0.4"></i></th>
                    <th>Categoria</th>
                    <th style="cursor:pointer;text-align:right" onclick="sortTable(3)">Qtd Total <i class="fas fa-sort" style="font-size:10px;opacity:0.4"></i></th>
                    <th style="text-align:right">Média/Dia</th>
                    <th style="text-align:right">Média/Semana</th>
                    <th style="cursor:pointer;text-align:right" onclick="sortTable(6)">Faturamento <i class="fas fa-sort" style="font-size:10px;opacity:0.4"></i></th>
                    <th style="text-align:right">Lucro</th>
                    <th style="text-align:right">Preço Médio</th>
                    <th>Transações</th>
                    <th>Última Venda</th>
                    <th>Giro (barra)</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $maxQty = max(1, $produtos[0]['total_qty'] ?? 1);
            foreach ($produtos as $i => $p):
                $mediaDia   = $p['total_qty'] / $diasPeriodo;
                $mediaSem   = $mediaDia * 7;
                $pct        = ($p['total_qty'] / $maxQty) * 100;
                $diasUltVenda = $p['ultima_venda'] ? floor((time() - strtotime($p['ultima_venda'])) / 86400) : null;
                $corBarra   = $pct > 60 ? '#059669' : ($pct > 25 ? '#d97706' : '#6b7280');
            ?>
            <tr>
                <td style="font-weight:800;color:<?= ['#f59e0b','#94a3b8','#cd7f32'][$i] ?? 'var(--text-muted)' ?>;font-size:13px"><?= $i+1 ?></td>
                <td>
                    <div class="td-name"><?= htmlspecialchars($p['product_name']) ?></div>
                    <div style="font-size:11px;color:var(--text-muted);font-family:'JetBrains Mono',monospace"><?= htmlspecialchars($p['code']) ?></div>
                </td>
                <td><span class="badge badge-secondary"><?= htmlspecialchars($p['categoria'] ?: '—') ?></span></td>
                <td style="text-align:right;font-weight:700;font-size:15px">
                    <?= number_format($p['total_qty'], 0, ',', '.') ?>
                    <span style="font-size:11px;color:var(--text-muted);font-weight:400"> <?= $p['unit'] ?></span>
                </td>
                <td style="text-align:right;color:var(--text-secondary)"><?= number_format($mediaDia, 2, ',', '.') ?>/dia</td>
                <td style="text-align:right;color:var(--text-secondary)"><?= number_format($mediaSem, 1, ',', '.') ?>/sem</td>
                <td style="text-align:right;font-weight:600"><?= formatMoney($p['total_receita']) ?></td>
                <td style="text-align:right" class="<?= $p['total_lucro']>=0?'profit-positive':'profit-negative' ?>"><?= formatMoney($p['total_lucro']) ?></td>
                <td style="text-align:right;color:var(--text-muted)"><?= formatMoney($p['preco_medio']) ?></td>
                <td style="text-align:center"><span class="badge badge-primary"><?= $p['num_transacoes'] ?>x</span></td>
                <td style="font-size:12px;color:<?= ($diasUltVenda !== null && $diasUltVenda > 30)?'var(--danger)':($diasUltVenda > 7?'var(--warning)':'var(--success)') ?>">
                    <?= $diasUltVenda !== null ? ($diasUltVenda === 0 ? 'Hoje' : "há {$diasUltVenda}d") : '—' ?>
                </td>
                <td style="min-width:120px">
                    <div style="height:8px;background:var(--border);border-radius:4px;overflow:hidden">
                        <div style="width:<?= number_format($pct,1,'.','') ?>%;height:100%;background:<?= $corBarra ?>;border-radius:4px;transition:width 0.6s ease"></div>
                    </div>
                    <div style="font-size:10px;color:var(--text-muted);margin-top:2px"><?= number_format($pct,0) ?>% do top</div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-chart-line"></i></div>
        <div class="empty-title">Nenhuma venda no período</div>
        <div class="empty-text">Ajuste os filtros ou selecione outro período</div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
Chart.defaults.color = '#64748b';

// ── Gráfico de demanda ────────────────────────────────────────────────────
const periodos    = <?= json_encode($periodos ?? []) ?>;
const seriesRaw   = <?= json_encode(array_values($seriesData)) ?>;
const COLORS      = ['#1a56db','#059669','#d97706','#7c3aed','#dc2626'];

if (periodos.length && seriesRaw.length) {
    const datasets = seriesRaw.map((s, i) => ({
        label: s.name,
        data: periodos.map(p => s.data[p] || 0),
        borderColor: COLORS[i % COLORS.length],
        backgroundColor: COLORS[i % COLORS.length] + '22',
        borderWidth: 2.5,
        pointRadius: periodos.length < 20 ? 4 : 2,
        tension: 0.35,
        fill: false,
    }));

    new Chart(document.getElementById('demandaChart'), {
        type: 'line',
        data: { labels: periodos, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y.toLocaleString('pt-BR', {minimumFractionDigits:0})} un.`
                    }
                }
            },
            scales: {
                y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('pt-BR') } }
            }
        }
    });
}

// ── Filtro rápido na tabela ───────────────────────────────────────────────
function filtrarTabela(term) {
    const t = term.toLowerCase();
    document.querySelectorAll('#demandaTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(t) ? '' : 'none';
    });
}

// ── Ordenação de colunas ──────────────────────────────────────────────────
let sortDir = {};
function sortTable(col) {
    const tbody = document.querySelector('#demandaTable tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    sortDir[col] = !sortDir[col];
    rows.sort((a, b) => {
        const va = a.cells[col]?.textContent.replace(/[^\d,.-]/g,'').replace(',','.') || '';
        const vb = b.cells[col]?.textContent.replace(/[^\d,.-]/g,'').replace(',','.') || '';
        const na = parseFloat(va), nb = parseFloat(vb);
        const cmp = isNaN(na) ? va.localeCompare(vb) : na - nb;
        return sortDir[col] ? cmp : -cmp;
    });
    rows.forEach(r => tbody.appendChild(r));
}

// ── Exportar CSV ──────────────────────────────────────────────────────────
function exportCSV() {
    const rows = [['Pos','Produto','Código','Categoria','Qtd Total','Unidade','Média/Dia','Faturamento','Lucro','Preço Médio','Transações','Última Venda']];
    document.querySelectorAll('#demandaTable tbody tr').forEach((row, i) => {
        if (row.style.display === 'none') return;
        const cells = row.querySelectorAll('td');
        rows.push([
            i+1,
            cells[1]?.querySelector('.td-name')?.textContent.trim() || '',
            cells[1]?.querySelector('div:last-child')?.textContent.trim() || '',
            cells[2]?.textContent.trim() || '',
            cells[3]?.textContent.replace(/[^\d,]/g,'').replace(',','.') || '',
            '',
            cells[4]?.textContent.trim() || '',
            cells[6]?.textContent.trim() || '',
            cells[7]?.textContent.trim() || '',
            cells[8]?.textContent.trim() || '',
            cells[9]?.textContent.trim() || '',
            cells[10]?.textContent.trim() || '',
        ]);
    });
    const csv = rows.map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(';')).join('\n');
    const blob = new Blob(['\uFEFF'+csv], {type:'text/csv;charset=utf-8'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `demanda_<?= $dateFrom ?>_<?= $dateTo ?>.csv`;
    a.click();
}
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>