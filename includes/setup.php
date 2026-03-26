<?php
// Cria o banco e tabelas automaticamente na primeira execucao
// Nao define constantes — tudo vem do config.php

function runSetup() {
    try {
        // Conecta sem selecionar banco ainda
        $pdo = new PDO(
            "mysql:host=".DB_HOST.";port=".DB_PORT.";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `".DB_NAME."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `".DB_NAME."`");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `username` varchar(100) NOT NULL UNIQUE,
            `password` varchar(255) NOT NULL,
            `role` enum('admin','operator') DEFAULT 'operator',
            `active` tinyint(1) DEFAULT 1,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `categories` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `description` text,
            `active` tinyint(1) DEFAULT 1,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `suppliers` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(150) NOT NULL,
            `cnpj` varchar(20),
            `phone` varchar(20),
            `email` varchar(100),
            `address` text,
            `contact_name` varchar(100),
            `active` tinyint(1) DEFAULT 1,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `products` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `code` varchar(50) UNIQUE,
            `name` varchar(200) NOT NULL,
            `description` text,
            `category_id` int(11),
            `supplier_id` int(11),
            `unit` varchar(20) DEFAULT 'UN',
            `cost_price` decimal(10,2) DEFAULT 0.00,
            `sale_price` decimal(10,2) DEFAULT 0.00,
            `stock_quantity` decimal(10,3) DEFAULT 0.000,
            `min_stock` decimal(10,3) DEFAULT 0.000,
            `barcode` varchar(50),
            `ncm` varchar(10) DEFAULT '22030000',
            `active` tinyint(1) DEFAULT 1,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Adiciona coluna NCM se já existia a tabela sem ela
        try { $pdo->exec("ALTER TABLE products ADD COLUMN ncm varchar(10) DEFAULT '22030000'"); } catch(Exception $e) {}

        $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_movements` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `product_id` int(11) NOT NULL,
            `type` enum('entrada','saida','ajuste','venda') NOT NULL,
            `quantity` decimal(10,3) NOT NULL,
            `unit_cost` decimal(10,2) DEFAULT 0.00,
            `unit_price` decimal(10,2) DEFAULT 0.00,
            `total_cost` decimal(10,2) DEFAULT 0.00,
            `total_price` decimal(10,2) DEFAULT 0.00,
            `reference` varchar(100),
            `notes` text,
            `user_id` int(11),
            `sale_id` int(11),
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `customers` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(150) NOT NULL,
            `cpf_cnpj` varchar(20),
            `phone` varchar(20),
            `email` varchar(100),
            `address` text,
            `credit_limit` decimal(10,2) DEFAULT 0.00,
            `active` tinyint(1) DEFAULT 1,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `sales` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `sale_number` varchar(20) UNIQUE,
            `customer_id` int(11),
            `user_id` int(11) NOT NULL,
            `subtotal` decimal(10,2) DEFAULT 0.00,
            `discount` decimal(10,2) DEFAULT 0.00,
            `total` decimal(10,2) DEFAULT 0.00,
            `cost_total` decimal(10,2) DEFAULT 0.00,
            `profit` decimal(10,2) DEFAULT 0.00,
            `payment_method` enum('dinheiro','cartao_credito','cartao_debito','pix','fiado') DEFAULT 'dinheiro',
            `status` enum('aberta','finalizada','cancelada') DEFAULT 'finalizada',
            `notes` text,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `sale_items` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `sale_id` int(11) NOT NULL,
            `product_id` int(11) NOT NULL,
            `quantity` decimal(10,3) NOT NULL,
            `unit_cost` decimal(10,2) DEFAULT 0.00,
            `unit_price` decimal(10,2) DEFAULT 0.00,
            `discount` decimal(10,2) DEFAULT 0.00,
            `total` decimal(10,2) DEFAULT 0.00,
            `profit` decimal(10,2) DEFAULT 0.00,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `purchases` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `purchase_number` varchar(50),
            `supplier_id` int(11),
            `user_id` int(11),
            `total` decimal(10,2) DEFAULT 0.00,
            `notes` text,
            `purchase_date` date,
            `status` enum('pendente','recebido','cancelado') DEFAULT 'recebido',
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `purchase_items` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `purchase_id` int(11) NOT NULL,
            `product_id` int(11) NOT NULL,
            `quantity` decimal(10,3) NOT NULL,
            `unit_cost` decimal(10,2) DEFAULT 0.00,
            `total` decimal(10,2) DEFAULT 0.00,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Admin padrão (só se não existir nenhum usuário)
        $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count == 0) {
            $hash = password_hash('admin', PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (name,username,password,role) VALUES (?,?,?,?)")
                ->execute(['Administrador','admin',$hash,'admin']);
        }

    } catch(PDOException $e) {
        die('<div style="font-family:sans-serif;padding:40px;max-width:600px;margin:60px auto;background:#fef2f2;border:1px solid #fecaca;border-radius:12px;color:#dc2626"><h2>Erro ao configurar o banco</h2><p>'.$e->getMessage().'</p></div>');
    }
}

// Roda automaticamente se o banco/tabelas não existirem
function checkAndSetup() {
    try {
        $pdo = new PDO(
            "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $exists = $pdo->query("SHOW TABLES LIKE 'users'")->rowCount();
        if (!$exists) runSetup();
    } catch(PDOException $e) {
        runSetup();
    }
}

checkAndSetup();

// Tabelas de caixa
function setupCaixaTables() {
    try {
        $pdo = new PDO(
            "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4",
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("CREATE TABLE IF NOT EXISTS `caixas` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `status` enum('aberto','fechado') DEFAULT 'aberto',
            `valor_abertura` decimal(10,2) DEFAULT 0.00,
            `valor_fechamento` decimal(10,2) DEFAULT NULL,
            `observacao_abertura` text,
            `observacao_fechamento` text,
            `aberto_em` datetime NOT NULL,
            `fechado_em` datetime DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `caixa_movimentos` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `caixa_id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL,
            `tipo` enum('sangria','suprimento') NOT NULL,
            `valor` decimal(10,2) NOT NULL,
            `motivo` text,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(PDOException $e) {}
}
setupCaixaTables();

// Tabela de baixas de fiado
function setupFiadoTable() {
    try {
        $pdo = new PDO(
            "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4",
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("CREATE TABLE IF NOT EXISTS `fiado_pagamentos` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `customer_id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL,
            `valor` decimal(10,2) NOT NULL,
            `forma_pagamento` enum('dinheiro','pix','cartao_debito','cartao_credito') DEFAULT 'dinheiro',
            `caixa_id` int(11) DEFAULT NULL,
            `observacao` text,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(PDOException $e) {}
}
setupFiadoTable();

// Tabelas de pedidos / comandas
function setupPedidosTables() {
    try {
        $pdo = new PDO(
            "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4",
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $pdo->exec("CREATE TABLE IF NOT EXISTS `pedidos` (
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
            PRIMARY KEY (`id`),
            KEY `idx_comanda_codigo` (`comanda_codigo`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `pedido_items` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `pedido_id` int(11) NOT NULL,
            `product_id` int(11) NOT NULL,
            `quantity` decimal(10,3) NOT NULL,
            `unit_price` decimal(10,2) NOT NULL,
            `unit_cost` decimal(10,2) DEFAULT 0.00,
            `total` decimal(10,2) NOT NULL,
            `notes` text,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_pedido_id` (`pedido_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    } catch(PDOException $e) {}
}
setupPedidosTables();

// Migration: email -> username se banco já existia
function migrateEmailToUsername() {
    try {
        $pdo = new PDO(
            "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4",
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        // Verifica se coluna email ainda existe
        $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'")->fetchAll();
        if ($cols) {
            // Adiciona username se não existir
            try { $pdo->exec("ALTER TABLE users ADD COLUMN `username` varchar(100) UNIQUE AFTER `name`"); } catch(Exception $e) {}
            // Copia email para username onde username é null
            $pdo->exec("UPDATE users SET username = SUBSTRING_INDEX(email,'@',1) WHERE username IS NULL OR username = ''");
            // Remove coluna email
            try { $pdo->exec("ALTER TABLE users DROP COLUMN email"); } catch(Exception $e) {}
        }
    } catch(Exception $e) {}
}
migrateEmailToUsername();

// Tabela de carrinho persistente do PDV
function setupCarrinhoTable() {
    try {
        $pdo = new PDO(
            "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4",
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("CREATE TABLE IF NOT EXISTS `pdv_carrinho` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `product_id` int(11) NOT NULL,
            `product_name` varchar(200) NOT NULL,
            `unit` varchar(20) DEFAULT 'UN',
            `quantity` decimal(10,3) NOT NULL DEFAULT 1.000,
            `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
            `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
            `customer_id` int(11) DEFAULT NULL,
            `payment_method` varchar(30) DEFAULT 'dinheiro',
            `discount` decimal(10,2) DEFAULT 0.00,
            `notes` varchar(255) DEFAULT NULL,
            `pedido_id` int(11) DEFAULT NULL,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(PDOException $e) {}
}
setupCarrinhoTable();