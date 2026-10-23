// RandomQuiz.Pro Service Worker
// 提供离线支持和缓存策略

const CACHE_VERSION = 'opensource-1';
const CACHE_NAME = `randomquiz-runtime-${CACHE_VERSION}`;
const STATIC_CACHE_NAME = `randomquiz-static-${CACHE_VERSION}`;

// 需要缓存的静态资源 (不包含 manifest.json，因为它由浏览器单独处理)
const STATIC_ASSETS = [
    './',
    './index.html',
    './manifest.json',
    './pwa.js?v=opensource-1'
];

// 外部CDN资源（需要在线时缓存）
const CDN_ASSETS = [
    'https://cdn.tailwindcss.com',
    'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
    'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css',
    'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js',
    'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js',
    'https://unpkg.com/lucide@0.515.0'
];

// 安装事件 - 预缓存静态资源
self.addEventListener('install', (event) => {
    console.log('[SW] Installing service worker...');
    event.waitUntil(
        caches.open(STATIC_CACHE_NAME)
            .then((cache) => {
                console.log('[SW] Caching static assets');
                return cache.addAll(STATIC_ASSETS);
            })
            .then(() => self.skipWaiting())
    );
});

// 激活事件 - 清理旧缓存
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating service worker...');
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name !== CACHE_NAME && name !== STATIC_CACHE_NAME)
                    .map((name) => {
                        console.log('[SW] Deleting old cache:', name);
                        return caches.delete(name);
                    })
            );
        }).then(() => self.clients.claim())
    );
});

// 请求拦截 - 缓存策略
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // 跳过非GET请求
    if (request.method !== 'GET') {
        return;
    }

    // API请求 - 网络优先，失败时返回离线提示
    if (url.pathname.includes('/api.php') || url.hostname.includes('api.')) {
        event.respondWith(networkFirst(request));
        return;
    }

    // HTML / 关键脚本 - 网络优先，避免旧版本页面长期卡在缓存里
    if (
        request.mode === 'navigate' ||
        request.destination === 'document' ||
        (url.origin === self.location.origin && (
            url.pathname.endsWith('/index.html') ||
            url.pathname.endsWith('/pwa.js') ||
            url.pathname.endsWith('/manifest.json')
        ))
    ) {
        event.respondWith(networkFirst(request));
        return;
    }

    // CDN资源 - 缓存优先，网络备用
    if (CDN_ASSETS.some(asset => request.url.includes(asset.split('/')[2]))) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // 其他资源 - 缓存优先
    event.respondWith(cacheFirst(request));
});

// 缓存优先策略
async function cacheFirst(request) {
    const cached = await caches.match(request);
    if (cached) {
        return cached;
    }

    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }
        return response;
    } catch (error) {
        console.log('[SW] Cache first failed for:', request.url);
        // 返回离线页面或空响应
        return new Response('离线模式 - 资源不可用', {
            status: 503,
            statusText: 'Service Unavailable'
        });
    }
}

// 网络优先策略
async function networkFirst(request) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }
        return response;
    } catch (error) {
        const cached = await caches.match(request);
        if (cached) {
            return cached;
        }

        return new Response(
            JSON.stringify({
                success: false,
                message: '网络连接失败，请检查网络后重试'
            }),
            {
                status: 503,
                headers: { 'Content-Type': 'application/json' }
            }
        );
    }
}

// 处理推送通知（预留）
self.addEventListener('push', (event) => {
    if (event.data) {
        const data = event.data.json();
        self.registration.showNotification(data.title || 'RandomQuiz.Pro', {
            body: data.body || '您有新的学习提醒',
            icon: new URL('favicon.svg', self.registration.scope).href,
            badge: new URL('favicon.svg', self.registration.scope).href
        });
    }
});
