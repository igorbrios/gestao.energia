<?php
// ============================================================
// config.php
// O que faz: Credenciais do banco e configurações globais
// Depende de: nada (importado por todos os arquivos PHP)
// Usado por: api_auth.php, api_usuarios.php e todos os futuros api_*.php
// NUNCA versionar este arquivo com credenciais reais.
// ============================================================

// --- BANCO DE DADOS ---
define('DB_HOST', 'localhost');          // geralmente 'localhost' na Hostinger
define('DB_NAME', 'u805221541_gestao_viveiro'); // nome do banco criado no hPanel
define('DB_USER', 'u805221541_gestao_igor'); // usuário MySQL da Hostinger
define('DB_PASS', 'Variancia33');   // senha MySQL da Hostinger
define('DB_CHARSET', 'utf8mb4');

// --- APP ---
define('APP_NAME', 'Viveiro Florescer');
define('APP_VERSION', '1.0');
define('APP_ENV', 'production'); // 'development' | 'production'

// --- SESSÃO ---
define('SESSION_DIAS', 30);    // sessão expira em 30 dias
define('SESSION_COOKIE', 'vf_token'); // nome do cookie

// --- SEGURANÇA ---
define('BCRYPT_COST', 10);     // custo do hash bcrypt

// --- FUSO HORÁRIO ---
date_default_timezone_set('America/Campo_Grande'); // MS

// ============================================================
// Conexão PDO — usada por todos os arquivos PHP
// ============================================================
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST, DB_NAME, DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Em produção, não expõe detalhes do erro
        if (APP_ENV === 'development') {
            responder(false, null, 'Banco: ' . $e->getMessage());
        } else {
            responder(false, null, 'Erro de conexão com o banco de dados.');
        }
        exit;
    }

    return $pdo;
}

// ============================================================
// Resposta padrão da API — sempre o mesmo formato
// { "ok": true, "dados": {...} }
// { "ok": false, "erro": "mensagem clara" }
// ============================================================
function responder(bool $ok, $dados = null, string $erro = ''): void {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $payload = ['ok' => $ok];
    if ($ok)  $payload['dados'] = $dados;
    if (!$ok) $payload['erro']  = $erro;

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// Verificar sessão — chamado em toda requisição autenticada
// Retorna o array do usuário ou encerra com erro 401
// ============================================================
function autenticar(): array {
    $token = $_COOKIE[SESSION_COOKIE] ?? $_SERVER['HTTP_X_VF_TOKEN'] ?? '';
    if (empty($token)) {
        http_response_code(401);
        responder(false, null, 'Não autenticado.');
    }

    $stmt = db()->prepare("
        SELECT u.id, u.nome, u.apelido, u.nivel, u.avatar_emoji, u.ativo,
               s.expira_em
        FROM sessoes s
        JOIN usuarios u ON u.id = s.usuario_id
        WHERE s.token = ? AND s.ativo = 1 AND u.ativo = 1
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        http_response_code(401);
        responder(false, null, 'Sessão inválida ou expirada.');
    }

    if (strtotime($usuario['expira_em']) < time()) {
        // Inativa a sessão expirada
        db()->prepare("UPDATE sessoes SET ativo = 0 WHERE token = ?")->execute([$token]);
        http_response_code(401);
        responder(false, null, 'Sessão expirada. Faça login novamente.');
    }

    return $usuario;
}

// ============================================================
// Verificar nível mínimo — aborta se o usuário não tem acesso
// ============================================================
function exigir_nivel(array $usuario, int $nivel_minimo): void {
    if ((int)$usuario['nivel'] < $nivel_minimo) {
        http_response_code(403);
        responder(false, null, 'Acesso negado. Nível insuficiente.');
    }
}

// ============================================================
// Verificar permissão específica por etapa × espécie
// Retorna 'livre' | 'supervisionado' | 'bloqueado'
// ============================================================
function verificar_permissao(int $usuario_id, string $etapa, ?int $especie_id = null): string {
    // Nível 5 tem tudo livre sempre
    $stmt = db()->prepare("SELECT nivel FROM usuarios WHERE id = ?");
    $stmt->execute([$usuario_id]);
    $nivel = (int)($stmt->fetchColumn() ?? 0);
    if ($nivel >= 5) return 'livre';

    // Busca permissão específica (espécie + etapa), depois genérica (só etapa)
    $stmt = db()->prepare("
        SELECT tipo FROM permissoes
        WHERE usuario_id = ?
          AND etapa = ?
          AND (especie_id = ? OR especie_id IS NULL)
          AND ativo = 1
        ORDER BY especie_id DESC  -- específica tem prioridade sobre genérica
        LIMIT 1
    ");
    $stmt->execute([$usuario_id, $etapa, $especie_id]);
    $tipo = $stmt->fetchColumn();

    return $tipo ?: 'bloqueado'; // se não tem registro, está bloqueado
}
