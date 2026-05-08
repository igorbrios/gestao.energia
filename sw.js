// ============================================================
// sw.js
// O que faz: Service Worker — faz o app funcionar offline
//            e como PWA instalável
// Depende de: nada (roda no browser, separado do app)
// Usado por: app_login.html e todos os app_*.html (registrado uma vez)
// Versão: 1.0 · Mai/2026
// ============================================================

const CACHE_NOME    = 'florescer-v1';
const CACHE_OFFLINE = 'florescer-offline-v1';

// Arquivos que vão para o cache na instalação
// (shell do app — funciona sem internet)
const ARQUIVOS_CACHE = [
  '/',
  '/app/app_login.html',
  '/app/app_ponto.html',
  '/manifest.json',
  'https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=DM+Mono:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap',
];

// ============================================================
// INSTALAÇÃO — pré-cacheia os arquivos do shell
// ============================================================
self.addEventListener('install', event => {
  console.log('[SW] Instalando...');
  event.waitUntil(
    caches.open(CACHE_NOME).then(cache => {
      return cache.addAll(ARQUIVOS_CACHE).catch(err => {
        // Falha silenciosa — fontes do Google podem falhar offline
        console.warn('[SW] Alguns arquivos não cacheados:', err);
      });
    })
  );
  self.skipWaiting(); // ativa imediatamente, sem esperar fechar abas
});

// ============================================================
// ATIVAÇÃO — limpa caches antigas
// ============================================================
self.addEventListener('activate', event => {
  console.log('[SW] Ativando...');
  event.waitUntil(
    caches.keys().then(nomes => {
      return Promise.all(
        nomes
          .filter(n => n !== CACHE_NOME && n !== CACHE_OFFLINE)
          .map(n => {
            console.log('[SW] Removendo cache antigo:', n);
            return caches.delete(n);
          })
      );
    })
  );
  self.clients.claim(); // toma controle imediato de todas as abas
});

// ============================================================
// FETCH — estratégia por tipo de requisição
// ============================================================
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  // API: sempre tenta a rede. Se falhar, retorna erro padronizado.
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(redeComFallbackAPI(event.request));
    return;
  }

  // Uploads (fotos): só rede — não cacheia imagens
  if (url.pathname.startsWith('/uploads/')) {
    event.respondWith(fetch(event.request).catch(() => new Response('', { status: 503 })));
    return;
  }

  // Telas HTML e assets: cache-first, rede como fallback
  event.respondWith(cacheFirst(event.request));
});

// Cache-first: tenta cache, depois rede (e atualiza cache)
async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) return cached;

  try {
    const resposta = await fetch(request);
    if (resposta.ok) {
      const cache = await caches.open(CACHE_NOME);
      cache.put(request, resposta.clone());
    }
    return resposta;
  } catch {
    // Fallback: retorna página de login cacheada para navegação
    if (request.mode === 'navigate') {
      return caches.match('/app/app_login.html');
    }
    return new Response('Offline', { status: 503 });
  }
}

// Rede com fallback para API: tenta rede, se falhar retorna erro JSON padrão
async function redeComFallbackAPI(request) {
  try {
    return await fetch(request);
  } catch {
    return new Response(
      JSON.stringify({ ok: false, erro: 'Sem conexão. Registro salvo localmente.' }),
      {
        status: 503,
        headers: { 'Content-Type': 'application/json' }
      }
    );
  }
}

// ============================================================
// BACKGROUND SYNC — tenta sincronizar quando volta online
// (suportado em Chrome/Android — no iOS usa o evento 'online')
// ============================================================
self.addEventListener('sync', event => {
  if (event.tag === 'sincronizar-fila') {
    console.log('[SW] Background sync disparado');
    event.waitUntil(sincronizarComCliente());
  }
});

// Envia mensagem para o cliente (tab aberta) sincronizar
async function sincronizarComCliente() {
  const clientes = await self.clients.matchAll({ type: 'window' });
  clientes.forEach(cliente => {
    cliente.postMessage({ tipo: 'SINCRONIZAR' });
  });
}

// ============================================================
// MENSAGENS DO APP → SW
// ============================================================
self.addEventListener('message', event => {
  if (event.data?.tipo === 'SKIP_WAITING') {
    self.skipWaiting();
  }
  if (event.data?.tipo === 'CACHE_ADICIONAR') {
    caches.open(CACHE_NOME).then(cache => cache.add(event.data.url));
  }
});
