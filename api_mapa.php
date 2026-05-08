<?php
// ============================================================
// api_mapa.php  (v4)
// Grade por corredores (abas). Cada corredor tem 4 cols × 15 linhas.
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
    // CARREGAR MAPA COMPLETO
    // Retorna lotes agrupados por corredor, com posição grade
    // ----------------------------------------------------------
    case 'carregar':
        $stmt = db()->query("
            SELECT
                l.id, l.codigo, l.tipo_lote, l.status, l.fase_atual,
                l.embalagem_atual, l.qtd_atual, l.qtd_inicial,
                l.taxa_germinacao, l.modo_atencao,
                l.corredor, l.grade_linha, l.grade_col,
                COALESCE(l.nome_display, e.nome_popular, 'Sem nome') AS nome_exibicao,
                e.nome_popular, e.necessidade_sol AS esp_sol,
                e.resistencia_geral AS esp_resist,
                r.data_inicio AS rust_inicio,
                DATEDIFF(CURDATE(), r.data_inicio) AS dias_rustificando,
                e.rust_dias_min,
                (SELECT COUNT(*) FROM visita_acoes va WHERE va.lote_id=l.id AND va.checado=0) AS acoes_pendentes
            FROM lotes l
            LEFT JOIN especies e ON e.id = l.especie_id
            LEFT JOIN rustificacao r ON r.lote_id=l.id AND r.data_fim IS NULL
            WHERE l.ativo=1 AND l.status NOT IN ('expedido','arquivado')
            ORDER BY l.corredor, l.grade_linha, l.grade_col
        ");
        $todos_lotes = $stmt->fetchAll();

        // Agrupa por corredor
        $por_corredor = [];
        $sem_corredor = [];
        foreach ($todos_lotes as $lt) {
            if ($lt['corredor']) {
                $por_corredor[$lt['corredor']][] = $lt;
            } else {
                $sem_corredor[] = $lt;
            }
        }

        // Zonas de insolação
        $stmt = db()->query("SELECT id, nome, nivel, grade_celulas FROM zonas_insolacao WHERE ativa=1");
        $zonas = $stmt->fetchAll();
        foreach ($zonas as &$z) {
            $z['grade_celulas'] = json_decode($z['grade_celulas'], true) ?? [];
        }

        // Sugestões
        $sugestoes = _gerar_sugestoes($todos_lotes);

        responder(true, [
            'por_corredor' => $por_corredor,
            'sem_corredor' => $sem_corredor,
            'zonas'        => $zonas,
            'sugestoes'    => $sugestoes,
            'n_corredores' => max(6, count($por_corredor) > 0 ? max(array_keys($por_corredor)) : 1),
        ]);
        break;

    // ----------------------------------------------------------
    // MOVER LOTE PARA POSIÇÃO NO MAPA
    // ----------------------------------------------------------
    case 'mover':
        if ((int)$eu['nivel'] < 2) responder(false, null, 'Nível mínimo 2.');

        $lote_id  = (int)($body['lote_id'] ?? 0);
        $corredor = (int)($body['corredor'] ?? 1);
        $linha    = (int)($body['linha'] ?? 1);
        $col      = (int)($body['col'] ?? 1);

        if (!$lote_id) responder(false, null, 'Lote inválido.');

        $stmt = db()->prepare("SELECT qtd_atual, espaco_id FROM lotes WHERE id=? AND ativo=1");
        $stmt->execute([$lote_id]);
        $lote = $stmt->fetch();
        if (!$lote) responder(false, null, 'Lote não encontrado.');

        db()->prepare("UPDATE lotes SET corredor=?,grade_linha=?,grade_col=? WHERE id=?")
            ->execute([$corredor,$linha,$col,$lote_id]);

        db()->prepare("INSERT INTO movimentacoes (lote_id,tipo_mov,data,quantidade,motivo,registrado_por)
            VALUES (?,?,CURDATE(),?,?,?)")
            ->execute([$lote_id,'movimentacao',$lote['qtd_atual'],
                "Movido para corredor $corredor, linha $linha, col $col",$eu['id']]);

        responder(true, ['mensagem'=>'Lote movido.']);
        break;

    // ----------------------------------------------------------
    // MINI-FICHA
    // ----------------------------------------------------------
    case 'mini_ficha':
        $lote_id = (int)($body['lote_id'] ?? 0);
        if (!$lote_id) responder(false, null, 'ID inválido.');

        $stmt = db()->prepare("
            SELECT l.*,
                   COALESCE(l.nome_display, e.nome_popular, 'Sem nome') AS nome_exibicao,
                   e.nome_popular, e.nome_cientifico, e.rust_dias_min,
                   ev.nome AS espaco_nome,
                   r.data_inicio AS rust_inicio,
                   DATEDIFF(CURDATE(), r.data_inicio) AS dias_rustificando,
                   lo.codigo AS lote_origem_codigo,
                   u_enc.apelido AS enchido_por_nome,
                   (SELECT COUNT(*) FROM visita_acoes va WHERE va.lote_id=l.id AND va.checado=0) AS acoes_pendentes
            FROM lotes l
            LEFT JOIN especies e ON e.id=l.especie_id
            LEFT JOIN espacos_viveiro ev ON ev.id=l.espaco_id
            LEFT JOIN rustificacao r ON r.lote_id=l.id AND r.data_fim IS NULL
            LEFT JOIN lotes lo ON lo.id=l.lote_origem_id
            LEFT JOIN usuarios u_enc ON u_enc.id=l.enchido_por
            WHERE l.id=? AND l.ativo=1
        ");
        $stmt->execute([$lote_id]);
        $lote = $stmt->fetch();
        if (!$lote) responder(false, null, 'Lote não encontrado.');

        $alertas = [];
        if ($lote['status'] === 'urgente')
            $alertas[] = ['tipo'=>'al','msg'=>'Atenção imediata'];
        if ($lote['status'] === 'aguardando_agua')
            $alertas[] = ['tipo'=>'az','msg'=>'Aguardando irrigação'];
        if (!empty($lote['dias_rustificando']) && !empty($lote['rust_dias_min']))
            if ((int)$lote['dias_rustificando'] >= (int)$lote['rust_dias_min'])
                $alertas[] = ['tipo'=>'am','msg'=>"Rustificação completa ({$lote['dias_rustificando']}d)"];
        if ($lote['modo_atencao'] !== 'nenhum')
            $alertas[] = ['tipo'=>'am','msg'=>'Em modo de atenção'];
        if ((int)$lote['acoes_pendentes'] > 0)
            $alertas[] = ['tipo'=>'am','msg'=>"{$lote['acoes_pendentes']} ação(ões) pendente(s)"];

        $nivel = (int)$eu['nivel'];
        responder(true, [
            'lote'    => $lote,
            'alertas' => $alertas,
            'permissoes' => [
                'visitar'          => true,
                'mover'            => $nivel >= 2,
                'associar_especie' => $nivel >= 3 && $lote['tipo_lote'] === 'vazio',
                'expedicao'        => $nivel >= 4,
            ],
        ]);
        break;

    // ----------------------------------------------------------
    // SALVAR ZONA DE INSOLAÇÃO
    // ----------------------------------------------------------
    case 'salvar_zona':
        exigir_nivel($eu, 4);
        $id      = (int)($body['id'] ?? 0);
        $nome    = trim($body['nome'] ?? '');
        $nivel_z = $body['nivel'] ?? 'atencao';
        $celulas = $body['grade_celulas'] ?? [];
        $obs     = trim($body['obs'] ?? '') ?: null;

        if (!$nome)    responder(false, null, 'Nome é obrigatório.');
        if (!$celulas) responder(false, null, 'Selecione ao menos uma posição.');

        $json = json_encode($celulas);
        if ($id) {
            db()->prepare("UPDATE zonas_insolacao SET nome=?,nivel=?,grade_celulas=?,obs=? WHERE id=?")
                ->execute([$nome,$nivel_z,$json,$obs,$id]);
        } else {
            db()->prepare("INSERT INTO zonas_insolacao (nome,nivel,grade_celulas,obs,criado_por) VALUES (?,?,?,?,?)")
                ->execute([$nome,$nivel_z,$json,$obs,$eu['id']]);
            $id = (int)db()->lastInsertId();
        }
        responder(true, ['id'=>$id,'mensagem'=>'Zona salva.']);
        break;

    // ----------------------------------------------------------
    // DESATIVAR ZONA
    // ----------------------------------------------------------
    case 'desativar_zona':
        exigir_nivel($eu, 4);
        db()->prepare("UPDATE zonas_insolacao SET ativa=0 WHERE id=?")
            ->execute([(int)($body['id']??0)]);
        responder(true, ['mensagem'=>'Zona desativada.']);
        break;

    // ----------------------------------------------------------
    // ATUALIZAR STATUS VIA MAPA
    // ----------------------------------------------------------
    case 'atualizar_status':
        exigir_nivel($eu, 4);
        $lote_id = (int)($body['lote_id'] ?? 0);
        $status  = $body['status'] ?? '';
        if (!in_array($status,['ativo','urgente','aguardando_agua','pronto']))
            responder(false, null, 'Status inválido.');
        db()->prepare("UPDATE lotes SET status=? WHERE id=?")->execute([$status,$lote_id]);
        responder(true, ['mensagem'=>'Status atualizado.']);
        break;

    default:
        responder(false, null, 'Ação desconhecida.');
}

function _gerar_sugestoes(array $lotes): array {
    $s = [];
    $atencao = array_filter($lotes, fn($l) => $l['modo_atencao'] !== 'nenhum');
    if (count($atencao)) $s[] = ['tipo'=>'am','emoji'=>'👁️','titulo'=>count($atencao).' em atenção','texto'=>'Requerem acompanhamento.','lote_id'=>null];
    foreach ($lotes as $l) {
        if ($l['status']==='pronto') { $s[] = ['tipo'=>'am','emoji'=>'📦','titulo'=>'Pronto: '.$l['nome_exibicao'],'texto'=>"{$l['qtd_atual']} mudas.","lote_id"=>$l['id']]; break; }
    }
    foreach ($lotes as $l) {
        if (!empty($l['dias_rustificando']) && !empty($l['rust_dias_min']) && (int)$l['dias_rustificando']>=(int)$l['rust_dias_min'])
            { $s[] = ['tipo'=>'am','emoji'=>'☀️','titulo'=>'Rustificação completa','texto'=>"{$l['nome_exibicao']} — {$l['dias_rustificando']}d.",'lote_id'=>$l['id']]; break; }
    }
    return array_slice($s, 0, 2);
}
