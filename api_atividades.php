<?php
// ============================================================
// api_atividades.php  (v3)
// NOVO: atividades persistem no banco e são buscadas ao abrir
//       o app. Gestor avalia (nota 1-6) e arquiva. Visibilidade
//       por hierarquia de nível.
// Versão: 3.0 · Mai/2026
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
    // REGISTRAR ATIVIDADE
    // Salva no banco. Aparece para o funcionário até ser arquivada.
    // ----------------------------------------------------------
    case 'registrar':
        $tipo      = trim($body['tipo'] ?? '');
        $descricao = trim($body['descricao'] ?? '') ?: null;
        $quantidade= isset($body['quantidade']) ? (int)$body['quantidade'] : null;
        $lote_id   = isset($body['lote_id']) ? (int)$body['lote_id'] : null;
        $uuid      = $body['uuid'] ?? null;

        $tipos_validos = ['irrigacao','saquinhos','tubetes','semeadura','repicagem',
                          'germinacao','ronda','fitossanitario','expedicao','outro'];
        if (!in_array($tipo, $tipos_validos)) responder(false, null, 'Tipo inválido.');

        // Score base por tipo
        $scores = ['irrigacao'=>30,'saquinhos'=>20,'tubetes'=>20,'semeadura'=>50,
                   'repicagem'=>60,'germinacao'=>20,'ronda'=>15,'fitossanitario'=>40,
                   'expedicao'=>80,'outro'=>10];
        $score = $scores[$tipo] ?? 10;

        db()->prepare("
            INSERT INTO atividades
                (usuario_id, data, tipo, descricao, quantidade,
                 score_base, nivel_criador, offline_uuid)
            VALUES (?,CURDATE(),?,?,?,?,?,?)
        ")->execute([
            $eu['id'], $tipo, $descricao, $quantidade,
            $score, $eu['nivel'], $uuid,
        ]);
        $ativ_id = (int)db()->lastInsertId();

        // Se encher tubetes ou saquinhos → SEMPRE cria lote novo (rastreabilidade por funcionário)
        if (in_array($tipo, ['tubetes','saquinhos']) && $quantidade > 0) {
            $emb        = $tipo === 'tubetes' ? 'tubete' : 'saco';
            $nome_disp  = $tipo === 'tubetes' ? 'Tubete Novo' : 'Saquinho Novo';
            $ano        = date('Y');
            $stmt = db()->prepare("SELECT COUNT(*) FROM lotes WHERE tipo_lote='vazio' AND YEAR(criado_em)=?");
            $stmt->execute([$ano]);
            $seq    = (int)$stmt->fetchColumn() + 1;
            $codigo = sprintf('VAZ-%s-%03d', $ano, $seq);

            db()->prepare("
                INSERT INTO lotes
                    (codigo, tipo_lote, nome_display, especie_id, origem,
                     enchido_por, qtd_inicial, qtd_atual,
                     fase_atual, embalagem_atual, status, criado_por)
                VALUES (?,?,?,NULL,'semente',?,?,?,?,?,?,?)
            ")->execute([
                $codigo, 'vazio', $nome_disp,
                $eu['id'], $quantidade, $quantidade,
                'vazio', $emb, 'vazio', $eu['id'],
            ]);
            $lote_id_vazio = (int)db()->lastInsertId();

            // Vincula atividade ao lote vazio
            db()->prepare("UPDATE atividades SET lote_id=? WHERE id=?")
                ->execute([$lote_id_vazio, $ativ_id]);
        } elseif ($lote_id) {
            db()->prepare("UPDATE atividades SET lote_id=? WHERE id=?")
                ->execute([$lote_id, $ativ_id]);
        }

        _recalc_score_dia((int)$eu['id'], date('Y-m-d'));

        responder(true, [
            'id'      => $ativ_id,
            'score'   => $score,
            'mensagem'=> 'Atividade registrada.',
        ]);
        break;

    // ----------------------------------------------------------
    // LISTAR ATIVIDADES DO USUÁRIO LOGADO
    // Só mostra não arquivadas. Persiste entre sessões.
    // ----------------------------------------------------------
    case 'minhas':
        $data  = $body['data'] ?? date('Y-m-d');
        $todas = (int)($body['todas'] ?? 0); // 1 = todas as datas

        $where  = ['a.usuario_id = ?', 'a.ativo = 1', 'a.arquivada = 0'];
        $params = [$eu['id']];

        if (!$todas) {
            $where[]  = 'a.data = ?';
            $params[] = $data;
        }

        $stmt = db()->prepare("
            SELECT a.*,
                   l.codigo AS lote_codigo,
                   e.nome_popular AS especie_nome,
                   av.nota AS nota_gestor,
                   av.obs  AS obs_gestor_aval
            FROM atividades a
            LEFT JOIN lotes l ON l.id = a.lote_id
            LEFT JOIN especies e ON e.id = l.especie_id
            LEFT JOIN avaliacoes_atividade av ON av.atividade_id = a.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.criado_em DESC
        ");
        $stmt->execute($params);
        $atividades = $stmt->fetchAll();

        // Score do dia
        $stmt = db()->prepare("
            SELECT COALESCE(score_total,0), COALESCE(atividades_n,0), horas_trab
            FROM score_diario WHERE usuario_id=? AND data=?
        ");
        $stmt->execute([$eu['id'], $data]);
        $score = $stmt->fetch(PDO::FETCH_NUM) ?: [0,0,null];

        responder(true, [
            'atividades'  => $atividades,
            'score_hoje'  => (int)$score[0],
            'ativ_hoje'   => (int)$score[1],
            'horas_hoje'  => $score[2],
        ]);
        break;

    // ----------------------------------------------------------
    // LISTAR ATIVIDADES DE UM USUÁRIO (gestor vê equipe)
    // Hierarquia: nível 4 vê níveis 1-3, nível 5 vê todos
    // ----------------------------------------------------------
    case 'listar_usuario':
        $uid_alvo  = (int)($body['usuario_id'] ?? 0);
        $data      = $body['data'] ?? date('Y-m-d');
        $arquivadas= (int)($body['arquivadas'] ?? 0);

        if (!$uid_alvo) responder(false, null, 'ID inválido.');

        // Verifica hierarquia — só pode ver quem está abaixo
        $stmt = db()->prepare("SELECT nivel FROM usuarios WHERE id=?");
        $stmt->execute([$uid_alvo]);
        $nivel_alvo = (int)$stmt->fetchColumn();

        if ((int)$eu['nivel'] <= $nivel_alvo && $eu['id'] != $uid_alvo) {
            responder(false, null, 'Sem permissão para ver atividades deste usuário.');
        }

        $where  = ['a.usuario_id = ?', 'a.ativo = 1'];
        $params = [$uid_alvo];

        if (!$arquivadas) {
            $where[] = 'a.arquivada = 0';
        }
        if ($data) {
            $where[]  = 'a.data = ?';
            $params[] = $data;
        }

        $stmt = db()->prepare("
            SELECT a.*,
                   l.codigo AS lote_codigo, e.nome_popular AS especie_nome,
                   av.nota AS nota_gestor, av.obs AS obs_gestor_aval,
                   u_arq.apelido AS arquivada_por_nome
            FROM atividades a
            LEFT JOIN lotes l ON l.id = a.lote_id
            LEFT JOIN especies e ON e.id = l.especie_id
            LEFT JOIN avaliacoes_atividade av ON av.atividade_id = a.id
            LEFT JOIN usuarios u_arq ON u_arq.id = a.arquivada_por
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.criado_em DESC
        ");
        $stmt->execute($params);

        responder(true, ['atividades' => $stmt->fetchAll()]);
        break;

    // ----------------------------------------------------------
    // AVALIAR E ARQUIVAR ATIVIDADE (gestor)
    // Arquivar = confirmou que viu. Nota é opcional.
    // ----------------------------------------------------------
    case 'avaliar':
        $ativ_id = (int)($body['atividade_id'] ?? 0);
        $nota    = isset($body['nota']) ? (int)$body['nota'] : null;
        $obs     = trim($body['obs'] ?? '') ?: null;
        $arquivar= (int)($body['arquivar'] ?? 1); // 1 = arquiva junto

        if (!$ativ_id) responder(false, null, 'ID inválido.');
        if ($nota !== null && ($nota < 1 || $nota > 6))
            responder(false, null, 'Nota deve ser entre 1 e 6.');

        // Verifica hierarquia
        $stmt = db()->prepare("SELECT a.usuario_id, u.nivel FROM atividades a JOIN usuarios u ON u.id=a.usuario_id WHERE a.id=?");
        $stmt->execute([$ativ_id]);
        $ativ = $stmt->fetch();
        if (!$ativ) responder(false, null, 'Atividade não encontrada.');
        if ((int)$eu['nivel'] <= (int)$ativ['nivel'] && $eu['id'] != $ativ['usuario_id'])
            responder(false, null, 'Sem permissão para avaliar esta atividade.');

        // Salva ou atualiza avaliação
        db()->prepare("
            INSERT INTO avaliacoes_atividade (atividade_id, avaliado_por, nota, obs, arquivado)
            VALUES (?,?,?,?,1)
            ON DUPLICATE KEY UPDATE nota=VALUES(nota), obs=VALUES(obs), avaliado_por=VALUES(avaliado_por)
        ")->execute([$ativ_id, $eu['id'], $nota, $obs]);

        // Ajuste de score se houver nota
        if ($nota !== null) {
            $mult  = [1=>-0.6,2=>-0.4,3=>-0.2,4=>0,5=>0.2,6=>0.5];
            $stmt2 = db()->prepare("SELECT score_base FROM atividades WHERE id=?");
            $stmt2->execute([$ativ_id]);
            $sb = (int)$stmt2->fetchColumn();
            $ajuste = (int)round($sb * ($mult[$nota] ?? 0));
            db()->prepare("UPDATE atividades SET score_ajuste=? WHERE id=?")
                ->execute([$ajuste, $ativ_id]);
        }

        // Arquivar
        if ($arquivar) {
            db()->prepare("
                UPDATE atividades SET arquivada=1, arquivada_por=?, arquivada_em=NOW() WHERE id=?
            ")->execute([$eu['id'], $ativ_id]);
        }

        // Recalcula score do funcionário
        $stmt3 = db()->prepare("SELECT usuario_id, data FROM atividades WHERE id=?");
        $stmt3->execute([$ativ_id]);
        $info = $stmt3->fetch();
        if ($info) _recalc_score_dia((int)$info['usuario_id'], $info['data']);

        responder(true, ['mensagem' => 'Atividade ' . ($arquivar ? 'avaliada e arquivada.' : 'avaliada.')]);
        break;

    // ----------------------------------------------------------
    // HISTÓRICO DO USUÁRIO (para relatório pessoal)
    // Só retorna o próprio histórico ou de quem está abaixo
    // ----------------------------------------------------------
    case 'historico':
        $uid_alvo = isset($body['usuario_id']) ? (int)$body['usuario_id'] : (int)$eu['id'];
        $mes      = $body['mes'] ?? date('Y-m');

        // Verifica hierarquia
        if ($uid_alvo !== (int)$eu['id']) {
            $stmt = db()->prepare("SELECT nivel FROM usuarios WHERE id=?");
            $stmt->execute([$uid_alvo]);
            $nivel_alvo = (int)$stmt->fetchColumn();
            if ((int)$eu['nivel'] <= $nivel_alvo)
                responder(false, null, 'Sem permissão.');
        }

        $stmt = db()->prepare("
            SELECT tipo, COUNT(*) AS vezes,
                   COALESCE(SUM(quantidade),0) AS total_qtd,
                   COALESCE(SUM(score_base+COALESCE(score_ajuste,0)),0) AS score_total,
                   AVG(av.nota) AS media_nota
            FROM atividades a
            LEFT JOIN avaliacoes_atividade av ON av.atividade_id=a.id
            WHERE a.usuario_id=? AND DATE_FORMAT(a.data,'%Y-%m')=? AND a.ativo=1
            GROUP BY tipo ORDER BY vezes DESC
        ");
        $stmt->execute([$uid_alvo, $mes]);
        $por_tipo = $stmt->fetchAll();

        // Score diário do mês (para gráfico)
        $stmt = db()->prepare("
            SELECT data, score_total, atividades_n, horas_trab
            FROM score_diario
            WHERE usuario_id=? AND DATE_FORMAT(data,'%Y-%m')=?
            ORDER BY data ASC
        ");
        $stmt->execute([$uid_alvo, $mes]);
        $score_diario = $stmt->fetchAll();

        responder(true, [
            'por_tipo'     => $por_tipo,
            'score_diario' => $score_diario,
        ]);
        break;

    // ----------------------------------------------------------
    // RELATÓRIO DE ATIVIDADES POR EQUIPE (nível 4+)
    // Cada nível vê a si e a quem está abaixo
    // ----------------------------------------------------------
    case 'relatorio_equipe':
        exigir_nivel($eu, 4);

        $mes        = $body['mes'] ?? date('Y-m');
        $nivel_max  = (int)$eu['nivel'] - 1; // vê até um nível abaixo do próprio

        $stmt = db()->prepare("
            SELECT u.id, u.apelido, u.nivel,
                   COUNT(a.id) AS total_atividades,
                   COALESCE(SUM(a.score_base+COALESCE(a.score_ajuste,0)),0) AS score_total,
                   COALESCE(SUM(a.quantidade),0) AS total_qtd,
                   AVG(av.nota) AS media_nota,
                   COUNT(CASE WHEN a.arquivada=1 THEN 1 END) AS arquivadas
            FROM usuarios u
            LEFT JOIN atividades a ON a.usuario_id=u.id
                AND DATE_FORMAT(a.data,'%Y-%m')=? AND a.ativo=1
            LEFT JOIN avaliacoes_atividade av ON av.atividade_id=a.id
            WHERE u.ativo=1 AND u.nivel <= ?
            GROUP BY u.id
            ORDER BY score_total DESC
        ");
        $stmt->execute([$mes, $nivel_max]);

        responder(true, ['equipe' => $stmt->fetchAll()]);
        break;

    // ----------------------------------------------------------
    // SYNC FILA OFFLINE
    // ----------------------------------------------------------
    case 'sync':
        $fila = $body['fila'] ?? [];
        if (empty($fila)) responder(true, ['sincronizados' => 0]);

        $ok = 0;
        foreach ($fila as $item) {
            // Verifica duplicata por uuid
            if (!empty($item['uuid'])) {
                $stmt = db()->prepare("SELECT id FROM atividades WHERE offline_uuid=?");
                $stmt->execute([$item['uuid']]);
                if ($stmt->fetch()) { $ok++; continue; }
            }

            $tipo  = $item['tipo'] ?? 'outro';
            $data  = $item['data'] ?? date('Y-m-d');
            $score = ['irrigacao'=>30,'saquinhos'=>20,'tubetes'=>20,'semeadura'=>50,
                      'repicagem'=>60,'germinacao'=>20,'ronda'=>15,'fitossanitario'=>40,
                      'expedicao'=>80,'outro'=>10][$tipo] ?? 10;

            db()->prepare("
                INSERT INTO atividades
                    (usuario_id,data,tipo,descricao,quantidade,score_base,nivel_criador,offline_uuid)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute([
                $eu['id'], $data, $tipo,
                $item['descricao'] ?? null,
                $item['quantidade'] ?? null,
                $score, $eu['nivel'],
                $item['uuid'] ?? null,
            ]);
            _recalc_score_dia((int)$eu['id'], $data);
            $ok++;
        }

        responder(true, ['sincronizados' => $ok]);
        break;

    // ----------------------------------------------------------
    // SCORE DO DIA
    // ----------------------------------------------------------
    case 'score_dia':
        $data = $body['data'] ?? date('Y-m-d');
        $stmt = db()->prepare("
            SELECT score_total, atividades_n, horas_trab
            FROM score_diario WHERE usuario_id=? AND data=?
        ");
        $stmt->execute([$eu['id'], $data]);
        $score = $stmt->fetch() ?: ['score_total'=>0,'atividades_n'=>0,'horas_trab'=>null];
        responder(true, $score);
        break;

    default:
        responder(false, null, 'Ação desconhecida.');
}

function _recalc_score_dia(int $uid, string $data): void {
    $s1 = db()->prepare("SELECT COALESCE(score_ponto,0) FROM pontos WHERE usuario_id=? AND data=?");
    $s1->execute([$uid,$data]);
    $sp = (int)$s1->fetchColumn();
    $s2 = db()->prepare("SELECT COALESCE(SUM(score_base+COALESCE(score_ajuste,0)),0),COUNT(*) FROM atividades WHERE usuario_id=? AND data=? AND ativo=1");
    $s2->execute([$uid,$data]);
    [$sa,$n] = $s2->fetch(PDO::FETCH_NUM);
    $s3 = db()->prepare("SELECT TIMESTAMPDIFF(MINUTE,entrada,COALESCE(saida,NOW()))/60.0 FROM pontos WHERE usuario_id=? AND data=? AND entrada IS NOT NULL");
    $s3->execute([$uid,$data]);
    $h = $s3->fetchColumn();
    db()->prepare("INSERT INTO score_diario (usuario_id,data,score_total,atividades_n,horas_trab)
        VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE score_total=VALUES(score_total),atividades_n=VALUES(atividades_n),horas_trab=VALUES(horas_trab)")
        ->execute([$uid,$data,$sp+(int)$sa,(int)$n,$h?round($h,2):null]);
}
