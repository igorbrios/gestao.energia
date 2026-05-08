<?php
// ============================================================
// api_auth.php
// O que faz: Login, logout e verificação de sessão
// Depende de: config.php
// Usado por: app_login.html, todos os módulos (via autenticar())
// ============================================================

require_once __DIR__ . '/config.php';

// Apenas POST é aceito
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responder(false, null, 'Método não permitido.');
}

// Lê o corpo JSON da requisição
$body = json_decode(file_get_contents('php://input'), true);
$acao = $body['acao'] ?? '';

switch ($acao) {

    // ----------------------------------------------------------
    // LOGIN
    // Recebe: { acao: "login", pin: "123456" }
    // Retorna: dados do usuário + define cookie de sessão
    //
    // NOTA: o app envia apenas o PIN. Não há campo de usuário
    // porque é um viveiro pequeno — um dispositivo por pessoa.
    // Se crescer, adicionar seletor de usuário antes do PIN.
    // ----------------------------------------------------------
    case 'login':
        $pin = trim($body['pin'] ?? '');

        if (strlen($pin) < 4 || strlen($pin) > 6) {
            responder(false, null, 'PIN inválido.');
        }

        // Busca todos os usuários ativos (poucos — viveiro pequeno)
        $stmt = db()->prepare("
            SELECT id, nome, apelido, nivel, pin, avatar_emoji
            FROM usuarios
            WHERE ativo = 1
            ORDER BY nivel DESC
        ");
        $stmt->execute();
        $usuarios = $stmt->fetchAll();

        $usuario_autenticado = null;
        foreach ($usuarios as $u) {
            if (password_verify($pin, $u['pin'])) {
                $usuario_autenticado = $u;
                break;
            }
        }

        if (!$usuario_autenticado) {
            // Delay para dificultar brute-force
            sleep(1);
            responder(false, null, 'PIN incorreto.');
        }

        // Gera token de sessão
        $token     = bin2hex(random_bytes(32)); // 64 chars hex
        $expira_em = date('Y-m-d H:i:s', time() + (SESSION_DIAS * 86400));
        $dispositivo = substr($_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido', 0, 200);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $stmt = db()->prepare("
            INSERT INTO sessoes (usuario_id, token, dispositivo, ip, expira_em)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $usuario_autenticado['id'],
            $token,
            $dispositivo,
            $ip,
            $expira_em,
        ]);

        // Define cookie HTTP-only (30 dias)
        setcookie(
            SESSION_COOKIE,
            $token,
            [
                'expires'  => time() + (SESSION_DIAS * 86400),
                'path'     => '/',
                'secure'   => true,        // só HTTPS
                'httponly' => true,        // não acessível por JS
                'samesite' => 'Strict',
            ]
        );

        // Remove PIN do retorno
        unset($usuario_autenticado['pin']);

        responder(true, [
            'usuario' => $usuario_autenticado,
            'token'   => $token,           // também retorna no JSON para o app guardar no localStorage
            'expira'  => $expira_em,
        ]);
        break;

    // ----------------------------------------------------------
    // LOGOUT
    // Recebe: { acao: "logout" }
    // Desativa a sessão atual
    // ----------------------------------------------------------
    case 'logout':
        $usuario = autenticar(); // exige sessão válida
        $token   = $_COOKIE[SESSION_COOKIE] ?? $_SERVER['HTTP_X_VF_TOKEN'] ?? '';

        db()->prepare("UPDATE sessoes SET ativo = 0 WHERE token = ?")
            ->execute([$token]);

        // Remove cookie
        setcookie(SESSION_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        responder(true, ['mensagem' => 'Logout realizado.']);
        break;

    // ----------------------------------------------------------
    // VERIFICAR SESSÃO
    // Recebe: { acao: "verificar" }
    // Retorna dados do usuário se a sessão for válida
    // Usado pelo app na abertura para saber se precisa pedir PIN
    // ----------------------------------------------------------
    case 'verificar':
        $usuario = autenticar();
        unset($usuario['expira_em']); // não precisa ir para o app
        responder(true, ['usuario' => $usuario]);
        break;

    // ----------------------------------------------------------
    // TROCAR PIN
    // Recebe: { acao: "trocar_pin", pin_atual: "...", pin_novo: "..." }
    // ----------------------------------------------------------
    case 'trocar_pin':
        $usuario   = autenticar();
        $pin_atual = trim($body['pin_atual'] ?? '');
        $pin_novo  = trim($body['pin_novo'] ?? '');

        if (strlen($pin_novo) < 4 || strlen($pin_novo) > 6) {
            responder(false, null, 'O novo PIN deve ter entre 4 e 6 dígitos.');
        }

        // Verifica PIN atual
        $stmt = db()->prepare("SELECT pin FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario['id']]);
        $hash_atual = $stmt->fetchColumn();

        if (!password_verify($pin_atual, $hash_atual)) {
            responder(false, null, 'PIN atual incorreto.');
        }

        // Salva novo PIN
        $novo_hash = password_hash($pin_novo, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        db()->prepare("UPDATE usuarios SET pin = ? WHERE id = ?")
            ->execute([$novo_hash, $usuario['id']]);

        responder(true, ['mensagem' => 'PIN alterado com sucesso.']);
        break;

    default:
        responder(false, null, 'Ação desconhecida.');
}
