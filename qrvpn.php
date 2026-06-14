<?php
/**
 * qrvpn — QR Code Scanner & Generator untuk VPN Login (Single File)
 *
 * Dua fungsi utama dalam satu halaman:
 *
 * [Tab 1 — SCAN]
 *   - Aktifkan kamera browser (getUserMedia)
 *   - Decode QR Code real-time menggunakan jsQR (pure JS, no server)
 *   - Auto-deteksi format hasil QR:
 *       • URL biasa        → tombol "Buka URL" langsung
 *       • vpn://...        → tombol "Login VPN" → POST ke endpoint
 *       • token=...        → ekstrak token → POST ke VPN portal
 *       • JSON credential  → parse + tampilkan + auto-fill form login
 *       • Plain text       → tampilkan + copy
 *   - Upload gambar QR (fallback, tanpa kamera)
 *   - Riwayat scan terakhir (localStorage, 10 entri)
 *
 * [Tab 2 — GENERATE]
 *   - Form input: URL VPN portal, username, password, token/OTP
 *   - Format payload yang di-encode ke QR:
 *       • URL saja         → QR berisi URL langsung
 *       • Credential JSON  → {"vpn":"...","u":"...","p":"...","t":"..."}
 *       • Custom text      → QR berisi teks bebas
 *   - Download QR sebagai PNG
 *   - Opsi ukuran + error correction level
 *   - Preview real-time saat mengetik
 *
 * [Tab 3 — LOGIN]
 *   - Form manual login VPN portal (URL + user + pass + token)
 *   - PHP backend: POST credential ke VPN web portal via cURL
 *   - Tampilkan response: redirect URL, cookie session, status
 *   - Support: form-based login, Bearer token, Basic Auth
 *
 * PHP 7.4+ | Tidak memerlukan dependency server-side
 * Library client-side:
 *   jsQR 1.4.0  — https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js
 *   qrcode      — https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js
 *   Tailwind CSS — https://cdn.tailwindcss.com
 */

date_default_timezone_set('Asia/Jakarta');

// ============================================================
// KONFIGURASI VPN
// ============================================================

// URL default VPN portal — ganti sesuai server
define('VPN_PORTAL_URL',     '');          // e.g. 'https://vpn.company.com/login'
define('VPN_TOKEN_ENDPOINT', '');          // e.g. 'https://vpn.company.com/api/auth'
define('VPN_DEFAULT_USER',   '');          // pre-fill username
define('APP_TITLE',          'QR VPN');

// ============================================================
// PHP BACKEND — Handle POST login
// ============================================================

$loginResult = null;
$loginError  = '';
$activeTab   = $_GET['tab'] ?? 'scan';    // scan | generate | login

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Action: POST login ke VPN portal via cURL ──────────────
    if ($action === 'vpn_login') {
        $vpnUrl   = trim($_POST['vpn_url']   ?? VPN_PORTAL_URL);
        $vpnUser  = trim($_POST['vpn_user']  ?? '');
        $vpnPass  = trim($_POST['vpn_pass']  ?? '');
        $vpnToken = trim($_POST['vpn_token'] ?? '');
        $authType = trim($_POST['auth_type'] ?? 'form');  // form | bearer | basic

        $activeTab = 'login';

        if (empty($vpnUrl)) {
            $loginError = 'URL VPN portal wajib diisi.';
        } elseif (!filter_var($vpnUrl, FILTER_VALIDATE_URL)) {
            $loginError = 'Format URL tidak valid.';
        } else {
            $loginResult = doVpnLogin($vpnUrl, $vpnUser, $vpnPass, $vpnToken, $authType);
        }
    }

    // ── Action: decode QR dari upload gambar (server-side fallback) ──
    if ($action === 'qr_upload_info') {
        // Info saja — decode dilakukan client-side via jsQR
        // Endpoint ini tidak digunakan; decode gambar = JS
        echo json_encode(['status' => 'client_side_only']);
        exit;
    }
}

// ============================================================
// FUNGSI BACKEND
// ============================================================

/**
 * POST credential ke VPN web portal via cURL.
 * Support: form POST, Bearer token header, Basic Auth.
 */
function doVpnLogin(
    string $url,
    string $user,
    string $pass,
    string $token,
    string $authType
): array {
    $ch = curl_init();

    $headers = ['Accept: text/html,application/json,*/*'];

    if ($authType === 'bearer' && $token !== '') {
        // Bearer Token auth
        $headers[] = 'Authorization: Bearer ' . $token;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPGET, true);

    } elseif ($authType === 'basic') {
        // HTTP Basic Auth
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
        curl_setopt($ch, CURLOPT_HTTPGET, true);

    } else {
        // Form POST (default)
        $postFields = [];
        if ($user  !== '') $postFields['username'] = $user;
        if ($pass  !== '') $postFields['password'] = $pass;
        if ($token !== '') $postFields['token']    = $token;
        if ($user  !== '') $postFields['user']     = $user;   // alias

        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }

    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,  // jangan auto-follow — kita mau lihat redirect
        CURLOPT_HEADER         => true,   // sertakan response header
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (QR-VPN-Login/1.0)',
        CURLOPT_COOKIEJAR      => '',     // capture cookie
        CURLOPT_COOKIEFILE     => '',
    ]);

    $tStart   = microtime(true);
    $raw      = curl_exec($ch);
    $elapsed  = round((microtime(true) - $tStart) * 1000);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    $info     = curl_getinfo($ch);
    curl_close($ch);

    if ($raw === false || $raw === '') {
        return [
            'success'   => false,
            'error'     => 'cURL gagal: ' . $curlErr,
            'http_code' => $httpCode,
            'elapsed'   => $elapsed,
        ];
    }

    // Pisahkan header dan body
    $headerSize  = $info['header_size'] ?? 0;
    $rawHeaders  = substr($raw, 0, $headerSize);
    $body        = substr($raw, $headerSize);

    // Parse header penting
    $headers_parsed = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (strpos($line, ':') !== false) {
            [$key, $val] = explode(':', $line, 2);
            $headers_parsed[strtolower(trim($key))] = trim($val);
        }
    }

    // Cek redirect location (login berhasil biasanya redirect)
    $location   = $headers_parsed['location']   ?? '';
    $setCookie  = $headers_parsed['set-cookie'] ?? '';

    // Deteksi sukses: 200 + body mengandung token/dashboard, atau 302 redirect
    $bodyLower  = strtolower(substr($body, 0, 2000));
    $looksGood  = ($httpCode === 302 || $httpCode === 301)
               || str_contains($bodyLower, 'dashboard')
               || str_contains($bodyLower, 'welcome')
               || str_contains($bodyLower, 'logout')
               || str_contains($bodyLower, 'success');

    // Parse JSON response jika ada
    $jsonBody = null;
    $jsonStart = strpos($body, '{');
    if ($jsonStart !== false) {
        $jsonBody = json_decode(substr($body, $jsonStart), true);
    }

    // Ekstrak token dari response
    $responseToken = '';
    if ($jsonBody) {
        $responseToken = $jsonBody['token']        ?? $jsonBody['access_token']
                      ?? $jsonBody['data']['token'] ?? $jsonBody['data']['access_token']
                      ?? '';
    }

    return [
        'success'        => $looksGood,
        'http_code'      => $httpCode,
        'elapsed'        => $elapsed,
        'redirect_url'   => $location,
        'set_cookie'     => substr($setCookie, 0, 200),
        'response_token' => $responseToken,
        'json_body'      => $jsonBody,
        'body_preview'   => htmlspecialchars(substr($body, 0, 800)),
        'raw_headers'    => htmlspecialchars(substr($rawHeaders, 0, 600)),
        'auth_type'      => $authType,
        'vpn_url'        => $url,
    ];
}

