<?php
// ============================================================
// api_relatorio.php  (v2)
// O que faz: Relatório mensal para a Energisa MS.
//            AJUSTES v2:
//            - Exclui 'reaproveitamento' da contagem de perdas
//            - Inclui visitas do período no relatório
//            - Perdas por causa vão para o JSON do relatório
//            - Inclui lotes com origem (matriz_origem)
// Depende de: config.php, db_relatorio.sql, db_lotes.sql (v2),
//             db_ponto.sql, db_especies.sql (v2)
// Contrato: Energisa MS · 2026007601
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
    // PRÉVIA
    // ----------------------------------------------------------
    case 'previa':
        exigir_nivel($eu, 4);

        $ini = $body['periodo_inicio'] ?? date('Y-m-01');
        $fim = $body['periodo_fim']    ?? date('Y-m-t');

        $dados = _consolidar_periodo($ini, $fim);

        $avisos = [];
        if ($dados['total_expedidas'] === 0)
            $avisos[] = 'Nenhuma expedição registrada no período.';
        if (empty($dados['por_especie']))
            $avisos[] = 'Nenhum lote ativo no período.';
        if ($dados['horas_trabalhadas'] == 0)
            $avisos[] = 'Nenhum ponto de trabalho registrado no período.';

        responder(true, array_merge($dados, ['avisos' => $avisos]));
        break;

    // ----------------------------------------------------------
    // CRIAR RELATÓRIO
    // ----------------------------------------------------------
    case 'criar':
        exigir_nivel($eu, 4);

        $ini    = $body['periodo_inicio'] ?? date('Y-m-01');
        $fim    = $body['periodo_fim']    ?? date('Y-m-t');
        $titulo = trim($body['titulo'] ?? 'Relatório Mensal — '.date('m/Y', strtotime($ini)));
        $obs    = trim($body['obs_gestor'] ?? '') ?: null;
        $resp   = trim($body['responsavel_tecnico'] ?? '') ?: null;

        $dados = _consolidar_periodo($ini, $fim);

        db()->prepare("
            INSERT INTO relatorios
                (titulo, periodo_inicio, periodo_fim, obs_gestor, responsavel_tecnico,
                 total_mudas_produzidas, total_mudas_expedidas, total_perdas,
                 taxa_aproveitamento, horas_trabalhadas, gerado_por, gerado_em)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
        ")->execute([
            $titulo, $ini, $fim, $obs, $resp,
            $dados['total_producao'],
            $dados['total_expedidas'],
            $dados['total_perdas'],
            $dados['taxa_aproveitamento'],
            $dados['horas_trabalhadas'],
            $eu['id'],
        ]);
        $rel_id = (int)db()->lastInsertId();

        foreach ($dados['por_especie'] as $esp) {
            db()->prepare("
                INSERT INTO relatorio_especies
                    (relatorio_id, especie_id, lotes_ativos, mudas_em_producao,
                     mudas_expedidas, mudas_perdidas, taxa_germinacao, taxa_perda, taxa_aproveitamento)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                $rel_id, $esp['especie_id'],
                $esp['lotes_ativos'], $esp['mudas_em_producao'],
                $esp['mudas_expedidas'], $esp['mudas_perdidas'],
                $esp['taxa_germinacao'], $esp['taxa_perda'], $esp['taxa_aproveitamento'],
            ]);
        }

        foreach ($dados['expedicoes'] as $exp) {
            db()->prepare("
                INSERT INTO relatorio_expedicoes
                    (relatorio_id, expedicao_id, lote_codigo, especie_nome,
                     quantidade, destino, data, embalagem)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute([
                $rel_id, $exp['id'], $exp['lote_codigo'], $exp['especie_nome'],
                $exp['quantidade'], $exp['destino'], $exp['data'], $exp['embalagem'],
            ]);
        }

        responder(true, [
            'id'      => $rel_id,
            'titulo'  => $titulo,
            'mensagem'=> 'Relatório criado.',
        ]);
        break;

    // ----------------------------------------------------------
    // BUSCAR RELATÓRIO COMPLETO  (v2)
    // ----------------------------------------------------------
    case 'buscar':
        exigir_nivel($eu, 4);

        $id = (int)($body['id'] ?? 0);
        if (!$id) responder(false, null, 'ID inválido.');

        $stmt = db()->prepare("
            SELECT r.*, u.nome AS gestor_nome, u.apelido AS gestor_apelido
            FROM relatorios r
            JOIN usuarios u ON u.id = r.gerado_por
            WHERE r.id = ? AND r.ativo = 1
        ");
        $stmt->execute([$id]);
        $rel = $stmt->fetch();
        if (!$rel) responder(false, null, 'Relatório não encontrado.');

        // Por espécie
        $stmt = db()->prepare("
            SELECT re.*, e.nome_popular, e.nome_cientifico, e.codigo,
                   e.altura_maxima_cm, e.sucessao
            FROM relatorio_especies re
            JOIN especies e ON e.id = re.especie_id
            WHERE re.relatorio_id = ?
            ORDER BY re.mudas_expedidas DESC
        ");
        $stmt->execute([$id]);
        $por_especie = $stmt->fetchAll();

        // Expedições
        $stmt = db()->prepare("
            SELECT re.*, l.matriz_origem
            FROM relatorio_expedicoes re
            LEFT JOIN lotes l ON l.codigo = re.lote_codigo
            WHERE re.relatorio_id = ?
            ORDER BY re.data ASC
        ");
        $stmt->execute([$id]);
        $expedicoes = $stmt->fetchAll();

        // Fotos
        $stmt = db()->prepare("
            SELECT * FROM relatorio_fotos
            WHERE relatorio_id = ? ORDER BY ordem ASC
        ");
        $stmt->execute([$id]);
        $fotos = $stmt->fetchAll();

        // Atividades do período
        $ativs = _atividades_resumo($rel['periodo_inicio'], $rel['periodo_fim']);

        // Perdas por causa (v2 — agora vai pro relatório)
        $stmt = db()->prepare("
            SELECT causa_perda, SUM(quantidade) AS total,
                   COUNT(*) AS ocorrencias
            FROM movimentacoes
            WHERE tipo_mov = 'perda'
              AND data BETWEEN ? AND ?
              AND ativo = 1
              AND causa_perda IS NOT NULL
            GROUP BY causa_perda
            ORDER BY total DESC
        ");
        $stmt->execute([$rel['periodo_inicio'], $rel['periodo_fim']]);
        $perdas_causa = $stmt->fetchAll();

        // Visitas registradas no período (resumo)
        $stmt = db()->prepare("
            SELECT
                COUNT(*) AS total_visitas,
                SUM(estado_geral = 'problema') AS problemas,
                SUM(estado_geral = 'atencao')  AS atencoes,
                SUM(estado_geral = 'ok')        AS oks,
                SUM(estado_geral = 'muito_bem') AS muito_bems,
                COUNT(DISTINCT registrado_por)  AS funcionarios_envolvidos
            FROM visitas_lote
            WHERE DATE(criado_em) BETWEEN ? AND ?
        ");
        $stmt->execute([$rel['periodo_inicio'], $rel['periodo_fim']]);
        $visitas_resumo = $stmt->fetch();

        // Lotes que saíram do modo atenção no período (resolvidos)
        // (estimativa: lotes que foram expedidos com ocorrência anterior)
        $stmt = db()->prepare("
            SELECT l.codigo, e.nome_popular AS especie_nome,
                   l.taxa_aproveitamento, l.qtd_expedida
            FROM lotes l
            JOIN especies e ON e.id = l.especie_id
            WHERE l.data_expedicao BETWEEN ? AND ?
              AND l.ativo = 1
        ");
        $stmt->execute([$rel['periodo_inicio'], $rel['periodo_fim']]);
        $lotes_expedidos = $stmt->fetchAll();

        responder(true, [
            'relatorio'      => $rel,
            'por_especie'    => $por_especie,
            'expedicoes'     => $expedicoes,
            'fotos'          => $fotos,
            'atividades'     => $ativs,
            'perdas_causa'   => $perdas_causa,
            'visitas_resumo' => $visitas_resumo,
            'lotes_expedidos'=> $lotes_expedidos,
        ]);
        break;

    // ----------------------------------------------------------
    // LISTAR RELATÓRIOS
    // ----------------------------------------------------------
    case 'listar':
        exigir_nivel($eu, 4);

        $stmt = db()->query("
            SELECT r.id, r.titulo, r.periodo_inicio, r.periodo_fim, r.status,
                   r.total_mudas_expedidas, r.taxa_aproveitamento, r.gerado_em,
                   u.apelido AS gestor_nome
            FROM relatorios r
            JOIN usuarios u ON u.id = r.gerado_por
            WHERE r.ativo = 1
            ORDER BY r.periodo_inicio DESC
            LIMIT 24
        ");
        responder(true, ['relatorios' => $stmt->fetchAll()]);
        break;

    // ----------------------------------------------------------
    // ATUALIZAR OBS / RESPONSÁVEL (só rascunho)
    // ----------------------------------------------------------
    case 'atualizar':
        exigir_nivel($eu, 4);

        $id   = (int)($body['id'] ?? 0);
        $obs  = trim($body['obs_gestor'] ?? '') ?: null;
        $resp = trim($body['responsavel_tecnico'] ?? '') ?: null;

        $stmt = db()->prepare("SELECT status FROM relatorios WHERE id=?");
        $stmt->execute([$id]);
        $rel = $stmt->fetch();
        if (!$rel) responder(false, null, 'Relatório não encontrado.');
        if ($rel['status'] === 'finalizado') responder(false, null, 'Relatório finalizado não pode ser editado.');

        db()->prepare("UPDATE relatorios SET obs_gestor=?, responsavel_tecnico=? WHERE id=?")
            ->execute([$obs, $resp, $id]);

        responder(true, ['mensagem' => 'Relatório atualizado.']);
        break;

    // ----------------------------------------------------------
    // ADICIONAR FOTO
    // ----------------------------------------------------------
    case 'adicionar_foto':
        exigir_nivel($eu, 4);

        $id       = (int)($body['id'] ?? 0);
        $foto_b64 = $body['foto_base64'] ?? '';
        $legenda  = trim($body['legenda'] ?? '') ?: null;
        $tipo     = $body['tipo'] ?? 'outro';
        $ordem    = (int)($body['ordem'] ?? 0);

        if (!$id || empty($foto_b64)) responder(false, null, 'ID e foto obrigatórios.');

        if (str_contains($foto_b64, ',')) $foto_b64 = explode(',', $foto_b64)[1];
        $dados_foto = base64_decode($foto_b64);
        if (!$dados_foto || strlen($dados_foto) > 8*1024*1024)
            responder(false, null, 'Foto inválida ou muito grande (máx 8MB).');

        $dir = __DIR__.'/../uploads/relatorios/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $arq  = "rel_{$id}_".time().'.jpg';
        file_put_contents($dir.$arq, $dados_foto);
        $path = 'uploads/relatorios/'.$arq;

        db()->prepare("
            INSERT INTO relatorio_fotos (relatorio_id, foto_path, legenda, tipo, ordem)
            VALUES (?,?,?,?,?)
        ")->execute([$id, $path, $legenda, $tipo, $ordem]);

        responder(true, ['foto_path' => $path, 'mensagem' => 'Foto adicionada.']);
        break;

    // ----------------------------------------------------------
    // FINALIZAR (nível 5)
    // ----------------------------------------------------------
    case 'finalizar':
        exigir_nivel($eu, 5);

        $id = (int)($body['id'] ?? 0);
        $stmt = db()->prepare("SELECT status FROM relatorios WHERE id=?");
        $stmt->execute([$id]);
        $rel = $stmt->fetch();
        if (!$rel) responder(false, null, 'Relatório não encontrado.');
        if ($rel['status'] === 'finalizado') responder(false, null, 'Já finalizado.');

        db()->prepare("UPDATE relatorios SET status='finalizado' WHERE id=?")
            ->execute([$id]);

        responder(true, ['mensagem' => 'Relatório finalizado. Pronto para exportar.']);
        break;

    default:
        responder(false, null, 'Ação desconhecida.');
}

// ============================================================
// FUNÇÕES INTERNAS
// ============================================================

function _consolidar_periodo(string $ini, string $fim): array {
    $dados = [
        'periodo_inicio'      => $ini,
        'periodo_fim'         => $fim,
        'total_producao'      => 0,
        'total_expedidas'     => 0,
        'total_perdas'        => 0,   // exclui reaproveitamento
        'taxa_aproveitamento' => null,
        'horas_trabalhadas'   => 0,
        'por_especie'         => [],
        'expedicoes'          => [],
        'perdas_por_causa'    => [],
        'visitas_resumo'      => null,
        'lotes_ativos_periodo'=> 0,
    ];

    // Expedidas no período
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(quantidade),0) FROM expedicao
        WHERE data BETWEEN ? AND ?
    ");
    $stmt->execute([$ini, $fim]);
    $dados['total_expedidas'] = (int)$stmt->fetchColumn();

    // Perdas reais no período — EXCLUI 'reaproveitamento' (v2)
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(quantidade),0) FROM movimentacoes
        WHERE tipo_mov = 'perda'
          AND data BETWEEN ? AND ?
          AND ativo = 1
    ");
    $stmt->execute([$ini, $fim]);
    $dados['total_perdas'] = (int)$stmt->fetchColumn();

    // Lotes ativos no período
    $stmt = db()->prepare("
        SELECT COUNT(*) FROM lotes WHERE ativo=1
        AND ((data_semeadura BETWEEN ? AND ?) OR status NOT IN ('expedido','arquivado'))
    ");
    $stmt->execute([$ini, $fim]);
    $dados['lotes_ativos_periodo'] = (int)$stmt->fetchColumn();

    // Produção total atual
    $stmt = db()->query("
        SELECT COALESCE(SUM(qtd_atual),0) FROM lotes
        WHERE ativo=1 AND status NOT IN ('expedido','arquivado')
    ");
    $dados['total_producao'] = (int)$stmt->fetchColumn();

    // Taxa aproveitamento
    $total_ini = (int)db()->query("SELECT COALESCE(SUM(qtd_inicial),0) FROM lotes WHERE ativo=1")->fetchColumn();
    if ($total_ini > 0) {
        $dados['taxa_aproveitamento'] = round(($dados['total_expedidas']/$total_ini)*100, 2);
    }

    // Horas trabalhadas
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE,entrada,COALESCE(saida,entrada))/60.0),0)
        FROM pontos WHERE data BETWEEN ? AND ? AND entrada IS NOT NULL
    ");
    $stmt->execute([$ini, $fim]);
    $dados['horas_trabalhadas'] = round((float)$stmt->fetchColumn(), 1);

    // Por espécie
    $stmt = db()->query("SELECT DISTINCT especie_id FROM lotes WHERE ativo=1");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $eid) {
        $s1 = db()->prepare("SELECT nome_popular, nome_cientifico, codigo FROM especies WHERE id=?");
        $s1->execute([$eid]);
        $esp = $s1->fetch();
        if (!$esp) continue;

        $s2 = db()->prepare("
            SELECT COUNT(*), SUM(qtd_atual), SUM(qtd_perdida), AVG(taxa_germinacao), AVG(taxa_perda)
            FROM lotes WHERE especie_id=? AND ativo=1 AND status NOT IN ('expedido','arquivado')
        ");
        $s2->execute([$eid]);
        [$n_lotes,$qtd_atual,$perdidas,$avg_germ,$avg_perda] = $s2->fetch(PDO::FETCH_NUM);

        $s3 = db()->prepare("
            SELECT COALESCE(SUM(ex.quantidade),0)
            FROM expedicao ex JOIN lotes l ON l.id=ex.lote_id
            WHERE l.especie_id=? AND ex.data BETWEEN ? AND ?
        ");
        $s3->execute([$eid, $ini, $fim]);
        $expedidas_esp = (int)$s3->fetchColumn();

        // Perdas reais (exclui reaproveitamento)
        $s4 = db()->prepare("
            SELECT COALESCE(SUM(m.quantidade),0)
            FROM movimentacoes m JOIN lotes l ON l.id=m.lote_id
            WHERE l.especie_id=? AND m.tipo_mov='perda' AND m.data BETWEEN ? AND ?
        ");
        $s4->execute([$eid, $ini, $fim]);
        $perdidas_esp = (int)$s4->fetchColumn();

        if (!$n_lotes && !$expedidas_esp) continue;

        $dados['por_especie'][] = [
            'especie_id'          => (int)$eid,
            'nome_popular'        => $esp['nome_popular'],
            'nome_cientifico'     => $esp['nome_cientifico'],
            'codigo'              => $esp['codigo'],
            'lotes_ativos'        => (int)$n_lotes,
            'mudas_em_producao'   => (int)$qtd_atual,
            'mudas_expedidas'     => $expedidas_esp,
            'mudas_perdidas'      => $perdidas_esp,
            'taxa_germinacao'     => $avg_germ ? round($avg_germ, 1) : null,
            'taxa_perda'          => $avg_perda ? round($avg_perda, 1) : null,
            'taxa_aproveitamento' => $qtd_atual && $expedidas_esp
                ? round(($expedidas_esp/max($qtd_atual+$expedidas_esp,1))*100, 1)
                : null,
        ];
    }

    // Expedições
    $stmt = db()->prepare("
        SELECT ex.*, l.codigo AS lote_codigo, l.matriz_origem,
               e.nome_popular AS especie_nome
        FROM expedicao ex
        JOIN lotes l ON l.id=ex.lote_id
        JOIN especies e ON e.id=l.especie_id
        WHERE ex.data BETWEEN ? AND ?
        ORDER BY ex.data ASC
    ");
    $stmt->execute([$ini, $fim]);
    $dados['expedicoes'] = $stmt->fetchAll();

    // Perdas por causa (exclui reaproveitamento)
    $stmt = db()->prepare("
        SELECT causa_perda, SUM(quantidade) AS total, COUNT(*) AS ocorrencias
        FROM movimentacoes
        WHERE tipo_mov='perda' AND data BETWEEN ? AND ?
          AND ativo=1 AND causa_perda IS NOT NULL
        GROUP BY causa_perda ORDER BY total DESC
    ");
    $stmt->execute([$ini, $fim]);
    $dados['perdas_por_causa'] = $stmt->fetchAll();

    // Visitas do período (resumo)
    $stmt = db()->prepare("
        SELECT COUNT(*) AS total_visitas,
               SUM(estado_geral='problema')  AS problemas,
               SUM(estado_geral='atencao')   AS atencoes,
               SUM(estado_geral='ok')        AS oks,
               SUM(estado_geral='muito_bem') AS muito_bems
        FROM visitas_lote
        WHERE DATE(criado_em) BETWEEN ? AND ?
    ");
    $stmt->execute([$ini, $fim]);
    $dados['visitas_resumo'] = $stmt->fetch();

    return $dados;
}

function _atividades_resumo(string $ini, string $fim): array {
    $stmt = db()->prepare("
        SELECT tipo, COUNT(*) AS vezes,
               COALESCE(SUM(quantidade),0) AS total_qtd,
               COUNT(DISTINCT usuario_id) AS funcionarios
        FROM atividades
        WHERE data BETWEEN ? AND ? AND ativo=1
        GROUP BY tipo ORDER BY vezes DESC
    ");
    $stmt->execute([$ini, $fim]);
    return $stmt->fetchAll();
}
