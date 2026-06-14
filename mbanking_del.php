<?php
/**
 * mBankingDEL — Deaktivasi User mBanking via CBS (Single File)
 *
 * Mengirim request deaktivasi/unregister user mBanking ke CBS
 * melalui jalur SIS/Assist Switching Middleware (assist-switching_v3_pro).
 *
 * Flow:
 *   1. Get OAuth access token dari myassist.sis1.net
 *   2. Bangun ISO 8583 request: MTI=009, KT=51
 *      MSG format: CS#{faktur}#mBankingDEL#{noHP}#{kodeAgen}
 *   3. POST ke switching.mcoll.sis1.net/.../mobile-digital
 *   4. Parse response: AR#{faktur}#SUKSES#User telah dinonaktifkan
 *
 * CBS handler (mbanking.controller.php):
 *   MTI=009 (DIGITAL_BANK_REGISTER) + KT=51 (TRX_MB_DEAKTIVASI_FROM_CORE)
 *   → Unregister(): DELETE agen_smsbanking + agen_aktifasi WHERE HP + Agen
 *
 * Credentials A-000300 (BPR Penataran Kabupaten Blitar):
 *   auth_client_id  = 000087 (dari assist-bpr.net/env/)
 *   msKodeH2H       = A-000300 (OAUTH_USERNAME)
 *   msCDSID         = babba586c65c1a8119cfe6a6dae9972f (DE061_SIM_SERIAL)
 *
 * PHP 8.1+
 */

date_default_timezone_set('Asia/Jakarta');

// ============================================================
// KONFIGURASI — Credentials A-000300 (BPR Penataran Blitar)
// ============================================================

// OAuth credentials (dari assist-bpr.net/env/ + config.sql)
define('OAUTH_CLIENT_ID',      '000087');
define('OAUTH_CLIENT_SECRET',  '274FrdhikpazQXdLtv5kNoRucN7SlQPq');
define('OAUTH_CORPORATE_ID',   '553231');
define('OAUTH_SERTIFIKAT',     'd9ebe47971b415daadc3440ee4070aea'); // msAuth_SERTIFIKAT_API
define('OAUTH_KODE_APLIKASI',  'BPRPAS');
define('OAUTH_USERNAME',       'A-000300');                         // msKodeH2H
define('OAUTH_PASSWORD',       '');                                 // isi jika diperlukan

// Device / SIM serial
define('DE061_SIM_SERIAL',     'babba586c65c1a8119cfe6a6dae9972f'); // msCDSID

// Kode agen
define('KODE_AGEN',            'A-000300');

// URL endpoints
define('URL_GET_TOKEN',  'http://myassist.sis1.net/assist-auth_api/public/oauth/getaccesstoken');
define('URL_DIGITAL',    'http://switching.mcoll.sis1.net/assist-switching_v3_pro/public/mobile-digital');

// Token cache file (di-cache agar tidak request token setiap kali)
define('TOKEN_CACHE_FILE', __DIR__ . '/storage/cds/cache/mbanking_del_token.cache');

// ============================================================
// FUNGSI HELPER
// ============================================================

/**
 * Waktu sekarang format HH:MM:SS WIB.
 */
function SNow(): string {
    return date('H:i:s');
}

/**
 * Generate nomor faktur (DE037): 12 digit — HHMMSS + 6 digit random.
 */
