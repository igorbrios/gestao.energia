<?php
// ============================================================
// api_usuarios.php
// O que faz: Criar usuário, alterar nível, listar equipe
// Depende de: config.php
// Usado por: app_login.html (gestão de equipe — nível 5 apenas)
// ============================================================

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responder(false, null, 'Método não permitido.');
}

// Toda ação aqui exige autenticação
$eu = autenticar();

$body = json_decode(file_get_contents('php://input'), true);
$acao = $body['acao'] ?? '';

switch ($acao) {

    // ----------------------------------------------------------
    // LISTAR EQUIPE
    // Retorna todos os usuários ativos
    // Nível 4+ pode ver; nível 5 vê tudo
    // ----------------------------------------------------------
    case 'listar':
        exigir_nivel($eu, 4);

        $stmt = db()->prepare("
            SELECT id, nome, apelido, nivel, avatar_emoji, criado_em
            FROM usuarios
            WHERE ativo = 1
            ORDER BY nivel DESC, nome ASC
        ");
        $stmt->execute();
        responder(true, ['usuarios' => $stmt->fetchAll()]);
        break;

    // ----------------------------------------------------------
    // CRIAR USUÁRIO
    // Apenas nível 5
    // Recebe: { acao: "criar", nome, apelido, pin, nivel, avatar_emoji }
    // ----------------------------------------------------------
    case 'criar':
        exigir_nivel($eu, 5);

        $nome   = trim($body['nome'] ?? '');
        $apelido = trim($body['apelido'] ?? '');
        $pin    = trim($body['pin'] ?? '');
        $nivel  = (int)($body['nivel'] ?? 1);
        $emoji  = $body['avatar_emoji'] ?? '🌱';

        if (empty($nome)) responder(false, null, 'Nome é obrigatório.');
        if (strlen($pin) < 4 || strlen($pin) > 6) responder(false, null, 'PIN deve ter 4 a 6 dígitos.');
        if ($nivel < 1 || $nivel > 4) responder(false, null, 'Nível deve ser entre 1 e 4.'); // 5 só tem um

        $hash = password_hash($pin, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

        $stmt = db()->prepare("
            INSERT INTO usuarios (nome, apelido, nivel, pin, avatar_emoji)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$nome, $apelido ?: $nome, $nivel, $hash, $emoji]);
        $novo_id = db()->lastInsertId();

        // Registra histórico de nível inicial
        db()->prepare("
            INSERT INTO nivel_historico (usuario_id, nivel_anterior, nivel_novo, motivo, alterado_por)
            VALUES (?, 0, ?, 'Criação do usuário', ?)
        ")->execute([$novo_id, $nivel, $eu['id']]);

        // Cria permissões padrão baseadas no nível
        _criar_permissoes_padrao($novo_id, $nivel);

        responder(true, ['id' => $novo_id, 'mensagem' => "Usuário $nome criado com sucesso."]);
        break;

    // ----------------------------------------------------------
    // ALTERAR NÍVEL
    // Apenas nível 5; não pode promover para nível 5
    // ----------------------------------------------------------
    case 'alterar_nivel':
        exigir_nivel($eu, 5);

        $usuario_id  = (int)($body['usuario_id'] ?? 0);
        $nivel_novo  = (int)($body['nivel'] ?? 0);
        $motivo      = trim($body['motivo'] ?? '');

        if (!$usuario_id) responder(false, null, 'ID de usuário inválido.');
        if ($nivel_novo < 1 || $nivel_novo > 4) responder(false, null, 'Nível deve ser entre 1 e 4.');
        if ($usuario_id === (int)$eu['id']) responder(false, null, 'Você não pode alterar seu próprio nível.');

        $stmt = db()->prepare("SELECT nivel, nome FROM usuarios WHERE id = ? AND ativo = 1");
        $stmt->execute([$usuario_id]);
        $alvo = $stmt->fetch();
        if (!$alvo) responder(false, null, 'Usuário não encontrado.');

        // Atualiza nível
        db()->prepare("UPDATE usuarios SET nivel = ? WHERE id = ?")
            ->execute([$nivel_novo, $usuario_id]);

        // Registra histórico
        db()->prepare("
            INSERT INTO nivel_historico (usuario_id, nivel_anterior, nivel_novo, motivo, alterado_por)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$usuario_id, $alvo['nivel'], $nivel_novo, $motivo, $eu['id']]);

        // Atualiza permissões padrão conforme novo nível
        _criar_permissoes_padrao($usuario_id, $nivel_novo);

        responder(true, ['mensagem' => "Nível de {$alvo['nome']} alterado para $nivel_novo."]);
        break;

    // ----------------------------------------------------------
    // DESATIVAR USUÁRIO
    // Nunca deleta — apenas marca como inativo
    // ----------------------------------------------------------
    case 'desativar':
        exigir_nivel($eu, 5);

        $usuario_id = (int)($body['usuario_id'] ?? 0);
        if ($usuario_id === (int)$eu['id']) responder(false, null, 'Você não pode desativar sua própria conta.');

        db()->prepare("UPDATE usuarios SET ativo = 0 WHERE id = ?")
            ->execute([$usuario_id]);

        // Invalida todas as sessões do usuário
        db()->prepare("UPDATE sessoes SET ativo = 0 WHERE usuario_id = ?")
            ->execute([$usuario_id]);

        responder(true, ['mensagem' => 'Usuário desativado.']);
        break;

    // ----------------------------------------------------------
    // MEU PERFIL
    // Retorna dados do usuário autenticado
    // ----------------------------------------------------------
    case 'meu_perfil':
        $stmt = db()->prepare("
            SELECT id, nome, apelido, nivel, avatar_emoji, criado_em
            FROM usuarios WHERE id = ?
        ");
        $stmt->execute([$eu['id']]);
        responder(true, ['usuario' => $stmt->fetch()]);
        break;

    default:
        responder(false, null, 'Ação desconhecida.');
}

// ============================================================
// Função interna: cria permissões padrão por nível
// Chamada na criação do usuário e na mudança de nível
// ============================================================
function _criar_permissoes_padrao(int $usuario_id, int $nivel): void {
    // Desativa todas as permissões genéricas existentes (especie_id IS NULL)
    db()->prepare("
        UPDATE permissoes SET ativo = 0
        WHERE usuario_id = ? AND especie_id IS NULL
    ")->execute([$usuario_id]);

    // Mapa de permissões por nível
    // Permissões específicas por espécie são geridas separadamente pelo gestor
    $mapa = [
        // etapa => [nivel_minimo_livre, nivel_minimo_supervisionado]
        'irrigacao'         => [1, null],  // livre desde nível 1
        'saquinhos'         => [1, null],
        'tubetes'           => [2, 1],     // livre no 2, supervisionado no 1
        'semeadura'         => [3, 2],     // livre no 3, supervisionado no 2
        'repicagem'         => [3, 2],
        'transplante'       => [3, 2],
        'acompanhamento'    => [1, null],
        'expedicao'         => [4, 3],
        'rustificacao'      => [4, null],  // só gestor decide início
        'fitossanitario'    => [3, 2],
        'substrato'         => [3, 2],
    ];

    $stmt = db()->prepare("
        INSERT INTO permissoes (usuario_id, etapa, especie_id, tipo, concedido_por, motivo)
        VALUES (?, ?, NULL, ?, ?, 'Permissão padrão por nível')
    ");

    foreach ($mapa as $etapa => [$nivel_livre, $nivel_sup]) {
        if ($nivel >= $nivel_livre) {
            $tipo = 'livre';
        } elseif ($nivel_sup !== null && $nivel >= $nivel_sup) {
            $tipo = 'supervisionado';
        } else {
            $tipo = 'bloqueado';
        }

        $stmt->execute([$usuario_id, $etapa, $tipo, $usuario_id]);
    }
}
