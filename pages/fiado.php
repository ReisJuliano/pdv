<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();
$pageTitle = 'Controle de Fiado';
$db = getDB();

// ── API ──────────────────────────────────────────────────────────────────
if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';

    // Registrar pagamento de fiado
    if ($action === 'pagar') {
        $data       = json_decode(file_get_contents('php://input'), true);
        $customerId = intval($data['customer_id'] ?? 0);
        $valor      = floatval($data['valor'] ?? 0);
        $obs        = $data['obs'] ?? '';

        if (!$customerId || $valor <= 0) {
            echo json_encode(['success' => false, 'message' => 'Dados inválidos.']);
            exit;
        }

        // Busca saldo devedor atual
        $saldoAtual = $db->prepare("SELECT COALESCE(SUM(total),0) FROM sales WHERE customer_id=? AND payment_method='fiado' AND status='finalizada'");
        $saldoAtual->execute([$customerId]);
        $totalFiado = floatval($saldoAtual->fetchColumn());

        $jaFoi = $db->prepare("SELECT COALESCE(SUM(valor),0) FROM fiado_pagamentos WHERE customer_id=?");
        $jaFoi->execute([$customerId]);
        $totalPago = floatval($jaFoi->fetchColumn());

        $saldo = $totalFiado - $totalPago;

        if ($valor > $saldo + 0.001) {
            echo json_encode(['success' => false, 'message' => 'Valor maior que o saldo devedor (R$ ' . number_format($saldo, 2, ',', '.') . ').']);
            exit;
        }

        $db->prepare("INSERT INTO fiado_pagamentos (customer_id, user_id, valor, observacao) VALUES (?,?,?,?)")
           ->execute([$customerId, $_SESSION['user_id'], $valor, $obs]);

        echo json_encode(['success' => true, 'message' => 'Pagamento de ' . formatMoney($valor) . ' registrado!']);
        exit;
    }

    // Detalhes das vendas fiado de um cliente
    if ($action === 'detalhe') {
        $customerId = intval($_GET['customer_id']);
        $vendas = $db->prepare("
            SELECT s.id, s.sale_number, s.total, s.created_at, s.notes
            FROM sales s
            WHERE s.customer_id=? AND s.payment_method='fiado' AND s.status='finalizada'
            ORDER BY s.created_at DESC
        ");
        $vendas->execute([$customerId]);
        $vendas = $vendas->fetchAll();

        $pagamentos = $db->prepare("
            SELECT fp.*, u.name as user_name
            FROM fiado_pagamentos fp
            JOIN users u ON fp.user_id = u.id
            WHERE fp.customer_id=?
            ORDER BY fp.created_at DESC
        ");
        $pagamentos->execute([$customerId]);
        $pagamentos = $pagamentos->fetchAll();

        echo json_encode(['vendas' => $vendas, 'pagamentos' => $pagamentos]);
        exit;
    }

    exit;
}

// ── Garante tabela de pagamentos ─────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `fiado_pagamentos` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `customer_id` int(11) NOT NULL,
        `user_id` int(11) NOT NULL,
        `valor` decimal(10,2) NOT NULL,
        `observacao` text,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

// ── Busca clientes com fiado ─────────────────────────────────────────────
$clientes = $db->query("
    SELECT
        c.id,
        c.name,
        c.phone,
        c.credit_limit,
        COALESCE(SUM(s.total), 0)                              AS total_fiado,
        COALESCE((SELECT SUM(fp.valor) FROM fiado_pagamentos fp WHERE fp.customer_id = c.id), 0) AS total_pago,
        COALESCE(SUM(s.total), 0) - COALESCE((SELECT SUM(fp.valor) FROM fiado_pagamentos fp WHERE fp.customer_id = c.id), 0) AS saldo_devedor,
        MAX(s.created_at)                                      AS ultima_venda,
        COUNT(s.id)                                            AS qtd_vendas
    FROM customers c
    JOIN sales s ON s.customer_id = c.id AND s.payment_method = 'fiado' AND s.status = 'finalizada'
    WHERE c.active = 1
    GROUP BY c.id
    HAVING saldo_devedor > 0.001
    ORDER BY saldo_devedor DESC
")->fetchAll();

// ── Totais gerais ────────────────────────────────────────────────────────
$totalGeral    = array_sum(array_column($clientes, 'total_fiado'));
$totalPagoGeral = array_sum(array_column($clientes, 'total_pago'));
$saldoGeral    = array_sum(array_column($clientes, 'saldo_devedor'));
$totalClientes = count($clientes);

include __DIR__.'/../includes/header.php';
?>

<!-- ── Resumo ──────────────────────────────────────────────────────────── -->
<div class="stats-grid" style="margin-bottom:20px">
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-handshake"></i></div>
        <div class="stat-info">
            <div class="stat-label">Clientes em Débito</div>
            <div class="stat-value"><?= $totalClientes ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-file-invoice-dollar"></i></div>
        <div class="stat-info">
            <div class="stat-label">Total em Fiado</div>
            <div class="stat-value"><?= formatMoney($totalGeral) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-circle-check"></i></div>
        <div class="stat-info">
            <div class="stat-label">Total Recebido</div>
            <div class="stat-value"><?= formatMoney($totalPagoGeral) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-triangle-exclamation"></i></div>
        <div class="stat-info">
            <div class="stat-label">Saldo a Receber</div>
            <div class="stat-value" style="color:var(--danger)"><?= formatMoney($saldoGeral) ?></div>
        </div>
    </div>
</div>

<!-- ── Tabela de clientes ─────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-handshake" style="color:var(--danger)"></i> Clientes com Fiado em Aberto</div>
        <div style="display:flex;gap:8px;align-items:center">
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" id="s" class="form-control" placeholder="Buscar cliente..." oninput="filterTable('s','tbl')" style="width:220px">
            </div>
            <button class="btn btn-outline btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
        </div>
    </div>

    <?php if ($clientes): ?>
    <div class="table-wrapper">
        <table id="tbl">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Telefone</th>
                    <th>Última Venda</th>
                    <th>Qtd Compras</th>
                    <th>Total Fiado</th>
                    <th>Já Pagou</th>
                    <th>Saldo Devedor</th>
                    <th>Limite</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($clientes as $c):
                $saldo = floatval($c['saldo_devedor']);
                $limite = floatval($c['credit_limit']);
                $acimadoLimite = $limite > 0 && $saldo > $limite;
            ?>
            <tr id="row-<?= $c['id'] ?>">
                <td class="td-name">
                    <?= htmlspecialchars($c['name']) ?>
                    <?php if ($acimadoLimite): ?>
                    <span class="badge badge-danger" style="margin-left:6px" title="Acima do limite de crédito">
                        <i class="fas fa-triangle-exclamation"></i> Limite
                    </span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($c['phone'] ?: '—') ?></td>
                <td style="font-size:12px;color:var(--text-muted)"><?= formatDateTime($c['ultima_venda']) ?></td>
                <td style="text-align:center"><?= $c['qtd_vendas'] ?></td>
                <td><?= formatMoney($c['total_fiado']) ?></td>
                <td style="color:var(--success);font-weight:600"><?= formatMoney($c['total_pago']) ?></td>
                <td>
                    <span style="font-weight:800;font-size:15px;color:<?= $acimadoLimite ? 'var(--danger)' : 'var(--warning)' ?>">
                        <?= formatMoney($saldo) ?>
                    </span>
                </td>
                <td>
                    <?php if ($limite > 0): ?>
                    <div style="display:flex;align-items:center;gap:6px">
                        <div style="flex:1;height:6px;background:var(--border);border-radius:3px;min-width:60px;overflow:hidden">
                            <div style="width:<?= min(100, ($saldo/$limite)*100) ?>%;height:100%;background:<?= $acimadoLimite ? 'var(--danger)' : 'var(--warning)' ?>;border-radius:3px"></div>
                        </div>
                        <span style="font-size:11px;white-space:nowrap"><?= formatMoney($limite) ?></span>
                    </div>
                    <?php else: ?>
                    <span style="color:var(--text-muted);font-size:12px">Sem limite</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;gap:4px">
                        <button class="btn btn-ghost btn-sm" title="Ver detalhes / histórico"
                                onclick="verDetalhe(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>')">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-success btn-sm" title="Registrar pagamento"
                                onclick="abrirPagamento(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>', <?= $saldo ?>)">
                            <i class="fas fa-money-bill-wave"></i> Receber
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-handshake"></i></div>
        <div class="empty-title">Nenhum fiado em aberto</div>
        <div class="empty-text">Ótimo! Todos os clientes estão em dia.</div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Modal: Registrar Pagamento ─────────────────────────────────────── -->
<div class="modal modal-sm" id="pagModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-money-bill-wave" style="color:var(--success)"></i> Registrar Pagamento</div>
            <button class="modal-close" onclick="closeModal('pagModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="pagClienteId">
            <div style="background:var(--bg);border-radius:8px;padding:14px;margin-bottom:16px">
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:2px">Cliente</div>
                <div id="pagClienteNome" style="font-weight:700;font-size:15px"></div>
                <div style="margin-top:6px;font-size:13px">
                    Saldo devedor: <strong id="pagSaldo" style="color:var(--danger)"></strong>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Valor Recebido (R$)</label>
                <input type="number" id="pagValor" class="form-control" style="font-size:20px;text-align:center;height:52px" step="0.01" min="0.01" placeholder="0,00">
            </div>
            <div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap" id="pagRapido"></div>
            <div class="form-group">
                <label class="form-label">Observação (opcional)</label>
                <input type="text" id="pagObs" class="form-control" placeholder="Ex: Pagou em dinheiro, Pix...">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('pagModal')">Cancelar</button>
            <button class="btn btn-success" onclick="registrarPagamento()">
                <i class="fas fa-check"></i> Confirmar Pagamento
            </button>
        </div>
    </div>
</div>

<!-- ── Modal: Histórico do Cliente ───────────────────────────────────── -->
<div class="modal modal-lg" id="detalheModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title" id="detalheTitle">Histórico de Fiado</div>
            <button class="modal-close" onclick="closeModal('detalheModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="detalheContent" style="padding:0">
            <div style="text-align:center;padding:40px;color:var(--text-muted)">
                <i class="fas fa-spinner fa-spin fa-2x"></i>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" onclick="closeModal('detalheModal')">Fechar</button>
        </div>
    </div>
</div>

<script>
let _pagSaldo = 0;

function abrirPagamento(id, nome, saldo) {
    _pagSaldo = saldo;
    document.getElementById('pagClienteId').value  = id;
    document.getElementById('pagClienteNome').textContent = nome;
    document.getElementById('pagSaldo').textContent = 'R$ ' + saldo.toLocaleString('pt-BR', {minimumFractionDigits:2});
    document.getElementById('pagValor').value = '';
    document.getElementById('pagObs').value   = '';

    // Botões rápidos: total e frações do saldo
    const rapido = document.getElementById('pagRapido');
    rapido.innerHTML = '';
    const opcoes = [];
    if (saldo > 0)        opcoes.push([saldo,   'Tudo ('+fmt(saldo)+')']);
    if (saldo >= 200)     opcoes.push([100,      'R$ 100']);
    if (saldo >= 100)     opcoes.push([50,       'R$ 50']);
    if (saldo >= 50)      opcoes.push([20,       'R$ 20']);
    opcoes.forEach(([v, label]) => {
        const b = document.createElement('button');
        b.className = 'btn btn-outline btn-sm';
        b.textContent = label;
        b.onclick = () => { document.getElementById('pagValor').value = v.toFixed(2); };
        rapido.appendChild(b);
    });

    openModal('pagModal');
    setTimeout(() => document.getElementById('pagValor').focus(), 200);
}

async function registrarPagamento() {
    const id    = document.getElementById('pagClienteId').value;
    const valor = parseFloat(document.getElementById('pagValor').value);
    const obs   = document.getElementById('pagObs').value;
    if (!valor || valor <= 0) { showToast('Informe um valor válido.', 'warning'); return; }
    if (valor > _pagSaldo + 0.01) { showToast('Valor maior que o saldo devedor.', 'warning'); return; }

    const res = await apiCall(`${BASE_PATH}/pages/fiado.php?action=pagar`, {
        customer_id: id, valor, obs
    });
    if (res.success) {
        showToast(res.message, 'success');
        closeModal('pagModal');
        setTimeout(() => location.reload(), 900);
    } else {
        showToast(res.message, 'error');
    }
}

async function verDetalhe(id, nome) {
    document.getElementById('detalheTitle').innerHTML =
        '<i class="fas fa-history" style="color:var(--primary)"></i> Histórico — ' + nome;
    document.getElementById('detalheContent').innerHTML =
        '<div style="text-align:center;padding:40px;color:var(--text-muted)"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    openModal('detalheModal');

    const res = await apiCall(`${BASE_PATH}/pages/fiado.php?action=detalhe&customer_id=${id}`);
    const vendas     = res.vendas     || [];
    const pagamentos = res.pagamentos || [];

    let html = '<div style="padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:20px">';

    // Vendas fiado
    html += `<div>
        <div style="font-weight:700;font-size:13px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px">
            <i class="fas fa-receipt" style="color:var(--danger)"></i> Vendas em Fiado
        </div>`;
    if (vendas.length) {
        html += vendas.map(v => `
            <div style="display:flex;justify-content:space-between;padding:10px 12px;background:var(--bg);border-radius:8px;margin-bottom:6px;font-size:13px">
                <div>
                    <div style="font-weight:700;color:var(--text-primary)">${v.sale_number}</div>
                    <div style="font-size:11px;color:var(--text-muted)">${new Date(v.created_at.replace(' ','T')).toLocaleString('pt-BR')}</div>
                    ${v.notes ? `<div style="font-size:11px;color:var(--text-muted);margin-top:2px">${v.notes}</div>` : ''}
                </div>
                <div style="font-weight:800;font-size:15px;color:var(--danger)">${fmt(v.total)}</div>
            </div>`).join('');
    } else {
        html += '<div style="text-align:center;padding:16px;color:var(--text-muted);font-size:13px">Nenhuma venda</div>';
    }
    html += '</div>';

    // Pagamentos
    html += `<div>
        <div style="font-weight:700;font-size:13px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px">
            <i class="fas fa-money-bill-wave" style="color:var(--success)"></i> Pagamentos Recebidos
        </div>`;
    if (pagamentos.length) {
        html += pagamentos.map(p => `
            <div style="display:flex;justify-content:space-between;padding:10px 12px;background:#f0fdf4;border-radius:8px;margin-bottom:6px;font-size:13px;border:1px solid #bbf7d0">
                <div>
                    <div style="font-weight:600;color:#065f46">${p.user_name}</div>
                    <div style="font-size:11px;color:#6b7280">${new Date(p.created_at.replace(' ','T')).toLocaleString('pt-BR')}</div>
                    ${p.observacao ? `<div style="font-size:11px;color:#6b7280;margin-top:2px">${p.observacao}</div>` : ''}
                </div>
                <div style="font-weight:800;font-size:15px;color:var(--success)">${fmt(p.valor)}</div>
            </div>`).join('');
    } else {
        html += '<div style="text-align:center;padding:16px;color:var(--text-muted);font-size:13px">Nenhum pagamento</div>';
    }
    html += '</div></div>';

    document.getElementById('detalheContent').innerHTML = html;
}

function fmt(v) {
    return 'R$ ' + parseFloat(v||0).toLocaleString('pt-BR', {minimumFractionDigits:2});
}
</script>

<style>
@media print {
    .sidebar, .topbar, .actions-bar, button, .btn, #s { display: none !important; }
    .main-wrapper { margin-left: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ccc !important; }
}
</style>

<?php include __DIR__.'/../includes/footer.php'; ?>