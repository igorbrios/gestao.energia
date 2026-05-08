<?php
// ============================================================
// api_lotes.php  (v4)
// NOVO: nome_display correto (Saquinho Novo / Reuso / espécie),
//       posicionamento próximo ao lote de origem,
//       herança de quem encheu e espécie de origem.
// Versão: 4.0 · Mai/2026
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
    // LISTAR LOTES
    // ----------------------------------------------------------
    case 'listar':
        $where  = ['l.ativo = 1'];
        $params = [];

        if (!empty($body['status']))    { $where[] = 'l.status = ?';       $params[] = $body['status']; }
        if (!empty($body['tipo_lote'])) { $where[] = 'l.tipo_lote = ?';    $params[] = $body['tipo_lote']; }
        if (!empty($body['especie_id'])){ $where[] = 'l.especie_id = ?';   $params[] = (int)$body['especie_id']; }
        if (!empty($body['fase_atual'])){ $where[] = 'l.fase_atual = ?';   $params[] = $body['fase_atual']; }
        if (!empty($body['espaco_id'])) { $where[] = 'l.espaco_id = ?';    $params[] = (int)$body['espaco_id']; }
        if (!empty($body['atencao']))   { $where[] = "l.modo_atencao != 'nenhum'"; }
        if (!empty($body['so_vazios'])) { $where[] = "l.tipo_lote = 'vazio'"; }

        $stmt = db()->prepare("
            SELECT l.*,
                   COALESCE(l.nome_display, e.nome_popular, 'Sem nome') AS nome_exibicao,
                   e.nome_popular, e.codigo AS especie_codigo, e.dificuldade,
                   e.rust_dias_min, e.germ_taxa_esperada,
                   ev.nome AS espaco_nome, ev.tipo AS espaco_tipo,
                   u_enc.apelido AS enchido_por_nome,
                   r.data_inicio AS rust_inicio,
                   DATEDIFF(CURDATE(), r.data_inicio) AS dias_rustificando,
                   (SELECT v.estado_geral FROM visitas_lote v
                    WHERE v.lote_id=l.id ORDER BY v.criado_em DESC LIMIT 1) AS ultima_visita_estado,
                   (SELECT COUNT(*) FROM visita_acoes va
                    WHERE va.lote_id=l.id AND va.checado=0) AS acoes_pendentes
            FROM lotes l
            LEFT JOIN especies e ON e.id = l.especie_id
            LEFT JOIN espacos_viveiro ev ON ev.id = l.espaco_id
            LEFT JOIN usuarios u_enc ON u_enc.id = l.enchido_por
            LEFT JOIN rustificacao r ON r.lote_id=l.id AND r.data_fim IS NULL
            WHERE " . implode(' AND ', $where) . "
            ORDER BY
                l.tipo_lote = 'vazio' ASC,
                l.modo_atencao != 'nenhum' DESC,
                FIELD(l.status,'urgente','aguardando_agua','pronto',
                      'rustificando','ativo','vazio','expedido','arquivado'),
                l.criado_em DESC
        ");
        $stmt->execute($params);
        $lotes = $stmt->fetchAll();

        foreach ($lotes as &$lote) {
            $lote['alertas'] = _calcular_alertas($lote);
        }

        responder(true, ['lotes' => $lotes]);
        break;

    // ----------------------------------------------------------
    // CRIAR LOTE VAZIO (nível 2+)
    // Encher tubetes ou saquinhos cria um lote vazio.
    // Posicionado próximo ao lote de origem se informado.
    // ----------------------------------------------------------
    case 'criar_vazio':
        if ((int)$eu['nivel'] < 2) responder(false, null, 'Nível mínimo 2.');

        $embalagem    = $body['embalagem'] ?? 'saco';
        $quantidade   = (int)($body['quantidade'] ?? 0);
        $espaco_id    = isset($body['espaco_id']) ? (int)$body['espaco_id'] : null;
        $lote_origem  = isset($body['lote_origem_id']) ? (int)$body['lote_origem_id'] : null;
        $tipo_origem  = $body['tipo_origem'] ?? 'novo'; // 'novo' | 'reuso'
        $obs          = trim($body['obs'] ?? '') ?: null;

        if ($quantidade <= 0 && !in_array($embalagem, ['sementeira','bandeja']))
            responder(false, null, 'Quantidade deve ser maior que zero.');
        if (!in_array($embalagem, ['tubete','saco','balde','sementeira','bandeja']))
            responder(false, null, 'Embalagem inválida.');

        // Nome de exibição
        if ($embalagem === 'sementeira') $nome_display = 'Sementeira Nova';
        elseif ($embalagem === 'bandeja') $nome_display = 'Bandeja Nova';
        elseif ($embalagem === 'tubete')  $nome_display = $tipo_origem === 'reuso' ? 'Reuso' : 'Tubete Novo';
        else $nome_display = $tipo_origem === 'reuso' ? 'Reuso' : 'Saquinho Novo';

        // Espécie de origem (herdada se vier de reuso)
        $especie_origem = null;
        $corredor_origem = null;
        $linha_origem   = null;
        $col_origem     = null;

        if ($lote_origem) {
            $stmt = db()->prepare("SELECT especie_id, corredor, grade_linha, grade_col, espaco_id FROM lotes WHERE id=?");
            $stmt->execute([$lote_origem]);
            $orig = $stmt->fetch();
            if ($orig) {
                $especie_origem  = $orig['especie_id'];
                $corredor_origem = $orig['corredor'];
                $linha_origem    = $orig['grade_linha'];
                $col_origem      = $orig['grade_col'];
                if (!$espaco_id) $espaco_id = $orig['espaco_id'];
            }
        }

        // Encontra posição mais próxima disponível no mesmo corredor
        [$corredor_novo, $linha_nova, $col_nova] = _encontrar_posicao_proxima(
            $corredor_origem, $linha_origem, $col_origem
        );

        // Código
        $ano  = date('Y');
        $stmt = db()->prepare("SELECT COUNT(*) FROM lotes WHERE tipo_lote='vazio' AND YEAR(criado_em)=?");
        $stmt->execute([$ano]);
        $seq    = (int)$stmt->fetchColumn() + 1;
        $codigo = sprintf('VAZ-%s-%03d', $ano, $seq);

        $qtd_usar = in_array($embalagem, ['sementeira','bandeja']) ? 0 : $quantidade;

        db()->prepare("
            INSERT INTO lotes
                (codigo, tipo_lote, nome_display, especie_id, espaco_id, origem,
                 lote_origem_id, enchido_por,
                 corredor, grade_linha, grade_col,
                 qtd_inicial, qtd_atual,
                 fase_atual, embalagem_atual, status,
                 obs, criado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $codigo, 'vazio', $nome_display,
            $especie_origem, $espaco_id, 'semente',
            $lote_origem, $eu['id'],
            $corredor_novo, $linha_nova, $col_nova,
            $qtd_usar, $qtd_usar,
            'vazio', $embalagem, 'vazio',
            $obs, $eu['id'],
        ]);
        $lote_id = (int)db()->lastInsertId();

        responder(true, [
            'id'         => $lote_id,
            'codigo'     => $codigo,
            'nome'       => $nome_display,
            'corredor'   => $corredor_novo,
            'linha'      => $linha_nova,
            'coluna'     => $col_nova,
            'mensagem'   => "$quantidade {$embalagem}(s) registrado(s) → lote '$nome_display' criado.",
        ]);
        break;

    // ----------------------------------------------------------
    // ASSOCIAR ESPÉCIE AO LOTE VAZIO (nível 3+)
    // ----------------------------------------------------------
    case 'associar_especie':
        if ((int)$eu['nivel'] < 3) responder(false, null, 'Nível mínimo 3.');

        $lote_id    = (int)($body['lote_id'] ?? 0);
        $especie_id = (int)($body['especie_id'] ?? 0);
        $quantidade = (int)($body['quantidade'] ?? 0);
        $espaco_id  = isset($body['espaco_id']) ? (int)$body['espaco_id'] : null;
        $sementes_por_unidade = (int)($body['sementes_por_unidade'] ?? 1);
        $data_semeadura = $body['data_semeadura'] ?? date('Y-m-d');
        $protocolo_id   = isset($body['protocolo_id']) ? (int)$body['protocolo_id'] : null;
        $obs = trim($body['obs'] ?? '') ?: null;

        if (!$lote_id || !$especie_id) responder(false, null, 'Lote e espécie são obrigatórios.');
        if ($quantidade <= 0) responder(false, null, 'Quantidade inválida.');

        $stmt = db()->prepare("SELECT * FROM lotes WHERE id=? AND tipo_lote='vazio' AND ativo=1");
        $stmt->execute([$lote_id]);
        $lote_vazio = $stmt->fetch();
        if (!$lote_vazio) responder(false, null, 'Lote vazio não encontrado.');
        if ($quantidade > $lote_vazio['qtd_atual'])
            responder(false, null, "Quantidade ({$quantidade}) maior que disponível ({$lote_vazio['qtd_atual']}).");

        $stmt = db()->prepare("SELECT codigo, nome_popular FROM especies WHERE id=?");
        $stmt->execute([$especie_id]);
        $esp = $stmt->fetch();
        if (!$esp) responder(false, null, 'Espécie não encontrada.');

        $ano = date('Y');
        $stmt = db()->prepare("SELECT COUNT(*) FROM lotes WHERE especie_id=? AND YEAR(criado_em)=?");
        $stmt->execute([$especie_id, $ano]);
        $seq = (int)$stmt->fetchColumn() + 1;
        $novo_codigo = sprintf('%s-%s-%03d', $esp['codigo'], $ano, $seq);

        db()->prepare("
            INSERT INTO lotes
                (codigo, tipo_lote, nome_display, especie_id, espaco_id, origem,
                 lote_origem_id, corredor, grade_linha, grade_col,
                 qtd_inicial, qtd_atual, sementes_por_unidade,
                 fase_atual, embalagem_atual, status,
                 data_semeadura, protocolo_id, obs, criado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $novo_codigo, 'normal', $esp['nome_popular'],
            $especie_id, $espaco_id ?? $lote_vazio['espaco_id'],
            'semente', $lote_id,
            $lote_vazio['corredor'], $lote_vazio['grade_linha'], $lote_vazio['grade_col'],
            $quantidade, $quantidade, $sementes_por_unidade,
            'semeadura', $lote_vazio['embalagem_atual'], 'ativo',
            $data_semeadura, $protocolo_id, $obs, $eu['id'],
        ]);
        $novo_lote_id = (int)db()->lastInsertId();

        _registrar_historico($novo_lote_id, null, 'semeadura', null,
            $lote_vazio['embalagem_atual'], null,
            $espaco_id ?? $lote_vazio['espaco_id'],
            $quantidade, "Plantado a partir do lote vazio {$lote_vazio['codigo']}", null, $eu['id']);

        $qtd_restante = $lote_vazio['qtd_atual'] - $quantidade;
        if ($qtd_restante <= 0) {
            db()->prepare("UPDATE lotes SET qtd_atual=0, status='arquivado', ativo=0 WHERE id=?")
                ->execute([$lote_id]);
        } else {
            db()->prepare("UPDATE lotes SET qtd_atual=? WHERE id=?")
                ->execute([$qtd_restante, $lote_id]);
        }

        responder(true, [
            'novo_lote_id'   => $novo_lote_id,
            'novo_codigo'    => $novo_codigo,
            'nome'           => $esp['nome_popular'],
            'vazio_restante' => $qtd_restante,
            'mensagem'       => "$quantidade unidades plantadas → lote '{$esp['nome_popular']}' criado.",
        ]);
        break;

    // ----------------------------------------------------------
    // CRIAR LOTE NORMAL (nível 4+)
    // ----------------------------------------------------------
    case 'criar':
        exigir_nivel($eu, 4);

        $especie_id   = (int)($body['especie_id'] ?? 0);
        $espaco_id    = (int)($body['espaco_id'] ?? 0);
        $origem       = $body['origem'] ?? 'semente';
        $qtd          = (int)($body['qtd_inicial'] ?? 0);
        $sem_unid     = (int)($body['sementes_por_unidade'] ?? 1);
        $matriz       = trim($body['matriz_origem'] ?? '') ?: null;
        $data_sem     = $body['data_semeadura'] ?? date('Y-m-d');
        $protocolo_id = isset($body['protocolo_id']) ? (int)$body['protocolo_id'] : null;
        $obs          = trim($body['obs'] ?? '') ?: null;
        $corredor     = isset($body['corredor']) ? (int)$body['corredor'] : null;
        $linha        = isset($body['grade_linha']) ? (int)$body['grade_linha'] : null;
        $col          = isset($body['grade_col']) ? (int)$body['grade_col'] : null;

        if (!$especie_id) responder(false, null, 'Espécie é obrigatória.');
        if ($qtd <= 0)    responder(false, null, 'Quantidade inválida.');

        $stmt = db()->prepare("SELECT codigo, nome_popular FROM especies WHERE id=?");
        $stmt->execute([$especie_id]);
        $esp = $stmt->fetch();
        if (!$esp) responder(false, null, 'Espécie não encontrada.');

        $ano  = date('Y');
        $stmt = db()->prepare("SELECT COUNT(*) FROM lotes WHERE especie_id=? AND YEAR(criado_em)=?");
        $stmt->execute([$especie_id, $ano]);
        $seq    = (int)$stmt->fetchColumn() + 1;
        $codigo = sprintf('%s-%s-%03d', $esp['codigo'], $ano, $seq);
        $fase   = $origem === 'sementeira' ? 'germinacao' : 'semeadura';

        db()->prepare("
            INSERT INTO lotes
                (codigo, tipo_lote, nome_display, especie_id, espaco_id, origem,
                 corredor, grade_linha, grade_col,
                 qtd_inicial, qtd_atual, sementes_por_unidade, matriz_origem,
                 fase_atual, embalagem_atual, status, data_semeadura,
                 protocolo_id, obs, criado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $codigo, 'normal', $esp['nome_popular'],
            $especie_id, $espaco_id ?: null, $origem,
            $corredor, $linha, $col,
            $qtd, $qtd, $sem_unid, $matriz,
            $fase, 'tubete', 'ativo', $data_sem,
            $protocolo_id, $obs, $eu['id'],
        ]);
        $lote_id = (int)db()->lastInsertId();

        _registrar_historico($lote_id, null, $fase, null, 'tubete',
            null, $espaco_id ?: null, $qtd, 'Lote criado', null, $eu['id']);

        responder(true, ['id'=>$lote_id,'codigo'=>$codigo,'mensagem'=>"Lote $codigo criado."]);
        break;

    // ----------------------------------------------------------
    // DETALHE DE UM LOTE
    // ----------------------------------------------------------
    case 'detalhe':
        $id = (int)($body['id'] ?? 0);
        if (!$id) responder(false, null, 'ID inválido.');

        $stmt = db()->prepare("
            SELECT l.*,
                   COALESCE(l.nome_display, e.nome_popular, 'Sem nome') AS nome_exibicao,
                   e.nome_popular, e.nome_cientifico, e.codigo AS especie_codigo,
                   e.dificuldade, e.rust_dias_min, e.rust_dias_max,
                   e.germ_taxa_esperada, e.embalagem_padrao,
                   e.transplante_necessario, e.altura_min_cm, e.tolera_repique_balde,
                   ev.nome AS espaco_nome, ev.tipo AS espaco_tipo,
                   u_enc.apelido AS enchido_por_nome,
                   lo.codigo AS lote_origem_codigo,
                   eo.nome_popular AS especie_origem_nome,
                   r.data_inicio AS rust_inicio, r.dias_planejados AS rust_planejados,
                   DATEDIFF(CURDATE(), r.data_inicio) AS dias_rustificando
            FROM lotes l
            LEFT JOIN especies e ON e.id = l.especie_id
            LEFT JOIN espacos_viveiro ev ON ev.id = l.espaco_id
            LEFT JOIN usuarios u_enc ON u_enc.id = l.enchido_por
            LEFT JOIN lotes lo ON lo.id = l.lote_origem_id
            LEFT JOIN especies eo ON eo.id = lo.especie_id
            LEFT JOIN rustificacao r ON r.lote_id=l.id AND r.data_fim IS NULL
            WHERE l.id=? AND l.ativo=1
        ");
        $stmt->execute([$id]);
        $lote = $stmt->fetch();
        if (!$lote) responder(false, null, 'Lote não encontrado.');

        $stmt = db()->prepare("
            SELECT hf.*, u.apelido AS usuario_nome FROM historico_fases hf
            JOIN usuarios u ON u.id=hf.registrado_por
            WHERE hf.lote_id=? ORDER BY hf.criado_em DESC LIMIT 20
        ");
        $stmt->execute([$id]);
        $historico = $stmt->fetchAll();

        $stmt = db()->prepare("
            SELECT m.*, u.apelido AS usuario_nome,
                   ea.nome AS espaco_anterior_nome, en.nome AS espaco_novo_nome
            FROM movimentacoes m
            JOIN usuarios u ON u.id=m.registrado_por
            LEFT JOIN espacos_viveiro ea ON ea.id=m.espaco_anterior
            LEFT JOIN espacos_viveiro en ON en.id=m.espaco_novo
            WHERE m.lote_id=? AND m.ativo=1 ORDER BY m.criado_em DESC LIMIT 10
        ");
        $stmt->execute([$id]);
        $movs = $stmt->fetchAll();

        $stmt = db()->prepare("
            SELECT * FROM registros_germinacao WHERE lote_id=? ORDER BY data DESC LIMIT 10
        ");
        $stmt->execute([$id]);
        $germinacao = $stmt->fetchAll();

        $stmt = db()->prepare("
            SELECT v.*, u.apelido AS registrado_por_nome, ug.apelido AS checado_por_nome,
                   (SELECT COUNT(*) FROM visita_acoes va WHERE va.visita_id=v.id) AS total_acoes,
                   (SELECT COUNT(*) FROM visita_acoes va WHERE va.visita_id=v.id AND va.checado=0) AS acoes_pendentes
            FROM visitas_lote v
            JOIN usuarios u ON u.id=v.registrado_por
            LEFT JOIN usuarios ug ON ug.id=v.checado_por
            WHERE v.lote_id=? ORDER BY v.criado_em DESC LIMIT 5
        ");
        $stmt->execute([$id]);
        $visitas = $stmt->fetchAll();

        $nivel = (int)$eu['nivel'];
        $especie_id = (int)($lote['especie_id'] ?? 0);
        $permissoes = [
            'visitar'          => true,
            'anotar'           => true,
            'registrar_perda'  => true,
            'gerar_tarefa'     => $nivel >= 3,
            'associar_especie' => $nivel >= 3 && $lote['tipo_lote'] === 'vazio',
            'repicagem'        => $especie_id ? verificar_permissao($eu['id'],'repicagem',$especie_id) : 'bloqueado',
            'transplante'      => $especie_id ? verificar_permissao($eu['id'],'transplante',$especie_id) : 'bloqueado',
            'expedicao'        => $nivel >= 4 ? 'livre' : 'bloqueado',
            'rustificacao'     => $nivel >= 4,
            'alterar_atencao'  => $nivel >= 4,
            'check_visitas'    => $nivel >= 4,
            'mover_mapa'       => $nivel >= 4,
        ];

        responder(true, [
            'lote'       => $lote,
            'historico'  => $historico,
            'movimentos' => $movs,
            'germinacao' => $germinacao,
            'visitas'    => $visitas,
            'alertas'    => _calcular_alertas($lote),
            'permissoes' => $permissoes,
        ]);
        break;

    // ----------------------------------------------------------
    // REGISTRAR VISITA
    // ----------------------------------------------------------
    case 'registrar_visita':
        $lote_id      = (int)($body['lote_id'] ?? 0);
        $estado_geral = $body['estado_geral'] ?? 'ok';
        $observacao   = trim($body['observacao'] ?? '') ?: null;
        $foto_b64     = $body['foto_base64'] ?? null;
        $sugere_atencao = (int)($body['sugere_atencao'] ?? 0);

        if (!$lote_id) responder(false, null, 'Lote inválido.');
        if (!in_array($estado_geral,['muito_bem','ok','atencao','problema']))
            responder(false, null, 'Estado inválido.');

        $stmt = db()->prepare("SELECT id, modo_atencao FROM lotes WHERE id=? AND ativo=1");
        $stmt->execute([$lote_id]);
        $lote = $stmt->fetch();
        if (!$lote) responder(false, null, 'Lote não encontrado.');

        $foto_path = $foto_b64 ? _salvar_foto($foto_b64,"visita_{$lote_id}_".time()) : null;

        db()->prepare("
            INSERT INTO visitas_lote
                (lote_id, estado_geral, observacao, foto, sugere_atencao, registrado_por, nivel_registrou)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([$lote_id,$estado_geral,$observacao,$foto_path,$sugere_atencao,$eu['id'],$eu['nivel']]);
        $visita_id = (int)db()->lastInsertId();

        if ($estado_geral === 'problema' && $lote['modo_atencao'] === 'nenhum') {
            db()->prepare("UPDATE lotes SET modo_atencao='historico_ocorrencia',
                modo_atencao_obs='Problema registrado em visita' WHERE id=?")
                ->execute([$lote_id]);
        }
        if ($sugere_atencao && $lote['modo_atencao'] === 'nenhum') {
            db()->prepare("UPDATE lotes SET modo_atencao='gestor_definiu',
                modo_atencao_obs='Funcionário sugeriu atenção' WHERE id=?")
                ->execute([$lote_id]);
        }

        db()->prepare("
            INSERT INTO atividades (usuario_id,data,tipo,descricao,score_base,nivel_criador)
            VALUES (?,CURDATE(),'ronda',?,15,?)
        ")->execute([$eu['id'],"Visita ao lote — estado: $estado_geral",$eu['nivel']]);

        _recalc_score_dia((int)$eu['id'], date('Y-m-d'));

        responder(true, ['visita_id'=>$visita_id,'mensagem'=>'Visita registrada.']);
        break;

    // ----------------------------------------------------------
    // REGISTRAR AÇÃO DA VISITA
    // ----------------------------------------------------------
    case 'registrar_acao_visita':
        $visita_id = (int)($body['visita_id'] ?? 0);
        $lote_id   = (int)($body['lote_id'] ?? 0);
        $tipo_acao = $body['tipo_acao'] ?? '';
        $nivel     = (int)$eu['nivel'];

        if (!$visita_id || !$lote_id) responder(false, null, 'Visita ou lote inválido.');
        if (!in_array($tipo_acao,['perda','tarefa','realizada','reaproveita']))
            responder(false, null, 'Tipo inválido.');
        if ($tipo_acao === 'tarefa' && $nivel < 3)
            responder(false, null, 'Nível mínimo 3 para gerar tarefas.');

        $dados_acao = ['visita_id'=>$visita_id,'lote_id'=>$lote_id,
                       'tipo_acao'=>$tipo_acao,'registrado_por'=>$eu['id']];

        if ($tipo_acao === 'perda') {
            $qtd   = (int)($body['qtd_perdida'] ?? 0);
            $causa = trim($body['causa_perda'] ?? '');
            $det   = trim($body['causa_detalhe'] ?? '') ?: null;
            if (!$qtd)   responder(false, null, 'Quantidade inválida.');
            if (!$causa) responder(false, null, 'Causa é obrigatória.');

            $dados_acao += ['qtd_perdida'=>$qtd,'causa_perda'=>$causa,'causa_detalhe'=>$det];

            $stmt = db()->prepare("SELECT qtd_atual, fase_atual, embalagem_atual, corredor, grade_linha, grade_col FROM lotes WHERE id=?");
            $stmt->execute([$lote_id]);
            $l = $stmt->fetch();
            if ($qtd > (int)$l['qtd_atual'])
                responder(false, null, "Quantidade maior que disponível ({$l['qtd_atual']}).");

            db()->prepare("UPDATE lotes SET qtd_atual=qtd_atual-?,qtd_perdida=qtd_perdida+? WHERE id=?")
                ->execute([$qtd,$qtd,$lote_id]);
            db()->prepare("
                INSERT INTO movimentacoes
                    (lote_id,tipo_mov,data,quantidade,causa_perda,causa_detalhe,fase_perda,registrado_por)
                VALUES (?,?,CURDATE(),?,?,?,?,?)
            ")->execute([$lote_id,'perda',$qtd,$causa,$det,$l['fase_atual'],$eu['id']]);

            // Cria lote vazio (Reuso) próximo ao lote de origem
            _criar_reuso($lote_id, $qtd, $l['embalagem_atual'],
                $l['corredor'], $l['grade_linha'], $l['grade_col'], $eu['id']);
        }

        if ($tipo_acao === 'tarefa') {
            $titulo = trim($body['tarefa_titulo'] ?? '');
            $prio   = $body['tarefa_prioridade'] ?? 'normal';
            if (!$titulo) responder(false, null, 'Título é obrigatório.');
            $dados_acao += ['tarefa_titulo'=>$titulo,'tarefa_prioridade'=>$prio];
            db()->prepare("INSERT INTO tarefas (titulo,prioridade,lote_id,nivel_minimo,criado_por,data_limite)
                VALUES (?,?,?,1,?,CURDATE())")
                ->execute([$titulo,$prio,$lote_id,$eu['id']]);
            $dados_acao['tarefa_id'] = (int)db()->lastInsertId();
        }

        if ($tipo_acao === 'realizada') {
            $desc = trim($body['acao_descricao'] ?? '');
            if (!$desc) responder(false, null, 'Descreva o que foi realizado.');
            $dados_acao['acao_descricao'] = $desc;
        }

        if ($tipo_acao === 'reaproveita') {
            $qtd_sacos    = (int)($body['qtd_saquinhos'] ?? 0);
            $lote_destino = isset($body['lote_destino_id']) ? (int)$body['lote_destino_id'] : null;
            if ($qtd_sacos <= 0) responder(false, null, 'Quantidade inválida.');
            $dados_acao += ['qtd_saquinhos'=>$qtd_sacos,'lote_destino_id'=>$lote_destino];
            db()->prepare("INSERT INTO movimentacoes
                (lote_id,tipo_mov,data,quantidade,lote_destino_id,registrado_por)
                VALUES (?,?,CURDATE(),?,?,?)")
                ->execute([$lote_id,'reaproveitamento',$qtd_sacos,$lote_destino,$eu['id']]);
            db()->prepare("UPDATE lotes SET qtd_atual=qtd_atual-? WHERE id=?")
                ->execute([$qtd_sacos,$lote_id]);
        }

        $cols = implode(',', array_keys($dados_acao));
        $vals = implode(',', array_fill(0, count($dados_acao), '?'));
        db()->prepare("INSERT INTO visita_acoes ($cols) VALUES ($vals)")
            ->execute(array_values($dados_acao));

        responder(true, ['mensagem'=>'Ação registrada.']);
        break;

    // ----------------------------------------------------------
    // CHECAR VISITA
    // ----------------------------------------------------------
    case 'checar_visita':
        exigir_nivel($eu, 4);
        $visita_id = (int)($body['visita_id'] ?? 0);
        $resultado = $body['resultado'] ?? 'ok'; // 'ok' | 'atencao'
        $obs_gestor = trim($body['obs_gestor'] ?? '') ?: null;

        $stmt = db()->prepare("SELECT lote_id FROM visitas_lote WHERE id=?");
        $stmt->execute([$visita_id]);
        $lote_id = (int)$stmt->fetchColumn();

        db()->prepare("UPDATE visitas_lote SET checado_por=?,checado_em=NOW(),obs_gestor=?,estado_geral=? WHERE id=?")
            ->execute([$eu['id'], $obs_gestor, $resultado, $visita_id]);
        db()->prepare("UPDATE visita_acoes SET checado=1,checado_por=?,checado_em=NOW() WHERE visita_id=?")
            ->execute([$eu['id'], $visita_id]);

        if ($resultado === 'atencao' && $lote_id) {
            db()->prepare("UPDATE lotes SET modo_atencao='gestor_definiu',modo_atencao_obs=? WHERE id=?")
                ->execute([$obs_gestor, $lote_id]);
        } elseif ($resultado === 'ok' && $lote_id) {
            db()->prepare("UPDATE lotes SET modo_atencao='nenhum',modo_atencao_obs=NULL WHERE id=?")
                ->execute([$lote_id]);
        }

        responder(true, ['mensagem' => 'Visita checada.']);
        break;

    // ----------------------------------------------------------
    // ALTERAR MODO ATENÇÃO
    // ----------------------------------------------------------
    case 'alterar_atencao':
        exigir_nivel($eu, 4);
        $lote_id = (int)($body['lote_id'] ?? 0);
        $modo    = $body['modo_atencao'] ?? 'nenhum';
        $obs_a   = trim($body['obs'] ?? '') ?: null;
        $modos   = ['nenhum','zona_inadequada','especie_sensivel','historico_ocorrencia','gestor_definiu'];
        if (!in_array($modo,$modos)) responder(false, null, 'Modo inválido.');
        db()->prepare("UPDATE lotes SET modo_atencao=?,modo_atencao_obs=? WHERE id=?")
            ->execute([$modo,$obs_a,$lote_id]);
        responder(true, ['mensagem'=>'Modo de atenção atualizado.']);
        break;

    // ----------------------------------------------------------
    // MOVER LOTE NO MAPA (atualiza corredor/linha/col)
    // ----------------------------------------------------------
    case 'mover_grade':
        exigir_nivel($eu, 2);
        $lote_id  = (int)($body['lote_id'] ?? 0);
        $corredor = (int)($body['corredor'] ?? 1);
        $linha    = (int)($body['linha'] ?? 1);
        $col      = (int)($body['col'] ?? 1);

        db()->prepare("UPDATE lotes SET corredor=?,grade_linha=?,grade_col=? WHERE id=?")
            ->execute([$corredor,$linha,$col,$lote_id]);

        // Registra movimentação
        $stmt = db()->prepare("SELECT qtd_atual FROM lotes WHERE id=?");
        $stmt->execute([$lote_id]);
        $qtd = (int)$stmt->fetchColumn();
        db()->prepare("INSERT INTO movimentacoes (lote_id,tipo_mov,data,quantidade,motivo,registrado_por)
            VALUES (?,?,CURDATE(),?,?,?)")
            ->execute([$lote_id,'movimentacao',$qtd,"Movido para corredor $corredor, linha $linha, col $col",$eu['id']]);

        responder(true, ['mensagem'=>'Lote movido no mapa.']);
        break;

    // ----------------------------------------------------------
    // INICIAR PROCESSO DE TAXA (germinação ou adaptação)
    // Abre o acompanhamento com foto obrigatória
    // ----------------------------------------------------------
    case 'iniciar_taxa':
        exigir_nivel($eu, 4);
        $lote_id   = (int)($body['lote_id'] ?? 0);
        $tipo_taxa = $body['tipo'] ?? 'germinacao'; // germinacao | adaptacao
        $qtd_ini   = (int)($body['qtd_inicial'] ?? 0);
        $foto_b64  = $body['foto_base64'] ?? null;

        if (!$lote_id) responder(false, null, 'Lote inválido.');
        if (!$foto_b64) responder(false, null, 'Foto obrigatória para iniciar o processo de taxa.');
        if (!in_array($tipo_taxa, ['germinacao','adaptacao'])) responder(false, null, 'Tipo inválido.');

        $stmt = db()->prepare("SELECT fase_atual, qtd_atual, embalagem_atual FROM lotes WHERE id=? AND ativo=1");
        $stmt->execute([$lote_id]);
        $lote = $stmt->fetch();
        if (!$lote) responder(false, null, 'Lote não encontrado.');

        $foto_path = _salvar_foto($foto_b64, "taxa_inicio_{$lote_id}_" . time());
        $qtd_usar  = $qtd_ini ?: (int)$lote['qtd_atual'];

        db()->prepare("
            UPDATE lotes
            SET fase_atual=?, taxa_inicio_em=NOW(), taxa_qtd_inicial=?,
                taxa_qtd_atual=?, taxa_encerrada=0
            WHERE id=?
        ")->execute([$tipo_taxa, $qtd_usar, $qtd_usar, $lote_id]);

        // Primeiro registro de taxa
        db()->prepare("
            INSERT INTO registros_taxa (lote_id, tipo, qtd_vivas, qtd_perdidas, foto_path, registrado_por)
            VALUES (?,?,?,0,?,?)
        ")->execute([$lote_id, $tipo_taxa, $qtd_usar, $foto_path, $eu['id']]);

        responder(true, ['mensagem' => "Processo de {$tipo_taxa} iniciado com {$qtd_usar} mudas."]);
        break;

    // ----------------------------------------------------------
    // REGISTRAR TAXA (número do dia)
    // Detecta queda e obriga justificativa
    // ----------------------------------------------------------
    case 'registrar_taxa':
        exigir_nivel($eu, 4);
        $lote_id       = (int)($body['lote_id'] ?? 0);
        $qtd_vivas     = (int)($body['qtd_vivas'] ?? 0);
        $justificativa = trim($body['justificativa'] ?? '') ?: null;
        $foto_b64      = $body['foto_base64'] ?? null;

        if (!$lote_id) responder(false, null, 'Lote inválido.');

        $stmt = db()->prepare("SELECT fase_atual, taxa_qtd_atual, taxa_qtd_inicial FROM lotes WHERE id=? AND ativo=1");
        $stmt->execute([$lote_id]);
        $lote = $stmt->fetch();
        if (!$lote) responder(false, null, 'Lote não encontrado.');
        if (!in_array($lote['fase_atual'], ['germinacao','adaptacao'])) {
            responder(false, null, 'Lote não está em processo de taxa.');
        }

        $qtd_anterior  = (int)$lote['taxa_qtd_atual'];
        $qtd_perdidas  = max(0, $qtd_anterior - $qtd_vivas);

        // Se houve queda, justificativa é obrigatória
        if ($qtd_perdidas > 0 && !$justificativa) {
            responder(false, null, "Houve queda de {$qtd_perdidas} muda(s). Justificativa obrigatória.");
        }

        $foto_path = $foto_b64 ? _salvar_foto($foto_b64, "taxa_reg_{$lote_id}_" . time()) : null;

        db()->prepare("
            INSERT INTO registros_taxa (lote_id, tipo, qtd_vivas, qtd_perdidas, justificativa, foto_path, registrado_por)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([$lote_id, $lote['fase_atual'], $qtd_vivas, $qtd_perdidas, $justificativa, $foto_path, $eu['id']]);

        db()->prepare("UPDATE lotes SET taxa_qtd_atual=? WHERE id=?")
            ->execute([$qtd_vivas, $lote_id]);

        responder(true, [
            'qtd_vivas'    => $qtd_vivas,
            'qtd_perdidas' => $qtd_perdidas,
            'mensagem'     => $qtd_perdidas > 0
                ? "Registrado: {$qtd_vivas} vivas, {$qtd_perdidas} perdidas."
                : "Registrado: {$qtd_vivas} mudas vivas."
        ]);
        break;

    // ----------------------------------------------------------
    // FECHAR TAXA — declara CRESCENDO com número oficial
    // ----------------------------------------------------------
    case 'fechar_taxa':
        exigir_nivel($eu, 4);
        $lote_id    = (int)($body['lote_id'] ?? 0);
        $qtd_final  = (int)($body['qtd_final'] ?? 0);
        $obs_final  = trim($body['obs'] ?? '') ?: null;

        if (!$lote_id)   responder(false, null, 'Lote inválido.');
        if (!$qtd_final) responder(false, null, 'Informe a quantidade final de mudas aprovadas.');

        $stmt = db()->prepare("SELECT fase_atual, embalagem_atual FROM lotes WHERE id=? AND ativo=1");
        $stmt->execute([$lote_id]);
        $lote = $stmt->fetch();
        if (!in_array($lote['fase_atual'], ['germinacao','adaptacao'])) {
            responder(false, null, 'Lote não está em processo de taxa.');
        }

        $fase_crescimento = $lote['embalagem_atual'] === 'saco' ? 'crescimento_saco' : 'crescimento_tubete';

        db()->prepare("
            UPDATE lotes
            SET fase_atual=?, qtd_atual=?, taxa_encerrada=1,
                status='ativo', data_plantio_atual=CURDATE()
            WHERE id=?
        ")->execute([$fase_crescimento, $qtd_final, $lote_id]);

        // Último registro de taxa
        db()->prepare("
            INSERT INTO registros_taxa (lote_id, tipo, qtd_vivas, qtd_perdidas, justificativa, registrado_por)
            SELECT id, fase_atual, ?, GREATEST(0, taxa_qtd_atual - ?), ?, ?
            FROM lotes WHERE id=?
        ")->execute([$qtd_final, $qtd_final, $obs_final ?? 'Processo encerrado pelo gestor', $eu['id'], $lote_id]);

        responder(true, [
            'qtd_final' => $qtd_final,
            'fase'      => $fase_crescimento,
            'mensagem'  => "✓ {$qtd_final} mudas aprovadas. Lote entra em CRESCENDO — número oficial registrado."
        ]);
        break;

    // ----------------------------------------------------------
    // FINALIZAR BANDEJA — volta para vazio
    // ----------------------------------------------------------
    case 'finalizar_bandeja':
        exigir_nivel($eu, 3);
        $lote_id = (int)($body['lote_id'] ?? 0);
        if (!$lote_id) responder(false, null, 'Lote inválido.');

        $stmt = db()->prepare("SELECT embalagem_atual FROM lotes WHERE id=? AND ativo=1");
        $stmt->execute([$lote_id]);
        $lote = $stmt->fetch();
        if ($lote['embalagem_atual'] !== 'bandeja') {
            responder(false, null, 'Este lote não é uma bandeja.');
        }

        db()->prepare("
            UPDATE lotes SET fase_atual='vazio', status='vazio',
                qtd_atual=0, especie_id=NULL, taxa_encerrada=0,
                taxa_qtd_atual=NULL, taxa_qtd_inicial=NULL, taxa_inicio_em=NULL
            WHERE id=?
        ")->execute([$lote_id]);

        responder(true, ['mensagem' => 'Bandeja finalizada e disponível para novo plantio.']);
        break;

    // ----------------------------------------------------------
    // ENCERRAR SEMENTEIRA — volta para vazio (nível 4-5 apenas)
    // ----------------------------------------------------------
    case 'encerrar_sementeira':
        exigir_nivel($eu, 4);
        $lote_id = (int)($body['lote_id'] ?? 0);
        if (!$lote_id) responder(false, null, 'Lote inválido.');

        $stmt = db()->prepare("SELECT embalagem_atual FROM lotes WHERE id=? AND ativo=1");
        $stmt->execute([$lote_id]);
        $lote = $stmt->fetch();
        if ($lote['embalagem_atual'] !== 'sementeira') {
            responder(false, null, 'Este lote não é uma sementeira.');
        }

        db()->prepare("
            UPDATE lotes SET fase_atual='vazio', status='vazio',
                qtd_atual=0, especie_id=NULL,
                taxa_encerrada=0, taxa_qtd_atual=NULL,
                taxa_qtd_inicial=NULL, taxa_inicio_em=NULL
            WHERE id=?
        ")->execute([$lote_id]);

        responder(true, ['mensagem' => 'Sementeira encerrada e disponível para novo uso.']);
        break;
    // ----------------------------------------------------------
    case 'historico_taxa':
        $lote_id = (int)($body['lote_id'] ?? 0);
        if (!$lote_id) responder(false, null, 'ID inválido.');

        $stmt = db()->prepare("
            SELECT rt.*, u.apelido AS registrado_por_nome,
                   TIMESTAMPDIFF(DAY, l.taxa_inicio_em, rt.registrado_em) AS dia_taxa
            FROM registros_taxa rt
            JOIN usuarios u ON u.id = rt.registrado_por
            JOIN lotes l ON l.id = rt.lote_id
            WHERE rt.lote_id = ?
            ORDER BY rt.registrado_em ASC
        ");
        $stmt->execute([$lote_id]);
        $registros = $stmt->fetchAll();

        // Também retorna dados do lote para o gráfico
        $stmt = db()->prepare("SELECT taxa_inicio_em, taxa_qtd_inicial, taxa_qtd_atual, taxa_encerrada, fase_atual FROM lotes WHERE id=?");
        $stmt->execute([$lote_id]);
        $taxa_info = $stmt->fetch();

        responder(true, ['registros' => $registros, 'taxa_info' => $taxa_info]);
        break;

    // ----------------------------------------------------------
    // CRIAR TESTE A/B (divide lote em dois)
    // ----------------------------------------------------------
    case 'criar_teste_ab':
        exigir_nivel($eu, 4);
        $lote_id = (int)($body['lote_id'] ?? 0);
        $titulo  = trim($body['titulo'] ?? '');
        $tese    = trim($body['tese'] ?? '');
        $qtd_b   = (int)($body['qtd_b'] ?? 0); // quantas mudas vão para o lote B

        if (!$lote_id) responder(false, null, 'Lote inválido.');
        if (!$titulo)  responder(false, null, 'Título é obrigatório.');
        if (!$tese)    responder(false, null, 'Tese é obrigatória.');
        if ($qtd_b <= 0) responder(false, null, 'Informe quantas mudas vão para o Lote B.');

        $stmt = db()->prepare("SELECT * FROM lotes WHERE id=? AND ativo=1");
        $stmt->execute([$lote_id]);
        $lote_a = $stmt->fetch();
        if (!$lote_a) responder(false, null, 'Lote não encontrado.');
        if ((int)$lote_a['qtd_atual'] <= $qtd_b) {
            responder(false, null, 'Quantidade do Lote B deve ser menor que o total atual.');
        }

        // Cria o lote B como cópia do A
        $ano   = date('Y');
        $stmt2 = db()->prepare("SELECT COUNT(*) FROM lotes WHERE YEAR(criado_em)=?");
        $stmt2->execute([$ano]); $seq = (int)$stmt2->fetchColumn() + 1;
        $cod_b = sprintf('TST-%s-%03d', $ano, $seq);

        db()->prepare("
            INSERT INTO lotes (codigo, tipo_lote, nome_display, especie_id, origem,
                qtd_inicial, qtd_atual, fase_atual, embalagem_atual,
                status, corredor, grade_linha, grade_col,
                lote_origem_id, criado_por)
            SELECT ?,tipo_lote,CONCAT(nome_display,' [B]'),especie_id,origem,
                ?,?,fase_atual,embalagem_atual,
                status,corredor,grade_linha,grade_col,
                id,?
            FROM lotes WHERE id=?
        ")->execute([$cod_b, $qtd_b, $qtd_b, $eu['id'], $lote_id]);
        $lote_b_id = (int)db()->lastInsertId();

        // Reduz qtd do lote A
        db()->prepare("UPDATE lotes SET qtd_atual = qtd_atual - ?, nome_display=CONCAT(nome_display,' [A]') WHERE id=?")
            ->execute([$qtd_b, $lote_id]);

        // Cria o teste
        db()->prepare("INSERT INTO testes_ab (titulo, tese, lote_a_id, lote_b_id, criado_por) VALUES (?,?,?,?,?)")
            ->execute([$titulo, $tese, $lote_id, $lote_b_id, $eu['id']]);
        $teste_id = (int)db()->lastInsertId();

        // Vincula os dois lotes ao teste
        db()->prepare("UPDATE lotes SET teste_ab_id=? WHERE id IN (?,?)")
            ->execute([$teste_id, $lote_id, $lote_b_id]);

        responder(true, [
            'teste_id'  => $teste_id,
            'lote_b_id' => $lote_b_id,
            'mensagem'  => "✓ Teste A/B criado. Lote A: " . ((int)$lote_a['qtd_atual'] - $qtd_b) . " mudas · Lote B: {$qtd_b} mudas."
        ]);
        break;

    // ----------------------------------------------------------
    // REGISTRAR GERMINAÇÃO (legado — mantido por compatibilidade)
    // ----------------------------------------------------------
    case 'registrar_germinacao':
        $lote_id   = (int)($body['lote_id'] ?? 0);
        $germ_hoje = (int)($body['germinadas_hoje'] ?? 0);
        $perd_hoje = (int)($body['perdidas_hoje'] ?? 0);
        $foto_b64  = $body['foto_base64'] ?? null;
        $obs_germ  = trim($body['obs'] ?? '') ?: null;
        $data      = $body['data'] ?? date('Y-m-d');

        if (!$lote_id) responder(false, null, 'Lote inválido.');

        $stmt = db()->prepare("
            SELECT COALESCE(MAX(total_germinadas),0), COALESCE(MAX(total_perdidas),0)
            FROM registros_germinacao WHERE lote_id=? AND data<?
        ");
        $stmt->execute([$lote_id,$data]);
        [$acum_germ,$acum_perd] = $stmt->fetch(PDO::FETCH_NUM);

        $total_germ = $acum_germ + $germ_hoje;
        $total_perd = $acum_perd + $perd_hoje;
        $foto_path  = $foto_b64 ? _salvar_foto($foto_b64,"germ_{$lote_id}_{$data}") : null;

        db()->prepare("
            INSERT INTO registros_germinacao
                (lote_id,data,germinadas_hoje,perdidas_hoje,total_germinadas,total_perdidas,foto,obs,registrado_por)
            VALUES (?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                germinadas_hoje=VALUES(germinadas_hoje), perdidas_hoje=VALUES(perdidas_hoje),
                total_germinadas=VALUES(total_germinadas), total_perdidas=VALUES(total_perdidas),
                foto=COALESCE(VALUES(foto),foto), obs=COALESCE(VALUES(obs),obs)
        ")->execute([$lote_id,$data,$germ_hoje,$perd_hoje,$total_germ,$total_perd,$foto_path,$obs_germ,$eu['id']]);

        $stmt = db()->prepare("SELECT qtd_inicial FROM lotes WHERE id=?");
        $stmt->execute([$lote_id]);
        $qtd_ini = (int)$stmt->fetchColumn();

        $taxa_germ = $qtd_ini > 0 ? round(($total_germ/$qtd_ini)*100,2) : null;
        $taxa_perd = $qtd_ini > 0 ? round(($total_perd/$qtd_ini)*100,2) : null;

        db()->prepare("UPDATE lotes SET qtd_atual=qtd_inicial-?,taxa_germinacao=?,taxa_perda=?,
            data_germinacao=COALESCE(data_germinacao,CURDATE()) WHERE id=?")
            ->execute([$total_perd,$taxa_germ,$taxa_perd,$lote_id]);

        responder(true, ['total_germinadas'=>$total_germ,'total_perdidas'=>$total_perd,'taxa_germinacao'=>$taxa_germ]);
        break;

    // ----------------------------------------------------------
    // REGISTRAR MOVIMENTAÇÃO
    // ----------------------------------------------------------
    case 'registrar_mov':
        $lote_id  = (int)($body['lote_id'] ?? 0);
        $tipo_mov = $body['tipo_mov'] ?? '';
        $qtd      = (int)($body['quantidade'] ?? 0);
        $motivo   = trim($body['motivo'] ?? '') ?: null;
        $foto_b64 = $body['foto_base64'] ?? null;
        $uuid     = $body['uuid'] ?? null;

        if (!$lote_id) responder(false, null, 'Lote inválido.');
        if (!in_array($tipo_mov,['transplante','movimentacao','perda'])) responder(false, null, 'Tipo inválido.');
        if ($qtd <= 0) responder(false, null, 'Quantidade inválida.');

        $stmt = db()->prepare("SELECT * FROM lotes WHERE id=? AND ativo=1");
        $stmt->execute([$lote_id]);
        $lote = $stmt->fetch();
        if (!$lote) responder(false, null, 'Lote não encontrado.');

        if ($tipo_mov === 'perda') {
            $causa = trim($body['causa_perda'] ?? '');
            if (!$causa) responder(false, null, 'Causa é obrigatória.');
            if ($qtd > $lote['qtd_atual']) responder(false, null, "Quantidade maior que disponível ({$lote['qtd_atual']}).");
        }

        $foto_path   = $foto_b64 ? _salvar_foto($foto_b64,"{$tipo_mov}_{$lote_id}_".time()) : null;
        $emb_ant     = $lote['embalagem_atual'];
        $emb_nova    = $body['embalagem_nova'] ?? null;
        $espaco_ant  = $lote['espaco_id'];
        $espaco_novo = isset($body['espaco_novo']) ? (int)$body['espaco_novo'] : null;
        $causa_perda = $body['causa_perda'] ?? null;
        $causa_det   = trim($body['causa_detalhe'] ?? '') ?: null;

        db()->prepare("
            INSERT INTO movimentacoes
                (lote_id,tipo_mov,data,quantidade,emb_anterior,emb_nova,
                 espaco_anterior,espaco_novo,motivo,causa_perda,causa_detalhe,
                 fase_perda,foto,registrado_por,offline_uuid)
            VALUES (?,?,CURDATE(),?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $lote_id,$tipo_mov,$qtd,
            $tipo_mov==='transplante'?$emb_ant:null,
            $tipo_mov==='transplante'?($emb_nova??null):null,
            $espaco_ant,$espaco_novo,$motivo,
            $causa_perda,$causa_det,
            $tipo_mov==='perda'?$lote['fase_atual']:null,
            $foto_path,$eu['id'],$uuid,
        ]);

        if ($tipo_mov === 'perda') {
            db()->prepare("UPDATE lotes SET qtd_atual=qtd_atual-?,qtd_perdida=qtd_perdida+? WHERE id=?")
                ->execute([$qtd,$qtd,$lote_id]);
            _criar_reuso($lote_id, $qtd, $lote['embalagem_atual'],
                $lote['corredor'], $lote['grade_linha'], $lote['grade_col'], $eu['id']);
        } elseif ($tipo_mov === 'transplante') {
            db()->prepare("UPDATE lotes SET embalagem_atual=COALESCE(?,embalagem_atual),espaco_id=COALESCE(?,espaco_id) WHERE id=?")
                ->execute([$emb_nova,$espaco_novo,$lote_id]);
        } elseif ($tipo_mov === 'movimentacao') {
            db()->prepare("UPDATE lotes SET espaco_id=COALESCE(?,espaco_id) WHERE id=?")
                ->execute([$espaco_novo,$lote_id]);
        }

        responder(true, ['mensagem'=>ucfirst($tipo_mov).' registrada.']);
        break;

    // ----------------------------------------------------------
    // INICIAR RUSTIFICAÇÃO
    // ----------------------------------------------------------
    case 'iniciar_rustificacao':
        exigir_nivel($eu, 4);
        $lote_id   = (int)($body['lote_id'] ?? 0);
        $espaco_id = isset($body['espaco_id']) ? (int)$body['espaco_id'] : null;
        $dias      = isset($body['dias_planejados']) ? (int)$body['dias_planejados'] : null;
        $obs_r     = trim($body['obs'] ?? '') ?: null;
        $foto_b64  = $body['foto_base64'] ?? null;

        $stmt = db()->prepare("SELECT id FROM rustificacao WHERE lote_id=?");
        $stmt->execute([$lote_id]);
        if ($stmt->fetch()) responder(false, null, 'Rustificação já iniciada.');

        $foto_path = $foto_b64 ? _salvar_foto($foto_b64,"rust_{$lote_id}_ini") : null;
        db()->prepare("INSERT INTO rustificacao (lote_id,data_inicio,dias_planejados,espaco_id,obs,iniciado_por,foto_inicio)
            VALUES (?,CURDATE(),?,?,?,?,?)")
            ->execute([$lote_id,$dias,$espaco_id,$obs_r,$eu['id'],$foto_path]);
        db()->prepare("UPDATE lotes SET fase_atual='rustificacao',status='rustificando',
            data_rustificacao=CURDATE(),espaco_id=COALESCE(?,espaco_id) WHERE id=?")
            ->execute([$espaco_id,$lote_id]);
        _registrar_historico($lote_id,'crescimento_tubete','rustificacao',null,null,null,$espaco_id,null,'Rustificação iniciada',$foto_path,$eu['id']);
        responder(true, ['mensagem'=>'Rustificação iniciada.']);
        break;

    // ----------------------------------------------------------
    // REGISTRAR EXPEDIÇÃO
    // ----------------------------------------------------------
    case 'registrar_expedicao':
        exigir_nivel($eu, 4);
        $lote_id  = (int)($body['lote_id'] ?? 0);
        $qtd      = (int)($body['quantidade'] ?? 0);
        $destino  = trim($body['destino'] ?? '');
        $resp     = trim($body['responsavel'] ?? '') ?: null;
        $obs_e    = trim($body['obs'] ?? '') ?: null;
        $foto_b64 = $body['foto_base64'] ?? null;

        if (!$qtd)     responder(false, null, 'Quantidade inválida.');
        if (!$destino) responder(false, null, 'Destino é obrigatório.');

        $stmt = db()->prepare("SELECT l.*,e.rust_dias_min FROM lotes l LEFT JOIN especies e ON e.id=l.especie_id WHERE l.id=?");
        $stmt->execute([$lote_id]);
        $lote = $stmt->fetch();
        if (!$lote) responder(false, null, 'Lote não encontrado.');
        if ($lote['tipo_lote'] === 'vazio') responder(false, null, 'Não é possível expedir lote vazio.');

        $stmt = db()->prepare("SELECT data_inicio FROM rustificacao WHERE lote_id=? AND data_inicio IS NOT NULL");
        $stmt->execute([$lote_id]);
        $rust = $stmt->fetch();
        if (!$rust) responder(false, null, 'Rustificação obrigatória antes de expedir.');

        $dias_rust = (int)date_diff(date_create($rust['data_inicio']),date_create())->days;
        $min_rust  = (int)($lote['rust_dias_min'] ?? 15);
        if ($dias_rust < $min_rust)
            responder(false, null, "Rustificação insuficiente: {$dias_rust} dias. Mínimo: {$min_rust}.");
        if ($qtd > $lote['qtd_atual'])
            responder(false, null, "Quantidade ({$qtd}) maior que disponível ({$lote['qtd_atual']}).");

        $foto_path = $foto_b64 ? _salvar_foto($foto_b64,"exp_{$lote_id}_".time()) : null;
        db()->prepare("INSERT INTO expedicao (lote_id,data,quantidade,destino,embalagem,responsavel,obs,foto,aprovado_por)
            VALUES (?,CURDATE(),?,?,?,?,?,?,?)")
            ->execute([$lote_id,$qtd,$destino,$lote['embalagem_atual'],$resp,$obs_e,$foto_path,$eu['id']]);

        $qtd_nova   = $lote['qtd_atual'] - $qtd;
        $qtd_exp_t  = $lote['qtd_expedida'] + $qtd;
        $taxa_aprov = $lote['qtd_inicial'] > 0 ? round(($qtd_exp_t/$lote['qtd_inicial'])*100,2) : null;
        $novo_status= $qtd_nova <= 0 ? 'expedido' : 'ativo';

        db()->prepare("UPDATE lotes SET qtd_atual=?,qtd_expedida=?,taxa_aproveitamento=?,status=?,
            data_expedicao=CURDATE(),fase_atual=CASE WHEN ?<=0 THEN 'expedicao' ELSE fase_atual END WHERE id=?")
            ->execute([$qtd_nova,$qtd_exp_t,$taxa_aprov,$novo_status,$qtd_nova,$lote_id]);
        db()->prepare("UPDATE rustificacao SET data_fim=CURDATE(),encerrado_por=? WHERE lote_id=?")
            ->execute([$eu['id'],$lote_id]);

        // Tubetes expedidos voltam como reuso próximo ao lote
        _criar_reuso($lote_id, $qtd, $lote['embalagem_atual'],
            $lote['corredor'], $lote['grade_linha'], $lote['grade_col'], $eu['id']);

        responder(true, ['mensagem'=>"$qtd mudas expedidas para '$destino'.",'qtd_restante'=>$qtd_nova]);
        break;

    // ----------------------------------------------------------
    // ATUALIZAR STATUS
    // ----------------------------------------------------------
    case 'atualizar_status':
        exigir_nivel($eu, 4);
        $lote_id = (int)($body['lote_id'] ?? 0);
        $status  = $body['status'] ?? '';
        if (!in_array($status,['vazio','ativo','urgente','aguardando_agua','pronto','arquivado']))
            responder(false, null, 'Status inválido.');
        db()->prepare("UPDATE lotes SET status=? WHERE id=?")->execute([$status,$lote_id]);
        responder(true, ['mensagem'=>'Status atualizado.']);
        break;

    // ----------------------------------------------------------
    // DASHBOARD
    // ----------------------------------------------------------
    case 'dashboard':
        exigir_nivel($eu, 3);
        $stmt = db()->query("
            SELECT
                COUNT(*) AS total_lotes_ativos,
                SUM(qtd_atual) AS total_mudas,
                SUM(status='urgente') AS urgentes,
                SUM(status='pronto') AS prontos,
                SUM(status='rustificando') AS rustificando,
                SUM(modo_atencao!='nenhum') AS em_atencao,
                SUM(tipo_lote='vazio') AS lotes_vazios,
                SUM(CASE WHEN tipo_lote='vazio' THEN qtd_atual ELSE 0 END) AS embalagens_vazias
            FROM lotes WHERE ativo=1 AND status NOT IN ('expedido','arquivado')
        ");
        $kpis = $stmt->fetch();
        $stmt = db()->query("
            SELECT SUM(quantidade) AS expedidas_mes
            FROM expedicao WHERE MONTH(data)=MONTH(CURDATE()) AND YEAR(data)=YEAR(CURDATE())
        ");
        $mes = $stmt->fetch();
        responder(true, array_merge($kpis,$mes));
        break;

    // ----------------------------------------------------------
    // VISITAS PENDENTES
    // ----------------------------------------------------------
    case 'visitas_pendentes':
        exigir_nivel($eu, 4);
        $stmt = db()->query("
            SELECT v.*,
                   COALESCE(l.nome_display, e.nome_popular, l.codigo) AS lote_nome,
                   l.codigo AS lote_codigo,
                   u.apelido AS registrado_por_nome,
                   COUNT(va.id) AS total_acoes
            FROM visitas_lote v
            JOIN lotes l ON l.id=v.lote_id
            LEFT JOIN especies e ON e.id=l.especie_id
            JOIN usuarios u ON u.id=v.registrado_por
            LEFT JOIN visita_acoes va ON va.visita_id=v.id AND va.checado=0
            WHERE v.checado_por IS NULL
            GROUP BY v.id
            ORDER BY v.estado_geral='problema' DESC, v.criado_em DESC LIMIT 20
        ");
        responder(true, ['visitas'=>$stmt->fetchAll()]);
        break;

    // ----------------------------------------------------------
    // AVANCAR FASE
    // ----------------------------------------------------------
    case 'avancar_fase':
        $lote_id    = (int)($body['lote_id'] ?? 0);
        $fase_nova  = trim($body['fase_nova'] ?? '');
        $emb_nova   = $body['embalagem_nova'] ?? null;
        $espaco_novo= isset($body['espaco_id']) ? (int)$body['espaco_id'] : null;
        $motivo     = trim($body['motivo'] ?? '');
        $foto_b64   = $body['foto_base64'] ?? null;

        if (!$lote_id) responder(false, null, 'Lote inválido.');

        $stmt = db()->prepare("SELECT l.*,e.nivel_min_transplante
            FROM lotes l LEFT JOIN especies e ON e.id=l.especie_id WHERE l.id=?");
        $stmt->execute([$lote_id]);
        $lote = $stmt->fetch();
        if (!$lote) responder(false, null, 'Lote não encontrado.');
        if ($lote['tipo_lote'] === 'vazio')
            responder(false, null, 'Lote vazio: associe uma espécie antes de avançar de fase.');

        $nivel = (int)$eu['nivel'];
        $bloqueios = ['crescimento_saco'=>(int)($lote['nivel_min_transplante']??2),'rustificacao'=>4,'expedicao'=>4];
        if (isset($bloqueios[$fase_nova]) && $nivel < $bloqueios[$fase_nova])
            responder(false, null, "Nível insuficiente para '$fase_nova'. Necessário {$bloqueios[$fase_nova]}.");

        $foto_path = $foto_b64 ? _salvar_foto($foto_b64,"fase_{$lote_id}_".time()) : null;

        db()->prepare("UPDATE lotes SET
            fase_atual=?,embalagem_atual=COALESCE(?,embalagem_atual),espaco_id=COALESCE(?,espaco_id),
            data_germinacao=CASE WHEN ?='germinacao' AND data_germinacao IS NULL THEN CURDATE() ELSE data_germinacao END,
            status=CASE ? WHEN 'rustificacao' THEN 'rustificando' WHEN 'expedicao' THEN 'expedido' ELSE status END
            WHERE id=?")
            ->execute([$fase_nova,$emb_nova,$espaco_novo,$fase_nova,$fase_nova,$lote_id]);

        _registrar_historico($lote_id,$lote['fase_atual'],$fase_nova,
            $lote['embalagem_atual'],$emb_nova??$lote['embalagem_atual'],
            $lote['espaco_id'],$espaco_novo,$lote['qtd_atual'],$motivo??'Avanço de fase',$foto_path,$eu['id']);

        responder(true, ['mensagem'=>"Lote avançado para '$fase_nova'."]);
        break;

    default:
        responder(false, null, 'Ação desconhecida.');
}

// ============================================================
// FUNÇÕES INTERNAS
// ============================================================

/**
 * Cria lote vazio de reuso próximo ao lote de origem.
 * Procura célula livre no mesmo corredor, linha adjacente.
 */
function _criar_reuso(int $lote_origem_id, int $qtd, string $embalagem,
    ?int $corredor, ?int $linha, ?int $col, int $uid): void
{
    if ($qtd <= 0) return;

    $ano = date('Y');
    $stmt = db()->prepare("SELECT COUNT(*) FROM lotes WHERE tipo_lote='vazio' AND YEAR(criado_em)=?");
    $stmt->execute([$ano]);
    $seq    = (int)$stmt->fetchColumn() + 1;
    $codigo = sprintf('VAZ-%s-%03d', $ano, $seq);

    // Busca espécie de origem para herdar o nome
    $stmt = db()->prepare("SELECT l.especie_id, e.nome_popular FROM lotes l LEFT JOIN especies e ON e.id=l.especie_id WHERE l.id=?");
    $stmt->execute([$lote_origem_id]);
    $orig = $stmt->fetch();
    $nome_display = 'Reuso' . ($orig && $orig['nome_popular'] ? ' · '.$orig['nome_popular'] : '');

    // Posição próxima
    [$c_novo, $l_novo, $col_novo] = _encontrar_posicao_proxima($corredor, $linha, $col);

    db()->prepare("
        INSERT INTO lotes
            (codigo, tipo_lote, nome_display, especie_id, origem,
             lote_origem_id, enchido_por,
             corredor, grade_linha, grade_col,
             qtd_inicial, qtd_atual,
             fase_atual, embalagem_atual, status, criado_por)
        VALUES (?,?,?,NULL,'semente',?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $codigo, 'vazio', $nome_display,
        $lote_origem_id, $uid,
        $c_novo, $l_novo, $col_novo,
        $qtd, $qtd,
        'vazio', $embalagem, 'vazio', $uid,
    ]);
}

/**
 * Encontra a posição mais próxima disponível.
 * Grade: 6 corredores × 15 linhas × 4 colunas.
 * Percorre do ponto de origem para fora até achar célula livre
 * (livre = sem lote ativo ou com espaço lateral).
 */
function _encontrar_posicao_proxima(?int $corredor, ?int $linha, ?int $col): array {
    // Sem referência de origem: coloca no corredor 1, primeira posição livre
    if (!$corredor || !$linha || !$col) {
        $corredor = 1; $linha = 1; $col = 1;
    }

    // Busca posições ocupadas
    $stmt = db()->prepare("
        SELECT corredor, grade_linha, grade_col FROM lotes
        WHERE ativo=1 AND corredor IS NOT NULL
    ");
    $stmt->execute();
    $ocupadas = [];
    foreach ($stmt->fetchAll() as $r) {
        $ocupadas["{$r['corredor']}-{$r['grade_linha']}-{$r['grade_col']}"][] = 1;
    }

    // Percorre em espiral a partir da origem
    // Tenta mesma célula (cabe +1 lado a lado até 2 por célula)
    $chave = "$corredor-$linha-$col";
    if (!isset($ocupadas[$chave]) || count($ocupadas[$chave]) < 2) {
        return [$corredor, $linha, $col];
    }

    // Tenta células adjacentes: mesma linha, colunas vizinhas; depois linhas abaixo
    $ordens = [];
    for ($dl = 0; $dl <= 14; $dl++) {
        for ($dc = 0; $dc <= 3; $dc++) {
            $nl = $linha + $dl;
            $nc = $col + $dc;
            if ($nl > 15) break;
            if ($nc > 4)  $nc = $nc % 4 ?: 4;
            $ordens[] = [$corredor, $nl, $nc];
        }
    }
    // Tenta corredor adjacente se nenhum disponível
    for ($nl = 1; $nl <= 15; $nl++) {
        for ($nc = 1; $nc <= 4; $nc++) {
            $nc_adj = $corredor < 6 ? $corredor + 1 : $corredor - 1;
            $ordens[] = [$nc_adj, $nl, $nc];
        }
    }

    foreach ($ordens as [$c,$l,$col_t]) {
        $chave = "$c-$l-$col_t";
        if (!isset($ocupadas[$chave]) || count($ocupadas[$chave]) < 2) {
            return [$c, $l, $col_t];
        }
    }

    // Fallback: corredor 1, linha 1, col 1
    return [1, 1, 1];
}

function _registrar_historico(
    int $lote_id, ?string $fase_ant, string $fase_nova,
    ?string $emb_ant, ?string $emb_nova,
    ?int $esp_ant, ?int $esp_novo,
    ?int $qtd, ?string $motivo, ?string $foto, int $uid
): void {
    db()->prepare("
        INSERT INTO historico_fases
            (lote_id,fase_anterior,fase_nova,emb_anterior,emb_nova,
             espaco_anterior,espaco_novo,qtd_momento,motivo,foto,registrado_por)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([$lote_id,$fase_ant,$fase_nova,$emb_ant,$emb_nova,$esp_ant,$esp_novo,$qtd,$motivo,$foto,$uid]);
}

function _salvar_foto(string $b64, string $nome): ?string {
    if (str_contains($b64,',')) $b64 = explode(',',$b64)[1];
    $dados = base64_decode($b64);
    if (!$dados || strlen($dados) > 8*1024*1024) return null;
    $dir = __DIR__.'/../uploads/lotes/';
    if (!is_dir($dir)) mkdir($dir,0755,true);
    file_put_contents($dir.$nome.'.jpg',$dados);
    return 'uploads/lotes/'.$nome.'.jpg';
}

function _calcular_alertas(array $lote): array {
    $alertas = [];
    if (($lote['status']??'') === 'urgente')
        $alertas[] = ['tipo'=>'al','msg'=>'Atenção imediata necessária'];
    if (($lote['status']??'') === 'aguardando_agua')
        $alertas[] = ['tipo'=>'az','msg'=>'Aguardando irrigação'];
    if (!empty($lote['dias_rustificando']) && !empty($lote['rust_dias_min']))
        if ((int)$lote['dias_rustificando'] >= (int)$lote['rust_dias_min'])
            $alertas[] = ['tipo'=>'am','msg'=>"Rustificação completa ({$lote['dias_rustificando']}d)"];
    if (!empty($lote['modo_atencao']) && $lote['modo_atencao'] !== 'nenhum')
        $alertas[] = ['tipo'=>'am','msg'=>'Lote em modo de atenção'];
    if (!empty($lote['acoes_pendentes']) && (int)$lote['acoes_pendentes'] > 0)
        $alertas[] = ['tipo'=>'am','msg'=>"{$lote['acoes_pendentes']} ação(ões) pendente(s)"];
    return $alertas;
}

function _recalc_score_dia(int $uid, string $data): void {
    $s1 = db()->prepare("SELECT COALESCE(score_ponto,0) FROM pontos WHERE usuario_id=? AND data=?");
    $s1->execute([$uid,$data]);
    $sp = (int)$s1->fetchColumn();
    $s2 = db()->prepare("SELECT COALESCE(SUM(score_base+COALESCE(score_ajuste,0)),0),COUNT(*) FROM atividades WHERE usuario_id=? AND data=? AND ativo=1");
    $s2->execute([$uid,$data]);
    [$sa,$n] = $s2->fetch(PDO::FETCH_NUM);
    db()->prepare("INSERT INTO score_diario (usuario_id,data,score_total,atividades_n)
        VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE score_total=VALUES(score_total),atividades_n=VALUES(atividades_n)")
        ->execute([$uid,$data,$sp+(int)$sa,(int)$n]);
}
