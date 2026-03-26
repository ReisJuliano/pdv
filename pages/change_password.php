<?php
require_once __DIR__.'/../includes/config.php';
requireLogin();

// Se não precisa trocar, manda pro index
$db = getDB();
$u = $db->query("SELECT must_change_password FROM users WHERE id = {$_SESSION['user_id']}")->fetch();
if (empty($u['must_change_password'])) redirect('index.php');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nova   = trim($_POST['new_password'] ?? '');
    $confirm = trim($_POST['confirm_password'] ?? '');

    if (strlen($nova) < 6) {
        $error = 'A senha deve ter pelo menos 6 caracteres.';
    } elseif ($nova !== $confirm) {
        $error = 'As senhas não coincidem.';
    } else {
        $stmt = $db->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
        $stmt->execute([password_hash($nova, PASSWORD_DEFAULT), $_SESSION['user_id']]);
        redirect('index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trocar Senha — Nimvo</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/main.css') ?>">
    <style>
        html, body { display: block !important; min-height: 100vh; }
        .login-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
    </style>
</head>
<body>
<div class="login-page">
    <div class="login-box">
        <div class="login-logo">
            <div class="logo-login-icon">
                <img src="/pdv/assets/img/logo.png" alt="Logo" style="width:60px;height:60px;">
            </div>
            <div class="login-title">Nimvo</div>
            <div class="login-sub">Primeiro acesso</div>
        </div>
        <div class="login-card">
            <h2 style="font-size:18px;font-weight:800;margin-bottom:6px;color:var(--text-primary)">Crie sua senha</h2>
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:24px">Por segurança, defina uma nova senha para continuar.</p>
            <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-circle-xmark"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label class="form-label">Nova senha</label>
                    <div style="position:relative">
                        <i class="fas fa-lock" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px"></i>
                        <input type="password" name="new_password" class="form-control"
                               placeholder="Mínimo 6 caracteres" required minlength="6"
                               style="padding-left:36px">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirmar senha</label>
                    <div style="position:relative">
                        <i class="fas fa-lock" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px"></i>
                        <input type="password" name="confirm_password" class="form-control"
                               placeholder="Repita a senha" required minlength="6"
                               style="padding-left:36px">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-lg btn-block" style="margin-top:8px;height:48px;font-size:15px">
                    <i class="fas fa-check"></i> Salvar senha
                </button>
            </form>
        </div>
    </div>
</div>
</body>
</html>