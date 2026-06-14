<?php
/**
 * aktivasimbanking2 — Aktivasi User mBanking v2 via CBS (Single File)
 *
 * Mengirim request aktivasi user mBanking ke CBS (versi 2)
 * melalui jalur SIS/Assist Switching Middleware (assist-switching_v3_pro).
 *
 * Perbedaan dengan aktivasimbanking.php (KT=50):
 *   KT=50 → RegisterDigital()   → generate PIN → kirim via SMS/Email
 *   KT=80 → RegisterDigital_v2() → generate Kode Fasilitas 6 digit (terenkripsi)
 *                                  → kirim via SMS Masking / Email / WA
 *                                  → support CIF, Email CBS, WABLAST, OTP field
 *
 * Flow:
 *   1. Get OAuth access token dari myassist.sis1.net (PascalCase + cache)
 *   2. Bangun ISO 8583 request: MTI=009, KT=80
 *      MSG format: CS#{faktur}#mBankingREG#{noHP}#{kodeAgen}
 *      + field tambahan: EMAIL, EMAILCBS, CIF, EMAILFROMCBS, WABLAST, OTP
 *   3. POST ke switching.mcoll.sis1.net/.../mobile-digital
 *   4. Parse response:
 *        AR#{faktur}#SUKSES#E  → kode fasilitas dikirim via Email
 *        AR#{faktur}#SUKSES#S  → kode fasilitas dikirim via SMS Masking
 *        AR#{faktur}#SUKSES#SL → kode fasilitas dikirim via SMS Gateway (fallback)
 *        AR#{faktur}#SUKSES#W  → kode fasilitas dikirim via WA Blast
 *        AR#{faktur}#GAGAL# Nomor atau CIF sudah diaktivasi sebelumnya
 *
 * CBS handler (mbanking.controller.php):
 *   MTI=009 (DIGITAL_BANK_REGISTER) + KT=80 (TRX_MB_AKTIVASI_FROM_CORE_V2)
 *   → RegisterDigital_v2():
 *       Cek agen_aktifasi WHERE (HP OR KodeCIF) + Agen
 *       Jika belum ada → generate KodeFasilitas 6 digit → encryptIt()
 *       Kirim kode via:
 *         Email: INSERT notifemail_sent → response #SUKSES#E
 *         WA:    gunakan OTP field       → response #SUKSES#W
 *         SMS:   SendSMSMasking()        → response #SUKSES#S / #SUKSES#SL
 *       UPDATE agen_aktifasi SET HP, Agen, DateTime, Email, mBankingKodeFasilitas, KodeCIF, DateTimeOTP
 *
 * Catatan: KT=80 adalah jalur baru (versi aktif saat ini).
 *   Untuk jalur lama gunakan aktivasimbanking.php (KT=50).
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

define('OAUTH_CLIENT_ID',      '000087');
define('OAUTH_CLIENT_SECRET',  '274FrdhikpazQXdLtv5kNoRucN7SlQPq');
define('OAUTH_CORPORATE_ID',   '553231');
define('OAUTH_SERTIFIKAT',     'd9ebe47971b415daadc3440ee4070aea'); // msAuth_SERTIFIKAT_API
define('OAUTH_KODE_APLIKASI',  'BPRPAS');
define('OAUTH_USERNAME',       'A-000300');   // msKodeH2H
define('OAUTH_PASSWORD',       '');           // isi jika diperlukan

define('DE061_SIM_SERIAL',     'babba586c65c1a8119cfe6a6dae9972f'); // msCDSID
define('KODE_AGEN',            'A-000300');

define('URL_GET_TOKEN', 'http://myassist.sis1.net/assist-auth_api/public/oauth/getaccesstoken');
define('URL_DIGITAL',   'http://switching.mcoll.sis1.net/assist-switching_v3_pro/public/mobile-digital');

// Cache token terpisah dari script lain
define('TOKEN_CACHE_FILE', __DIR__ . '/storage/cds/cache/aktivasi_mbanking2_token.cache');

// Kode singkat kanal pengiriman kode fasilitas dari CBS
define('KANAL_MAP', [
    'E'  => '📧 Email',
    'S'  => '📱 SMS Masking',
    'SL' => '📱 SMS Gateway (fallback)',
    'W'  => '💬 WA Blast',
]);

// ============================================================
// FUNGSI HELPER
// ============================================================

function generateFaktur(): string {
    return date('His') . str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Ambil OAuth access token — support format PascalCase (myassist.sis1.net)
 * dan lowercase (assist-auth_api standar). Cache 15 menit.
 */
