<?php
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/setup.php';

if (isLoggedIn()) { redirect('index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $pass     = trim($_POST['password'] ?? '');
    if ($username && $pass) {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($pass, $user['password'])) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user']    = ['id'=>$user['id'],'name'=>$user['name'],'username'=>$user['username'],'role'=>$user['role']];
    
    if (!empty($user['must_change_password'])) {
        redirect('pages/change_password.php');
    } else {
        redirect('index.php');
    }
    exit;
} else {
            $error = 'Usuário ou senha incorretos.';
        }
    } else {
        $error = 'Preencha todos os campos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Nimvo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= url('assets/css/main.css') ?>">
    <style>
        html, body { display: block !important; min-height: 100vh;}
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
            <div class="login-sub">Sistema Inteligente</div>
        </div>
        <div class="login-card">
            <h2 style="font-size:18px;font-weight:800;margin-bottom:6px;color:var(--text-primary)">Entrar no sistema</h2>
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:24px">Informe suas credenciais de acesso</p>
            <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-circle-xmark"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label class="form-label">Usuário</label>
                    <div style="position:relative">
                        <i class="fas fa-user" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px"></i>
                        <input type="text" name="username" class="form-control" placeholder="admin"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                               required autofocus autocomplete="username"
                               style="padding-left:36px">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Senha</label>
                    <div style="position:relative">
                        <i class="fas fa-lock" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px"></i>
                        <input type="password" name="password" id="passInput" class="form-control"
                               placeholder="••••••••" required autocomplete="current-password"
                               style="padding-left:36px;padding-right:42px">
                        <button type="button" onclick="togglePass()"
                                style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:14px;padding:4px">
                            <i class="fas fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-lg btn-block" style="margin-top:8px;height:48px;font-size:15px">
                    <i class="fas fa-right-to-bracket"></i> Entrar
                </button>
            </form>
        </div>
    </div>
</div>
<script>
function togglePass() {
    const i = document.getElementById('passInput');
    const e = document.getElementById('eyeIcon');
    i.type = i.type === 'password' ? 'text' : 'password';
    e.className = i.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}
</script>
</body>
</html>
