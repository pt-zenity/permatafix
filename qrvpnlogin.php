<?php
/**
 * qrvpnlogin.php — QR Scan VPN Login
 * Single-file tool untuk QR Code scan + VPN login via index_mobile.php
 *
 * Alur: Generate QR berisi Username VPN → Scan dari mobile app AssistTeam
 *       → polling status vpn_verify_list → tampilkan hasil connect
 *
 * Endpoint assist-pro.net:
 *   Base URL : http://aa.pro.sis1.net/assist-pro.net
 *   POST index_mobile.php   body JSON (cCode=...)
 *   MTI=02, KT=02104  → QRSCAN    (mobile scan → set Status=3)
 *   MTI=04, KT=04001  → CEKVPN    (PC polling → can connect)
 *   MTI=02, KT=02100  → GETVPNLIST (list VPN by email)
 *   MTI=02, KT=02105  → DISCONECTVPN
 *   MTI=04, KT=04003  → EDITVPNSTATUS (set Status=1 setelah connected)
 */

// ─────────────────────────────────────────────
//  KONFIGURASI — sesuaikan dengan lingkungan
// ─────────────────────────────────────────────
define('API_BASE_URL',    'http://aa.pro.sis1.net/assist-pro.net');  // URL assist-pro.net
define('API_ENDPOINT',    'index_mobile.php');  // relatif dari API_BASE_URL
define('APP_TITLE',       'QR VPN Login');
define('APP_VERSION',     '1.0.0');
define('CURL_TIMEOUT',    15);

// Device identifier untuk script ini (digunakan sebagai ClientID di QRSCAN)
define('SCRIPT_CLIENT_ID', 'QRVPNLOGIN-' . substr(md5(gethostname() . __FILE__), 0, 12));
define('SCRIPT_MAC',       '00:00:00:00:00:00'); // fallback MacAddress
define('SCRIPT_APPVER',    APP_VERSION);

// ─────────────────────────────────────────────
//  FUNGSI API CALLS
// ─────────────────────────────────────────────

/**
 * POST ke index_mobile.php dengan JSON body
 * Request format: { MTI, KT, ...fields }
 * Response: array hasil json_decode
 */
function apiCall(array $payload): array {
    $baseUrl = rtrim(API_BASE_URL, '/');
    if ($baseUrl === '') {
        return ['Status' => 0, 'MSG' => 'API_BASE_URL belum dikonfigurasi'];
    }
    $url = $baseUrl . '/' . API_ENDPOINT;

    $json = json_encode($payload);
    $ch   = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['cCode' => $json],
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['Status' => 0, 'MSG' => "cURL error: $err", 'HTTPCode' => $httpCode];
    }
    $decoded = json_decode($raw, true);
    if ($decoded === null) {
        return ['Status' => 0, 'MSG' => 'Response bukan JSON valid', 'RawResponse' => substr($raw, 0, 300)];
    }
    return $decoded;
}

/**
 * KT=02100 — GETVPNLIST: ambil daftar VPN berdasarkan email
 * Response: { Status, MSG, Data: [ {Username, Customer, VPN_Gateway, Status, MacAddress, LastUsed, PPP_Profile, Block} ] }
 */
function apiGetVpnList(string $email): array {
    return apiCall([
        'MTI'   => '02',
        'KT'    => '02100',
        'EMAIL' => strtolower(trim($email)),
    ]);
}

/**
 * KT=02104 — QRSCAN: mobile app mengirim scan QR berisi Username VPN
 * Response: { Status:"1"|"0", MSG:"ok"|"Gagal, ..." }
 */
function apiQrScan(string $username, string $macAddress, string $clientId, string $appVersion): array {
    return apiCall([
        'MTI'        => '02',
        'KT'         => '02104',
        'Username'   => $username,
        'MacAddress' => $macAddress,
        'ClientID'   => $clientId,
        'APPVersion' => $appVersion,
    ]);
}

/**
 * KT=04001 — CEKVPN: PC polling apakah mobile sudah scan QR
 * Response: { Status:1, MSG:"Ok", Data:{ MSG:"can connect"|"sudah connect"|"not found", Status:1|3|2, DataVPN:{...} } }
 */
function apiCekVpn(string $macAddress, string $clientId = ''): array {
    $payload = [
        'MTI'        => '04',
        'KT'         => '04001',
        'MacAddress' => $macAddress,
    ];
    if ($clientId !== '') $payload['ClientID'] = $clientId;
    return apiCall($payload);
}

/**
 * KT=04003 — EDITVPNSTATUS: PC konfirmasi VPN sudah connect
 * Response: { MSG:"ok"|"Gagal,...", Status:"1"|"0" }
 */
function apiEditVpnStatus(string $username, string $macAddress, string $clientId, string $vpnVersion = '2.0'): array {
    return apiCall([
        'MTI'        => '04',
        'KT'         => '04003',
        'Username'   => $username,
        'MacAddress' => $macAddress,
        'ClientID'   => $clientId,
        'VPNVersion' => $vpnVersion,
    ]);
}

/**
 * KT=02105 — DISCONECTVPN: reset status VPN ke idle
 * Response: { MSG:"ok", Status:"1" }
 */
function apiDisconnectVpn(string $username): array {
    return apiCall([
        'MTI'      => '02',
        'KT'       => '02105',
        'Username' => $username,
    ]);
}

// ─────────────────────────────────────────────
//  POST HANDLER (AJAX JSON)
// ─────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $action = trim($_POST['_action']);

    switch ($action) {

        // ── List VPN by email ──────────────────
        case 'get_vpn_list':
            $email = trim($_POST['email'] ?? '');
            if ($email === '') {
                echo json_encode(['ok' => false, 'msg' => 'Email tidak boleh kosong']);
                exit;
            }
            $res = apiGetVpnList($email);
            echo json_encode(['ok' => true, 'data' => $res]);
            exit;

        // ── QRSCAN: kirim scan dari form (simulasi mobile) ──
        case 'qrscan':
            $username   = trim($_POST['username']   ?? '');
            $macAddress = trim($_POST['mac_address'] ?? SCRIPT_MAC);
            $clientId   = trim($_POST['client_id']   ?? SCRIPT_CLIENT_ID);
            $appVersion = trim($_POST['app_version'] ?? SCRIPT_APPVER);
            if ($username === '') {
                echo json_encode(['ok' => false, 'msg' => 'Username VPN tidak boleh kosong']);
                exit;
            }
            $res = apiQrScan($username, $macAddress, $clientId, $appVersion);
            echo json_encode(['ok' => true, 'data' => $res]);
            exit;

        // ── CEKVPN: polling dari PC ─────────────
        case 'cek_vpn':
            $macAddress = trim($_POST['mac_address'] ?? SCRIPT_MAC);
            $clientId   = trim($_POST['client_id']   ?? SCRIPT_CLIENT_ID);
            $res = apiCekVpn($macAddress, $clientId);
            echo json_encode(['ok' => true, 'data' => $res]);
            exit;

        // ── EDITVPNSTATUS: konfirmasi sudah connect ──
        case 'edit_vpn_status':
            $username   = trim($_POST['username']    ?? '');
            $macAddress = trim($_POST['mac_address'] ?? SCRIPT_MAC);
            $clientId   = trim($_POST['client_id']   ?? SCRIPT_CLIENT_ID);
            $vpnVersion = trim($_POST['vpn_version'] ?? '2.0');
            if ($username === '') {
                echo json_encode(['ok' => false, 'msg' => 'Username diperlukan']);
                exit;
            }
            $res = apiEditVpnStatus($username, $macAddress, $clientId, $vpnVersion);
            echo json_encode(['ok' => true, 'data' => $res]);
            exit;

        // ── DISCONECTVPN ──────────────────────────
        case 'disconnect_vpn':
            $username = trim($_POST['username'] ?? '');
            if ($username === '') {
                echo json_encode(['ok' => false, 'msg' => 'Username diperlukan']);
                exit;
            }
            $res = apiDisconnectVpn($username);
            echo json_encode(['ok' => true, 'data' => $res]);
            exit;

        default:
            echo json_encode(['ok' => false, 'msg' => 'Action tidak dikenal']);
            exit;
    }
}

