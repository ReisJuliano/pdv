<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/setup.php';
requireLogin();
$pageTitle = 'Pedidos / Comandas';
$db = getDB();

// ── Garante que as tabelas existem ──────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `pedidos` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `comanda_codigo` varchar(30) NOT NULL,
        `customer_id` int(11) DEFAULT NULL,
        `user_id` int(11) NOT NULL,
        `status` enum('aberto','fechando','finalizado','cancelado') DEFAULT 'aberto',
        `subtotal` decimal(10,2) DEFAULT 0.00,
        `discount` decimal(10,2) DEFAULT 0.00,
        `total` decimal(10,2) DEFAULT 0.00,
        `notes` text,
        `mesa` varchar(30) DEFAULT NULL,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS `pedido_items` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `pedido_id` int(11) NOT NULL,
        `product_id` int(11) NOT NULL,
        `quantity` decimal(10,3) NOT NULL,
        `unit_price` decimal(10,2) NOT NULL,
        `unit_cost` decimal(10,2) DEFAULT 0.00,
        `total` decimal(10,2) NOT NULL,
        `notes` text,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

// ── API ──────────────────────────────────────────────────────────────────
if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';

    // ── Criar novo pedido ────────────────────────────────────────────────
    if ($action === 'criar') {
        $data = json_decode(file_get_contents('php://input'), true);
        $customerId = intval($data['customer_id'] ?? 0) ?: null;
        $mesa       = trim($data['mesa'] ?? '');
        $notes      = trim($data['notes'] ?? '');

        // Gera código de comanda único: CMD-YYYYMMDD-XXXX
        $seq = $db->query("SELECT COUNT(*)+1 FROM pedidos WHERE DATE(created_at)=CURDATE()")->fetchColumn();
        $codigo = 'CMD-'.date('Ymd').'-'.str_pad($seq, 4, '0', STR_PAD_LEFT);

        $db->prepare("INSERT INTO pedidos (comanda_codigo, customer_id, user_id, status, mesa, notes) VALUES (?,?,?,?,?,?)")
           ->execute([$codigo, $customerId, $_SESSION['user_id'], 'aberto', $mesa ?: null, $notes]);

        $pedidoId = $db->lastInsertId();
        echo json_encode(['success' => true, 'pedido_id' => $pedidoId, 'codigo' => $codigo,
                          'message' => "Comanda $codigo criada!"]);
        exit;
    }

    // ── Buscar pedido por código (para bipe) ─────────────────────────────
    if ($action === 'buscar_codigo') {
        $codigo = trim($_GET['codigo'] ?? '');
        if (!$codigo) { echo json_encode(['found' => false]); exit; }
        $stmt = $db->prepare("SELECT p.*, c.name as customer_name FROM pedidos p LEFT JOIN customers c ON p.customer_id=c.id WHERE p.comanda_codigo=? AND p.status IN ('aberto','fechando') LIMIT 1");
        $stmt->execute([$codigo]);
        $pedido = $stmt->fetch();
        if (!$pedido) { echo json_encode(['found' => false, 'message' => 'Comanda não encontrada ou já fechada.']); exit; }
        echo json_encode(['found' => true, 'pedido' => $pedido]);
        exit;
    }

    // ── Buscar detalhes de um pedido ─────────────────────────────────────
    if ($action === 'detalhe') {
        $id = intval($_GET['id']);
        $pedido = $db->query("SELECT p.*, c.name as customer_name, u.name as user_name FROM pedidos p LEFT JOIN customers c ON p.customer_id=c.id LEFT JOIN users u ON p.user_id=u.id WHERE p.id=$id")->fetch();
        if (!$pedido) { echo json_encode(['success' => false, 'message' => 'Pedido não encontrado']); exit; }
        $items = $db->query("SELECT pi.*, pr.name as product_name, pr.unit FROM pedido_items pi JOIN products pr ON pi.product_id=pr.id WHERE pi.pedido_id=$id ORDER BY pi.created_at")->fetchAll();
        echo json_encode(['success' => true, 'pedido' => $pedido, 'items' => $items]);
        exit;
    }

    // ── Adicionar item ao pedido ─────────────────────────────────────────
    if ($action === 'add_item') {
        $data = json_decode(file_get_contents('php://input'), true);
        $pedidoId  = intval($data['pedido_id']);
        $productId = intval($data['product_id']);
        $qty       = floatval($data['quantity'] ?? 1);
        $notes     = trim($data['notes'] ?? '');

        $pedido = $db->query("SELECT * FROM pedidos WHERE id=$pedidoId AND status='aberto'")->fetch();
        if (!$pedido) { echo json_encode(['success' => false, 'message' => 'Pedido não encontrado ou já fechado.']); exit; }

        $prod = $db->query("SELECT * FROM products WHERE id=$productId AND active=1")->fetch();
        if (!$prod) { echo json_encode(['success' => false, 'message' => 'Produto não encontrado.']); exit; }

        // Verifica se produto já existe no pedido — incrementa
        $existing = $db->query("SELECT * FROM pedido_items WHERE pedido_id=$pedidoId AND product_id=$productId LIMIT 1")->fetch();
        if ($existing) {
            $newQty   = $existing['quantity'] + $qty;
            $newTotal = $newQty * $prod['sale_price'];
            $db->prepare("UPDATE pedido_items SET quantity=?, total=? WHERE id=?")->execute([$newQty, $newTotal, $existing['id']]);
        } else {
            $total = $qty * $prod['sale_price'];
            $db->prepare("INSERT INTO pedido_items (pedido_id, product_id, quantity, unit_price, unit_cost, total, notes) VALUES (?,?,?,?,?,?,?)")
               ->execute([$pedidoId, $productId, $qty, $prod['sale_price'], $prod['cost_price'], $total, $notes]);
        }

        // Recalcula totais do pedido
        recalcPedido($db, $pedidoId);
        $updated = $db->query("SELECT * FROM pedidos WHERE id=$pedidoId")->fetch();

        echo json_encode(['success' => true, 'message' => $prod['name'].' adicionado!',
                          'subtotal' => $updated['subtotal'], 'total' => $updated['total']]);
        exit;
    }

    // ── Remover item ─────────────────────────────────────────────────────
    if ($action === 'remove_item') {
        $itemId   = intval($_GET['item_id']);
        $pedidoId = intval($_GET['pedido_id']);
        $db->prepare("DELETE FROM pedido_items WHERE id=? AND pedido_id=?")->execute([$itemId, $pedidoId]);
        recalcPedido($db, $pedidoId);
        echo json_encode(['success' => true]);
        exit;
    }

    // ── Atualizar quantidade de item ─────────────────────────────────────
    if ($action === 'update_qty') {
        $data     = json_decode(file_get_contents('php://input'), true);
        $itemId   = intval($data['item_id']);
        $pedidoId = intval($data['pedido_id']);
        $qty      = floatval($data['quantity']);
        if ($qty <= 0) { echo json_encode(['success' => false, 'message' => 'Quantidade inválida']); exit; }
        $item = $db->query("SELECT * FROM pedido_items WHERE id=$itemId")->fetch();
        if (!$item) { echo json_encode(['success' => false]); exit; }
        $newTotal = $qty * $item['unit_price'];
        $db->prepare("UPDATE pedido_items SET quantity=?, total=? WHERE id=?")->execute([$qty, $newTotal, $itemId]);
        recalcPedido($db, $pedidoId);
        $updated = $db->query("SELECT * FROM pedidos WHERE id=$pedidoId")->fetch();
        echo json_encode(['success' => true, 'subtotal' => $updated['subtotal'], 'total' => $updated['total']]);
        exit;
    }

    // ── Cancelar pedido ──────────────────────────────────────────────────
    if ($action === 'cancelar') {
        $id = intval($_GET['id']);
        $db->prepare("UPDATE pedidos SET status='cancelado' WHERE id=? AND status IN ('aberto','fechando')")->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Pedido cancelado.']);
        exit;
    }

    // ── Mudar status do pedido ───────────────────────────────────────────
    if ($action === 'mudar_status') {
        $data      = json_decode(file_get_contents('php://input'), true);
        $id        = intval($data['id']);
        $novoStatus= $data['status'] ?? '';
        $allowed   = ['aberto','fechando','cancelado'];
        if (!in_array($novoStatus, $allowed)) { echo json_encode(['success'=>false,'message'=>'Status inválido']); exit; }
        $pedido = $db->query("SELECT * FROM pedidos WHERE id=$id")->fetch();
        if (!$pedido) { echo json_encode(['success'=>false,'message'=>'Pedido não encontrado']); exit; }
        // Não permite reabrir um já finalizado
        if ($pedido['status'] === 'finalizado') { echo json_encode(['success'=>false,'message'=>'Pedido finalizado não pode ser alterado.']); exit; }
        $db->prepare("UPDATE pedidos SET status=?, updated_at=NOW() WHERE id=?")->execute([$novoStatus, $id]);
        echo json_encode(['success'=>true,'message'=>'Status alterado para '.ucfirst($novoStatus).'.']);
        exit;
    }

    // ── Cadastro rápido de cliente ───────────────────────────────────────
    if ($action === 'cadastrar_cliente') {
        $data = json_decode(file_get_contents('php://input'), true);
        $nome = trim($data['name']  ?? '');
        $tel  = trim($data['phone'] ?? '');
        if (!$nome) { echo json_encode(['success'=>false,'message'=>'Nome obrigatório']); exit; }
        $db->prepare("INSERT INTO customers (name, phone) VALUES (?, ?)")->execute([$nome, $tel]);
        $newId = $db->lastInsertId();
        echo json_encode(['success'=>true,'id'=>$newId,'name'=>$nome,'message'=>'Cliente cadastrado!']);
        exit;
    }

    // ── Vincular cliente ao pedido ───────────────────────────────────────
    if ($action === 'vincular_cliente') {
        $data       = json_decode(file_get_contents('php://input'), true);
        $pedidoId   = intval($data['pedido_id']);
        $customerId = intval($data['customer_id']) ?: null;
        $db->prepare("UPDATE pedidos SET customer_id=?, updated_at=NOW() WHERE id=?")->execute([$customerId, $pedidoId]);
        $nome = '—';
        if ($customerId) {
            $r = $db->prepare("SELECT name FROM customers WHERE id=?");
            $r->execute([$customerId]);
            $nome = $r->fetchColumn() ?: '—';
        }
        echo json_encode(['success'=>true,'message'=>'Cliente vinculado!','customer_name'=>$nome]);
        exit;
    }

    // ── Mover para PDV (marcar como "fechando") ──────────────────────────
    if ($action === 'fechar_pedido') {
        $id = intval($_GET['id']);
        $pedido = $db->query("SELECT * FROM pedidos WHERE id=$id AND status='aberto'")->fetch();
        if (!$pedido) { echo json_encode(['success' => false, 'message' => 'Pedido não encontrado ou já está sendo fechado.']); exit; }
        $db->prepare("UPDATE pedidos SET status='fechando' WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true, 'redirect' => url('pages/pdv.php').'?pedido_id='.$id]);
        exit;
    }

    // ── Busca produto para adicionar ao pedido ───────────────────────────
    if ($action === 'search_product') {
        $term = $_GET['term'] ?? '';
        $stmt = $db->prepare("SELECT id,code,name,barcode,sale_price,stock_quantity,unit,cost_price FROM products WHERE active=1 AND (barcode=? OR code=? OR name LIKE ?) LIMIT 10");
        $stmt->execute([$term, $term, "%$term%"]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // ── Listar pedidos abertos (para polling) ────────────────────────────
    if ($action === 'listar') {
        $pedidos = $db->query("SELECT p.*, c.name as customer_name, u.name as user_name,
            (SELECT COUNT(*) FROM pedido_items WHERE pedido_id=p.id) as item_count
            FROM pedidos p
            LEFT JOIN customers c ON p.customer_id=c.id
            LEFT JOIN users u ON p.user_id=u.id
            WHERE p.status IN ('aberto','fechando')
            ORDER BY p.created_at DESC")->fetchAll();
        echo json_encode($pedidos);
        exit;
    }

    exit;
}

function recalcPedido($db, $pedidoId) {
    $subtotal = $db->query("SELECT COALESCE(SUM(total),0) FROM pedido_items WHERE pedido_id=$pedidoId")->fetchColumn();
    $pedido   = $db->query("SELECT discount FROM pedidos WHERE id=$pedidoId")->fetch();
    $total    = max(0, $subtotal - ($pedido['discount'] ?? 0));
    $db->prepare("UPDATE pedidos SET subtotal=?, total=?, updated_at=NOW() WHERE id=?")->execute([$subtotal, $total, $pedidoId]);
}

// ── Dados para renderização ──────────────────────────────────────────────
$pedidosAbertos = $db->query("SELECT p.*, c.name as customer_name, u.name as user_name,
    (SELECT COUNT(*) FROM pedido_items WHERE pedido_id=p.id) as item_count
    FROM pedidos p
    LEFT JOIN customers c ON p.customer_id=c.id
    LEFT JOIN users u ON p.user_id=u.id
    WHERE p.status IN ('aberto','fechando')
    ORDER BY p.created_at DESC")->fetchAll();

$customers = $db->query("SELECT id, name FROM customers WHERE active=1 ORDER BY name")->fetchAll();

include __DIR__.'/../includes/header.php';
?>

<!-- Buscador de comanda por bipe -->
<div class="card" style="margin-bottom:16px;border:2px solid var(--primary-light)">
    <div class="card-body" style="padding:14px 16px">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <div style="font-weight:700;font-size:13px;color:var(--primary);white-space:nowrap"><i class="fas fa-barcode"></i> Bipe ou Digite o Código da Comanda:</div>
            <div style="flex:1;min-width:200px;position:relative">
                <input type="text" id="comandaBipe" class="form-control" placeholder="Digite ou bipe o código da comanda"
                       style="font-family:'JetBrains Mono',monospace;font-size:14px;height:42px"
                       autocomplete="off" autofocus>
            </div>
            <button class="btn btn-primary" style="height:42px" onclick="buscarComanda()">
                <i class="fas fa-search"></i> Abrir Comanda
            </button>
            <button class="btn btn-success" style="height:42px" onclick="openModal('novoPedidoModal')">
                <i class="fas fa-plus"></i> Nova Comanda
            </button>
        </div>
    </div>
</div>

<!-- Pedidos em aberto -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-clipboard-list"></i> Pedidos em Aberto (<span id="pedidoCount"><?= count($pedidosAbertos) ?></span>)</div>
        <div style="display:flex;gap:8px">
            <button class="btn btn-ghost btn-sm" onclick="refreshPedidos()" id="refreshBtn" title="Atualizar">
                <i class="fas fa-rotate-right"></i>
            </button>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted);cursor:pointer">
                <input type="checkbox" id="autoRefresh" checked onchange="toggleAutoRefresh()">
                Auto-atualizar
            </label>
        </div>
    </div>

<div id="pedidosGrid" class="table-wrapper" <?= !$pedidosAbertos ? 'style="display:none"' : '' ?>>
    <table id="pedidosTable">
        <thead>
            <tr>
                <th>Comanda</th><th>Mesa/Local</th><th>Cliente</th>
                <th>Itens</th><th>Total</th><th>Abertura</th>
                <th>Operador</th><th>Status</th><th>Ações</th>
            </tr>
        </thead>
        <tbody id="pedidosTbody">
        <?php foreach ($pedidosAbertos as $p): ?>
        <?php
            $minutos = round((time() - strtotime($p['created_at'])) / 60);
            $tempoClass = $minutos > 60 ? 'color:var(--danger)' : ($minutos > 30 ? 'color:var(--warning)' : 'color:var(--success)');
        ?>
        <tr id="row-pedido-<?= $p['id'] ?>">
            <td><code style="font-size:12px;background:var(--bg);padding:3px 8px;border-radius:6px"><?= htmlspecialchars($p['comanda_codigo']) ?></code></td>
            <td><?= htmlspecialchars($p['mesa'] ?: '—') ?></td>
            <td class="td-name"><?= htmlspecialchars($p['customer_name'] ?: 'Sem cliente') ?></td>
            <td><span class="badge badge-secondary"><?= $p['item_count'] ?> item(ns)</span></td>
            <td><strong><?= formatMoney($p['total']) ?></strong></td>
            <td>
                <div style="font-size:12px"><?= date('H:i', strtotime($p['created_at'])) ?></div>
                <div style="font-size:11px;<?= $tempoClass ?>"><?= $minutos ?>min atrás</div>
            </td>
            <td style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($p['user_name']) ?></td>
            <td>
                <?php if ($p['status'] === 'fechando'): ?>
                <span class="badge badge-warning"><i class="fas fa-clock"></i> Fechando</span>
                <?php else: ?>
                <span class="badge badge-success"><i class="fas fa-circle" style="font-size:8px"></i> Aberto</span>
                <?php endif; ?>
            </td>
            <td>
                <div style="display:flex;gap:4px">
                    <button class="btn btn-primary btn-sm" onclick="abrirPedido(<?= $p['id'] ?>)"><i class="fas fa-eye"></i> Ver</button>
                    <button class="btn btn-success btn-sm" onclick="fecharPedido(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['comanda_codigo'])) ?>')"><i class="fas fa-cash-register"></i> Cobrar</button>
                    <button class="btn btn-ghost btn-sm" onclick="imprimirComanda(<?= $p['id'] ?>)"><i class="fas fa-print"></i></button>
                    <?php if (currentUser()['role'] === 'admin'): ?>
                    <button class="btn btn-ghost btn-sm" onclick="cancelarPedido(<?= $p['id'] ?>)"><i class="fas fa-ban" style="color:var(--danger)"></i></button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="empty-state" id="emptyPedidos" <?= $pedidosAbertos ? 'style="display:none"' : '' ?>>
    <div class="empty-icon"><i class="fas fa-clipboard-list"></i></div>
    <div class="empty-title">Nenhum pedido em aberto</div>
    <div class="empty-text">Crie uma nova comanda para começar</div>
    <button class="btn btn-primary" onclick="openModal('novoPedidoModal')"><i class="fas fa-plus"></i> Nova Comanda</button>
</div>

<!-- ── Modal: Novo Pedido ─────────────────────────────────────────────── -->
<div class="modal modal-sm" id="novoPedidoModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-plus" style="color:var(--primary)"></i> Nova Comanda</div>
            <button class="modal-close" onclick="closeModal('novoPedidoModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Mesa / Identificação (opcional)</label>
                <input type="text" id="novaMesa" class="form-control" placeholder="Ex: Mesa 5, Balcão, Delivery...">
            </div>
            <div class="form-group">
                <label class="form-label">Cliente (opcional)</label>
                <div style="display:flex;gap:6px">
                    <select id="novoCustomer" class="form-control" style="flex:1">
                        <option value="">Sem cliente</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline btn-sm" title="Cadastrar novo cliente" onclick="openModal('novoClienteComandaModal')" style="flex-shrink:0;padding:0 10px">
                        <i class="fas fa-user-plus"></i>
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Observação</label>
                <input type="text" id="novaNotes" class="form-control" placeholder="Opcional...">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('novoPedidoModal')">Cancelar</button>
            <button class="btn btn-primary" onclick="criarNovoPedido()"><i class="fas fa-check"></i> Criar Comanda</button>
        </div>
    </div>
</div>

<!-- ── Modal: Ver/Editar Pedido ───────────────────────────────────────── -->
<div class="modal modal-lg" id="pedidoModal">
    <div class="modal-box" style="max-width:800px">
        <div class="modal-header">
            <div class="modal-title" id="pedidoModalTitle"><i class="fas fa-clipboard-list"></i> Pedido</div>
            <button class="modal-close" onclick="closeModal('pedidoModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="pedidoModalBody">
            <div style="text-align:center;padding:32px;color:var(--text-muted)"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('pedidoModal')">Fechar</button>
            <button class="btn btn-ghost" id="btnStatusModal" onclick=""><i class="fas fa-arrows-rotate"></i> Status</button>
            <button class="btn btn-ghost" id="btnImprimirModal" onclick=""><i class="fas fa-print"></i> Imprimir</button>
            <button class="btn btn-success" id="btnCobrarModal" onclick=""><i class="fas fa-cash-register"></i> Cobrar / Ir para Caixa</button>
        </div>
    </div>
</div>

<!-- ── Modal: Imprimir Comanda ────────────────────────────────────────── -->
<div class="modal modal-sm" id="printModal">
    <div class="modal-box" style="max-width:380px">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-print"></i> Imprimir Comanda</div>
            <button class="modal-close" onclick="closeModal('printModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="printContent" style="padding:0">
            <!-- Conteúdo da comanda para impressão -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('printModal')">Fechar</button>
            <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
        </div>
    </div>
</div>

<!-- ── Modal: Cadastro Rápido de Cliente ──────────────────────────────── -->
<div class="modal modal-sm" id="novoClienteComandaModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-user-plus" style="color:var(--primary)"></i> Novo Cliente</div>
            <button class="modal-close" onclick="closeModal('novoClienteComandaModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Nome *</label>
                <input type="text" id="ncNome" class="form-control" placeholder="Nome completo">
            </div>
            <div class="form-group">
                <label class="form-label">Telefone</label>
                <input type="text" id="ncTel" class="form-control" placeholder="(00) 00000-0000">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('novoClienteComandaModal')">Cancelar</button>
            <button class="btn btn-primary" onclick="cadastrarClienteRapido()"><i class="fas fa-check"></i> Cadastrar e Selecionar</button>
        </div>
    </div>
</div>

<!-- ── Modal: Mudar Status ────────────────────────────────────────────── -->
<div class="modal modal-sm" id="statusModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-arrows-rotate" style="color:var(--primary)"></i> Alterar Status</div>
            <button class="modal-close" onclick="closeModal('statusModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="statusPedidoId">
            <div id="statusPedidoInfo" style="background:var(--bg);border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px"></div>
            <div class="form-group">
                <label class="form-label">Novo Status</label>
                <div style="display:flex;flex-direction:column;gap:8px;margin-top:4px">
                    <button class="btn btn-outline" style="justify-content:flex-start;gap:10px" onclick="confirmarStatus('aberto')">
                        <span style="width:10px;height:10px;background:var(--success);border-radius:50%;display:inline-block"></span>
                        <strong>Aberto</strong> — pedido em andamento
                    </button>
                    <button class="btn btn-outline" style="justify-content:flex-start;gap:10px" onclick="confirmarStatus('fechando')">
                        <span style="width:10px;height:10px;background:var(--warning);border-radius:50%;display:inline-block"></span>
                        <strong>Fechando</strong> — aguardando pagamento no caixa
                    </button>
                    <button class="btn btn-outline" style="justify-content:flex-start;gap:10px;border-color:var(--danger);color:var(--danger)" onclick="confirmarStatus('cancelado')">
                        <span style="width:10px;height:10px;background:var(--danger);border-radius:50%;display:inline-block"></span>
                        <strong>Cancelado</strong> — encerrar sem cobrar
                    </button>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('statusModal')">Fechar</button>
        </div>
    </div>
</div>

<style>
@media print {
    body * { visibility: hidden !important; }
    #printContent, #printContent * { visibility: visible !important; }
    #printContent { position: fixed !important; left: 0; top: 0; width: 80mm !important; }
    .modal-backdrop, .modal-footer, .modal-header { display: none !important; }
}

.comanda-print {
    font-family: 'Courier New', monospace;
    font-size: 12px;
    padding: 16px;
    max-width: 300px;
    margin: 0 auto;
    color: #000;
    background: #fff;
}
.comanda-print .shop-name { font-size: 16px; font-weight: bold; text-align: center; margin-bottom: 4px; }
.comanda-print .divider { border-top: 1px dashed #000; margin: 8px 0; }
.comanda-print .comanda-code { text-align: center; font-size: 14px; font-weight: bold; margin: 8px 0 4px; }
.comanda-print .barcode-area { text-align: center; margin: 8px 0; }
.comanda-print svg { max-width: 100%; }
.comanda-print table { width: 100%; border-collapse: collapse; }
.comanda-print td { padding: 2px 0; vertical-align: top; font-size: 11px; }
.comanda-print .total-line { font-weight: bold; font-size: 13px; }
</style>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
let autoRefreshInterval = null;
let currentPedidoId = null;

// ── Auto-refresh ──────────────────────────────────────────────────────
function toggleAutoRefresh() {
    const checked = document.getElementById('autoRefresh').checked;
    if (checked) {
        autoRefreshInterval = setInterval(refreshPedidos, 15000);
    } else {
        clearInterval(autoRefreshInterval);
    }
}
// Inicia auto-refresh
autoRefreshInterval = setInterval(refreshPedidos, 15000);

async function refreshPedidos() {
    const btn = document.getElementById('refreshBtn');
    btn.querySelector('i').classList.add('fa-spin');
    try {
        const data = await apiCall(`${BASE_PATH}/pages/pedidos.php?action=listar`);
        if (Array.isArray(data)) {
            document.getElementById('pedidoCount').textContent = data.length;
            renderPedidosTable(data);
        }
    } catch(e) {}
    btn.querySelector('i').classList.remove('fa-spin');
}

function renderPedidosTable(pedidos) {
    const wrapper = document.getElementById('pedidosGrid');
    const tbody   = document.getElementById('pedidosTbody');
    const empty   = document.getElementById('emptyPedidos');

    if (!pedidos.length) {
        if (wrapper) wrapper.style.display = 'none';
        if (empty)   empty.style.display   = '';
        return;
    }

    // Há pedidos: mostra tabela, esconde empty
    if (wrapper) wrapper.style.display = '';
    if (empty)   empty.style.display   = 'none';

    if (!tbody) return;

    const pmFmt = v => 'R$ ' + parseFloat(v||0).toLocaleString('pt-BR', {minimumFractionDigits:2});

    tbody.innerHTML = pedidos.map(p => {
        const min = Math.round((Date.now() - new Date(p.created_at.replace(' ','T')).getTime()) / 60000);
        const cor = min > 60 ? 'var(--danger)' : (min > 30 ? 'var(--warning)' : 'var(--success)');
        const statusBadge = p.status === 'fechando'
            ? '<span class="badge badge-warning"><i class="fas fa-clock"></i> Fechando</span>'
            : '<span class="badge badge-success"><i class="fas fa-circle" style="font-size:8px"></i> Aberto</span>';
        const adminBtn = '<?= currentUser()['role'] === 'admin' ? '1' : '0' ?>' === '1'
            ? `<button class="btn btn-ghost btn-sm" onclick="cancelarPedido(${p.id})"><i class="fas fa-ban" style="color:var(--danger)"></i></button>` : '';
        return `<tr id="row-pedido-${p.id}">
            <td><code style="font-size:12px;background:var(--bg);padding:3px 8px;border-radius:6px">${p.comanda_codigo}</code></td>
            <td>${p.mesa || '—'}</td>
            <td class="td-name">${p.customer_name || 'Sem cliente'}</td>
            <td><span class="badge badge-secondary">${p.item_count} item(ns)</span></td>
            <td><strong>${pmFmt(p.total)}</strong></td>
            <td><div style="font-size:12px">${new Date(p.created_at.replace(' ','T')).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'})}</div><div style="font-size:11px;color:${cor}">${min}min atrás</div></td>
            <td style="font-size:12px;color:var(--text-muted)">${p.user_name}</td>
            <td>${statusBadge}</td>
            <td><div style="display:flex;gap:4px">
                <button class="btn btn-primary btn-sm" onclick="abrirPedido(${p.id})"><i class="fas fa-eye"></i> Ver</button>
                <button class="btn btn-success btn-sm" onclick="fecharPedido(${p.id}, '${p.comanda_codigo}')"><i class="fas fa-cash-register"></i> Cobrar</button>
                <button class="btn btn-ghost btn-sm" onclick="imprimirComanda(${p.id})"><i class="fas fa-print"></i></button>
                ${adminBtn}
            </div></td>
        </tr>`;
    }).join('');
}
// ── Buscar comanda por bipe ───────────────────────────────────────────
document.getElementById('comandaBipe').addEventListener('keydown', e => {
    if (e.key === 'Enter') buscarComanda();
});

async function buscarComanda() {
    const codigo = document.getElementById('comandaBipe').value.trim();
    if (!codigo) return;
    const res = await apiCall(`${BASE_PATH}/pages/pedidos.php?action=buscar_codigo&codigo=${encodeURIComponent(codigo)}`);
    if (res.found) {
        document.getElementById('comandaBipe').value = '';
        abrirPedido(res.pedido.id);
    } else {
        showToast(res.message || 'Comanda não encontrada.', 'error');
        document.getElementById('comandaBipe').select();
    }
}

// ── Criar novo pedido ─────────────────────────────────────────────────
async function criarNovoPedido() {
    const res = await apiCall(`${BASE_PATH}/pages/pedidos.php?action=criar`, {
        mesa: document.getElementById('novaMesa').value,
        customer_id: document.getElementById('novoCustomer').value || null,
        notes: document.getElementById('novaNotes').value
    });
    if (res.success) {
        showToast(res.message, 'success');
        closeModal('novoPedidoModal');
        document.getElementById('novaMesa').value = '';
        document.getElementById('novoCustomer').value = '';
        document.getElementById('novaNotes').value = '';
        // Abre o pedido recém criado para já adicionar itens
        await refreshPedidos();
        abrirPedido(res.pedido_id);
    } else {
        showToast(res.message || 'Erro ao criar pedido', 'error');
    }
}

// ── Abrir pedido no modal ─────────────────────────────────────────────
async function abrirPedido(id) {
    currentPedidoId = id;
    openModal('pedidoModal');
    document.getElementById('pedidoModalBody').innerHTML = '<div style="text-align:center;padding:32px"><i class="fas fa-spinner fa-spin fa-2x" style="color:var(--primary)"></i></div>';

    const res = await apiCall(`${BASE_PATH}/pages/pedidos.php?action=detalhe&id=${id}`);
    if (!res.success) { showToast('Erro ao carregar pedido', 'error'); closeModal('pedidoModal'); return; }

    const p = res.pedido;
    const items = res.items;
    const fmt = v => 'R$ ' + parseFloat(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2});

    document.getElementById('pedidoModalTitle').innerHTML = `<i class="fas fa-clipboard-list"></i> ${p.comanda_codigo}${p.mesa ? ' — ' + p.mesa : ''}`;
    document.getElementById('btnImprimirModal').onclick = () => imprimirComanda(id);
    document.getElementById('btnCobrarModal').onclick   = () => fecharPedido(id, p.comanda_codigo);
    document.getElementById('btnStatusModal').onclick   = () => abrirStatusModal(id, p);

    const isAberto = p.status === 'aberto';

    document.getElementById('pedidoModalBody').innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;font-size:13px">
            <div><strong>Comanda:</strong> <code>${p.comanda_codigo}</code></div>
            <div><strong>Status:</strong> <span class="badge ${p.status==='aberto'?'badge-success':'badge-warning'}">${p.status}</span></div>
            <div style="display:flex;align-items:center;gap:6px">
                <strong>Cliente:</strong>
                <span id="modalClienteNome">${p.customer_name || 'Sem cliente'}</span>
                ${isAberto ? `
                <button class="btn btn-ghost btn-sm" title="Alterar cliente" onclick="abrirVincularCliente(${id})" style="padding:2px 6px;font-size:11px">
                    <i class="fas fa-pen"></i>
                </button>` : ''}
            </div>
            <div><strong>Mesa/Local:</strong> ${p.mesa || '—'}</div>
            <div><strong>Abertura:</strong> ${new Date(p.created_at.replace(' ','T')).toLocaleString('pt-BR')}</div>
            <div><strong>Operador:</strong> ${p.user_name}</div>
        </div>

        ${isAberto ? `
        <div style="display:flex;gap:8px;margin-bottom:16px">
            <div style="position:relative;flex:1">
                <i class="fas fa-barcode" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--primary)"></i>
                <input type="text" id="modalSearchInput" class="form-control" placeholder="Bipe ou busque produto para adicionar..."
                       style="padding-left:34px;font-family:'JetBrains Mono',monospace" autocomplete="off"
                       oninput="searchModalProduct()" onkeydown="if(event.key==='Enter')searchModalProduct(true)">
            </div>
        </div>
        <div id="modalSearchResults" style="display:none;border:1px solid var(--border);border-radius:8px;overflow:hidden;max-height:200px;overflow-y:auto;margin-bottom:12px"></div>
        ` : ''}

        <div id="pedidoItemsTable">
            ${renderItemsHtml(items, fmt, isAberto, id)}
        </div>

        <div style="background:var(--bg);border-radius:8px;padding:14px;margin-top:12px">
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px">
                <span>Subtotal:</span><span>${fmt(p.subtotal)}</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:16px;font-weight:800;padding-top:8px;border-top:1px solid var(--border)">
                <span>Total:</span><span style="color:var(--primary)">${fmt(p.total)}</span>
            </div>
        </div>
    `;

    if (isAberto) {
        const inp = document.getElementById('modalSearchInput');
        if (inp) {
            setTimeout(() => inp.focus(), 100);
            inp.addEventListener('click', e => e.stopPropagation());
        }
    }
}

function renderItemsHtml(items, fmt, isAberto, pedidoId) {
    if (!items.length) return '<div style="text-align:center;padding:24px;color:var(--text-muted)">Nenhum item adicionado ainda.</div>';
    return `<table style="width:100%;border-collapse:collapse">
        <thead><tr style="background:var(--bg)">
            <th style="padding:8px 10px;text-align:left;font-size:11px;color:var(--text-muted);border-bottom:1px solid var(--border)">Produto</th>
            <th style="padding:8px 10px;text-align:center;font-size:11px;color:var(--text-muted);border-bottom:1px solid var(--border);width:100px">Qtd</th>
            <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text-muted);border-bottom:1px solid var(--border)">Unit.</th>
            <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text-muted);border-bottom:1px solid var(--border)">Total</th>
            ${isAberto ? '<th style="width:36px;border-bottom:1px solid var(--border)"></th>' : ''}
        </tr></thead>
        <tbody>
        ${items.map(it => `<tr>
            <td style="padding:9px 10px;font-weight:600;font-size:13px">${it.product_name}<br><span style="font-size:11px;color:var(--text-muted);font-weight:400">${it.unit}</span></td>
            <td style="padding:9px 10px;text-align:center">
                ${isAberto
                    ? `<input type="number" value="${it.quantity}" min="0.1" step="1" style="width:70px;text-align:center;border:1px solid var(--border);border-radius:6px;padding:4px 6px;font-size:13px"
                        onchange="updateQty(${it.id}, ${pedidoId}, this.value)">`
                    : it.quantity}
            </td>
            <td style="padding:9px 10px;text-align:right;font-size:13px">${fmt(it.unit_price)}</td>
            <td style="padding:9px 10px;text-align:right;font-weight:700;font-size:13px">${fmt(it.total)}</td>
            ${isAberto ? `<td style="padding:9px 6px;text-align:center"><button class="btn btn-ghost btn-sm" onclick="removerItem(${it.id}, ${pedidoId})"><i class="fas fa-xmark" style="color:var(--danger)"></i></button></td>` : ''}
        </tr>`).join('')}
        </tbody>
    </table>`;
}

// ── Search dentro do modal ────────────────────────────────────────────
let modalSearchTm = null;
function searchModalProduct(exact = false) {
    clearTimeout(modalSearchTm);
    const term = document.getElementById('modalSearchInput')?.value.trim();
    if (!term) { hideModalResults(); return; }
    modalSearchTm = setTimeout(async () => {
        const results = await apiCall(`${BASE_PATH}/pages/pedidos.php?action=search_product&term=${encodeURIComponent(term)}`);
        if (!Array.isArray(results)) return;
        if (exact && results.length === 1) { addItemModal(results[0]); return; }
        const el = document.getElementById('modalSearchResults');
        if (!el) return;
        if (!results.length) {
            el.innerHTML = '<div style="padding:12px;color:var(--text-muted);font-size:13px">Não encontrado</div>';
            el.style.display = 'block'; return;
        }
        el.innerHTML = results.map(p => `
            <div onclick='addItemModal(${JSON.stringify(p)})' style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border-light);display:flex;justify-content:space-between"
                 onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background=''">
                <div><div style="font-weight:600;font-size:13px">${p.name}</div>
                <div style="font-size:11px;color:var(--text-muted)">Estoque: ${p.stock_quantity} ${p.unit}</div></div>
                <div style="font-weight:700;color:var(--primary)">${'R$ '+parseFloat(p.sale_price).toLocaleString('pt-BR',{minimumFractionDigits:2})}</div>
            </div>`).join('');
        el.style.display = 'block';
    }, 150);
}

function hideModalResults() {
    const el = document.getElementById('modalSearchResults');
    if (el) el.style.display = 'none';
}

async function addItemModal(product) {
    hideModalResults();
    const inp = document.getElementById('modalSearchInput');
    if (inp) inp.value = '';
    const res = await apiCall(`${BASE_PATH}/pages/pedidos.php?action=add_item`, {
        pedido_id: currentPedidoId,
        product_id: product.id,
        quantity: 1
    });
    if (res.success) {
        showToast(res.message, 'success');
        await reloadPedidoItems();
    } else {
        showToast(res.message, 'error');
    }
}

async function removerItem(itemId, pedidoId) {
    const res = await apiCall(`${BASE_PATH}/pages/pedidos.php?action=remove_item&item_id=${itemId}&pedido_id=${pedidoId}`);
    if (res.success) { await reloadPedidoItems(); }
}

async function updateQty(itemId, pedidoId, qty) {
    const res = await apiCall(`${BASE_PATH}/pages/pedidos.php?action=update_qty`, {item_id: itemId, pedido_id: pedidoId, quantity: parseFloat(qty)});
    if (res.success) { await reloadPedidoItems(); }
}

async function reloadPedidoItems() {
    const res = await apiCall(`${BASE_PATH}/pages/pedidos.php?action=detalhe&id=${currentPedidoId}`);
    if (!res.success) return;
    const fmt = v => 'R$ ' + parseFloat(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2});
    const isAberto = res.pedido.status === 'aberto';
    document.getElementById('pedidoItemsTable').innerHTML = renderItemsHtml(res.items, fmt, isAberto, currentPedidoId);
    // Atualiza total no modal
    const totalEl = document.querySelector('#pedidoModalBody .total-line, #pedidoModalBody [style*="font-size:16px"]');
    if (totalEl) totalEl.lastChild.textContent = fmt(res.pedido.total);
    refreshPedidos();
}

// ── Fechar pedido → PDV ───────────────────────────────────────────────
async function fecharPedido(id, codigo) {
    const ok = await showConfirm({
        title: 'Cobrar pedido ' + codigo + '?',
        message: 'O pedido será movido para o PDV para finalizar o pagamento.',
        type: 'info', icon: '💳', confirmText: 'Sim, cobrar'
    });
    if (!ok) return;
    const res = await apiCall(`${BASE_PATH}/pages/pedidos.php?action=fechar_pedido&id=${id}`);
    if (res.success) {
        showToast('Redirecionando para o caixa...', 'success');
        setTimeout(() => { window.location.href = res.redirect; }, 600);
    } else {
        showToast(res.message, 'error');
    }
}

// ── Cancelar pedido ───────────────────────────────────────────────────
async function cancelarPedido(id) {
    const ok = await showConfirm({
        title: 'Cancelar pedido?',
        message: 'Esta ação não pode ser desfeita.',
        type: 'danger', icon: '🗑️', confirmText: 'Sim, cancelar'
    });
    if (!ok) return;
    const res = await apiCall(`${BASE_PATH}/pages/pedidos.php?action=cancelar&id=${id}`);
    if (res.success) {
        showToast(res.message, 'success');
        const row = document.getElementById('row-pedido-' + id);
        if (row) row.remove();
        refreshPedidos();
    } else {
        showToast(res.message, 'error');
    }
}

// ── Imprimir comanda ──────────────────────────────────────────────────
async function imprimirComanda(id) {
    const res = await apiCall(`${BASE_PATH}/pages/pedidos.php?action=detalhe&id=${id}`);
    if (!res.success) return;

    const p = res.pedido;
    const items = res.items;
    const fmt = v => 'R$ ' + parseFloat(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2});
    const now = new Date().toLocaleString('pt-BR');

    document.getElementById('printContent').innerHTML = `
        <div class="comanda-print" id="comandaImprimir">
            <div class="shop-name"><?= APP_NAME ?></div>
            <div style="text-align:center;font-size:11px">Comanda</div>
            <div class="divider"></div>
            <div class="comanda-code">${p.comanda_codigo}</div>
            <div class="barcode-area">
                <svg id="barcodeComanda"></svg>
            </div>
            <div class="divider"></div>
            ${p.mesa ? `<div style="text-align:center;font-size:12px">Mesa/Local: <strong>${p.mesa}</strong></div>` : ''}
            ${p.customer_name ? `<div style="text-align:center;font-size:12px">Cliente: <strong>${p.customer_name}</strong></div>` : ''}
            <div style="font-size:11px;text-align:center;color:#555">${now}</div>
            <div class="divider"></div>
            <table>
                <thead><tr><td><strong>Produto</strong></td><td style="text-align:center"><strong>Qtd</strong></td><td style="text-align:right"><strong>Total</strong></td></tr></thead>
                <tbody>
                ${items.map(it => `<tr>
                    <td>${it.product_name}</td>
                    <td style="text-align:center">${parseFloat(it.quantity)}</td>
                    <td style="text-align:right">${fmt(it.total)}</td>
                </tr>`).join('')}
                </tbody>
            </table>
            <div class="divider"></div>
            <div style="display:flex;justify-content:space-between" class="total-line">
                <span>TOTAL:</span><span>${fmt(p.total)}</span>
            </div>
            <div class="divider"></div>
            <div style="text-align:center;font-size:10px;margin-top:8px">Obrigado!</div>
        </div>`;

    openModal('printModal');

    // Gera código de barras com JsBarcode
    setTimeout(() => {
        try {
            JsBarcode('#barcodeComanda', p.comanda_codigo, {
                format: 'CODE128',
                width: 2,
                height: 50,
                displayValue: false,
                margin: 4
            });
        } catch(e) {}
    }, 100);
}

// Fecha resultados ao clicar fora
document.addEventListener('click', e => {
    if (!e.target.closest('#modalSearchInput') && !e.target.closest('#modalSearchResults')) hideModalResults();
});

// ── Mudar status do pedido ────────────────────────────────────────────
function abrirStatusModal(id, pedido) {
    document.getElementById('statusPedidoId').value = id;
    document.getElementById('statusPedidoInfo').innerHTML =
        `<strong>${pedido.comanda_codigo}</strong>${pedido.mesa ? ' — ' + pedido.mesa : ''}<br>
         Status atual: <span class="badge ${pedido.status==='aberto'?'badge-success':'badge-warning'}">${pedido.status}</span>`;
    openModal('statusModal');
}

async function confirmarStatus(novoStatus) {
    const id = document.getElementById('statusPedidoId').value;
    const labelMap = { aberto: 'Aberto', fechando: 'Fechando', cancelado: 'Cancelado' };
    const ok = await showConfirm({
        title: `Alterar para "${labelMap[novoStatus]}"?`,
        message: novoStatus === 'cancelado'
            ? 'O pedido será <strong>cancelado</strong> e não aparecerá mais na lista.'
            : `O pedido ficará marcado como <strong>${labelMap[novoStatus]}</strong>.`,
        type: novoStatus === 'cancelado' ? 'danger' : 'info',
        icon: novoStatus === 'cancelado' ? '🗑️' : '🔄',
        confirmText: 'Confirmar'
    });
    if (!ok) return;
    const res = await apiCall(`${BASE_PATH}/pages/pedidos.php?action=mudar_status`, { id: parseInt(id), status: novoStatus });
    if (res.success) {
        showToast(res.message, 'success');
        closeModal('statusModal');
        closeModal('pedidoModal');
        refreshPedidos();
    } else {
        showToast(res.message, 'error');
    }
}

// ── Vincular cliente ao pedido (dentro do modal) ──────────────────────
let _vincularPedidoId = null;
const _allCustomers = <?= json_encode(array_map(fn($c) => ['id'=>$c['id'],'name'=>$c['name']], $customers)) ?>;

function abrirVincularCliente(pedidoId) {
    _vincularPedidoId = pedidoId;
    // Cria modal inline rápido
    const existing = document.getElementById('_vincularModal');
    if (existing) existing.remove();

    const opts = _allCustomers.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
    const div = document.createElement('div');
    div.innerHTML = `
    <div class="modal modal-sm open" id="_vincularModal" style="z-index:10000">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-user"></i> Vincular Cliente</div>
                <button class="modal-close" onclick="document.getElementById('_vincularModal').remove()"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Selecionar cliente existente</label>
                    <select id="_vincularSelect" class="form-control">
                        <option value="">Sem cliente</option>
                        ${opts}
                    </select>
                </div>
                <div style="text-align:center;color:var(--text-muted);font-size:13px;margin:8px 0">— ou —</div>
                <button class="btn btn-outline btn-block" onclick="document.getElementById('_vincularModal').remove();openModal('novoClienteComandaModal');_modoVincular=true">
                    <i class="fas fa-user-plus"></i> Cadastrar Novo Cliente
                </button>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="document.getElementById('_vincularModal').remove()">Cancelar</button>
                <button class="btn btn-primary" onclick="salvarVincularCliente()"><i class="fas fa-check"></i> Confirmar</button>
            </div>
        </div>
    </div>`;
    document.body.appendChild(div.firstElementChild);
}

async function salvarVincularCliente() {
    const custId = document.getElementById('_vincularSelect')?.value || null;
    const res = await apiCall(`${BASE_PATH}/pages/pedidos.php?action=vincular_cliente`, {
        pedido_id: _vincularPedidoId,
        customer_id: custId ? parseInt(custId) : null
    });
    if (res.success) {
        showToast(res.message, 'success');
        document.getElementById('_vincularModal')?.remove();
        const nomeEl = document.getElementById('modalClienteNome');
        if (nomeEl) nomeEl.textContent = res.customer_name;
        refreshPedidos();
    } else {
        showToast(res.message, 'error');
    }
}

// ── Cadastro rápido de cliente (na comanda) ───────────────────────────
let _modoVincular = false;

async function cadastrarClienteRapido() {
    const nome = document.getElementById('ncNome').value.trim();
    const tel  = document.getElementById('ncTel').value.trim();
    if (!nome) { showToast('Informe o nome do cliente.', 'warning'); return; }
    const res = await apiCall(`${BASE_PATH}/pages/pedidos.php?action=cadastrar_cliente`, { name: nome, phone: tel });
    if (!res.success) { showToast(res.message, 'error'); return; }

    // Adiciona à lista de clientes em todos os selects
    const opt = new Option(res.name, res.id);
    document.getElementById('novoCustomer')?.appendChild(opt.cloneNode(true));
    _allCustomers.push({ id: res.id, name: res.name });

    document.getElementById('ncNome').value = '';
    document.getElementById('ncTel').value  = '';
    closeModal('novoClienteComandaModal');
    showToast(res.message, 'success');

    if (_modoVincular && _vincularPedidoId) {
        // Vincula direto ao pedido aberto
        const res2 = await apiCall(`${BASE_PATH}/pages/pedidos.php?action=vincular_cliente`, {
            pedido_id: _vincularPedidoId,
            customer_id: res.id
        });
        if (res2.success) {
            const nomeEl = document.getElementById('modalClienteNome');
            if (nomeEl) nomeEl.textContent = res.name;
            refreshPedidos();
        }
        _modoVincular = false;
    } else {
        // Seleciona no select da nova comanda
        const sel = document.getElementById('novoCustomer');
        if (sel) sel.value = res.id;
    }
}
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>