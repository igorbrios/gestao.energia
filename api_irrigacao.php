<?php
// ============================================================
// api_irrigacao.php
// Gerencia zonas de irrigação automática e sol por célula,
// registro de eventos de rega (automática e manual).
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
    // CARREGAR ZONAS + URGÊNCIA HÍDRICA + NOTIFICAÇÕES
    // ----------------------------------------------------------
    case 'carregar_zonas':
        $zi = db()->query("
            SELECT z.id, z.nome, z.descricao,
                   JSON_ARRAYAGG(
                     JSON_OBJECT('corredor',c.corredor,'linha',c.grade_linha,'col',c.grade_col)
                   ) AS celulas
            FROM zonas_irrigacao z
            JOIN zona_irrigacao_celulas c ON c.zona_id = z.id
            WHERE z.ativa = 1
            GROUP BY z.id
        ")->fetchAll();

        $zs = db()->query("
            SELECT z.id, z.nome, z.nivel,
                   JSON_ARRAYAGG(
                     JSON_OBJECT('corredor',c.corredor,'linha',c.grade_linha,'col',c.grade_col)
                   ) AS celulas
            FROM zonas_sol z
            JOIN zona_sol_celulas c ON c.zona_id = z.id
            WHERE z.ativa = 1
            GROUP BY z.id
        ")->fetchAll();

        foreach ($zi as &$z) { $z['celulas'] = json_decode($z['celulas'], true) ?? []; }
        foreach ($zs as &$z) { $z['celulas'] = json_decode($z['celulas'], true) ?? []; }

        // Última irrigação de CADA lote (sem limite de horas — para calcular urgência)
        $stmt = db()->query("
            SELECT r.lote_id,
                   MAX(r.registrado_em) AS ultima_em,
                   (SELECT r2.tipo FROM registros_irrigacao r2
                    WHERE r2.lote_id = r.lote_id
                    ORDER BY r2.registrado_em DESC LIMIT 1) AS tipo
            FROM registros_irrigacao r
            GROUP BY r.lote_id
        ");
        $ultima_irrig = [];
        foreach ($stmt->fetchAll() as $r) {
            $ultima_irrig[$r['lote_id']] = [
                'ultima_em'  => $r['ultima_em'],
                'tipo'       => $r['tipo'],
                'horas_atras'=> (int)((time() - strtotime($r['ultima_em'])) / 3600),
            ];
        }

        // Célula → tem zona de irrigação automática?
        $stmt = db()->query("
            SELECT c.corredor, c.grade_linha, c.grade_col
            FROM zona_irrigacao_celulas c
            JOIN zonas_irrigacao z ON z.id = c.zona_id
            WHERE z.ativa = 1
        ");
        $celulas_irrigacao = [];
        foreach ($stmt->fetchAll() as $c) {
            $celulas_irrigacao["{$c['corredor']}-{$c['grade_linha']}-{$c['grade_col']}"] = true;
        }

        // Lotes ativos com dados de espécie para calcular urgência e replantio
        $stmt = db()->query("
            SELECT l.id, l.corredor, l.grade_linha, l.grade_col,
                   l.data_plantio_atual, l.data_semeadura, l.fase_atual, l.tipo_lote,
                   l.status,
                   e.resistencia_geral, e.necessidade_agua,
                   e.germ_dias_max
            FROM lotes l
            LEFT JOIN especies e ON e.id = l.especie_id
            WHERE l.ativo = 1 AND l.status NOT IN ('expedido','arquivado')
              AND l.tipo_lote != 'vazio'
        ");
        $lotes_ativos = $stmt->fetchAll();

        $urgencias   = []; // lote_id → {nivel, horas_atras, recomendacao}
        $replantios  = []; // lote_id → {dias_plantio, fase_critica}
        $notificacoes_gerar = [];

        foreach ($lotes_ativos as $lt) {
            $lid        = $lt['id'];
            $irrig      = $ultima_irrig[$lid] ?? null;
            $horas_atras= $irrig ? $irrig['horas_atras'] : 9999;
            $na_zona    = isset($celulas_irrigacao["{$lt['corredor']}-{$lt['grade_linha']}-{$lt['grade_col']}"]);

            // Sensibilidade da espécie (1=muito sensível, 6=resistente)
            $resist = (int)($lt['resistencia_geral'] ?? 3);

            // Prazos base em horas
            if ($na_zona) {
                // Irrigação automática: base 24h atenção, 48h urgente
                $h_atencao = 24;
                $h_urgente = 48;
            } else {
                // Rega manual: base 48h atenção, 72h urgente
                $h_atencao = 48;
                $h_urgente = 72;
            }

            // Ajuste por sensibilidade: resist ≤ 2 = sensível (reduz), resist ≥ 5 = resistente (aumenta)
            // Por ora apenas marcamos — ajuste fino será por configuração futura
            // (conforme solicitado, não definir valores agora)

            // Replantio recente
            $data_plantio = $lt['data_plantio_atual'] ?? $lt['data_semeadura'];
            $dias_plantio = $data_plantio
                ? (int)((time() - strtotime($data_plantio)) / 86400)
                : null;

            $fase_critica = null;
            if ($dias_plantio !== null && $dias_plantio <= 14) {
                if ($dias_plantio <= 2) {
                    $fase_critica = 'decisivo'; // 🌱🌱 requer rega 2x/dia
                    $h_atencao    = 12;
                    $h_urgente    = 18;
                    $replantios[$lid] = ['dias' => $dias_plantio, 'fase' => 'decisivo'];
                } elseif ($dias_plantio <= 7) {
                    $fase_critica = 'critico';  // 🌱 importante regar diariamente
                    $h_atencao    = 18;
                    $h_urgente    = 30;
                    $replantios[$lid] = ['dias' => $dias_plantio, 'fase' => 'critico'];
                } else {
                    $fase_critica = 'acompanhar';
                    $replantios[$lid] = ['dias' => $dias_plantio, 'fase' => 'acompanhar'];
                }
            }

            // Germinação < 1 mês (germ_dias_max <= 30 ou fase = germinacao recente)
            $em_germinacao = $lt['fase_atual'] === 'germinacao';
            $germ_rapida   = (int)($lt['germ_dias_max'] ?? 999) <= 30;
            if ($em_germinacao && ($germ_rapida || ($dias_plantio !== null && $dias_plantio <= 30))) {
                $replantios[$lid] = array_merge($replantios[$lid] ?? [], ['germinacao' => true]);
            }

            // Calcula urgência hídrica
            $nivel_urg = 'ok';
            if ($horas_atras >= $h_urgente) $nivel_urg = 'urgente';
            elseif ($horas_atras >= $h_atencao) $nivel_urg = 'atencao';

            $urgencias[$lid] = [
                'nivel'       => $nivel_urg,
                'horas_atras' => $horas_atras === 9999 ? null : $horas_atras,
                'ultima_em'   => $irrig ? $irrig['ultima_em'] : null,
                'tipo'        => $irrig ? $irrig['tipo'] : null,
                'na_zona'     => $na_zona,
                'h_atencao'   => $h_atencao,
                'h_urgente'   => $h_urgente,
                'recomendacao_2x' => $fase_critica === 'decisivo',
            ];

            // Gera notificações pendentes
            // Popup plantio recente: 3h após irrigação nos primeiros 2 dias
            if ($fase_critica === 'decisivo' && $irrig && $irrig['horas_atras'] >= 3) {
                $notificacoes_gerar[] = [
                    'lote_id'  => $lid,
                    'tipo'     => 'plantio_recente',
                    'mensagem' => "Lembre-se de molhar as mudas recém-plantadas! Já se passaram {$irrig['horas_atras']}h desde a última rega.",
                ];
            }

            // Popup espécie morrendo: após urgente por 6h+
            if ($nivel_urg === 'urgente' && $horas_atras >= ($h_urgente + 6)) {
                $notificacoes_gerar[] = [
                    'lote_id'  => $lid,
                    'tipo'     => 'especie_morrendo',
                    'mensagem' => "Atenção: há mais de {$horas_atras}h sem molhar. Risco de perda!",
                ];
            }
        }

        // Salva notificações novas (sem duplicar — verifica última nas últimas 6h)
        $stmt_check = db()->prepare("
            SELECT id FROM notificacoes_lote
            WHERE lote_id=? AND tipo=? AND enviada_em >= NOW() - INTERVAL 6 HOUR
        ");
        $stmt_ins = db()->prepare("
            INSERT INTO notificacoes_lote (lote_id, tipo, mensagem)
            VALUES (?,?,?)
        ");
        foreach ($notificacoes_gerar as $n) {
            $stmt_check->execute([$n['lote_id'], $n['tipo']]);
            if (!$stmt_check->fetch()) {
                $stmt_ins->execute([$n['lote_id'], $n['tipo'], $n['mensagem']]);
            }
        }

        // Notificações não lidas para o usuário atual
        $stmt = db()->prepare("
            SELECT n.*, l.nome_display, e.nome_popular
            FROM notificacoes_lote n
            JOIN lotes l ON l.id = n.lote_id
            LEFT JOIN especies e ON e.id = l.especie_id
            WHERE n.lida = 0
              AND (n.usuario_id IS NULL OR n.usuario_id = ?)
              AND n.enviada_em >= NOW() - INTERVAL 24 HOUR
            ORDER BY n.enviada_em DESC
            LIMIT 10
        ");
        $stmt->execute([$eu['id']]);
        $notificacoes = $stmt->fetchAll();

        responder(true, [
            'zonas_irrigacao' => $zi,
            'zonas_sol'       => $zs,
            'urgencias'       => $urgencias,
            'replantios'      => $replantios,
            'ultima_irrig'    => $ultima_irrig,
            'notificacoes'    => $notificacoes,
        ]);
        break;

    // ----------------------------------------------------------
    // SALVAR ZONA DE IRRIGAÇÃO (nível 4+)
    // Cria ou atualiza. Substitui as células da zona.
    // ----------------------------------------------------------
    case 'salvar_zona_irrigacao':
        exigir_nivel($eu, 4);

        $id      = (int)($body['id'] ?? 0);
        $nome    = trim($body['nome'] ?? '');
        $desc    = trim($body['descricao'] ?? '') ?: null;
        $celulas = $body['celulas'] ?? []; // [{corredor,linha,col}, ...]

        if (!$nome)    responder(false, null, 'Nome é obrigatório.');
        if (!$celulas) responder(false, null, 'Selecione ao menos uma célula.');

        if ($id) {
            db()->prepare("UPDATE zonas_irrigacao SET nome=?, descricao=? WHERE id=?")
                ->execute([$nome, $desc, $id]);
            db()->prepare("DELETE FROM zona_irrigacao_celulas WHERE zona_id=?")
                ->execute([$id]);
        } else {
            db()->prepare("INSERT INTO zonas_irrigacao (nome, descricao, criado_por) VALUES (?,?,?)")
                ->execute([$nome, $desc, $eu['id']]);
            $id = (int)db()->lastInsertId();
        }

        $stmt = db()->prepare("
            INSERT IGNORE INTO zona_irrigacao_celulas (zona_id, corredor, grade_linha, grade_col)
            VALUES (?,?,?,?)
        ");
        foreach ($celulas as $c) {
            $stmt->execute([$id, (int)$c['corredor'], (int)$c['linha'], (int)$c['col']]);
        }

        $n_celulas = count($celulas);
        responder(true, ['id' => $id, 'mensagem' => "Zona '{$nome}' salva com {$n_celulas} célula(s).", 'n_celulas' => $n_celulas]);
        break;

    // ----------------------------------------------------------
    // SALVAR ZONA DE SOL (nível 4+)
    // ----------------------------------------------------------
    case 'salvar_zona_sol':
        exigir_nivel($eu, 4);

        $id      = (int)($body['id'] ?? 0);
        $nome    = trim($body['nome'] ?? '');
        $nivel_z = $body['nivel'] ?? 'pleno';
        $celulas = $body['celulas'] ?? [];

        if (!$nome)    responder(false, null, 'Nome é obrigatório.');
        if (!$celulas) responder(false, null, 'Selecione ao menos uma célula.');
        if (!in_array($nivel_z, ['pleno','meia_sombra','sombra']))
            responder(false, null, 'Nível de sol inválido.');

        if ($id) {
            db()->prepare("UPDATE zonas_sol SET nome=?, nivel=? WHERE id=?")
                ->execute([$nome, $nivel_z, $id]);
            db()->prepare("DELETE FROM zona_sol_celulas WHERE zona_id=?")
                ->execute([$id]);
        } else {
            db()->prepare("INSERT INTO zonas_sol (nome, nivel, criado_por) VALUES (?,?,?)")
                ->execute([$nome, $nivel_z, $eu['id']]);
            $id = (int)db()->lastInsertId();
        }

        $stmt = db()->prepare("
            INSERT IGNORE INTO zona_sol_celulas (zona_id, corredor, grade_linha, grade_col)
            VALUES (?,?,?,?)
        ");
        foreach ($celulas as $c) {
            $stmt->execute([$id, (int)$c['corredor'], (int)$c['linha'], (int)$c['col']]);
        }

        responder(true, ['id' => $id, 'mensagem' => "Zona de sol '{$nome}' salva."]);
        break;

    // ----------------------------------------------------------
    // DESATIVAR ZONA
    // ----------------------------------------------------------
    case 'desativar_zona':
        exigir_nivel($eu, 4);
        $tipo = $body['tipo'] ?? 'irrigacao'; // irrigacao | sol
        $id   = (int)($body['id'] ?? 0);
        $tabela = $tipo === 'sol' ? 'zonas_sol' : 'zonas_irrigacao';
        db()->prepare("UPDATE {$tabela} SET ativa=0 WHERE id=?")->execute([$id]);
        responder(true, ['mensagem' => 'Zona desativada.']);
        break;

    // ----------------------------------------------------------
    // LIGAR IRRIGAÇÃO AUTOMÁTICA (nível 2+)
    // Marca todos os lotes das zonas de irrigação como molhados.
    // Retorna quantos lotes foram afetados.
    // ----------------------------------------------------------
    case 'ligar_irrigacao':
        if ((int)$eu['nivel'] < 2) responder(false, null, 'Nível mínimo 2.');

        $zona_ids = $body['zona_ids'] ?? []; // quais zonas ligar (vazio = todas)
        $obs      = trim($body['obs'] ?? '') ?: null;

        // Busca células das zonas ativas (ou das selecionadas)
        if ($zona_ids) {
            $placeholders = implode(',', array_fill(0, count($zona_ids), '?'));
            $stmt = db()->prepare("
                SELECT zona_id, corredor, grade_linha, grade_col
                FROM zona_irrigacao_celulas
                WHERE zona_id IN ($placeholders)
            ");
            $stmt->execute(array_map('intval', $zona_ids));
        } else {
            $stmt = db()->query("
                SELECT c.zona_id, c.corredor, c.grade_linha, c.grade_col
                FROM zona_irrigacao_celulas c
                JOIN zonas_irrigacao z ON z.id = c.zona_id
                WHERE z.ativa = 1
            ");
        }
        $celulas = $stmt->fetchAll();

        if (!$celulas) responder(false, null, 'Nenhuma zona de irrigação definida. Configure no mapa primeiro.');

        // Busca lotes que estão nessas células
        $afetados = 0;
        $stmt_lotes = db()->prepare("
            SELECT id FROM lotes
            WHERE corredor=? AND grade_linha=? AND grade_col=?
              AND ativo=1 AND status NOT IN ('expedido','arquivado')
        ");
        $stmt_reg = db()->prepare("
            INSERT INTO registros_irrigacao (lote_id, tipo, zona_id, registrado_por, obs)
            VALUES (?,?,?,?,?)
        ");

        $zona_por_celula = [];
        foreach ($celulas as $c) {
            $zona_por_celula["{$c['corredor']}-{$c['grade_linha']}-{$c['grade_col']}"] = $c['zona_id'];
        }

        foreach ($celulas as $c) {
            $stmt_lotes->execute([$c['corredor'], $c['grade_linha'], $c['grade_col']]);
            foreach ($stmt_lotes->fetchAll() as $lote) {
                $stmt_reg->execute([$lote['id'], 'automatica', $c['zona_id'], $eu['id'], $obs]);
                $afetados++;
            }
        }

        // Registra como atividade
        db()->prepare("
            INSERT INTO atividades (usuario_id, data, tipo, descricao, score_base, nivel_criador)
            VALUES (?,CURDATE(),'irrigacao',?,25,?)
        ")->execute([$eu['id'], "Irrigação automática ligada — {$afetados} lote(s) molhado(s)", $eu['nivel']]);

        responder(true, [
            'afetados' => $afetados,
            'mensagem' => "✓ {$afetados} lote(s) marcado(s) como molhados.",
        ]);
        break;

    // ----------------------------------------------------------
    // REGAR MANUALMENTE (nível 1+)
    // Funcionário informa quais lotes regou com regador.
    // ----------------------------------------------------------
    case 'regar_manual':
        $lote_ids = $body['lote_ids'] ?? [];
        $obs      = trim($body['obs'] ?? '') ?: null;

        if (!$lote_ids) responder(false, null, 'Selecione ao menos um lote.');

        $stmt = db()->prepare("
            INSERT INTO registros_irrigacao (lote_id, tipo, zona_id, registrado_por, obs)
            VALUES (?,?,NULL,?,?)
        ");
        foreach ($lote_ids as $lid) {
            $stmt->execute([(int)$lid, 'manual', $eu['id'], $obs]);
        }

        $n = count($lote_ids);
        db()->prepare("
            INSERT INTO atividades (usuario_id, data, tipo, descricao, score_base, nivel_criador)
            VALUES (?,CURDATE(),'irrigacao',?,20,?)
        ")->execute([$eu['id'], "Rega manual — {$n} lote(s)", $eu['nivel']]);

        responder(true, [
            'afetados' => $n,
            'mensagem' => "✓ {$n} lote(s) regado(s) com regador.",
        ]);
        break;

    // ----------------------------------------------------------
    // HISTÓRICO DE IRRIGAÇÃO DE UM LOTE
    // ----------------------------------------------------------
    case 'historico_lote':
        $lote_id = (int)($body['lote_id'] ?? 0);
        if (!$lote_id) responder(false, null, 'ID inválido.');

        $stmt = db()->prepare("
            SELECT r.*, u.apelido AS registrado_por_nome,
                   z.nome AS zona_nome,
                   TIMESTAMPDIFF(HOUR, r.registrado_em, NOW()) AS horas_atras
            FROM registros_irrigacao r
            JOIN usuarios u ON u.id = r.registrado_por
            LEFT JOIN zonas_irrigacao z ON z.id = r.zona_id
            WHERE r.lote_id = ?
            ORDER BY r.registrado_em DESC
            LIMIT 20
        ");
        $stmt->execute([$lote_id]);
        responder(true, ['historico' => $stmt->fetchAll()]);
        break;

    // ----------------------------------------------------------
    // MARCAR NOTIFICAÇÃO COMO LIDA
    // ----------------------------------------------------------
    case 'ler_notificacao':
        $id = (int)($body['id'] ?? 0);
        db()->prepare("UPDATE notificacoes_lote SET lida=1 WHERE id=?")
            ->execute([$id]);
        responder(true, ['mensagem' => 'Notificação lida.']);
        break;

    default:
        responder(false, null, 'Ação desconhecida.');
}
