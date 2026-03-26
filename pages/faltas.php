<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();
$pageTitle = 'Faltas & Giro de Estoque';
$db = getDB();

// ── Parâmetros ────────────────────────────────────────────────────────────
$periodo   = intval($_GET['dias']      ?? 30);   // dias para análise de demanda
$paradoDias= intval($_GET['parado']    ?? 30);   // dias sem venda = "parado"
$catFiltro = intval($_GET['cat']       ?? 0);
$fornFiltro= intval($_GET['forn']      ?? 0);
$aba       = $_GET['aba']              ?? 'pedido'; // pedido | parado | giro

$categorias  = $db->query("SELECT * FROM categories WHERE active=1 ORDER BY name")->fetchAll();
$fornecedores= $db->query("SELECT * FROM suppliers   WHERE active=1 ORDER BY name")->fetchAll();

// ── Cálculo de demanda média diária por produto ────────────────────────────
// Baseado nas vendas do período selecionado
$wherecat  = $catFiltro  ? "AND p.category_id  = $catFiltro"  : '';
$whereforn = $fornFiltro ? "AND p.supplier_id   = $fornFiltro" : '';

// Vendas por produto no período
$sqlVendas = "
    SELECT
        p.id,
        p.code,
        p.name,
        p.unit,
        p.stock_quantity,
        p.min_stock,
        p.cost_price,
        p.sale_price,
        p.barcode,
        c.name  AS categoria,
        s.name  AS fornecedor,
        COALESCE(v.qtd_vendida, 0)            AS qtd_vendida,
        COALESCE(v.receita,     0)            AS receita,
        COALESCE(v.num_vendas,  0)            AS num_vendas,
        COALESCE(v.qtd_vendida, 0) / $periodo AS media_dia,
        -- Última venda
        COALESCE(uv.ultima_venda, NULL)       AS ultima_venda,
        -- Dias desde última venda
        COALESCE(DATEDIFF(NOW(), uv.ultima_venda), 9999) AS dias_sem_vender
    FROM products p
    LEFT JOIN categories c  ON p.category_id  = c.id
    LEFT JOIN suppliers   s ON p.supplier_id   = s.id
    LEFT JOIN (
        SELECT si.product_id,
               SUM(si.quantity)  AS qtd_vendida,
               SUM(si.total)     AS receita,
               COUNT(DISTINCT si.sale_id) AS num_vendas
        FROM sale_items si
        JOIN sales sa ON si.sale_id = sa.id
        WHERE sa.status = 'finalizada'
          AND sa.created_at >= DATE_SUB(NOW(), INTERVAL $periodo DAY)
        GROUP BY si.product_id
    ) v ON v.product_id = p.id
    LEFT JOIN (
        SELECT si.product_id, MAX(sa.created_at) AS ultima_venda
        FROM sale_items si
        JOIN sales sa ON si.sale_id = sa.id
        WHERE sa.status = 'finalizada'
        GROUP BY si.product_id
    ) uv ON uv.product_id = p.id
    WHERE p.active = 1
    $wherecat $whereforn
    ORDER BY qtd_vendida DESC, p.name
";
$produtos = $db->query($sqlVendas)->fetchAll();

// ── Separa nas abas ───────────────────────────────────────────────────────
$precisamPedido = [];
$produtosParados= [];
$todosProdutos  = [];

foreach ($produtos as $p) {
    $mediaDia    = (float)$p['media_dia'];
    // Dias de estoque restante
    $diasEstoque = $mediaDia > 0 ? ($p['stock_quantity'] / $mediaDia) : ($p['stock_quantity'] > 0 ? 9999 : 0);
    // Sugestão: comprar para cobrir 2x o período analisado
    $cobrir      = $periodo * 2;
    $necessario  = max(0, ($mediaDia * $cobrir) - $p['stock_quantity']);
    // Arredonda para cima em múltiplo de 1 (pode-se ajustar por unidade)
    $sugerido    = ceil($necessario);

    $p['dias_estoque']  = round($diasEstoque);
    $p['necessario']    = $necessario;
    $p['sugerido']      = $sugerido;
    $p['cobertura_dias']= $cobrir;
    $p['custo_pedido']  = $sugerido * $p['cost_price'];
    $todosProdutos[]    = $p;

    // Precisa pedir: estoque cobre menos de $periodo dias OU abaixo do mínimo
    if (
        ($mediaDia > 0 && $diasEstoque < $periodo) ||
        ($p['stock_quantity'] <= $p['min_stock'] && $p['min_stock'] > 0)
    ) {
        $precisamPedido[] = $p;
    }

    // Parado: não vendeu no período definido E tem estoque
    if ($p['dias_sem_vender'] >= $paradoDias && $p['stock_quantity'] > 0) {
        $produtosParados[] = $p;
    }
}