function getAccessToken(): array {
    // Baca dari cache jika masih valid
    if (file_exists(TOKEN_CACHE_FILE)) {
        $cached = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
        if (!empty($cached['access_token']) && !empty($cached['expires_at'])) {
            if (time() < ($cached['expires_at'] - 60)) {
                return [
                    'success'    => true,
                    'token'      => $cached['access_token'],
                    'from_cache' => true,
                    'http_code'  => '-',
                    'raw'        => '',
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
    if (OAUTH_KODE_APLIKASI !== '') $postBody['KODEAPLIKASI'] = OAUTH_KODE_APLIKASI;
    if (OAUTH_PASSWORD      !== '') $postBody['PASSWORD']     = OAUTH_PASSWORD;

    $kodeSertifikat = OAUTH_SERTIFIKAT ?: OAUTH_CLIENT_ID;

    $ch = curl_init(URL_GET_TOKEN);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postBody),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $kodeSertifikat,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $rawOriginal = $raw;

    // cURL gagal total
    if ($raw === false || $raw === '') {
        return [
            'success'    => false, 'token' => '', 'from_cache' => false,
            'http_code'  => $httpCode,
            'raw'        => '[curl error] ' . $curlErr,
            'error'      => 'cURL gagal: ' . $curlErr,
        ];
    }

    // Cari awal JSON object
    $jsonStart = strpos($raw, '{');
    if ($jsonStart === false) {
        return [
            'success'    => false, 'token' => '', 'from_cache' => false,
            'http_code'  => $httpCode,
            'raw'        => '[no JSON] ' . substr($rawOriginal, 0, 500),
            'error'      => 'Response bukan JSON. Raw: ' . substr($rawOriginal, 0, 200),
        ];
    }
    if ($jsonStart > 0) $raw = substr($raw, $jsonStart);

    // Strip trailing garbage setelah '}'
    $jsonEnd = strrpos($raw, '}');
    if ($jsonEnd !== false) $raw = substr($raw, 0, $jsonEnd + 1);

    $resp = json_decode($raw, true);

    // Retry setelah strip BOM/whitespace jika null
    if ($resp === null) {
        $resp = json_decode(ltrim($raw, "\xEF\xBB\xBF\t\n\r "), true);
    }

    if ($resp === null) {
        return [
            'success'    => false, 'token' => '', 'from_cache' => false,
            'http_code'  => $httpCode,
            'raw'        => $raw,
            'error'      => 'json_decode gagal. Raw: ' . substr($raw, 0, 500),
        ];
    }

    // Ekstraksi token — Format A (lowercase), Format B (PascalCase myassist), Format C (mixed)
    $token = $resp['data']['access_token']
          ?? $resp['access_token']
          ?? $resp['Data']['AccessToken']
          ?? $resp['Data']['Token']
          ?? $resp['data']['Token']
          ?? $resp['Token']
          ?? '';

    // LifeTime: server kirim dalam menit (≤1440), konversi ke detik
    $expiresRaw = $resp['data']['expires_in']
               ?? $resp['expires_in']
               ?? $resp['Data']['LifeTime']
               ?? $resp['Data']['expires_in']
               ?? null;
    $expiresIn = ($expiresRaw !== null)
        ? ((int)$expiresRaw <= 1440 ? (int)$expiresRaw * 60 : (int)$expiresRaw)
        : 3600;

    if (!empty($token)) {
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
        'error'      => $resp['message'] ?? $resp['error'] ?? $resp['MSG'] ?? $resp['Message']
                     ?? ('Token kosong. Keys: ' . implode(',', array_keys($resp))
                        . (isset($resp['Data']) ? ' | Data keys: ' . implode(',', array_keys((array)$resp['Data'])) : '')),
    ];
}

/**
 * Kirim request aktivasi mBanking v2 ke CBS (KT=80 / RegisterDigital_v2).
 *
 * CBS: RegisterDigital_v2()
 *   → Cek agen_aktifasi: jika HP/CIF sudah ada → GAGAL
 *   → Generate KodeFasilitas 6 digit → encryptIt()
 *   → Kirim via: Email (#E) | WA Blast (#W) | SMS Masking (#S) | SMS GW (#SL)
 *   → UPDATE agen_aktifasi (HP, Agen, DateTime, Email, mBankingKodeFasilitas, KodeCIF, DateTimeOTP)
 *
 * Response codes:
 *   #SUKSES#E  → Email terkirim
 *   #SUKSES#S  → SMS Masking terkirim
 *   #SUKSES#SL → SMS Gateway fallback terkirim
 *   #SUKSES#W  → WA Blast terkirim
 *   #GAGAL#... → sudah aktif / error
 *
 * @param string $noHP       Nomor HP user (format: 08xxxxxxxxxx)
 * @param string $kodeAgen   Kode agen (default: KODE_AGEN)
 * @param string $email      Email user — jika diisi CBS kirim kode via Email (#E)
 * @param string $emailCbs   Email untuk disimpan di agen_aktifasi (bisa beda dengan $email)
 * @param string $kif        Kode CIF nasabah (opsional, untuk cek duplikasi by CIF)
 * @param bool   $wablast    Kirim kode via WA Blast (true/false, default false)
 * @param string $otp        Kode OTP dari WA (digunakan jika $wablast = true)
 * @return array
 */
function aktivasiMBanking2(
    string $noHP,
    string $kodeAgen  = KODE_AGEN,
    string $email     = '',
    string $emailCbs  = '',
    string $kif       = '',
    bool   $wablast   = false,
    string $otp       = ''
): array {
    // Step 1: Ambil token
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

    // Step 2: Bangun ISO 8583 — MTI=009 / KT=80
    //   MSG format: CS#{faktur}#mBankingREG#{noHP}#{kodeAgen}
    //   (sama dengan KT=50 — CBS v2 membaca va[1]=faktur, va[3]=noHP, AGEN dari request)
    $faktur = generateFaktur();
    $msg    = "CS#{$faktur}#mBankingREG#{$noHP}#{$kodeAgen}";

    $isoRequest = [
        'MTI'  => '009',   // DIGITAL_BANK_REGISTER
        'KT'   => '80',    // TRX_MB_AKTIVASI_FROM_CORE_V2
        'AGEN' => $kodeAgen,
        'MSG'  => $msg,
    ];

    // Field-field opsional KT=80
    if ($email    !== '') $isoRequest['EMAIL']        = strtolower($email);
    if ($emailCbs !== '') $isoRequest['EMAILCBS']     = strtolower($emailCbs);
    if ($kif      !== '') $isoRequest['CIF']          = $kif;
    if ($wablast)         $isoRequest['WABLAST']      = '1';
    if ($otp      !== '') $isoRequest['OTP']          = $otp;

    // Step 3: POST ke CBS
    $deviceId = DE061_SIM_SERIAL ?: (KODE_AGEN ?: md5(gethostname()));
    $postBody = http_build_query([
        'cCode'         => json_encode($isoRequest),
        'DEVICEID'      => $deviceId,
        'PLATFORM'      => 'android',
        'VERSIAPLIKASI' => '1.2.6',
    ]);

    $tStart = microtime(true);
    $ch = curl_init(URL_DIGITAL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postBody,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $rawResponse = curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $elapsed     = round((microtime(true) - $tStart) * 1000);
    curl_close($ch);

    // Step 4: Parse response
    // Strip PHP Notice/Warning HTML sebelum JSON
    $jsonStart = strpos($rawResponse, '{');
    if ($jsonStart !== false && $jsonStart > 0) {
        $rawResponse = substr($rawResponse, $jsonStart);
    }

    $respJson = json_decode($rawResponse, true);
    $dataStr  = $respJson['data'] ?? '';

    // Format: AR#{faktur}#SUKSES#{kanalKode}
    //    atau AR#{faktur}#GAGAL#{pesan}
    $parts      = explode('#', $dataStr);
    $status     = $parts[2] ?? '';
    $kanalKode  = $parts[3] ?? '';   // E / S / SL / W  atau pesan gagal
    $success    = (strtoupper($status) === 'SUKSES');

    // Decode kanal
    $kanalLabel = '';
    if ($success) {
        $kanalLabel = KANAL_MAP[$kanalKode] ?? ('Kanal: ' . $kanalKode);
    }

    return [
        'success'      => $success,
        'step'         => 'aktivasi',
        'status'       => $status,
        'kanal_kode'   => $kanalKode,     // E / S / SL / W
        'kanal_label'  => $kanalLabel,    // human-readable kanal
        'message'      => $success ? '' : $kanalKode,  // pesan error jika GAGAL
        'faktur'       => $faktur,
        'no_hp'        => $noHP,
        'email'        => $email,
        'email_cbs'    => $emailCbs,
        'cif'          => $kif,
        'wablast'      => $wablast,
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

$result       = null;
$errorMsg     = '';
$formNoHP     = trim($_POST['no_hp']      ?? '');
$formKodeAgen = trim($_POST['kode_agen']  ?? KODE_AGEN);
$formEmail    = trim($_POST['email']      ?? '');
$formEmailCbs = trim($_POST['email_cbs']  ?? '');
$formCIF      = trim($_POST['cif']        ?? '');
$formWablast  = isset($_POST['wablast']) && $_POST['wablast'] === '1';
$formOTP      = trim($_POST['otp']        ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_aktivasi2'])) {
    if (empty($formNoHP)) {
        $errorMsg = '⚠️ Nomor HP wajib diisi.';
    } elseif (!preg_match('/^0[0-9]{8,13}$/', $formNoHP)) {
        $errorMsg = '⚠️ Format Nomor HP tidak valid. Gunakan format: 08xxxxxxxxxx (tanpa spasi / +62).';
    } elseif (empty($formKodeAgen)) {
        $errorMsg = '⚠️ Kode Agen wajib diisi.';
    } elseif ($formEmail !== '' && !filter_var($formEmail, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = '⚠️ Format Email tidak valid.';
    } elseif ($formEmailCbs !== '' && !filter_var($formEmailCbs, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = '⚠️ Format Email CBS tidak valid.';
    } elseif ($formWablast && $formOTP === '') {
        $errorMsg = '⚠️ Kode OTP WA wajib diisi jika WA Blast diaktifkan.';
    } else {
        $result = aktivasiMBanking2(
            $formNoHP,
            $formKodeAgen,
            $formEmail,
            $formEmailCbs,
            $formCIF,
            $formWablast,
            $formOTP
        );
    }
}

// Helper: tentukan label kanal kirim untuk konfirmasi JS
function kanalKonfirmasi(bool $wablast, string $email): string {
    if ($wablast)      return 'WA Blast';
    if ($email !== '') return 'Email (' . $email . ')';
    return 'SMS Masking / SMS Gateway';
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
    <title>Aktivasi mBanking v2 (KT=80)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; }
        pre  { white-space: pre-wrap; word-break: break-all; font-size: 12px; }
        .step-box  { border-left: 4px solid #2563eb; background: #fafafa; }
        .step-ok   { border-left-color: #16a34a !important; }
        .step-err  { border-left-color: #dc2626 !important; }
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
            🆕 Aktivasi mBanking v2
        </h1>
        <p class="text-gray-500 text-sm">Aktivasi user mBanking v2 via CBS — A-000300 BPR Penataran Kabupaten Blitar</p>
        <div class="mt-3 flex flex-wrap gap-2 text-xs">
            <span class="bg-blue-100 text-blue-700 px-2 py-1 rounded font-mono">MTI=009</span>
            <span class="bg-blue-100 text-blue-700 px-2 py-1 rounded font-mono">KT=80</span>
            <span class="bg-gray-100 text-gray-600 px-2 py-1 rounded font-mono">DIGITAL_BANK_REGISTER</span>
            <span class="bg-gray-100 text-gray-600 px-2 py-1 rounded font-mono">TRX_MB_AKTIVASI_FROM_CORE_V2</span>
        </div>
        <div class="mt-3 p-3 bg-blue-50 border border-blue-200 rounded-lg text-xs text-blue-800">
            ℹ️ <strong>KT=80 (versi aktif):</strong> CBS generate <em>Kode Fasilitas</em> 6 digit (terenkripsi),
            dikirim via <strong>Email / SMS Masking / WA Blast</strong>.
            Support field tambahan: CIF, EmailCBS, WABLAST, OTP.
            <br>Untuk aktivasi lama (SMS PIN langsung), gunakan
            <a href="aktivasimbanking.php" class="underline font-semibold">aktivasimbanking.php (KT=50)</a>.
        </div>
    </div>

    <!-- Form -->
    <div class="bg-white rounded-2xl shadow p-6 mb-6">
        <h2 class="font-semibold text-gray-700 mb-4">📋 Form Aktivasi v2</h2>
        <form method="POST" action="">

            <!-- Nomor HP -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Nomor HP User <span class="text-red-500">*</span>
                </label>
                <input type="text" name="no_hp" id="noHP"
                    value="<?= htmlspecialchars($formNoHP) ?>"
                    placeholder="08xxxxxxxxxx"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
                    autocomplete="off" required>
                <p class="text-xs text-gray-400 mt-1">Format: 08xxxxxxxx — tanpa +62 atau spasi</p>
            </div>

            <!-- Kode Agen -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Kode Agen <span class="text-red-500">*</span>
                </label>
                <input type="text" name="kode_agen" id="kodeAgen"
                    value="<?= htmlspecialchars($formKodeAgen) ?>"
                    placeholder="A-000300"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
                    required>
                <p class="text-xs text-gray-400 mt-1">Default: A-000300. Ubah jika aktivasi untuk agen lain.</p>
            </div>

            <!-- Email (opsional) -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Email User <span class="text-gray-400 font-normal">(opsional)</span>
                </label>
                <input type="email" name="email" id="email"
                    value="<?= htmlspecialchars($formEmail) ?>"
                    placeholder="user@example.com"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
                    autocomplete="off">
                <p class="text-xs text-gray-400 mt-1">
                    Jika diisi, CBS kirim Kode Fasilitas via Email (response: #SUKSES#E).
                </p>
            </div>

            <!-- Email CBS (opsional) -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Email CBS <span class="text-gray-400 font-normal">(opsional)</span>
                </label>
                <input type="email" name="email_cbs" id="emailCbs"
                    value="<?= htmlspecialchars($formEmailCbs) ?>"
                    placeholder="internal@bank.co.id"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
                    autocomplete="off">
                <p class="text-xs text-gray-400 mt-1">
                    Email yang disimpan ke tabel <code>agen_aktifasi</code> (EMAILCBS).
                    Bisa berbeda dengan Email kirim kode di atas.
                </p>
            </div>

            <!-- Kode CIF (opsional) -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Kode CIF <span class="text-gray-400 font-normal">(opsional)</span>
                </label>
                <input type="text" name="cif" id="cif"
                    value="<?= htmlspecialchars($formCIF) ?>"
                    placeholder="CIF nasabah"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
                    autocomplete="off">
                <p class="text-xs text-gray-400 mt-1">
                    CBS cek duplikasi berdasarkan HP <strong>atau</strong> CIF.
                    Kosongkan jika tidak menggunakan CIF.
                </p>
            </div>

            <!-- WA Blast + OTP -->
            <div class="mb-4 p-3 bg-gray-50 border border-gray-200 rounded-lg">
                <label class="flex items-center gap-2 text-sm font-medium text-gray-700 mb-2 cursor-pointer">
                    <input type="checkbox" name="wablast" id="wablastCheck" value="1"
                        <?= $formWablast ? 'checked' : '' ?>
                        onchange="toggleWA(this.checked)"
                        class="w-4 h-4 accent-blue-600">
                    💬 Kirim Kode Fasilitas via WA Blast
                </label>
                <div id="otpWrap" class="<?= $formWablast ? '' : 'hidden' ?>">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Kode OTP WA <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="otp" id="otp"
                        value="<?= htmlspecialchars($formOTP) ?>"
                        placeholder="Kode OTP dari WA"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
                        autocomplete="off">
                    <p class="text-xs text-gray-400 mt-1">Wajib jika WA Blast diaktifkan.</p>
                </div>
            </div>

            <?php if ($errorMsg): ?>
            <div class="mb-4 bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm">
                <?= htmlspecialchars($errorMsg) ?>
            </div>
            <?php endif; ?>

            <!-- Tombol Submit -->
            <?php if ($result === null || !$result['success']): ?>
            <button type="submit" name="submit_aktivasi2" value="1"
                onclick="return confirmAktivasi2()"
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg py-2 px-4 text-sm transition">
                🆕 Aktivasi mBanking v2
            </button>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($result !== null): ?>
    <!-- Hasil -->
    <div class="bg-white rounded-2xl shadow p-6 mb-6">
        <h2 class="font-semibold text-gray-700 mb-4">📊 Hasil Aktivasi v2</h2>

        <!-- Status Banner -->
        <?php if ($result['success']): ?>
        <div class="bg-green-50 border border-green-300 rounded-xl p-4 mb-4 flex items-start gap-3">
            <span class="text-2xl">✅</span>
            <div>
                <div class="font-bold text-green-800">Aktivasi v2 Berhasil</div>
                <div class="text-green-700 text-sm mt-1">
                    Kode Fasilitas berhasil dikirim via
                    <strong><?= htmlspecialchars($result['kanal_label']) ?></strong>
                </div>
                <div class="text-xs text-green-600 mt-1">
                    HP: <strong><?= htmlspecialchars($result['no_hp']) ?></strong> |
                    Agen: <strong><?= htmlspecialchars($result['kode_agen']) ?></strong> |
                    Faktur: <strong><?= htmlspecialchars($result['faktur']) ?></strong>
                    <?php if (!empty($result['email'])): ?>
                    | Email: <strong><?= htmlspecialchars($result['email']) ?></strong>
                    <?php endif; ?>
                    <?php if (!empty($result['cif'])): ?>
                    | CIF: <strong><?= htmlspecialchars($result['cif']) ?></strong>
                    <?php endif; ?>
                </div>
                <div class="mt-2 p-2 bg-blue-100 rounded-lg text-xs text-blue-800">
                    💡 <strong>Langkah berikutnya:</strong> User menyampaikan Kode Fasilitas ke CS untuk divalidasi.
                    CS gunakan script <strong>ValidasiKodeFasilitas</strong> (KT=81) atau input di sistem.
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="bg-red-50 border border-red-300 rounded-xl p-4 mb-4 flex items-start gap-3">
            <span class="text-2xl">❌</span>
            <div>
                <div class="font-bold text-red-800">Aktivasi v2 Gagal</div>
                <div class="text-red-700 text-sm mt-1">
                    <?= htmlspecialchars($result['error'] ?? $result['message'] ?? 'Unknown error') ?>
                </div>
                <?php if (!empty($result['status'])): ?>
                <div class="text-xs text-red-600 mt-1">Status CBS: <strong><?= htmlspecialchars($result['status']) ?></strong></div>
                <?php endif; ?>
                <?php if (stripos($result['message'] ?? '', 'sudah diaktivasi') !== false): ?>
                <div class="mt-2 p-2 bg-amber-50 border border-amber-200 rounded text-xs text-amber-800">
                    ⚠️ Nomor HP / CIF sudah terdaftar. Gunakan
                    <a href="hapusaktivasimbanking.php" class="underline font-semibold">hapusaktivasimbanking.php</a>
                    untuk hapus aktivasi lama, lalu ulangi proses ini.
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Detail 4 Step -->
        <div class="space-y-3">

            <!-- Step 1: Token -->
            <div class="step-box <?= $result['token_result']['success'] ? 'step-ok' : 'step-err' ?> rounded-r-lg p-3">
                <div class="text-xs font-bold text-blue-700 mb-2">━━ STEP 1: GET TOKEN ━━</div>
                <div class="text-xs text-gray-600 grid grid-cols-2 gap-1">
                    <span class="text-gray-500">Token URL</span>
                    <span class="font-mono text-xs break-all"><?= htmlspecialchars(URL_GET_TOKEN) ?></span>
                    <span class="text-gray-500">USERNAME</span>
                    <span class="font-mono"><?= htmlspecialchars(OAUTH_USERNAME) ?></span>
                    <span class="text-gray-500">KODEAPLIKASI</span>
                    <span class="font-mono"><?= htmlspecialchars(OAUTH_KODE_APLIKASI) ?></span>
                    <span class="text-gray-500">SERTIFIKAT (Bearer)</span>
                    <span class="font-mono"><?= htmlspecialchars(substr(OAUTH_SERTIFIKAT ?: OAUTH_CLIENT_ID, 0, 8)) ?>...</span>
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
                    <?php if (!$result['token_result']['success']): ?>
                    <span class="text-gray-500">Error</span>
                    <span class="font-mono err"><?= htmlspecialchars($result['token_result']['error'] ?? '-') ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($result['token_result']['raw'])): ?>
                <details class="mt-2">
                    <summary class="text-xs text-blue-500 cursor-pointer hover:underline">OAuth Raw Response</summary>
                    <pre class="text-xs bg-gray-50 rounded p-2 mt-1"><?= htmlspecialchars($result['token_result']['raw']) ?></pre>
                </details>
                <?php endif; ?>
            </div>

            <!-- Step 2: ISO Request -->
            <?php if (isset($result['iso_request'])): ?>
            <div class="step-box step-ok rounded-r-lg p-3">
                <div class="text-xs font-bold text-blue-700 mb-2">━━ STEP 2: ISO 8583 REQUEST (MTI=009, KT=80) ━━</div>
                <pre class="text-xs bg-gray-50 rounded p-2"><?= htmlspecialchars(json_encode($result['iso_request'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                <div class="text-xs text-gray-500 mt-1">
                    MSG: <code class="bg-gray-100 px-1 py-0.5 rounded"><?= htmlspecialchars($result['iso_request']['MSG'] ?? '') ?></code>
                </div>
            </div>
            <?php endif; ?>

            <!-- Step 3: HTTP POST -->
            <div class="step-box <?= isset($result['http_code']) && ($result['step'] ?? '') !== 'token' ? 'step-ok' : '' ?> rounded-r-lg p-3">
                <div class="text-xs font-bold text-blue-700 mb-2">━━ STEP 3: HTTP POST ke CBS ━━</div>
                <?php if (($result['step'] ?? '') === 'token'): ?>
                <div class="text-xs text-amber-600 font-semibold">⚠️ Tidak dieksekusi — gagal di Step 1 (token)</div>
                <?php else: ?>
                <div class="text-xs text-gray-600 grid grid-cols-2 gap-1">
                    <span class="text-gray-500">URL</span>
                    <span class="font-mono text-xs break-all"><?= htmlspecialchars(URL_DIGITAL) ?></span>
                    <span class="text-gray-500">HTTP Code</span>
                    <span class="font-mono <?= ($result['http_code'] ?? 0) == 200 ? 'ok' : 'err' ?>">
                        <?= htmlspecialchars((string)($result['http_code'] ?? '-')) ?> (<?= ($result['elapsed_ms'] ?? '-') ?>ms)
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Step 4: Response -->
            <div class="step-box <?= ($result['success'] ?? false) ? 'step-ok' : (($result['step'] ?? '') !== 'token' ? 'step-err' : '') ?> rounded-r-lg p-3">
                <div class="text-xs font-bold text-blue-700 mb-2">━━ STEP 4: RESPONSE CBS ━━</div>
                <?php if (($result['step'] ?? '') === 'token'): ?>
                <div class="text-xs text-amber-600 font-semibold">⚠️ Tidak dieksekusi — gagal di Step 1 (token)</div>
                <?php else: ?>
                <div class="text-xs text-gray-600 grid grid-cols-2 gap-1">
                    <span class="text-gray-500">Status</span>
                    <span class="font-mono font-bold <?= $result['success'] ? 'ok' : 'err' ?>">
                        <?= htmlspecialchars($result['status'] ?? '-') ?>
                    </span>
                    <?php if ($result['success']): ?>
                    <span class="text-gray-500">Kanal Kirim</span>
                    <span class="font-mono ok"><?= htmlspecialchars($result['kanal_label'] ?: $result['kanal_kode']) ?></span>
                    <?php else: ?>
                    <span class="text-gray-500">Pesan</span>
                    <span class="font-mono"><?= htmlspecialchars($result['message'] ?? '-') ?></span>
                    <?php endif; ?>
                    <span class="text-gray-500">Faktur</span>
                    <span class="font-mono"><?= htmlspecialchars($result['faktur'] ?? '-') ?></span>
                </div>
                <details class="mt-2">
                    <summary class="text-xs text-blue-500 cursor-pointer hover:underline">Raw Response CBS</summary>
                    <pre class="text-xs bg-gray-50 rounded p-2 mt-1"><?= htmlspecialchars($result['raw_response'] ?? '') ?></pre>
                </details>
                <?php endif; ?>
            </div>

        </div><!-- /space-y-3 -->

        <!-- Tombol aksi -->
        <div class="mt-5 flex gap-3 flex-wrap">
            <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>"
               class="inline-block bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-lg py-2 px-4 transition">
                ← Aktivasi v2 Lain
            </a>
            <a href="aktivasimbanking.php"
               class="inline-block bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-lg py-2 px-4 transition">
                Ke KT=50 (Aktivasi v1) →
            </a>
            <?php if ($result['success']): ?>
            <span class="inline-block bg-green-100 text-green-800 text-sm font-semibold rounded-lg py-2 px-4">
                ✅ Selesai — Kode Fasilitas sudah dikirim ke user
            </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Info Teknis -->
    <div class="bg-white rounded-2xl shadow p-5 text-xs text-gray-500">
        <div class="font-semibold text-gray-600 mb-2">ℹ️ Informasi Teknis</div>
        <div class="grid grid-cols-2 gap-1">
            <span>Agen Default</span>     <span class="font-mono"><?= KODE_AGEN ?></span>
            <span>CBS URL</span>           <span class="font-mono break-all"><?= URL_DIGITAL ?></span>
            <span>OAuth URL</span>         <span class="font-mono break-all"><?= URL_GET_TOKEN ?></span>
            <span>MTI</span>               <span class="font-mono">009 (DIGITAL_BANK_REGISTER)</span>
            <span>KT</span>                <span class="font-mono">80 (TRX_MB_AKTIVASI_FROM_CORE_V2)</span>
            <span>MSG Format</span>        <span class="font-mono">CS#{faktur}#mBankingREG#{noHP}#{agen}</span>
            <span>CBS: tabel diupdate</span>
            <span class="font-mono">agen_aktifasi (HP, Agen, DateTime, Email, mBankingKodeFasilitas, KodeCIF)</span>
            <span>CBS: kanal kirim</span>  <span class="font-mono">#E (Email) | #S (SMS Masking) | #SL (SMS GW) | #W (WA)</span>
            <span>Cache Token</span>       <span class="font-mono break-all">aktivasi_mbanking2_token.cache</span>
            <span>Waktu Server</span>      <span class="font-mono"><?= date('Y-m-d H:i:s') ?> WIB</span>
        </div>
    </div>

</div><!-- /max-w-2xl -->

<script>
function toggleWA(checked) {
    document.getElementById('otpWrap').classList.toggle('hidden', !checked);
}

function confirmAktivasi2() {
    const hp       = document.getElementById('noHP').value.trim();
    const agen     = document.getElementById('kodeAgen').value.trim();
    const email    = document.getElementById('email').value.trim();
    const cif      = document.getElementById('cif').value.trim();
    const wablast  = document.getElementById('wablastCheck').checked;
    let kanal = wablast ? 'WA Blast' : (email ? 'Email (' + email + ')' : 'SMS Masking / SMS Gateway');

    let info = 'KONFIRMASI AKTIVASI mBanking v2\n\n'
             + 'Nomor HP  : ' + hp + '\n'
             + 'Kode Agen : ' + agen + '\n';
    if (email) info += 'Email     : ' + email + '\n';
    if (cif)   info += 'CIF       : ' + cif + '\n';
    info += 'Kanal     : ' + kanal + '\n\n'
          + 'CBS akan generate Kode Fasilitas dan mengirim ke user via ' + kanal + '.\n\n'
          + 'Lanjutkan?';
    return confirm(info);
}
</script>
</body>
</html>
