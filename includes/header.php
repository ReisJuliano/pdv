<?php
if (!isset($pageTitle)) $pageTitle = 'Início';
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — <?= APP_NAME ?></title>
    <script>const BASE_PATH = '<?= rtrim(url(''), '/') ?>';</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/main.css') ?>">
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <div class="logo-icon">
                <img src="/pdv/assets/img/logo.png" alt="Logo" style="width:40px;height:40px;">
            </div>
            <div class="logo-text">
                <span class="logo-name"><?= APP_NAME ?></span>
                <span class="logo-sub">Sistema Inteligente</span>
            </div>
        </div>
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    <nav class="sidebar-nav">
 <div class="nav-section-label">Gerencial</div>
        </a>
        <a href="<?= url('index.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">
            <i class="fas fa-chart-pie"></i>
            <span>Início</span>
        </a>

        <div class="nav-section-label">Vendas/Pedidos</div>
        
        <a href="<?= url('pages/pdv.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'pdv.php' ? 'active' : '' ?>">
            <i class="fas fa-cash-register"></i>
            <span>Caixa</span>

        <a href="<?= url('pages/pedidos.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'pedidos.php' ? 'active' : '' ?>">
            <i class="fas fa-clipboard-list"></i>
            <span>Pedidos / Comandas</span>
        </a>
                <a href="<?= url('pages/caixa.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'caixa.php' ? 'active' : '' ?>">
            <i class="fas fa-cash-register"></i>
            <span>Abertura / Fechamento do Caixa</span>
        </a>

        
        <div class="nav-section-label">Fiado</div>
       

        <a href="<?= url('pages/fiado.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'fiado.php' ? 'active' : '' ?>">
            <i class="fas fa-handshake"></i>
            <span>Fiado / A Receber</span>
        </a>


        <div class="nav-section-label">Cadastros</div>
        <a href="<?= url('pages/products.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'products.php' ? 'active' : '' ?>">
            <i class="fas fa-boxes-stacked"></i>
            <span>Produtos</span>
        </a>
        <a href="<?= url('pages/suppliers.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'suppliers.php' ? 'active' : '' ?>">
            <i class="fas fa-building"></i>
            <span>Fornecedores</span>
        </a>

                <a href="<?= url('pages/categories.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'categories.php' ? 'active' : '' ?>">
            <i class="fas fa-tags"></i>
            <span>Categorias</span>
        </a>

               <a href="<?= url('pages/customers.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'customers.php' ? 'active' : '' ?>">
            <i class="fas fa-users"></i>
            <span>Clientes</span>
        </a>

        <div class="nav-section-label">Estoque</div>
        
        <a href="<?= url('pages/stock_in.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'stock_in.php' ? 'active' : '' ?>">
            <i class="fas fa-arrow-down"></i>
            <span>Entrada de Estoque</span>
        </a>
        <a href="<?= url('pages/stock_adjust.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'stock_adjust.php' ? 'active' : '' ?>">
            <i class="fas fa-sliders"></i>
            <span>Ajuste de Estoque</span>
        </a>
        <a href="<?= url('pages/stock_history.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'stock_history.php' ? 'active' : '' ?>">
            <i class="fas fa-timeline"></i>
            <span>Histórico Estoque</span>
        </a>



        <div class="nav-section-label">Relatórios</div>
        <a href="<?= url('pages/reports.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : '' ?>">
            <i class="fas fa-chart-bar"></i>
            <span>Relatórios</span>
        </a>
        <a href="<?= url('pages/faltas.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'faltas.php' ? 'active' : '' ?>">
            <i class="fas fa-cart-plus"></i>
            <span>Faltas & Giro</span>
        </a>
 <a href="<?= url('pages/sales.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'sales.php' ? 'active' : '' ?>">
            <i class="fas fa-receipt"></i>
            <span>Vendas Gerais</span>
        </a>
<a href="<?= url('pages/demanda.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'demanda.php' ? 'active' : '' ?>">
            <i class="fas fa-chart-line"></i>
            <span>Vendas por Produto</span>
        </a>

        <?php if ($user['role'] == 'admin'): ?>
        <div class="nav-section-label">Admin</div>

        <a href="<?= url('pages/users.php') ?>" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : '' ?>">
            <i class="fas fa-user-gear"></i>
            <span>Usuários</span>
        </a>
        <?php endif; ?>


    </nav>

    <div class="sidebar-footer">
        <a href="<?= url('logout.php') ?>" class="logout-btn">
            <i class="fas fa-right-from-bracket"></i>
            <span>Sair</span>
        </a>
    </div>
</aside>

<!-- Main Content -->
<div class="main-wrapper" id="mainWrapper">
    <!-- Topbar -->
    <header class="topbar">
        <div class="topbar-left">
            <button class="topbar-toggle" id="topbarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="breadcrumb-area">
                <span class="page-title"><?= htmlspecialchars($pageTitle) ?></span>
            </div>
        </div>
        <div class="topbar-right">
            <div class="topbar-date">
                <i class="far fa-calendar"></i>
                <span id="currentDate"></span>
            </div>
            <div class="topbar-time">
                <i class="far fa-clock"></i>
                <span id="currentTime"></span>
            </div>
        </div>
    </header>

    <!-- Page Content -->
    <main class="page-content">