// ─────────────────────────────────────────────
//  HTML
// ─────────────────────────────────────────────
$scriptClientId = SCRIPT_CLIENT_ID;
$scriptMac      = SCRIPT_MAC;
$scriptAppVer   = SCRIPT_APPVER;
$apiConfigured  = (API_BASE_URL !== '') ? 'true' : 'false';
$apiBaseUrl     = htmlspecialchars(API_BASE_URL);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= APP_TITLE ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
<style>
  :root {
    --bg:     #0f172a;
    --card:   #1e293b;
    --border: #334155;
    --text:   #e2e8f0;
    --muted:  #94a3b8;
    --accent: #38bdf8;
    --green:  #22c55e;
    --red:    #ef4444;
    --amber:  #f59e0b;
    --violet: #a78bfa;
  }
  * { box-sizing: border-box; }
  body { background: var(--bg); color: var(--text); font-family: 'Segoe UI', system-ui, sans-serif; margin: 0; min-height: 100vh; }
  
  /* TABS */
  .tab-bar { display: flex; border-bottom: 2px solid var(--border); background: var(--card); }
  .tab-btn { flex: 1; padding: 14px 8px; border: none; background: transparent; color: var(--muted); cursor: pointer;
             font-size: 13px; font-weight: 600; letter-spacing: .4px; transition: all .2s; border-bottom: 2px solid transparent; margin-bottom: -2px; }
  .tab-btn:hover { color: var(--text); background: rgba(255,255,255,.03); }
  .tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); background: rgba(56,189,248,.06); }
  .tab-panel { display: none; }
  .tab-panel.active { display: block; }

  /* CARDS */
  .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 16px; }
  .card-title { font-size: 13px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .8px; margin-bottom: 14px; }

  /* INPUTS */
  input[type=text], input[type=email], input[type=password], select {
    width: 100%; padding: 10px 13px; background: #0f172a; border: 1px solid var(--border); border-radius: 8px;
    color: var(--text); font-size: 14px; outline: none; transition: border-color .2s;
  }
  input:focus, select:focus { border-color: var(--accent); }
  label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 5px; font-weight: 600; }

  /* BUTTONS */
  .btn { display: inline-flex; align-items: center; gap: 7px; padding: 10px 18px; border: none; border-radius: 8px;
         font-size: 13px; font-weight: 700; cursor: pointer; transition: all .2s; letter-spacing: .3px; }
  .btn:disabled { opacity: .5; cursor: not-allowed; }
  .btn-primary  { background: var(--accent); color: #0f172a; }
  .btn-primary:hover:not(:disabled)  { background: #7dd3fc; }
  .btn-success  { background: var(--green); color: #fff; }
  .btn-success:hover:not(:disabled)  { background: #16a34a; }
  .btn-danger   { background: var(--red); color: #fff; }
  .btn-danger:hover:not(:disabled)   { background: #dc2626; }
  .btn-amber    { background: var(--amber); color: #0f172a; }
  .btn-amber:hover:not(:disabled)    { background: #d97706; }
  .btn-violet   { background: var(--violet); color: #fff; }
  .btn-violet:hover:not(:disabled)   { background: #7c3aed; }
  .btn-outline  { background: transparent; border: 1px solid var(--border); color: var(--text); }
  .btn-outline:hover:not(:disabled)  { border-color: var(--accent); color: var(--accent); }
  .btn-sm { padding: 7px 13px; font-size: 12px; }
  .btn-full { width: 100%; justify-content: center; }

  /* STATUS BADGES */
  .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
  .badge-green  { background: rgba(34,197,94,.15); color: var(--green); border: 1px solid rgba(34,197,94,.3); }
  .badge-red    { background: rgba(239,68,68,.15); color: var(--red); border: 1px solid rgba(239,68,68,.3); }
  .badge-amber  { background: rgba(245,158,11,.15); color: var(--amber); border: 1px solid rgba(245,158,11,.3); }
  .badge-blue   { background: rgba(56,189,248,.15); color: var(--accent); border: 1px solid rgba(56,189,248,.3); }
  .badge-violet { background: rgba(167,139,250,.15); color: var(--violet); border: 1px solid rgba(167,139,250,.3); }
  .badge-gray   { background: rgba(148,163,184,.12); color: var(--muted); border: 1px solid rgba(148,163,184,.2); }

  /* ALERT BOXES */
  .alert { padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 12px; display: flex; align-items: flex-start; gap: 10px; }
  .alert-info    { background: rgba(56,189,248,.1); border: 1px solid rgba(56,189,248,.25); color: #7dd3fc; }
  .alert-success { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.25); color: #86efac; }
  .alert-error   { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.25); color: #fca5a5; }
  .alert-warn    { background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.25); color: #fde68a; }

  /* CAMERA / QR */
  #video-container { position: relative; background: #000; border-radius: 10px; overflow: hidden; }
  #scanner-video   { width: 100%; max-height: 320px; object-fit: cover; display: block; }
  #scanner-canvas  { display: none; }
  .scan-overlay { position: absolute; inset: 0; pointer-events: none; }
  .scan-corner  { position: absolute; width: 40px; height: 40px; border-color: var(--accent); border-style: solid; }
  .scan-corner.tl { top: 20px; left: 20px; border-width: 3px 0 0 3px; border-radius: 4px 0 0 0; }
  .scan-corner.tr { top: 20px; right: 20px; border-width: 3px 3px 0 0; border-radius: 0 4px 0 0; }
  .scan-corner.bl { bottom: 20px; left: 20px; border-width: 0 0 3px 3px; border-radius: 0 0 0 4px; }
  .scan-corner.br { bottom: 20px; right: 20px; border-width: 0 3px 3px 0; border-radius: 0 0 4px 0; }
  .scan-line { position: absolute; left: 20px; right: 20px; height: 2px; background: var(--accent);
               box-shadow: 0 0 6px var(--accent); animation: scanline 2.5s linear infinite; }
  @keyframes scanline { 0%{top:20px} 50%{top:calc(100% - 22px)} 100%{top:20px} }

  /* QR CANVAS */
  #qr-canvas-wrap { display: flex; justify-content: center; padding: 16px 0; }
  #qr-canvas-wrap canvas { border: 6px solid #fff; border-radius: 10px; background: #fff; }

  /* VPN STATUS CARDS */
  .vpn-card { background: #0f172a; border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; margin-bottom: 10px;
              display: flex; align-items: center; gap: 14px; cursor: pointer; transition: border-color .2s; }
  .vpn-card:hover { border-color: var(--accent); }
  .vpn-card.selected { border-color: var(--accent); background: rgba(56,189,248,.06); }
  .vpn-icon { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }

  /* POLLING ANIM */
  .spin { animation: spin .8s linear infinite; display: inline-block; }
  @keyframes spin { to { transform: rotate(360deg); } }

  /* TABLE */
  .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .data-table th { padding: 9px 12px; text-align: left; color: var(--muted); font-size: 11px; font-weight: 700; 
                   text-transform: uppercase; letter-spacing: .6px; border-bottom: 1px solid var(--border); }
  .data-table td { padding: 10px 12px; border-bottom: 1px solid rgba(51,65,85,.5); vertical-align: middle; }
  .data-table tr:last-child td { border-bottom: none; }
  .data-table tr:hover td { background: rgba(255,255,255,.02); }

  /* SCROLLBAR */
  ::-webkit-scrollbar { width: 6px; height: 6px; }
  ::-webkit-scrollbar-track { background: transparent; }
  ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

  /* DIVIDER */
  .divider { height: 1px; background: var(--border); margin: 16px 0; }

  /* PULSE */
  .pulse { animation: pulse 1.5s ease-in-out infinite; }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }

  /* CONFIG WARN */
  .config-banner { background: rgba(245,158,11,.12); border: 1px dashed rgba(245,158,11,.4); border-radius: 10px;
                   padding: 14px 18px; font-size: 13px; color: #fde68a; margin-bottom: 20px; }
</style>
</head>
<body>

<!-- ─── HEADER ─── -->
<div style="background:var(--card); border-bottom:1px solid var(--border); padding:14px 20px; display:flex; align-items:center; gap:12px;">
  <div style="width:38px;height:38px;background:linear-gradient(135deg,#0ea5e9,#6366f1);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;">
    <i class="fas fa-shield-halved" style="color:#fff;"></i>
  </div>
  <div>
    <div style="font-size:16px;font-weight:800;color:var(--text);"><?= APP_TITLE ?></div>
    <div style="font-size:11px;color:var(--muted);">AssistTeam VPN · index_mobile.php · v<?= APP_VERSION ?></div>
  </div>
  <div style="margin-left:auto;display:flex;align-items:center;gap:8px;">
    <span id="api-status-badge"></span>
  </div>
</div>

<div style="max-width:860px; margin:0 auto; padding:20px 16px;">

  <!-- Config banner jika belum dikonfigurasi -->
  <?php if (API_BASE_URL === ''): ?>
  <div class="config-banner">
    <i class="fas fa-triangle-exclamation"></i>
    <strong> Konfigurasi diperlukan</strong> — Edit konstanta <code>API_BASE_URL</code> di bagian atas file ini.
    Contoh: <code>define('API_BASE_URL', 'https://app.assist-pro.net');</code>
  </div>
  <?php endif; ?>

  <!-- ─── TAB BAR ─── -->
  <div class="tab-bar" style="border-radius:12px 12px 0 0; overflow:hidden;">
    <button class="tab-btn active" onclick="switchTab('scan')" id="tab-scan">
      <i class="fas fa-qrcode"></i> Scan QR
    </button>
    <button class="tab-btn" onclick="switchTab('generate')" id="tab-generate">
      <i class="fas fa-plus-square"></i> Generate QR
    </button>
    <button class="tab-btn" onclick="switchTab('vpnlist')" id="tab-vpnlist">
      <i class="fas fa-server"></i> VPN List
    </button>
    <button class="tab-btn" onclick="switchTab('polling')" id="tab-polling">
      <i class="fas fa-rotate"></i> Polling Status
    </button>
  </div>

  <div style="background:var(--card); border:1px solid var(--border); border-top:none; border-radius:0 0 12px 12px; padding:20px; margin-bottom:20px;">

    <!-- ═══════════════════════════════════════ -->
    <!-- TAB 1 — SCAN QR                         -->
    <!-- ═══════════════════════════════════════ -->
    <div class="tab-panel active" id="panel-scan">

      <div class="alert alert-info" style="margin-bottom:16px;">
        <i class="fas fa-circle-info"></i>
        <div>
          <strong>Tab ini mensimulasikan fungsi mobile app</strong> — Scan QR Code berisi Username VPN, 
          lalu kirim request QRSCAN (KT=02104) ke server. Setelah sukses, gunakan tab 
          <strong>Polling Status</strong> untuk cek CEKVPN dari sisi PC.
        </div>
      </div>

      <!-- Camera Scanner -->
      <div class="card" id="camera-card">
        <div class="card-title"><i class="fas fa-camera"></i> Scanner Kamera</div>
        <div id="video-container" style="display:none; margin-bottom:12px;">
          <video id="scanner-video" playsinline autoplay muted></video>
          <canvas id="scanner-canvas"></canvas>
          <div class="scan-overlay">
            <div class="scan-corner tl"></div>
            <div class="scan-corner tr"></div>
            <div class="scan-corner bl"></div>
            <div class="scan-corner br"></div>
            <div class="scan-line"></div>
          </div>
        </div>
        <div id="scan-placeholder" style="background:#0f172a; border-radius:10px; height:160px; display:flex; flex-direction:column;
             align-items:center; justify-content:center; color:var(--muted); margin-bottom:12px; border:2px dashed var(--border);">
          <i class="fas fa-camera" style="font-size:36px; margin-bottom:10px; opacity:.4;"></i>
          <span style="font-size:13px;">Kamera belum aktif</span>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn btn-primary" onclick="startScan()" id="btn-start-scan">
            <i class="fas fa-play"></i> Mulai Scan
          </button>
          <button class="btn btn-outline btn-sm" onclick="stopScan()" id="btn-stop-scan" disabled>
            <i class="fas fa-stop"></i> Stop
          </button>
          <label class="btn btn-outline btn-sm" style="cursor:pointer; margin:0;">
            <i class="fas fa-image"></i> Upload Gambar
            <input type="file" accept="image/*" style="display:none" onchange="decodeFromImage(this)">
          </label>
        </div>
      </div>

      <!-- Input Manual -->
      <div class="card">
        <div class="card-title"><i class="fas fa-keyboard"></i> Input Manual / Hasil Scan</div>
        <div style="margin-bottom:12px;">
          <label>Username VPN (hasil scan atau input manual)</label>
          <input type="text" id="scan-username" placeholder="Contoh: VPN-USR-001" autocomplete="off">
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
          <div>
            <label>MacAddress / DeviceID</label>
            <input type="text" id="scan-mac" value="<?= htmlspecialchars(SCRIPT_MAC) ?>" placeholder="XX:XX:XX:XX:XX:XX">
          </div>
          <div>
            <label>ClientID (UniqID)</label>
            <input type="text" id="scan-clientid" value="<?= htmlspecialchars(SCRIPT_CLIENT_ID) ?>">
          </div>
        </div>
        <div style="margin-bottom:16px;">
          <label>App Version</label>
          <input type="text" id="scan-appver" value="<?= htmlspecialchars(SCRIPT_APPVER) ?>" style="width:160px;">
        </div>

        <button class="btn btn-success btn-full" onclick="doQrScan()" id="btn-qrscan">
          <i class="fas fa-qrcode"></i> Kirim QRSCAN (KT=02104)
        </button>
      </div>

      <!-- Hasil QRSCAN -->
      <div id="qrscan-result" style="display:none;"></div>

    </div><!-- /panel-scan -->


    <!-- ═══════════════════════════════════════ -->
    <!-- TAB 2 — GENERATE QR                     -->
    <!-- ═══════════════════════════════════════ -->
    <div class="tab-panel" id="panel-generate">

      <div class="alert alert-info" style="margin-bottom:16px;">
        <i class="fas fa-circle-info"></i>
        <div>
          Generate QR Code berisi <strong>Username VPN</strong> — QR ini yang di-scan oleh mobile app AssistTeam 
          untuk memulai proses connect VPN. Format payload: <code>Username VPN</code> plain text.
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; align-items:start;">

        <!-- Form Generate -->
        <div>
          <div class="card" style="margin-bottom:0;">
            <div class="card-title"><i class="fas fa-gear"></i> Parameter QR</div>

            <div style="margin-bottom:12px;">
              <label>Mode Payload</label>
              <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:6px;">
                <button class="btn btn-sm btn-primary" onclick="setGenMode('username')" id="gmode-username">Username</button>
                <button class="btn btn-sm btn-outline" onclick="setGenMode('json')" id="gmode-json">JSON Cred</button>
                <button class="btn btn-sm btn-outline" onclick="setGenMode('custom')" id="gmode-custom">Custom</button>
              </div>
            </div>

            <div id="gen-fields-username">
              <div style="margin-bottom:12px;">
                <label>Username VPN</label>
                <input type="text" id="gen-vpn-username" placeholder="VPN-USR-001" oninput="updateQRPreview()">
              </div>
            </div>

            <div id="gen-fields-json" style="display:none;">
              <div style="margin-bottom:10px;">
                <label>Username VPN</label>
                <input type="text" id="gen-json-username" placeholder="VPN-USR-001" oninput="updateQRPreview()">
              </div>
              <div style="margin-bottom:10px;">
                <label>Password</label>
                <input type="text" id="gen-json-password" placeholder="password" oninput="updateQRPreview()">
              </div>
              <div style="margin-bottom:12px;">
                <label>Email (opsional)</label>
                <input type="email" id="gen-json-email" placeholder="user@email.com" oninput="updateQRPreview()">
              </div>
            </div>

            <div id="gen-fields-custom" style="display:none;">
              <div style="margin-bottom:12px;">
                <label>Payload Custom</label>
                <textarea id="gen-custom-text" rows="3" oninput="updateQRPreview()"
                  style="width:100%;padding:10px 13px;background:#0f172a;border:1px solid var(--border);
                         border-radius:8px;color:var(--text);font-size:13px;outline:none;resize:vertical;font-family:monospace;"
                  placeholder="Masukkan teks bebas untuk QR Code"></textarea>
              </div>
            </div>

            <div class="divider"></div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
              <div>
                <label>Ukuran (px)</label>
                <select id="gen-size" onchange="updateQRPreview()">
                  <option value="160">160</option>
                  <option value="200" selected>200</option>
                  <option value="256">256</option>
                  <option value="320">320</option>
                  <option value="400">400</option>
                </select>
              </div>
              <div>
                <label>Error Correction</label>
                <select id="gen-ecc" onchange="updateQRPreview()">
                  <option value="L">L — Low (7%)</option>
                  <option value="M" selected>M — Medium (15%)</option>
                  <option value="Q">Q — Quartile (25%)</option>
                  <option value="H">H — High (30%)</option>
                </select>
              </div>
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap;">
              <button class="btn btn-primary btn-sm" onclick="updateQRPreview()">
                <i class="fas fa-refresh"></i> Refresh
              </button>
              <button class="btn btn-success btn-sm" onclick="downloadQR()">
                <i class="fas fa-download"></i> Download PNG
              </button>
              <button class="btn btn-outline btn-sm" onclick="copyPayloadToScanTab()">
                <i class="fas fa-arrow-right"></i> Pakai di Scan Tab
              </button>
            </div>
          </div>
        </div>

        <!-- Preview QR -->
        <div>
          <div class="card" style="text-align:center; margin-bottom:12px;">
            <div class="card-title"><i class="fas fa-qrcode"></i> Preview QR</div>
            <div id="qr-canvas-wrap">
              <canvas id="qr-canvas"></canvas>
            </div>
            <div id="qr-payload-preview" style="font-size:11px; color:var(--muted); margin-top:8px; font-family:monospace;
                 word-break:break-all; max-height:60px; overflow:hidden; padding:0 8px;"></div>
          </div>
          <div id="qr-gen-error" style="display:none;" class="alert alert-error"></div>
        </div>

      </div>
    </div><!-- /panel-generate -->


    <!-- ═══════════════════════════════════════ -->
    <!-- TAB 3 — VPN LIST                        -->
    <!-- ═══════════════════════════════════════ -->
    <div class="tab-panel" id="panel-vpnlist">

      <div class="card">
        <div class="card-title"><i class="fas fa-envelope"></i> GETVPNLIST (KT=02100)</div>
        <div style="display:flex; gap:10px; align-items:flex-end;">
          <div style="flex:1;">
            <label>Email pengguna VPN</label>
            <input type="email" id="vpnlist-email" placeholder="user@email.com" onkeydown="if(event.key==='Enter') loadVpnList()">
          </div>
          <button class="btn btn-primary" onclick="loadVpnList()" id="btn-load-vpnlist" style="flex-shrink:0; height:42px;">
            <i class="fas fa-search"></i> Load
          </button>
        </div>
      </div>

      <div id="vpnlist-result"></div>

    </div><!-- /panel-vpnlist -->


    <!-- ═══════════════════════════════════════ -->
    <!-- TAB 4 — POLLING STATUS                  -->
    <!-- ═══════════════════════════════════════ -->
    <div class="tab-panel" id="panel-polling">

      <div class="alert alert-info" style="margin-bottom:16px;">
        <i class="fas fa-circle-info"></i>
        <div>
          <strong>Sisi PC / Server</strong> — Polling CEKVPN (KT=04001) untuk mendeteksi apakah 
          mobile app sudah scan QR. Jika <code>can connect</code>, data VPN (sertifikat, rasphone.pbk) 
          ditampilkan. Konfirmasi connect dengan EDITVPNSTATUS (KT=04003).
        </div>
      </div>

      <!-- Config polling -->
      <div class="card">
        <div class="card-title"><i class="fas fa-sliders"></i> Konfigurasi Polling</div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
          <div>
            <label>MacAddress / DeviceID</label>
            <input type="text" id="poll-mac" value="<?= htmlspecialchars(SCRIPT_MAC) ?>" placeholder="XX:XX:XX:XX:XX:XX">
          </div>
          <div>
            <label>ClientID (UniqID)</label>
            <input type="text" id="poll-clientid" value="<?= htmlspecialchars(SCRIPT_CLIENT_ID) ?>">
          </div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
          <button class="btn btn-primary" onclick="startPolling()" id="btn-start-poll">
            <i class="fas fa-play"></i> Mulai Polling
          </button>
          <button class="btn btn-outline" onclick="stopPolling()" id="btn-stop-poll" disabled>
            <i class="fas fa-stop"></i> Stop
          </button>
          <button class="btn btn-outline btn-sm" onclick="pollOnce()" id="btn-once">
            <i class="fas fa-bolt"></i> Cek Sekali
          </button>
          <div style="display:flex; align-items:center; gap:8px; margin-left:auto; font-size:13px; color:var(--muted);">
            <label style="margin:0; font-size:12px;">Interval:</label>
            <select id="poll-interval" style="width:90px; padding:6px 8px; font-size:12px;">
              <option value="2000">2 det</option>
              <option value="3000" selected>3 det</option>
              <option value="5000">5 det</option>
              <option value="10000">10 det</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Status polling -->
      <div class="card" style="margin-bottom:16px;">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
          <div class="card-title" style="margin:0; flex:1;"><i class="fas fa-signal"></i> Status Koneksi</div>
          <span id="poll-status-badge" class="badge badge-gray">Idle</span>
          <span id="poll-count" style="font-size:11px; color:var(--muted);"></span>
        </div>

        <div id="poll-live-status">
          <div style="text-align:center; padding:30px; color:var(--muted); font-size:13px;">
            <i class="fas fa-satellite-dish" style="font-size:32px; opacity:.3; display:block; margin-bottom:10px;"></i>
            Belum ada data — klik <strong>Mulai Polling</strong> atau <strong>Cek Sekali</strong>
          </div>
        </div>
      </div>

      <!-- Aksi setelah can connect -->
      <div id="poll-actions" style="display:none;">
        <div class="card" style="border-color:rgba(34,197,94,.3);">
          <div class="card-title" style="color:var(--green);"><i class="fas fa-circle-check"></i> Aksi Setelah Connect</div>
          <div style="margin-bottom:12px;">
            <label>Username VPN (otomatis dari CEKVPN)</label>
            <input type="text" id="action-username" placeholder="Username VPN">
          </div>
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button class="btn btn-success" onclick="doEditVpnStatus()">
              <i class="fas fa-check-circle"></i> Konfirmasi Connected (KT=04003)
            </button>
            <button class="btn btn-danger" onclick="doDisconnect()">
              <i class="fas fa-power-off"></i> Disconnect (KT=02105)
            </button>
          </div>
          <div id="action-result" style="margin-top:12px; display:none;"></div>
        </div>
      </div>

      <!-- Log polling -->
      <div class="card">
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
          <div class="card-title" style="margin:0; flex:1;"><i class="fas fa-list-ul"></i> Log Polling</div>
          <button class="btn btn-outline btn-sm" onclick="clearLog()"><i class="fas fa-trash"></i> Clear</button>
        </div>
        <div id="poll-log" style="background:#0f172a; border-radius:8px; padding:12px; height:180px; overflow-y:auto;
             font-family:monospace; font-size:11px; color:var(--muted); line-height:1.7;">
        </div>
      </div>

    </div><!-- /panel-polling -->

  </div><!-- /tab-content-wrapper -->

</div><!-- /max-width -->


<script>
// ═══════════════════════════════════════════════════════
//  CONFIG
// ═══════════════════════════════════════════════════════
const API_CONFIGURED = <?= $apiConfigured ?>;
const CLIENT_ID      = '<?= $scriptClientId ?>';
const SCRIPT_MAC     = '<?= $scriptMac ?>';

// ═══════════════════════════════════════════════════════
//  TAB SWITCHER
// ═══════════════════════════════════════════════════════
function switchTab(name) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  document.getElementById('panel-' + name).classList.add('active');
  if (name === 'generate') setTimeout(updateQRPreview, 100);
}

// ═══════════════════════════════════════════════════════
//  API HELPER
// ═══════════════════════════════════════════════════════
async function postAction(action, params = {}) {
  const form = new FormData();
  form.append('_action', action);
  for (const [k,v] of Object.entries(params)) form.append(k, v);
  const res = await fetch(window.location.pathname, { method:'POST', body: form });
  return res.json();
}

// Badge API status
function updateApiBadge() {
  const el = document.getElementById('api-status-badge');
  if (API_CONFIGURED) {
    el.className = 'badge badge-green';
    el.innerHTML = '<i class="fas fa-circle" style="font-size:7px;"></i> API Terhubung';
  } else {
    el.className = 'badge badge-amber';
    el.innerHTML = '<i class="fas fa-triangle-exclamation" style="font-size:10px;"></i> Belum Dikonfigurasi';
  }
}
document.addEventListener('DOMContentLoaded', updateApiBadge);

// ═══════════════════════════════════════════════════════
//  TAB 1 — SCANNER
// ═══════════════════════════════════════════════════════
let scanStream   = null;
let scanInterval = null;
const video  = document.getElementById('scanner-video');
const canvas = document.getElementById('scanner-canvas');
const ctx    = canvas.getContext('2d', {willReadFrequently: true});

async function startScan() {
  try {
    scanStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'environment', width:{ideal:1280}, height:{ideal:720} }
    });
    video.srcObject = scanStream;
    document.getElementById('video-container').style.display = 'block';
    document.getElementById('scan-placeholder').style.display = 'none';
    document.getElementById('btn-start-scan').disabled = true;
    document.getElementById('btn-stop-scan').disabled  = false;
    scanInterval = setInterval(scanFrame, 150);
  } catch(e) {
    showScanResult('error', `<i class="fas fa-ban"></i> Kamera tidak tersedia: ${e.message}
      <br><small>Gunakan HTTPS atau upload gambar QR.</small>`);
  }
}

function stopScan() {
  clearInterval(scanInterval);
  if (scanStream) scanStream.getTracks().forEach(t => t.stop());
  scanStream = null;
  video.srcObject = null;
  document.getElementById('video-container').style.display = 'none';
  document.getElementById('scan-placeholder').style.display = 'flex';
  document.getElementById('btn-start-scan').disabled = false;
  document.getElementById('btn-stop-scan').disabled  = true;
}

function scanFrame() {
  if (!video.videoWidth) return;
  canvas.width  = video.videoWidth;
  canvas.height = video.videoHeight;
  ctx.drawImage(video, 0, 0);
  const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
  const code    = jsQR(imgData.data, imgData.width, imgData.height, {inversionAttempts:'dontInvert'});
  if (code) {
    stopScan();
    handleScanResult(code.data);
  }
}

function decodeFromImage(input) {
  const file = input.files[0];
  if (!file) return;
  const img = new Image();
  const url = URL.createObjectURL(file);
  img.onload = () => {
    canvas.width  = img.naturalWidth;
    canvas.height = img.naturalHeight;
    ctx.drawImage(img, 0, 0);
    const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const code    = jsQR(imgData.data, imgData.width, imgData.height, {inversionAttempts:'attemptBoth'});
    URL.revokeObjectURL(url);
    if (code) {
      handleScanResult(code.data);
    } else {
      showScanResult('error', '<i class="fas fa-times-circle"></i> QR Code tidak terdeteksi dalam gambar ini.');
    }
  };
  img.src = url;
  input.value = '';
}

function handleScanResult(value) {
  // Auto-detect tipe payload
  const type = detectQRType(value);

  // Isi field username jika tipenya username/teks VPN
  if (type.username) {
    document.getElementById('scan-username').value = type.username;
  }

  showScanResult('info',
    `<div style="display:flex;align-items:flex-start;gap:10px;">
      <span class="badge ${type.badgeCls}" style="flex-shrink:0;margin-top:2px;">${type.label}</span>
      <div>
        <div style="font-weight:700;color:var(--text);margin-bottom:4px;">QR Berhasil Dibaca</div>
        <code style="font-size:12px;word-break:break-all;color:var(--muted);">${escHtml(value)}</code>
      </div>
    </div>`
  );
}

// Deteksi tipe payload QR
function detectQRType(val) {
  // Coba parse JSON (credential object)
  try {
    const obj = JSON.parse(val);
    const u = obj.Username || obj.username || obj.u || obj.vpn_username || '';
    return { label:'JSON CRED', badgeCls:'badge-violet', username: u };
  } catch(e) {}

  // token=xxx / bearer=xxx / key=xxx
  const tokenMatch = val.match(/(?:token|bearer|key|password)=([^\s&]+)/i);
  if (tokenMatch) return { label:'TOKEN', badgeCls:'badge-amber', username:'' };

  // vpn:// scheme → extract username after //
  if (/^vpn:\/\//i.test(val)) {
    const u = val.replace(/^vpn:\/\//i, '').split(/[/?#]/)[0];
    return { label:'VPN SCHEME', badgeCls:'badge-violet', username: u };
  }

  // URL biasa
  if (/^https?:\/\//i.test(val)) return { label:'URL', badgeCls:'badge-blue', username:'' };

  // Plain text → anggap sebagai Username VPN
  return { label:'USERNAME', badgeCls:'badge-green', username: val.trim() };
}

function showScanResult(type, html) {
  const el = document.getElementById('qrscan-result');
  const cls = {info:'alert-info', success:'alert-success', error:'alert-error', warn:'alert-warn'}[type] || 'alert-info';
  el.innerHTML = `<div class="alert ${cls}">${html}</div>`;
  el.style.display = 'block';
}

// ─── Kirim QRSCAN ─────────────────────────────
async function doQrScan() {
  const username   = document.getElementById('scan-username').value.trim();
  const macAddress = document.getElementById('scan-mac').value.trim();
  const clientId   = document.getElementById('scan-clientid').value.trim();
  const appVersion = document.getElementById('scan-appver').value.trim();

  if (!username) {
    showScanResult('warn', '<i class="fas fa-triangle-exclamation"></i> Masukkan Username VPN terlebih dahulu.');
    return;
  }

  const btn = document.getElementById('btn-qrscan');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"><i class="fas fa-circle-notch"></i></span> Mengirim...';
  showScanResult('info', '<i class="fas fa-circle-notch spin"></i> Mengirim QRSCAN ke server...');

  try {
    const res = await postAction('qrscan', { username, mac_address: macAddress, client_id: clientId, app_version: appVersion });
    if (!res.ok) {
      showScanResult('error', `<i class="fas fa-times-circle"></i> ${escHtml(res.msg)}`);
      return;
    }
    const d = res.data;
    if (d.Status == '1' || d.Status === 1) {
      showScanResult('success',
        `<div>
          <div style="font-weight:700;margin-bottom:8px;"><i class="fas fa-check-circle"></i> QRSCAN SUKSES — ${escHtml(d.MSG)}</div>
          <div style="font-size:12px;color:var(--muted);">Status vpn_verify_list diubah ke <strong>3</strong> (mobile scan OK).<br>
          Sekarang buka tab <strong>Polling Status</strong> untuk cek CEKVPN dari sisi PC.<br>
          Gunakan ClientID: <code>${escHtml(clientId)}</code> dan MAC: <code>${escHtml(macAddress)}</code></div>
          <div style="margin-top:10px;">
            <button class="btn btn-primary btn-sm" onclick="prefillPolling('${escHtml(macAddress)}','${escHtml(clientId)}','${escHtml(username)}')">
              <i class="fas fa-arrow-right"></i> Lanjut ke Polling Status
            </button>
          </div>
        </div>`
      );
    } else {
      showScanResult('error',
        `<div>
          <div style="font-weight:700;margin-bottom:6px;"><i class="fas fa-times-circle"></i> QRSCAN GAGAL</div>
          <div style="font-size:13px;">${escHtml(d.MSG || 'Tidak ada pesan error')}</div>
          ${renderRawJson(d)}
        </div>`
      );
    }
  } catch(e) {
    showScanResult('error', `<i class="fas fa-bug"></i> Error JS: ${escHtml(e.message)}`);
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-qrcode"></i> Kirim QRSCAN (KT=02104)';
  }
}

function prefillPolling(mac, clientId, username) {
  document.getElementById('poll-mac').value      = mac;
  document.getElementById('poll-clientid').value = clientId;
  document.getElementById('action-username').value = username;
  switchTab('polling');
}


// ═══════════════════════════════════════════════════════
//  TAB 2 — GENERATE QR
// ═══════════════════════════════════════════════════════
let genMode = 'username';

function setGenMode(mode) {
  genMode = mode;
  ['username','json','custom'].forEach(m => {
    document.getElementById('gen-fields-' + m).style.display = (m === mode) ? 'block' : 'none';
    const btn = document.getElementById('gmode-' + m);
    btn.className = 'btn btn-sm ' + (m === mode ? 'btn-primary' : 'btn-outline');
  });
  updateQRPreview();
}

function buildPayload() {
  switch (genMode) {
    case 'username':
      return document.getElementById('gen-vpn-username').value.trim() || 'VPN-USERNAME';
    case 'json': {
      const obj = { Username: document.getElementById('gen-json-username').value.trim() || 'VPN-USR-001' };
      const pw  = document.getElementById('gen-json-password').value.trim();
      const em  = document.getElementById('gen-json-email').value.trim();
      if (pw) obj.Password = pw;
      if (em) obj.Email    = em;
      return JSON.stringify(obj);
    }
    case 'custom':
      return document.getElementById('gen-custom-text').value.trim() || 'custom-payload';
    default:
      return 'payload';
  }
}

function updateQRPreview() {
  const payload = buildPayload();
  const size    = parseInt(document.getElementById('gen-size').value);
  const ecc     = document.getElementById('gen-ecc').value;
  const canvas  = document.getElementById('qr-canvas');
  const errEl   = document.getElementById('qr-gen-error');

  if (!payload) return;

  QRCode.toCanvas(canvas, payload, {
    width: size,
    margin: 2,
    color: { dark:'#000000', light:'#ffffff' },
    errorCorrectionLevel: ecc
  }, (err) => {
    if (err) {
      errEl.innerHTML = `<i class="fas fa-triangle-exclamation"></i> ${escHtml(err.message)}`;
      errEl.style.display = 'flex';
    } else {
      errEl.style.display = 'none';
    }
  });

  document.getElementById('qr-payload-preview').textContent = payload;
}

function downloadQR() {
  const canvas  = document.getElementById('qr-canvas');
  const payload = buildPayload();
  const link    = document.createElement('a');
  const safeName = (payload.substring(0,30).replace(/[^a-zA-Z0-9_-]/g,'_') || 'qr') + '.png';
  link.download = safeName;
  link.href     = canvas.toDataURL('image/png');
  link.click();
}

function copyPayloadToScanTab() {
  const payload = buildPayload();
  // Auto-detect dan isi username
  const type = detectQRType(payload);
  if (type.username) {
    document.getElementById('scan-username').value = type.username;
    switchTab('scan');
    showScanResult('info', `<i class="fas fa-arrow-left"></i> Payload <code>${escHtml(type.username)}</code> disalin ke tab Scan.`);
  } else {
    switchTab('scan');
    showScanResult('warn', '<i class="fas fa-triangle-exclamation"></i> Payload bukan username langsung. Isi kolom Username VPN secara manual.');
  }
}


// ═══════════════════════════════════════════════════════
//  TAB 3 — VPN LIST
// ═══════════════════════════════════════════════════════
async function loadVpnList() {
  const email = document.getElementById('vpnlist-email').value.trim();
  if (!email) return;
  const btn = document.getElementById('btn-load-vpnlist');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"><i class="fas fa-circle-notch"></i></span>';
  const resultEl = document.getElementById('vpnlist-result');
  resultEl.innerHTML = `<div class="alert alert-info"><i class="fas fa-circle-notch spin"></i> Mengambil data VPN untuk <strong>${escHtml(email)}</strong>...</div>`;

  try {
    const res = await postAction('get_vpn_list', { email });
    if (!res.ok) {
      resultEl.innerHTML = `<div class="alert alert-error"><i class="fas fa-times-circle"></i> ${escHtml(res.msg)}</div>`;
      return;
    }
    const d = res.data;
    if (d.Status != 1 && d.Status !== '1') {
      resultEl.innerHTML = `<div class="alert alert-error"><i class="fas fa-times-circle"></i> ${escHtml(d.MSG || 'Gagal')}</div>`;
      return;
    }
    const list = d.Data || [];
    if (list.length === 0) {
      resultEl.innerHTML = `<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i> Tidak ada data VPN untuk email <strong>${escHtml(email)}</strong>.</div>`;
      return;
    }

    let html = `
      <div class="card">
        <div class="card-title"><i class="fas fa-server"></i> ${list.length} VPN ditemukan untuk ${escHtml(email)}</div>
        <table class="data-table">
          <thead><tr>
            <th>Username</th><th>Customer</th><th>Profile</th>
            <th>Status</th><th>Last Used</th><th>Block</th><th>Aksi</th>
          </tr></thead>
          <tbody>`;
    list.forEach(row => {
      const statusInfo = vpnStatusInfo(row.Status);
      html += `<tr>
        <td><code style="color:var(--accent);">${escHtml(row.Username||'')}</code></td>
        <td style="font-size:12px;">${escHtml(row.Customer||'')}</td>
        <td style="font-size:11px;color:var(--muted);">${escHtml(row.PPP_Profile||'')}</td>
        <td><span class="badge ${statusInfo.cls}">${statusInfo.icon} ${statusInfo.label}</span></td>
        <td style="font-size:11px;color:var(--muted);">${escHtml(row.LastUsed||'')}</td>
        <td>${row.Block ? '<span class="badge badge-red"><i class="fas fa-ban"></i> Blok</span>' : '<span class="badge badge-gray">-</span>'}</td>
        <td>
          <button class="btn btn-sm btn-outline" onclick="quickFillUsername('${escHtml(row.Username||'')}')">
            <i class="fas fa-qrcode"></i> Generate QR
          </button>
          <button class="btn btn-sm btn-danger" style="margin-top:4px;" onclick="quickDisconnect('${escHtml(row.Username||'')}')">
            <i class="fas fa-power-off"></i> Disconnect
          </button>
        </td>
      </tr>`;
    });
    html += `</tbody></table></div>`;
    resultEl.innerHTML = html;
  } catch(e) {
    resultEl.innerHTML = `<div class="alert alert-error"><i class="fas fa-bug"></i> ${escHtml(e.message)}</div>`;
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-search"></i> Load';
  }
}

function quickFillUsername(username) {
  document.getElementById('gen-vpn-username').value = username;
  setGenMode('username');
  switchTab('generate');
  updateQRPreview();
}

async function quickDisconnect(username) {
  if (!confirm(`Disconnect VPN user: ${username}?`)) return;
  try {
    const res = await postAction('disconnect_vpn', { username });
    const d   = res.ok ? res.data : res;
    const ok  = d.Status == '1' || d.Status === 1;
    alert((ok ? '✅ Disconnect sukses: ' : '❌ Gagal: ') + (d.MSG || ''));
    if (ok) loadVpnList();
  } catch(e) { alert('Error: ' + e.message); }
}

function vpnStatusInfo(s) {
  s = parseInt(s);
  if (s === 0) return { label:'Idle',          cls:'badge-gray',   icon:'<i class="fas fa-circle" style="font-size:7px;"></i>' };
  if (s === 1) return { label:'Connected',      cls:'badge-green',  icon:'<i class="fas fa-check-circle"></i>' };
  if (s === 3) return { label:'Scan OK',         cls:'badge-amber',  icon:'<i class="fas fa-qrcode"></i>' };
  if (s === 4) return { label:'Connecting...',   cls:'badge-blue',   icon:'<i class="fas fa-circle-notch spin"></i>' };
  return       { label:'Status ' + s,            cls:'badge-gray',   icon:'' };
}


// ═══════════════════════════════════════════════════════
//  TAB 4 — POLLING STATUS
// ═══════════════════════════════════════════════════════
let pollTimer    = null;
let pollCount    = 0;
let lastUsername = '';

function addLog(msg, type = 'default') {
  const logEl = document.getElementById('poll-log');
  const now   = new Date().toLocaleTimeString('id-ID');
  const colors = { success:'#86efac', error:'#fca5a5', warn:'#fde68a', info:'#7dd3fc', default:'#94a3b8' };
  const c = colors[type] || colors.default;
  const line = document.createElement('div');
  line.innerHTML = `<span style="color:#475569;">[${now}]</span> <span style="color:${c};">${msg}</span>`;
  logEl.appendChild(line);
  logEl.scrollTop = logEl.scrollHeight;
}

function clearLog() { document.getElementById('poll-log').innerHTML = ''; }

function startPolling() {
  if (pollTimer) return;
  const interval = parseInt(document.getElementById('poll-interval').value);
  document.getElementById('btn-start-poll').disabled = true;
  document.getElementById('btn-stop-poll').disabled  = false;
  document.getElementById('poll-status-badge').className = 'badge badge-blue pulse';
  document.getElementById('poll-status-badge').innerHTML  = '<i class="fas fa-circle-notch spin"></i> Polling...';
  addLog('Polling dimulai, interval ' + (interval/1000) + 'det', 'info');
  pollTimer = setInterval(pollOnce, interval);
  pollOnce();
}

function stopPolling() {
  clearInterval(pollTimer);
  pollTimer = null;
  document.getElementById('btn-start-poll').disabled = false;
  document.getElementById('btn-stop-poll').disabled  = true;
  document.getElementById('poll-status-badge').className = 'badge badge-gray';
  document.getElementById('poll-status-badge').innerHTML  = 'Stopped';
  addLog('Polling dihentikan.', 'warn');
}

async function pollOnce() {
  const mac      = document.getElementById('poll-mac').value.trim();
  const clientId = document.getElementById('poll-clientid').value.trim();

  pollCount++;
  document.getElementById('poll-count').textContent = `(${pollCount}x)`;

  try {
    const res = await postAction('cek_vpn', { mac_address: mac, client_id: clientId });
    if (!res.ok) {
      addLog('Error: ' + (res.msg || 'unknown'), 'error');
      updatePollLiveStatus('error', res.msg || 'error', null);
      return;
    }
    const outer = res.data;       // { MSG:"Ok", Status:1, Data:{ MSG, Status, DataVPN? } }
    const inner = outer.Data || outer;
    const msg   = inner.MSG  || outer.MSG || '';
    const s     = inner.Status;

    addLog(`CEKVPN → ${msg} (Status=${s})`, msg === 'can connect' ? 'success' : 'default');

    if (msg === 'can connect' && s == 1) {
      // Ambil username dari DataVPN
      if (inner.DataVPN && inner.DataVPN.Username) {
        lastUsername = inner.DataVPN.Username;
        document.getElementById('action-username').value = lastUsername;
      }
      updatePollLiveStatus('connect', msg, inner.DataVPN || null);
      stopPolling();
      document.getElementById('poll-actions').style.display = 'block';
    } else if (msg === 'sudah connect' || s == 3) {
      updatePollLiveStatus('connected', msg, null);
    } else if (msg.indexOf('not found') >= 0 || s == 2) {
      updatePollLiveStatus('idle', msg, null);
    } else {
      updatePollLiveStatus('unknown', msg, null);
    }
  } catch(e) {
    addLog('JS Error: ' + e.message, 'error');
  }
}

function updatePollLiveStatus(state, msg, dataVPN) {
  const el = document.getElementById('poll-live-status');
  const badge = document.getElementById('poll-status-badge');

  if (state === 'connect') {
    badge.className = 'badge badge-green';
    badge.innerHTML = '<i class="fas fa-check-circle"></i> Can Connect!';

    let vpnHtml = '';
    if (dataVPN) {
      vpnHtml = `
        <div class="divider"></div>
        <div style="font-size:12px; color:var(--muted); margin-bottom:8px; font-weight:700; text-transform:uppercase; letter-spacing:.6px;">Data VPN</div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:12px;">
          ${dataVPN.Username    ? `<div><span style="color:var(--muted);">Username:</span><br><code style="color:var(--accent);">${escHtml(dataVPN.Username)}</code></div>` : ''}
          ${dataVPN.Customer    ? `<div><span style="color:var(--muted);">Customer:</span><br>${escHtml(dataVPN.Customer)}</div>` : ''}
          ${dataVPN.Host        ? `<div><span style="color:var(--muted);">Host:</span><br><code>${escHtml(dataVPN.Host)}</code></div>` : ''}
          ${dataVPN.VPN_Gateway ? `<div><span style="color:var(--muted);">Gateway:</span><br><code>${escHtml(dataVPN.VPN_Gateway)}</code></div>` : ''}
        </div>
        ${dataVPN.URLSertifikat ? `
        <div class="divider"></div>
        <div style="font-size:12px;">
          <div style="margin-bottom:8px;">
            <span style="color:var(--muted);">URL Sertifikat:</span><br>
            <a href="${escHtml(dataVPN.URLSertifikat)}" target="_blank" style="color:var(--accent); font-size:11px; word-break:break-all;">
              <i class="fas fa-certificate"></i> ${escHtml(dataVPN.URLSertifikat)}
            </a>
          </div>
          <div>
            <span style="color:var(--muted);">URL Rasphone (.pbk):</span><br>
            <a href="${escHtml(dataVPN.URLRasphone||'')}" target="_blank" style="color:var(--accent); font-size:11px; word-break:break-all;">
              <i class="fas fa-file-alt"></i> ${escHtml(dataVPN.URLRasphone||'')}
            </a>
          </div>
        </div>` : ''}`;
    }

    el.innerHTML = `
      <div style="display:flex; align-items:flex-start; gap:14px;">
        <div style="width:44px;height:44px;background:rgba(34,197,94,.15);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fas fa-check-circle" style="color:var(--green); font-size:22px;"></i>
        </div>
        <div style="flex:1;">
          <div style="font-size:15px;font-weight:800;color:var(--green);margin-bottom:4px;">CAN CONNECT</div>
          <div style="font-size:12px;color:var(--muted);">Mobile app sudah scan QR. PC dapat melakukan VPN connect.</div>
          ${vpnHtml}
        </div>
      </div>`;

  } else if (state === 'connected') {
    badge.className = 'badge badge-blue';
    badge.innerHTML = '<i class="fas fa-link"></i> Connected';
    el.innerHTML = `
      <div class="alert alert-info">
        <i class="fas fa-link"></i>
        <div><strong>Sudah Connect</strong> — VPN sedang aktif (Status=3/4). Disconnect terlebih dahulu jika ingin reconnect.</div>
      </div>`;
  } else if (state === 'idle') {
    badge.className = 'badge badge-gray';
    badge.innerHTML = '<i class="fas fa-minus-circle"></i> Idle';
    el.innerHTML = `
      <div class="alert alert-warn">
        <i class="fas fa-circle-exclamation"></i>
        <div><strong>Not Found / Disconnect</strong> — ${escHtml(msg)}. Pastikan Username VPN sudah terdaftar dan MacAddress/ClientID sesuai dengan yang digunakan saat QRSCAN.</div>
      </div>`;
  } else if (state === 'error') {
    badge.className = 'badge badge-red';
    badge.innerHTML = '<i class="fas fa-times-circle"></i> Error';
    el.innerHTML = `<div class="alert alert-error"><i class="fas fa-times-circle"></i> ${escHtml(msg)}</div>`;
  } else {
    badge.className = 'badge badge-amber';
    badge.innerHTML = '<i class="fas fa-circle-question"></i> ' + escHtml(msg.substring(0,20));
    el.innerHTML = `<div class="alert alert-warn"><i class="fas fa-circle-question"></i> ${escHtml(msg)}</div>`;
  }
}

// ─── EDITVPNSTATUS ────────────────────────────
async function doEditVpnStatus() {
  const username  = document.getElementById('action-username').value.trim();
  const mac       = document.getElementById('poll-mac').value.trim();
  const clientId  = document.getElementById('poll-clientid').value.trim();
  if (!username) { alert('Username VPN diperlukan'); return; }

  const btn = event.currentTarget;
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"><i class="fas fa-circle-notch"></i></span> Mengirim...';

  try {
    const res = await postAction('edit_vpn_status', { username, mac_address: mac, client_id: clientId });
    const d   = res.ok ? res.data : res;
    const ok  = d.Status == '1' || d.Status === 1;
    showActionResult(ok ? 'success' : 'error',
      `<i class="fas fa-${ok?'check-circle':'times-circle'}"></i> 
       EDITVPNSTATUS (KT=04003): <strong>${escHtml(d.MSG||'')}</strong>`
    );
    if (ok) addLog('EDITVPNSTATUS sukses → Status=1 (fully connected)', 'success');
  } catch(e) {
    showActionResult('error', `<i class="fas fa-bug"></i> ${escHtml(e.message)}`);
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-check-circle"></i> Konfirmasi Connected (KT=04003)';
  }
}

// ─── DISCONECTVPN ─────────────────────────────
async function doDisconnect() {
  const username = document.getElementById('action-username').value.trim();
  if (!username) { alert('Username VPN diperlukan'); return; }
  if (!confirm(`Disconnect VPN user: ${username}?`)) return;

  const btn = event.currentTarget;
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"><i class="fas fa-circle-notch"></i></span> Disconnect...';

  try {
    const res = await postAction('disconnect_vpn', { username });
    const d   = res.ok ? res.data : res;
    const ok  = d.Status == '1' || d.Status === 1;
    showActionResult(ok ? 'success' : 'error',
      `<i class="fas fa-${ok?'check-circle':'times-circle'}"></i> 
       DISCONECTVPN (KT=02105): <strong>${escHtml(d.MSG||'')}</strong>`
    );
    if (ok) {
      addLog('DISCONECTVPN sukses → Status=0 reset', 'success');
      document.getElementById('poll-actions').style.display = 'none';
      updatePollLiveStatus('idle', 'Disconnect berhasil', null);
    }
  } catch(e) {
    showActionResult('error', `<i class="fas fa-bug"></i> ${escHtml(e.message)}`);
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-power-off"></i> Disconnect (KT=02105)';
  }
}

function showActionResult(type, html) {
  const el  = document.getElementById('action-result');
  const cls = { success:'alert-success', error:'alert-error', warn:'alert-warn' }[type] || 'alert-info';
  el.innerHTML    = `<div class="alert ${cls}">${html}</div>`;
  el.style.display = 'block';
}

// ═══════════════════════════════════════════════════════
//  HELPER
// ═══════════════════════════════════════════════════════
function escHtml(s) {
  if (!s && s !== 0) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderRawJson(obj) {
  const j = JSON.stringify(obj, null, 2);
  return `<details style="margin-top:8px;font-size:11px;">
    <summary style="cursor:pointer;color:var(--muted);">Raw response</summary>
    <pre style="background:#0f172a;padding:10px;border-radius:6px;margin-top:6px;overflow:auto;max-height:120px;color:var(--muted);">${escHtml(j)}</pre>
  </details>`;
}

// Init
document.addEventListener('DOMContentLoaded', () => {
  updateQRPreview();
});
</script>
</body>
</html>
