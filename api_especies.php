<?php
// ============================================================
// api_especies.php  (v2 — refatorada)
// O que faz: CRUD de gêneros, espécies expandidas, fases de
//            cultivo, zonas de insolação, protocolos, espaços.
//            Lógica de herança: espécie → gênero quando NULL.
// Depende de: config.php, db_especies.sql (v2)
// Usado por: app_especies.html, api_lotes.php, api_mapa.php
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
    // LISTAR ESPÉCIES com referência ao gênero
    // ----------------------------------------------------------
    case 'listar_especies':
        $stmt = db()->prepare("
            SELECT e.*,
                   g.nome            AS genero_nome,
                   g.necessidade_sol AS genero_sol,
                   g.necessidade_agua AS genero_agua,
                   g.resistencia_geral AS genero_resist,
                   g.porte           AS genero_porte,
                   g.crescimento     AS genero_cresc,
                   g.sucessao        AS genero_suc,
                   COUNT(DISTINCT p.id) AS total_protocolos
            FROM especies e
            LEFT JOIN generos g ON g.id = e.genero_id
            LEFT JOIN protocolos p ON p.especie_id = e.id AND p.ativo = 1
            WHERE e.ativa = 1
            GROUP BY e.id
            ORDER BY e.dificuldade ASC, e.nome_popular ASC
        ");
        $stmt->execute();
        $especies = $stmt->fetchAll();

        // Resolve herança: se campo da espécie é NULL, usa do gênero
        foreach ($especies as &$esp) {
            $esp = _resolver_heranca($esp);
        }

        responder(true, ['especies' => $especies]);
        break;

    // ----------------------------------------------------------
    // DETALHE DE UMA ESPÉCIE
    // Retorna espécie + fases + protocolos + permissões
    // ----------------------------------------------------------
    case 'detalhe_especie':
        $id = (int)($body['id'] ?? 0);
        if (!$id) responder(false, null, 'ID inválido.');

        $stmt = db()->prepare("
            SELECT e.*,
                   g.nome            AS genero_nome,
                   g.obs_cultivo     AS genero_obs_cultivo,
                   g.necessidade_sol AS genero_sol,
                   g.necessidade_agua AS genero_agua,
                   g.resistencia_geral AS genero_resist,
                   g.porte           AS genero_porte,
                   g.crescimento     AS genero_cresc,
                   g.sucessao        AS genero_suc,
                   g.bioma           AS genero_bioma,
                   g.fitofisionomia  AS genero_fitofisio
            FROM especies e
            LEFT JOIN generos g ON g.id = e.genero_id
            WHERE e.id = ? AND e.ativa = 1
        ");
        $stmt->execute([$id]);
        $especie = $stmt->fetch();
        if (!$especie) responder(false, null, 'Espécie não encontrada.');

        $especie = _resolver_heranca($especie);

        // Fases de cultivo
        $stmt = db()->prepare("
            SELECT * FROM especie_fases
            WHERE especie_id = ?
            ORDER BY FIELD(fase,'semeadura','germinacao','crescimento_tubete','crescimento_saco','rustificacao')
        ");
        $stmt->execute([$id]);
        $fases = $stmt->fetchAll();

        // Protocolos
        $stmt = db()->prepare("
            SELECT * FROM protocolos
            WHERE especie_id = ? AND ativo = 1
            ORDER BY FIELD(status,'ativo','teste','arquivado'), etapa
        ");
        $stmt->execute([$id]);
        $protocolos = $stmt->fetchAll();

        // Permissões do usuário logado nesta espécie
        $etapas = ['semeadura','repicagem','transplante','fitossanitario','expedicao','irrigacao'];
        $permissoes = [];
        foreach ($etapas as $etapa) {
            $permissoes[$etapa] = verificar_permissao($eu['id'], $etapa, $id);
        }

        responder(true, [
            'especie'    => $especie,
            'fases'      => $fases,
            'protocolos' => $protocolos,
            'permissoes' => $permissoes,
        ]);
        break;

    // ----------------------------------------------------------
    // SALVAR ESPÉCIE (criar ou editar) — nível 4+
    // ----------------------------------------------------------
    case 'salvar_especie':
        exigir_nivel($eu, 4);

        $id       = (int)($body['id'] ?? 0);
        $campos   = _extrair_campos_especie($body);

        if (empty($campos['nome_popular'])) responder(false, null, 'Nome popular é obrigatório.');
        if (empty($campos['codigo']))       responder(false, null, 'Código é obrigatório.');

        $campos['codigo'] = strtoupper(trim($campos['codigo']));

        if ($id) {
            // Editar
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
            $stmt = db()->prepare("UPDATE especies SET $sets WHERE id = ?");
            $stmt->execute([...array_values($campos), $id]);
            responder(true, ['id' => $id, 'mensagem' => 'Espécie atualizada.']);
        } else {
            // Criar — verifica código único
            $stmt = db()->prepare("SELECT id FROM especies WHERE codigo = ?");
            $stmt->execute([$campos['codigo']]);
            if ($stmt->fetch()) responder(false, null, "Código '{$campos['codigo']}' já existe.");

            $campos['criado_por'] = $eu['id'];
            $cols = implode(', ', array_keys($campos));
            $vals = implode(', ', array_fill(0, count($campos), '?'));
            $stmt = db()->prepare("INSERT INTO especies ($cols) VALUES ($vals)");
            $stmt->execute(array_values($campos));
            responder(true, ['id' => (int)db()->lastInsertId(), 'mensagem' => 'Espécie criada.']);
        }
        break;

    // ----------------------------------------------------------
    // SALVAR FASE DE CULTIVO — nível 4+
    // ----------------------------------------------------------
    case 'salvar_fase':
        exigir_nivel($eu, 4);

        $especie_id = (int)($body['especie_id'] ?? 0);
        $fase       = $body['fase'] ?? '';
        $sol        = isset($body['necessidade_sol'])  ? (int)$body['necessidade_sol']  : null;
        $agua       = isset($body['necessidade_agua']) ? (int)$body['necessidade_agua'] : null;
        $sens       = isset($body['sensibilidade'])    ? (int)$body['sensibilidade']    : null;
        $obs        = trim($body['obs'] ?? '') ?: null;

        $fases_validas = ['semeadura','germinacao','crescimento_tubete','crescimento_saco','rustificacao'];
        if (!$especie_id) responder(false, null, 'Espécie é obrigatória.');
        if (!in_array($fase, $fases_validas)) responder(false, null, 'Fase inválida.');

        // Valida escalas 1–6
        foreach ([$sol, $agua, $sens] as $val) {
            if ($val !== null && ($val < 1 || $val > 6)) {
                responder(false, null, 'Escala deve ser entre 1 e 6.');
            }
        }

        db()->prepare("
            INSERT INTO especie_fases (especie_id, fase, necessidade_sol, necessidade_agua, sensibilidade, obs)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                necessidade_sol  = VALUES(necessidade_sol),
                necessidade_agua = VALUES(necessidade_agua),
                sensibilidade    = VALUES(sensibilidade),
                obs              = VALUES(obs)
        ")->execute([$especie_id, $fase, $sol, $agua, $sens, $obs]);

        responder(true, ['mensagem' => 'Fase salva.']);
        break;

    // ----------------------------------------------------------
    // LISTAR GÊNEROS
    // ----------------------------------------------------------
    case 'listar_generos':
        $stmt = db()->query("
            SELECT g.*, COUNT(e.id) AS total_especies
            FROM generos g
            LEFT JOIN especies e ON e.genero_id = g.id AND e.ativa = 1
            WHERE g.ativo = 1
            GROUP BY g.id
            ORDER BY g.nome ASC
        ");
        responder(true, ['generos' => $stmt->fetchAll()]);
        break;

    // ----------------------------------------------------------
    // SALVAR GÊNERO — nível 4+
    // ----------------------------------------------------------
    case 'salvar_genero':
        exigir_nivel($eu, 4);

        $id     = (int)($body['id'] ?? 0);
        $campos = _extrair_campos_genero($body);

        if (empty($campos['nome'])) responder(false, null, 'Nome do gênero é obrigatório.');

        // Valida escalas
        foreach (['necessidade_sol','necessidade_agua','resistencia_geral'] as $campo) {
            if (isset($campos[$campo]) && $campos[$campo] !== null) {
                if ($campos[$campo] < 1 || $campos[$campo] > 6) {
                    responder(false, null, "Campo $campo deve ser entre 1 e 6.");
                }
            }
        }

        if ($id) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
            db()->prepare("UPDATE generos SET $sets WHERE id = ?")
                ->execute([...array_values($campos), $id]);
            responder(true, ['id' => $id, 'mensagem' => 'Gênero atualizado.']);
        } else {
            $cols = implode(', ', array_keys($campos));
            $vals = implode(', ', array_fill(0, count($campos), '?'));
            db()->prepare("INSERT INTO generos ($cols) VALUES ($vals)")
                ->execute(array_values($campos));
            responder(true, ['id' => (int)db()->lastInsertId(), 'mensagem' => 'Gênero criado.']);
        }
        break;

    // ----------------------------------------------------------
    // ZONAS DE INSOLAÇÃO — listar
    // ----------------------------------------------------------
    case 'listar_zonas':
        $stmt = db()->query("
            SELECT z.*, u.apelido AS criado_por_nome
            FROM zonas_insolacao z
            LEFT JOIN usuarios u ON u.id = z.criado_por
            WHERE z.ativa = 1
            ORDER BY z.nivel DESC, z.criado_em DESC
        ");
        responder(true, ['zonas' => $stmt->fetchAll()]);
        break;

    // ----------------------------------------------------------
    // ZONAS DE INSOLAÇÃO — salvar (nível 4+)
    // Recebe: { id?, nome, nivel, grade_celulas: [{linha,col},...], obs }
    // ----------------------------------------------------------
    case 'salvar_zona':
        exigir_nivel($eu, 4);

        $id      = (int)($body['id'] ?? 0);
        $nome    = trim($body['nome'] ?? '');
        $nivel   = $body['nivel'] ?? 'atencao';
        $celulas = $body['grade_celulas'] ?? [];
        $obs     = trim($body['obs'] ?? '') ?: null;

        if (empty($nome)) responder(false, null, 'Nome da zona é obrigatório.');
        if (!in_array($nivel, ['atencao','critico'])) responder(false, null, 'Nível inválido.');
        if (empty($celulas)) responder(false, null, 'Selecione ao menos uma célula no mapa.');

        $celulas_json = json_encode($celulas);

        if ($id) {
            db()->prepare("
                UPDATE zonas_insolacao SET nome=?, nivel=?, grade_celulas=?, obs=? WHERE id=?
            ")->execute([$nome, $nivel, $celulas_json, $obs, $id]);
            responder(true, ['id' => $id, 'mensagem' => 'Zona atualizada.']);
        } else {
            db()->prepare("
                INSERT INTO zonas_insolacao (nome, nivel, grade_celulas, obs, criado_por)
                VALUES (?,?,?,?,?)
            ")->execute([$nome, $nivel, $celulas_json, $obs, $eu['id']]);
            responder(true, ['id' => (int)db()->lastInsertId(), 'mensagem' => 'Zona criada.']);
        }
        break;

    // ----------------------------------------------------------
    // ZONAS DE INSOLAÇÃO — desativar
    // ----------------------------------------------------------
    case 'desativar_zona':
        exigir_nivel($eu, 4);
        $id = (int)($body['id'] ?? 0);
        db()->prepare("UPDATE zonas_insolacao SET ativa = 0 WHERE id = ?")
            ->execute([$id]);
        responder(true, ['mensagem' => 'Zona desativada.']);
        break;

    // ----------------------------------------------------------
    // VERIFICAR ALERTA DE ZONA para um lote
    // Recebe: { lote_id } — cruza posição do lote com zonas ativas
    // e tolerância da espécie na fase atual
    // Retorna: { em_zona, zona_nivel, alerta, modo_atencao }
    // ----------------------------------------------------------
    case 'alerta_zona':
        $lote_id = (int)($body['lote_id'] ?? 0);
        if (!$lote_id) responder(false, null, 'ID inválido.');

        $stmt = db()->prepare("
            SELECT l.fase_atual, l.espaco_id,
                   ev.grade_linha, ev.grade_col,
                   l.especie_id,
                   ef.necessidade_sol AS fase_sol,
                   ef.sensibilidade   AS fase_sensib,
                   e.necessidade_sol  AS esp_sol,
                   e.resistencia_geral AS esp_resist,
                   g.necessidade_sol  AS gen_sol,
                   g.resistencia_geral AS gen_resist
            FROM lotes l
            JOIN espacos_viveiro ev ON ev.id = l.espaco_id
            JOIN especies e ON e.id = l.especie_id
            LEFT JOIN generos g ON g.id = e.genero_id
            LEFT JOIN especie_fases ef ON ef.especie_id = l.especie_id AND ef.fase = l.fase_atual
            WHERE l.id = ?
        ");
        $stmt->execute([$lote_id]);
        $info = $stmt->fetch();
        if (!$info) responder(true, ['em_zona' => false, 'alerta' => null]);

        // Resolve herança de sol
        $sol_necessario = $info['fase_sol']
            ?? $info['esp_sol']
            ?? $info['gen_sol'];
        $resist = $info['esp_resist'] ?? $info['gen_resist'];

        // Busca zonas que contêm a posição deste lote
        $stmt2 = db()->query("SELECT id, nome, nivel, grade_celulas FROM zonas_insolacao WHERE ativa = 1");
        $zonas = $stmt2->fetchAll();

        $zona_encontrada = null;
        $linha = (int)$info['grade_linha'];
        $col   = (int)$info['grade_col'];

        foreach ($zonas as $zona) {
            $celulas = json_decode($zona['grade_celulas'], true);
            foreach ($celulas as $c) {
                if ((int)$c['linha'] === $linha && (int)$c['col'] === $col) {
                    $zona_encontrada = $zona;
                    break 2;
                }
            }
        }

        if (!$zona_encontrada) {
            responder(true, ['em_zona' => false, 'alerta' => null, 'modo_atencao' => 'nenhum']);
        }

        // Determina modo de atenção
        // Sol necessário <= 3 (prefere sombra) em zona crítica → alerta forte
        $modo = 'nenhum';
        if ($zona_encontrada['nivel'] === 'critico') {
            $modo = ($sol_necessario !== null && $sol_necessario <= 3) ? 'critico' : 'atencao';
        } else {
            // zona de atenção — só marca atenção se espécie é sensível
            $modo = ($resist !== null && $resist <= 3) ? 'atencao' : 'nenhum';
        }

        responder(true, [
            'em_zona'      => true,
            'zona_nome'    => $zona_encontrada['nome'],
            'zona_nivel'   => $zona_encontrada['nivel'],
            'modo_atencao' => $modo,
            'alerta'       => $modo !== 'nenhum'
                ? "Lote em zona de {$zona_encontrada['nivel']}. Espécie na fase {$info['fase_atual']} — monitorar."
                : null,
        ]);
        break;

    // ----------------------------------------------------------
    // SALVAR PROTOCOLO — nível 4+
    // ----------------------------------------------------------
    case 'salvar_protocolo':
        exigir_nivel($eu, 4);

        $id         = (int)($body['id'] ?? 0);
        $especie_id = (int)($body['especie_id'] ?? 0);
        $nome       = trim($body['nome'] ?? '');
        $etapa      = trim($body['etapa'] ?? '');
        $instrucoes = trim($body['instrucoes'] ?? '');
        $status_p   = $body['status'] ?? 'ativo';
        $descricao  = trim($body['descricao'] ?? '') ?: null;

        if (!$especie_id) responder(false, null, 'Espécie é obrigatória.');
        if (empty($nome)) responder(false, null, 'Nome é obrigatório.');
        if (empty($instrucoes)) responder(false, null, 'Instruções são obrigatórias.');

        if (!$id && $status_p === 'ativo') {
            db()->prepare("
                UPDATE protocolos SET status='arquivado'
                WHERE especie_id=? AND etapa=? AND status='ativo'
            ")->execute([$especie_id, $etapa]);
        }

        if ($id) {
            db()->prepare("UPDATE protocolos SET nome=?,descricao=?,etapa=?,instrucoes=?,status=? WHERE id=?")
                ->execute([$nome,$descricao,$etapa,$instrucoes,$status_p,$id]);
            responder(true, ['id' => $id, 'mensagem' => 'Protocolo atualizado.']);
        } else {
            db()->prepare("INSERT INTO protocolos (especie_id,nome,descricao,etapa,instrucoes,status,criado_por) VALUES (?,?,?,?,?,?,?)")
                ->execute([$especie_id,$nome,$descricao,$etapa,$instrucoes,$status_p,$eu['id']]);
            responder(true, ['id' => (int)db()->lastInsertId(), 'mensagem' => 'Protocolo criado.']);
        }
        break;

    // ----------------------------------------------------------
    // PROMOVER PROTOCOLO — nível 5
    // ----------------------------------------------------------
    case 'promover_protocolo':
        exigir_nivel($eu, 5);
        $id = (int)($body['id'] ?? 0);
        $stmt = db()->prepare("SELECT especie_id, etapa FROM protocolos WHERE id=? AND status='teste'");
        $stmt->execute([$id]);
        $prot = $stmt->fetch();
        if (!$prot) responder(false, null, 'Protocolo não encontrado ou não está em teste.');

        db()->prepare("UPDATE protocolos SET status='arquivado' WHERE especie_id=? AND etapa=? AND status='ativo'")
            ->execute([$prot['especie_id'],$prot['etapa']]);
        db()->prepare("UPDATE protocolos SET status='ativo',promovido_por=?,promovido_em=NOW() WHERE id=?")
            ->execute([$eu['id'],$id]);

        responder(true, ['mensagem' => 'Protocolo promovido para ativo.']);
        break;

    // ----------------------------------------------------------
    // LISTAR ESPAÇOS
    // ----------------------------------------------------------
    case 'listar_espacos':
        $stmt = db()->prepare("
            SELECT ev.*,
                   COUNT(l.id) AS lotes_ativos
            FROM espacos_viveiro ev
            LEFT JOIN lotes l ON l.espaco_id=ev.id AND l.status NOT IN ('expedido','arquivado')
            WHERE ev.ativo=1
            GROUP BY ev.id
            ORDER BY ev.grade_linha, ev.grade_col
        ");
        $stmt->execute();
        responder(true, ['espacos' => $stmt->fetchAll()]);
        break;

    // ----------------------------------------------------------
    // VERIFICAR PERMISSÃO para etapa + espécie
    // ----------------------------------------------------------
    case 'minha_permissao':
        $etapa      = trim($body['etapa'] ?? '');
        $especie_id = isset($body['especie_id']) ? (int)$body['especie_id'] : null;
        $perm       = verificar_permissao($eu['id'], $etapa, $especie_id);

        $protocolo = null;
        if ($especie_id && $etapa) {
            $stmt = db()->prepare("
                SELECT instrucoes, nome FROM protocolos
                WHERE especie_id=? AND etapa=? AND status='ativo' AND ativo=1 LIMIT 1
            ");
            $stmt->execute([$especie_id, $etapa]);
            $protocolo = $stmt->fetch() ?: null;
        }

        responder(true, ['permissao' => $perm, 'protocolo' => $protocolo]);
        break;

    default:
        responder(false, null, 'Ação desconhecida.');
}

// ============================================================
// FUNÇÕES INTERNAS
// ============================================================

/**
 * Resolve herança de campos NULL da espécie para o gênero.
 * Campos escalares (1–6) e enums de porte/crescimento/sucessao.
 */
function _resolver_heranca(array $esp): array {
    $campos_herdaveis = [
        'necessidade_sol'  => 'genero_sol',
        'necessidade_agua' => 'genero_agua',
        'resistencia_geral'=> 'genero_resist',
        'porte'            => 'genero_porte',
        'crescimento'      => 'genero_cresc',
        'sucessao'         => 'genero_suc',
    ];

    foreach ($campos_herdaveis as $campo_esp => $campo_gen) {
        $esp["{$campo_esp}_ref"] = $esp[$campo_esp]; // valor próprio (pode ser null)
        if ($esp[$campo_esp] === null && isset($esp[$campo_gen])) {
            $esp[$campo_esp]         = $esp[$campo_gen]; // herda do gênero
            $esp["{$campo_esp}_herdado"] = true;
        } else {
            $esp["{$campo_esp}_herdado"] = false;
        }
    }

    return $esp;
}

function _extrair_campos_especie(array $body): array {
    $campos = [
        'nome_popular'             => trim($body['nome_popular'] ?? ''),
        'nome_cientifico'          => trim($body['nome_cientifico'] ?? '') ?: null,
        'familia'                  => trim($body['familia'] ?? '') ?: null,
        'codigo'                   => strtoupper(trim($body['codigo'] ?? '')),
        'genero_id'                => isset($body['genero_id']) ? (int)$body['genero_id'] : null,
        'bioma'                    => trim($body['bioma'] ?? '') ?: null,
        'fitofisionomia'           => trim($body['fitofisionomia'] ?? '') ?: null,
        'estados_ocorrencia'       => trim($body['estados_ocorrencia'] ?? '') ?: null,
        'necessidade_sol'          => isset($body['necessidade_sol']) ? (int)$body['necessidade_sol'] : null,
        'necessidade_agua'         => isset($body['necessidade_agua']) ? (int)$body['necessidade_agua'] : null,
        'resistencia_geral'        => isset($body['resistencia_geral']) ? (int)$body['resistencia_geral'] : null,
        'sensibilidade_transplante'=> isset($body['sensibilidade_transplante']) ? (int)$body['sensibilidade_transplante'] : null,
        'porte'                    => $body['porte'] ?? null,
        'crescimento'              => $body['crescimento'] ?? null,
        'sucessao'                 => $body['sucessao'] ?? null,
        'altura_maxima_cm'         => isset($body['altura_maxima_cm']) ? (int)$body['altura_maxima_cm'] : null,
        'dificuldade'              => $body['dificuldade'] ?? 'media',
        'sementes_por_kg'          => isset($body['sementes_por_kg']) ? (int)$body['sementes_por_kg'] : null,
        'obs_beneficiamento'       => trim($body['obs_beneficiamento'] ?? '') ?: null,
        'semente_recalcitrante'    => (int)($body['semente_recalcitrante'] ?? 0),
        'germ_dias_min'            => isset($body['germ_dias_min']) ? (int)$body['germ_dias_min'] : null,
        'germ_dias_max'            => isset($body['germ_dias_max']) ? (int)$body['germ_dias_max'] : null,
        'germ_taxa_esperada'       => isset($body['germ_taxa_esperada']) ? (float)$body['germ_taxa_esperada'] : null,
        'germ_tratamento'          => trim($body['germ_tratamento'] ?? '') ?: null,
        'metodo_germinacao'        => trim($body['metodo_germinacao'] ?? '') ?: null,
        'embalagem_padrao'         => $body['embalagem_padrao'] ?? 'tubete',
        'transplante_necessario'   => (int)($body['transplante_necessario'] ?? 0),
        'tolera_repique_balde'     => (int)($body['tolera_repique_balde'] ?? 1),
        'rust_dias_min'            => (int)($body['rust_dias_min'] ?? 15),
        'rust_dias_max'            => (int)($body['rust_dias_max'] ?? 30),
        'nivel_min_semeadura'      => (int)($body['nivel_min_semeadura'] ?? 2),
        'nivel_min_repicagem'      => (int)($body['nivel_min_repicagem'] ?? 2),
        'nivel_min_transplante'    => (int)($body['nivel_min_transplante'] ?? 2),
        'altura_min_cm'            => isset($body['altura_min_cm']) ? (int)$body['altura_min_cm'] : null,
        'raiz_min_cm'              => isset($body['raiz_min_cm']) ? (int)$body['raiz_min_cm'] : null,
        'obs_tecnicas'             => trim($body['obs_tecnicas'] ?? '') ?: null,
        'obs_fitossanitario'       => trim($body['obs_fitossanitario'] ?? '') ?: null,
        'foto_adulta_url'          => trim($body['foto_adulta_url'] ?? '') ?: null,
    ];

    // Remove nulls para não sobrescrever campos existentes com UPDATE parcial
    return array_filter($campos, fn($v) => $v !== null || in_array(
        array_search($v, $campos),
        ['genero_id','bioma','fitofisionomia','estados_ocorrencia',
         'necessidade_sol','necessidade_agua','resistencia_geral','sensibilidade_transplante',
         'porte','crescimento','sucessao','altura_maxima_cm','sementes_por_kg',
         'obs_beneficiamento','germ_dias_min','germ_dias_max','germ_taxa_esperada',
         'germ_tratamento','metodo_germinacao','altura_min_cm','raiz_min_cm',
         'obs_tecnicas','obs_fitossanitario','foto_adulta_url']
    ));
}

function _extrair_campos_genero(array $body): array {
    return array_filter([
        'nome'               => trim($body['nome'] ?? ''),
        'familia'            => trim($body['familia'] ?? '') ?: null,
        'bioma'              => trim($body['bioma'] ?? '') ?: null,
        'fitofisionomia'     => trim($body['fitofisionomia'] ?? '') ?: null,
        'estados_ocorrencia' => trim($body['estados_ocorrencia'] ?? '') ?: null,
        'necessidade_sol'    => isset($body['necessidade_sol']) ? (int)$body['necessidade_sol'] : null,
        'necessidade_agua'   => isset($body['necessidade_agua']) ? (int)$body['necessidade_agua'] : null,
        'resistencia_geral'  => isset($body['resistencia_geral']) ? (int)$body['resistencia_geral'] : null,
        'porte'              => $body['porte'] ?? null,
        'crescimento'        => $body['crescimento'] ?? null,
        'sucessao'           => $body['sucessao'] ?? null,
        'obs_cultivo'        => trim($body['obs_cultivo'] ?? '') ?: null,
    ], fn($v) => $v !== '');
}
