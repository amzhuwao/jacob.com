/**
 * Jacob Marketplace - Main Application JavaScript
 * Handles PWA registration, UI interactions, and client-side logic
 */

// Service Worker Registration
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register('/sw.js')
      .then((registration) => {
        console.log('✅ Service Worker registered successfully:', registration.scope);
        
        // Check for updates
        registration.addEventListener('updatefound', () => {
          const newWorker = registration.installing;
          newWorker.addEventListener('statechange', () => {
            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
              // New service worker available, show update notification
              showUpdateNotification();
            }
          });
        });
      })
      .catch((error) => {
        console.error('❌ Service Worker registration failed:', error);
      });
  });

  // Handle service worker updates
  let refreshing = false;
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (!refreshing) {
      refreshing = true;
      window.location.reload();
    }
  });
}

// PWA Install Prompt
let deferredPrompt;
const installButton = document.getElementById('pwa-install-btn');

window.addEventListener('beforeinstallprompt', (e) => {
  // Prevent the mini-infobar from appearing on mobile
  e.preventDefault();
  // Store the event for later use
  deferredPrompt = e;
  
  // Show install button if it exists
  if (installButton) {
    installButton.style.display = 'block';
  } else {
    // Create and show install banner
    showInstallBanner();
  }
});

// Handle install button click
if (installButton) {
  installButton.addEventListener('click', async () => {
    if (!deferredPrompt) return;
    
    // Show the install prompt
    deferredPrompt.prompt();
    
    // Wait for the user's response
    const { outcome } = await deferredPrompt.userChoice;
    console.log(`User response to install prompt: ${outcome}`);
    
    // Clear the deferred prompt
    deferredPrompt = null;
    installButton.style.display = 'none';
  });
}

// Track successful install
window.addEventListener('appinstalled', (e) => {
  console.log('✅ PWA installed successfully');
  deferredPrompt = null;
  
  // Hide install button/banner
  if (installButton) {
    installButton.style.display = 'none';
  }
  hideInstallBanner();
});

/**
 * Show install banner for browsers that support PWA
 */
function showInstallBanner() {
  // Only show if not already installed and banner hasn't been dismissed
  if (window.matchMedia('(display-mode: standalone)').matches) {
    return; // Already installed
  }
  
  if (localStorage.getItem('pwa-banner-dismissed') === 'true') {
    return; // User dismissed banner
  }
  
  const banner = document.createElement('div');
  banner.id = 'pwa-install-banner';
  banner.innerHTML = `
    <div style="position: fixed; bottom: 20px; left: 20px; right: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 16px 20px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 9999; display: flex; align-items: center; justify-content: space-between; gap: 12px; animation: slideUp 0.3s ease;">
      <div style="flex: 1;">
        <strong style="display: block; font-size: 16px; margin-bottom: 4px;">Install Jacob Marketplace</strong>
        <span style="font-size: 14px; opacity: 0.9;">Get quick access and work offline</span>
      </div>
      <button id="pwa-install-banner-btn" style="background: white; color: #667eea; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; white-space: nowrap;">Install</button>
      <button id="pwa-banner-close" style="background: transparent; color: white; border: none; padding: 8px; cursor: pointer; font-size: 20px; line-height: 1; opacity: 0.8;">×</button>
    </div>
  `;
  
  document.body.appendChild(banner);
  
  // Handle install button click
  document.getElementById('pwa-install-banner-btn').addEventListener('click', async () => {
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    console.log(`Install prompt outcome: ${outcome}`);
    deferredPrompt = null;
    hideInstallBanner();
  });
  
  // Handle close button click
  document.getElementById('pwa-banner-close').addEventListener('click', () => {
    localStorage.setItem('pwa-banner-dismissed', 'true');
    hideInstallBanner();
  });
}

/**
 * Hide install banner
 */
function hideInstallBanner() {
  const banner = document.getElementById('pwa-install-banner');
  if (banner) {
    banner.style.animation = 'slideDown 0.3s ease';
    setTimeout(() => banner.remove(), 300);
  }
}

/**
 * Show update notification when new service worker is available
 */
function showUpdateNotification() {
  const notification = document.createElement('div');
  notification.innerHTML = `
    <div style="position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background: #2563eb; color: white; padding: 12px 24px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 9999; display: flex; align-items: center; gap: 12px; animation: slideDown 0.3s ease;">
      <span>New version available!</span>
      <button id="update-app-btn" style="background: white; color: #2563eb; border: none; padding: 6px 16px; border-radius: 6px; font-weight: 600; cursor: pointer;">Update</button>
    </div>
  `;
  
  document.body.appendChild(notification);
  
  document.getElementById('update-app-btn').addEventListener('click', () => {
    if (navigator.serviceWorker.controller) {
      navigator.serviceWorker.controller.postMessage({ type: 'SKIP_WAITING' });
    }
  });
}

/**
 * Check if app is running in standalone mode (installed PWA)
 */
function isPWAInstalled() {
  return window.matchMedia('(display-mode: standalone)').matches ||
         window.navigator.standalone === true;
}

// Log PWA status
if (isPWAInstalled()) {
  console.log('✅ Running as installed PWA');
} else {
  console.log('🌐 Running in browser mode');
}

/**
 * Handle offline/online status
 */
window.addEventListener('online', () => {
  console.log('✅ Back online');
  showToast('You are back online', 'success');
});

window.addEventListener('offline', () => {
  console.log('⚠️ Offline mode');
  showToast('You are offline. Some features may be limited.', 'warning');
});

/**
 * Show toast notification
 */
function showToast(message, type = 'info') {
  const colors = {
    success: '#10b981',
    warning: '#f59e0b',
    error: '#ef4444',
    info: '#3b82f6'
  };
  
  const toast = document.createElement('div');
  toast.style.cssText = `
    position: fixed;
    bottom: 80px;
    left: 50%;
    transform: translateX(-50%);
    background: ${colors[type]};
    color: white;
    padding: 12px 24px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 10000;
    animation: slideUp 0.3s ease;
    max-width: 90%;
  `;
  toast.textContent = message;
  
  document.body.appendChild(toast);
  
  setTimeout(() => {
    toast.style.animation = 'slideDown 0.3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
  @keyframes slideUp {
    from { transform: translateX(-50%) translateY(100px); opacity: 0; }
    to { transform: translateX(-50%) translateY(0); opacity: 1; }
  }
  
  @keyframes slideDown {
    from { transform: translateX(-50%) translateY(0); opacity: 1; }
    to { transform: translateX(-50%) translateY(100px); opacity: 0; }
  }
`;
document.head.appendChild(style);