function generateFaktur(): string {
    return date('His') . str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Ambil access token dari cache atau request baru ke OAuth server.
 * Cache disimpan di TOKEN_CACHE_FILE.
 */
function getAccessToken(): array {
    // Coba baca dari cache
    if (file_exists(TOKEN_CACHE_FILE)) {
        $cached = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
        if (!empty($cached['access_token']) && !empty($cached['expires_at'])) {
            if (time() < ($cached['expires_at'] - 60)) {
                return [
                    'success'      => true,
                    'token'        => $cached['access_token'],
                    'from_cache'   => true,
                    'http_code'    => '-',
                    'raw'          => '',
                ];
            }
        }
    }

    // Request token baru
    $deviceId = DE061_SIM_SERIAL
        ?: ($_SERVER['SERVER_ADDR'] ?? '')
        ?: (KODE_AGEN ?: md5(gethostname()));

    $postBody = [
        'PLATFORM'      => 'android',
        'DEVICEID'      => $deviceId,
        'VERSIAPLIKASI' => '1.2.6',
        'USERNAME'      => OAUTH_USERNAME,
    ];
    if (OAUTH_KODE_APLIKASI !== '') {
        $postBody['KODEAPLIKASI'] = OAUTH_KODE_APLIKASI;
    }
    if (OAUTH_PASSWORD !== '') {
        $postBody['PASSWORD'] = OAUTH_PASSWORD;
    }
    $body = http_build_query($postBody);

    $kodeSertifikat = OAUTH_SERTIFIKAT ?: OAUTH_CLIENT_ID;

    $headers = [
        'Authorization: Bearer ' . $kodeSertifikat,
        'Content-Type: application/x-www-form-urlencoded',
    ];

    $ch = curl_init(URL_GET_TOKEN);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Strip PHP Notice/Warning HTML jika ada
    $jsonStart = strpos($raw, '{');
    if ($jsonStart !== false && $jsonStart > 0) {
        $raw = substr($raw, $jsonStart);
    }

    $resp = json_decode($raw, true);
    $token = $resp['data']['access_token'] ?? $resp['access_token'] ?? '';
    $expiresIn = (int)($resp['data']['expires_in'] ?? $resp['expires_in'] ?? 3600);

    if (!empty($token)) {
        // Simpan ke cache
        $cacheDir = dirname(TOKEN_CACHE_FILE);
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
        file_put_contents(TOKEN_CACHE_FILE, json_encode([
            'access_token' => $token,
            'expires_at'   => time() + $expiresIn,
        ]));
        return [
            'success'    => true,
            'token'      => $token,
            'from_cache' => false,
            'http_code'  => $httpCode,
            'raw'        => $raw,
        ];
    }

    return [
        'success'    => false,
        'token'      => '',
        'from_cache' => false,
        'http_code'  => $httpCode,
        'raw'        => $raw,
        'error'      => $resp['message'] ?? $resp['error'] ?? 'Token kosong',
    ];
}

/**
 * Kirim request deaktivasi mBanking ke CBS.
 *
 * @param string $noHP      Nomor HP user yang akan dinonaktifkan (format: 08xxxxxxxx)
 * @param string $kodeAgen  Kode agen (default: KODE_AGEN konstanta)
 * @return array
 */
function sendMBankingDEL(string $noHP, string $kodeAgen = KODE_AGEN): array {
    // Step 1: Get token
    $tokenResult = getAccessToken();
    if (!$tokenResult['success']) {
        return [
            'success'      => false,
            'step'         => 'token',
            'error'        => $tokenResult['error'] ?? 'Gagal ambil token',
            'token_result' => $tokenResult,
        ];
    }
    $accessToken = $tokenResult['token'];

    // Step 2: Bangun ISO 8583 request
    //   MTI = 009 (DIGITAL_BANK_REGISTER)
    //   KT  = 51  (TRX_MB_DEAKTIVASI_FROM_CORE)
    //   MSG = CS#{faktur}#mBankingDEL#{noHP}#{kodeAgen}
    $faktur  = generateFaktur();
    $msg     = "CS#{$faktur}#mBankingDEL#{$noHP}#{$kodeAgen}";

    $isoRequest = [
        'MTI'  => '009',
        'KT'   => '51',
        'AGEN' => $kodeAgen,
        'MSG'  => $msg,
    ];

    // Step 3: POST ke CBS
    $deviceId = DE061_SIM_SERIAL ?: (KODE_AGEN ?: md5(gethostname()));
    $postBody = http_build_query([
        'cCode'          => json_encode($isoRequest),
        'DEVICEID'       => $deviceId,
        'PLATFORM'       => 'android',
        'VERSIAPLIKASI'  => '1.2.6',
    ]);

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/x-www-form-urlencoded',
    ];

    $tStart = microtime(true);
    $ch = curl_init(URL_DIGITAL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postBody,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $rawResponse = curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $elapsed     = round((microtime(true) - $tStart) * 1000);
    curl_close($ch);

    // Step 4: Parse response
    // Strip PHP Notice/Warning HTML jika ada
    $jsonStart = strpos($rawResponse, '{');
    if ($jsonStart !== false && $jsonStart > 0) {
        $rawResponse = substr($rawResponse, $jsonStart);
    }

    $respJson = json_decode($rawResponse, true);
    $dataStr  = $respJson['data'] ?? '';

    // Format response CBS: AR#{faktur}#SUKSES#User telah dinonaktifkan
    //                  atau AR#{faktur}#GAGAL#{pesan error}
    $parts   = explode('#', $dataStr);
    $status  = $parts[2] ?? '';   // SUKSES / GAGAL
    $message = $parts[3] ?? '';   // pesan detail

    $success = (strtoupper($status) === 'SUKSES');

    return [
        'success'      => $success,
        'step'         => 'del',
        'status'       => $status,
        'message'      => $message,
        'faktur'       => $faktur,
        'no_hp'        => $noHP,
        'kode_agen'    => $kodeAgen,
        'http_code'    => $httpCode,
        'elapsed_ms'   => $elapsed,
        'iso_request'  => $isoRequest,
        'raw_response' => $rawResponse,
        'token_result' => $tokenResult,
    ];
}

// ============================================================
// HANDLE POST REQUEST
// ============================================================

$result      = null;
$errorMsg    = '';
$formNoHP    = trim($_POST['no_hp']    ?? '');
$formKodeAgen = trim($_POST['kode_agen'] ?? KODE_AGEN);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_del'])) {
    // Validasi
    if (empty($formNoHP)) {
        $errorMsg = '⚠️ Nomor HP wajib diisi.';
    } elseif (!preg_match('/^0[0-9]{8,13}$/', $formNoHP)) {
        $errorMsg = '⚠️ Format Nomor HP tidak valid. Gunakan format: 08xxxxxxxx';
    } elseif (empty($formKodeAgen)) {
        $errorMsg = '⚠️ Kode Agen wajib diisi.';
    } else {
        $result = sendMBankingDEL($formNoHP, $formKodeAgen);
    }
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
    <title>mBankingDEL — Deaktivasi User mBanking</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; }
        pre  { white-space: pre-wrap; word-break: break-all; font-size: 12px; }
        .step-box { border-left: 4px solid #6366f1; background: #f8fafc; }
        .ok   { color: #16a34a; }
        .err  { color: #dc2626; }
        .warn { color: #d97706; }
    </style>
</head>
<body class="min-h-screen p-6">
<div class="max-w-2xl mx-auto">

    <!-- Header -->
    <div class="bg-white rounded-2xl shadow p-6 mb-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-1">
            🗑️ mBankingDEL
        </h1>
        <p class="text-gray-500 text-sm">Deaktivasi User mBanking — A-000300 BPR Penataran Kabupaten Blitar</p>
        <div class="mt-3 flex flex-wrap gap-2 text-xs">
            <span class="bg-indigo-100 text-indigo-700 px-2 py-1 rounded font-mono">MTI=009</span>
            <span class="bg-indigo-100 text-indigo-700 px-2 py-1 rounded font-mono">KT=51</span>
            <span class="bg-gray-100 text-gray-600 px-2 py-1 rounded font-mono">DIGITAL_BANK_REGISTER</span>
            <span class="bg-gray-100 text-gray-600 px-2 py-1 rounded font-mono">TRX_MB_DEAKTIVASI_FROM_CORE</span>
        </div>
    </div>

    <!-- Form -->
    <div class="bg-white rounded-2xl shadow p-6 mb-6">
        <h2 class="font-semibold text-gray-700 mb-4">📋 Form Deaktivasi</h2>
        <form method="POST" action="">
            <!-- Nomor HP -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Nomor HP User <span class="text-red-500">*</span>
                </label>
                <input type="text" name="no_hp" id="noHP"
                    value="<?= htmlspecialchars($formNoHP) ?>"
                    placeholder="08xxxxxxxxxx"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400"
                    required>
                <p class="text-xs text-gray-400 mt-1">Format: 08xxxxxxxx (tanpa +62 atau spasi)</p>
            </div>

            <!-- Kode Agen -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Kode Agen <span class="text-red-500">*</span>
                </label>
                <input type="text" name="kode_agen" id="kodeAgen"
                    value="<?= htmlspecialchars($formKodeAgen) ?>"
                    placeholder="A-000300"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400"
                    required>
            </div>

            <?php if ($errorMsg): ?>
            <div class="mb-4 bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm">
                <?= htmlspecialchars($errorMsg) ?>
            </div>
            <?php endif; ?>

            <!-- Konfirmasi -->
            <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $result === null): ?>
            <button type="submit" name="submit_del" value="1"
                onclick="return confirm('Yakin ingin menonaktifkan user mBanking dengan HP: ' + document.getElementById('noHP').value + ' ?\n\nTindakan ini akan menghapus data aktivasi dari CBS.')"
                class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg py-2 px-4 text-sm transition">
                🗑️ Nonaktifkan User mBanking
            </button>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($result !== null): ?>
    <!-- Hasil -->
    <div class="bg-white rounded-2xl shadow p-6 mb-6">
        <h2 class="font-semibold text-gray-700 mb-4">📊 Hasil Deaktivasi</h2>

        <!-- Status Banner -->
        <?php if ($result['success']): ?>
        <div class="bg-green-50 border border-green-300 rounded-xl p-4 mb-4 flex items-start gap-3">
            <span class="text-2xl">✅</span>
            <div>
                <div class="font-bold text-green-800">User Berhasil Dinonaktifkan</div>
                <div class="text-green-700 text-sm mt-1">
                    <?= htmlspecialchars($result['message'] ?: 'User telah dinonaktifkan') ?>
                </div>
                <div class="text-xs text-green-600 mt-1">
                    HP: <strong><?= htmlspecialchars($result['no_hp']) ?></strong> |
                    Agen: <strong><?= htmlspecialchars($result['kode_agen']) ?></strong> |
                    Faktur: <strong><?= htmlspecialchars($result['faktur']) ?></strong>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="bg-red-50 border border-red-300 rounded-xl p-4 mb-4 flex items-start gap-3">
            <span class="text-2xl">❌</span>
            <div>
                <div class="font-bold text-red-800">Deaktivasi Gagal</div>
                <div class="text-red-700 text-sm mt-1">
                    <?= htmlspecialchars($result['error'] ?? $result['message'] ?? 'Unknown error') ?>
                </div>
                <?php if (!empty($result['status'])): ?>
                <div class="text-xs text-red-600 mt-1">Status CBS: <strong><?= htmlspecialchars($result['status']) ?></strong></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Detail Steps -->
        <div class="space-y-3">

            <!-- Step 1: Token -->
            <div class="step-box rounded-r-lg p-3">
                <div class="text-xs font-bold text-indigo-600 mb-1">━━ STEP 1: GET TOKEN ━━</div>
                <div class="text-xs text-gray-600 grid grid-cols-2 gap-1">
                    <span class="text-gray-500">Token URL</span>
                    <span class="font-mono"><?= htmlspecialchars(URL_GET_TOKEN) ?></span>
                    <span class="text-gray-500">Kode Agen</span>
                    <span class="font-mono"><?= htmlspecialchars(KODE_AGEN) ?></span>
                    <span class="text-gray-500">Dari Cache</span>
                    <span class="font-mono <?= $result['token_result']['from_cache'] ? 'ok' : 'warn' ?>">
                        <?= $result['token_result']['from_cache'] ? 'YA' : 'TIDAK (baru)' ?>
                    </span>
                    <span class="text-gray-500">Sukses</span>
                    <span class="font-mono <?= $result['token_result']['success'] ? 'ok' : 'err' ?>">
                        <?= $result['token_result']['success'] ? 'YA' : 'TIDAK' ?>
                    </span>
                    <span class="text-gray-500">HTTP Code</span>
                    <span class="font-mono"><?= htmlspecialchars((string)($result['token_result']['http_code'] ?? '-')) ?></span>
                </div>
            </div>

            <!-- Step 2: ISO Request -->
            <?php if (isset($result['iso_request'])): ?>
            <div class="step-box rounded-r-lg p-3">
                <div class="text-xs font-bold text-indigo-600 mb-1">━━ STEP 2: ISO 8583 REQUEST (MTI=009, KT=51) ━━</div>
                <pre class="text-xs bg-gray-50 rounded p-2 mt-1"><?= htmlspecialchars(json_encode($result['iso_request'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                <div class="text-xs text-gray-500 mt-1">MSG: <code class="bg-gray-100 px-1 rounded"><?= htmlspecialchars($result['iso_request']['MSG']) ?></code></div>
            </div>
            <?php endif; ?>

            <!-- Step 3: HTTP POST -->
            <div class="step-box rounded-r-lg p-3">
                <div class="text-xs font-bold text-indigo-600 mb-1">━━ STEP 3: HTTP POST ke CBS ━━</div>
                <div class="text-xs text-gray-600 grid grid-cols-2 gap-1">
                    <span class="text-gray-500">URL</span>
                    <span class="font-mono text-xs break-all"><?= htmlspecialchars(URL_DIGITAL) ?></span>
                    <span class="text-gray-500">HTTP Code</span>
                    <span class="font-mono <?= $result['http_code'] == 200 ? 'ok' : 'err' ?>">
                        <?= htmlspecialchars((string)$result['http_code']) ?> (<?= $result['elapsed_ms'] ?>ms)
                    </span>
                </div>
            </div>

            <!-- Step 4: Response -->
            <div class="step-box rounded-r-lg p-3">
                <div class="text-xs font-bold text-indigo-600 mb-1">━━ STEP 4: RESPONSE CBS ━━</div>
                <div class="text-xs text-gray-600 grid grid-cols-2 gap-1">
                    <span class="text-gray-500">Status</span>
                    <span class="font-mono font-bold <?= $result['success'] ? 'ok' : 'err' ?>">
                        <?= htmlspecialchars($result['status'] ?? '-') ?>
                    </span>
                    <span class="text-gray-500">Message</span>
                    <span class="font-mono"><?= htmlspecialchars($result['message'] ?? '-') ?></span>
                    <span class="text-gray-500">Faktur</span>
                    <span class="font-mono"><?= htmlspecialchars($result['faktur'] ?? '-') ?></span>
                </div>
                <details class="mt-2">
                    <summary class="text-xs text-indigo-500 cursor-pointer hover:underline">Raw Response</summary>
                    <pre class="text-xs bg-gray-50 rounded p-2 mt-1"><?= htmlspecialchars($result['raw_response'] ?? '') ?></pre>
                </details>
            </div>

        </div><!-- /space-y-3 -->

        <!-- Tombol Baru -->
        <div class="mt-4">
            <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>"
               class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg py-2 px-4 transition">
                ← Deaktivasi User Lain
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Info -->
    <div class="bg-white rounded-2xl shadow p-5 text-xs text-gray-500">
        <div class="font-semibold text-gray-600 mb-2">ℹ️ Informasi Teknis</div>
        <div class="grid grid-cols-2 gap-1">
            <span>Agen</span><span class="font-mono"><?= KODE_AGEN ?></span>
            <span>CBS URL</span><span class="font-mono break-all"><?= URL_DIGITAL ?></span>
            <span>OAuth URL</span><span class="font-mono break-all"><?= URL_GET_TOKEN ?></span>
            <span>MTI</span><span class="font-mono">009 (DIGITAL_BANK_REGISTER)</span>
            <span>KT</span><span class="font-mono">51 (TRX_MB_DEAKTIVASI_FROM_CORE)</span>
            <span>MSG Format</span><span class="font-mono">CS#{faktur}#mBankingDEL#{noHP}#{agen}</span>
            <span>CBS Action</span><span class="font-mono">DELETE agen_smsbanking + agen_aktifasi</span>
            <span>Waktu</span><span class="font-mono"><?= date('Y-m-d H:i:s') ?> WIB</span>
        </div>
    </div>

</div><!-- /max-w-2xl -->
</body>
</html>
