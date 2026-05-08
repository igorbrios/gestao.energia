// ============================================================
// offline_queue.js
// O que faz: Gerencia a fila de registros offline usando IndexedDB.
//            Sincroniza automaticamente quando há conexão.
// Depende de: sw.js (para background sync), api_ponto.php
// Usado por: app_ponto.html (importado como módulo)
// Versão: 1.0 · Mai/2026
// ============================================================

const FilaOffline = (() => {

  const DB_NOME    = 'florescer_offline';
  const DB_VERSAO  = 1;
  const STORE_NOME = 'fila';
  let db = null;

  // ----------------------------------------------------------
  // INICIALIZAR IndexedDB
  // ----------------------------------------------------------
  async function inicializar() {
    return new Promise((resolve, reject) => {
      const req = indexedDB.open(DB_NOME, DB_VERSAO);

      req.onupgradeneeded = e => {
        const d = e.target.result;
        if (!d.objectStoreNames.contains(STORE_NOME)) {
          const store = d.createObjectStore(STORE_NOME, { keyPath: 'uuid' });
          store.createIndex('status', 'status', { unique: false });
          store.createIndex('criado_em', 'criado_em', { unique: false });
        }
      };

      req.onsuccess = e => {
        db = e.target.result;
        resolve(db);
      };

      req.onerror = () => reject(req.error);
    });
  }

  // ----------------------------------------------------------
  // ADICIONAR REGISTRO À FILA
  // ----------------------------------------------------------
  async function adicionar(tabela, acao, payload) {
    if (!db) await inicializar();

    const registro = {
      uuid:             gerarUUID(),
      tabela_destino:   tabela,
      acao:             acao,
      payload:          payload,
      criado_offline:   new Date().toISOString().replace('T', ' ').slice(0, 19),
      status:           'pendente',
      tentativas:       0,
    };

    return new Promise((resolve, reject) => {
      const tx    = db.transaction(STORE_NOME, 'readwrite');
      const store = tx.objectStore(STORE_NOME);
      const req   = store.add(registro);
      req.onsuccess = () => resolve(registro);
      req.onerror   = () => reject(req.error);
    });
  }

  // ----------------------------------------------------------
  // LISTAR PENDENTES
  // ----------------------------------------------------------
  async function listarPendentes() {
    if (!db) await inicializar();
    return new Promise((resolve, reject) => {
      const tx    = db.transaction(STORE_NOME, 'readonly');
      const store = tx.objectStore(STORE_NOME);
      const idx   = store.index('status');
      const req   = idx.getAll('pendente');
      req.onsuccess = () => resolve(req.result);
      req.onerror   = () => reject(req.error);
    });
  }

  // ----------------------------------------------------------
  // MARCAR COMO SINCRONIZADO
  // ----------------------------------------------------------
  async function marcarSincronizado(uuid) {
    if (!db) await inicializar();
    return new Promise((resolve, reject) => {
      const tx    = db.transaction(STORE_NOME, 'readwrite');
      const store = tx.objectStore(STORE_NOME);
      const req   = store.get(uuid);
      req.onsuccess = () => {
        const reg = req.result;
        if (reg) {
          reg.status = 'sincronizado';
          store.put(reg);
        }
        resolve();
      };
      req.onerror = () => reject(req.error);
    });
  }

  // ----------------------------------------------------------
  // MARCAR COMO ERRO
  // ----------------------------------------------------------
  async function marcarErro(uuid, msg) {
    if (!db) await inicializar();
    return new Promise((resolve, reject) => {
      const tx    = db.transaction(STORE_NOME, 'readwrite');
      const store = tx.objectStore(STORE_NOME);
      const req   = store.get(uuid);
      req.onsuccess = () => {
        const reg = req.result;
        if (reg) {
          reg.status     = 'erro';
          reg.tentativas = (reg.tentativas || 0) + 1;
          reg.erro_msg   = msg;
          store.put(reg);
        }
        resolve();
      };
      req.onerror = () => reject(req.error);
    });
  }

  // ----------------------------------------------------------
  // CONTAR PENDENTES (para exibir badge)
  // ----------------------------------------------------------
  async function contarPendentes() {
    if (!db) await inicializar();
    return new Promise((resolve, reject) => {
      const tx    = db.transaction(STORE_NOME, 'readonly');
      const store = tx.objectStore(STORE_NOME);
      const idx   = store.index('status');
      const req   = idx.count('pendente');
      req.onsuccess = () => resolve(req.result);
      req.onerror   = () => reject(req.error);
    });
  }

  // ----------------------------------------------------------
  // SINCRONIZAR COM O SERVIDOR
  // Enviados em lote para api_ponto.php (acao: sincronizar)
  // ----------------------------------------------------------
  async function sincronizar() {
    const pendentes = await listarPendentes();
    if (pendentes.length === 0) return { sincronizados: 0, erros: 0 };

    const token = localStorage.getItem('vf_token');
    if (!token) return { sincronizados: 0, erros: 0 }; // não autenticado

    let resp;
    try {
      const r = await fetch('/api/api_ponto.php', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-VF-TOKEN': token,
        },
        body: JSON.stringify({
          acao: 'sincronizar',
          registros: pendentes,
        }),
      });
      resp = await r.json();
    } catch (e) {
      console.warn('[Fila] Sem conexão para sincronizar:', e);
      return { sincronizados: 0, erros: pendentes.length };
    }

    if (!resp.ok) {
      console.error('[Fila] Servidor recusou sincronização:', resp.erro);
      return { sincronizados: 0, erros: pendentes.length };
    }

    // Atualiza status local de cada registro
    const detalhes = resp.dados?.detalhes ?? [];
    let sync = 0, erros = 0;

    for (const d of detalhes) {
      if (d.status === 'ok' || d.status === 'duplicado' || d.status === 'ja_existe') {
        await marcarSincronizado(d.uuid);
        sync++;
      } else {
        await marcarErro(d.uuid, d.msg || 'Erro desconhecido');
        erros++;
      }
    }

    return { sincronizados: sync, erros };
  }

  // ----------------------------------------------------------
  // MONITORAR CONEXÃO E SINCRONIZAR AUTOMATICAMENTE
  // ----------------------------------------------------------
  function monitorarConexao(callbackStatus) {
    const sincronizarSeOnline = async () => {
      if (navigator.onLine) {
        const result = await sincronizar();
        if (callbackStatus) callbackStatus(result);
      }
    };

    window.addEventListener('online',  sincronizarSeOnline);

    // Ouve mensagens do Service Worker (background sync)
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.addEventListener('message', e => {
        if (e.data?.tipo === 'SINCRONIZAR') {
          sincronizarSeOnline();
        }
      });
    }

    // Tenta sincronizar ao iniciar (caso tenha voltado online)
    sincronizarSeOnline();
  }

  // ----------------------------------------------------------
  // REGISTRAR SERVICE WORKER
  // ----------------------------------------------------------
  async function registrarSW() {
    if (!('serviceWorker' in navigator)) {
      console.warn('[SW] Service Worker não suportado neste navegador.');
      return;
    }

    try {
      const registro = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
      console.log('[SW] Registrado com sucesso:', registro.scope);

      // Solicitar background sync (Chrome/Android)
      if (registro.sync) {
        await registro.sync.register('sincronizar-fila');
      }
    } catch (e) {
      console.error('[SW] Falha no registro:', e);
    }
  }

  // ----------------------------------------------------------
  // GERAR UUID v4
  // ----------------------------------------------------------
  function gerarUUID() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
      return crypto.randomUUID();
    }
    // Fallback para navegadores mais antigos
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
      const r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  // API pública
  return {
    inicializar,
    adicionar,
    listarPendentes,
    contarPendentes,
    sincronizar,
    monitorarConexao,
    registrarSW,
    gerarUUID,
  };

})();

// Exporta para uso como módulo (app_ponto.html importa diretamente)
if (typeof module !== 'undefined') module.exports = FilaOffline;
