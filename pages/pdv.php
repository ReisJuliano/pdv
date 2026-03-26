<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();
$pageTitle = 'PDV — Caixa';
$db = getDB();
$categories = $db->query("SELECT * FROM categories WHERE active=1 ORDER BY name")->fetchAll();
$customers  = $db->query("SELECT id,name,credit_limit FROM customers WHERE active=1 ORDER BY name")->fetchAll();

// Verifica se há caixa aberto
$caixaAtual = $db->prepare("SELECT id FROM caixas WHERE user_id=? AND status='aberto' LIMIT 1");
$caixaAtual->execute([$_SESSION['user_id']]);
$caixaAtual = $caixaAtual->fetch();

// Handle sale submission
if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';

    if ($action === 'search') {
        $term = $_GET['term'] ?? '';
        $stmt = $db->prepare("SELECT id,code,name,barcode,sale_price,stock_quantity,unit,cost_price FROM products WHERE active=1 AND (barcode=? OR code=? OR name LIKE ?) LIMIT 12");
        $stmt->execute([$term, $term, "%$term%"]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // ── Salva carrinho no banco ──────────────────────────────────────────
    if ($action === 'cart_save') {
        $data = json_decode(file_get_contents('php://input'), true);
        $uid  = $_SESSION['user_id'];
        $db->prepare("DELETE FROM pdv_carrinho WHERE user_id=?")->execute([$uid]);
        $items   = $data['items']   ?? [];
        $payment = $data['payment'] ?? 'dinheiro';
        $custId  = $data['customer_id'] ? intval($data['customer_id']) : null;
        $disc    = floatval($data['discount'] ?? 0);
        $notes   = $data['notes'] ?? '';
        $pedId   = $data['pedido_id'] ? intval($data['pedido_id']) : null;
        $stmt = $db->prepare("INSERT INTO pdv_carrinho (user_id,product_id,product_name,unit,quantity,unit_price,unit_cost,customer_id,payment_method,discount,notes,pedido_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($items as $it) {
            $stmt->execute([$uid, $it['id'], $it['name'], $it['unit'], $it['qty'], $it['price'], $it['cost'], $custId, $payment, $disc, $notes, $pedId]);
        }
        // Se carrinho vazio, apenas o DELETE já limpou
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Carrega carrinho do banco ────────────────────────────────────────
    if ($action === 'cart_load') {
        $uid  = $_SESSION['user_id'];
        $rows = $db->prepare("SELECT * FROM pdv_carrinho WHERE user_id=? ORDER BY id");
        $rows->execute([$uid]);
        $rows = $rows->fetchAll();
        if (!$rows) { echo json_encode(['items'=>[],'payment'=>'dinheiro','customer_id'=>null,'discount'=>0,'notes'=>'','pedido_id'=>null]); exit; }
        $first = $rows[0];
        echo json_encode([
            'items'       => array_map(fn($r) => ['id'=>$r['product_id'],'name'=>$r['product_name'],'unit'=>$r['unit'],'qty'=>floatval($r['quantity']),'price'=>floatval($r['unit_price']),'cost'=>floatval($r['unit_cost'])], $rows),
            'payment'     => $first['payment_method'],
            'customer_id' => $first['customer_id'],
            'discount'    => floatval($first['discount']),
            'notes'       => $first['notes'],
            'pedido_id'   => $first['pedido_id'],
        ]);
        exit;
    }

    // ── Limpa carrinho do banco ──────────────────────────────────────────
    if ($action === 'cart_clear') {
        $db->prepare("DELETE FROM pdv_carrinho WHERE user_id=?")->execute([$_SESSION['user_id']]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Retorna saldo devedor + limite de um cliente ─────────────────────
    if ($action === 'saldo_fiado') {
        $customerId = intval($_GET['customer_id']);
        if (!$customerId) { echo json_encode(['saldo'=>0,'limite'=>0,'disponivel'=>0]); exit; }

        $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) FROM sales WHERE customer_id=? AND payment_method='fiado' AND status='finalizada'");
        $stmt->execute([$customerId]);
        $totalFiado = floatval($stmt->fetchColumn());

        // Garante tabela de pagamentos existe
        try { $db->exec("CREATE TABLE IF NOT EXISTS `fiado_pagamentos` (`id` int NOT NULL AUTO_INCREMENT, `customer_id` int NOT NULL, `user_id` int NOT NULL, `valor` decimal(10,2) NOT NULL, `observacao` text, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e) {}

        $stmt2 = $db->prepare("SELECT COALESCE(SUM(valor),0) FROM fiado_pagamentos WHERE customer_id=?");
        $stmt2->execute([$customerId]);
        $totalPago = floatval($stmt2->fetchColumn());

        $saldo = $totalFiado - $totalPago;

        $stmt3 = $db->prepare("SELECT credit_limit FROM customers WHERE id=?");
        $stmt3->execute([$customerId]);
        $limite = floatval($stmt3->fetchColumn());

        $disponivel = $limite > 0 ? max(0, $limite - $saldo) : null; // null = sem limite definido

        echo json_encode([
            'saldo'      => round($saldo, 2),
            'limite'     => round($limite, 2),
            'disponivel' => $disponivel !== null ? round($disponivel, 2) : null,
        ]);
        exit;
    }

    // ── Carrega pedido/comanda para o PDV ────────────────────────────────
    if ($action === 'load_pedido') {
        $pedidoId = intval($_GET['pedido_id']);
        $pedido = $db->query("SELECT p.*, c.name as customer_name FROM pedidos p LEFT JOIN customers c ON p.customer_id=c.id WHERE p.id=$pedidoId AND p.status='fechando'")->fetch();
        if (!$pedido) { echo json_encode(['success'=>false,'message'=>'Pedido não encontrado ou não está pronto para cobrança.']); exit; }
        $items = $db->query("SELECT pi.*, pr.name as product_name, pr.unit FROM pedido_items pi JOIN products pr ON pi.product_id=pr.id WHERE pi.pedido_id=$pedidoId")->fetchAll();
        echo json_encode(['success'=>true,'pedido'=>$pedido,'items'=>$items]);
        exit;
    }

    // ── Cadastro rápido de cliente no PDV ────────────────────────────────
    if ($action === 'cadastrar_cliente') {
        $data = json_decode(file_get_contents('php://input'), true);
        $nome = trim($data['name'] ?? '');
        $tel  = trim($data['phone'] ?? '');
        if (!$nome) { echo json_encode(['success'=>false,'message'=>'Nome obrigatório']); exit; }
        $db->prepare("INSERT INTO customers (name, phone) VALUES (?, ?)")->execute([$nome, $tel]);
        $newId = $db->lastInsertId();
        echo json_encode(['success'=>true,'id'=>$newId,'name'=>$nome,'message'=>'Cliente cadastrado!']);
        exit;
    }

    if ($action === 'finalize') {
        $data  = json_decode(file_get_contents('php://input'), true);
        $items = $data['items'] ?? [];
        if (!$items) { echo json_encode(['success'=>false,'message'=>'Carrinho vazio']); exit; }

        // Suporte a pagamento misto: $payments = [['method'=>'dinheiro','valor'=>3.99], ...]
        $payments   = $data['payments'] ?? [];   // novo campo
        $payment    = $data['payment']  ?? 'dinheiro'; // fallback legado
        $customerId = $data['customer_id'] ? intval($data['customer_id']) : null;

        // Normaliza: se veio payments[], usa; senão usa payment simples
        if (empty($payments)) {
            $payments = [['method' => $payment, 'valor' => null]]; // valor null = total
        }

        // Garante tabela sale_payments
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `sale_payments` (
                `id` int NOT NULL AUTO_INCREMENT,
                `sale_id` int NOT NULL,
                `payment_method` varchar(50) NOT NULL,
                `valor` decimal(10,2) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `sale_id` (`sale_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch(Exception $e) {}

        // Determina payment_method principal para a tabela sales
        $methodsUsed = array_unique(array_column($payments, 'method'));
        $paymentMain = count($methodsUsed) === 1 ? $methodsUsed[0] : 'misto';

        // ── Validação de fiado ───────────────────────────────────────────
        $hasFiado = in_array('fiado', array_column($payments, 'method'));
        if ($hasFiado) {
            if (!$customerId) {
                echo json_encode(['success'=>false,'message'=>'Selecione um cliente para venda no fiado.','require_customer'=>true]);
                exit;
            }

            // Calcula total da venda
            $subtotalCalc = 0;
            foreach ($items as $it) $subtotalCalc += $it['price'] * $it['qty'];
            $totalCalc = $subtotalCalc - floatval($data['discount'] ?? 0);

            // Valor do fiado especificamente
            $valorFiado = 0;
            foreach ($payments as $p) { if ($p['method'] === 'fiado') $valorFiado += floatval($p['valor'] ?? $totalCalc); }

            // Busca limite e saldo atual
            $stmt = $db->prepare("SELECT credit_limit FROM customers WHERE id=?");
            $stmt->execute([$customerId]);
            $limite = floatval($stmt->fetchColumn());

            if ($limite > 0) {
                // Saldo devedor atual
                $stmt2 = $db->prepare("SELECT COALESCE(SUM(sp.valor),0) FROM sale_payments sp JOIN sales s ON sp.sale_id=s.id WHERE s.customer_id=? AND sp.payment_method='fiado' AND s.status='finalizada'");
                $stmt2->execute([$customerId]);
                $totalFiado = floatval($stmt2->fetchColumn());

                try { $db->exec("CREATE TABLE IF NOT EXISTS `fiado_pagamentos` (`id` int NOT NULL AUTO_INCREMENT, `customer_id` int NOT NULL, `user_id` int NOT NULL, `valor` decimal(10,2) NOT NULL, `observacao` text, `created_at` timestamp DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e) {}

                $stmt3 = $db->prepare("SELECT COALESCE(SUM(valor),0) FROM fiado_pagamentos WHERE customer_id=?");
                $stmt3->execute([$customerId]);
                $totalPago = floatval($stmt3->fetchColumn());

                $saldoAtual   = $totalFiado - $totalPago;
                $novoSaldo    = $saldoAtual + $valorFiado;
                $disponivel   = $limite - $saldoAtual;

                if ($novoSaldo > $limite && empty($data['bypass_limit'])) {
                    echo json_encode([
                        'success'         => false,
                        'limite_excedido' => true,
                        'limite'          => $limite,
                        'saldo_atual'     => $saldoAtual,
                        'disponivel'      => max(0, $disponivel),
                        'valor_venda'     => $valorFiado,
                    ]);
                    exit;
                }
            }
        }
        // ── Fim validação fiado ──────────────────────────────────────────

        $db->beginTransaction();
        try {
            $num = 'VND-'.date('Ymd').'-'.str_pad($db->query("SELECT COUNT(*)+1 FROM sales WHERE DATE(created_at)=CURDATE()")->fetchColumn(), 4, '0', STR_PAD_LEFT);
            $subtotal = 0; $costTotal = 0; $discount = floatval($data['discount'] ?? 0);
            foreach ($items as $it) {
                $subtotal  += $it['price'] * $it['qty'];
                $costTotal += $it['cost']  * $it['qty'];
            }
            $total  = $subtotal - $discount;
            $profit = $total - $costTotal;

            $stmt = $db->prepare("INSERT INTO sales (sale_number,customer_id,user_id,subtotal,discount,total,cost_total,profit,payment_method,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$num, $customerId, $_SESSION['user_id'], $subtotal, $discount, $total, $costTotal, $profit, $paymentMain, 'finalizada', $data['notes']??'']);
            $saleId = $db->lastInsertId();

            // Grava parcelas de pagamento
            $spStmt = $db->prepare("INSERT INTO sale_payments (sale_id, payment_method, valor) VALUES (?,?,?)");
            $somaPayments = array_sum(array_column($payments, 'valor'));
            foreach ($payments as $idx => $p) {
                // Se só tem 1 pagamento ou valor não informado, usa o total da venda
                $pValor = (count($payments) === 1 || $p['valor'] === null)
                    ? $total
                    : floatval($p['valor']);
                $spStmt->execute([$saleId, $p['method'], $pValor]);
            }

            foreach ($items as $it) {
                $itTotal  = $it['price'] * $it['qty'];
                $itProfit = ($it['price'] - $it['cost']) * $it['qty'];
                $db->prepare("INSERT INTO sale_items (sale_id,product_id,quantity,unit_cost,unit_price,total,profit) VALUES (?,?,?,?,?,?,?)")->execute([$saleId,$it['id'],$it['qty'],$it['cost'],$it['price'],$itTotal,$itProfit]);
                $db->prepare("UPDATE products SET stock_quantity=stock_quantity-? WHERE id=?")->execute([$it['qty'],$it['id']]);
                $db->prepare("INSERT INTO stock_movements (product_id,type,quantity,unit_cost,unit_price,total_cost,total_price,reference,user_id,sale_id) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([$it['id'],'venda',$it['qty'],$it['cost'],$it['price'],$it['cost']*$it['qty'],$itTotal,$num,$_SESSION['user_id'],$saleId]);
            }

            $db->commit();
            // Limpa carrinho persistente do operador
            $db->prepare("DELETE FROM pdv_carrinho WHERE user_id=?")->execute([$_SESSION['user_id']]);
            // Se era um pedido/comanda, marca como finalizado
            if (!empty($data['pedido_id'])) {
                $pid = intval($data['pedido_id']);
                $db->prepare("UPDATE pedidos SET status='finalizado' WHERE id=? AND status='fechando'")->execute([$pid]);
            }
            echo json_encode(['success'=>true,'sale_number'=>$num,'sale_id'=>$saleId,'total'=>$total,'message'=>'Venda finalizada!']);
        } catch(Exception $e) {
            $db->rollBack();
            echo json_encode(['success'=>false,'message'=>'Erro: '.$e->getMessage()]);
        }
        exit;
    }
    exit;
}

// ── Carrega pedido/comanda se veio de ?pedido_id=X ───────────────────────
$pedidoCarregado = null;
$pedidoIdUrl = intval($_GET['pedido_id'] ?? 0);
if ($pedidoIdUrl) {
    try {
        $stmt = $db->prepare("SELECT p.*, c.name as customer_name FROM pedidos p LEFT JOIN customers c ON p.customer_id=c.id WHERE p.id=? AND p.status='fechando'");
        $stmt->execute([$pedidoIdUrl]);
        $pedidoCarregado = $stmt->fetch() ?: null;
    } catch(Exception $e) {}
}

include __DIR__.'/../includes/header.php';
?>

<?php if ($pedidoCarregado): ?>
<div class="alert" style="background:var(--primary-light);border:2px solid var(--primary);color:var(--primary);padding:12px 16px;border-radius:10px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;gap:12px">
    <div>
        <i class="fas fa-clipboard-list"></i>
        <strong>Comanda <?= htmlspecialchars($pedidoCarregado['comanda_codigo']) ?></strong>
        <?php if ($pedidoCarregado['mesa']): ?> — Mesa: <strong><?= htmlspecialchars($pedidoCarregado['mesa']) ?></strong><?php endif; ?>
        <?php if ($pedidoCarregado['customer_name']): ?> — Cliente: <strong><?= htmlspecialchars($pedidoCarregado['customer_name']) ?></strong><?php endif; ?>
        <span style="font-size:12px;margin-left:8px;opacity:0.7">Itens do pedido carregados no carrinho</span>
    </div>
    <a href="<?= url('pages/pedidos.php') ?>" class="btn btn-outline btn-sm" style="border-color:var(--primary);color:var(--primary);white-space:nowrap">
        <i class="fas fa-arrow-left"></i> Pedidos
    </a>
</div>
<?php endif; ?>

<?php if (!$caixaAtual): ?>
<div class="alert alert-warning" style="display:flex;align-items:center;justify-content:space-between;gap:16px">
    <div><i class="fas fa-triangle-exclamation"></i> <strong>Caixa não aberto.</strong> Abra o caixa antes de realizar vendas para o controle funcionar corretamente.</div>
    <a href="<?= url('pages/caixa.php') ?>" class="btn btn-warning btn-sm" style="white-space:nowrap"><i class="fas fa-lock-open"></i> Abrir Caixa</a>
</div>
<?php endif; ?>

<div class="pdv-layout">
    <!-- LEFT: Product search + cart -->
    <div class="pdv-left">
        <div class="card" style="padding:16px">
            <div class="pdv-search">
    <!-- Badge de quantidade pendente -->
    <div id="qtyBadge" style="display:none;align-items:center;background:var(--primary);color:#fff;font-weight:800;font-size:15px;padding:0 14px;border-radius:8px;white-space:nowrap;height:46px">
        <span id="qtyBadgeNum">1</span>×
    </div>
    <div style="position:relative;flex:1">
        <i class="fas fa-barcode" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--primary);font-size:18px"></i>
        <input type="text" id="searchInput" class="form-control" placeholder="Bipe o código de barras ou digite o nome do produto..." style="padding-left:42px;font-size:15px;height:46px;font-family:'JetBrains Mono',monospace" autofocus autocomplete="off">
    </div>
    <button class="btn btn-primary" style="height:46px;padding:0 20px" onclick="searchProduct()">
        <i class="fas fa-search"></i>
    </button>
</div>
<div id="searchResults" style="display:none;margin-top:8px;border:1px solid var(--border);border-radius:8px;overflow:hidden;max-height:280px;overflow-y:auto"></div>
        </div>

        <div class="card" style="flex:1;display:flex;flex-direction:column;overflow:hidden">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-cart-shopping"></i> Itens do Carrinho</div>
                <button class="btn btn-ghost btn-sm" onclick="clearCart()" id="clearBtn" style="display:none;color:var(--danger)"><i class="fas fa-trash"></i> Limpar</button>
            </div>
            <div class="pdv-items-table" id="cartTable">
                <div class="empty-state" id="emptyCart">
                    <div class="empty-icon"><i class="fas fa-cart-shopping"></i></div>
                    <div class="empty-title">Carrinho vazio</div>
                    <div class="empty-text">Bipe ou busque um produto para adicionar</div>
                </div>
                <table id="cartItems" style="display:none;width:100%">
                    <thead>
                        <tr>
                            <th>Produto</th>
                            <th style="width:130px">Quantidade</th>
                            <th style="width:110px">Unit.</th>
                            <th style="width:110px">Total</th>
                            <th style="width:40px"></th>
                        </tr>
                    </thead>
                    <tbody id="cartBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- RIGHT: Payment panel -->
    <div class="pdv-right">
        <!-- Customer -->
        <div class="card" style="padding:14px">
            <label class="form-label"><i class="fas fa-user" style="color:var(--primary)"></i> Cliente (opcional)</label>
            <div style="display:flex;gap:6px">
                <select id="customerId" class="form-control" onchange="onClienteChange()" style="flex:1">
                    <option value="">Venda no balcão</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" data-limite="<?= $c['credit_limit'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-outline btn-sm" title="Cadastrar novo cliente" onclick="openModal('novoClienteModal')" style="flex-shrink:0;padding:0 10px">
                    <i class="fas fa-user-plus"></i>
                </button>
            </div>
            <!-- Indicador de saldo fiado -->
            <div id="fiadoInfo" style="display:none;margin-top:8px;padding:10px 12px;border-radius:8px;font-size:12px"></div>
        </div>

        <!-- Discount -->
        <div class="card" style="padding:14px">
            <label class="form-label"><i class="fas fa-tag" style="color:var(--warning)"></i> Desconto (R$)</label>
            <input type="number" id="discountInput" class="form-control" step="0.01" min="0" value="0" oninput="updateTotals()">
        </div>

        <!-- Totals panel -->
        <div class="pdv-totals">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:rgba(255,255,255,0.4);margin-bottom:12px">Resumo</div>
            <div class="pdv-total-row">
                <span>Subtotal</span>
                <span id="subtotal">R$ 0,00</span>
            </div>
            <div class="pdv-total-row">
                <span>Desconto</span>
                <span id="discountDisplay" style="color:#f87171">- R$ 0,00</span>
            </div>
            <div style="border-top:1px solid rgba(255,255,255,0.1);margin:12px 0;padding-top:12px">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:rgba(255,255,255,0.4);margin-bottom:6px">Total</div>
                <div class="pdv-total-final" id="totalDisplay">R$ 0,00</div>
            </div>
            <div class="pdv-total-row" style="margin-top:4px">
                <span>Itens</span>
                <span id="itemCount">0</span>
            </div>
        </div>

        <!-- Payment method -->
        <div class="card" style="padding:14px">
            <div class="form-label"><i class="fas fa-credit-card" style="color:var(--primary)"></i> Forma de Pagamento</div>

            <!-- Botões rápidos de forma única -->
            <div class="pdv-pay-btns" style="margin-top:8px" id="payBtns">
                <button class="pdv-pay-btn selected" data-pay="dinheiro" onclick="selectPay(this)"><i class="fas fa-money-bill"></i><br>Dinheiro</button>
                <button class="pdv-pay-btn" data-pay="pix" onclick="selectPay(this)"><i class="fas fa-qrcode"></i><br>Pix</button>
                <button class="pdv-pay-btn" data-pay="cartao_debito" onclick="selectPay(this)"><i class="fas fa-credit-card"></i><br>Débito</button>
                <button class="pdv-pay-btn" data-pay="cartao_credito" onclick="selectPay(this)"><i class="fas fa-credit-card"></i><br>Crédito</button>
                <button class="pdv-pay-btn" data-pay="fiado" onclick="selectPay(this)"><i class="fas fa-handshake"></i><br>Fiado</button>
                <button class="pdv-pay-btn" data-pay="misto" onclick="selectPay(this)" style="background:var(--primary-light);border-color:var(--primary);color:var(--primary)"><i class="fas fa-layer-group"></i><br>Misto</button>
            </div>

            <!-- Alerta de limite de fiado -->
            <div id="fiadoAlerta" style="display:none;margin-top:10px;padding:10px 12px;border-radius:8px;font-size:12px;background:#fef2f2;border:1px solid #fecaca;color:#dc2626"></div>

            <!-- Troco (pagamento simples em dinheiro) -->
            <div id="trocoPanel" style="display:none;margin-top:12px">
                <label class="form-label" style="color:rgba(255,255,255,0.7)"><i class="fas fa-money-bill-wave"></i> Valor Recebido (R$)</label>
                <input type="number" id="recebidoInput" class="form-control" step="0.01" min="0" placeholder="0,00" oninput="calcTroco()" style="font-size:16px;font-weight:700">
                <div id="trocoDisplay" style="display:none;margin-top:8px;padding:12px 14px;border-radius:8px;background:#f0fdf4;border:1px solid #bbf7d0">
                    <div style="font-size:11px;color:#6b7280;font-weight:700;text-transform:uppercase">Troco</div>
                    <div id="trocoValor" style="font-size:22px;font-weight:800;color:var(--success)">R$ 0,00</div>
                </div>
            </div>

            <!-- Painel de pagamento misto -->
            <div id="mistoPanel" style="display:none;margin-top:12px">
                <div id="mistoParcelasList" style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px"></div>

                <!-- Saldo restante -->
                <div id="mistoRestante" style="padding:8px 12px;border-radius:8px;background:#f0fdf4;border:1px solid #bbf7d0;font-size:13px;font-weight:700;color:#065f46;margin-bottom:10px;display:none">
                    <i class="fas fa-check-circle"></i> <span id="mistoRestanteValor"></span>
                </div>

                <!-- Adicionar parcela -->
                <div style="display:flex;gap:6px;align-items:center">
                    <select id="mistoMetodo" class="form-control" style="flex:1;font-size:13px;height:38px">
                        <option value="dinheiro">💵 Dinheiro</option>
                        <option value="pix">🔷 Pix</option>
                        <option value="cartao_debito">💳 Débito</option>
                        <option value="cartao_credito">💳 Crédito</option>
                        <option value="fiado">🤝 Fiado</option>
                    </select>
                    <input type="number" id="mistoValor" class="form-control" step="0.01" min="0.01" placeholder="R$ valor" style="width:110px;height:38px;font-size:13px" oninput="atualizarMistoRestante()">
                    <button class="btn btn-primary btn-sm" onclick="adicionarParcela()" style="height:38px;padding:0 12px;white-space:nowrap">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
                    Clique <strong>+</strong> para adicionar cada forma. O restante é completado automaticamente.
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="card" style="padding:14px">
            <label class="form-label">Observação</label>
            <input type="text" id="saleNotes" class="form-control" placeholder="Opcional...">
        </div>

        <!-- Finalize button -->
        <button class="btn btn-success btn-block pdv-finalize" onclick="finalizeSale()" id="finalizeBtn" disabled>
            <i class="fas fa-check-circle"></i> Finalizar Venda
        </button>
        <button class="btn btn-outline btn-block" onclick="clearCart()" style="margin-top:4px">
            <i class="fas fa-xmark"></i> Cancelar
        </button>
    </div>
</div>

<!-- ── Modal: Sucesso ─────────────────────────────────────────────────── -->
<div class="modal modal-sm" id="successModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-circle-check" style="color:var(--success)"></i> Venda Finalizada</div>
            <button class="modal-close" onclick="closeModal('successModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" style="text-align:center;padding:40px 24px">
            <div style="width:64px;height:64px;background:var(--success-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:28px;color:var(--success)">
                <i class="fas fa-circle-check"></i>
            </div>
            <div style="font-size:20px;font-weight:800;margin-bottom:8px;color:var(--text-primary)">Venda Finalizada!</div>
            <div id="successSaleNum" style="font-size:13px;color:var(--text-muted);margin-bottom:4px"></div>
            <div id="successTotal" style="font-size:28px;font-weight:800;color:var(--success);margin:12px 0"></div>
            <div style="display:flex;gap:10px;margin-top:20px">
                <button class="btn btn-outline btn-block" onclick="closeModal('successModal')"><i class="fas fa-xmark"></i> Fechar</button>
                <button class="btn btn-outline btn-block" onclick="closeModal('successModal');newSale()"><i class="fas fa-plus"></i> Nova Venda</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal: Selecionar cliente (fiado sem cliente) ─────────────────── -->
<div class="modal modal-sm" id="selecionarClienteModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-handshake" style="color:var(--danger)"></i> Cliente Obrigatório para Fiado</div>
            <button class="modal-close" onclick="closeModal('selecionarClienteModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="alert alert-warning" style="margin-bottom:16px">
                <i class="fas fa-triangle-exclamation"></i>
                Vendas no fiado precisam de um cliente identificado.
            </div>
            <div class="form-group">
                <label class="form-label">Selecione o cliente</label>
                <select id="selecionarClienteSelect" class="form-control" style="font-size:15px;height:46px">
                    <option value="">— Escolha —</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="text-align:center;color:var(--text-muted);font-size:13px;margin:8px 0">— ou —</div>
            <button class="btn btn-outline btn-block" onclick="closeModal('selecionarClienteModal');openModal('novoClienteModal')">
                <i class="fas fa-user-plus"></i> Cadastrar Novo Cliente
            </button>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('selecionarClienteModal')">Cancelar</button>
            <button class="btn btn-primary" onclick="confirmarClienteFiado()"><i class="fas fa-check"></i> Confirmar</button>
        </div>
    </div>
</div>

<!-- ── Modal: Limite de crédito excedido ─────────────────────────────── -->
<div class="modal modal-sm" id="limiteModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title" style="color:var(--danger)"><i class="fas fa-triangle-exclamation"></i> Limite de Crédito Excedido</div>
            <button class="modal-close" onclick="closeModal('limiteModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="limiteModalBody" style="text-align:center;padding:24px"></div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('limiteModal')">Cancelar</button>
            <button class="btn btn-danger" onclick="finalizeSale(true)">
                <i class="fas fa-check"></i> Liberar e Vender
            </button>
        </div>
    </div>
</div>

<!-- ── Modal: Cadastro Rápido de Cliente ─────────────────────────────── -->
<div class="modal modal-sm" id="novoClienteModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-user-plus" style="color:var(--primary)"></i> Novo Cliente</div>
            <button class="modal-close" onclick="closeModal('novoClienteModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Nome *</label>
                <input type="text" id="ncNome" class="form-control" placeholder="Nome completo do cliente">
            </div>
            <div class="form-group">
                <label class="form-label">Telefone</label>
                <input type="text" id="ncTel" class="form-control" placeholder="(00) 00000-0000">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('novoClienteModal')">Cancelar</button>
            <button class="btn btn-primary" onclick="cadastrarClienteRapido()">
                <i class="fas fa-check"></i> Cadastrar e Selecionar
            </button>
        </div>
    </div>
</div>

<script>
// Garante que formatMoney existe mesmo se o main.js ainda não carregou
if (typeof formatMoney === 'undefined') {
    window.formatMoney = function(v) {
        return 'R$ ' + parseFloat(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
}

let cart = [];
let selectedPay = 'dinheiro';
let searchTimeout = null;
let barcodeBuffer = '';
let barcodeTimer = null;
let _fiadoSaldo = null;
let _cartSaveTimer = null;
let pendingQty = 1;
let mistoParcelas = []; // [{method, valor}]

// ── Persistência do carrinho no banco ─────────────────────────────────
function scheduleCartSave() {
    clearTimeout(_cartSaveTimer);
    _cartSaveTimer = setTimeout(saveCartToDB, 600);
}

async function saveCartToDB() {
    await apiCall(`${BASE_PATH}/pages/pdv.php?action=cart_save`, {
        items:       cart,
        payment:     selectedPay,
        customer_id: document.getElementById('customerId')?.value || null,
        discount:    parseFloat(document.getElementById('discountInput')?.value) || 0,
        notes:       document.getElementById('saleNotes')?.value || '',
        pedido_id:   PEDIDO_ID_URL || null,
    });
}

async function loadCartFromDB() {
    const data = await apiCall(`${BASE_PATH}/pages/pdv.php?action=cart_load`);
    if (!data || !data.items || !data.items.length) return;

    cart = data.items;

    // Restaura forma de pagamento
    if (data.payment) {
        selectedPay = data.payment;
        document.querySelectorAll('.pdv-pay-btn').forEach(b => b.classList.remove('selected'));
        const btn = document.querySelector(`[data-pay="${data.payment}"]`);
        if (btn) btn.classList.add('selected');
    }

    // Restaura cliente
    if (data.customer_id) {
        const sel = document.getElementById('customerId');
        if (sel) { sel.value = data.customer_id; onClienteChange(); }
    }

    // Restaura desconto e obs
    if (data.discount) document.getElementById('discountInput').value = data.discount;
    if (data.notes)    document.getElementById('saleNotes').value = data.notes;

    renderCart();
    showToast(`Carrinho restaurado com ${cart.length} item(ns)!`, 'info');
}

// ── Pedido carregado da comanda ────────────────────────────────────────
const PEDIDO_ID_URL = <?= $pedidoIdUrl ?? 0 ?>;

// ── LEITOR DE CÓDIGO DE BARRAS ──────────────────────────────────────────
const BARCODE_SPEED = 50;

document.addEventListener('keydown', function(e) {
    const active = document.activeElement;
    const isSearchInput = active && active.id === 'searchInput';

    if (e.key === 'Enter' && barcodeBuffer.length > 2) {
        clearTimeout(barcodeTimer);
        const code = barcodeBuffer;
        barcodeBuffer = '';
        if (!isSearchInput) {
            searchAndAddDirect(code);
        }
        return;
    }

    if (e.key.length === 1) {
        clearTimeout(barcodeTimer);
        barcodeBuffer += e.key;
        barcodeTimer = setTimeout(() => { barcodeBuffer = ''; }, 300);
    }
});

document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const val = this.value.trim();
    if (!val) { hideResults(); return; }
    searchTimeout = setTimeout(() => searchProduct(false), 200);
});

document.getElementById('searchInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        clearTimeout(searchTimeout);
        clearTimeout(barcodeTimer);
        barcodeBuffer = '';
        searchProduct(true);
        return;
    }
    // Detecta padrão Nx ou N* para quantidade rápida
    // Ex: o campo tem "3" e o usuário digita "x" ou "*"
    if (e.key === 'x' || e.key === 'X' || e.key === '*') {
        const val = parseInt(this.value.trim());
        if (val > 0) {
            e.preventDefault();
            pendingQty = val;
            this.value = '';
            document.getElementById('qtyBadge').style.display = 'flex';
            document.getElementById('qtyBadgeNum').textContent = pendingQty;
            return;
        }
    }
    // Escape limpa quantidade pendente
    if (e.key === 'Escape') {
        pendingQty = 1;
        document.getElementById('qtyBadge').style.display = 'none';
    }
});

async function searchProduct(forceAdd = false) {
    const term = document.getElementById('searchInput').value.trim();
    if (!term) return;
    const results = await apiCall(`${BASE_PATH}/pages/pdv.php?action=search&term=${encodeURIComponent(term)}`);
    if (!Array.isArray(results)) return;

    const exact = results.find(r => r.barcode === term || r.code === term);
    if (exact || (forceAdd && results.length === 1)) {
        const prod = exact || results[0];
        document.getElementById('searchInput').value = '';
        hideResults();
        addToCart(prod);
        document.getElementById('searchInput').focus();
        return;
    }

    showResults(results);
}

async function searchAndAddDirect(term) {
    const results = await apiCall(`${BASE_PATH}/pages/pdv.php?action=search&term=${encodeURIComponent(term)}`);
    if (!Array.isArray(results) || !results.length) {
        showToast('Produto não encontrado: ' + term, 'warning');
        return;
    }
    const exact = results.find(r => r.barcode === term || r.code === term) || results[0];
    addToCart(exact);
}

function showResults(results) {
    const el = document.getElementById('searchResults');
    if (!results.length) {
        el.innerHTML = `<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px"><i class="fas fa-search"></i> Nenhum produto encontrado</div>`;
        el.style.display = 'block';
        return;
    }
    el.innerHTML = results.map(p => `
        <div onclick="selectProduct(${p.id},'${escHtml(p.name)}',${p.sale_price},${p.cost_price},'${p.unit}')"
             style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:center"
             onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='white'">
            <div>
                <div style="font-weight:600;font-size:13px">${escHtml(p.name)}</div>
                <div style="font-size:11px;color:var(--text-muted)">
                    <code>${p.code}</code>${p.barcode ? ' · EAN: '+p.barcode : ''} · Estoque: ${p.stock_quantity} ${p.unit}
                </div>
            </div>
            <div style="font-weight:700;color:var(--primary);font-size:14px">${formatMoney(p.sale_price)}</div>
        </div>
    `).join('');
    el.style.display = 'block';
}

function hideResults() { document.getElementById('searchResults').style.display = 'none'; }
function escHtml(str) { return String(str).replace(/'/g, "\\'").replace(/"/g, '&quot;'); }

function selectProduct(id, name, price, cost, unit) {
    addToCart({id, name, sale_price: price, cost_price: cost, unit});
    document.getElementById('searchInput').value = '';
    hideResults();
    document.getElementById('searchInput').focus();
}

function addToCart(product) {
    const qty = pendingQty;
    pendingQty = 1;
    document.getElementById('qtyBadge').style.display = 'none';

    const idx = cart.findIndex(i => i.id == product.id);
    if (idx >= 0) {
        cart[idx].qty += qty;
        renderCart();
        showToast(`+${qty} ${product.name}`, 'success');
    } else {
        cart.push({
            id: product.id,
            name: product.name,
            price: parseFloat(product.sale_price),
            cost: parseFloat(product.cost_price),
            unit: product.unit,
            qty: qty
        });
        renderCart();
        showToast(product.name + (qty > 1 ? ` ×${qty}` : '') + ' adicionado!', 'success');
    }
    checkFiadoAlerta();
    scheduleCartSave();
}

function renderCart() {
    const tbody   = document.getElementById('cartBody');
    const emptyEl = document.getElementById('emptyCart');
    const tableEl = document.getElementById('cartItems');
    const clearBtn = document.getElementById('clearBtn');

    if (!cart.length) {
        emptyEl.style.display = '';
        tableEl.style.display = 'none';
        clearBtn.style.display = 'none';
        document.getElementById('finalizeBtn').disabled = true;
        updateTotals();
        return;
    }

    emptyEl.style.display = 'none';
    tableEl.style.display = '';
    clearBtn.style.display = '';
    document.getElementById('finalizeBtn').disabled = false;

    tbody.innerHTML = cart.map((item, i) => `
        <tr>
            <td class="td-name" style="font-size:13px">
                ${item.name}
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px">${item.unit}</div>
            </td>
            <td>
                <div class="pdv-qty-control">
                    <button class="pdv-qty-btn" onclick="changeQty(${i},-1)">−</button>
                    <input type="number" class="form-control pdv-qty-input"
                           value="${item.qty}" min="0.001" step="0.001"
                           style="width:64px"
                           onchange="setQty(${i},this.value)">
                    <button class="pdv-qty-btn" onclick="changeQty(${i},1)">+</button>
                </div>
            </td>
            <td style="font-weight:600">${formatMoney(item.price)}</td>
            <td style="font-weight:700;color:var(--primary)">${formatMoney(item.price * item.qty)}</td>
            <td><button class="btn btn-ghost btn-sm" onclick="removeItem(${i})" style="color:var(--danger)"><i class="fas fa-xmark"></i></button></td>
        </tr>
    `).join('');

    updateTotals();
}

function changeQty(idx, delta) {
    const newQty = Math.round((cart[idx].qty + delta) * 1000) / 1000;
    if (newQty <= 0) { cart.splice(idx, 1); } else { cart[idx].qty = newQty; }
    renderCart();
    checkFiadoAlerta();
    scheduleCartSave();
}

function setQty(idx, val) {
    const q = parseFloat(val);
    if (!q || q <= 0) { cart.splice(idx, 1); } else { cart[idx].qty = Math.round(q * 1000) / 1000; }
    renderCart();
    checkFiadoAlerta();
    scheduleCartSave();
}

function removeItem(idx) { cart.splice(idx, 1); renderCart(); checkFiadoAlerta(); scheduleCartSave(); }
function clearCart() { cart = []; renderCart(); checkFiadoAlerta(); apiCall(`${BASE_PATH}/pages/pdv.php?action=cart_clear`); }

function updateTotals() {
    const subtotal = cart.reduce((s, i) => s + (i.price * i.qty), 0);
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    const total    = Math.max(0, subtotal - discount);
    const count    = cart.reduce((s, i) => s + i.qty, 0);
    document.getElementById('subtotal').textContent      = formatMoney(subtotal);
    document.getElementById('discountDisplay').textContent = '− ' + formatMoney(discount);
    document.getElementById('totalDisplay').textContent  = formatMoney(total);
    document.getElementById('itemCount').textContent     = parseFloat(count.toFixed(3));
    if (selectedPay === 'misto') atualizarMistoRestante();
}

function selectPay(btn) {
    document.querySelectorAll('.pdv-pay-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedPay = btn.dataset.pay;

    const isMisto   = selectedPay === 'misto';
    const isDinheiro = selectedPay === 'dinheiro';

    document.getElementById('trocoPanel').style.display  = isDinheiro  ? 'block' : 'none';
    document.getElementById('mistoPanel').style.display  = isMisto     ? 'block' : 'none';
    document.getElementById('recebidoInput').value = '';
    document.getElementById('trocoDisplay').style.display = 'none';

    if (isMisto) {
        mistoParcelas = [];
        renderMistoParcelas();
        atualizarMistoRestante();
    }

    checkFiadoAlerta();
    scheduleCartSave();
}

// ── Pagamento misto ───────────────────────────────────────────────────
function getTotal() {
    const subtotal = cart.reduce((s, i) => s + (i.price * i.qty), 0);
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    return Math.max(0, subtotal - discount);
}

function mistoSomaParcelas() {
    return mistoParcelas.reduce((s, p) => s + p.valor, 0);
}

function atualizarMistoRestante() {
    const total   = getTotal();
    const pago    = mistoSomaParcelas();
    const restante = Math.round((total - pago) * 100) / 100;
    const el      = document.getElementById('mistoRestante');
    const elVal   = document.getElementById('mistoRestanteValor');

    // Sugere o restante no campo de valor
    const inputVal = document.getElementById('mistoValor');
    if (restante > 0 && inputVal && !inputVal.value) {
        inputVal.placeholder = 'R$ ' + restante.toFixed(2).replace('.', ',');
    }

    if (el) {
        if (restante <= 0) {
            el.style.background = '#f0fdf4';
            el.style.borderColor = '#bbf7d0';
            el.style.color = '#065f46';
            elVal.textContent = restante < 0
                ? `⚠️ Excedeu ${formatMoney(Math.abs(restante))}`
                : '✅ Pagamento completo';
            el.style.display = 'block';
        } else if (mistoParcelas.length > 0) {
            el.style.background = '#fff7ed';
            el.style.borderColor = '#fed7aa';
            el.style.color = '#9a3412';
            elVal.textContent = `Faltam ${formatMoney(restante)}`;
            el.style.display = 'block';
        } else {
            el.style.display = 'none';
        }
    }
}

function adicionarParcela() {
    const metodo = document.getElementById('mistoMetodo').value;
    let valor  = parseFloat(document.getElementById('mistoValor').value);

    if (!valor || valor <= 0) {
        // Se vazio, usa o restante
        valor = Math.round((getTotal() - mistoSomaParcelas()) * 100) / 100;
        if (valor <= 0) { showToast('Valor inválido ou pagamento já completo.', 'warning'); return; }
    }

    // Não deixa ultrapassar o total
    const pago = mistoSomaParcelas();
    const total = getTotal();
    if (pago + valor > total + 0.001) {
        showToast(`Soma ultrapassa o total (${formatMoney(total)}). Ajuste o valor.`, 'warning');
        return;
    }

    mistoParcelas.push({ method: metodo, valor: Math.round(valor * 100) / 100 });
    document.getElementById('mistoValor').value = '';
    document.getElementById('mistoValor').placeholder = 'R$ valor';
    renderMistoParcelas();
    atualizarMistoRestante();
}

const PM_LABELS = {dinheiro:'💵 Dinheiro', pix:'🔷 Pix', cartao_debito:'💳 Débito', cartao_credito:'💳 Crédito', fiado:'🤝 Fiado'};

function renderMistoParcelas() {
    const el = document.getElementById('mistoParcelasList');
    if (!mistoParcelas.length) { el.innerHTML = ''; return; }
    el.innerHTML = mistoParcelas.map((p, i) => `
        <div style="display:flex;justify-content:space-between;align-items:center;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:8px 12px;font-size:13px">
            <span style="font-weight:600">${PM_LABELS[p.method] || p.method}</span>
            <div style="display:flex;align-items:center;gap:10px">
                <span style="font-weight:800;color:var(--primary)">${formatMoney(p.valor)}</span>
                <button onclick="removerParcela(${i})" class="btn btn-ghost btn-sm" style="color:var(--danger);padding:0 4px"><i class="fas fa-xmark"></i></button>
            </div>
        </div>`).join('');
}

function removerParcela(i) {
    mistoParcelas.splice(i, 1);
    renderMistoParcelas();
    atualizarMistoRestante();
}

function calcTroco() {
    const recebido = parseFloat(document.getElementById('recebidoInput').value) || 0;
    const subtotal = cart.reduce((s, i) => s + (i.price * i.qty), 0);
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    const total    = Math.max(0, subtotal - discount);
    const trocoDisplay = document.getElementById('trocoDisplay');
    const trocoValor   = document.getElementById('trocoValor');
    if (recebido <= 0) { trocoDisplay.style.display = 'none'; return; }
    const troco = recebido - total;
    trocoValor.textContent = formatMoney(Math.max(0, troco));
    trocoValor.style.color = troco < 0 ? 'var(--danger)' : 'var(--success)';
    trocoDisplay.style.display = 'block';
    // Aviso se recebido é insuficiente
    trocoValor.textContent = troco < 0
        ? `Faltam ${formatMoney(Math.abs(troco))}`
        : formatMoney(troco);
}
// ── Atualiza indicador de saldo quando cliente muda ───────────────────
async function onClienteChange() {
    const sel   = document.getElementById('customerId');
    const id    = sel.value;
    const info  = document.getElementById('fiadoInfo');
    _fiadoSaldo = null;

    scheduleCartSave();

    if (!id) { info.style.display = 'none'; checkFiadoAlerta(); return; }

    const res = await apiCall(`${BASE_PATH}/pages/pdv.php?action=saldo_fiado&customer_id=${id}`);
    _fiadoSaldo = res;

    if (res.limite > 0) {
        const disponivel = res.disponivel ?? (res.limite - res.saldo);
        const pct        = Math.min(100, (res.saldo / res.limite) * 100);
        const cor        = pct >= 90 ? 'var(--danger)' : pct >= 60 ? 'var(--warning)' : 'var(--success)';
        info.style.display = 'block';
        info.style.background = pct >= 90 ? '#fef2f2' : pct >= 60 ? '#fffbeb' : '#f0fdf4';
        info.style.border = `1px solid ${pct >= 90 ? '#fecaca' : pct >= 60 ? '#fde68a' : '#bbf7d0'}`;
        info.innerHTML = `
            <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                <span style="font-weight:700">Fiado: <span style="color:${cor}">${formatMoney(res.saldo)}</span></span>
                <span>Limite: <strong>${formatMoney(res.limite)}</strong></span>
            </div>
            <div style="height:5px;background:var(--border);border-radius:3px;overflow:hidden">
                <div style="width:${pct}%;height:100%;background:${cor};border-radius:3px"></div>
            </div>
            <div style="margin-top:4px;color:${cor};font-weight:600">
                Disponível: ${formatMoney(disponivel)}
            </div>`;
    } else if (res.saldo > 0) {
        info.style.display = 'block';
        info.style.background = '#fffbeb';
        info.style.border = '1px solid #fde68a';
        info.innerHTML = `<i class="fas fa-handshake"></i> Fiado em aberto: <strong style="color:var(--warning)">${formatMoney(res.saldo)}</strong> (sem limite definido)`;
    } else {
        info.style.display = 'none';
    }

    checkFiadoAlerta();
}

// ── Verifica alerta de limite ao mudar carrinho ou pagamento ──────────
function checkFiadoAlerta() {
    const alerta = document.getElementById('fiadoAlerta');
    if (selectedPay !== 'fiado' || !_fiadoSaldo || !_fiadoSaldo.limite) {
        alerta.style.display = 'none';
        return;
    }

    const subtotal  = cart.reduce((s, i) => s + (i.price * i.qty), 0);
    const discount  = parseFloat(document.getElementById('discountInput').value) || 0;
    const total     = Math.max(0, subtotal - discount);
    const novoSaldo = _fiadoSaldo.saldo + total;

    if (novoSaldo > _fiadoSaldo.limite) {
        alerta.style.display = 'block';
        alerta.innerHTML = `<i class="fas fa-triangle-exclamation"></i> <strong>Atenção:</strong> Esta venda (${formatMoney(total)}) ultrapassará o limite! Saldo atual: ${formatMoney(_fiadoSaldo.saldo)} | Novo saldo: <strong>${formatMoney(novoSaldo)}</strong> | Limite: ${formatMoney(_fiadoSaldo.limite)}`;
    } else {
        alerta.style.display = 'none';
    }
}

// ── Finalizar venda ───────────────────────────────────────────────────
async function finalizeSale(bypass = false) {
    if (!cart.length) return;

    const customerId = document.getElementById('customerId').value || null;

    // Valida fiado sem cliente
    const needsCustomer = selectedPay === 'fiado' ||
        (selectedPay === 'misto' && mistoParcelas.some(p => p.method === 'fiado'));
    if (needsCustomer && !customerId) {
        openModal('selecionarClienteModal');
        return;
    }

    // Valida pagamento misto
    if (selectedPay === 'misto') {
        const total = getTotal();
        const pago  = mistoSomaParcelas();
        if (mistoParcelas.length < 2) { showToast('Adicione ao menos 2 formas de pagamento no modo misto.', 'warning'); return; }
        const restante = Math.round((total - pago) * 100) / 100;
        if (restante > 0.01) { showToast(`Faltam ${formatMoney(restante)} para completar o pagamento.`, 'warning'); return; }
    }

    const btn = document.getElementById('finalizeBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';

    // Monta payload
    const payload = {
        items:        cart,
        customer_id:  customerId,
        discount:     parseFloat(document.getElementById('discountInput').value) || 0,
        notes:        document.getElementById('saleNotes').value,
        bypass_limit: bypass,
        pedido_id:    PEDIDO_ID_URL || null,
    };

    if (selectedPay === 'misto') {
        payload.payments = mistoParcelas;
        payload.payment  = 'misto';
    } else {
        payload.payment  = selectedPay;
        payload.payments = [{ method: selectedPay, valor: null }];
    }

    const res = await apiCall(`${BASE_PATH}/pages/pdv.php?action=finalize`, payload);

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-check-circle"></i> Finalizar Venda';

    if (res.success) {
        closeModal('limiteModal');
        document.getElementById('successSaleNum').textContent = 'Nº ' + res.sale_number;
        document.getElementById('successTotal').textContent   = formatMoney(res.total);
        openModal('successModal');
        newSale();
    } else if (res.limite_excedido) {
        document.getElementById('limiteModalBody').innerHTML = `
            <div style="font-size:44px;margin-bottom:12px">⚠️</div>
            <div style="font-size:15px;font-weight:700;margin-bottom:16px;color:var(--danger)">Limite de crédito ultrapassado!</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;text-align:left">
                <div style="background:#fef2f2;border-radius:8px;padding:12px">
                    <div style="font-size:11px;color:#9ca3af;font-weight:700;text-transform:uppercase">Limite</div>
                    <div style="font-size:18px;font-weight:800;color:var(--danger)">${formatMoney(res.limite)}</div>
                </div>
                <div style="background:#fef2f2;border-radius:8px;padding:12px">
                    <div style="font-size:11px;color:#9ca3af;font-weight:700;text-transform:uppercase">Saldo atual</div>
                    <div style="font-size:18px;font-weight:800;color:var(--danger)">${formatMoney(res.saldo_atual)}</div>
                </div>
                <div style="background:#fff7ed;border-radius:8px;padding:12px">
                    <div style="font-size:11px;color:#9ca3af;font-weight:700;text-transform:uppercase">Disponível</div>
                    <div style="font-size:18px;font-weight:800;color:var(--warning)">${formatMoney(res.disponivel)}</div>
                </div>
                <div style="background:#fef2f2;border-radius:8px;padding:12px">
                    <div style="font-size:11px;color:#9ca3af;font-weight:700;text-transform:uppercase">Esta venda</div>
                    <div style="font-size:18px;font-weight:800;color:var(--danger)">${formatMoney(res.valor_venda)}</div>
                </div>
            </div>`;
        openModal('limiteModal');
    } else {
        showToast(res.message, 'error');
    }
}

// ── Modal: confirmar cliente para fiado ───────────────────────────────
function confirmarClienteFiado() {
    const sel = document.getElementById('selecionarClienteSelect').value;
    if (!sel) { showToast('Selecione um cliente.', 'warning'); return; }

    // Atualiza o select principal
    const mainSel = document.getElementById('customerId');
    mainSel.value = sel;
    closeModal('selecionarClienteModal');
    onClienteChange();
    // Tenta finalizar de novo
    setTimeout(() => finalizeSale(), 200);
}

// ── Cadastro rápido de cliente no PDV ─────────────────────────────────
async function cadastrarClienteRapido() {
    const nome = document.getElementById('ncNome').value.trim();
    const tel  = document.getElementById('ncTel').value.trim();
    if (!nome) { showToast('Informe o nome do cliente.', 'warning'); return; }

    const res = await apiCall(`${BASE_PATH}/pages/pdv.php?action=cadastrar_cliente`, {name: nome, phone: tel});
    if (!res.success) { showToast(res.message, 'error'); return; }

    // Adiciona ao select principal
    const mainSel  = document.getElementById('customerId');
    const opt      = new Option(res.name, res.id);
    opt.dataset.limite = 0;
    mainSel.appendChild(opt);
    mainSel.value = res.id;

    // Também no select do modal de seleção
    const selModal = document.getElementById('selecionarClienteSelect');
    if (selModal) {
        const opt2 = new Option(res.name, res.id);
        selModal.appendChild(opt2);
    }

    document.getElementById('ncNome').value = '';
    document.getElementById('ncTel').value  = '';
    closeModal('novoClienteModal');
    showToast(res.message, 'success');
    onClienteChange();
}

function newSale() {
    cart = [];
    mistoParcelas = [];
    _fiadoSaldo = null;
    document.getElementById('discountInput').value = 0;
    document.getElementById('saleNotes').value     = '';
    document.getElementById('customerId').value    = '';
    document.getElementById('fiadoInfo').style.display  = 'none';
    document.getElementById('fiadoAlerta').style.display = 'none';
    document.querySelectorAll('.pdv-pay-btn').forEach(b => b.classList.remove('selected'));
    document.querySelector('[data-pay="dinheiro"]').classList.add('selected');
    selectedPay = 'dinheiro';
    pendingQty = 1;
    document.getElementById('qtyBadge').style.display = 'none';
    document.getElementById('trocoPanel').style.display = 'block';
    document.getElementById('mistoPanel').style.display = 'none';
    document.getElementById('recebidoInput').value = '';
    document.getElementById('trocoDisplay').style.display = 'none';
    renderCart();
    apiCall(`${BASE_PATH}/pages/pdv.php?action=cart_clear`);
    document.getElementById('searchInput').focus();
}

document.addEventListener('click', (e) => {
    if (!e.target.closest('#searchInput') && !e.target.closest('#searchResults')) hideResults();
});

// Salva ao mudar desconto ou observação
document.getElementById('discountInput').addEventListener('input', () => { updateTotals(); checkFiadoAlerta(); scheduleCartSave(); });
document.getElementById('saleNotes').addEventListener('input', () => { scheduleCartSave(); });

// Carrega carrinho salvo ao abrir o PDV (sem pedido na URL)
window.addEventListener('load', async () => {
    if (!PEDIDO_ID_URL) {
        await loadCartFromDB();
    } else {
        // Carrinho vindo de pedido — carrega o pedido
        const res = await apiCall(`${BASE_PATH}/pages/pdv.php?action=load_pedido&pedido_id=${PEDIDO_ID_URL}`);
        if (res.success) {
            cart = res.items.map(it => ({
                id: parseInt(it.product_id),
                name: it.product_name,
                price: parseFloat(it.unit_price),
                cost: parseFloat(it.unit_cost),
                qty: parseFloat(it.quantity),
                unit: it.unit
            }));
            if (res.pedido.customer_id) {
                const sel = document.getElementById('customerId');
                if (sel) { sel.value = res.pedido.customer_id; onClienteChange(); }
            }
            renderCart();
            scheduleCartSave();
            showToast(`Comanda ${res.pedido.comanda_codigo} carregada com ${cart.length} item(ns)!`, 'success');
        } else {
            showToast(res.message || 'Erro ao carregar pedido.', 'error');
        }
    }
});

renderCart();
document.getElementById('searchInput').focus();
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>