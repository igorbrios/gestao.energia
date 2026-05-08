<?php
// ============================================================
// api_ponto.php
// O que faz: Registrar entrada/saída, ponto manual pelo gestor,
//            sincronizar fila offline, buscar ponto do dia
// Depende de: config.php, db_ponto.sql
// Usado por: app_ponto.html, offline_queue.js
// ============================================================

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    responder(false, null, 'Método não permitido.');
}

$eu   = autenticar();
$body = json_decode(file_get_contents('php://input'), true);
$acao = $body['acao'] ?? '';

switch ($acao) {

    // ----------------------------------------------------------
    // REGISTRAR ENTRADA
    // Recebe: { acao: "entrada", foto_base64, lat, lng, uuid }
    // ----------------------------------------------------------
    case 'entrada':
        $data  = date('Y-m-d');
        $agora = date('Y-m-d H:i:s');
        $uuid  = $body['uuid'] ?? null;
        $lat   = isset($body['lat']) ? (float)$body['lat'] : null;
        $lng   = isset($body['lng']) ? (float)$body['lng'] : null;

        // Verifica se há entrada ABERTA (sem saída) hoje
        $stmt = db()->prepare("SELECT id FROM pontos WHERE usuario_id = ? AND data = ? AND entrada IS NOT NULL AND saida IS NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([$eu['id'], $data]);
        $aberto = $stmt->fetch();

        if ($aberto) {
            responder(false, null, 'Já há uma entrada em aberto. Registre a saída primeiro.');
        }

        $foto_path = null;
        if (!empty($body['foto_base64'])) {
            $foto_path = _salvar_foto($body['foto_base64'], "ponto_entrada_{$eu['id']}_" . time());
        }

        $hora = (int)date('H'); $min = (int)date('i');
        if ($hora < 8) $score = 50;
        elseif ($hora === 8 && $min <= 10) $score = 30;
        else $score = 10;

        db()->prepare("
            INSERT INTO pontos (usuario_id, data, entrada, entrada_foto, entrada_lat, entrada_lng, score_ponto, offline_uuid)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$eu['id'], $data, $agora, $foto_path, $lat, $lng, $score, $uuid]);
        $ponto_id = db()->lastInsertId();

        responder(true, ['ponto_id' => $ponto_id, 'entrada' => $agora, 'score' => $score]);
        break;

    // ----------------------------------------------------------
    // REGISTRAR SAÍDA
    // Recebe: { acao: "saida", foto_base64, lat, lng, uuid }
    // ----------------------------------------------------------
    case 'saida':
        $data  = date('Y-m-d');
        $agora = date('Y-m-d H:i:s');
        $uuid  = $body['uuid'] ?? null;
        $lat   = isset($body['lat']) ? (float)$body['lat'] : null;
        $lng   = isset($body['lng']) ? (float)$body['lng'] : null;

        // Busca a entrada aberta mais recente (sem saída)
        $stmt = db()->prepare("SELECT id, entrada FROM pontos WHERE usuario_id = ? AND data = ? AND entrada IS NOT NULL AND saida IS NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([$eu['id'], $data]);
        $aberto = $stmt->fetch();

        if (!$aberto) {
            responder(false, null, 'Não há entrada em aberto para registrar saída.');
        }

        $foto_path = null;
        if (!empty($body['foto_base64'])) {
            $foto_path = _salvar_foto($body['foto_base64'], "ponto_saida_{$eu['id']}_" . time());
        }

        $horas = (strtotime($agora) - strtotime($aberto['entrada'])) / 3600;

        db()->prepare("
            UPDATE pontos SET saida = ?, saida_foto = ?, saida_lat = ?, saida_lng = ? WHERE id = ?
        ")->execute([$agora, $foto_path, $lat, $lng, $aberto['id']]);

        _atualizar_score_diario($eu['id'], $data);

        responder(true, ['saida' => $agora, 'horas' => round($horas, 2)]);
        break;

    // ----------------------------------------------------------
    // PONTO MANUAL (gestor registra por funcionário)
    // Recebe: { acao: "manual", usuario_id, entrada, saida, obs }
    // Nível 4+ pode registrar
    // ----------------------------------------------------------
    case 'manual':
        exigir_nivel($eu, 4);

        $usuario_id = (int)($body['usuario_id'] ?? 0);
        $entrada    = $body['entrada'] ?? null;  // "2026-05-03 07:48:00"
        $saida_m    = $body['saida'] ?? null;
        $obs        = trim($body['obs'] ?? '');

        if (!$usuario_id) responder(false, null, 'ID de usuário inválido.');

        // Verifica se usuário existe
        $stmt = db()->prepare("SELECT id FROM usuarios WHERE id = ? AND ativo = 1");
        $stmt->execute([$usuario_id]);
        if (!$stmt->fetch()) responder(false, null, 'Usuário não encontrado.');

        $data = $entrada ? date('Y-m-d', strtotime($entrada)) : date('Y-m-d');

        // Verifica se já existe ponto nesse dia
        $stmt = db()->prepare("SELECT id FROM pontos WHERE usuario_id = ? AND data = ?");
        $stmt->execute([$usuario_id, $data]);
        $existe = $stmt->fetch();

        $score = -30; // ponto manual sempre perde pontos

        if ($existe) {
            db()->prepare("
                UPDATE pontos
                SET entrada = COALESCE(?, entrada),
                    saida = COALESCE(?, saida),
                    registrado_por = ?,
                    obs_gestor = ?,
                    score_ponto = score_ponto + ?
                WHERE id = ?
            ")->execute([$entrada, $saida_m, $eu['id'], $obs, $score, $existe['id']]);
        } else {
            db()->prepare("
                INSERT INTO pontos (usuario_id, data, entrada, saida, registrado_por, obs_gestor, score_ponto)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$usuario_id, $data, $entrada, $saida_m, $eu['id'], $obs, $score]);
        }

        _atualizar_score_diario($usuario_id, $data);

        responder(true, ['mensagem' => 'Ponto registrado manualmente.', 'score_ajuste' => $score]);
        break;

    // ----------------------------------------------------------
    // BUSCAR PONTO DO DIA
    // Retorna ponto e atividades de hoje para o usuário logado
    // ----------------------------------------------------------
    case 'hoje':
        $data = date('Y-m-d');
        $uid  = isset($body['usuario_id']) && (int)$eu['nivel'] >= 4
                ? (int)$body['usuario_id']
                : (int)$eu['id'];

        // Todos os registros do dia (para somar horas e mostrar histórico)
        $stmt = db()->prepare("SELECT * FROM pontos WHERE usuario_id = ? AND data = ? ORDER BY id ASC");
        $stmt->execute([$uid, $data]);
        $registros = $stmt->fetchAll();

        // Registro aberto atual (entrada sem saída)
        $aberto = null;
        $total_segundos = 0;
        foreach($registros as $r){
            if($r['entrada'] && $r['saida']){
                $total_segundos += strtotime($r['saida']) - strtotime($r['entrada']);
            } elseif($r['entrada'] && !$r['saida']){
                $aberto = $r;
                $total_segundos += time() - strtotime($r['entrada']); // conta até agora
            }
        }

        // Para o front: ponto = registro aberto atual (ou último fechado se não há aberto)
        $ponto = $aberto ?? (count($registros) ? end($registros) : null);

        $stmt = db()->prepare("
            SELECT id, tipo, descricao, lote_id, quantidade,
                   score_base, score_ajuste, criado_em
            FROM atividades
            WHERE usuario_id = ? AND data = ? AND ativo = 1
            ORDER BY criado_em ASC
        ");
        $stmt->execute([$uid, $data]);
        $atividades = $stmt->fetchAll();

        $stmt = db()->prepare("SELECT score_total, horas_trab FROM score_diario WHERE usuario_id = ? AND data = ?");
        $stmt->execute([$uid, $data]);
        $score = $stmt->fetch();

        responder(true, [
            'data'              => $data,
            'ponto'             => $ponto,
            'tem_entrada_aberta'=> $aberto !== null,
            'total_segundos'    => $total_segundos,
            'registros_dia'     => count($registros),
            'atividades'        => $atividades,
            'score_hoje'        => $score['score_total'] ?? 0,
            'horas_hoje'        => $score['horas_trab'] ?? null,
        ]);
        break;

    // ----------------------------------------------------------
    // SINCRONIZAR FILA OFFLINE
    // Recebe: { acao: "sincronizar", registros: [...] }
    // Cada registro tem: uuid, tabela_destino, acao, payload, criado_offline
    // ----------------------------------------------------------
    case 'sincronizar':
        $registros = $body['registros'] ?? [];
        if (empty($registros) || !is_array($registros)) {
            responder(true, ['sincronizados' => 0, 'erros' => 0]);
        }

        $sync  = 0;
        $erros = 0;
        $detalhes = [];

        foreach ($registros as $reg) {
            $uuid    = $reg['uuid'] ?? '';
            $tabela  = $reg['tabela_destino'] ?? '';
            $ac      = $reg['acao'] ?? '';
            $payload = $reg['payload'] ?? [];
            $ts      = $reg['criado_offline'] ?? date('Y-m-d H:i:s');

            if (empty($uuid)) { $erros++; continue; }

            // Verifica se já foi sincronizado (deduplicação por UUID)
            $stmt = db()->prepare("SELECT id FROM fila_offline WHERE uuid = ?");
            $stmt->execute([$uuid]);
            if ($stmt->fetch()) {
                $detalhes[] = ['uuid' => $uuid, 'status' => 'duplicado'];
                continue;
            }

            // Verifica duplicação na tabela destino
            if ($tabela === 'pontos') {
                $stmt = db()->prepare("SELECT id FROM pontos WHERE offline_uuid = ?");
                $stmt->execute([$uuid]);
                if ($stmt->fetch()) {
                    $detalhes[] = ['uuid' => $uuid, 'status' => 'ja_existe'];
                    $sync++;
                    continue;
                }
            }

            // Registra na fila antes de processar (para log)
            db()->prepare("
                INSERT INTO fila_offline (usuario_id, uuid, tabela_destino, acao, payload, criado_offline, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pendente')
            ")->execute([$eu['id'], $uuid, $tabela, $ac, json_encode($payload), $ts]);
            $fila_id = db()->lastInsertId();

            // Processa conforme tipo
            try {
                $resultado = _processar_registro_offline($eu['id'], $tabela, $ac, $payload, $ts, $uuid);
                db()->prepare("UPDATE fila_offline SET status = 'sincronizado', sincronizado_em = NOW() WHERE id = ?")
                    ->execute([$fila_id]);
                $sync++;
                $detalhes[] = ['uuid' => $uuid, 'status' => 'ok', 'id' => $resultado];
            } catch (Exception $e) {
                db()->prepare("UPDATE fila_offline SET status = 'erro', erro_msg = ?, tentativas = tentativas + 1 WHERE id = ?")
                    ->execute([$e->getMessage(), $fila_id]);
                $erros++;
                $detalhes[] = ['uuid' => $uuid, 'status' => 'erro', 'msg' => $e->getMessage()];
            }
        }

        responder(true, [
            'sincronizados' => $sync,
            'erros'         => $erros,
            'detalhes'      => $detalhes,
        ]);
        break;

    default:
        responder(false, null, 'Ação desconhecida.');
}

// ============================================================
// FUNÇÕES INTERNAS
// ============================================================

function _salvar_foto(string $base64, string $nome): ?string {
    // Remove prefixo data:image/jpeg;base64, se houver
    if (str_contains($base64, ',')) {
        $base64 = explode(',', $base64)[1];
    }
    $dados = base64_decode($base64);
    if (!$dados || strlen($dados) > 5 * 1024 * 1024) return null; // max 5MB

    $dir = __DIR__ . '/../uploads/fotos/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $arquivo = $nome . '_' . time() . '.jpg';
    file_put_contents($dir . $arquivo, $dados);

    return 'uploads/fotos/' . $arquivo;
}

function _atualizar_score_diario(int $usuario_id, string $data): void {
    // Soma score do ponto + score das atividades do dia
    $stmt = db()->prepare("SELECT COALESCE(score_ponto, 0) FROM pontos WHERE usuario_id = ? AND data = ?");
    $stmt->execute([$usuario_id, $data]);
    $score_ponto = (int)$stmt->fetchColumn();

    $stmt = db()->prepare("
        SELECT COALESCE(SUM(score_base + score_ajuste), 0), COUNT(*) as n
        FROM atividades WHERE usuario_id = ? AND data = ? AND ativo = 1
    ");
    $stmt->execute([$usuario_id, $data]);
    [$score_ativ, $n_ativ] = $stmt->fetch(PDO::FETCH_NUM);

    // Horas trabalhadas
    $stmt = db()->prepare("
        SELECT TIMESTAMPDIFF(MINUTE, entrada, COALESCE(saida, NOW())) / 60.0
        FROM pontos WHERE usuario_id = ? AND data = ? AND entrada IS NOT NULL
    ");
    $stmt->execute([$usuario_id, $data]);
    $horas = $stmt->fetchColumn();

    $total = $score_ponto + (int)$score_ativ;

    db()->prepare("
        INSERT INTO score_diario (usuario_id, data, score_total, atividades_n, horas_trab)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            score_total = VALUES(score_total),
            atividades_n = VALUES(atividades_n),
            horas_trab = VALUES(horas_trab)
    ")->execute([$usuario_id, $data, $total, (int)$n_ativ, $horas ? round($horas, 2) : null]);
}

function _processar_registro_offline(int $uid, string $tabela, string $ac, array $p, string $ts, string $uuid): int {
    if ($tabela === 'pontos' && $ac === 'entrada') {
        $data = date('Y-m-d', strtotime($ts));
        $stmt = db()->prepare("SELECT id FROM pontos WHERE usuario_id = ? AND data = ?");
        $stmt->execute([$uid, $data]);
        $existe = $stmt->fetch();
        if ($existe) {
            db()->prepare("UPDATE pontos SET entrada = ?, entrada_lat = ?, entrada_lng = ?, offline_uuid = ? WHERE id = ?")
                ->execute([$ts, $p['lat'] ?? null, $p['lng'] ?? null, $uuid, $existe['id']]);
            return $existe['id'];
        }
        db()->prepare("INSERT INTO pontos (usuario_id, data, entrada, entrada_lat, entrada_lng, offline_uuid) VALUES (?,?,?,?,?,?)")
            ->execute([$uid, $data, $ts, $p['lat'] ?? null, $p['lng'] ?? null, $uuid]);
        return (int)db()->lastInsertId();
    }

    if ($tabela === 'pontos' && $ac === 'saida') {
        $data = date('Y-m-d', strtotime($ts));
        $stmt = db()->prepare("SELECT id FROM pontos WHERE usuario_id = ? AND data = ?");
        $stmt->execute([$uid, $data]);
        $existe = $stmt->fetch();
        if (!$existe) throw new Exception('Entrada não encontrada para sincronizar saída.');
        db()->prepare("UPDATE pontos SET saida = ?, saida_lat = ?, saida_lng = ? WHERE id = ?")
            ->execute([$ts, $p['lat'] ?? null, $p['lng'] ?? null, $existe['id']]);
        return $existe['id'];
    }

    if ($tabela === 'atividades') {
        $data = date('Y-m-d', strtotime($ts));
        $stmt = db()->prepare("
            INSERT INTO atividades
                (usuario_id, data, tipo, descricao, lote_id, quantidade, unidade,
                 hora_inicio, hora_fim, duracao_min, score_base, offline_uuid)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $score = _calcular_score_atividade($p['tipo'] ?? 'outro', $p['quantidade'] ?? null);
        $stmt->execute([
            $uid,
            $data,
            $p['tipo'] ?? 'outro',
            $p['descricao'] ?? null,
            $p['lote_id'] ?? null,
            $p['quantidade'] ?? null,
            $p['unidade'] ?? null,
            $p['hora_inicio'] ?? null,
            $p['hora_fim'] ?? null,
            $p['duracao_min'] ?? null,
            $score,
            $uuid,
        ]);
        $id = (int)db()->lastInsertId();
        _atualizar_score_diario($uid, $data);
        return $id;
    }

    throw new Exception("Tabela desconhecida: $tabela");
}

function _calcular_score_atividade(string $tipo, ?int $qtd): int {
    // Score base por tipo de atividade
    $base = match($tipo) {
        'irrigacao'     => 80,
        'saquinhos'     => max(10, min(300, (int)($qtd * 5))),  // 5pts por saquinho, máx 300
        'tubetes'       => max(10, min(200, (int)($qtd * 4))),
        'semeadura'     => 120,
        'repicagem'     => 150,
        'transplante'   => 100,
        'germinacao'    => 60,
        'ronda'         => 40,
        'fitossanitario'=> 90,
        'expedicao'     => 100,
        default         => 20,
    };
    return $base;
}