// ============================================================
// HTML OUTPUT
// ============================================================
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_TITLE ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- jsQR: pure-JS QR decoder (kamera + gambar) -->
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
    <!-- qrcode: QR generator ke canvas/PNG -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; }
        pre  { white-space: pre-wrap; word-break: break-all; font-size: 11px; }

        /* Tab active */
        .tab-btn         { transition: all .2s; }
        .tab-btn.active  { background: #6366f1; color: #fff; }
        .tab-pane        { display: none; }
        .tab-pane.active { display: block; }

        /* Video frame */
        #videoEl { width: 100%; border-radius: 12px; background: #1e293b; }
        #scanCanvas { display: none; }

        /* QR result badge */
        .qr-type-url     { background: #1d4ed8; }
        .qr-type-vpn     { background: #7c3aed; }
        .qr-type-json    { background: #0f766e; }
        .qr-type-token   { background: #b45309; }
        .qr-type-text    { background: #374151; }

        /* Scan animation border */
        .scan-active { box-shadow: 0 0 0 3px #6366f1, 0 0 20px #6366f155; }
        .scan-found  { box-shadow: 0 0 0 3px #16a34a, 0 0 20px #16a34a55; }

        /* QR preview canvas */
        #qrCanvas { border-radius: 8px; background: white; padding: 12px; }

        /* History item */
        .hist-item { cursor: pointer; transition: background .15s; }
        .hist-item:hover { background: #1e293b; }

        /* Dark form */
        .dark-input {
            background: #1e293b; border: 1px solid #334155; color: #e2e8f0;
            border-radius: 8px; padding: 8px 12px; width: 100%; font-size: 14px;
            outline: none; transition: border-color .2s;
        }
        .dark-input:focus { border-color: #6366f1; }
        .dark-input::placeholder { color: #64748b; }
        select.dark-input option { background: #1e293b; }

        /* Pulse dot */
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
        .pulse { animation: pulse 1.5s infinite; }
    </style>
</head>
<body class="min-h-screen p-4">
<div class="max-w-2xl mx-auto">

    <!-- Header -->
    <div class="mb-5 flex items-center gap-3">
        <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center text-xl">🔐</div>
        <div>
            <h1 class="text-xl font-bold text-white"><?= htmlspecialchars(APP_TITLE) ?></h1>
            <p class="text-slate-400 text-xs">QR Code Scanner &amp; Generator untuk VPN Login</p>
        </div>
        <div class="ml-auto text-xs text-slate-500"><?= date('H:i:s') ?> WIB</div>
    </div>

    <!-- Tab Navigation -->
    <div class="flex gap-2 mb-5">
        <button onclick="switchTab('scan')"
            class="tab-btn flex-1 py-2.5 rounded-xl text-sm font-semibold text-slate-300 bg-slate-800 <?= $activeTab === 'scan' ? 'active' : '' ?>"
            id="btn-scan">
            📷 Scan QR
        </button>
        <button onclick="switchTab('generate')"
            class="tab-btn flex-1 py-2.5 rounded-xl text-sm font-semibold text-slate-300 bg-slate-800 <?= $activeTab === 'generate' ? 'active' : '' ?>"
            id="btn-generate">
            ⚡ Generate QR
        </button>
        <button onclick="switchTab('login')"
            class="tab-btn flex-1 py-2.5 rounded-xl text-sm font-semibold text-slate-300 bg-slate-800 <?= $activeTab === 'login' ? 'active' : '' ?>"
            id="btn-login">
            🔑 Login VPN
        </button>
    </div>

    <!-- ═══════════════════════════════════════════════════════
         TAB 1 — SCAN
    ═══════════════════════════════════════════════════════ -->
    <div id="tab-scan" class="tab-pane <?= $activeTab === 'scan' ? 'active' : '' ?>">

        <!-- Kamera -->
        <div class="bg-slate-800 rounded-2xl p-4 mb-4">
            <div class="flex items-center justify-between mb-3">
                <span class="text-sm font-semibold text-slate-200">📷 Kamera</span>
                <div id="scanStatus" class="text-xs text-slate-400 flex items-center gap-1">
                    <span class="w-2 h-2 rounded-full bg-slate-500" id="statusDot"></span>
                    <span id="statusText">Belum aktif</span>
                </div>
            </div>

            <div id="videoWrap" class="relative rounded-xl overflow-hidden bg-slate-900 mb-3" style="min-height:220px">
                <video id="videoEl" autoplay muted playsinline></video>
                <canvas id="scanCanvas"></canvas>
                <!-- Scanner overlay -->
                <div id="scanOverlay" class="absolute inset-0 flex items-center justify-center pointer-events-none hidden">
                    <div class="w-48 h-48 border-2 border-indigo-400 rounded-xl opacity-60 relative">
                        <div class="absolute top-0 left-0 w-6 h-6 border-t-4 border-l-4 border-indigo-400 rounded-tl-lg"></div>
                        <div class="absolute top-0 right-0 w-6 h-6 border-t-4 border-r-4 border-indigo-400 rounded-tr-lg"></div>
                        <div class="absolute bottom-0 left-0 w-6 h-6 border-b-4 border-l-4 border-indigo-400 rounded-bl-lg"></div>
                        <div class="absolute bottom-0 right-0 w-6 h-6 border-b-4 border-r-4 border-indigo-400 rounded-br-lg"></div>
                        <div id="scanLine" class="absolute left-2 right-2 h-0.5 bg-indigo-400 top-0 opacity-70" style="animation: scanAnim 2s linear infinite"></div>
                    </div>
                </div>
                <!-- Placeholder -->
                <div id="videoPlaceholder" class="absolute inset-0 flex flex-col items-center justify-center text-slate-500">
                    <div class="text-4xl mb-2">📷</div>
                    <div class="text-sm">Klik "Mulai Scan" untuk mengaktifkan kamera</div>
                </div>
            </div>

            <!-- Kontrol kamera -->
            <div class="flex gap-2 flex-wrap">
                <button id="btnStart" onclick="startScan()"
                    class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold py-2 px-3 rounded-lg transition">
                    ▶ Mulai Scan
                </button>
                <button id="btnStop" onclick="stopScan()" disabled
                    class="flex-1 bg-slate-700 hover:bg-slate-600 text-slate-300 text-sm font-semibold py-2 px-3 rounded-lg transition disabled:opacity-40">
                    ⏹ Stop
                </button>
                <select id="cameraSelect" onchange="switchCamera()"
                    class="dark-input text-xs py-2" style="width:auto;min-width:120px">
                    <option value="">Kamera...</option>
                </select>
            </div>
        </div>

        <!-- Upload gambar QR (fallback) -->
        <div class="bg-slate-800 rounded-2xl p-4 mb-4">
            <div class="text-sm font-semibold text-slate-200 mb-2">🖼️ Upload Gambar QR</div>
            <label class="flex items-center gap-3 border-2 border-dashed border-slate-600 rounded-xl p-4 cursor-pointer hover:border-indigo-500 transition">
                <span class="text-2xl">📁</span>
                <div>
                    <div class="text-sm text-slate-300">Pilih file PNG / JPG / WebP</div>
                    <div class="text-xs text-slate-500">Decode dilakukan di browser — tidak dikirim ke server</div>
                </div>
                <input type="file" id="qrImageInput" accept="image/*" class="hidden" onchange="decodeFromImage(this)">
            </label>
            <canvas id="uploadCanvas" class="hidden"></canvas>
        </div>

        <!-- Hasil Scan -->
        <div id="resultBox" class="bg-slate-800 rounded-2xl p-4 mb-4 hidden">
            <div class="flex items-center justify-between mb-3">
                <span class="text-sm font-semibold text-slate-200">✅ Hasil Scan</span>
                <span id="qrTypeBadge" class="text-xs px-2 py-0.5 rounded-full text-white font-mono">TEXT</span>
            </div>

            <!-- Raw value -->
            <div class="bg-slate-900 rounded-lg p-3 mb-3 break-all font-mono text-xs text-green-400" id="rawValue"></div>

            <!-- Action buttons berdasarkan tipe -->
            <div id="actionButtons" class="flex flex-wrap gap-2 mb-3"></div>

            <!-- Parsed detail (JSON / credential) -->
            <div id="parsedDetail" class="hidden bg-slate-900 rounded-lg p-3 text-xs"></div>

            <!-- VPN Auto-Login result -->
            <div id="autoLoginResult" class="hidden mt-3"></div>
        </div>

        <!-- History -->
        <div class="bg-slate-800 rounded-2xl p-4">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-semibold text-slate-200">🕐 Riwayat Scan</span>
                <button onclick="clearHistory()" class="text-xs text-slate-500 hover:text-red-400 transition">Hapus semua</button>
            </div>
            <div id="historyList" class="space-y-1 text-xs text-slate-400">
                <div class="text-slate-500 italic" id="histEmpty">Belum ada riwayat</div>
            </div>
        </div>
    </div><!-- /tab-scan -->


    <!-- ═══════════════════════════════════════════════════════
         TAB 2 — GENERATE
    ═══════════════════════════════════════════════════════ -->
    <div id="tab-generate" class="tab-pane <?= $activeTab === 'generate' ? 'active' : '' ?>">

        <div class="bg-slate-800 rounded-2xl p-5 mb-4">
            <div class="text-sm font-semibold text-slate-200 mb-4">⚡ Buat QR Code VPN</div>

            <!-- Mode selector -->
            <div class="mb-4">
                <label class="block text-xs text-slate-400 mb-1">Mode QR</label>
                <div class="flex gap-2">
                    <button onclick="setMode('url')"     id="mode-url"     class="mode-btn flex-1 py-2 rounded-lg text-xs font-semibold bg-indigo-600 text-white transition">🔗 URL</button>
                    <button onclick="setMode('cred')"    id="mode-cred"    class="mode-btn flex-1 py-2 rounded-lg text-xs font-semibold bg-slate-700 text-slate-300 transition">👤 Credential</button>
                    <button onclick="setMode('token')"   id="mode-token"   class="mode-btn flex-1 py-2 rounded-lg text-xs font-semibold bg-slate-700 text-slate-300 transition">🔑 Token</button>
                    <button onclick="setMode('custom')"  id="mode-custom"  class="mode-btn flex-1 py-2 rounded-lg text-xs font-semibold bg-slate-700 text-slate-300 transition">✏️ Custom</button>
                </div>
            </div>

            <!-- URL mode -->
            <div id="gen-url" class="gen-mode">
                <label class="block text-xs text-slate-400 mb-1">URL VPN Portal <span class="text-red-400">*</span></label>
                <input type="url" id="genUrlInput" placeholder="https://vpn.company.com/login"
                    class="dark-input mb-3" oninput="updatePreview()" value="<?= htmlspecialchars(VPN_PORTAL_URL) ?>">
                <p class="text-xs text-slate-500">QR berisi URL langsung. Scan → buka di browser → landing di halaman login VPN.</p>
            </div>

            <!-- Credential mode -->
            <div id="gen-cred" class="gen-mode hidden">
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">URL VPN Portal</label>
                        <input type="url" id="genCredUrl" placeholder="https://vpn.company.com"
                            class="dark-input" oninput="updatePreview()" value="<?= htmlspecialchars(VPN_PORTAL_URL) ?>">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Username</label>
                        <input type="text" id="genCredUser" placeholder="username"
                            class="dark-input" oninput="updatePreview()" value="<?= htmlspecialchars(VPN_DEFAULT_USER) ?>">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Password</label>
                        <input type="password" id="genCredPass" placeholder="••••••••"
                            class="dark-input" oninput="updatePreview()">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Token / OTP</label>
                        <input type="text" id="genCredToken" placeholder="123456"
                            class="dark-input" oninput="updatePreview()" maxlength="12">
                    </div>
                </div>
                <div class="bg-amber-900/30 border border-amber-700/50 rounded-lg p-2 text-xs text-amber-300">
                    ⚠️ QR credential mengandung password. Gunakan hanya di jaringan terpercaya + jangan share QR ini.
                </div>
                <p class="text-xs text-slate-500 mt-2">
                    Payload: <code class="text-green-400" id="credPayloadPreview">{"vpn":"...","u":"...","p":"...","t":"..."}</code>
                </p>
            </div>

            <!-- Token mode -->
            <div id="gen-token" class="gen-mode hidden">
                <label class="block text-xs text-slate-400 mb-1">URL VPN Portal</label>
                <input type="url" id="genTokenUrl" placeholder="https://vpn.company.com/auth"
                    class="dark-input mb-3" oninput="updatePreview()" value="<?= htmlspecialchars(VPN_PORTAL_URL) ?>">
                <label class="block text-xs text-slate-400 mb-1">Token / API Key</label>
                <input type="text" id="genTokenVal" placeholder="eyJhbGciOiJIUzI1NiIs..."
                    class="dark-input mb-3" oninput="updatePreview()">
                <p class="text-xs text-slate-500">
                    Payload: <code class="text-green-400" id="tokenPayloadPreview">{"vpn":"...","token":"..."}</code>
                </p>
            </div>

            <!-- Custom mode -->
            <div id="gen-custom" class="gen-mode hidden">
                <label class="block text-xs text-slate-400 mb-1">Teks / URL bebas</label>
                <textarea id="genCustomText" rows="4" placeholder="Masukkan teks, URL, atau data apapun..."
                    class="dark-input resize-none" oninput="updatePreview()"></textarea>
            </div>

            <!-- QR Options -->
            <div class="grid grid-cols-2 gap-3 mt-4">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Ukuran</label>
                    <select id="qrSize" class="dark-input text-xs" onchange="updatePreview()">
                        <option value="200">200px (kecil)</option>
                        <option value="300" selected>300px (sedang)</option>
                        <option value="400">400px (besar)</option>
                        <option value="512">512px (HD)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Error Correction</label>
                    <select id="qrEC" class="dark-input text-xs" onchange="updatePreview()">
                        <option value="L">L — Low (7%)</option>
                        <option value="M" selected>M — Medium (15%)</option>
                        <option value="Q">Q — Quartile (25%)</option>
                        <option value="H">H — High (30%)</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Preview + Download -->
        <div class="bg-slate-800 rounded-2xl p-5 flex flex-col items-center">
            <div class="text-sm font-semibold text-slate-200 mb-3 self-start">👁️ Preview QR Code</div>
            <canvas id="qrCanvas" width="300" height="300" class="mb-3"></canvas>
            <div id="qrPayloadDisplay" class="text-xs text-slate-500 mb-4 text-center break-all max-w-xs font-mono" id="qrPayloadText"></div>
            <div class="flex gap-3 w-full">
                <button onclick="downloadQR()"
                    class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold py-2 rounded-lg transition">
                    ⬇ Download PNG
                </button>
                <button onclick="copyPayload()"
                    class="flex-1 bg-slate-700 hover:bg-slate-600 text-slate-200 text-sm font-semibold py-2 rounded-lg transition">
                    📋 Copy Payload
                </button>
                <button onclick="sendToLogin()"
                    class="flex-1 bg-violet-700 hover:bg-violet-600 text-white text-sm font-semibold py-2 rounded-lg transition">
                    🔑 → Login
                </button>
            </div>
        </div>
    </div><!-- /tab-generate -->


    <!-- ═══════════════════════════════════════════════════════
         TAB 3 — LOGIN VPN
    ═══════════════════════════════════════════════════════ -->
    <div id="tab-login" class="tab-pane <?= $activeTab === 'login' ? 'active' : '' ?>">

        <div class="bg-slate-800 rounded-2xl p-5 mb-4">
            <div class="text-sm font-semibold text-slate-200 mb-4">🔑 Login ke VPN Portal</div>

            <form method="POST" action="?tab=login" id="loginForm">
                <input type="hidden" name="action" value="vpn_login">

                <!-- URL VPN -->
                <div class="mb-4">
                    <label class="block text-xs text-slate-400 mb-1">URL VPN Portal <span class="text-red-400">*</span></label>
                    <input type="url" name="vpn_url" id="loginUrl"
                        value="<?= htmlspecialchars($_POST['vpn_url'] ?? VPN_PORTAL_URL) ?>"
                        placeholder="https://vpn.company.com/login"
                        class="dark-input" required>
                </div>

                <!-- Auth Type -->
                <div class="mb-4">
                    <label class="block text-xs text-slate-400 mb-1">Metode Autentikasi</label>
                    <div class="flex gap-2">
                        <?php foreach (['form' => '📋 Form POST', 'bearer' => '🎫 Bearer Token', 'basic' => '🔒 Basic Auth'] as $v => $l): ?>
                        <label class="flex-1 flex items-center gap-2 bg-slate-700 rounded-lg px-3 py-2 cursor-pointer text-xs text-slate-300">
                            <input type="radio" name="auth_type" value="<?= $v ?>"
                                <?= (($_POST['auth_type'] ?? 'form') === $v) ? 'checked' : '' ?>
                                onchange="toggleAuthFields()" class="accent-indigo-500">
                            <?= $l ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Credential fields -->
                <div id="fieldUserPass" class="grid grid-cols-2 gap-3 mb-4">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Username</label>
                        <input type="text" name="vpn_user" id="loginUser"
                            value="<?= htmlspecialchars($_POST['vpn_user'] ?? VPN_DEFAULT_USER) ?>"
                            placeholder="username" class="dark-input" autocomplete="username">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Password</label>
                        <input type="password" name="vpn_pass" id="loginPass"
                            value="<?= htmlspecialchars($_POST['vpn_pass'] ?? '') ?>"
                            placeholder="••••••••" class="dark-input" autocomplete="current-password">
                    </div>
                </div>

                <div id="fieldToken" class="mb-4">
                    <label class="block text-xs text-slate-400 mb-1">Token / OTP / API Key</label>
                    <input type="text" name="vpn_token" id="loginToken"
                        value="<?= htmlspecialchars($_POST['vpn_token'] ?? '') ?>"
                        placeholder="Token, OTP 6-digit, atau API Key"
                        class="dark-input" maxlength="512" autocomplete="one-time-code">
                </div>

                <!-- Submit -->
                <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2.5 rounded-xl text-sm transition">
                    🔐 Login ke VPN
                </button>
            </form>
        </div>

        <?php if ($loginError): ?>
        <div class="bg-red-900/40 border border-red-700 rounded-xl p-3 mb-4 text-sm text-red-300">
            ❌ <?= htmlspecialchars($loginError) ?>
        </div>
        <?php endif; ?>

        <?php if ($loginResult !== null): ?>
        <!-- Hasil Login -->
        <div class="bg-slate-800 rounded-2xl p-5 mb-4">
            <div class="text-sm font-semibold text-slate-200 mb-3">📊 Hasil Login</div>

            <!-- Status banner -->
            <?php if ($loginResult['success']): ?>
            <div class="bg-green-900/40 border border-green-600 rounded-xl p-3 mb-4 flex items-center gap-3">
                <span class="text-xl">✅</span>
                <div>
                    <div class="font-semibold text-green-300 text-sm">Login Berhasil</div>
                    <div class="text-xs text-green-400 mt-0.5">
                        HTTP <?= $loginResult['http_code'] ?> — <?= $loginResult['elapsed'] ?>ms —
                        <?= $loginResult['auth_type'] ?>
                    </div>
                    <?php if ($loginResult['redirect_url']): ?>
                    <div class="text-xs text-green-400 mt-1">
                        Redirect: <a href="<?= htmlspecialchars($loginResult['redirect_url']) ?>"
                            target="_blank" class="underline break-all"><?= htmlspecialchars($loginResult['redirect_url']) ?></a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-red-900/40 border border-red-600 rounded-xl p-3 mb-4 flex items-center gap-3">
                <span class="text-xl">❌</span>
                <div>
                    <div class="font-semibold text-red-300 text-sm">Login Gagal / Tidak Terdeteksi</div>
                    <div class="text-xs text-red-400 mt-0.5">
                        HTTP <?= $loginResult['http_code'] ?> — <?= $loginResult['elapsed'] ?>ms
                        <?php if (!empty($loginResult['error'])): ?>
                        — <?= htmlspecialchars($loginResult['error']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Detail rows -->
            <div class="space-y-2 text-xs">

                <!-- Token dari response -->
                <?php if (!empty($loginResult['response_token'])): ?>
                <div class="bg-slate-900 rounded-lg p-3">
                    <div class="text-slate-400 mb-1">🎫 Token dari Response</div>
                    <div class="font-mono text-green-400 break-all"><?= htmlspecialchars($loginResult['response_token']) ?></div>
                    <button onclick="copyText('<?= addslashes($loginResult['response_token']) ?>')"
                        class="mt-1.5 text-xs bg-slate-700 hover:bg-slate-600 px-2 py-0.5 rounded text-slate-300 transition">
                        📋 Copy Token
                    </button>
                </div>
                <?php endif; ?>

                <!-- Cookie -->
                <?php if (!empty($loginResult['set_cookie'])): ?>
                <div class="bg-slate-900 rounded-lg p-3">
                    <div class="text-slate-400 mb-1">🍪 Set-Cookie</div>
                    <div class="font-mono text-yellow-400 break-all"><?= htmlspecialchars($loginResult['set_cookie']) ?></div>
                </div>
                <?php endif; ?>

                <!-- Response Headers -->
                <details>
                    <summary class="text-slate-400 cursor-pointer hover:text-slate-300 py-1">📋 Response Headers</summary>
                    <pre class="bg-slate-900 rounded-lg p-3 text-slate-300 mt-1"><?= $loginResult['raw_headers'] ?></pre>
                </details>

                <!-- Body preview -->
                <details>
                    <summary class="text-slate-400 cursor-pointer hover:text-slate-300 py-1">📄 Body Preview (800 char)</summary>
                    <pre class="bg-slate-900 rounded-lg p-3 text-slate-300 mt-1"><?= $loginResult['body_preview'] ?></pre>
                </details>

                <!-- JSON parsed -->
                <?php if (!empty($loginResult['json_body'])): ?>
                <details open>
                    <summary class="text-slate-400 cursor-pointer hover:text-slate-300 py-1">{ } JSON Response</summary>
                    <pre class="bg-slate-900 rounded-lg p-3 text-green-400 mt-1"><?= htmlspecialchars(json_encode($loginResult['json_body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                </details>
                <?php endif; ?>

            </div><!-- /space-y-2 -->

            <!-- Aksi lanjut -->
            <?php if ($loginResult['success'] && $loginResult['redirect_url']): ?>
            <div class="mt-4">
                <a href="<?= htmlspecialchars($loginResult['redirect_url']) ?>" target="_blank"
                    class="block text-center bg-green-600 hover:bg-green-700 text-white font-semibold py-2 rounded-xl text-sm transition">
                    🌐 Buka Dashboard VPN →
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Tips -->
        <div class="bg-slate-800/60 rounded-2xl p-4 text-xs text-slate-500">
            <div class="font-semibold text-slate-400 mb-2">💡 Panduan Penggunaan</div>
            <ul class="space-y-1 list-disc list-inside">
                <li><strong class="text-slate-300">Form POST</strong>: kirim username/password sebagai form data (paling umum)</li>
                <li><strong class="text-slate-300">Bearer Token</strong>: kirim token di header Authorization (REST API)</li>
                <li><strong class="text-slate-300">Basic Auth</strong>: HTTP authentication standar (username:password)</li>
                <li>Redirect 301/302 = login sukses di kebanyakan web portal</li>
                <li>Gunakan tab <strong class="text-slate-300">Scan QR</strong> untuk auto-fill form ini dari QR Code</li>
            </ul>
        </div>
    </div><!-- /tab-login -->

</div><!-- /max-w-2xl -->

<!-- ============================================================
     JAVASCRIPT
============================================================ -->
<script>
// ── State ────────────────────────────────────────────────────
let videoStream   = null;
let scanInterval  = null;
let lastScanValue = '';
let scanCount     = 0;
let currentMode   = 'url';
let currentPayload = '';

const HISTORY_KEY = 'qrvpn_scan_history';
const MAX_HISTORY = 10;

// ── Tab navigation ───────────────────────────────────────────
function switchTab(tab) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    document.getElementById('btn-' + tab).classList.add('active');
    if (tab !== 'scan') stopScan();
    if (tab === 'generate') setTimeout(updatePreview, 100);
}

// ── SCAN: Start/Stop camera ──────────────────────────────────
async function startScan() {
    try {
        // Enumerate cameras
        const devices = await navigator.mediaDevices.enumerateDevices();
        const cameras = devices.filter(d => d.kind === 'videoinput');
        const sel = document.getElementById('cameraSelect');
        sel.innerHTML = cameras.map((c, i) =>
            `<option value="${c.deviceId}">${c.label || 'Kamera ' + (i+1)}</option>`
        ).join('');

        const deviceId = sel.value || undefined;
        const constraints = {
            video: deviceId
                ? { deviceId: { exact: deviceId }, facingMode: 'environment' }
                : { facingMode: { ideal: 'environment' } }
        };

        videoStream = await navigator.mediaDevices.getUserMedia(constraints);
        const video = document.getElementById('videoEl');
        video.srcObject = videoStream;
        await video.play();

        document.getElementById('videoPlaceholder').style.display = 'none';
        document.getElementById('scanOverlay').classList.remove('hidden');
        document.getElementById('videoWrap').classList.add('scan-active');
        document.getElementById('btnStart').disabled = true;
        document.getElementById('btnStop').disabled  = false;
        setStatus('🟢', 'Scanning...', 'bg-green-400 pulse');

        scanInterval = setInterval(scanFrame, 150);
    } catch (e) {
        setStatus('🔴', 'Gagal: ' + e.message, 'bg-red-400');
        alert('Tidak bisa akses kamera:\n' + e.message + '\n\nPastikan:\n• Izin kamera sudah diberikan\n• Halaman diakses via HTTPS atau localhost');
    }
}

function stopScan() {
    if (videoStream) {
        videoStream.getTracks().forEach(t => t.stop());
        videoStream = null;
    }
    if (scanInterval) { clearInterval(scanInterval); scanInterval = null; }
    const video = document.getElementById('videoEl');
    video.srcObject = null;
    document.getElementById('videoPlaceholder').style.display = 'flex';
    document.getElementById('scanOverlay').classList.add('hidden');
    document.getElementById('videoWrap').classList.remove('scan-active', 'scan-found');
    document.getElementById('btnStart').disabled = false;
    document.getElementById('btnStop').disabled  = true;
    setStatus('⚫', 'Berhenti', 'bg-slate-500');
}

function switchCamera() {
    if (videoStream) { stopScan(); startScan(); }
}

function scanFrame() {
    const video = document.getElementById('videoEl');
    if (!video.videoWidth) return;

    const canvas = document.getElementById('scanCanvas');
    canvas.width  = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0);

    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const code = jsQR(imageData.data, imageData.width, imageData.height, {
        inversionAttempts: 'dontInvert'
    });

    if (code && code.data !== lastScanValue) {
        lastScanValue = code.data;
        scanCount++;
        document.getElementById('videoWrap').classList.replace('scan-active', 'scan-found');
        setStatus('✅', 'Ditemukan!', 'bg-green-400');
        handleScanResult(code.data);
        // Reset setelah 3 detik agar bisa scan lagi
        setTimeout(() => {
            document.getElementById('videoWrap').classList.replace('scan-found', 'scan-active');
            setStatus('🟢', 'Scanning...', 'bg-green-400 pulse');
            lastScanValue = '';
        }, 3000);
    }
}

// ── SCAN: Decode dari upload gambar ─────────────────────────
function decodeFromImage(input) {
    if (!input.files[0]) return;
    const img = new Image();
    const url = URL.createObjectURL(input.files[0]);
    img.onload = () => {
        const canvas = document.getElementById('uploadCanvas');
        canvas.width  = img.width;
        canvas.height = img.height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0);
        const imageData = ctx.getImageData(0, 0, img.width, img.height);
        const code = jsQR(imageData.data, imageData.width, imageData.height, {
            inversionAttempts: 'attemptBoth'
        });
        URL.revokeObjectURL(url);
        if (code) {
            handleScanResult(code.data);
        } else {
            alert('QR Code tidak terdeteksi di gambar ini.\nPastikan gambar cukup jelas dan QR Code terlihat penuh.');
        }
    };
    img.src = url;
}

// ── SCAN: Handle hasil ───────────────────────────────────────
function handleScanResult(value) {
    addToHistory(value);
    showResult(value);
}

function showResult(value) {
    const box    = document.getElementById('resultBox');
    const raw    = document.getElementById('rawValue');
    const badge  = document.getElementById('qrTypeBadge');
    const btns   = document.getElementById('actionButtons');
    const detail = document.getElementById('parsedDetail');
    const autoLR = document.getElementById('autoLoginResult');

    box.classList.remove('hidden');
    raw.textContent = value;
    btns.innerHTML  = '';
    detail.classList.add('hidden');
    detail.innerHTML = '';
    autoLR.classList.add('hidden');
    autoLR.innerHTML = '';

    const type = detectQRType(value);
    badge.textContent = type.label;
    badge.className = 'text-xs px-2 py-0.5 rounded-full text-white font-mono ' + type.cls;

    // Buat action buttons
    type.actions.forEach(a => {
        const btn = document.createElement('button');
        btn.className = 'text-xs font-semibold px-3 py-1.5 rounded-lg transition ' + a.cls;
        btn.textContent = a.label;
        btn.onclick = () => a.fn(value);
        btns.appendChild(btn);
    });

    // Tampilkan detail parsed
    if (type.parsed) {
        detail.classList.remove('hidden');
        detail.innerHTML = type.parsed;
    }
}

function detectQRType(value) {
    // ── JSON credential ─────────────────────────────
    try {
        const j = JSON.parse(value);
        if (j.vpn || j.token || j.u || j.username) {
            const rows = Object.entries(j).map(([k, v]) =>
                `<div class="flex gap-2"><span class="text-slate-500 w-20 shrink-0">${escH(k)}</span>` +
                `<span class="text-green-400 break-all font-mono">${escH(String(v))}</span></div>`
            ).join('');
            return {
                label: 'JSON CRED',
                cls: 'qr-type-json',
                parsed: `<div class="space-y-1 text-xs">${rows}</div>`,
                actions: [
                    { label: '🔑 Auto Login', cls: 'bg-indigo-600 hover:bg-indigo-700 text-white',
                      fn: v => autoLoginFromQR(JSON.parse(v)) },
                    { label: '📋 Copy', cls: 'bg-slate-700 hover:bg-slate-600 text-slate-200',
                      fn: v => copyText(v) },
                    { label: '✏️ Edit di Login', cls: 'bg-violet-700 hover:bg-violet-600 text-white',
                      fn: v => fillLoginForm(JSON.parse(v)) },
                ]
            };
        }
    } catch(e) {}

    // ── token=... atau bearer=... ────────────────────
    const tokenMatch = value.match(/(?:token|bearer|key|api_key)=([^\s&]+)/i);
    if (tokenMatch) {
        const urlPart  = value.match(/(?:https?:\/\/[^\s?]+)/)?.[0] || '';
        const tokenVal = tokenMatch[1];
        return {
            label: 'TOKEN',
            cls: 'qr-type-token',
            parsed: `<div class="text-xs space-y-1">
                <div><span class="text-slate-500">Token:</span> <span class="text-yellow-400 font-mono break-all">${escH(tokenVal)}</span></div>
                ${urlPart ? `<div><span class="text-slate-500">URL:</span> <span class="text-blue-400 break-all">${escH(urlPart)}</span></div>` : ''}
            </div>`,
            actions: [
                { label: '🔑 Login dengan Token', cls: 'bg-indigo-600 hover:bg-indigo-700 text-white',
                  fn: () => autoLoginFromQR({ vpn: urlPart, token: tokenVal }) },
                { label: '📋 Copy Token', cls: 'bg-slate-700 hover:bg-slate-600 text-slate-200',
                  fn: () => copyText(tokenVal) },
            ]
        };
    }

    // ── vpn:// scheme ────────────────────────────────
    if (/^vpn:\/\//i.test(value)) {
        const params = {};
        try {
            const u = new URL(value.replace(/^vpn:/i, 'https:'));
            u.searchParams.forEach((v, k) => params[k] = v);
            params.vpn = u.origin + u.pathname;
        } catch(e) {}
        return {
            label: 'VPN SCHEME',
            cls: 'qr-type-vpn',
            parsed: `<div class="text-xs font-mono text-purple-300 break-all">${escH(value)}</div>`,
            actions: [
                { label: '🚀 Login VPN', cls: 'bg-violet-700 hover:bg-violet-600 text-white',
                  fn: v => autoLoginFromQR(params) },
                { label: '📋 Copy', cls: 'bg-slate-700 hover:bg-slate-600 text-slate-200',
                  fn: v => copyText(v) },
            ]
        };
    }

    // ── URL biasa ────────────────────────────────────
    if (/^https?:\/\//i.test(value)) {
        return {
            label: 'URL',
            cls: 'qr-type-url',
            parsed: null,
            actions: [
                { label: '🌐 Buka URL', cls: 'bg-blue-600 hover:bg-blue-700 text-white',
                  fn: v => window.open(v, '_blank') },
                { label: '🔑 Pakai sbg VPN URL', cls: 'bg-violet-700 hover:bg-violet-600 text-white',
                  fn: v => { document.getElementById('loginUrl').value = v; switchTab('login'); }},
                { label: '📋 Copy', cls: 'bg-slate-700 hover:bg-slate-600 text-slate-200',
                  fn: v => copyText(v) },
            ]
        };
    }

    // ── Plain text ───────────────────────────────────
    return {
        label: 'TEXT',
        cls: 'qr-type-text',
        parsed: null,
        actions: [
            { label: '📋 Copy', cls: 'bg-slate-700 hover:bg-slate-600 text-slate-200',
              fn: v => copyText(v) },
            { label: '🔍 Coba sebagai URL', cls: 'bg-slate-600 hover:bg-slate-500 text-slate-200',
              fn: v => { document.getElementById('loginUrl').value = 'https://' + v.replace(/^https?:\/\//,''); switchTab('login'); }},
        ]
    };
}

// ── SCAN: Auto-login dari QR credential ─────────────────────
function autoLoginFromQR(cred) {
    const vpnUrl = cred.vpn || cred.url || cred.host || '';
    const user   = cred.u   || cred.username || cred.user || '';
    const pass   = cred.p   || cred.password || cred.pass || '';
    const token  = cred.t   || cred.token    || '';

    // Fill login form + switch tab
    document.getElementById('loginUrl').value   = vpnUrl;
    document.getElementById('loginUser').value  = user;
    document.getElementById('loginPass').value  = pass;
    document.getElementById('loginToken').value = token;

    const autoLR = document.getElementById('autoLoginResult');
    autoLR.classList.remove('hidden');
    autoLR.innerHTML = `<div class="bg-slate-700 rounded-lg p-3 text-xs text-slate-300">
        <div class="font-semibold mb-1">🔑 Credential siap digunakan:</div>
        <div>URL: <span class="text-blue-400">${escH(vpnUrl || '(tidak ada)')}</span></div>
        ${user  ? `<div>User: <span class="text-green-400">${escH(user)}</span></div>` : ''}
        ${pass  ? `<div>Pass: <span class="text-yellow-400">••••••••</span></div>` : ''}
        ${token ? `<div>Token: <span class="text-purple-400">${escH(token.substring(0,20))}...</span></div>` : ''}
        <button onclick="switchTab('login')"
            class="mt-2 bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded-lg transition text-xs font-semibold">
            → Lanjut ke Login Tab
        </button>
    </div>`;
}

function fillLoginForm(cred) {
    document.getElementById('loginUrl').value   = cred.vpn || cred.url || '';
    document.getElementById('loginUser').value  = cred.u   || cred.username || '';
    document.getElementById('loginPass').value  = cred.p   || cred.password || '';
    document.getElementById('loginToken').value = cred.t   || cred.token    || '';
    switchTab('login');
}

// ── GENERATE ─────────────────────────────────────────────────
function setMode(mode) {
    currentMode = mode;
    document.querySelectorAll('.gen-mode').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.mode-btn').forEach(btn => {
        btn.classList.replace('bg-indigo-600', 'bg-slate-700');
        btn.classList.replace('text-white', 'text-slate-300');
    });
    document.getElementById('gen-' + mode).classList.remove('hidden');
    const btn = document.getElementById('mode-' + mode);
    btn.classList.replace('bg-slate-700', 'bg-indigo-600');
    btn.classList.replace('text-slate-300', 'text-white');
    updatePreview();
}

function buildPayload() {
    switch (currentMode) {
        case 'url': {
            const u = document.getElementById('genUrlInput').value.trim();
            return u;
        }
        case 'cred': {
            const vpn = document.getElementById('genCredUrl').value.trim();
            const u   = document.getElementById('genCredUser').value.trim();
            const p   = document.getElementById('genCredPass').value.trim();
            const t   = document.getElementById('genCredToken').value.trim();
            const obj = {};
            if (vpn) obj.vpn = vpn;
            if (u)   obj.u   = u;
            if (p)   obj.p   = p;
            if (t)   obj.t   = t;
            const payload = JSON.stringify(obj);
            document.getElementById('credPayloadPreview').textContent = payload;
            return payload;
        }
        case 'token': {
            const vpn = document.getElementById('genTokenUrl').value.trim();
            const tok = document.getElementById('genTokenVal').value.trim();
            const obj = {};
            if (vpn) obj.vpn   = vpn;
            if (tok) obj.token = tok;
            const payload = JSON.stringify(obj);
            document.getElementById('tokenPayloadPreview').textContent = payload;
            return payload;
        }
        case 'custom':
            return document.getElementById('genCustomText').value.trim();
    }
    return '';
}

async function updatePreview() {
    const payload = buildPayload();
    currentPayload = payload;

    const size = parseInt(document.getElementById('qrSize').value) || 300;
    const ec   = document.getElementById('qrEC').value || 'M';
    const canvas = document.getElementById('qrCanvas');
    const display = document.getElementById('qrPayloadDisplay');

    if (!payload) {
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        display.textContent = '(isi form di atas)';
        return;
    }

    display.textContent = payload.length > 80
        ? payload.substring(0, 77) + '...'
        : payload;

    try {
        canvas.width  = size;
        canvas.height = size;
        await QRCode.toCanvas(canvas, payload, {
            width: size,
            margin: 2,
            errorCorrectionLevel: ec,
            color: { dark: '#000000', light: '#ffffff' }
        });
    } catch(e) {
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#1e293b';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#ef4444';
        ctx.font = '14px monospace';
        ctx.fillText('Error: ' + e.message, 10, 30);
    }
}

function downloadQR() {
    if (!currentPayload) { alert('Generate QR terlebih dahulu.'); return; }
    const canvas = document.getElementById('qrCanvas');
    const link   = document.createElement('a');
    const ts     = new Date().toISOString().slice(0,19).replace(/[T:]/g,'-');
    link.download = 'qrvpn-' + ts + '.png';
    link.href    = canvas.toDataURL('image/png');
    link.click();
}

function copyPayload() {
    if (!currentPayload) { alert('Tidak ada payload untuk disalin.'); return; }
    copyText(currentPayload);
}

function sendToLogin() {
    if (!currentPayload) { alert('Generate QR terlebih dahulu.'); return; }
    try {
        const j = JSON.parse(currentPayload);
        fillLoginForm(j);
        return;
    } catch(e) {}
    // Kalau URL langsung
    if (/^https?:\/\//i.test(currentPayload)) {
        document.getElementById('loginUrl').value = currentPayload;
        switchTab('login');
    }
}

// ── LOGIN form helpers ────────────────────────────────────────
function toggleAuthFields() {
    const type = document.querySelector('input[name="auth_type"]:checked')?.value || 'form';
    const upWrap = document.getElementById('fieldUserPass');
    const tokWrap = document.getElementById('fieldToken');
    if (type === 'bearer') {
        upWrap.style.opacity  = '0.4';
        tokWrap.style.opacity = '1';
    } else if (type === 'basic') {
        upWrap.style.opacity  = '1';
        tokWrap.style.opacity = '0.4';
    } else {
        upWrap.style.opacity  = '1';
        tokWrap.style.opacity = '1';
    }
}

// ── History ──────────────────────────────────────────────────
function addToHistory(value) {
    let hist = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
    // Deduplicate
    hist = hist.filter(h => h.value !== value);
    hist.unshift({ value, time: new Date().toLocaleTimeString('id-ID') });
    if (hist.length > MAX_HISTORY) hist = hist.slice(0, MAX_HISTORY);
    localStorage.setItem(HISTORY_KEY, JSON.stringify(hist));
    renderHistory();
}

function renderHistory() {
    const list  = document.getElementById('historyList');
    const empty = document.getElementById('histEmpty');
    const hist  = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
    if (!hist.length) {
        if (empty) empty.style.display = '';
        list.querySelectorAll('.hist-item').forEach(el => el.remove());
        return;
    }
    if (empty) empty.style.display = 'none';
    list.querySelectorAll('.hist-item').forEach(el => el.remove());
    hist.forEach(h => {
        const el = document.createElement('div');
        el.className = 'hist-item flex items-center gap-2 rounded-lg px-2 py-1.5';
        el.onclick   = () => showResult(h.value);
        const type   = detectQRType(h.value);
        el.innerHTML =
            `<span class="text-xs px-1.5 py-0.5 rounded ${type.cls} text-white font-mono shrink-0">${type.label}</span>` +
            `<span class="text-slate-300 truncate flex-1" style="max-width:240px">${escH(h.value)}</span>` +
            `<span class="text-slate-600 shrink-0">${h.time}</span>`;
        list.appendChild(el);
    });
}

function clearHistory() {
    localStorage.removeItem(HISTORY_KEY);
    renderHistory();
}

// ── Utils ─────────────────────────────────────────────────────
function setStatus(icon, text, dotCls) {
    document.getElementById('statusText').textContent = text;
    const dot = document.getElementById('statusDot');
    dot.className = 'w-2 h-2 rounded-full ' + dotCls;
}

function escH(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        const msg = document.createElement('div');
        msg.className = 'fixed top-4 right-4 bg-green-700 text-white text-xs px-4 py-2 rounded-xl shadow-xl z-50';
        msg.textContent = '✅ Disalin ke clipboard';
        document.body.appendChild(msg);
        setTimeout(() => msg.remove(), 2000);
    } catch(e) {
        prompt('Salin manual:', text);
    }
}

// ── Scan line CSS animation ───────────────────────────────────
const styleEl = document.createElement('style');
styleEl.textContent = `
  @keyframes scanAnim {
    0%   { top: 4px;   opacity: 1; }
    50%  { top: calc(100% - 4px); opacity: 0.7; }
    100% { top: 4px;   opacity: 1; }
  }
`;
document.head.appendChild(styleEl);

// ── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    renderHistory();
    updatePreview();
    toggleAuthFields();

    // Deteksi apakah ada kamera
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        document.getElementById('btnStart').disabled = true;
        setStatus('⚠️', 'Kamera tidak tersedia (butuh HTTPS)', 'bg-yellow-400');
    }
});
</script>

</body>
</html>
