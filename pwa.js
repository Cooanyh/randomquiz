/**
 * RandomQuiz.Pro - PWA Helper
 * 处理PWA安装、离线状态等功能
 */

(function () {
    'use strict';

    function getAppBasePath() {
        return new URL('./', window.location.href);
    }

    async function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            console.log('[PWA] Service Worker not supported');
            return;
        }

        try {
            const baseUrl = getAppBasePath();
            const swUrl = new URL('sw.js', baseUrl);
            const registration = await navigator.serviceWorker.register(swUrl.href, {
                scope: baseUrl.pathname
            });

            console.log('[PWA] Service Worker registered:', registration.scope);

            registration.addEventListener('updatefound', () => {
                const newWorker = registration.installing;
                console.log('[PWA] New Service Worker installing...');

                newWorker.addEventListener('statechange', () => {
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        showUpdateNotification();
                    }
                });
            });

        } catch (error) {
            console.error('[PWA] Service Worker registration failed:', error);
        }
    }

    let deferredPrompt = null;
    let installButton = null;

    window.addEventListener('beforeinstallprompt', (e) => {
        console.log('[PWA] Install prompt available');
        e.preventDefault();
        deferredPrompt = e;
        showInstallButton();
    });

    function showInstallButton() {
        if (document.getElementById('pwaInstallBtn')) return;

        const floatingContainer = document.getElementById('floatingButtonsContainer');
        if (!floatingContainer) return;

        installButton = document.createElement('button');
        installButton.id = 'pwaInstallBtn';
        installButton.className = 'p-3 rounded-full shadow-lg transition-all duration-300 btn';
        installButton.title = '安装应用';
        installButton.innerHTML = '<i data-lucide="download" class="w-5 h-5"></i>';
        installButton.style.cssText = `
            background: color-mix(in srgb, var(--surface-highlight) 82%, var(--surface));
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.16);
        `;

        installButton.addEventListener('mouseenter', () => {
            installButton.style.transform = 'translateY(-1px)';
            installButton.style.borderColor = 'var(--border-hover)';
        });
        installButton.addEventListener('mouseleave', () => {
            installButton.style.transform = '';
            installButton.style.borderColor = 'var(--border-color)';
        });
        installButton.addEventListener('click', handleInstallClick);

        floatingContainer.insertBefore(installButton, floatingContainer.firstChild);

        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    }

    async function handleInstallClick() {
        if (!deferredPrompt) {
            console.log('[PWA] No install prompt available');
            return;
        }

        console.log('[PWA] Showing install prompt');
        deferredPrompt.prompt();

        const { outcome } = await deferredPrompt.userChoice;
        console.log('[PWA] Install prompt outcome:', outcome);

        deferredPrompt = null;

        if (installButton) {
            installButton.remove();
            installButton = null;
        }
    }

    window.addEventListener('appinstalled', () => {
        console.log('[PWA] App installed successfully');
        deferredPrompt = null;

        if (installButton) {
            installButton.remove();
            installButton = null;
        }
    });

    let offlineBanner = null;

    function updateOnlineStatus() {
        if (navigator.onLine) {
            hideOfflineBanner();
        } else {
            showOfflineBanner();
        }
    }

    function showOfflineBanner() {
        if (offlineBanner) return;

        offlineBanner = document.createElement('div');
        offlineBanner.id = 'pwaOfflineBanner';
        offlineBanner.style.cssText = `
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 9999;
            padding: 0.75rem 1rem;
            text-align: center;
            font-size: 0.875rem;
            font-weight: 500;
            background: rgba(245, 158, 11, 0.95);
            color: #18181b;
            backdrop-filter: blur(8px);
            animation: slideUp 0.3s ease-out;
        `;
        offlineBanner.innerHTML = `<span>📴 离线模式 - 部分功能可能受限</span>`;

        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideUp {
                from { transform: translateY(100%); }
                to { transform: translateY(0); }
            }
        `;
        document.head.appendChild(style);

        document.body.appendChild(offlineBanner);
    }

    function hideOfflineBanner() {
        if (offlineBanner) {
            offlineBanner.remove();
            offlineBanner = null;
        }
    }

    window.addEventListener('online', updateOnlineStatus);
    window.addEventListener('offline', updateOnlineStatus);

    function showUpdateNotification() {
        const notification = document.createElement('div');
        notification.style.cssText = `
            position: fixed;
            top: 80px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 9999;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            background: var(--surface, #18181b);
            border: 1px solid var(--border-color, rgba(255,255,255,0.1));
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: fadeInDown 0.3s ease-out;
        `;
        notification.innerHTML = `
            <span style="color: var(--text-primary, #fafafa);">🆕 新版本可用</span>
            <button id="pwaUpdateBtn" style="
                padding: 0.5rem 1rem;
                border-radius: 8px;
                background: var(--primary, #3b82f6);
                color: white;
                border: none;
                cursor: pointer;
                font-weight: 500;
            ">刷新</button>
        `;

        document.body.appendChild(notification);

        document.getElementById('pwaUpdateBtn').addEventListener('click', () => {
            window.location.reload();
        });

        setTimeout(() => notification.remove(), 10000);
    }

    function init() {
        registerServiceWorker();
        updateOnlineStatus();
        console.log('[PWA] PWA helper initialized');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
