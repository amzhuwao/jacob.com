<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#667eea">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/images/icons/icon-192x192.png">
    <title>PWA Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }

        .card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin: 10px 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .status {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            margin: 5px 0;
            background: #f9f9f9;
            border-radius: 4px;
        }

        .check {
            color: #10b981;
            font-weight: bold;
        }

        .cross {
            color: #ef4444;
            font-weight: bold;
        }

        button {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            margin: 5px;
        }

        button:hover {
            background: #764ba2;
        }

        pre {
            background: #f0f0f0;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
        }
    </style>
</head>

<body>
    <h1>PWA Installation Diagnostic</h1>

    <div class="card">
        <h2>Status Checks</h2>
        <div class="status">
            <span>Manifest Loading</span>
            <span id="manifest-check" class="cross">❌ Checking...</span>
        </div>
        <div class="status">
            <span>Service Worker Support</span>
            <span id="sw-check" class="check">✓</span>
        </div>
        <div class="status">
            <span>Service Worker Registered</span>
            <span id="sw-registered" class="cross">❌ Checking...</span>
        </div>
        <div class="status">
            <span>beforeinstallprompt Event</span>
            <span id="prompt-check" class="cross">❌ Waiting...</span>
        </div>
        <div class="status">
            <span>HTTPS or Localhost</span>
            <span id="https-check" class="check">✓</span>
        </div>
    </div>

    <div class="card">
        <h2>Actions</h2>
        <button onclick="testManifest()">Test Manifest</button>
        <button onclick="registerSW()">Register Service Worker</button>
        <button onclick="clearStorage()">Clear All Storage</button>
        <button onclick="triggerInstall()">Trigger Install (if available)</button>
    </div>

    <div class="card">
        <h2>Manifest Content</h2>
        <pre id="manifest-content">Loading...</pre>
    </div>

    <div class="card">
        <h2>Console Output</h2>
        <pre id="console-output"></pre>
    </div>

    <script>
        let deferredPrompt = null;

        // Override console.log to capture output
        const originalLog = console.log;
        const consoleOutput = [];
        console.log = function(...args) {
            originalLog.apply(console, args);
            const output = args.map(a => typeof a === 'object' ? JSON.stringify(a, null, 2) : String(a)).join(' ');
            consoleOutput.push(output);
            document.getElementById('console-output').textContent = consoleOutput.slice(-20).join('\n');
        };

        // Check HTTPS
        document.getElementById('https-check').innerHTML =
            (location.protocol === 'https:' || location.hostname === 'localhost') ?
            '<span class="check">✓ HTTPS or Localhost</span>' :
            '<span class="cross">✗ Must use HTTPS</span>';

        // Check SW Support
        document.getElementById('sw-check').innerHTML =
            'serviceWorker' in navigator ?
            '<span class="check">✓</span>' :
            '<span class="cross">✗</span>';

        // Test manifest
        async function testManifest() {
            try {
                const response = await fetch('/manifest.json');
                const manifest = await response.json();
                document.getElementById('manifest-content').textContent = JSON.stringify(manifest, null, 2);
                document.getElementById('manifest-check').innerHTML = '<span class="check">✓ Loaded</span>';
                console.log('✅ Manifest loaded:', manifest);
                return manifest;
            } catch (error) {
                document.getElementById('manifest-check').innerHTML = '<span class="cross">✗ Error</span>';
                console.log('❌ Manifest error:', error);
                throw error;
            }
        }

        // Register SW
        async function registerSW() {
            if ('serviceWorker' in navigator) {
                try {
                    const registration = await navigator.serviceWorker.register('/sw.js');
                    console.log('✅ SW registered:', registration.scope);
                    document.getElementById('sw-registered').innerHTML = '<span class="check">✓ Registered</span>';
                } catch (error) {
                    console.log('❌ SW registration failed:', error);
                    document.getElementById('sw-registered').innerHTML = '<span class="cross">✗ Failed</span>';
                }
            }
        }

        // Clear storage
        function clearStorage() {
            localStorage.clear();
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.getRegistrations().then(regs => {
                    regs.forEach(reg => reg.unregister());
                    console.log('🧹 Cleared all storage and SW');
                });
            }
            // Clear IndexedDB
            indexedDB.databases().then(dbs => {
                dbs.forEach(db => indexedDB.deleteDatabase(db.name));
            });
            alert('All storage cleared! Reload the page and wait 30 seconds.');
        }

        // Trigger install
        function triggerInstall() {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                console.log('📲 Install prompt triggered');
            } else {
                console.log('⚠️ Install prompt not available yet. Wait 30 seconds and ensure beforeinstallprompt fires.');
            }
        }

        // Listen for beforeinstallprompt
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            document.getElementById('prompt-check').innerHTML = '<span class="check">✓ Available</span>';
            console.log('✅ beforeinstallprompt fired!');
            console.log('💡 Click "Trigger Install" button to show install dialog');
        });

        // Track app installed
        window.addEventListener('appinstalled', () => {
            console.log('✅ App installed!');
        });

        // Run initial checks
        console.log('🚀 PWA Diagnostic Page Loaded');
        console.log('📍 URL:', window.location.href);
        console.log('🔒 Protocol:', location.protocol);
        testManifest();
        setTimeout(() => registerSW(), 100);
    </script>
</body>

</html>