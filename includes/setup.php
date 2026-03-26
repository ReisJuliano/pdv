<?php

function checkAndSetup() {
    try {
        $pdo = new PDO(
            "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        // Banco acessível, nada a fazer
    } catch (PDOException $e) {
        die('<div style="font-family:sans-serif;padding:40px;max-width:600px;margin:60px auto;background:#fef2f2;border:1px solid #fecaca;border-radius:12px;color:#dc2626">
            <h2>Erro de conexão</h2>
            <p>Não foi possível conectar ao banco de dados.</p>
            <p style="font-size:13px;color:#666">Banco: <b>'.DB_NAME.'</b></p>
        </div>');
    }
}

checkAndSetup();