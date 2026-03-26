<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();
$pageTitle = 'Caixa';
$db = getDB();
$userId = $_SESSION['user_id'];

// ── API ──────────────────────────────────────────────────────────────────
if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];

    // Abre caixa
    if ($action === 'abrir') {
        // Verifica se já há caixa aberto por este operador
        $jaAberto = $db->prepare("SELECT id FROM caixas WHERE user_id=? AND status='aberto'");
        $jaAberto->execute([$userId]);
        if ($jaAberto->fetch()) { echo json_encode(['success'=>false,'message'=>'Você já tem um caixa aberto.']); exit; }

        $valor = floatval($body['valor_abertura'] ?? 0);
        $obs   = $body['observacao'] ?? '';
        $db->prepare("INSERT INTO caixas (user_id,status,valor_abertura,observacao_abertura,aberto_em) VALUES (?,?,?,?,NOW())")
           ->execute([$userId,'aberto',$valor,$obs]);
        $id = $db->lastInsertId();
        echo json_encode(['success'=>true,'caixa_id'=>$id,'message'=>'Caixa aberto com sucesso!']);
        exit;
    }

    // Fecha caixa
    if ($action === 'fechar') {
        $caixaId = intval($body['caixa_id']);
        $valorFechamento = floatval($body['valor_fechamento'] ?? 0);
        $obs = $body['observacao'] ?? '';

        $caixa = $db->prepare("SELECT * FROM caixas WHERE id=? AND user_id=? AND status='aberto'");
        $caixa->execute([$caixaId, $userId]);
        $caixa = $caixa->fetch();
        if (!$caixa) { echo json_encode(['success'=>false,'message'=>'Caixa não encontrado ou já fechado.']); exit; }

        $db->prepare("UPDATE caixas SET status='fechado',valor_fechamento=?,observacao_fechamento=?,fechado_em=NOW() WHERE id=?")
           ->execute([$valorFechamento, $obs, $caixaId]);

        $rel = buildRelatorio($db, $caixaId, $caixa);
        echo json_encode(['success'=>true,'message'=>'Caixa fechado!','relatorio'=>$rel]);
        exit;
    }

    // Sangria / Suprimento
    if ($action === 'movimento') {
        $caixaId = intval($body['caixa_id']);
        $tipo    = $body['tipo'] === 'suprimento' ? 'suprimento' : 'sangria';
        $valor   = floatval($body['valor']);
        $motivo  = $body['motivo'] ?? '';
        if ($valor <= 0) { echo json_encode(['success'=>false,'message'=>'Valor inválido.']); exit; }

        $db->prepare("INSERT INTO caixa_movimentos (caixa_id,user_id,tipo,valor,motivo) VALUES (?,?,?,?,?)")
           ->execute([$caixaId,$userId,$tipo,$valor,$motivo]);
        $label = $tipo === 'sangria' ? 'Sangria' : 'Suprimento';
        echo json_encode(['success'=>true,'message'=>"$label de ".formatMoney($valor)." registrada!"]);
        exit;
    }

    // Relatório de caixa já fechado
    if ($action === 'relatorio') {
        $caixaId = intval($_GET['caixa_id']);
        $caixa = $db->query("SELECT * FROM caixas WHERE id=$caixaId")->fetch();
        if (!$caixa) { echo json_encode(['success'=>false]); exit; }
        echo json_encode(['success'=>true,'relatorio'=>buildRelatorio($db,$caixaId,$caixa)]);
        exit;
    }

    exit;
}