// Ordena pedido por urgência (menos dias de estoque primeiro)
usort($precisamPedido, fn($a,$b) => $a['dias_estoque'] <=> $b['dias_estoque']);

// Totais
$totalCustoPedido = array_sum(array_column($precisamPedido, 'custo_pedido'));
$totalParadoValor = array_sum(array_map(fn($p) => $p['stock_quantity'] * $p['cost_price'], $produtosParados));

include __DIR__.'/../includes/header.php';
?>

<!-- Filtros -->
<div class="card" style="margin-bottom:16px">
    <div class="card-body" style="padding:14px">
        <form method="GET" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
            <input type="hidden" name="aba" value="<?= htmlspecialchars($aba) ?>">
            <div class="form-group" style="margin:0">
                <label class="form-label">Período de análise</label>
                <select name="dias" class="form-control" onchange="this.form.submit()">
                    <?php foreach ([7,14,30,60,90] as $d): ?>
                    <option value="<?= $d ?>" <?= $periodo==$d?'selected':'' ?>>Últimos <?= $d ?> dias</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Parado há mais de</label>
                <select name="parado" class="form-control" onchange="this.form.submit()">
                    <?php foreach ([15,30,45,60,90] as $d): ?>
                    <option value="<?= $d ?>" <?= $paradoDias==$d?'selected':'' ?>><?= $d ?> dias</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Categoria</label>
                <select name="cat" class="form-control" onchange="this.form.submit()">
                    <option value="0">Todas</option>
                    <?php foreach ($categorias as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $catFiltro==$cat['id']?'selected':'' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Fornecedor</label>
                <select name="forn" class="form-control" onchange="this.form.submit()">
                    <option value="0">Todos</option>
                    <?php foreach ($fornecedores as $f): ?>
                    <option value="<?= $f['id'] ?>" <?= $fornFiltro==$f['id']?'selected':'' ?>><?= htmlspecialchars($f['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- Cards resumo -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px">
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-triangle-exclamation"></i></div>
        <div class="stat-info">
            <div class="stat-label">Precisam de Pedido</div>
            <div class="stat-value"><?= count($precisamPedido) ?></div>
            <div class="stat-change neutral">Estoque crítico ou abaixo do mínimo</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-cart-shopping"></i></div>
        <div class="stat-info">
            <div class="stat-label">Custo Estimado Pedido</div>
            <div class="stat-value"><?= formatMoney($totalCustoPedido) ?></div>
            <div class="stat-change neutral">Para cobrir <?= $periodo*2 ?> dias</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-box-archive"></i></div>
        <div class="stat-info">
            <div class="stat-label">Produtos Parados</div>
            <div class="stat-value"><?= count($produtosParados) ?></div>
            <div class="stat-change neutral">Sem venda há +<?= $paradoDias ?> dias</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-money-bill-wave"></i></div>
        <div class="stat-info">
            <div class="stat-label">Capital Parado</div>
            <div class="stat-value"><?= formatMoney($totalParadoValor) ?></div>
            <div class="stat-change neutral">Em estoque sem giro</div>
        </div>
    </div>
</div>

<!-- Abas -->
<div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:2px solid var(--border);padding-bottom:0">
    <?php
    $abas = [
        'pedido' => ['icon'=>'fa-cart-plus',    'label'=>'Sugestão de Pedido',  'count'=>count($precisamPedido),  'cor'=>'var(--danger)'],
        'parado' => ['icon'=>'fa-box-archive',   'label'=>'Produtos Parados',    'count'=>count($produtosParados), 'cor'=>'var(--warning)'],
        'giro'   => ['icon'=>'fa-chart-line',    'label'=>'Giro Completo',       'count'=>count($todosProdutos),   'cor'=>'var(--primary)'],
    ];
    foreach ($abas as $k => $a): ?>
    <a href="?aba=<?= $k ?>&dias=<?= $periodo ?>&parado=<?= $paradoDias ?>&cat=<?= $catFiltro ?>&forn=<?= $fornFiltro ?>"
       style="display:flex;align-items:center;gap:8px;padding:10px 18px;font-weight:700;font-size:13px;text-decoration:none;border-bottom:3px solid <?= $aba===$k ? $a['cor'] : 'transparent' ?>;color:<?= $aba===$k ? $a['cor'] : 'var(--text-secondary)' ?>;margin-bottom:-2px;transition:var(--transition)">
        <i class="fas <?= $a['icon'] ?>"></i>
        <?= $a['label'] ?>
        <span style="background:<?= $aba===$k ? $a['cor'] : 'var(--border)' ?>;color:<?= $aba===$k ? 'white' : 'var(--text-muted)' ?>;border-radius:20px;padding:1px 8px;font-size:11px"><?= $a['count'] ?></span>
    </a>
    <?php endforeach; ?>
</div>

<?php // ══════════════════════════════════════════════════════════════
// ABA: SUGESTÃO DE PEDIDO
// ══════════════════════════════════════════════════════════════════
if ($aba === 'pedido'): ?>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-cart-plus" style="color:var(--danger)"></i> Sugestão de Pedido — <?= count($precisamPedido) ?> produtos</div>
        <div style="display:flex;gap:8px">
            <button class="btn btn-outline btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
            <button class="btn btn-primary btn-sm" onclick="exportarCSV()"><i class="fas fa-file-csv"></i> Exportar CSV</button>
        </div>
    </div>

    <?php if ($precisamPedido): ?>
    <div class="table-wrapper">
        <table id="tabelaPedido">
            <thead>
                <tr>
                    <th>Produto</th>
                    <th>Fornecedor</th>
                    <th style="text-align:center">Estoque<br><small>atual</small></th>
                    <th style="text-align:center">Mínimo</th>
                    <th style="text-align:center">Vendeu<br><small><?= $periodo ?>d</small></th>
                    <th style="text-align:center">Média<br><small>/dia</small></th>
                    <th style="text-align:center">Dias de<br><small>estoque</small></th>
                    <th style="text-align:center">Sugestão<br><small>pedir</small></th>
                    <th style="text-align:center">Qtd ajustada</th>
                    <th style="text-align:right">Custo unit.</th>
                    <th style="text-align:right">Custo total</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($precisamPedido as $p):
                $urgencia = $p['dias_estoque'] <= 3 ? 'danger' : ($p['dias_estoque'] <= 7 ? 'warning' : 'secondary');
                $urgLabel = $p['dias_estoque'] <= 3 ? 'URGENTE' : ($p['dias_estoque'] <= 7 ? 'Atenção' : 'Baixo');
            ?>
            <tr data-id="<?= $p['id'] ?>" data-custo="<?= $p['cost_price'] ?>" data-nome="<?= htmlspecialchars($p['name']) ?>" data-forn="<?= htmlspecialchars($p['fornecedor']??'—') ?>">
                <td>
                    <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($p['name']) ?></div>
                    <div style="font-size:11px;color:var(--text-muted)"><?= $p['codigo'] ?? $p['code'] ?> · <?= htmlspecialchars($p['categoria']??'—') ?></div>
                </td>
                <td style="font-size:13px;color:var(--text-secondary)"><?= htmlspecialchars($p['fornecedor']??'—') ?></td>
                <td style="text-align:center;font-weight:700;color:<?= $p['stock_quantity']<=0?'var(--danger)':'var(--text-primary)' ?>">
                    <?= number_format($p['stock_quantity'],1) ?> <small><?= $p['unit'] ?></small>
                </td>
                <td style="text-align:center;color:var(--text-muted);font-size:13px"><?= number_format($p['min_stock'],1) ?></td>
                <td style="text-align:center;font-size:13px"><?= number_format($p['qtd_vendida'],1) ?></td>
                <td style="text-align:center;font-size:13px"><?= number_format($p['media_dia'],2) ?></td>
                <td style="text-align:center">
                    <span class="badge badge-<?= $urgencia ?>" style="font-size:11px">
                        <?= $p['dias_estoque'] >= 9999 ? '∞' : $p['dias_estoque'] ?>d
                    </span>
                </td>
                <td style="text-align:center;font-weight:700;font-size:15px;color:var(--primary)"><?= $p['sugerido'] ?></td>
                <td style="text-align:center">
                    <input type="number"
                           class="form-control qty-ajuste"
                           style="width:80px;text-align:center;margin:0 auto;font-weight:700"
                           value="<?= $p['sugerido'] ?>"
                           min="0" step="1"
                           data-custo="<?= $p['cost_price'] ?>"
                           onchange="recalcularTotal(this)">
                </td>
                <td style="text-align:right;font-size:13px"><?= formatMoney($p['cost_price']) ?></td>
                <td style="text-align:right;font-weight:700" class="td-total">
                    <?= formatMoney($p['sugerido'] * $p['cost_price']) ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--bg);font-weight:800">
                    <td colspan="9" style="text-align:right;padding:12px;font-size:13px">TOTAL ESTIMADO DO PEDIDO:</td>
                    <td colspan="2" style="text-align:right;padding:12px;font-size:16px;color:var(--primary)" id="totalPedido"><?= formatMoney($totalCustoPedido) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <div class="empty-icon" style="color:var(--success)"><i class="fas fa-circle-check"></i></div>
        <div class="empty-title">Estoque OK!</div>
        <div class="empty-text">Nenhum produto precisa de reposição nos próximos <?= $periodo ?> dias.</div>
    </div>
    <?php endif; ?>
</div>

<?php // ══════════════════════════════════════════════════════════════
// ABA: PRODUTOS PARADOS
// ══════════════════════════════════════════════════════════════════
elseif ($aba === 'parado'): ?>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-box-archive" style="color:var(--warning)"></i> Produtos Parados há +<?= $paradoDias ?> dias — <?= count($produtosParados) ?> itens</div>
        <div style="font-size:13px;color:var(--text-muted)">Capital imobilizado: <strong style="color:var(--danger)"><?= formatMoney($totalParadoValor) ?></strong></div>
    </div>

    <?php if ($produtosParados): ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Produto</th>
                    <th>Categoria</th>
                    <th>Fornecedor</th>
                    <th style="text-align:center">Estoque</th>
                    <th style="text-align:center">Última venda</th>
                    <th style="text-align:center">Dias parado</th>
                    <th style="text-align:right">Custo unit.</th>
                    <th style="text-align:right">Capital imob.</th>
                    <th style="text-align:center">Total vendido<br><small>ever</small></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($produtosParados as $p):
                $diasP = $p['dias_sem_vender'];
                $corDias = $diasP >= 90 ? 'var(--danger)' : ($diasP >= 60 ? 'var(--warning)' : 'var(--text-secondary)');
                $capitalParado = $p['stock_quantity'] * $p['cost_price'];
            ?>
            <tr>
                <td>
                    <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($p['name']) ?></div>
                    <div style="font-size:11px;color:var(--text-muted)"><?= $p['code'] ?><?= $p['barcode'] ? ' · '.$p['barcode'] : '' ?></div>
                </td>
                <td style="font-size:13px"><?= htmlspecialchars($p['categoria']??'—') ?></td>
                <td style="font-size:13px;color:var(--text-secondary)"><?= htmlspecialchars($p['fornecedor']??'—') ?></td>
                <td style="text-align:center;font-weight:700"><?= number_format($p['stock_quantity'],1) ?> <?= $p['unit'] ?></td>
                <td style="text-align:center;font-size:12px;color:var(--text-muted)">
                    <?= $p['ultima_venda'] ? formatDate($p['ultima_venda']) : 'Nunca vendeu' ?>
                </td>
                <td style="text-align:center">
                    <span style="font-weight:800;font-size:15px;color:<?= $corDias ?>">
                        <?= $diasP >= 9999 ? '∞' : $diasP ?>
                    </span>
                    <div style="font-size:10px;color:var(--text-muted)">dias</div>
                </td>
                <td style="text-align:right;font-size:13px"><?= formatMoney($p['cost_price']) ?></td>
                <td style="text-align:right;font-weight:700;color:var(--danger)"><?= formatMoney($capitalParado) ?></td>
                <td style="text-align:center;font-size:13px;color:var(--text-muted)"><?= number_format($p['qtd_vendida'],1) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--bg);font-weight:800">
                    <td colspan="7" style="text-align:right;padding:12px;font-size:13px">CAPITAL IMOBILIZADO TOTAL:</td>
                    <td style="text-align:right;padding:12px;font-size:16px;color:var(--danger)"><?= formatMoney($totalParadoValor) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <div class="empty-icon" style="color:var(--success)"><i class="fas fa-circle-check"></i></div>
        <div class="empty-title">Nenhum produto parado!</div>
        <div class="empty-text">Todos os produtos com estoque tiveram venda nos últimos <?= $paradoDias ?> dias.</div>
    </div>
    <?php endif; ?>
</div>

<?php // ══════════════════════════════════════════════════════════════
// ABA: GIRO COMPLETO
// ══════════════════════════════════════════════════════════════════
else: ?>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-chart-line" style="color:var(--primary)"></i> Giro de Todos os Produtos</div>
        <div style="display:flex;gap:8px;align-items:center">
            <input type="text" id="buscaGiro" class="form-control" style="width:220px" placeholder="Buscar produto..." oninput="filtrarGiro(this.value)">
        </div>
    </div>
    <div class="table-wrapper">
        <table id="tabelaGiro">
            <thead>
                <tr>
                    <th>Produto</th>
                    <th>Cat.</th>
                    <th style="text-align:center">Estoque</th>
                    <th style="text-align:center">Vendeu<br><small><?= $periodo ?>d</small></th>
                    <th style="text-align:center">Receita<br><small><?= $periodo ?>d</small></th>
                    <th style="text-align:center">Média/dia</th>
                    <th style="text-align:center">Dias<br><small>estoque</small></th>
                    <th style="text-align:center">Última<br><small>venda</small></th>
                    <th style="text-align:center">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($todosProdutos as $p):
                // Status de giro
                if ($p['qtd_vendida'] == 0 && $p['stock_quantity'] > 0) {
                    $status = ['label'=>'Parado','cor'=>'badge-danger'];
                } elseif ($p['qtd_vendida'] == 0) {
                    $status = ['label'=>'Sem estoque','cor'=>'badge-secondary'];
                } elseif ($p['dias_estoque'] < 7) {
                    $status = ['label'=>'Crítico','cor'=>'badge-danger'];
                } elseif ($p['dias_estoque'] < $periodo) {
                    $status = ['label'=>'Atenção','cor'=>'badge-warning'];
                } else {
                    $status = ['label'=>'OK','cor'=>'badge-success'];
                }
                $diasLabel = $p['dias_estoque'] >= 9999 ? '∞' : $p['dias_estoque'];
            ?>
            <tr class="giro-row">
                <td>
                    <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($p['name']) ?></div>
                    <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($p['fornecedor']??'') ?></div>
                </td>
                <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($p['categoria']??'—') ?></td>
                <td style="text-align:center;font-weight:600"><?= number_format($p['stock_quantity'],1) ?> <small><?= $p['unit'] ?></small></td>
                <td style="text-align:center;font-weight:600"><?= number_format($p['qtd_vendida'],1) ?></td>
                <td style="text-align:center;font-size:13px"><?= formatMoney($p['receita']) ?></td>
                <td style="text-align:center;font-size:13px"><?= number_format($p['media_dia'],2) ?>/d</td>
                <td style="text-align:center;font-weight:700"><?= $diasLabel ?><?= $p['dias_estoque']<9999?'d':'' ?></td>
                <td style="text-align:center;font-size:12px;color:var(--text-muted)">
                    <?= $p['ultima_venda'] ? date('d/m', strtotime($p['ultima_venda'])) : '—' ?>
                </td>
                <td style="text-align:center"><span class="badge <?= $status['cor'] ?>"><?= $status['label'] ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<script>
// Recalcula total do pedido ao editar quantidade
function recalcularTotal(input) {
    const row   = input.closest('tr');
    const custo = parseFloat(input.dataset.custo) || 0;
    const qty   = parseFloat(input.value) || 0;
    const total = custo * qty;
    row.querySelector('.td-total').textContent = 'R$ ' + total.toLocaleString('pt-BR', {minimumFractionDigits:2});

    // Recalcula rodapé
    let grand = 0;
    document.querySelectorAll('.qty-ajuste').forEach(i => {
        grand += (parseFloat(i.dataset.custo)||0) * (parseFloat(i.value)||0);
    });
    document.getElementById('totalPedido').textContent = 'R$ ' + grand.toLocaleString('pt-BR', {minimumFractionDigits:2});
}

// Exporta pedido como CSV
function exportarCSV() {
    const rows = document.querySelectorAll('#tabelaPedido tbody tr');
    let csv = 'Produto;Fornecedor;Estoque Atual;Qtd Sugerida;Qtd Ajustada;Custo Unit;Custo Total\n';
    rows.forEach(row => {
        const nome  = row.dataset.nome  || '';
        const forn  = row.dataset.forn  || '';
        const estq  = row.cells[2].textContent.trim();
        const sugr  = row.cells[7].textContent.trim();
        const ajust = row.querySelector('.qty-ajuste').value;
        const custo = row.cells[9].textContent.trim();
        const total = row.querySelector('.td-total').textContent.trim();
        csv += `"${nome}";"${forn}";"${estq}";"${sugr}";"${ajust}";"${custo}";"${total}"\n`;
    });
    const blob = new Blob(['\ufeff'+csv], {type:'text/csv;charset=utf-8'});
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = 'pedido_<?= date('Y-m-d') ?>.csv';
    a.click(); URL.revokeObjectURL(url);
}

// Filtra tabela de giro
function filtrarGiro(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.giro-row').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>

<style>
@media print {
    .sidebar, .topbar, .actions-bar, form, .card-header .btn, #buscaGiro { display: none !important; }
    .main-wrapper { margin: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ddd !important; }
    .qty-ajuste { border: none !important; font-weight: 700; }
}
</style>

<?php include __DIR__.'/../includes/footer.php'; ?>
