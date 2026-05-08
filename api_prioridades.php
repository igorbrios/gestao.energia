<?php
// ============================================================
// api_prioridades.php  (v2)
// O que faz: Tarefas do dia, alertas automáticos (inclui
//            visitas pendentes e modo atenção dos lotes),
//            anotações do gestor, avaliações, painel da equipe
//            com visitas para checar.
// Depende de: config.php, db_prioridades.sql, db_lotes.sql (v2)
// Usado por: app_ponto.html, app_gestor.html (v2)
// Versão: 2.0 · Mai/2026
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
    // PAINEL DO DIA (funcionário)
    // ----------------------------------------------------------
    case 'meu_dia':
        $uid  = (int)$eu['id'];
        $hoje = date('Y-m-d');

        $stmt = db()->prepare("
            SELECT t.*, u.apelido AS criado_por_nome
            FROM tarefas t
            JOIN usuarios u ON u.id = t.criado_por
            WHERE t.ativo = 1
              AND t.status IN ('pendente','em_andamento')
              AND (t.atribuido_a = ? OR t.atribuido_a IS NULL)
              AND t.nivel_minimo <= ?
              AND (t.data_limite IS NULL OR t.data_limite >= ?)
            ORDER BY FIELD(t.prioridade,'urgente','alta','normal','baixa'), t.hora_limite ASC
        ");
        $stmt->execute([$uid, $eu['nivel'], $hoje]);
        $tarefas = $stmt->fetchAll();

        $stmt = db()->prepare("
            SELECT * FROM anotacoes_gestor
            WHERE sobre_usuario = ? AND visivel_func = 1 AND ativo = 1
            ORDER BY criado_em DESC LIMIT 5
        ");
        $stmt->execute([$uid]);
        $sugestoes = $stmt->fetchAll();

        if (!empty($sugestoes)) {
            db()->prepare("
                UPDATE anotacoes_gestor
                SET lida_func = 1, lida_em = NOW()
                WHERE sobre_usuario = ? AND visivel_func = 1 AND lida_func = 0
            ")->execute([$uid]);
        }

        $stmt = db()->prepare("
            SELECT COALESCE(score_total,0), COALESCE(atividades_n,0), horas_trab
            FROM score_diario WHERE usuario_id = ? AND data = ?
        ");
        $stmt->execute([$uid, $hoje]);
        $score = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0, null];

        // Visitas que este funcionário fez hoje (para ele ver o status)
        $stmt = db()->prepare("
            SELECT v.id, v.estado_geral, v.checado_por, l.codigo AS lote_codigo,
                   e.nome_popular AS especie_nome,
                   (SELECT COUNT(*) FROM visita_acoes va WHERE va.visita_id=v.id) AS total_acoes
            FROM visitas_lote v
            JOIN lotes l ON l.id = v.lote_id
            JOIN especies e ON e.id = l.especie_id
            WHERE v.registrado_por = ? AND DATE(v.criado_em) = ?
            ORDER BY v.criado_em DESC
        ");
        $stmt->execute([$uid, $hoje]);
        $visitas_hoje = $stmt->fetchAll();

        responder(true, [
            'tarefas'      => $tarefas,
            'sugestoes'    => $sugestoes,
            'score_hoje'   => (int)$score[0],
            'ativ_hoje'    => (int)$score[1],
            'horas_hoje'   => $score[2],
            'visitas_hoje' => $visitas_hoje,
        ]);
        break;

    // ----------------------------------------------------------
    // CRIAR TAREFA (nível 4+)
    // ----------------------------------------------------------
    case 'criar_tarefa':
        exigir_nivel($eu, 4);

        $titulo     = trim($body['titulo'] ?? '');
        $descricao  = trim($body['descricao'] ?? '') ?: null;
        $prioridade = $body['prioridade'] ?? 'normal';
        $atribuido  = isset($body['atribuido_a']) ? (int)$body['atribuido_a'] : null;
        $nivel_min  = (int)($body['nivel_minimo'] ?? 1);
        $lote_id    = isset($body['lote_id']) ? (int)$body['lote_id'] : null;
        $data_lim   = $body['data_limite'] ?? null;
        $hora_lim   = $body['hora_limite'] ?? null;
        $recorrente = (int)($body['recorrente'] ?? 0);

        if (empty($titulo)) responder(false, null, 'Título é obrigatório.');
        if (!in_array($prioridade, ['urgente','alta','normal','baixa'])) responder(false, null, 'Prioridade inválida.');

        db()->prepare("
            INSERT INTO tarefas
                (titulo, descricao, prioridade, atribuido_a, nivel_minimo, lote_id,
                 data_limite, hora_limite, recorrente, criado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([$titulo,$descricao,$prioridade,$atribuido,$nivel_min,$lote_id,$data_lim,$hora_lim,$recorrente,$eu['id']]);

        responder(true, ['id' => (int)db()->lastInsertId(), 'mensagem' => 'Tarefa criada.']);
        break;

    // ----------------------------------------------------------
    // CONCLUIR TAREFA
    // ----------------------------------------------------------
    case 'concluir_tarefa':
        $id  = (int)($body['id'] ?? 0);
        $obs = trim($body['obs'] ?? '') ?: null;

        $stmt = db()->prepare("SELECT * FROM tarefas WHERE id = ? AND ativo = 1");
        $stmt->execute([$id]);
        $t = $stmt->fetch();
        if (!$t) responder(false, null, 'Tarefa não encontrada.');

        if ($t['atribuido_a'] && $t['atribuido_a'] != $eu['id'] && (int)$eu['nivel'] < 4)
            responder(false, null, 'Esta tarefa não é sua.');

        db()->prepare("
            UPDATE tarefas SET status='concluida', concluida_por=?, concluida_em=NOW(), obs_conclusao=?
            WHERE id=?
        ")->execute([$eu['id'], $obs, $id]);

        $pts = ['urgente'=>120,'alta'=>80,'normal'=>50,'baixa'=>30][$t['prioridade']] ?? 50;

        db()->prepare("
            INSERT INTO atividades (usuario_id, data, tipo, descricao, score_base)
            VALUES (?, CURDATE(), 'outro', ?, ?)
        ")->execute([$eu['id'], "Tarefa concluída: {$t['titulo']}", $pts]);

        _recalc_score_dia((int)$eu['id'], date('Y-m-d'));

        responder(true, ['mensagem' => "Tarefa concluída. +$pts pts.", 'pontos' => $pts]);
        break;

    // ----------------------------------------------------------
    // LISTAR TAREFAS
    // ----------------------------------------------------------
    case 'listar_tarefas':
        exigir_nivel($eu, 3);

        $status  = $body['status'] ?? 'pendente';
        $uid     = isset($body['usuario_id']) ? (int)$body['usuario_id'] : null;
        $data    = $body['data'] ?? date('Y-m-d');

        $where  = ['t.ativo = 1', 't.status = ?'];
        $params = [$status];

        if ($uid) { $where[] = '(t.atribuido_a=? OR t.atribuido_a IS NULL)'; $params[] = $uid; }
        if ($data) { $where[] = '(t.data_limite IS NULL OR t.data_limite=?)'; $params[] = $data; }

        $stmt = db()->prepare("
            SELECT t.*,
                   ua.apelido AS atribuido_nome,
                   uc.apelido AS criado_por_nome,
                   un.apelido AS concluida_por_nome,
                   l.codigo   AS lote_codigo
            FROM tarefas t
            LEFT JOIN usuarios ua ON ua.id = t.atribuido_a
            LEFT JOIN usuarios uc ON uc.id = t.criado_por
            LEFT JOIN usuarios un ON un.id = t.concluida_por
            LEFT JOIN lotes l    ON l.id   = t.lote_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY FIELD(t.prioridade,'urgente','alta','normal','baixa'), t.hora_limite ASC
        ");
        $stmt->execute($params);

        responder(true, ['tarefas' => $stmt->fetchAll()]);
        break;

    // ----------------------------------------------------------
    // GERAR ALERTAS AUTOMÁTICOS  (v2)
    // Agora inclui: lotes em modo atenção, visitas sem check,
    // visitas com problema, além dos alertas originais.
    // ----------------------------------------------------------
    case 'gerar_alertas':
        exigir_nivel($eu, 3);
        $gerados = _gerar_alertas_automaticos();
        responder(true, ['gerados' => $gerados]);
        break;

    // ----------------------------------------------------------
    // LISTAR ALERTAS
    // ----------------------------------------------------------
    case 'listar_alertas':
        exigir_nivel($eu, 3);

        $stmt = db()->query("
            SELECT a.*,
                   l.codigo   AS lote_codigo,
                   u.apelido  AS usuario_nome
            FROM alertas_sistema a
            LEFT JOIN lotes    l ON l.id = a.lote_id
            LEFT JOIN usuarios u ON u.id = a.usuario_id
            WHERE a.ativo = 1
            ORDER BY a.lido ASC, a.criado_em DESC
            LIMIT 40
        ");

        responder(true, [
            'alertas'   => $stmt->fetchAll(),
            'nao_lidos' => _contar_alertas_nao_lidos(),
        ]);
        break;

    // ----------------------------------------------------------
    // MARCAR ALERTA COMO LIDO
    // ----------------------------------------------------------
    case 'ler_alerta':
        exigir_nivel($eu, 3);
        $id = (int)($body['id'] ?? 0);
        db()->prepare("UPDATE alertas_sistema SET lido=1, lido_por=?, lido_em=NOW() WHERE id=?")
            ->execute([$eu['id'], $id]);
        responder(true, ['nao_lidos' => _contar_alertas_nao_lidos()]);
        break;

    // ----------------------------------------------------------
    // PAINEL DA EQUIPE  (v2)
    // Agora inclui: visitas pendentes de check, lotes em atenção,
    // contador de visitas do dia por funcionário.
    // ----------------------------------------------------------
    case 'painel_equipe':
        exigir_nivel($eu, 4);

        $stmt = db()->query("
            SELECT
                u.id, u.nome, u.apelido, u.nivel, u.avatar_emoji,
                p.entrada, p.saida, p.registrado_por AS ponto_manual,
                sd.score_total, sd.atividades_n, sd.horas_trab,
                -- visitas hoje
                (SELECT COUNT(*) FROM visitas_lote v
                 WHERE v.registrado_por = u.id AND DATE(v.criado_em)=CURDATE()) AS visitas_hoje,
                -- visitas pendentes de check
                (SELECT COUNT(*) FROM visitas_lote v
                 WHERE v.registrado_por = u.id AND v.checado_por IS NULL) AS visitas_sem_check,
                -- saquinhos hoje: quantidade e minutos totais
                (SELECT COALESCE(SUM(a.quantidade),0) FROM atividades a
                 WHERE a.usuario_id=u.id AND a.tipo='saquinhos' AND a.data=CURDATE()) AS saquinhos_qtd,
                (SELECT COALESCE(SUM(a.duracao_min),0) FROM atividades a
                 WHERE a.usuario_id=u.id AND a.tipo='saquinhos' AND a.data=CURDATE()) AS saquinhos_min,
                -- irrigação hoje: minutos totais
                (SELECT COALESCE(SUM(a.quantidade),0) FROM atividades a
                 WHERE a.usuario_id=u.id AND a.tipo='irrigacao' AND a.data=CURDATE()
                   AND a.descricao LIKE '%automática%') AS irrigacao_min
            FROM usuarios u
            LEFT JOIN pontos p ON p.usuario_id = u.id AND p.data = CURDATE() AND p.saida IS NULL
            LEFT JOIN score_diario sd ON sd.usuario_id = u.id AND sd.data = CURDATE()
            WHERE u.ativo = 1 AND u.nivel < 5
            ORDER BY u.nivel DESC, u.nome ASC
        ");
        $funcionarios = $stmt->fetchAll();

        foreach ($funcionarios as &$f) {
            // Progressão por espécie
            $stmt2 = db()->prepare("
                SELECT e.nome_popular, e.dificuldade, e.codigo,
                       p_rep.tipo AS perm_repicagem,
                       p_sem.tipo AS perm_semeadura,
                       p_tra.tipo AS perm_transplante
                FROM especies e
                LEFT JOIN permissoes p_rep ON p_rep.usuario_id=? AND p_rep.etapa='repicagem'  AND p_rep.especie_id=e.id AND p_rep.ativo=1
                LEFT JOIN permissoes p_sem ON p_sem.usuario_id=? AND p_sem.etapa='semeadura'  AND p_sem.especie_id=e.id AND p_sem.ativo=1
                LEFT JOIN permissoes p_tra ON p_tra.usuario_id=? AND p_tra.etapa='transplante' AND p_tra.especie_id=e.id AND p_tra.ativo=1
                WHERE e.ativa = 1 LIMIT 6
            ");
            $stmt2->execute([$f['id'],$f['id'],$f['id']]);
            $f['progressao_especies'] = $stmt2->fetchAll();

            // Alertas internos
            $stmt3 = db()->prepare("
                SELECT id, titulo, nivel, criado_em FROM alertas_sistema
                WHERE usuario_id=? AND lido=0 AND ativo=1
                ORDER BY criado_em DESC LIMIT 3
            ");
            $stmt3->execute([$f['id']]);
            $f['alertas_internos'] = $stmt3->fetchAll();

            // Últimas visitas deste funcionário (3)
            $stmt4 = db()->prepare("
                SELECT v.id, v.estado_geral, v.checado_por, v.criado_em,
                       l.codigo AS lote_codigo, e.nome_popular AS especie_nome,
                       (SELECT COUNT(*) FROM visita_acoes va WHERE va.visita_id=v.id AND va.checado=0) AS acoes_pendentes
                FROM visitas_lote v
                JOIN lotes l ON l.id = v.lote_id
                JOIN especies e ON e.id = l.especie_id
                WHERE v.registrado_por = ?
                ORDER BY v.criado_em DESC LIMIT 3
            ");
            $stmt4->execute([$f['id']]);
            $f['ultimas_visitas'] = $stmt4->fetchAll();
        }

        // Visitas pendentes de check (todas — visão geral do gestor)
        $stmt = db()->query("
            SELECT v.id, v.estado_geral, v.observacao, v.sugere_atencao, v.criado_em,
                   l.id AS lote_id, l.codigo AS lote_codigo,
                   COALESCE(l.nome_display, e.nome_popular, 'Lote vazio') AS lote_nome,
                   e.nome_popular AS especie_nome,
                   l.corredor, l.grade_linha, l.grade_col,
                   u.apelido AS registrado_por_nome, u.avatar_emoji,
                   (SELECT COUNT(*) FROM visita_acoes va WHERE va.visita_id=v.id) AS total_acoes,
                   (SELECT COUNT(*) FROM visita_acoes va WHERE va.visita_id=v.id AND va.checado=0) AS acoes_pendentes
            FROM visitas_lote v
            JOIN lotes l     ON l.id = v.lote_id
            LEFT JOIN especies e ON e.id = l.especie_id
            JOIN usuarios u  ON u.id = v.registrado_por
            WHERE v.checado_por IS NULL
            ORDER BY v.estado_geral = 'problema' DESC,
                     v.sugere_atencao DESC,
                     v.criado_em DESC
            LIMIT 20
        ");
        $visitas_pendentes = $stmt->fetchAll();

        // Lotes em modo atenção
        $stmt = db()->query("
            SELECT l.id, l.codigo, l.modo_atencao, l.modo_atencao_obs,
                   e.nome_popular AS especie_nome,
                   ev.nome AS espaco_nome
            FROM lotes l
            JOIN especies e ON e.id = l.especie_id
            LEFT JOIN espacos_viveiro ev ON ev.id = l.espaco_id
            WHERE l.ativo=1 AND l.modo_atencao != 'nenhum'
              AND l.status NOT IN ('expedido','arquivado')
            ORDER BY l.modo_atencao = 'historico_ocorrencia' DESC, l.atualizado_em DESC
        ");
        $lotes_atencao = $stmt->fetchAll();

        // Score da equipe no mês
        $stmt = db()->query("
            SELECT COALESCE(SUM(score_total),0) AS score_equipe
            FROM score_diario
            WHERE data >= DATE_FORMAT(CURDATE(),'%Y-%m-01')
        ");
        $score_equipe = (int)$stmt->fetchColumn();

        responder(true, [
            'funcionarios'      => $funcionarios,
            'visitas_pendentes' => $visitas_pendentes,
            'lotes_atencao'     => $lotes_atencao,
            'score_equipe'      => $score_equipe,
        ]);
        break;

    // ----------------------------------------------------------
    // CHECAR VISITA direto do painel (atalho)
    // ----------------------------------------------------------
    case 'checar_visita':
        exigir_nivel($eu, 4);

        $visita_id  = (int)($body['visita_id'] ?? 0);
        $obs_gestor = trim($body['obs_gestor'] ?? '') ?: null;
        $encerrar_atencao = (int)($body['encerrar_atencao'] ?? 0);

        if (!$visita_id) responder(false, null, 'ID inválido.');

        // Busca lote vinculado
        $stmt = db()->prepare("SELECT lote_id FROM visitas_lote WHERE id=?");
        $stmt->execute([$visita_id]);
        $lote_id = (int)$stmt->fetchColumn();

        db()->prepare("
            UPDATE visitas_lote SET checado_por=?, checado_em=NOW(), obs_gestor=? WHERE id=?
        ")->execute([$eu['id'], $obs_gestor, $visita_id]);

        db()->prepare("
            UPDATE visita_acoes SET checado=1, checado_por=?, checado_em=NOW() WHERE visita_id=?
        ")->execute([$eu['id'], $visita_id]);

        // Encerrar modo atenção se gestor decidiu
        if ($encerrar_atencao && $lote_id) {
            db()->prepare("UPDATE lotes SET modo_atencao='nenhum', modo_atencao_obs=NULL WHERE id=?")
                ->execute([$lote_id]);
        }

        responder(true, ['mensagem' => 'Visita checada.']);
        break;

    // ----------------------------------------------------------
    // ADICIONAR ANOTAÇÃO DO GESTOR (diário)
    // ----------------------------------------------------------
    case 'adicionar_anotacao':
        exigir_nivel($eu, 4);

        $sobre     = (int)($body['sobre_usuario'] ?? 0);
        $texto     = trim($body['texto'] ?? '');
        $tipo      = $body['tipo'] ?? 'obs';          // obs | func | estrutura | custo
        $valor     = isset($body['valor']) ? (float)$body['valor'] : null;
        $visivel   = (int)($body['visivel_func'] ?? 0);

        if (empty($texto)) responder(false, null, 'Texto é obrigatório.');

        // Verifica se anotacoes_gestor tem coluna categoria e valor; tenta INSERT
        try {
            db()->prepare("
                INSERT INTO anotacoes_gestor (sobre_usuario, texto, tipo, visivel_func, criado_por)
                VALUES (?,?,?,?,?)
            ")->execute([$sobre ?: null, $texto, $tipo, $visivel, $eu['id']]);
        } catch (\PDOException $e) {
            // Coluna pode não existir ainda — INSERT sem tipo
            db()->prepare("
                INSERT INTO anotacoes_gestor (sobre_usuario, texto, visivel_func, criado_por)
                VALUES (?,?,?,?)
            ")->execute([$sobre ?: null, $texto, $visivel, $eu['id']]);
        }

        responder(true, ['mensagem' => 'Anotação salva.']);
        break;

    // ----------------------------------------------------------
    // DIÁRIO DO DIA — tudo que aconteceu hoje (gestor)
    // ----------------------------------------------------------
    case 'diario_hoje':
        exigir_nivel($eu, 4);

        $data = $body['data'] ?? date('Y-m-d');

        // 1. Anotações manuais do gestor
        $stmt = db()->prepare("
            SELECT ag.id, ag.texto, ag.tipo AS categoria, ag.criado_em,
                   TIME(ag.criado_em) AS hora,
                   u.apelido AS autor_nome
            FROM anotacoes_gestor ag
            LEFT JOIN usuarios u ON u.id = ag.criado_por
            WHERE DATE(ag.criado_em) = ?
            ORDER BY ag.criado_em DESC
        ");
        $stmt->execute([$data]);
        $anotacoes = $stmt->fetchAll();

        // 2. Eventos automáticos do dia (atividades relevantes)
        $stmt = db()->prepare("
            SELECT a.tipo, a.descricao, a.quantidade, a.criado_em,
                   TIME(a.criado_em) AS hora,
                   u.apelido AS autor_nome,
                   'auto' AS categoria
            FROM atividades a
            JOIN usuarios u ON u.id = a.usuario_id
            WHERE a.data = ? AND a.tipo IN ('irrigacao','saquinhos','expedicao')
            ORDER BY a.criado_em DESC
            LIMIT 20
        ");
        $stmt->execute([$data]);
        $auto = $stmt->fetchAll();
        foreach ($auto as &$a) {
            $a['texto'] = ucfirst($a['tipo']) . ': ' . ($a['descricao'] ?: '') .
                ($a['quantidade'] ? " — {$a['quantidade']} un." : '');
        }

        // Junta e ordena por hora
        $entradas = array_merge($anotacoes, $auto);
        usort($entradas, fn($a,$b) => strcmp($b['criado_em'], $a['criado_em']));

        responder(true, ['entradas' => $entradas, 'data' => $data]);
        break;

    // ----------------------------------------------------------
    // ANOTAÇÕES DE UM FUNCIONÁRIO
    // ----------------------------------------------------------
    case 'anotacoes_funcionario':
        exigir_nivel($eu, 4);

        $uid = (int)($body['usuario_id'] ?? 0);
        if (!$uid) responder(false, null, 'ID inválido.');

        $stmt = db()->prepare("
            SELECT a.*, u.apelido AS criado_por_nome
            FROM anotacoes_gestor a
            JOIN usuarios u ON u.id = a.criado_por
            WHERE a.sobre_usuario=? AND a.ativo=1
            ORDER BY a.criado_em DESC LIMIT 20
        ");
        $stmt->execute([$uid]);

        responder(true, ['anotacoes' => $stmt->fetchAll()]);
        break;

    // ----------------------------------------------------------
    // SALVAR AVALIAÇÃO
    // ----------------------------------------------------------
    case 'salvar_avaliacao':
        exigir_nivel($eu, 4);

        $uid      = (int)($body['usuario_id'] ?? 0);
        $nota     = (int)($body['nota'] ?? 0);
        $dimensao = $body['dimensao'] ?? 'geral';
        $obs      = trim($body['obs'] ?? '') ?: null;
        $ativ_id  = isset($body['atividade_id']) ? (int)$body['atividade_id'] : null;
        $per_ini  = $body['periodo_inicio'] ?? date('Y-m-d');
        $per_fim  = $body['periodo_fim'] ?? null;

        if (!$uid) responder(false, null, 'Usuário é obrigatório.');
        if ($nota < 1 || $nota > 6) responder(false, null, 'Nota deve ser entre 1 e 6.');

        db()->prepare("
            INSERT INTO avaliacoes (usuario_id, periodo_inicio, periodo_fim, nota, dimensao, obs, atividade_id, avaliado_por)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([$uid,$per_ini,$per_fim,$nota,$dimensao,$obs,$ativ_id,$eu['id']]);

        if ($ativ_id) {
            $mult  = [1=>-0.6,2=>-0.4,3=>-0.2,4=>0,5=>0.2,6=>0.5];
            $stmt2 = db()->prepare("SELECT score_base FROM atividades WHERE id=?");
            $stmt2->execute([$ativ_id]);
            $sb = (int)$stmt2->fetchColumn();
            $ajuste = (int)round($sb * ($mult[$nota] ?? 0));
            db()->prepare("UPDATE atividades SET nota_gestor=?, obs_gestor=?, score_ajuste=? WHERE id=?")
                ->execute([$nota,$obs,$ajuste,$ativ_id]);
        }

        responder(true, ['mensagem' => 'Avaliação salva.']);
        break;

    // ----------------------------------------------------------
    // HISTÓRICO DE AVALIAÇÕES
    // ----------------------------------------------------------
    case 'historico_avaliacoes':
        exigir_nivel($eu, 4);

        $uid = (int)($body['usuario_id'] ?? 0);
        $stmt = db()->prepare("
            SELECT av.*, u.apelido AS avaliador_nome
            FROM avaliacoes av
            JOIN usuarios u ON u.id = av.avaliado_por
            WHERE av.usuario_id=? AND av.ativo=1
            ORDER BY av.criado_em DESC LIMIT 20
        ");
        $stmt->execute([$uid]);

        responder(true, ['avaliacoes' => $stmt->fetchAll()]);
        break;

    default:
        responder(false, null, 'Ação desconhecida.');
}

// ============================================================
// FUNÇÕES INTERNAS
// ============================================================

function _gerar_alertas_automaticos(): int {
    $gerados = 0;

    // Limpa alertas do sistema mais antigos que 24h
    db()->exec("DELETE FROM alertas_sistema WHERE criado_em < DATE_SUB(NOW(), INTERVAL 24 HOUR) AND ativo=1");

    // 1. Lotes urgentes
    $stmt = db()->query("SELECT l.id, l.codigo, e.nome_popular FROM lotes l JOIN especies e ON e.id=l.especie_id WHERE l.status='urgente' AND l.ativo=1");
    foreach ($stmt->fetchAll() as $lt) {
        _inserir_alerta('urgente', "⚠️ Lote urgente: {$lt['codigo']}",
            "{$lt['codigo']} ({$lt['nome_popular']}) está marcado como urgente.", 'al', (int)$lt['id'], null);
        $gerados++;
    }

    // 2. Sem água
    $stmt = db()->query("SELECT l.id, l.codigo FROM lotes l WHERE l.status='aguardando_agua' AND l.ativo=1");
    foreach ($stmt->fetchAll() as $lt) {
        _inserir_alerta('sem_agua', "💧 Sem irrigação: {$lt['codigo']}",
            "O lote {$lt['codigo']} está aguardando irrigação.", 'az', (int)$lt['id'], null);
        $gerados++;
    }

    // 3. Rustificação completa
    $stmt = db()->query("
        SELECT l.id, l.codigo, DATEDIFF(CURDATE(), r.data_inicio) AS dias, e.rust_dias_min
        FROM rustificacao r
        JOIN lotes l ON l.id=r.lote_id
        JOIN especies e ON e.id=l.especie_id
        WHERE r.data_fim IS NULL AND l.status='rustificando'
    ");
    foreach ($stmt->fetchAll() as $lt) {
        if ((int)$lt['dias'] >= (int)$lt['rust_dias_min']) {
            _inserir_alerta('rust_completa', "☀️ Rustificação concluída: {$lt['codigo']}",
                "Lote {$lt['codigo']} completou {$lt['dias']} dias. Pronto para expedir.", 'am', (int)$lt['id'], null);
            $gerados++;
        }
    }

    // 4. Funcionários sem ponto após 9h
    if (date('H') >= 9) {
        $stmt = db()->query("
            SELECT u.id, u.apelido FROM usuarios u
            LEFT JOIN pontos p ON p.usuario_id=u.id AND p.data=CURDATE()
            WHERE u.ativo=1 AND u.nivel<5 AND p.id IS NULL
        ");
        foreach ($stmt->fetchAll() as $u) {
            _inserir_alerta('sem_ponto', "⏰ Sem ponto: {$u['apelido']}",
                "{$u['apelido']} ainda não registrou entrada hoje.", 'am', null, (int)$u['id']);
            $gerados++;
        }
    }

    // 5. Lotes em modo atenção (novo v2)
    $stmt = db()->query("
        SELECT l.id, l.codigo, l.modo_atencao, e.nome_popular
        FROM lotes l JOIN especies e ON e.id=l.especie_id
        WHERE l.ativo=1 AND l.modo_atencao!='nenhum'
          AND l.status NOT IN ('expedido','arquivado')
    ");
    foreach ($stmt->fetchAll() as $lt) {
        $tipos = [
            'historico_ocorrencia' => ['🦟 Ocorrência anterior','Lote com histórico de problema — verificar se recorreu.','al'],
            'especie_sensivel'     => ['⚡ Espécie sensível',    'Espécie em fase sensível — monitorar de perto.',            'am'],
            'zona_inadequada'      => ['☀️ Zona inadequada',     'Lote em zona de insolação — verificar tolerância.',          'am'],
            'gestor_definiu'       => ['👁️ Em observação',       'Lote marcado para acompanhamento ativo.',                   'am'],
        ];
        if (isset($tipos[$lt['modo_atencao']])) {
            [$titulo_base, $msg, $nivel] = $tipos[$lt['modo_atencao']];
            _inserir_alerta($lt['modo_atencao'], "$titulo_base: {$lt['codigo']}",
                "{$lt['codigo']} ({$lt['nome_popular']}) — $msg", $nivel, (int)$lt['id'], null);
            $gerados++;
        }
    }

    // 6. Visitas com problema sem check (novo v2)
    $stmt = db()->query("
        SELECT v.id, l.codigo, u.apelido AS func_nome
        FROM visitas_lote v
        JOIN lotes l ON l.id=v.lote_id
        JOIN usuarios u ON u.id=v.registrado_por
        WHERE v.checado_por IS NULL AND v.estado_geral IN ('problema','atencao')
          AND v.criado_em >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
    ");
    foreach ($stmt->fetchAll() as $v) {
        _inserir_alerta('visita_sem_check',
            "📋 Visita sem check: {$v['codigo']}",
            "{$v['func_nome']} registrou problema no lote {$v['codigo']}. Aguardando sua revisão.",
            'al', null, null);
        $gerados++;
    }

    return $gerados;
}

function _inserir_alerta(string $tipo, string $titulo, string $msg, string $nivel, ?int $lote_id, ?int $uid): void {
    $stmt = db()->prepare("
        SELECT id FROM alertas_sistema
        WHERE tipo=? AND DATE(criado_em)=CURDATE()
        AND (lote_id=? OR (lote_id IS NULL AND ? IS NULL))
        AND (usuario_id=? OR (usuario_id IS NULL AND ? IS NULL))
        LIMIT 1
    ");
    $stmt->execute([$tipo,$lote_id,$lote_id,$uid,$uid]);
    if ($stmt->fetch()) return;

    db()->prepare("
        INSERT INTO alertas_sistema (tipo, titulo, mensagem, nivel, lote_id, usuario_id)
        VALUES (?,?,?,?,?,?)
    ")->execute([$tipo,$titulo,$msg,$nivel,$lote_id,$uid]);
}

function _contar_alertas_nao_lidos(): int {
    return (int)db()->query("SELECT COUNT(*) FROM alertas_sistema WHERE lido=0 AND ativo=1")->fetchColumn();
}

function _recalc_score_dia(int $uid, string $data): void {
    $s1 = db()->prepare("SELECT COALESCE(score_ponto,0) FROM pontos WHERE usuario_id=? AND data=?");
    $s1->execute([$uid,$data]);
    $sp = (int)$s1->fetchColumn();

    $s2 = db()->prepare("SELECT COALESCE(SUM(score_base+score_ajuste),0),COUNT(*) FROM atividades WHERE usuario_id=? AND data=? AND ativo=1");
    $s2->execute([$uid,$data]);
    [$sa,$n] = $s2->fetch(PDO::FETCH_NUM);

    $s3 = db()->prepare("SELECT TIMESTAMPDIFF(MINUTE,entrada,COALESCE(saida,NOW()))/60.0 FROM pontos WHERE usuario_id=? AND data=? AND entrada IS NOT NULL");
    $s3->execute([$uid,$data]);
    $h = $s3->fetchColumn();

    db()->prepare("
        INSERT INTO score_diario (usuario_id,data,score_total,atividades_n,horas_trab)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE score_total=VALUES(score_total), atividades_n=VALUES(atividades_n), horas_trab=VALUES(horas_trab)
    ")->execute([$uid,$data,$sp+(int)$sa,(int)$n,$h?round($h,2):null]);
}
