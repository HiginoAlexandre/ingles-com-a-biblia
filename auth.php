<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
if (isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
$mode = (string)($_GET['mode'] ?? 'login');
if (!in_array($mode, ['login', 'register'], true)) $mode = 'login';
$error = '';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf_token'], $postedToken)) {
        $error = 'A sessão do formulário expirou. Actualize a página e tente novamente.';
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        try {
            $pdo = db();
            if ($mode === 'register') {
                $name = trim((string)($_POST['name'] ?? ''));
                if (mb_strlen($name) < 2 || mb_strlen($name) > 120) throw new RuntimeException('Informe um nome entre 2 e 120 caracteres.');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new RuntimeException('Informe um endereço de e-mail válido.');
                if (strlen($password) < 8) throw new RuntimeException('A palavra-passe deve ter pelo menos 8 caracteres.');
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
                try { $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]); }
                catch (PDOException $e) { if ((string)$e->getCode() === '23000') throw new RuntimeException('Este e-mail já está registado.'); throw $e; }
                $userId = (int)$pdo->lastInsertId();
                $cycle = $pdo->prepare('INSERT INTO reading_cycles (user_id, cycle_number, started_at) VALUES (?, 1, NOW())');
                $cycle->execute([$userId]);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                $_SESSION['user_name'] = $name;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: index.php'); exit;
            }
            $stmt = $pdo->prepare('SELECT id, name, password_hash FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($password, (string)$user['password_hash'])) {
                throw new RuntimeException('E-mail ou palavra-passe incorrectos.');
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_name'] = (string)$user['name'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $find = $pdo->prepare('SELECT id FROM reading_cycles WHERE user_id = ? AND completed_at IS NULL ORDER BY cycle_number DESC LIMIT 1');
            $find->execute([(int)$user['id']]);
            if (!$find->fetchColumn()) {
                $next = $pdo->prepare('SELECT COALESCE(MAX(cycle_number), 0) + 1 FROM reading_cycles WHERE user_id = ?');
                $next->execute([(int)$user['id']]);
                $insert = $pdo->prepare('INSERT INTO reading_cycles (user_id, cycle_number, started_at) VALUES (?, ?, NOW())');
                $insert->execute([(int)$user['id'], (int)$next->fetchColumn()]);
            }
            header('Location: index.php'); exit;
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível concluir a operação. Confirme a ligação à base de dados.';
        }
    }
}
?><!doctype html>
<html lang="pt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $mode === 'register' ? 'Criar conta' : 'Iniciar sessão' ?> — Leitor Bíblico</title><link rel="stylesheet" href="assets/style.css"><style>
.auth-wrap{max-width:480px;margin:8vh auto;padding:24px}.auth-card{background:var(--card,#fff);border:1px solid var(--line,#ddd);border-radius:18px;padding:28px;box-shadow:0 12px 38px #0000000b}.auth-card h1{margin:0 0 8px}.auth-card p{line-height:1.5}.auth-card label{display:block;margin:16px 0 6px;font-weight:600}.auth-card input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #cbd5d0;border-radius:9px;font:inherit}.auth-card button{margin-top:20px;width:100%;padding:12px;border:0;border-radius:9px;background:#285b48;color:#fff;font:inherit;font-weight:700;cursor:pointer}.auth-error{padding:12px;background:#fff0f0;color:#9d2222;border-radius:8px}.auth-switch{margin-top:20px;text-align:center}
</style></head><body><main class="auth-wrap"><a class="brand" href="index.php"><span class="brand-icon">✝</span><span><strong>Leitor Bíblico</strong><small>LEITURA · MEDITAÇÃO · PROGRESSO</small></span></a><section class="auth-card"><h1><?= $mode === 'register' ? 'Criar a tua conta' : 'Bem-vindo de volta' ?></h1><p><?= $mode === 'register' ? 'Guarda o teu progresso e o histórico das leituras completas.' : 'Inicia sessão para continuar a tua leitura em qualquer dispositivo.' ?></p><?php if ($error !== ''): ?><p class="auth-error"><?= h($error) ?></p><?php endif; ?><form method="post" action="auth.php?mode=<?= h($mode) ?>"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><?php if ($mode === 'register'): ?><label for="name">Nome</label><input id="name" name="name" maxlength="120" required autocomplete="name"><?php endif; ?><label for="email">E-mail</label><input id="email" type="email" name="email" maxlength="190" required autocomplete="email"><label for="password">Palavra-passe</label><input id="password" type="password" name="password" required minlength="8" autocomplete="<?= $mode === 'register' ? 'new-password' : 'current-password' ?>"><?php if ($mode === 'register'): ?><small>Usa pelo menos 8 caracteres.</small><?php endif; ?><button type="submit"><?= $mode === 'register' ? 'Criar conta' : 'Iniciar sessão' ?></button></form><p class="auth-switch"><?php if ($mode === 'register'): ?>Já tens conta? <a href="auth.php?mode=login">Iniciar sessão</a><?php else: ?>Ainda não tens conta? <a href="auth.php?mode=register">Criar conta</a><?php endif; ?></p><p class="auth-switch"><a href="index.php">Continuar sem conta</a></p></section></main></body></html>
