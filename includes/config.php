<?php
define('DB_HOST', 'localhost');
define('DB_PORT', 3308);
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'mercearia_tatu');
define('APP_NAME', 'Mercearia do Tatu');
define('APP_VERSION', '1.0.0');

session_start();

// Detecta se está dentro de /pages/ e ajusta o prefixo
function url($path = '') {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
    // Remove o arquivo do final para pegar só o diretório
    $dir = dirname($script);
    // Se estiver em /pages, sobe um nível
    if (basename($dir) === 'pages') {
        $base = dirname($dir);
    } else {
        $base = $dir;
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function redirect($path) {
    header('Location: ' . url($path));
    exit;
}

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4",
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch(PDOException $e) {
            die('<div style="font-family:sans-serif;padding:40px;max-width:600px;margin:60px auto;background:#fef2f2;border:1px solid #fecaca;border-radius:12px;color:#dc2626"><h2>Erro de Conexao com o Banco</h2><p>'.$e->getMessage().'</p><p style="font-size:13px;color:#666;margin-top:12px">Verifique as configuracoes em <code>includes/config.php</code></p></div>');
        }
    }
    return $pdo;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) redirect('login.php');
}

function currentUser() {
    return $_SESSION['user'] ?? null;
}

function formatMoney($value) {
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

function formatDate($date) {
    if (!$date) return '-';
    return date('d/m/Y', strtotime($date));
}

function formatDateTime($date) {
    if (!$date) return '-';
    return date('d/m/Y H:i', strtotime($date));
}