// ── Monta relatório completo ─────────────────────────────────────────────
function buildRelatorio($db, $caixaId, $caixa) {
    $abertura  = $caixa['aberto_em'];
    $fechamento = $caixa['fechado_em'] ?? date('Y-m-d H:i:s');

    // Vendas no período do caixa por forma de pagamento
    $vendas = $db->prepare("
        SELECT payment_method, COUNT(*) as qtd, SUM(total) as total
        FROM sales
        WHERE status='finalizada'
          AND created_at >= ? AND created_at <= ?
        GROUP BY payment_method
    ");
    $vendas->execute([$abertura, $fechamento]);
    $vendas = $vendas->fetchAll();

    // Total cancelamentos
    $cancelados = $db->prepare("SELECT COUNT(*) as qtd, COALESCE(SUM(total),0) as total FROM sales WHERE status='cancelada' AND created_at >= ? AND created_at <= ?");
    $cancelados->execute([$abertura,$fechamento]);
    $cancelados = $cancelados->fetch();

    // Movimentos (sangrias/suprimentos)
    $movs = $db->prepare("SELECT cm.*, u.name as user_name FROM caixa_movimentos cm JOIN users u ON cm.user_id=u.id WHERE cm.caixa_id=? ORDER BY cm.created_at");
    $movs->execute([$caixaId]);
    $movs = $movs->fetchAll();

    $totalSangrias    = array_sum(array_column(array_filter($movs, fn($m)=>$m['tipo']==='sangria'), 'valor'));
    $totalSuprimentos = array_sum(array_column(array_filter($movs, fn($m)=>$m['tipo']==='suprimento'), 'valor'));

    $pmLabels = ['dinheiro'=>'Dinheiro','pix'=>'Pix','cartao_debito'=>'Cartão Débito','cartao_credito'=>'Cartão Crédito','fiado'=>'Fiado'];

    $totalVendas = array_sum(array_column($vendas, 'total'));
    $totalQtd    = array_sum(array_column($vendas, 'qtd'));

    // Baixas de fiado recebidas neste caixa
    $fiadoBaixas = $db->prepare("
        SELECT fp.forma_pagamento, COUNT(*) as qtd, SUM(fp.valor) as total, c.name as cliente
        FROM fiado_pagamentos fp
        JOIN customers c ON c.id = fp.customer_id
        WHERE fp.caixa_id = ?
        GROUP BY fp.forma_pagamento
    ");
    $fiadoBaixas->execute([$caixaId]);
    $fiadoBaixas = $fiadoBaixas->fetchAll();
    $totalFiadoBaixado = array_sum(array_column($fiadoBaixas, 'total'));

    // Dinheiro em caixa = abertura + suprimentos + dinheiro vendido + dinheiro de fiado baixado - sangrias
    $dinheiroVendido = 0;
    foreach ($vendas as $v) { if ($v['payment_method']==='dinheiro') $dinheiroVendido = $v['total']; }
    $dinheiroBaixaFiado = 0;
    foreach ($fiadoBaixas as $fb) { if ($fb['forma_pagamento']==='dinheiro') $dinheiroBaixaFiado = $fb['total']; }
    $dinheiroEsperado = $caixa['valor_abertura'] + $totalSuprimentos + $dinheiroVendido + $dinheiroBaixaFiado - $totalSangrias;

    $diferenca = ($caixa['valor_fechamento'] ?? 0) - $dinheiroEsperado;

    return [
        'caixa'              => $caixa,
        'vendas'             => $vendas,
        'pm_labels'          => $pmLabels,
        'total_vendas'       => $totalVendas,
        'total_qtd'          => $totalQtd,
        'cancelados'         => $cancelados,
        'movimentos'         => $movs,
        'total_sangrias'     => $totalSangrias,
        'total_suprimentos'  => $totalSuprimentos,
        'dinheiro_esperado'  => $dinheiroEsperado,
        'diferenca'          => $diferenca,
        'fiado_baixas'       => $fiadoBaixas,
        'total_fiado_baixado'=> $totalFiadoBaixado,
    ];
}

// ── Verifica caixa atual ─────────────────────────────────────────────────
$caixaAberto = $db->prepare("SELECT c.*, u.name as user_name FROM caixas c JOIN users u ON c.user_id=u.id WHERE c.user_id=? AND c.status='aberto' ORDER BY c.aberto_em DESC LIMIT 1");
$caixaAberto->execute([$userId]);
$caixaAberto = $caixaAberto->fetch();

// Últimos caixas fechados
$historico = $db->prepare("SELECT c.*,u.name as user_name FROM caixas c JOIN users u ON c.user_id=u.id WHERE c.status='fechado' ORDER BY c.fechado_em DESC LIMIT 15");
$historico->execute();
$historico = $historico->fetchAll();

include __DIR__.'/../includes/header.php';
?>

<?php if (!$caixaAberto): ?>
<!-- ── CAIXA FECHADO: Tela de abertura ──────────────────────────────── -->
<div style="max-width:520px;margin:40px auto">
    <div class="card">
        <div class="card-body" style="text-align:center;padding:40px 32px">
            <div style="width:80px;height:80px;background:var(--warning-light,#fef3c7);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:36px;color:#d97706">
                <i class="fas fa-cash-register"></i>
            </div>
            <div style="font-size:22px;font-weight:800;margin-bottom:8px">Caixa Fechado</div>
            <div style="color:var(--text-muted);margin-bottom:32px">Informe o valor em dinheiro para iniciar o caixa</div>

            <div class="form-group" style="text-align:left">
                <label class="form-label" style="font-size:15px">💵 Valor de Abertura (troco inicial)</label>
                <input type="number" id="valorAbertura" class="form-control" style="font-size:22px;text-align:center;height:56px" step="0.01" min="0" value="0" placeholder="0,00">
                <div class="form-hint">Dinheiro que está no caixa antes das vendas começarem</div>
            </div>
            <div class="form-group" style="text-align:left">
                <label class="form-label">Observação (opcional)</label>
                <input type="text" id="obsAbertura" class="form-control" placeholder="Ex: Troco recebido do gerente...">
            </div>
            <button class="btn btn-success btn-block btn-lg" style="height:52px;font-size:16px;margin-top:8px" onclick="abrirCaixa()">
                <i class="fas fa-lock-open"></i> Abrir Caixa
            </button>
        </div>
    </div>
</div>

<?php else:
    // Busca movimentos do caixa aberto
    $movimentos = $db->prepare("SELECT cm.*,u.name as uname FROM caixa_movimentos cm JOIN users u ON cm.user_id=u.id WHERE cm.caixa_id=? ORDER BY cm.created_at DESC");
    $movimentos->execute([$caixaAberto['id']]);
    $movimentos = $movimentos->fetchAll();
    $totalSangrias = array_sum(array_column(array_filter($movimentos,fn($m)=>$m['tipo']==='sangria'),'valor'));
    $totalSuprimentos = array_sum(array_column(array_filter($movimentos,fn($m)=>$m['tipo']==='suprimento'),'valor'));

    // Resumo de vendas do caixa atual
    $vendasAtual = $db->prepare("SELECT payment_method, COUNT(*) as qtd, SUM(total) as total FROM sales WHERE status='finalizada' AND created_at >= ? GROUP BY payment_method");
    $vendasAtual->execute([$caixaAberto['aberto_em']]);
    $vendasAtual = $vendasAtual->fetchAll();
    $totalVendasAtual = array_sum(array_column($vendasAtual,'total'));
    $pmLabels = ['dinheiro'=>'💵 Dinheiro','pix'=>'🔷 Pix','cartao_debito'=>'💳 Débito','cartao_credito'=>'💳 Crédito','fiado'=>'🤝 Fiado'];
?>
<!-- ── CAIXA ABERTO ──────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start">

    <!-- COLUNA ESQUERDA -->
    <div style="display:flex;flex-direction:column;gap:16px">

        <!-- Status card -->
        <div style="background:linear-gradient(135deg,#065f46,#047857);border-radius:16px;padding:24px;color:white">
            <div style="display:flex;justify-content:space-between;align-items:flex-start">
                <div>
                    <div style="font-size:12px;opacity:.7;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Caixa Aberto</div>
                    <div style="font-size:28px;font-weight:800"><?= formatMoney($totalVendasAtual) ?></div>
                    <div style="font-size:13px;opacity:.8;margin-top:4px">em vendas desde <?= date('H:i', strtotime($caixaAberto['aberto_em'])) ?></div>
                </div>
                <div style="text-align:right;font-size:13px;opacity:.8">
                    <div>Operador: <strong><?= htmlspecialchars($caixaAberto['user_name']) ?></strong></div>
                    <div style="margin-top:4px">Abertura: <?= formatMoney($caixaAberto['valor_abertura']) ?></div>
                    <?php if ($totalSangrias > 0): ?>
                    <div style="margin-top:4px;color:#fca5a5">Sangrias: - <?= formatMoney($totalSangrias) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Barra de pagamentos -->
            <?php if ($vendasAtual): ?>
            <div style="margin-top:20px;display:flex;flex-wrap:wrap;gap:10px">
                <?php foreach ($vendasAtual as $v): ?>
                <div style="background:rgba(255,255,255,.15);border-radius:8px;padding:8px 14px;font-size:13px">
                    <div style="opacity:.7;font-size:11px"><?= $pmLabels[$v['payment_method']] ?? $v['payment_method'] ?></div>
                    <div style="font-weight:800;margin-top:2px"><?= formatMoney($v['total']) ?></div>
                    <div style="font-size:11px;opacity:.6"><?= $v['qtd'] ?> vendas</div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Movimentos do caixa -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-list-ul"></i> Movimentações</div>
                <div style="font-size:12px;color:var(--text-muted)"><?= count($movimentos) ?> registros</div>
            </div>
            <?php if ($movimentos): ?>
            <div class="table-wrapper" style="max-height:280px;overflow-y:auto">
                <table>
                    <thead><tr>
                        <th>Hora</th><th>Tipo</th><th>Valor</th><th>Motivo</th><th>Operador</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($movimentos as $m): ?>
                    <tr>
                        <td style="font-size:12px;color:var(--text-muted)"><?= date('H:i', strtotime($m['created_at'])) ?></td>
                        <td>
                            <span class="badge <?= $m['tipo']==='sangria'?'badge-danger':'badge-success' ?>">
                                <?= $m['tipo']==='sangria' ? '↓ Sangria' : '↑ Suprimento' ?>
                            </span>
                        </td>
                        <td style="font-weight:700;color:<?= $m['tipo']==='sangria'?'var(--danger)':'var(--success)' ?>">
                            <?= $m['tipo']==='sangria'?'-':'+' ?><?= formatMoney($m['valor']) ?>
                        </td>
                        <td style="font-size:13px;color:var(--text-secondary)"><?= htmlspecialchars($m['motivo']??'—') ?></td>
                        <td style="font-size:12px"><?= htmlspecialchars($m['uname']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px">
                <i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;opacity:.4"></i>
                Nenhuma movimentação ainda
            </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- COLUNA DIREITA: Ações -->
    <div style="display:flex;flex-direction:column;gap:14px">

        <!-- Sangria -->
        <div class="card">
            <div class="card-header"><div class="card-title" style="color:var(--danger)"><i class="fas fa-arrow-down"></i> Sangria (retirada)</div></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Valor retirado</label>
                    <input type="number" id="valorSangria" class="form-control" style="font-size:18px;text-align:center" step="0.01" min="0.01" placeholder="0,00">
                </div>
                <div class="form-group">
                    <label class="form-label">Motivo</label>
                    <input type="text" id="motivoSangria" class="form-control" placeholder="Ex: Depósito bancário, pagamento fornecedor...">
                </div>
                <button class="btn btn-block" style="background:#fee2e2;color:#dc2626;font-weight:700;border:1px solid #fca5a5" onclick="registrarMovimento('sangria')">
                    <i class="fas fa-arrow-circle-down"></i> Registrar Sangria
                </button>
            </div>
        </div>

        <!-- Suprimento -->
        <div class="card">
            <div class="card-header"><div class="card-title" style="color:var(--success)"><i class="fas fa-arrow-up"></i> Suprimento (entrada)</div></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Valor adicionado</label>
                    <input type="number" id="valorSuprimento" class="form-control" style="font-size:18px;text-align:center" step="0.01" min="0.01" placeholder="0,00">
                </div>
                <div class="form-group">
                    <label class="form-label">Motivo</label>
                    <input type="text" id="motivoSuprimento" class="form-control" placeholder="Ex: Troco adicional, devolução...">
                </div>
                <button class="btn btn-block" style="background:#d1fae5;color:#065f46;font-weight:700;border:1px solid #6ee7b7" onclick="registrarMovimento('suprimento')">
                    <i class="fas fa-arrow-circle-up"></i> Registrar Suprimento
                </button>
            </div>
        </div>

        <!-- Fechar caixa -->
        <div class="card" style="border:2px solid var(--danger)">
            <div class="card-header" style="background:#fef2f2"><div class="card-title" style="color:var(--danger)"><i class="fas fa-lock"></i> Fechar Caixa</div></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Valor contado em dinheiro</label>
                    <input type="number" id="valorFechamento" class="form-control" style="font-size:18px;text-align:center" step="0.01" min="0" placeholder="Quanto tem no caixa agora?">
                </div>
                <div class="form-group">
                    <label class="form-label">Observação</label>
                    <input type="text" id="obsFechamento" class="form-control" placeholder="Opcional...">
                </div>
                <button class="btn btn-danger btn-block" style="height:48px;font-size:15px" onclick="fecharCaixa()">
                    <i class="fas fa-lock"></i> Fechar e Gerar Relatório
                </button>
            </div>
        </div>

    </div>
</div>
<?php endif; ?>

<!-- ── Histórico ──────────────────────────────────────────────────────── -->
<?php if ($historico): ?>
<div class="card" style="margin-top:20px">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-history"></i> Histórico de Caixas</div>
    </div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Abertura</th><th>Fechamento</th><th>Operador</th><th>Valor Abertura</th><th>Valor Fechamento</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($historico as $h): ?>
            <tr>
                <td style="font-size:12px"><?= formatDateTime($h['aberto_em']) ?></td>
                <td style="font-size:12px"><?= formatDateTime($h['fechado_em']) ?></td>
                <td><?= htmlspecialchars($h['user_name']) ?></td>
                <td><?= formatMoney($h['valor_abertura']) ?></td>
                <td><?= $h['valor_fechamento'] !== null ? formatMoney($h['valor_fechamento']) : '—' ?></td>
                <td>
                    <button class="btn btn-ghost btn-sm" onclick="verRelatorio(<?= $h['id'] ?>)" title="Ver relatório">
                        <i class="fas fa-chart-bar"></i> Relatório
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Modal Relatório ────────────────────────────────────────────────── -->
<div class="modal modal-lg" id="relatorioModal">
    <div class="modal-box" style="max-width:680px">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-chart-bar"></i> Relatório de Fechamento de Caixa</div>
            <button class="modal-close" onclick="closeModal('relatorioModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="relatorioContent" style="padding:0">
            <div style="text-align:center;padding:40px;color:var(--text-muted)"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
            <button class="btn btn-primary" onclick="closeModal('relatorioModal')">Fechar</button>
        </div>
    </div>
</div>

<script>
const CAIXA_ID = <?= $caixaAberto ? $caixaAberto['id'] : 'null' ?>;

async function abrirCaixa() {
    const valor = parseFloat(document.getElementById('valorAbertura').value) || 0;
    const obs   = document.getElementById('obsAbertura').value;
    const res   = await apiCall(`${BASE_PATH}/pages/caixa.php?action=abrir`, {valor_abertura: valor, observacao: obs});
    if (res.success) { showToast(res.message, 'success'); setTimeout(() => location.reload(), 700); }
    else showToast(res.message, 'error');
}

async function registrarMovimento(tipo) {
    const valor  = parseFloat(document.getElementById(`valor${tipo==='sangria'?'Sangria':'Suprimento'}`).value);
    const motivo = document.getElementById(`motivo${tipo==='sangria'?'Sangria':'Suprimento'}`).value;
    if (!valor || valor <= 0) { showToast('Informe um valor válido.', 'warning'); return; }
    const res = await apiCall(`${BASE_PATH}/pages/caixa.php?action=movimento`, {caixa_id: CAIXA_ID, tipo, valor, motivo});
    if (res.success) {
        showToast(res.message, 'success');
        document.getElementById(`valor${tipo==='sangria'?'Sangria':'Suprimento'}`).value = '';
        document.getElementById(`motivo${tipo==='sangria'?'Sangria':'Suprimento'}`).value = '';
        setTimeout(() => location.reload(), 900);
    } else showToast(res.message, 'error');
}

async function fecharCaixa() {
    const valor = document.getElementById('valorFechamento').value;
    const obs   = document.getElementById('obsFechamento').value;
    if (valor === '') { showToast('Informe o valor contado em caixa.', 'warning'); return; }
    const ok = await showConfirm({
        title: 'Fechar o caixa?',
        message: 'Será gerado o relatório de fechamento. Esta ação não pode ser desfeita.',
        type: 'warning', icon: '🔒', confirmText: 'Sim, fechar'
    });
    if (!ok) return;

    const res = await apiCall(`${BASE_PATH}/pages/caixa.php?action=fechar`, {
        caixa_id: CAIXA_ID,
        valor_fechamento: parseFloat(valor),
        observacao: obs
    });
    if (res.success) {
        showToast(res.message, 'success');
        renderRelatorio(res.relatorio);
        openModal('relatorioModal');
        setTimeout(() => location.reload(), 200);
    } else showToast(res.message, 'error');
}

async function verRelatorio(caixaId) {
    openModal('relatorioModal');
    document.getElementById('relatorioContent').innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-muted)"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    const res = await apiCall(`${BASE_PATH}/pages/caixa.php?action=relatorio&caixa_id=${caixaId}`);
    if (res.success) renderRelatorio(res.relatorio);
    else document.getElementById('relatorioContent').innerHTML = '<div style="padding:24px;color:var(--danger)">Erro ao carregar relatório.</div>';
}

function fmt(v) { return 'R$ ' + parseFloat(v||0).toLocaleString('pt-BR', {minimumFractionDigits:2}); }
function fmtDt(s) { if (!s) return '—'; const d = new Date(s.replace(' ','T')); return d.toLocaleString('pt-BR'); }

function renderRelatorio(r) {
    const c     = r.caixa;
    const dif   = parseFloat(r.diferenca);
    const difCor = dif >= 0 ? '#065f46' : '#dc2626';
    const difSinal = dif >= 0 ? '+' : '';
    const pmIcons = {dinheiro:'💵',pix:'🔷',cartao_debito:'💳',cartao_credito:'💳',fiado:'🤝'};
    const pmLabels = r.pm_labels || {dinheiro:'Dinheiro',pix:'Pix',cartao_debito:'Cartão Débito',cartao_credito:'Cartão Crédito',fiado:'Fiado'};

    let vendasHtml = '';
    if (r.vendas && r.vendas.length) {
        vendasHtml = r.vendas.map(v => `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f3f4f6">
                <div style="display:flex;align-items:center;gap:10px">
                    <div style="font-size:22px">${pmIcons[v.payment_method]||'💰'}</div>
                    <div>
                        <div style="font-weight:700;font-size:14px">${pmLabels[v.payment_method]||v.payment_method}</div>
                        <div style="font-size:12px;color:#6b7280">${v.qtd} venda${v.qtd>1?'s':''}</div>
                    </div>
                </div>
                <div style="font-weight:800;font-size:16px;color:#111">${fmt(v.total)}</div>
            </div>
        `).join('');
    } else {
        vendasHtml = '<div style="padding:16px 0;color:#9ca3af;text-align:center;font-size:13px">Nenhuma venda neste período</div>';
    }

    let movsHtml = '';
    if (r.movimentos && r.movimentos.length) {
        movsHtml = `<div style="margin-top:20px">
            <div style="font-weight:700;font-size:13px;color:#374151;margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px">Movimentos de Caixa</div>
            ${r.movimentos.map(m => `
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:13px">
                    <div>
                        <span style="font-weight:700;color:${m.tipo==='sangria'?'#dc2626':'#059669'}">${m.tipo==='sangria'?'↓ Sangria':'↑ Suprimento'}</span>
                        ${m.motivo ? `<span style="color:#6b7280;margin-left:8px">${m.motivo}</span>` : ''}
                        <span style="color:#9ca3af;margin-left:8px;font-size:11px">${m.user_name} · ${new Date(m.created_at.replace(' ','T')).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'})}</span>
                    </div>
                    <div style="font-weight:700;color:${m.tipo==='sangria'?'#dc2626':'#059669'}">${m.tipo==='sangria'?'-':'+'}${fmt(m.valor)}</div>
                </div>
            `).join('')}
        </div>`;
    }

    document.getElementById('relatorioContent').innerHTML = `
        <div style="padding:24px" id="printArea">
            <!-- Cabeçalho -->
            <div style="text-align:center;margin-bottom:24px;padding-bottom:20px;border-bottom:2px solid #e5e7eb">
                <div style="font-size:22px;font-weight:800;color:#111">📊 Relatório de Caixa</div>
                <div style="font-size:13px;color:#6b7280;margin-top:6px">Operador: <strong>${c.user_name||'—'}</strong></div>
                <div style="display:flex;justify-content:center;gap:24px;margin-top:10px;font-size:13px">
                    <div>🔓 Abertura: <strong>${fmtDt(c.aberto_em)}</strong></div>
                    <div>🔒 Fechamento: <strong>${fmtDt(c.fechado_em||null)}</strong></div>
                </div>
            </div>

            <!-- Cards resumo -->
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px">
                <div style="background:#f0fdf4;border-radius:12px;padding:16px;text-align:center;border:1px solid #bbf7d0">
                    <div style="font-size:11px;color:#065f46;font-weight:700;text-transform:uppercase">Total Vendas</div>
                    <div style="font-size:22px;font-weight:800;color:#065f46;margin-top:4px">${fmt(r.total_vendas)}</div>
                    <div style="font-size:11px;color:#6b7280;margin-top:2px">${r.total_qtd} venda${r.total_qtd!=1?'s':''}</div>
                </div>
                <div style="background:#fff7ed;border-radius:12px;padding:16px;text-align:center;border:1px solid #fed7aa">
                    <div style="font-size:11px;color:#9a3412;font-weight:700;text-transform:uppercase">Sangrias</div>
                    <div style="font-size:22px;font-weight:800;color:#dc2626;margin-top:4px">- ${fmt(r.total_sangrias)}</div>
                    <div style="font-size:11px;color:#6b7280;margin-top:2px">retiradas</div>
                </div>
                <div style="background:${dif>=0?'#f0fdf4':'#fef2f2'};border-radius:12px;padding:16px;text-align:center;border:1px solid ${dif>=0?'#bbf7d0':'#fecaca'}">
                    <div style="font-size:11px;color:${difCor};font-weight:700;text-transform:uppercase">Diferença</div>
                    <div style="font-size:22px;font-weight:800;color:${difCor};margin-top:4px">${difSinal}${fmt(Math.abs(dif))}</div>
                    <div style="font-size:11px;color:#6b7280;margin-top:2px">${dif>=0?'sobra':'falta'} no caixa</div>
                </div>
            </div>

            <!-- Vendas por forma de pagamento -->
            <div style="background:#f9fafb;border-radius:12px;padding:20px;margin-bottom:20px">
                <div style="font-weight:700;font-size:13px;color:#374151;margin-bottom:12px;text-transform:uppercase;letter-spacing:.5px">
                    Vendas por Forma de Pagamento
                </div>
                ${vendasHtml}
                <div style="display:flex;justify-content:space-between;padding-top:12px;margin-top:4px;font-size:15px;font-weight:800">
                    <span>Total</span><span>${fmt(r.total_vendas)}</span>
                </div>
            </div>

            <!-- Conferência de dinheiro -->
            <div style="background:#f9fafb;border-radius:12px;padding:20px;margin-bottom:20px">
                <div style="font-weight:700;font-size:13px;color:#374151;margin-bottom:12px;text-transform:uppercase;letter-spacing:.5px">
                    Conferência de Dinheiro
                </div>
                <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:13px"><span style="color:#6b7280">Valor de abertura</span><span>${fmt(c.valor_abertura)}</span></div>
                ${r.total_suprimentos>0?`<div style="display:flex;justify-content:space-between;padding:6px 0;font-size:13px"><span style="color:#6b7280">Suprimentos</span><span style="color:#059669">+ ${fmt(r.total_suprimentos)}</span></div>`:''}
                <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:13px"><span style="color:#6b7280">Vendas em dinheiro</span><span>+ ${fmt(r.vendas?.find(v=>v.payment_method==='dinheiro')?.total||0)}</span></div>
                ${r.total_sangrias>0?`<div style="display:flex;justify-content:space-between;padding:6px 0;font-size:13px"><span style="color:#6b7280">Sangrias</span><span style="color:#dc2626">- ${fmt(r.total_sangrias)}</span></div>`:''}
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-top:2px solid #e5e7eb;margin-top:6px;font-weight:700"><span>Esperado em caixa</span><span>${fmt(r.dinheiro_esperado)}</span></div>
                <div style="display:flex;justify-content:space-between;padding:6px 0;font-weight:700"><span>Contado pelo operador</span><span>${fmt(c.valor_fechamento)}</span></div>
                <div style="display:flex;justify-content:space-between;padding:10px 12px;background:${dif>=0?'#d1fae5':'#fee2e2'};border-radius:8px;margin-top:8px;font-weight:800;font-size:15px">
                    <span style="color:${difCor}">Diferença</span>
                    <span style="color:${difCor}">${difSinal}${fmt(Math.abs(dif))} ${dif>0?'(sobra)':dif<0?'(falta)':'(certo!)'}</span>
                </div>
            </div>

            ${movsHtml}

            ${r.cancelados?.qtd>0?`<div style="background:#fff7ed;border-radius:12px;padding:16px;margin-top:16px;border:1px solid #fed7aa">
                <div style="font-weight:700;font-size:13px;color:#9a3412">⚠️ Vendas Canceladas no Período: ${r.cancelados.qtd} (${fmt(r.cancelados.total)})</div>
            </div>`:''}
        </div>
    `;
}
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>
