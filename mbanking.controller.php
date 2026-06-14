<?php
class mbanking_Controller extends MVC_Controller {
  private static $nResponseCode = MVC::HTTP_CLIENT_BAD_REQUEST;
	private static $cKodeLog			= "SW3";
	
  function index() {
		date_default_timezone_set('Asia/Jakarta');
		
		$cResponse  = "";
    $vaResponse = array();
    $vaRequest  = array();
    $cRequest = empty($_POST['cCode']) ? "" : $_POST['cCode'];
		$cENP 		= empty($_POST['ENP']) ? "" : $_POST['ENP'];
		$cDID 		= empty($_POST['DEVICEID']) ? "" : $_POST['DEVICEID'];
		
    if (empty($cRequest)) {
      self::$nResponseCode = MVC::HTTP_CLIENT_BAD_REQUEST;
      $vaResponse = array("MTI"=>"100","RC"=>"","MSG"=>"Request kosong!");
    } else {
			$cRequest  = SQL2String($cRequest);
      $vaRequest = json_decode($cRequest,true);
			/*
			$vaB = array(
				"MTI"	=> "006",
				"MSG" => array(
					"TRX"	=> "99",
					"ENP" => $cENP,
					"DID"	=> $cDID,
					"REQ"	=> $cRequest
				),
			);
			$cB = json_encode($vaB);
			$cM   = "cCode=" . $cB;
			
			$cU	= self::GetConfig('ss');
			$vaH = array(
				'authorization: ' . hash('sha256',$cM.SNow()),
				'identity: ' . self::GetConfig('cicd'),
				'datetime: ' . SNow(),
				'Content-Type: application/x-www-form-urlencoded'
			);
			SendHTTPPostMB($cU,$cM,'',false,$vaH);
			*/
			
			$cIP            = $_SERVER['REMOTE_ADDR'];
			objData::Insert("digital_log",array("DateTime"=>date("Y-m-d H:i:s"),"Message"=>$_POST,"IPAddress"=>$cIP));
      if (isset($vaRequest['MTI'])) {
        #--- buat demo google tgl 22-09-2022
        //if(isset($vaRequest["MSG"]["HP"]) && ($vaRequest["MSG"]["HP"] == "+6281234567891" || $vaRequest["MSG"]["HP"] == "081234567891")){
          //self::$nResponseCode = MVC::HTTP_SUCCESS_OK;
          //$vaResponse[0] = join("|",array("99","Token demo akan dikirim via email",""));
        //}else{
        //$vaResponse = $this::DigitalBankHome($cRequest, $vaRequest);
        //} #--- buat demo google tgl 22-09-2022
				
				$vaResponse = $this::DigitalBankHome($cRequest, $vaRequest);
				//self::$nResponseCode = MVC::HTTP_CLIENT_BAD_REQUEST;
      	//$vaResponse = array("MTI"=>"100","RC"=>"","MSG"=>"Request kosong!");
      } else {
				self::$nResponseCode = MVC::HTTP_CLIENT_BAD_REQUEST;
        $vaResponse = array("MTI"=>"100","RC"=>"","MSG"=>"Request salah!!");
      }
    }
    $cResponse = json_encode($vaResponse, JSON_UNESCAPED_SLASHES);
    return MVC::Response($cResponse, self::$nResponseCode);
  }
  
  private function DigitalBankHome($cRequest, $vaRequest) {
    $cFakeResponse  = MBankingFunc::JSON2ISO(false,"231041", "000000000000", date("hi"), date("dm"), "000000000000","00","0","0","0000000000000000000000000000000000000000000000000000000000000000", "000000000000000000000000", "0", "0");
    $cIP            = $_SERVER['REMOTE_ADDR'];
    # akses digital skrg dijadwal dari pukul 00:31 WIB hingga 23:29 WIB.
    # Jika waktu antara 23:30 WIB hingga 00:30 WIB maka transaksi ditutup
    //$nTime = date("H:i:s");
    //if (
    //  (strtotime($nTime) >= strtotime("23:30:00") && strtotime($nTime) <= strtotime("23:59:00")) ||
    //  (strtotime($nTime) >= strtotime("00:00:00") && strtotime($nTime) <= strtotime("00:30:00"))
    //) {
    $nTime = date("H:i");
    //if( $nTime >= aCfg("msmBankingJamAwalCutOff") || $nTime <= aCfg("msmBankingJamAkhirCutOff") ){
    // [JAM-BUKA] Cut-off dikonfigurasi manual: tutup 23:00 WIB, buka kembali 05:00 WIB
    // Nilai lama dari DB (msmBankingJamAwalCutOff / msmBankingJamAkhirCutOff) tidak digunakan.
    // Untuk mengubah jam, edit dua konstanta di bawah ini:
    $nStart = strtotime("23:00"); // [JAM-TUTUP] jam mulai cut-off  → ubah di sini
    $nEnd   = strtotime("04:59"); // [JAM-BUKA]  jam akhir cut-off  → sistem buka mulai 05:00
    $nNow   = strtotime($nTime);
    if ($nStart > $nEnd) {
      // Range melewati tengah malam (kondisi aktif): 23:00 - 05:00
      $isCutOff = ($nNow >= $nStart || $nNow <= $nEnd);
    } else {
      // Range normal dalam satu hari: misal 08:00 - 17:00
      $isCutOff = ($nNow >= $nStart && $nNow <= $nEnd);
    }
		
		if ($isCutOff) {
			self::$nResponseCode = MVC::HTTP_CLIENT_BAD_REQUEST;
      $vaResponse = array("MTI"=>"101","RC"=>"","MSG"=>$cFakeResponse);
      insertDigitalLog($_POST,$cIP,true,$vaResponse,"Request tidak wajar");
    } else {
      # jika tidak ada request mencurigakan, maka tahap kedua adalah cek rentang waktu request dari sender yg sama
      $lWeird = false;
      $cKT    = "";
      if (isset($vaRequest['MSG']['KT'])) {
        $cKT = $vaRequest['MSG']['KT'];
      } else {
        if (isset($vaRequest['MSG']['TRX'])) {
          $cKT = $vaRequest['MSG']['TRX'];
        } else {
          if (isset($vaRequest['TRX'])) {
            $cKT = $vaRequest['TRX'] ;
          } else {
            $cKT = isset($vaRequest['KT']) ? $vaRequest['KT'] : '';  // fix: isset() guard, cegah PHP Notice Undefined index
          }
        }
      }
      if (strpos($cRequest,"DE003") > 0 || $cKT == TRX_GET_TOKEN) {
        $lWeird = checkRequest($cRequest,$cIP);
      }
			
			# coba weird ini kita false, semoga kedepannya senantiasa sejahtera dunia akhirat.
			# - sapi, 5 Agustus 2023 jam 18:18
			$lWeird = false;
      # jika ada request iso dan daftar akun, dari IP sama, yg masuk dalam waktu kurang dari 10 detik, maka lgsg kita block
      if($lWeird){
				$cResponse  = $cFakeResponse ; 
        $vaResponse = array("MTI"=>"101","RC"=>"","MSG"=>$cResponse);
        insertDigitalLog($_POST,$cIP,true,$vaResponse,"Request terlalu cepat di bawah 10 detik");
      } else {
				# kalau tidak ada yg aneh, teruskan saja request nya
        insertDigitalLog($_POST,$cIP);
        self::$nResponseCode = MVC::HTTP_SUCCESS_OK;
        $vaResponse = $this::ReadRequest($cRequest, $vaRequest);
      }
    }
    return $vaResponse;
  }
  
  private function ReadRequest($cRequest, $vaRequest) {
		$cDe3 = isset($vaRequest['MSG']['DE003'])? $vaRequest['MSG']['DE003'] : "";
		
    if ($vaRequest['MTI'] == DIGITAL_BANK && isset($vaRequest['KT']) == "") { // MTI = 002
      $vaResponse  = $this::mBanking($cRequest, $vaRequest['MSG']);
    } else if ($vaRequest['MTI'] == DIGITAL_BANK_V3 && $cDe3 != "") { // MTI = 006
      $vaResponse  = $this::mBanking($cRequest, $vaRequest['MSG']);
    } else if ($vaRequest['MTI'] == DIGITAL_BANK_AMBILDATA) { // MTI = 004
			$vaResponse  = $this::mBankingGetData($vaRequest['MSG'], $cRequest);
    } else if ($vaRequest['MTI'] == DIGITAL_BANK_V3) { // MTI = 006 Baru
			$vaResponse  = $this::mBankingGetData($vaRequest['MSG'], $cRequest);
    } else if ($vaRequest['MTI'] == DIGITAL_BANK_CEKSTATUSTRX) { // MTI = 005
      // $vaResponse  = $this::mBankingCekTrx($vaRequest['MSG']);
    } else if ($vaRequest['MTI'] == DIGITAL_BANK_REGISTER && $vaRequest['KT'] == TRX_MB_AKTIVASI_FROM_CORE) { // MTI = 009 && KT = 50
      $vaResponse  = $this::RegisterDigital($vaRequest);
    } else if ($vaRequest['MTI'] == DIGITAL_BANK_REGISTER && $vaRequest['KT'] == TRX_MB_DEAKTIVASI_FROM_CORE) { // MTI = 009 && KT = 51
      $vaResponse  = $this::Unregister($vaRequest);
    } else if ($vaRequest['MTI'] == DIGITAL_BANK_REGISTER && $vaRequest['KT'] == TRX_MB_SENDSMS) { // MTI = 009 && KT = 52
      $vaResponse  = KirimPesan($vaRequest['MSG'], "mBanking");
    } else if ($vaRequest['MTI'] == DIGITAL_BANK_REGISTER && $vaRequest['KT'] == TRX_MB_GETTOKEN_DIGITAL) { // MTI = 009 && KT = 54
      $vaResponse  = $this::GetTokenDigital($vaRequest);
    }else if($vaRequest['MTI'] == DIGITAL_BANK_REGISTER && $vaRequest['KT'] == TRX_MB_AKTIVASI_FROM_CORE_V2 ){  // MTI = 009 && KT = 80
      $vaResponse  = $this::RegisterDigital_v2($vaRequest);
    }else if($vaRequest['MTI'] == DIGITAL_BANK_REGISTER && $vaRequest['KT'] == TRX_MB_VALIDASI_KODE_FASILITAS ){  // MTI = 009 && KT = 81
      $vaResponse  = $this::ValidasiKodeFasilitas($vaRequest);
    }else if($vaRequest['MTI'] == DIGITAL_BANK_REGISTER && $vaRequest['KT'] == TRX_MB_KIRIM_ULANG_TOKEN_CBS ){ // MTI = 009 && KT = 83 rafii
			$vaResponse  = $this::KirimUlangKodeFasilitas($vaRequest);
		}else if($vaRequest['MTI'] == DIGITAL_BANK_REGISTER && $vaRequest['KT'] == TRX_MB_GETLOG ){ // MTI = 009 && KT = 85 ody
			$vaResponse  = $this::GetLogDigitalB($vaRequest);
		}else if($vaRequest['MTI'] == DIGITAL_BANK_REGISTER && $vaRequest['KT'] == TRX_MB_NOTIF_OS ){ // MTI = 009 && KT = 86 ody
			$vaResponse  = $this::KirimNotifOS($vaRequest['MSG']);
		}else if ($vaRequest['MTI'] == DIGITAL_BANK_INQUIRY) {   // MTI = 010
      $vaResponse  = $this::ProsesInquiryPayment($vaRequest['MSG']);
      # Non ISO 
    } else if ($vaRequest['MTI'] == DIGITAL_BANK && $vaRequest['KT'] == TRX_MB_NON_ISO) { // MTI = 002 && KT = 53 
      $vaResponse  = $this::ProsesNonISO($cRequest, $vaRequest['MSG']);
    } else if ($vaRequest['MTI'] == DIGITAL_BANK_REGISTER && $vaRequest['KT'] == TRX_MB_SAVING_EMAIL_FROM_CORE) { // MTI = 009 && KT = 61
      $vaResponse  = $this::SavingEmailAgen($vaRequest);
    } else if ($vaRequest['MTI'] == DIGITAL_BANK_REGISTER && $vaRequest['KT'] == TRX_MB_RESET_PIN_FROM_CORE) { // MTI = 009 && KT = 62
      $vaResponse  = $this::ResetPINRegister($vaRequest);
    } else if ($vaRequest['MTI'] == DIGITAL_BANK_REGISTER && $vaRequest['KT'] == TRX_GET_BANKCODE) { // MTI = 009 && KT = 99
      $vaResponse  = $this::GetBankCodeFromCBS($vaRequest);
    } else if ($vaRequest['MTI'] == TRX_REFKIRIMDANA && $vaRequest['KT'] == "01") { // MTI = 013
			$vaResponse  = $this::LoadKirimUangAPro($vaRequest);
		} else if ($vaRequest['MTI'] == TRX_REFKIRIMDANA && $vaRequest['KT'] == "02") { // MTI = 013
			$vaResponse  = $this::YukRefundTransaksi($vaRequest);
		} else if ($vaRequest['MTI'] == TRX_REFKIRIMDANA && $vaRequest['KT'] == "03") { // MTI = 013
			$vaResponse  = $this::RiwayatRefund($vaRequest);
		} else if ($vaRequest['MTI'] == TRX_WINPAY) { // MTI = 021
      # ody : untuk menutup VA sementara.
      $vaResponse = "{}";
      # ody : ini aslinya
      //$vaResponse = MBankingFunc::CreateRequestWinpay($vaRequest);
      
    } else if ($vaRequest['MTI'] == CBS_TRANSFER) { # TRUE: jika transaksi transfer dari CBS
			$cKodeAgen 			= isset($vaRequest["MITRA"]) ? $vaRequest["MITRA"] : "";
			$vaConfigAgen 	= GetKonfigurasiAgen($cKodeAgen);
			$cPermataSNAPTF = isset($vaConfigAgen["PermataSNAPTF"]) ? $vaConfigAgen["PermataSNAPTF"] : "";
			$cDanamonSNAPTF = isset($vaConfigAgen["DanamonSNAPTF"]) ? $vaConfigAgen["DanamonSNAPTF"] : "";
			if ($cPermataSNAPTF == "1") {
				$vaResponse = PermataSNAP::ReceiverHome($vaRequest);
			} else if ($cDanamonSNAPTF == "1") {
				$vaResponse = SNAPDanamon::receiver($vaRequest);
			} else {
				$vaResponse = array();
			}
		}else if($vaRequest['MTI'] == "999"){
			/*
			$vAr			= array(
				"MTI"	=> "999",
				"MSG"	=> array(
					"Seri"	=> "",
				)
			);

			$cMS = json_encode($vAr);
			$cM	= "cCode=" . $cMS;
			$cU	= $this->GetConfig('s');
			$vaH = array(
				'authorization: ' . hash('sha256',$cMS.SNow()),
				'identity: ' . $this->GetConfig('cicd'),
				'datetime: ' . SNow(),
				'Content-Type: application/x-www-form-urlencoded'
			);
			$cResponse  = SendHTTPPost($cU,$cM,'',false,$vaH);
			$cResponse  = ltrim($cResponse);
			$vaResponse	= json_decode($cResponse,1);
			$vaResponse = json_decode($vaResponse["data"],1);
			if($vaResponse['RC']!="00"){
				$cRettt = isset($vaResponse['MSG'])? $vaResponse['MSG'] : "Respon digital kosong.";
				objData::Insert("sms_inbox",array("SMSForm" => "TESTING DIGI", "Message" => $cResponse));
				$vaResponse = MBankingFunc::ISO2Array($cResponse);
				echo ($cResponse);
				return $vaResponse;
			}
			*/
		}else {
      self::$nResponseCode = MVC::HTTP_CLIENT_BAD_REQUEST;
      $vaResponse = array("MTI"=>"100","RC"=>"","MSG"=>"Request salah !!");
    }
    return $vaResponse;
  }
	
	private function KirimNotifOS($va){
		$cResponse   	= array();
		$cAppID 			= "";
		$cRestAPIKey	= "";
		$cURL					= "";
		$cPlayerID		= "";
		$cTitle				= "";
		$cMessage			= "";
		$cAgen				= "";
		
		$cAgen				= $va['Agen'];
		$cPlayerID		= $va['PlayerID'];
		$cTitle				= $va['Title'];
		$cMessage			= $va['Message'];
		insert2SMSInbox("0",$cAgen,$va,"mBankingOneSignal");
		
		$dbData = objData::Browse("agen","DataDigital","Kode = '$cAgen'");
		if ($dbRow = objData::GetRow($dbData)) {
			$vaData = json_decode(str_replace("\\","",$dbRow['DataDigital']),1);
			
			$cAppID 			= $vaData['AppID'];
			$cRestAPIKey	= $vaData['RestAPIKey'];
			$cURL					= $vaData['URL'];
		}
		 
		# setting untuk gambar
		# 'small_icon' => $smallIconBase64, // Base64 encoding untuk gambar kecil (small icon)
		$data = array(
      'app_id' => $cAppID,
      'include_player_ids' => array($cPlayerID),  
      'contents' => array('id' => $cMessage, 'en' => 'en'),
    );
    
		# setting untuk gambar
    # 'big-picture' = $imgUrl,
    # 'name' => "INTERNAL_CAMPAIGN_NAME",
    $fields = [ 
        'app_id' => $cAppID,
        'include_player_ids' => [$cPlayerID],
        'data' => $data,
        'headings' => ['id' => $cTitle, 'en' => $cTitle],
        'contents' => ['id' => $cMessage, 'en' => $cMessage]
    ];

    $headers = array(
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Basic ' . $cRestAPIKey,
    ) ;
		
		$ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $cURL);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
		
		if ($response === false) {
      # Kesalahan cURL
      $cEr = 'Error URL: ' . curl_error($ch);
			
			$cResponse = "AR#$cPlayerID#$cEr";
    } else {
      $responseData = json_decode($response, true);
        
      if (isset($responseData['errors'])) {
      	# Ada kesalahan dari OneSignal
        $errors = $responseData['errors'];
				$cResponse = "AR#$cPlayerID#$errors";
      } else {
        # Notifikasi berhasil dikirim
        $cResponse = "AR#$cPlayerID#SUKSES";
      }
    }
		
		$cResponse = "AR#$cPlayerID#SUKSES";
		return $cResponse;
	}
	
	private function GetBankCodeFromCBS(){
		$vaReturn   = array();
		$dbData = objData::Browse("bank_code","Kode,KodeBIFAST,BankPermataID,Nama","","","","Kode,KodeBIFAST,Nama");
		while ($dbRow = objData::GetRow($dbData)) {
			$vaReturn[] = $dbRow;
		}
			
		return $vaReturn;
	}
	
	private function GetLogDigitalB($vaRequest){
		# "CS#GetLog#cKodeAgen#Kode";
		$vaBody		= explode("#",$vaRequest['MSG']);
		$vaArray	= array();
		$cKode		= isset($vaBody['2'])? $vaBody['2'] : "";
    $dbData   = objData::Browse("token_digi_log", "*", "CFROM = '$cKode'","","","ID desc","15");
		
		while($dbRow = objData::GetRow($dbData)){      
			$vaArray[] = $dbRow;
		}
		
		$cResponse = json_encode($vaArray);
    return $cResponse;
	}

	#untuk kirim ulang kode token di cbs. rafii
	private function KirimUlangKodeFasilitas($vaRequest){
		# "CS#mBankingResendToken#$cNoHP#$cEmail#cKodeAgen#$metode";
		$vaBody		= explode("#",$vaRequest['MSG']);
		$lMetode	= ($vaBody[5]=="false")? false : true;
		$cFaktur  = "";
		$cHP      = $vaBody[2];
    $cAgen    = isset($vaRequest['AGEN']) ? $vaRequest['AGEN'] : $vaBody[4];
    $cEmail   = isset($vaRequest['EMAIL']) ? strtolower($vaRequest['EMAIL']) : ""; // UNTUK TUJUAN KIRIM
    $cKodeCIF = isset($vaRequest['CIF']) ? $vaRequest['CIF'] : "";
    $cWABL 		= isset($vaRequest['WABLAST']) ? $vaRequest['WABLAST'] : "0";
    $cOTP 		= isset($vaRequest['OTP']) ? $vaRequest['OTP'] : "";
		
		insert2SMSInbox($cHP,$cAgen,$vaRequest['MSG'],"mBanking");
    
		$dbData   = objData::Browse("agen_aktifasi", "HP", "HP='" . MBankingFunc::KodeNegara($cHP) . "' and Agen = '$cAgen' and SIMSerial = '' and Aktif = '0'");
		
		if($dbRow = objData::GetRow($dbData)){      
			$cKodeFasilitas     = rand(100000,999999);
      $cMD5KodeFasilitas  = encryptIt($cKodeFasilitas);
      $cPesan            = "Ini adalah kode fasilitas digital mu. Sampaikan ke CS agar divalidasi ya! $cKodeFasilitas, Jangan berikan kode ini kepada siapapun.";
			if($cWABL=="1"){
				$cKodeFasilitas     = $cOTP;
				$cMD5KodeFasilitas  = encryptIt($cKodeFasilitas);
				
				$va3 = array("mBankingKodeFasilitas"=>$cMD5KodeFasilitas,"DateTimeOTP"=>SNow());
				objData::Update("agen_aktifasi", $va3, "HP like '%".MBankingFunc::KodeNegara($cHP)."%' and Agen = '$cAgen'");
			}else if($lMetode){
				$vaBank = getDataAgen($cAgen);
				$dDT       = date("Y-m-d H:i:s");
				$cPesan  = rawurlencode($cPesan);
				$getMonthData = date("m");
				$vaInsert  = array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cEmail,"Subject"=>"Kode fasilitasmu dari Digital Bank Pada " . date("d") . " " . GetMonth($getMonthData) . " " . date("Y H:i:s"),"Message"=>$cPesan,"UserName"=>"mBanking");
				$cTRX      = objData::Insert("notifemail_sent",$vaInsert,false);

				$va3 = array("mBankingKodeFasilitas"=>$cMD5KodeFasilitas,"DateTimeOTP"=>SNow());
				objData::Update("agen_aktifasi", $va3, "HP like '%".MBankingFunc::KodeNegara($cHP)."%' and Agen = '$cAgen'");

				# kirim respon dan data ke cbs
				$cResponse = "AR#$cFaktur#SUKSES";
			}else{
				$va3 = array(
					"HP"=>MBankingFunc::KodeNegara($cHP),
					"Agen"=>$cAgen,
					"DateTime"=>date("Y-m-d H:i:s"),
					"mBankingKodeFasilitas"=>$cMD5KodeFasilitas,
					"KodeCIF"=>$cKodeCIF,
					"DateTimeOTP"=>SNow()
				);
				if($cEmail <> '') $va3 = array("HP"=>MBankingFunc::KodeNegara($cHP), "Agen"=>$cAgen,"DateTime"=>date("Y-m-d H:i:s"),"Email"=>$cEmail,"mBankingKodeFasilitas"=>$cMD5KodeFasilitas,"KodeCIF"=>$cKodeCIF,"DateTimeOTP"=>SNow());
				objData::Update("agen_aktifasi", $va3, "HP like '%".MBankingFunc::KodeNegara($cHP)."%' and Agen = '$cAgen'");
				
				# kirim sms ke user
        $lDataMasking   = true;
				
				if($cAgen == "A-000172" || $cAgen == "A-000153"){
					// Insert MHY
					$dDT       = date("Y-m-d H:i:s");
					objData::Insert("token_digi_log",array("CTO"=>$cHP,"CFROM"=>$cAgen,"CBD"=>$cPesan,"DT"=>$dDT),false);
					
					$va3 = array("mBankingKodeFasilitas"=>$cMD5KodeFasilitas,"DateTimeOTP"=>SNow());
					objData::Update("agen_aktifasi", $va3, "HP like '%".MBankingFunc::KodeNegara($cHP)."%' and Agen = '$cAgen'");
					
					$cResponse = "AR#$cFaktur#SUKSES";
				}else{
					$dbDataMasking  = objData::Browse("agen","UserZenziva,ApiKeyZenziva","Kode = '$cAgen'");
					if($dbRowMasking = objData::GetRow($dbDataMasking)){
						$cUserZenziva   = $dbRowMasking["UserZenziva"];
						$cApiKeyZenziva = $dbRowMasking["ApiKeyZenziva"];
						if(!empty($cUserZenziva) && !empty($cApiKeyZenziva)){
							$vaDataMasking  = array("HP"=>$cHP,"MSG"=>$cPesan,"OTP"=>"1","AGEN"=>$cAgen);
							$cSMSMasking    = SendSMSMasking($vaDataMasking);
							$vaSMSMasking   = json_decode($cSMSMasking,true);

							$va3 = array("mBankingKodeFasilitas"=>$cMD5KodeFasilitas,"DateTimeOTP"=>SNow());
							objData::Update("agen_aktifasi", $va3, "HP like '%".MBankingFunc::KodeNegara($cHP)."%' and Agen = '$cAgen'");

							# kirim respon dan data ke cbs
							$cResponse      = "AR#$cFaktur#" . $vaSMSMasking['status'] . "#" . $vaSMSMasking['text'];
						}else{
							$lDataMasking = false;
						}
					}else{
						$lDataMasking = false;
					}
				}
				
        if(!$lDataMasking){
          $cNoHP     = MBankingFunc::FormatNoHP($cHP); 
          $cNoModem  = "08563606500"; //$mBanking->GetNoModem($cNoHP);
          $vaField   = array("DateTime"=>time(), "SMSTo"=>$cNoHP,"IMEI"=>$cNoModem,"Message"=>$cPesan,"Priority"=>0);
          objData::Insert("sms_gateway_outbox", $vaField,false);#rafi 11-10-2023
					
					$va3 = array("mBankingKodeFasilitas"=>$cMD5KodeFasilitas,"DateTimeOTP"=>SNow());
					objData::Update("agen_aktifasi", $va3, "HP like '%".MBankingFunc::KodeNegara($cHP)."%' and Agen = '$cAgen'");
          # kirim respon dan data ke cbs
          $cResponse = "AR#SUKSES" ;
        }
			}
    }else{
			$cResponse = "AR#GAGAL#Gagal Kirim Token";
			
    }
		
    # insert response to log
    insert2SMSInbox($cHP,$cAgen,$cResponse,"mBanking");
    return $cResponse;
	}
  
  private function ValidasiKodeFasilitas($vaRequest){
    # "CS#$cNomor#mBankingCekKodeFasilitas#$cNoHP#$cToken#$cKodeAgen";
    $vaBody   = explode("#",$vaRequest['MSG']);
    $cFaktur  = $vaBody[1];
    $cHP      = trim(substr($vaBody[3],2));
    $cToken   = encryptIt($vaBody[4]);
    $cAgen    = isset($vaRequest['AGEN']) ? $vaRequest['AGEN'] : $vaBody[5];
    $db       = objData::Browse("agen_aktifasi","mBankingKodeFasilitas,Email,KodeCIF,HP,DateTimeOTP as DateTimeKirim","Jenis = 'M' and Agen = '$cAgen' and HP like '%$cHP%'");
		
		$cResponse = "AR#$cFaktur#GAGAL#Terjadi Gagal data pada pembukaan fasilitas sepertinya tidak ditemukan." ;
		insert2SMSInbox($vaBody[3],$cAgen,$vaRequest['MSG']. " " . $vaBody[4],"mBanking");
    if($rw = objData::GetRow($db)){
			
			// Cek kadaluarsa OTP
			$dTimeKadaluarsa = strtotime("{$rw['DateTimeKirim']} + 15 minutes");
			$dTimeKadaluarsa = date('Y-m-d H:i:s', $dTimeKadaluarsa);
			$dtNow = SNow();
			$cResponse = "AR#$cFaktur#GAGAL#Terjadi Gagal data pada pembukaan fasilitas data ditemukan [General Error]." ;
			if($dtNow > $dTimeKadaluarsa){
				$cResponse = "AR#$cFaktur#GAGAL#Token yang dimasukkan telah kadaluarsa, ulangi langkah pengiriman token lagi." ;
			}else{
				if($rw['mBankingKodeFasilitas'] == $cToken) {
					$cPIN           = rand(100000,999999);
					$cPIN_extracted = md5(md5($cPIN));
					$cEmail         = $rw['Email'];
					$cLogKodeCIF		= $rw['KodeCIF'];
					$cLogHP					= $rw['HP'];

					objData::Edit("agen_aktifasi", array("CS" => "1"), "Jenis = 'M' and Agen = '$cAgen' and HP like '%$cHP%'");

					# kirim sms dan email
					$vaBank         = getDataAgen($cAgen);
					$cMessage       = "Aktivasi Digital Bank anda pada ". $vaBank["BANK"] ." telah aktif. PIN anda ".  $cPIN;
					$cNoHP          = MBankingFunc::FormatNoHP($cHP); 
					$cNoModem       = "08563606500"; //$mBanking->GetNoModem($cNoHP);
					$cMessage       = "PIN anda: " . $cPIN_extracted;
					$vaField        = array("DateTime"=>time(), "SMSTo"=>$cNoHP,"IMEI"=>$cNoModem,"Message"=>$cMessage,"Priority"=>0);
					# kirim respon dan data ke cbs
					$cResponse      = "AR#$cFaktur#SUKSES#Akun berhasil diaktifkan.#$cPIN_extracted#$cPIN" ;

					# update: kebutuhan log
					/*
					$cLogMessage = "Aktivasi fasilitas digital untuk HP (%s)";
					$cLogMessage = sprintf($cLogMessage, $cLogHP);
					if (!empty(trim($cEmail))) {
						$cLogMessage .= " dan Email (%s)";
						$cLogMessage  = trim($cLogMessage);
						$cLogMessage  = sprintf($cLogMessage, $cEmail);
					}
					SwitchLog::SetKodeAgen($cAgen);
					SwitchLog::SetKodeCIF($cLogKodeCIF);
					SwitchLog::SetEvent(self::$cKodeLog . " : (CBS) Aktivasi Fasilitas Digital");
					SwitchLog::SetMessage($cLogMessage);
					SwitchLog::Save();
					*/
				} else {
					$cResponse = "AR#$cFaktur#GAGAL#Kode tidak cocok" ;
				}  
			}    
    }
    return $cResponse;
  }

  private function RegisterDigital_v2($vaRequest){
    $va       = explode("#",$vaRequest['MSG']);
    $cFaktur  = $va[1];
    $cHP      = $va[3];
    $cAgen    = isset($vaRequest['AGEN']) ? $vaRequest['AGEN'] : $va[4];
    $cEmail   = isset($vaRequest['EMAIL']) ? strtolower($vaRequest['EMAIL']) : "";
		$cEmailCBS	= isset($vaRequest['EMAILCBS']) ? strtolower($vaRequest['EMAILCBS']) : ""; // UNTUK SAVE di agen
    $cKodeCIF 	= isset($vaRequest['CIF']) ? $vaRequest['CIF'] : "";
    $lEmailFromCBS  = isset($vaRequest['EMAILFROMCBS']) ? $vaRequest['EMAILFROMCBS'] : false; # kirim Email menggunakan CBS (ODY)
    $lWA  					= isset($vaRequest['WABLAST']) ? $vaRequest['WABLAST'] : "0"; # kirim Email menggunakan CBS (ODY)
		# khusus WA
    $cOTP  					= isset($vaRequest['OTP']) ? $vaRequest['OTP'] : ""; # kirim Email menggunakan CBS (ODY)
    
		# insert request to log first
    insert2SMSInbox($cHP,$cAgen,json_encode($vaRequest),"mBanking");
		
		# UPDATE: kebutuhan log
		/*
		SwitchLog::SetKodeAgen($cAgen);
		SwitchLog::SetKodeCIF($cKodeCIF);
		SwitchLog::SetEvent(self::$cKodeLog . " : (CBS) Request Aktivasi Fasilitas Digital");
		$cLogMessage = "Request aktivasi fasilitas digital untuk HP (%s)";
		$cLogMessage = sprintf($cLogMessage, $cHP);
		if (!empty(trim($cEmail))) {
			$cLogMessage .= " dan Email (%s)";
			$cLogMessage  = trim($cLogMessage);
			$cLogMessage  = sprintf($cLogMessage, $cEmail);
		}
		SwitchLog::SetMessage($cLogMessage);
		SwitchLog::SetBodyForm(json_encode($vaRequest, JSON_UNESCAPED_SLASHES));
		SwitchLog::Save();
		*/
		
    $dbData   = objData::Browse("agen_aktifasi", "HP", "(HP='" . MBankingFunc::KodeNegara($cHP) . "' and Agen = '$cAgen') or (KodeCIF = '$cKodeCIF' and Agen = '$cAgen')");
    if($dbRow = objData::GetRow($dbData)){      
      $cResponse = "AR#$cFaktur#GAGAL# Nomor atau CIF sudah diaktivasi sebelumnya";
    }else{
      $cKodeFasilitas     = rand(100000,999999);
      $cMD5KodeFasilitas  = encryptIt($cKodeFasilitas);
      $cPesan            = "Ini adalah kode fasilitasmu. Sampaikan ke CS agar divalidasi ya! $cKodeFasilitas ";
      if($cEmail <> ""){
				$vaBank = getDataAgen($cAgen);
				$dDT       = date("Y-m-d H:i:s");
				$cPesan  = rawurlencode($cPesan);
				$getMonthData = date("m");
				$vaInsert  = array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cEmail,"Subject"=>"Kode fasilitasmu dari Digital Bank Pada " . date("d") . " " . GetMonth($getMonthData) . " " . date("Y H:i:s"),"Message"=>$cPesan,"UserName"=>"mBanking");
				$cTRX      = objData::Insert("notifemail_sent",$vaInsert,false);
				# kirim respon dan data ke cbs
				$cResponse = "AR#$cFaktur#SUKSES#E";
      }else if($lWA=="1"){
				//$cKodeFasilitas = $cOTP; 
      	$cMD5KodeFasilitas  = encryptIt($cOTP);
				insert2SMSInbox($cHP,$cAgen,json_encode($vaRequest). " " . $cOTP,"mBanking");
				
				$cResponse = "AR#$cFaktur#SUKSES#W" ;
			}else{
        # kirim sms ke user
        $lDataMasking   = true;
				
				if($cAgen == 'A-000172' || $cAgen == "A-000153"){
					// Insert MHY
					$dDT       = date("Y-m-d H:i:s");
					objData::Insert("token_digi_log",array("CTO"=>$cHP,"CFROM"=>$cAgen,"CBD"=>$cPesan,"DT"=>$dDT),false);
					
					$cResponse = "AR#$cFaktur#SUKSES#S";
				}else{
					$dbDataMasking  = objData::Browse("agen","UserZenziva,ApiKeyZenziva","Kode = '$cAgen'");
					if($dbRowMasking = objData::GetRow($dbDataMasking)){
						$cUserZenziva   = $dbRowMasking["UserZenziva"];
						$cApiKeyZenziva = $dbRowMasking["ApiKeyZenziva"];
						if(!empty($cUserZenziva) && !empty($cApiKeyZenziva)){
							$vaDataMasking  = array("HP"=>$cHP,"MSG"=>$cPesan,"OTP"=>"1","AGEN"=>$cAgen);
							$cSMSMasking    = SendSMSMasking($vaDataMasking);
							$vaSMSMasking   = json_decode($cSMSMasking,true);
							# kirim respon dan data ke cbs
							$cResponse      = "AR#$cFaktur#" . $vaSMSMasking['status'] . "#" . $vaSMSMasking['text'];
						}else{
							$lDataMasking = false;
						}
					}else{
						$lDataMasking = false;
					}
				}
				
        /*
				$dbDataMasking  = objData::Browse("agen","UserZenziva,ApiKeyZenziva","Kode = '$cAgen'");
        if($dbRowMasking = objData::GetRow($dbDataMasking)){
          $cUserZenziva   = $dbRowMasking["UserZenziva"];
          $cApiKeyZenziva = $dbRowMasking["ApiKeyZenziva"];
          if(!empty($cUserZenziva) && !empty($cApiKeyZenziva)){
            $vaDataMasking  = array("HP"=>$cHP,"MSG"=>$cPesan,"OTP"=>"1","AGEN"=>$cAgen);
            $cSMSMasking    = SendSMSMasking($vaDataMasking);
            $vaSMSMasking   = json_decode($cSMSMasking,true);
            # kirim respon dan data ke cbs
            $cResponse      = "AR#$cFaktur#" . $vaSMSMasking['status'] . "#" . $vaSMSMasking['text'];
          }else{
            $lDataMasking = false;
          }
        }else{
          $lDataMasking = false;
        }
				*/
        if(!$lDataMasking){
          $cNoHP     = MBankingFunc::FormatNoHP($cHP); 
          $cNoModem  = "08563606500"; //$mBanking->GetNoModem($cNoHP);
          $vaField   = array("DateTime"=>time(), "SMSTo"=>$cNoHP,"IMEI"=>$cNoModem,"Message"=>$cPesan,"Priority"=>0);
          objData::Insert("sms_gateway_outbox", $vaField,false);
          # kirim respon dan data ke cbs
          $cResponse = "AR#$cFaktur#SUKSES#SL" ;
        }
      }
      # simpan data ke agen untuk proses aktivasi selanjutnya
      $va3 = array("HP"=>MBankingFunc::KodeNegara($cHP), "Agen"=>$cAgen,"DateTime"=>date("Y-m-d H:i:s"),"Email"=>$cEmailCBS,"mBankingKodeFasilitas"=>$cMD5KodeFasilitas,"KodeCIF"=>$cKodeCIF,"DateTimeOTP"=>SNow());
			if($cEmail <> '') $va3 = array("HP"=>MBankingFunc::KodeNegara($cHP), "Agen"=>$cAgen,"DateTime"=>date("Y-m-d H:i:s"),"Email"=>$cEmail,"mBankingKodeFasilitas"=>$cMD5KodeFasilitas,"KodeCIF"=>$cKodeCIF,"DateTimeOTP"=>SNow());
			objData::Update("agen_aktifasi", $va3, "HP like '%".MBankingFunc::KodeNegara($cHP)."%' and Agen = '$cAgen'");
    }
    # insert response to log
    insert2SMSInbox($cHP,$cAgen,$cResponse,"mBanking");
		
		# UPDATE: kebutuhan log
		/*SwitchLog::SetBodyForm($cResponse);
		SwitchLog::Save();*/
		
    return $cResponse;
  } 

  //Penambahan pengecekan agen pada assist.pro
  private function DigitalBankCheckedAgen($cRequest, $vaRequest){
    #--- buat demo google tgl 22-09-2022
    //if(isset($vaRequest["MSG"]["HP"]) && ($vaRequest["MSG"]["HP"] == "+6281234567891" || $vaRequest["MSG"]["HP"] == "081234567891")){
      //self::$nResponseCode = MVC::HTTP_SUCCESS_OK;
      //$vaResponse[0] = join("|",array("99","Token demo akan dikirim via email",""));
    //}else{
    
    $vaResponse = array();
    $cAgen      = "";
    $cSimSerial = "";   
    $vaResponse = array("RC" => "02", "MSG" => "Nomor HP belum diaktivasi");
    if (isset($vaRequest["AGEN"])) {
      $cAgen = $vaRequest["AGEN"];
    } else if (isset($vaRequest["MSG"])) {
      # Jika request berupa ISO
      if (isset($vaRequest["MSG"]["DE061"])) {
        $cSimSerial = $vaRequest["MSG"]["DE061"];
        $cKodeBank  = substr($cSimSerial, 0, 4);
        $dbData   = objData::Browse("agen_fitur", "KodeAgen", "Kode='" . $cKodeBank . "'");
        if ($dbRow = objData::GetRow($dbData)) {
          $cAgen = $dbRow['KodeAgen'];
        }
        //Jika Agen Tidak Ditemukan
        $cResponse  = MBankingFunc::JSON2ISO(false, $vaRequest["MSG"]['DE003'], $vaRequest["MSG"]['DE004'], $vaRequest["MSG"]['DE012'], $vaRequest["MSG"]['DE013'], $vaRequest["MSG"]['DE037'], "02", $vaRequest["MSG"]['DE044'], $vaRequest["MSG"]['DE048'], $vaRequest["MSG"]['DE052'], $vaRequest["MSG"]['DE061'], $vaRequest["MSG"]['DE102'], $vaRequest["MSG"]['DE103']);
        $vaResponse = MBankingFunc::ISO2Array($cResponse);
        $vaResponse['RC']       = "00";
        $vaResponse['Message']  = "2735-Gagal melanjutkan, nomor anda belum diaktivasi";
        # Jika request berupa JSON SIMSerial atau SIMSERIAL
      } else if (isset($vaRequest["MSG"]["SIMSerial"]) || isset($vaRequest["MSG"]["SIMSERIAL"])) {
        $cSimSerial = isset($vaRequest["MSG"]["SIMSerial"]) ? $vaRequest["MSG"]["SIMSerial"] : $vaRequest["MSG"]["SIMSERIAL"] ;
        $cKodeBank  = substr($cSimSerial, 0, 4);
        $dbData   = objData::Browse("agen_fitur", "KodeAgen", "Kode='" . $cKodeBank . "'");
        if ($dbRow = objData::GetRow($dbData)) {
          $cAgen = $dbRow['KodeAgen'];
        }
        # Jika request berupa JSON Bank
      } else if (isset($vaRequest["MSG"]["Bank"])) {
        $cKodeBank  = $vaRequest["MSG"]["Bank"];
        $dbData   = objData::Browse("agen_fitur", "KodeAgen", "Kode='" . $cKodeBank . "'");
        if ($dbRow = objData::GetRow($dbData)) {
          $cAgen = $dbRow['KodeAgen'];
        }
        # Jika request berupa JSON Agen
      } else if (isset($vaRequest["MSG"]["AGEN"])) {
        $cAgen = $vaRequest["MSG"]["AGEN"];
      }
    }

    # untuk supplier
    # untuk trx supplier seperti winpay, lgsg tembak saja. kalau ditaruh diatas error karena pada requestnya tidak ada index MSG dan TRX
    if ($vaRequest['MTI'] == TRX_WINPAY) {
      return $this::DigitalBankHome($cRequest, $vaRequest); 
    }

    if ( $vaRequest['MTI'] == DIGITAL_BANK_REGISTER || ($vaRequest['MTI'] == DIGITAL_BANK_AMBILDATA && $vaRequest["MSG"]["TRX"] == TRX_GET_TRX) || $vaRequest["MSG"]["TRX"] == TRX_CHECK_LATEST_APP ) {
      $vaResponse  = $this::DigitalBankHome($cRequest, $vaRequest);
      if($vaRequest['MSG']['DE061'] == "007246b39b9fdd97d616") print_r($vaResponse);
    } else if ($cAgen <> "") { 
      $db = objData::Browse("Agen", "Kode", "Kode = '$cAgen'");
      if ($dbRowAgen = objData::GetRow($db)) $vaResponse  = $this::DigitalBankHome($cRequest, $vaRequest);
      
    }
    
    //} #--- buat demo google tgl 22-09-2022
    
    return $vaResponse;
  }
  //End pengecekan agen

  # fungsi ini digunakan untuk memeriksa data perangkat dan akun yang digunakan
  # ex: {"MTI":"004","MSG":{"TRX":"67","HP":"081804003032","SIMSerial":"005589620130003114738707","AGEN":"A-000172"}}
  private function PeriksaAkunPerangkat($vaBody){
    return $this::onPeriksaAkunPerangkat($vaBody['SIMSerial'], $vaBody['HP'], $vaBody['AGEN']);
  }

  private function onPeriksaAkunPerangkat(String $cSIMSerial = "", String $cHP = "", String $cAgen = ""){
    $vaArray = array("RC" => "02", "MSG" => "Nomor HP Belum Diaktivasi");
    if (!empty($cSIMSerial) && !empty($cHP) && !empty($cAgen)) {
      $vaAgen = getDataAgen($cAgen);
      if (empty($vaAgen)) { # jika Agen tidak terdaftar
        $vaArray["RC"]  = "16";
        $vaArray["MSG"] = "Agen Belum Terdaftar";
      } else {
        $cHP    = trim($cHP);
        $cNegaraHP = $cHP;
        if(substr($cHP,0,2) == "62") $cHP = "+" . $cHP ;
        $cHP    = str_replace("+62","0",trim($cHP)) ;
        $cField = "MAX(s.ID) ID, s.Nomor, s.Agen, s.SIMSerial, a.Aktif";
        $cWhere = "s.Nomor = '$cHP' AND s.Agen = '$cAgen'";
        $vaJoin = array("LEFT JOIN agen_aktifasi a ON a.SIMSerial = s.SIMSerial");
        $dbData = objData::Browse("agen_smsbanking s", $cField, $cWhere, $vaJoin);
        if ($dbRow = objData::GetRow($dbData)) {
          if ($dbRow["Aktif"] == "1") { # jika Nomor HP sudah aktif
            if ($dbRow["SIMSerial"] != $cSIMSerial) { # jika SIMSerial berbeda
              $vaArray["RC"]  = "XX";
              $vaArray["MSG"] = "Perangkat Belum Terdaftar";
            } else {
              $vaArray["RC"]  = "00";
              $vaArray["MSG"] = join("|", array($dbRow["ID"], $dbRow["Nomor"], $dbRow["Agen"], $dbRow["SIMSerial"]));
            }
          }
        }
        /*if ($vaArray["RC"] <> "00") {
          $cKeteranganNomor = GetKeterangan($cHP,"Nomor","agen_smsbanking","Nomor");
          if ($cKeteranganNomor == "") {
            $dbDataAktivasi = $objData->Browse("agen_aktifasi","HP,Email","HP = '$cNegaraHP' AND Agen = '$cAgen' AND Aktif = '0'");
            if ($dbRowAktivasi = $objData->GetRow($dbDataAktivasi)) {
              $vaArray["RC"]  = "00";
              $vaArray["MSG"] = "Fasilitas masih dalam proses aktivasi / daftar";
            }
          }
        }*/
      }
    } else {
      $vaArray["RC"]  = "19";
      $vaArray["MSG"] = "Data Tidak Boleh Kosong";
    }
    return $vaArray;
  }

  private function GetTokenDigital($va){
    $vaResponse = array();
    $n        = 0;
    $cAgen    = $va['AGEN'];
    $dTglAwal   = Date2String($va['TGLAWAL']);
    $dTglAkhir  = Date2String($va['TGLAKHIR']);
    $db = objData::Browse("agen_aktifasi", "DateTime,HP,mBankingToken", "Jenis = 'M' and Agen = '$cAgen' and (left(DateTime,10) >= '$dTglAwal' and left(DateTime,10) <= '$dTglAkhir')");
    while ($rw = objData::GetRow($db)) {
      $cHP  = MBankingFunc::FormatNoHP($rw['HP']);
      $dTgl = String2Date(substr($rw['DateTime'], 0, 10));
      $vaResponse[++$n] = join("|", array($dTgl, $cHP, $rw['mBankingToken']));
    }
    return $vaResponse;
  }
  
  private function GetInquiryHistoryCBS($cRequest="",$cKodeAgen=""){
    $vaResp = array("isRekeningAvailable"=>false, "biayaadmin"=>0, "tagihan"=>"", "feeAssist"=>0, "RC"=>"", "message"=>"");
    
    $vaBank             = getDataAgen($cKodeAgen);
    $cURL               = $vaBank["URL"];
    $vaMSG["TRX"]       = TRX_GET_INQ_HISTORY_CBS;
    $vaMSG["Rekening"]  = $cRequest['DE102'];
    $vaMSG["Transaksi"] = $cRequest['DE048'];
    $vaRequest["MTI"]   = DIGITAL_BANK_AMBILDATA;
    $vaRequest["MSG"]   = $vaMSG;
    $cRequest           = json_encode($vaRequest,JSON_UNESCAPED_SLASHES);
    $cRequest           = "cCode=" . $cRequest;
    $cResponse          = SendHTTPPost($cURL, $cRequest);
    $vaResponse         = json_decode($cResponse, 1);
    
    $vaResp["RC"]         = $vaResponse["RC"];
    $vaResp["biayaadmin"] = $vaResponse["MSG"]["biayaadmin"];
    $vaResp["message"]    = "";
    if($vaResponse["RC"] == "00"){
      $vaResp["isRekeningAvailable"]  = true;
      $vaResp["tagihan"]              = $vaResponse["MSG"]["tagihan"];
    }else{
      $vaResp["message"] = $vaResponse["MSG"]["message"];
    }
    
    return $vaResp;
  }
  
  private function ProsesInquiryPayment($cRequest){
		// response default: timeout  
    $cResponse  = MBankingFunc::JSON2ISO(false, "231041", "000000000000", date("hi"), date("dm"), "000000000000", "11", "0", "0", "0000000000000000000000000000000000000000000000000000000000000000", "000000000000000000000000", "0", "0");
    
		/*
		$lTutupLayanan = true;
		# Jika jam kerja
		$hariIni 	= date('w');
		$dNw 			= date('H:i:s');
		
		if($hariIni >= 1 && $hariIni <= 5){
			if(strtotime($dNw) >= strtotime('09:00:00') && strtotime($dNw) <= strtotime('15:58:59')){
				$lTutupLayanan	= false;
			}
		}
		
		if($lTutupLayanan){
			$cPesser		= "Halo pengguna!, Saat ini transaksi diluar hari dan jam kerja (Senin s/d Jumat, 09.00-16.00 WIB) tidak dapat diproses. Mohon maaf atas ketidaknyamanan yang dialami. Terima kasih atas pengertian dan dukungan Anda.";
			$cResponse 	= MBankingFunc::JSON2ISO(false,"231041","000000000000",date("hi"),date("dm"),"000000000000","XX","0",$cPesser,"0000000000000000000000000000000000000000000000000000000000000000","000000000000000000000000","0","0");
			$vaResponse = MBankingFunc::ISO2Array($cResponse);
			return $vaResponse;
		}
		*/
		
		# UPD: tutup layanan PPOB jika akhir tahun
		date_default_timezone_set('Asia/Jakarta');
		$nCurrentTime = time();
		
		// [24JAM] TutupPPOB (penutupan spesial berbasis timestamp) DINONAKTIFKAN — layanan 24 jam penuh
		// if ($nCurrentTime >= TutupPPOB::AKHIRTAHUN_FROMTIME && $nCurrentTime <= TutupPPOB::AKHIRTAHUN_TOTIME) {
		// 	$lTutupPPOBAkhirTahun = true;
		// } else {
		// 	$lTutupPPOBAkhirTahun = false;
		// }
		$lTutupPPOBAkhirTahun = false; // [24JAM] selalu false = tidak pernah tutup akhir tahun
		$cKodeAgen = MBankingFunc::GetKodeAgenMobile($cRequest['DE061']);
		# start: lusi
		# if (isset($_SERVER["REMOTE_ADDR"]) && $_SERVER["REMOTE_ADDR"] === "10.11.0.50") {
		# 		$cKodeAgen = "A-000265" ;
		# }
		# end: lusi
		$isTrxKirimUang = (strpos(json_encode($cRequest),"TFDANA") > 0 || strpos(json_encode($cRequest),"BIFAST") > 0 || strpos(json_encode($cRequest),"RTGS") > 0 || strpos(json_encode($cRequest),"LLG") > 0);
		
		if ($lTutupPPOBAkhirTahun) {
			$cResponse = MBankingFunc::JSON2ISO(false,"231041","000000000000",date("hi"),date("dm"),"000000000000",TutupPPOB::AKHIRTAHUN_RCODE,"0",TutupPPOB::AKHIRTAHUN_MSG_THNBARU,"0000000000000000000000000000000000000000000000000000000000000000","000000000000000000000000","0","0");
		} else {
			if(strpos(json_encode($cRequest),"WAKABBKS") > 0){
				$cResponse = ProsesTektaya::CreateRequestTektaya($cRequest);
			}else if(strpos(json_encode($cRequest),"TFDANA") > 0 || strpos(json_encode($cRequest),"BIFAST") > 0 || strpos(json_encode($cRequest),"RTGS") > 0 || strpos(json_encode($cRequest),"LLG") > 0){
				//$cKodeAgen    = MBankingFunc::GetKodeAgenMobile($cRequest['DE061']);

				# periksa apakah layanan SNAP TF Bank Permata
				$vaConfigAgen 	= GetKonfigurasiAgen($cKodeAgen);
				$cPermataSNAPTF = isset($vaConfigAgen["PermataSNAPTF"]) ? $vaConfigAgen["PermataSNAPTF"] : "";
				$cDanamonSNAPTF = isset($vaConfigAgen["DanamonSNAPTF"]) ? $vaConfigAgen["DanamonSNAPTF"] : "";
				if ($cPermataSNAPTF == "9") {
					$cDE004     = str_pad(0,10,"0",STR_PAD_LEFT) . "00";
					$cMessage		= "Mohon maaf, layanan ini sudah tidak lagi tersedia";
					$cResponse	= MBankingFunc::JSON2ISO(false,"231041",$cDE004,date("hi"),date("md"),"000000000000","11","0",$cMessage,"0000000000000000000000000000000000000000000000000000000000000000","000000000000000000000000", $cDE102, "0");
				} elseif ($cPermataSNAPTF == "1") {
					//$cDE004     = str_pad(0,10,"0",STR_PAD_LEFT) . "00";
					//$cMessage		= "Mohon maaf, layanan ini sudah tidak lagi tersedia";
					//$cResponse	= MBankingFunc::JSON2ISO(false,"231041",$cDE004,date("hi"),date("md"),"000000000000","11","0",$cMessage,"0000000000000000000000000000000000000000000000000000000000000000","000000000000000000000000", $cDE102, "0");
					$cDE102       = str_pad(0,10,"0",STR_PAD_LEFT) . "00";
					$vaInqHistory = $this::GetInquiryHistoryCBS($cRequest,$cKodeAgen);
					# cek apakah respon 00
					if ($vaInqHistory['RC'] == "00" || $vaInqHistory['RC'] == "19") {
						# cek apakah rekening ditemukan di tabel histori CBS dan tidak perlu membuat request ke permata
						if ($vaInqHistory['isRekeningAvailable']) {
							$cDE004     = str_pad($vaInqHistory["biayaadmin"],10,"0",STR_PAD_LEFT) . "00";
							$cDE102     = str_pad($vaInqHistory["feeAssist"],10,"0",STR_PAD_LEFT) . "00";
							$cResponse  = MBankingFunc::JSON2ISO(false,"231041",$cDE004,date("hi"),date("md"),"000000000000",$vaInqHistory["RC"],"0",$vaInqHistory["tagihan"],"0000000000000000000000000000000000000000000000000000000000000000","000000000000000000000000", $cDE102, "0");
						} else {
							# jika tidak ada data, buat request ke permata
							$cResponse  		= MBankingFunc::JSON2ISO(false, "231041", "000000000000", date("hi"), date("dm"), "000000000000", "11", "0", "", "0000000000000000000000000000000000000000000000000000000000000000", "000000000000000000000000", $cDE102, "0");
							$nAdminPermata 	= $vaInqHistory["biayaadmin"];
							$nFeeAssist    	= $vaInqHistory["feeAssist"];
							$PERMATASNAP_INQ = PermataSNAP::GetTFINQ($cKodeAgen, $cRequest, $nAdminPermata, $nFeeAssist);
							if (!empty($PERMATASNAP_INQ)) {
								$cResponse = $PERMATASNAP_INQ;
							}
						}
					} else {
						# buat menjadi transaksi gagal dan kirim messagenya
						$cDE004     = str_pad($vaInqHistory["biayaadmin"],10,"0",STR_PAD_LEFT) . "00";
						$cRC        = !empty($vaInqHistory["RC"]) ? $vaInqHistory["RC"] : "11";
						$cResponse  = MBankingFunc::JSON2ISO(false,"231041",$cDE004,date("hi"),date("md"),"000000000000",$cRC,"0",$vaInqHistory["message"],"0000000000000000000000000000000000000000000000000000000000000000","000000000000000000000000", $cDE102, "0");
					}
				} elseif ($cDanamonSNAPTF == "1") {
					/*$cDE004     = str_pad(0,10,"0",STR_PAD_LEFT) . "00";
					$cMessage		= "Mohon maaf, layanan ini sudah tidak lagi tersedia";
					$cResponse	= MBankingFunc::JSON2ISO(false,"231041",$cDE004,date("hi"),date("md"),"000000000000","11","0",$cMessage,"0000000000000000000000000000000000000000000000000000000000000000","000000000000000000000000", $cDE102, "0");*/
					
					$cDE102 = str_pad(0,10,"0",STR_PAD_LEFT) . "00";
					$vaInqHistory = $this::GetInquiryHistoryCBS($cRequest,$cKodeAgen);
					if ($vaInqHistory['RC'] == "00" || $vaInqHistory['RC'] == "19") {
						if ($vaInqHistory['isRekeningAvailable']) {
							$cDE004 = str_pad($vaInqHistory["biayaadmin"],10,"0",STR_PAD_LEFT) . "00";
							$cDE102	= str_pad($vaInqHistory["feeAssist"],10,"0",STR_PAD_LEFT) . "00";
							$cResponse = MBankingFunc::JSON2ISO(false,"231041",$cDE004,date("hi"),date("md"),"000000000000",$vaInqHistory["RC"],"0",$vaInqHistory["tagihan"],"0000000000000000000000000000000000000000000000000000000000000000","000000000000000000000000", $cDE102, "0");
						} else {
							$cResponseError	= MBankingFunc::JSON2ISO(false,"231041","000000000000",date("hi"),date("dm"),"000000000000","XT","0","Maintenance","0000000000000000000000000000000000000000000000000000000000000000","000000000000000000000000","0","0");
							$nAdmAmount 		= $vaInqHistory["biayaadmin"];
							$nFeeAmount 		= $vaInqHistory["feeAssist"];
							
							# start: lusi
							# flag untuk track
							$cTrackNew = false ;
							
							# whitelist produk yang boleh dibelokkan
							$allowedTransactions = [
    							"INQTFDANA",
									"INQBIFAST"
							];

							# parse de48 agar dapat jenis produknya
							$cISODE48 = $cRequest["DE048"] ?? "";
							$isAllowed = false;
							foreach ($allowedTransactions as $trx) {
									if (strpos($cISODE48, $trx) !== false) {
											$isAllowed = true;
											break;
									}
							}
							
							# cek apakah produk boleh dibelokkan, jika iya maka cek setting track dulu
							if ($isAllowed) {
									# setting ini ada di table agen fitur
									$cTrackConfig = $vaConfigAgen["DanamonTFDanaTrack"] ?? "";
									# jika track 2 maka belokkan ke jalur baru
									if ($cTrackConfig == 2) {
											$cTrackNew = true;
									}
							}
							
							if ($cTrackNew === true) {
									# jika jalur baru
									$cResponseDanamon = SNAPDanamonGw::inquiry($cKodeAgen, $cRequest, $nAdmAmount, $nFeeAmount, $vaConfigAgen["KodeBankTFOB"] ?? "");
							} else { 
									# default ke jalur lama
									$cResponseDanamon = SNAPDanamon::inquiry($cKodeAgen, $cRequest, $nAdmAmount, $nFeeAmount);
							}
							# end: lusi
							
							/*
							if ($cKodeAgen === "A-000265") { # start: lusi
								$cResponseDanamon = SNAPDanamonGw::inquiry($cKodeAgen, $cRequest, $nAdmAmount, $nFeeAmount, $vaConfigAgen["KodeBankTFOB"] ?? "");
							} else { # end: lusi
								$cResponseDanamon = SNAPDanamon::inquiry($cKodeAgen,$cRequest,$nAdmAmount,$nFeeAmount);
							}
							*/

							if (!empty($cResponseDanamon)) $cResponse = $cResponseDanamon;
						}
					} else {
						$cDE004	= str_pad($vaInqHistory["biayaadmin"],10,"0",STR_PAD_LEFT) . "00";
						$cRC    = !empty($vaInqHistory["RC"]) ? $vaInqHistory["RC"] : "11";
						$cResponse = MBankingFunc::JSON2ISO(false,"231041",$cDE004,date("hi"),date("md"),"000000000000",$cRC,"0",$vaInqHistory["message"],"0000000000000000000000000000000000000000000000000000000000000000","000000000000000000000000", $cDE102, "0");
					}
				} else {
					$vaInqHistory = $this::GetInquiryHistoryCBS($cRequest,$cKodeAgen);

					# cek apakah respon 00
					if($vaInqHistory['RC'] == "00" || $vaInqHistory['RC'] == "19"){
						# cek apakah rekening ditemukan di tabel histori CBS dan tidak perlu membuat request ke permata
						if($vaInqHistory['isRekeningAvailable']){
							$cDE004     = str_pad($vaInqHistory["biayaadmin"],10,"0",STR_PAD_LEFT) . "00";
							$cResponse  = MBankingFunc::JSON2ISO(false,"231041",$cDE004,date("His"),date("md"),"000000000000",$vaInqHistory["RC"],"0",$vaInqHistory["tagihan"],"0000000000000000000000000000000000000000000000000000000000000000","00000000000000000000","000000000000","000000000000");
						}else{
							# jika tidak ada data, buat request ke permata
							$cResponse = prosespermata::CreateRequestPermata($cRequest,$cKodeAgen,$vaInqHistory["biayaadmin"]);
						}
					}else{
						# buat menjadi transaksi gagal dan kirim messagenya
						$cDE004     = str_pad($vaInqHistory["biayaadmin"],10,"0",STR_PAD_LEFT) . "00";
						$cRC        = !empty($vaInqHistory["RC"]) ? $vaInqHistory["RC"] : "11";
						$cResponse  = MBankingFunc::JSON2ISO(false,"231041",$cDE004,date("His"),date("md"),"000000000000",$cRC,"0",$vaInqHistory["message"],"0000000000000000000000000000000000000000000000000000000000000000","00000000000000000000","000000000000","000000000000");
					}
				}
			}else{
				# TODO: mengirimkan data transaksi ke server baru
				$vaDispatcher = FastpayDispatcher::process($cRequest);
				
				if ($vaDispatcher['status'] == 'fallback') {
					// sending request to them
					$cRequestMsg = MBankingFunc::CreateRequestFastpay($cRequest);

					// [JAM-BUKA] maintenance Fastpay: tutup 23:00-23:59 dan 00:00-04:59 WIB (buka mulai 05:00)
					// Untuk mengubah jam, edit dua baris if di bawah ini:
					$nTime = date("H:i");
					if($nTime >= "23:00" && $nTime <= "23:59") $cRequestMsg = ""; // [JAM-TUTUP] mulai cut-off
					if($nTime >= "00:00" && $nTime <= "04:59") $cRequestMsg = ""; // [JAM-BUKA]  akhir cut-off → ubah "04:59" sesuai kebutuhan

					if ($cRequestMsg != "") {
						# menambahkan jenis transaksinya saat melakukan request
						$cJenisTrx 	= "";
						$cDE048 		= isset($cRequest['DE048']) ? $cRequest['DE048'] : "";
						if (!empty($cDE048)) {
							if (strpos($cDE048,"FINANCE") > 0) {
								$cJenisTrx = "INQFINANCE";
							} else if (strpos($cDE048, "INQEMONEY") !== false) {
								$cJenisTrx = "INQEMONEY";
							} else if (strpos($cDE048, "INQSPEEDY") !== false) {
								$cJenisTrx = "INQSPEEDY";
							}
						}

						$cNomor     = GetLastFakturPulsaPenjualan("SL", true, true);
						SendMessage("fastpay", "json", "Z", $cRequestMsg, $cNomor, $cJenisTrx);  // send request to supplier

						// penambahan margin (fanani) ex: 0501*1001*INQFINANCE~~INSTANSI | 0201*1001*INQTELKOM~~FP_TELEPON | 601*1001*INQTFDANA~~BLTRFAG
						$nMargin   = 0;
						$vaDE048   = split("*", $cRequest['DE048']);
						$vaTrx     = split("~~", $vaDE048[2]);
						$cKodeAgen = MBankingFunc::GetKodeAgenMobile($cRequest['DE061']);
						$cKodeMrg  = $vaTrx[0];
						if (isset($vaTrx[1])) {
							switch ($vaTrx[1]) {
								case "FP_PLNPASCA":
									$cKodeMrg = "PAYPLN";
									break;
								case "FP_TELEPON":
									$cKodeMrg = "PAYTELKOM";
									break;
								case "ASRBPJSKS":
									$cKodeMrg = "PAYBPJS";
									break;
								case "FP_INTERNET":
									$cKodeMrg = "PAY" . substr($cKodeMrg, 3);
									break;
							}
						}
						$vaHrg    = MBankingFunc::GetHargaPPOB($cKodeAgen, $cRequest['DE004'], $cKodeMrg);
						$nMargin  = $vaHrg["Margin"];
						$nPPN			= isset($vaHrg["PPN"]) ? $vaHrg["PPN"] : 0; # fanani: penambahan PPN untuk beberapa produk

						// waiting for the response from supplier
						$nMaxWait   = 60;
						$nRetry     = 0;
						$nTimeStart = time();
						$nTryAgain  = 1;
						while (++$nRetry <= $nMaxWait && $nTryAgain == 1) {
							$dbData = objData::Browse("xmpp_chatlog", "*", "Protocol = 'Z' and Nomor = '$cNomor' and Jenis = 'I'", "", "", "Protocol,ID");
							while ($dbRow = objData::GetRow($dbData)) {
								$cResponse = MBankingFunc::ParsingResponseInquiry($dbRow,0,false,$cJenisTrx,$nPPN);
								$nTryAgain = 0;
							}
							if ($nRetry <= $nMaxWait) sleep(1);
						}
						// $cResponse  = $there->JSON2ISO(false,"231041", "000000000000", date("hi"), date("dm"), "000000000000","77", "0","0 ".$cNomor, "0000000000000000000000000000000000000000000000000000000000000000", "000000000000000000000000", "0", "0") 
					} else {
						// bikin respon error "kode produk tidak ditemukan"
						$cResponse  = MBankingFunc::JSON2ISO(false, "231041", "000000000000", date("hi"), date("dm"), "000000000000", "09", "0", "0", "0000000000000000000000000000000000000000000000000000000000000000", "000000000000000000000000", "0", "0");
					}
				} else {
					# Lusi: untuk handle response baru
					# default rc 00 / berhasil
					$cRCfp = "00" ;
					$cMSGfp = "" ;
					
					# kita buat kondisi khusus dimana $vaDispatcher['tagihan'] berbentuk array
					if (isset($vaDispatcher['tagihan']) && is_array($vaDispatcher['tagihan'])) {
							$vaTagihan = $vaDispatcher['tagihan'] ?? [] ;
						
							# Jika bukan 00 maka RC di set XT biar muncul message
							if ($vaTagihan["RC"] != "00") $cRCfp = "XT" ;
							
							# DE48 masik sama
							$cMSGfp = $vaTagihan["Message"] ?? "" ;
					} else {
							# pasti string
							$cMSGfp = $vaDispatcher['tagihan'] ?? "" ;
					}
					
					$cResponse = MBankingFunc::JSON2ISO(false,"231041","000000000000", date("His"),date("md"),"000000000000",$cRCfp,"0",$cMSGfp,"0000000000000000000000000000000000000000000000000000000000000000","00000000000000000000","000000000000","000000000000") ;
					# END Lusi
					
					
					# Original Code:
					# $cResponse = MBankingFunc::JSON2ISO(false,"231041","000000000000", date("His"),date("md"),"000000000000","00","0",$vaDispatcher['tagihan'],"0000000000000000000000000000000000000000000000000000000000000000","00000000000000000000","000000000000","000000000000") ;
				}
			}
		}
    //echo "respone 3 " . $cResponse;
    $vaResponse = MBankingFunc::ISO2Array($cResponse);
    return $vaResponse;
  }

  function RegisterDigital($vaRequest){
    $va       = explode("#", $vaRequest['MSG']);
    $cFaktur  = $va[1];
    $cHP      = $va[3];
    $cAgen    = isset($vaRequest['AGEN']) ? $vaRequest['AGEN'] : $va[4];
    $cEmail   = isset($vaRequest['EMAIL']) ? $vaRequest['EMAIL'] : "";
    $lSendMSG = isset($vaRequest['SENDMSG']) ? $vaRequest['SENDMSG'] : true; # kirim pemberitahuan via CBS (fanani)
    $lSMSMasking = isset($vaRequest['SMS_MASKING']) ? $vaRequest['SMS_MASKING'] : false; # kirim SMS menggunakan SMS masking (fanani)
    // Penambahan terkait custom pemberitahuan (fanani)
    $cIsiPesan = isset($vaRequest['ISI_PESAN']) ? $vaRequest['ISI_PESAN'] : "";

    // insert request to log first
    insert2SMSInbox($cHP, $cAgen, $vaRequest['MSG'], "mBanking");

    $dbData   = objData::Browse("agen_aktifasi", "HP", "HP='" . $mBanking->KodeNegara($cHP) . "' and Agen = '$cAgen' and mBankingToken <> ''");
    if ($dbRow = objData::GetRow($dbData)) {
      $cResponse = "AR#$cFaktur#GAGAL#Nomor sudah diaktivasi sebelumnya";
    } else {
      $cPIN      = rand(100000, 999999);
      // kirim respon dan data ke cbs
      $cEncryptedPIN = "";//md5(md5($cPIN));
      // Penambahan terkait custom pemberitahuan (fanani)
      $cMessage = "";
      if ($cIsiPesan <> "") {
        $cIsiPesan = str_replace("[cPIN]", $cPIN, $cIsiPesan);
        $cMessage  = $cIsiPesan;
      }
      if ($lSMSMasking) {
        $cNoHP = MBankingFunc::FormatNoHP($cHP);
        $vaBank = getDataAgen($cAgen);
        $cMessage = "Aktivasi mBanking anda pada " . $vaBank["BANK"] . " telah aktif. PIN anda " . $cPIN;
        $vaDataMasking = array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "1", "AGEN" => $cAgen);
        $cSMSMasking = SendSMSMasking($vaDataMasking);
        $vaSMSMasking = json_decode($cSMSMasking, true);
        $cResponse = "AR#$cFaktur#" . $vaSMSMasking['status'] . "#" . $vaSMSMasking['text'] . "#$cEncryptedPIN#$cPIN";
      } else {
        if ($cEmail <> "") {
          $vaBank     = getDataAgen($cAgen);
          // Penambahan terkait custom pemberitahuan (fanani)
          if ($cMessage == "") $cMessage = "Aktivasi mBanking anda pada " . $vaBank["BANK"] . " telah aktif. PIN anda " . $cPIN;
          if ($lSendMSG) {
            $dDT       = date("Y-m-d H:i:s");
            $cMessage  = rawurlencode($cMessage);
            $vaInsert  = array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cEmail,"Subject"=>"Kode Aktivasi Digital","Message"=>$cMessage,"UserName"=>"mBanking");
            $cTRX      = objData::Insert("notifemail_sent",$vaInsert,false);
          }
          $cResponse = "AR#$cFaktur#SUKSES#Tunggu Email pemberitahuan selanjutnya#$cEncryptedPIN#$cPIN";
        } else {
          // kirim sms ke user
          $cNoHP     = MBankingFunc::FormatNoHP($cHP);
          $cNoModem  = "08563606500"; //$mBanking->GetNoModem($cNoHP);
          // Penambahan terkait custom pemberitahuan (fanani)
          if ($cMessage == "") $cMessage = "PIN anda: " . $cPIN;
          if ($lSendMSG) { # kirim pemberitahuan via CBS jika false (fanani)
            $vaField   = array("DateTime" => time(), "SMSTo" => $cNoHP, "IMEI" => $cNoModem, "Message" => $cMessage, "Priority" => 0);
            objData::Insert("sms_gateway_outbox", $vaField,false);
          }
          $cResponse = "AR#$cFaktur#SUKSES#Tunggu SMS pemberitahuan selanjutnya#$cEncryptedPIN#$cPIN";
        }
      }
      if (!$lSendMSG) $cResponse .= "#$cMessage";
      // simpan data ke agen untuk proses aktivasi selanjutnya
      $va3 = array("HP" => MBankingFunc::KodeNegara($cHP), "Agen" => $cAgen, "DateTime" => date("Y-m-d H:i:s"), "Email" => $cEmail);
      objData::Update("agen_aktifasi", $va3, "HP = '".MBankingFunc::KodeNegara($cHP)."'");
    }
    // insert response to log 
    insert2SMSInbox($cHP, $cAgen, $cResponse, "mBanking");

    return $cResponse;
  }

  function Unregister($vaRequest){
		//CS#$cNomor#mBankingDEL#$cNoHP#$cKodeAgen
    $va       = explode("#", $vaRequest['MSG']);
    $cFaktur  = $va[1];
    $cHP      = $va[3];
    $cAgen    = isset($vaRequest['AGEN']) ? $vaRequest['AGEN'] : $va[4];
    $cEmail   = isset($vaRequest['EMAIL']) ? $vaRequest['EMAIL'] : "";
    $lSendMSG = isset($vaRequest['SENDMSG']) ? $vaRequest['SENDMSG'] : true; # kirim pemberitahuan via CBS (fanani)
    $lSMSMasking = isset($vaRequest['SMS_MASKING']) ? $vaRequest['SMS_MASKING'] : false; # kirim SMS menggunakan SMS masking (fanani)
    // Penambahan terkait custom pemberitahuan (fanani)
    $cIsiPesan = isset($vaRequest['ISI_PESAN']) ? $vaRequest['ISI_PESAN'] : "";
		$cLogKodeCIF = "";

    // insert to log first
    insert2SMSInbox($cHP, $cAgen, $vaRequest['MSG'], "mBanking");

    $dbData   = objData::Browse("agen_aktifasi", "HP,KodeCIF", "HP='" . MBankingFunc::KodeNegara($cHP) . "' and Agen = '$cAgen'");
    if ($dbRow = objData::GetRow($dbData)) {
      objData::Delete("agen_smsbanking", "Nomor='" . $cHP . "' and Agen = '$cAgen'");
      objData::Delete("agen_aktifasi", "HP='" . MBankingFunc::KodeNegara($cHP) . "' and Agen = '$cAgen'");
			
			$cLogKodeCIF = $dbRow["KodeCIF"];
    }
    $cResponse = "AR#$cFaktur#SUKSES#User telah dinonaktifkan";

    // kirim sms ke user jika email kosong
    $cNoHP     = MBankingFunc::FormatNoHP($cHP);
    $cNoModem  = "08563606500"; //$mBanking->GetNoModem($cNoHP);
    // Penambahan terkait custom pemberitahuan (fanani)
    $cMessage  = ($cIsiPesan <> "") ? $cIsiPesan : "Akun anda telah dinonaktifkan. Terimakasih telah menggunakan layanan kami";
    if ($lSMSMasking) {
			
			if($cAgen == 'A-000172' || $cAgen == "A-000153"){
				// Insert MHY
				$dDT       = date("Y-m-d H:i:s");
				objData::Insert("token_digi_log",array("CTO"=>$cNoHP,"CFROM"=>$cAgen,"CBD"=>$cMessage,"DT"=>$dDT),false);
				
    		$cResponse = "AR#$cFaktur#SUKSES#User telah dinonaktifkan";
			}else{
				$vaBank = getDataAgen($cAgen);
				$vaDataMasking = array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "0", "AGEN" => $cAgen, "RES_MSG" => "User telah dinonaktifkan");
				$cSMSMasking = SendSMSMasking($vaDataMasking);
				$vaSMSMasking = json_decode($cSMSMasking, true);
				$cResponse = "AR#$cFaktur#" . $vaSMSMasking['status'] . "#" . $vaSMSMasking['text'];
			}
			/*
      $vaBank = getDataAgen($cAgen);
      $vaDataMasking = array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "0", "AGEN" => $cAgen, "RES_MSG" => "User telah dinonaktifkan");
      $cSMSMasking = SendSMSMasking($vaDataMasking);
      $vaSMSMasking = json_decode($cSMSMasking, true);
      $cResponse = "AR#$cFaktur#" . $vaSMSMasking['status'] . "#" . $vaSMSMasking['text'];
			*/
    } else {
      if ($cEmail <> '') {
        $vaBank     = getDataAgen($cAgen);
        if ($lSendMSG) {
          $dDT       = date("Y-m-d H:i:s");
          $cMessage  = rawurlencode($cMessage);
          $vaInsert  = array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cEmail,"Subject"=>"Nonaktifkan mBanking","Message"=>$cMessage,"UserName"=>"mBanking");
          $cTRX      = objData::Insert("notifemail_sent",$vaInsert,false);
        }
      } else {
        if ($lSendMSG) { # kirim pemberitahuan via CBS jika false (fanani)
          $vaField   = array("DateTime" => time(), "SMSTo" => $cNoHP, "IMEI" => $cNoModem, "Message" => $cMessage, "Priority" => 0);
          objData::Insert("sms_gateway_outbox", $vaField,false);
        }
      }
    }
    if (!$lSendMSG) $cResponse .= "#$cMessage";
    // insert response to log
    insert2SMSInbox($cHP, $cAgen, $cResponse, "mBanking");

		# update: kebutuhan log
		/*$cLogMessage = "Hapus aktivasi fasilitas digital untuk HP (%s)";
		$cLogMessage = sprintf($cLogMessage, $cHP);
		if (!empty(trim($cEmail))) {
			$cLogMessage .= " dan Email (%s)";
			$cLogMessage  = trim($cLogMessage);
			$cLogMessage  = sprintf($cLogMessage, $cEmail);
		}
		SwitchLog::SetKodeAgen($cAgen);
		SwitchLog::SetKodeCIF($cLogKodeCIF);
		SwitchLog::SetEvent(self::$cKodeLog . " : (CBS) Hapus Aktivasi Fasilitas Digital");
		SwitchLog::SetMessage($cLogMessage);
		SwitchLog::Save();*/
		
    return $cResponse;
  }

  function SavingEmailAgen($vaRequest){
    $va         = explode("#", $vaRequest['MSG']);
    $cEmail     = $va[0];
    $cPassword  = $va[1];
    $cAgen      = $vaRequest['AGEN'];

    //simpan ke tabel agen
    objData::Edit("agen", array("Email" => $cEmail, "EmailPassword" => $cPassword), "Kode = '$cAgen'");

    return "SUKSES";
  }

  function ResetPINRegister($vaRequest){
		//CS#Reset PIN mBanking#mBankingResetPIN#$cNoHP#$cKodeAgen
		$cResponse			= "";
    $va             = explode("#", $vaRequest['MSG']);
    $cFaktur        = $va[1];
    $cHP            = $va[3];
    $cNama          = $va[4];
    $cEmailFromCBS  = isset($va[5]) ? $va[5] : "";
    $cEmailNS = isset($vaRequest['EMAIL']) ? $vaRequest['EMAIL'] : "";
    $lSendMSG = isset($vaRequest['SENDMSG']) ? $vaRequest['SENDMSG'] : true; # kirim pemberitahuan via CBS (fanani)
    $lSMSMasking = isset($vaRequest['SMS_MASKING']) ? $vaRequest['SMS_MASKING'] : false; # kirim SMS menggunakan SMS masking (fanani)
    $cAgen          = $vaRequest['AGEN'];
    // Penambahan terkait custom pemberitahuan (fanani)
    $cIsiPesan = isset($vaRequest['ISI_PESAN']) ? $vaRequest['ISI_PESAN'] : "";
		//new
		$cVersi = isset($vaRequest['Versi']) ? $vaRequest['Versi'] : "";
		$cPIN 	= isset($vaRequest['PIN']) ? decryptIt(decryptIt(rawurldecode($vaRequest['PIN']))) : "";
		
    // insert request to log first 
    insert2SMSInbox($cHP, $cAgen, $vaRequest['MSG'], "mBanking");

    $dbData   = objData::Browse("agen_aktifasi", "HP,Email", "HP='" . MBankingFunc::KodeNegara($cHP) . "' and Agen = '$cAgen'"); // and mBankingToken <> ''
    if ($dbRow = objData::GetRow($dbData) && $cPIN != "") {
      $cEmail    = $cEmailFromCBS == "" ? $dbRow['Email'] : $cEmailFromCBS;
			if($cEmail == "" && $cEmailNS != "") $cEmail = $cEmailNS;
      $cMessage = "";
      if ($cIsiPesan <> "") {
        $cIsiPesan = str_replace("[cPIN]", $cPIN, $cIsiPesan);
        $cMessage  = $cIsiPesan;
      }
			
      if ($lSMSMasking) {
				if($cAgen == "A-000172" || $cAgen == "A-000153"){
					// Insert MHY
					$dDT       = date("Y-m-d H:i:s");
					$cMessage = "Yth " . $cNama . " PIN Anda berhasil direset menjadi $cPIN. Harap segera ganti PIN anda kembali.";
					objData::Insert("token_digi_log",array("CTO"=>$cHP,"CFROM"=>$cAgen,"CBD"=>$cMessage,"DT"=>$dDT),false);
					
					$cResponse = "AR#$cFaktur#SUKSES#Tunggu Email/SMS pemberitahuan selanjutnya##";
				}else{
					$cNoHP = MBankingFunc::FormatNoHP($cHP);
					$vaBank = getDataAgen($cAgen);
					$cMessage = "Yth " . $cNama . " PIN Anda berhasil direset menjadi $cPIN. Harap segera ganti PIN anda kembali.";
					$vaDataMasking = array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "1", "AGEN" => $cAgen);
					$cSMSMasking = SendSMSMasking($vaDataMasking);
					$vaSMSMasking = json_decode($cSMSMasking, true);
					$cResponse = "AR#$cFaktur#" . $vaSMSMasking['status'] . "#" . $vaSMSMasking['text'] . "##";
				}
				/*
        $cNoHP = MBankingFunc::FormatNoHP($cHP);
        $vaBank = getDataAgen($cAgen);
        $cMessage = "Yth " . $cNama . " PIN Anda berhasil direset menjadi $cPIN. Harap segera ganti PIN anda kembali.";
        $vaDataMasking = array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "1", "AGEN" => $cAgen);
        $cSMSMasking = SendSMSMasking($vaDataMasking);
        $vaSMSMasking = json_decode($cSMSMasking, true);
        $cResponse = "AR#$cFaktur#" . $vaSMSMasking['status'] . "#" . $vaSMSMasking['text'] . "#$cEncryptedPIN#$cEncryptedPIN";
				*/
      } else {
        if ($cEmail <> "") {
          $vaBank     = getDataAgen($cAgen);
          // Penambahan terkait custom pemberitahuan (fanani)
          if ($cMessage == "") $cMessage   = "Yth " . $cNama . " PIN Anda berhasil direset menjadi $cPIN. Harap segera ganti PIN anda kembali.";
          if ($lSendMSG) {
            $dDT       = date("Y-m-d H:i:s");
            $cMessage  = rawurlencode($cMessage);
            $vaInsert  = array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cEmail,"Subject"=>"Reset PIN mBanking","Message"=>$cMessage,"UserName"=>"mBanking");
            $cTRX      = objData::Insert("notifemail_sent",$vaInsert,false);
          }
          $cResponse = "AR#$cFaktur#SUKSES#Tunggu Email pemberitahuan selanjutnya##";
        } else {
          // kirim sms ke user
          $cNoHP     = MBankingFunc::FormatNoHP($cHP);
          $cNoModem  = "08563606500"; //$mBanking->GetNoModem($cNoHP);
          // Penambahan terkait custom pemberitahuan (fanani)
          if ($cMessage == "") $cMessage   = "Yth " . $cNama . " PIN Anda berhasil direset menjadi $cPIN. Harap segera ganti PIN anda kembali.";
          if ($lSendMSG) { # kirim pemberitahuan via CBS jika false (fanani)
            $vaField   = array("DateTime" => time(), "SMSTo" => $cNoHP, "IMEI" => $cNoModem, "Message" => $cMessage, "Priority" => 0);
            objData::Insert("sms_gateway_outbox", $vaField, false);
          }
					
          $cResponse = "AR#$cFaktur#SUKSES#Tunggu SMS pemberitahuan selanjutnya##";
        }
      }
      if (!$lSendMSG) $cResponse .= "#$cMessage";
			
			if($cVersi=="2.0"){
				$vaBody		= array(
					"TRX"			=> "76",
					"Agen"		=> $cAgen,
					"PIN"			=> $vaRequest['PIN'],
					"HP"			=> $cHP,
					"RG"			=> "00"
				);
				$cBody = json_encode(
					array(
						"MTI" => '006',
						"MSG"	=> $vaBody
					)
				);
				
				objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG-REQ","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				$cMessage	= "cCode=" . $cBody;
				$cU	= self::GetConfig('ss');
				$vaH = array(
					'authorization: ' . hash('sha256',$cBody.SNow()),
					'identity: ' . self::GetConfig('cicd'),
					'datetime: ' . SNow(),
					'Content-Type: application/x-www-form-urlencoded'
				);
				$cResponse2  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
				$cResponse2  = ltrim($cResponse2);
				objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG-RES","Message"=>$cResponse2,"DateTime"=>SNow(),"Agen"=>$cAgen));
				
				$vaResponse = json_decode($cResponse2,1);
				$cRC = isset($vaResponse['data']['RC'])? $vaResponse['data']['RC'] : "";
				if($cRC!="00"){
					$cResponse = "AR#$cFaktur#GAGAL#DATA TIDAK LENGKAP";
				}
			}
    } else {
      $cResponse = "AR#$cFaktur#GAGAL#Nomor belum aktivasi sebelumnya";
    }
    // insert response to log 
    insert2SMSInbox($cHP, $cAgen, $cResponse, "mBanking");

    return $cResponse;
  }
  
  static function mBankingGetData($vaBody, $cBody){
    $vaResponse = array();
    $cTrx       = $vaBody['TRX'];
    $cTrxKey    = isset($vaBody['TRXKEY']) ? $vaBody['TRXKEY'] : "";
    $cSim       = isset($vaBody['SIMSERIAL']) ? $vaBody['SIMSERIAL'] : "";
    $cAgen      = isset($vaBody['AGEN']) ? $vaBody['AGEN'] : "";
    $cRG       	= isset($vaBody['RG'])? $vaBody['RG'] : "";
    $cWhere1    = "TrxKey = '$cTrxKey'";
    if ($cSim != "") $cWhere1 = "SIMSerial = '$cSim'";
		
		if($cRG == TRX_REGISTER_ONLINE){
			if($cTrx == TRX_GET_REGISTERNASABAH){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				$cBase64 	= isset($vaBody['Base64'])? $vaBody['Base64'] : ""; 
				$cKTP 		= isset($vaBody['KTP'])? $vaBody['KTP'] : ""; 
				
				if($cKTP!=""){
					
				}
				
				if($cBase64!=""){
					// crt dir
					$cDir     = '../foto';
					if(!is_dir($cDir)) mkdir($cDir,0777) ;
					// rename file
					$permitted_chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
					$cNama			= substr(str_shuffle($permitted_chars), 0, 15);
					// Decode Base64 string into binary data
					$realImage 	= base64_decode($vaBody['Base64']);
					$nama 			= $cDir."/$cNama.jpg";
					// sv flie
					file_put_contents($nama, $realImage);

					# Upload ke CDS
					$cKeyFile       = md5(time().session_id()."1");

					$cCDSID             = "f8099b1aaf9c8ceec8d205a0b75e802d";
					$cUrlCDS            = "http://cds.sis1.net/cds/public/";
					$cFileName          = md5(microtime().session_id());

					$vaFileTmp          = new CurlFile($nama, mime_content_type($nama), $nama);
					$vaFile[$cFileName] = $vaFileTmp;
					$cBodyTmp           = cds::UploadFileTmp($vaFile,$cCDSID,"img");
					$vaBodyTmp          = json_decode($cBodyTmp,1);
					$vaBdy             	= array("FileKey"=>$cFileName ,"Dir"=>$vaBodyTmp['data']['cPathFile'],"Name"=>$vaBodyTmp['data']['file']['name'],"CDSID"=>$cCDSID) ;
					$cBdy              	= cds::UploadFile($vaBdy,$cCDSID,"img") ;
					$vaData             = json_decode($cBdy,true);
					$vaBdTmp 						= json_decode($cBody,1);
					$vaBdTmp['MSG']['Base64']		= $vaData['data'];
					$cBody		= json_encode($vaBdTmp);
					// del flie
					if (file_exists($nama)) {
						 unlink($nama);
					}
				}
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					$cUserH2H = $dbRow['UserH2H'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG-REQ","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage	= "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					//echo($cResponse . '---');
					//echo "asem " . $cResponse . " ~ " . $cU ."~";  
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG-RES","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen));
					
					$vaResponse['data']['MSG']['Step'] = isset($vaResponse['data']['MSG']['Step'])? $vaResponse['data']['MSG']['Step'] : "";
					if($vaResponse['data']['MSG']['Step']=="4" || $vaResponse['data']['MSG']['Step']=="5"){
						# Masuk ke agen_
						$vaBody['Email'] = isset($vaBody['Email'])? $vaBody['Email'] : "";
						$vaIns = array(
							"HP" 						=> MBankingFunc::KodeNegara($vaBody['HP']),
							"Email"					=> strtolower($vaBody['Email']),
							"SIMSerial"			=> $vaBody['SIMSerial'],
							"Agen"					=> $cAgen,
							"DateTime"			=> SNow(),
							"Aktif"					=> "0",
							"Jenis"					=> "M",
							"mBankingKodeFasilitas"	=> "",
							"KodeCIF"								=> "",
							"OTP"										=> ""
						);
						$cHPo = MBankingFunc::KodeNegara($vaBody['HP']);
						objData::Delete("agen_aktifasi","HP = '$cHPo' and Agen = '$cAgen'");
						objData::Update("agen_aktifasi",$vaIns,"HP = '$cHPo' and Agen = '$cAgen'");
						/*
						$vaIns2 = array(
							"Nomor" 				=> $vaBody['HP'],
							"TglAktifasi"		=> date("Y-m-d"),
							"Agen"					=> $cAgen,
							"Jenis"					=> "M",
							"SIMSerial"			=> $vaBody['SIMSerial'],
							"TrxKey"				=> "",
						);
						*/
						$cHPo = $vaBody['HP'];
						objData::Delete("agen_smsbanking","Nomor = '$cHPo' and Agen = '$cAgen' and Jenis = 'M'");
						//objData::Update("agen_smsbanking",$vaIns2,"Nomor = '$cHPo' and Agen = '$cAgen'");
						
						$cResponse  = "Berhasil.";
						$vaResponse['data']['MSG'] = array(
							"ErrorMSG"=>$cResponse
						);
					}else if($vaResponse['data']['MSG']['Step']=="HP"){
						# Kirim OTP HP
						
						# Kirim OTP HP
						$cNoHP	= $vaBody['HP'];
						$cNoHP 	= MBankingFunc::FormatNoHP($cNoHP);
						$vaBank = getDataAgen($cAgen);
						$nToken			= $vaResponse['data']['MSG']['OTP'];
						$cMessage 			= "Token untuk akun digital mobile anda: " . $nToken . ". Jangan berikan token ke orang lain";
						$vaDataMasking 	= array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "1", "AGEN" => $cAgen, "RES_MSG" => "Berhasil. Token akan dikirimkan via SMS. Mohon tunggu beberapa menit.");
						$cSMSMasking 		= SendSMSMasking($vaDataMasking);
						$vaSMSMasking 	= json_decode($cSMSMasking, true);
						$cResponse 			= $vaSMSMasking['text'];
						
						
						$vaResponse['data']['MSG'] = array(
							"ErrorMSG"=>$cResponse
						);
					}else if($vaResponse['data']['MSG']['Step']=="EMAIL"){
						# Kirim OTP EMail
						$vaBank     = getDataAgen($cAgen);
						$cEmail     = $vaBody['Email']; // penambahan fanani
						$cTujuan    = $vaBody['Email'];
						$nToken			= $vaResponse['data']['MSG']['OTP'];
						// Penambahan terkait custom pemberitahuan (fanani)
						$cPesan = "Token untuk akun anda: " . $nToken . ". Jangan berikan token ke orang lain";
						
						$dDT       = date("Y-m-d H:i:s");
						$cPesan  = rawurlencode($cPesan);
						$vaInsert  = array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cTujuan,"Subject"=>"Kode Aktivasi mBanking","Message"=>$cPesan,"UserName"=>"mBanking");
						$cTRX      = objData::Insert("notifemail_sent",$vaInsert,false);
						
						$cResponse  = "Berhasil. Token akan dikirimkan via Email. Mohon tunggu beberapa menit.";
						$vaResponse['data']['MSG'] = array(
							"ErrorMSG"=>$cResponse
						);
					}else if($vaResponse['data']['MSG']['Step']=="P" || $vaResponse['data']['MSG']['Step']=="10"){
						$vaIns = array(
							"DateTime"			=> SNow(),
							"Aktif"					=> "1",
							"Jenis"					=> "M",
							"mBankingKodeFasilitas"	=> "",
							"KodeCIF"								=> "",
							"OTP"										=> ""
						);
						
						$cHPo = MBankingFunc::KodeNegara($vaBody['HP']);
						objData::Update("agen_aktifasi",$vaIns,"HP = '$cHPo' and Agen = '$cAgen'");
						# Up pembukaan sudah punya rekening
						
						$vaIns2 = array(
							"Nomor" 				=> $vaBody['HP'],
							"TglAktifasi"		=> date("Y-m-d"),
							"Agen"					=> $cAgen,
							"Jenis"					=> "M",
							"SIMSerial"			=> $vaBody['SIMSerial'],
							"TrxKey"				=> "",
						);

						$cHPo = $vaBody['HP'];
						objData::Update("agen_smsbanking",$vaIns2,"Nomor = '$cHPo' and Agen = '$cAgen'");
					}
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse["data"];
			}else if($cTrx == TRX_GET_GETHPONLINE){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					$cURL     = $dbRow['URL'];
					$cUserH2H = $dbRow['UserH2H'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG-REQ","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage	= "cCode=" . $cBody;
					$cRes  		= SendHTTPPost($cURL, $cMessage);
					$vaResponse	= json_decode($cRes,1);
					
					if($vaResponse['RC']=="00"){
						objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG-REQ","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
						$cMessage   = "cCode=" . $cBody;
						$cU		= self::GetConfig('ss');
						$dNs = SNow();
						$vaH = array(
							'authorization: ' . hash('sha256',$cBody.$dNs),
							'identity: ' . self::GetConfig('cicd'),
							'datetime: ' . $dNs,
							'Content-Type: application/x-www-form-urlencoded'
						);
						$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
						//echo($cMessage . '---');
						//echo "\n asem " . $cResponse; // . " ~ " . $cU ."~";  
						$cResponse  = ltrim($cResponse);
						$vaResponse = json_decode($cResponse,1);
						
						objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG-RES","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					}else{
						return $vaResponse;
					}
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse["data"];
			}else if($cTrx == TRX_GET_LOGIN_USERNAME){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					$cURL     = $dbRow['URL'];
					$cUserH2H = $dbRow['UserH2H'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG-REQ","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG-RES","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}
				
				#kirim otp hp jika otp = 1. tapi sementara pakai email
				$cOTP 	= isset($vaResponse['data']["MSG"]["OTP"])? $vaResponse['data']["MSG"]["OTP"] : '';
				$cSLog 	= isset($vaResponse['data']["MSG"]["Log"])? $vaResponse['data']["MSG"]["Log"] : '';
				
				#cek dulu otp
				if($cOTP == 1){
					$cPsn 	= isset($vaResponse['data']["MSG"]["Pesan"])? $vaResponse['data']["MSG"]["Pesan"] : '';
					$cEml 	= isset($vaResponse['data']["MSG"]["Email"])? $vaResponse['data']["MSG"]["Email"] : '';
					
					# Kirim OTP HP
					$cNoHP	= $vaResponse['data']["MSG"]['HP'];
					$cNoHP 	= MBankingFunc::FormatNoHP($cNoHP);
					$vaBank = getDataAgen($cAgen);
					$nToken			= $vaResponse['data']['MSG']['OTPHP'];
					$cMessage 			= "Token untuk akun digital mobile anda: " . $nToken . ". Jangan berikan token ke orang lain";
					$vaDataMasking 	= array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "1", "AGEN" => $cAgen, "RES_MSG" => "Berhasil. Token akan dikirimkan via SMS. Mohon tunggu beberapa menit.");
					$cSMSMasking 		= SendSMSMasking($vaDataMasking);
					$vaSMSMasking 	= json_decode($cSMSMasking, true);
					$cResponse 			= $vaSMSMasking['text'];
					
					$vaResponse['data']['MSG'] = array(
						"Respon"		=> $cResponse,
						"Step"      => $vaResponse['data']['MSG']['Step'],
						"OTP"				=> $vaResponse['data']['MSG']['OTP'],
					);
					
					if($cPsn != ""){
						# Kirim OTP EMail
						$vaBank     = getDataAgen($cAgen);
						$cEmail     = $cEml; // penambahan fanani
						$cTujuan    = $cEml;
						//$nToken			= $vaResponse['data']['MSG']['OTPEmail'];
						// Penambahan terkait custom pemberitahuan (fanani)
						$cPesan = $cPsn; //"Token untuk akun anda: " . $nToken . ". Jangan berikan token ke orang lain";

						$dDT       	= date("Y-m-d H:i:s");
						$cPesan  		= rawurlencode($cPesan);
						$vaInsert  	= array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cTujuan,"Subject"=>"Aktivitas login terdeteksi","Message"=>$cPesan,"UserName"=>"mBanking");
						$cTRX      	= objData::Insert("notifemail_sent",$vaInsert,false);
					}
				}else if($cSLog == "1"){
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					$cURL     = $dbRow['URL'];
					$cUserH2H = $dbRow['UserH2H'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$vaBody 	= json_decode($cBody, true);
					$vaBody["MSG"] = array(
						"TRX" 			=> $cTrx,
						"HP" 				=> $vaResponse['data']['MSG']['HP'],
						"Email"			=> $vaResponse['data']['MSG']['Email'],
						"SIMSerial"	=> $vaResponse['data']['MSG']['SIMSerial'],
						"CIF"				=> $vaResponse['data']['MSG']['CIF'],
						"Username"	=> $vaResponse['data']['MSG']['Username'],
						"Point"			=> $vaResponse['data']['MSG']['Point'],
					);
					$cBody		= json_encode($vaBody);
					$cMessage	= "cCode=" . $cBody;
					$cRes  		= SendHTTPPost($cURL, $cMessage);
					$vaResp		= json_decode($cRes,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cRes,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					
					if($vaResp['RC'] == "00"){
						$vaIns = array(
							"HP" 						=> MBankingFunc::KodeNegara($vaResponse['data']['MSG']['HP']),
							"Email"					=> strtolower($vaResponse['data']['MSG']['Email']),
							"SIMSerial"			=> $vaResponse['data']['MSG']['SIMSerial'],
							"Agen"					=> $cAgen,
							"DateTime"			=> SNow(),
							"Aktif"					=> "0",
							"Jenis"					=> "M",
							"mBankingKodeFasilitas"	=> "",
							"KodeCIF"								=> "",
							"OTP"										=> ""
						);
						$cHPo = MBankingFunc::KodeNegara($vaResponse['data']['MSG']['HP']);
						objData::Delete("agen_aktifasi","HP = '$cHPo' and Agen = '$cAgen'");
						objData::Update("agen_aktifasi",$vaIns,"HP = '$cHPo' and Agen = '$cAgen'");

						$vaIns2 = array(
							"Nomor" 				=> $vaResponse['data']['MSG']['HP'],
							"TglAktifasi"		=> date("Y-m-d"),
							"Agen"					=> $cAgen,
							"Jenis"					=> "M",
							"SIMSerial"			=> $vaResponse['data']['MSG']['SIMSerial'],
							"TrxKey"				=> "",
						);

						$cHPo = $vaResponse['data']['MSG']['HP'];
						objData::Delete("agen_smsbanking","Nomor = '$cHPo' and Agen = '$cAgen' and Jenis = 'M'");
						objData::Update("agen_smsbanking",$vaIns2,"Nomor = '$cHPo' and Agen = '$cAgen'");
					}else{
						$cHPo = MBankingFunc::KodeNegara($vaResponse['data']['MSG']['HP']);
						objData::Delete("agen_aktifasi","HP = '$cHPo' and Agen = '$cAgen'");
						
						$cHPo = $vaResponse['data']['MSG']['HP'];
						objData::Delete("agen_smsbanking","Nomor = '$cHPo' and Agen = '$cAgen' and Jenis = 'M'");
					}
					
					
					$vaResponse['data']['MSG'] 	= $vaResp['MSG'];
					$vaResponse['data']['RC'] 	= $vaResp['RC'];
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_GET_LOGIN_VERIVIKASI) {
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));	
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					$cURL     = $dbRow['URL'];
					$cUserH2H = $dbRow['UserH2H'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage	= "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}	
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];		
			}else if($cTrx == TRX_GET_VERIFIKASI_REGISTER){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					//echo '--tes3--';
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					$cURL     = $dbRow['URL'];
					$cUserH2H = $dbRow['UserH2H'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];	
			}else if($cTrx == TRX_GET_GET_DATA){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));	
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					$cURL     = $dbRow['URL'];
					$cUserH2H = $dbRow['UserH2H'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;

					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}	
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];		
			}else if($cTrx == TRX_CHECK_ACTIVE_ACCOUNT){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					$cURL     = $dbRow['URL'];
					$cUserH2H = $dbRow['UserH2H'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU		= self::GetConfig('ss');
					$vaH 	= array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_GET_GET_DATA_REG){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}
				
				#
				$cStep = isset($vaResponse['data']["MSG"]["Step"])? $vaResponse['data']["MSG"]["Step"] : '';
				
				#
				if($cStep == "1"){
					# Up pembukaan rekening
					$vaIns2 = array(
						"Nomor" 				=> $vaResponse['data']["MSG"]["HP"],
						"TglAktifasi"		=> date("Y-m-d"),
						"Agen"					=> $cAgen,
						"Jenis"					=> "M",
						"SIMSerial"			=> $vaResponse['data']["MSG"]["SIMSerial"],
						"TrxKey"				=> "",
					);

					$cHPo = $vaBody['HP'];
					objData::Update("agen_smsbanking",$vaIns2,"Nomor = '$cHPo' and Agen = '$cAgen'");
					
					if(!isset($vaResponse['data'])) $vaResponse['data'] = json_encode(array("MTI"=>"100","RC"=>"XT","MSG"=>""));
					$vaResponse['data']['MSG'] = "Berhasil";
				}else if($cStep == "0"){
					$vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Proses pembuatan rekening tidak dapat dilanjutkan karena anomali data.");
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_GET_LOGIN_EXIST){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					$cURL     = $dbRow['URL'];
					$cUserH2H = $dbRow['UserH2H'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage	= "cCode=" . $cBody;
					$cRes  		= SendHTTPPost($cURL, $cMessage);
					$vaResponse 		= json_decode($cRes,1);
					
					if($vaResponse['RC']=="00"){
						$vaBd 								= json_decode($cBody,1);
						$vaBd['MSG']['data'] 	= $vaResponse['MSG'];
						$cBody 								= json_encode($vaBd);
						objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
						$cMessage	= "cCode=" . $cBody;
						$cU	= self::GetConfig('ss');
						$vaH = array(
							'authorization: ' . hash('sha256',$cBody.SNow()),
							'identity: ' . self::GetConfig('cicd'),
							'datetime: ' . SNow(),
							'Content-Type: application/x-www-form-urlencoded'
						);
						$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
						$cResponse  = ltrim($cResponse);
						$vaResponse = json_decode($cResponse,1);
						objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
						
						#kirim otp hp jika otp = 1. tapi sementara pakai email
						$cOTP = isset($vaResponse['data']["MSG"]["OTP"])? $vaResponse['data']["MSG"]["OTP"] : '';

						#cek dulu otp
						if($cOTP == 1){
							# Kirim OTP HP
							$cNoHP	= $vaBody['HP'];
							$cNoHP 	= MBankingFunc::FormatNoHP($cNoHP);
							$vaBank = getDataAgen($cAgen);
							$nToken			= $vaResponse['data']['MSG']['OTPHP'];
							$cMessage 			= "Token untuk akun digital mobile anda: " . $nToken . ". Jangan berikan token ke orang lain";
							$vaDataMasking 	= array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "1", "AGEN" => $cAgen, "RES_MSG" => "Berhasil. Token akan dikirimkan via SMS. Mohon tunggu beberapa menit.");
							$cSMSMasking 		= SendSMSMasking($vaDataMasking);
							$vaSMSMasking 	= json_decode($cSMSMasking, true);
							$cResponse 			= $vaSMSMasking['text'];
							/*
							# Kirim OTP EMail
							$vaBank     = getDataAgen($cAgen);
							$cEmail     = $vaBody['Email']; // penambahan fanani
							$cTujuan    = $vaBody['Email'];
							$nToken			= $vaResponse['data']['MSG']['OTPEmail'];
							// Penambahan terkait custom pemberitahuan (fanani)
							$cPesan = "Token untuk akun anda: " . $nToken . ". Jangan berikan token ke orang lain";

							$dDT       	= date("Y-m-d H:i:s");
							$cPesan  		= rawurlencode($cPesan);
							$vaInsert  	= array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cTujuan,"Subject"=>"Kode Aktivasi mBanking","Message"=>$cPesan,"UserName"=>"mBanking");
							$cTRX      	= objData::Insert("notifemail_sent",$vaInsert,false);
							$cResponse  = "Berhasil. Token akan dikirimkan via Email. Mohon tunggu beberapa menit.";
							*/
							$vaResponse['data']['MSG'] = $cResponse;
						}
					}else{
						if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>$vaResponse['RC'],"MSG"=>"");
						return $vaResponse['data'];
					}
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_GET_OTP_LOGIN_EXIST){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}
				
				#kirim otp hp jika otp = 1. tapi sementara pakai email
				$cOTP = isset($vaResponse['data']["MSG"]["OTP"])? $vaResponse['data']["MSG"]["OTP"] : '';
				
				#cek dulu otp
				if($cOTP == "1"){
					/*
					# Kirim OTP HP
					$cNoHP	= $vaBody['HP'];
					$cNoHP 	= MBankingFunc::FormatNoHP($cHP);
					$vaBank = getDataAgen($cAgen);
					$nToken			= $vaResponse['data']['MSG']['OTPEmail'];
					$cMessage 			= "Token untuk akun digital mobile anda: " . $nToken . ". Jangan berikan token ke orang lain";
					$vaDataMasking 	= array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "1", "AGEN" => $cAgen, "RES_MSG" => "Berhasil. Token akan dikirimkan via SMS. Mohon tunggu beberapa menit.");
					$cSMSMasking 		= SendSMSMasking($vaDataMasking);
					$vaSMSMasking 	= json_decode($cSMSMasking, true);
					$cResponse 			= $vaSMSMasking['text'];
					*/
					
					# Kirim OTP EMail
					$vaBank     = getDataAgen($cAgen);
					$cEmail     = $vaBody['Email']; // penambahan fanani
					$cTujuan    = $vaBody['Email'];
					$nToken			= $vaResponse['data']['MSG']['OTPEmail'];
					// Penambahan terkait custom pemberitahuan (fanani)
					$cPesan = "Token untuk akun anda: " . $nToken . ". Jangan berikan token ke orang lain";

					$dDT       	= date("Y-m-d H:i:s");
					$cPesan  		= rawurlencode($cPesan);
					$vaInsert  	= array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cTujuan,"Subject"=>"Kode Aktivasi mBanking","Message"=>$cPesan,"UserName"=>"mBanking");
					$cTRX      	= objData::Insert("notifemail_sent",$vaInsert,false);

					$cResponse  = "Berhasil. Token akan dikirimkan via Email. Mohon tunggu beberapa menit.";
					$vaResponse['data']['MSG'] = $cResponse;
				}else if($cOTP == "2"){
					$vaIns = array(
						"HP" 						=> MBankingFunc::KodeNegara($vaBody['HP']),
						"Email"					=> strtolower($vaBody['Email']),
						"SIMSerial"			=> $vaBody['SIMSerial'],
						"Agen"					=> $cAgen,
						"DateTime"			=> SNow(),
						"Aktif"					=> "0",
						"Jenis"					=> "M",
						"mBankingKodeFasilitas"	=> "",
						"KodeCIF"								=> "",
						"OTP"										=> ""
					);
					$cHPo = MBankingFunc::KodeNegara($vaBody['HP']);
					objData::Delete("agen_aktifasi","HP = '$cHPo' and Agen = '$cAgen'");
					objData::Update("agen_aktifasi",$vaIns,"HP = '$cHPo' and Agen = '$cAgen'");
					# Pra pembukaan sudah punya rekening
					$cHPo = $vaBody['HP'];
					objData::Delete("agen_smsbanking","Nomor = '$cHPo' and Agen = '$cAgen' and Jenis = 'M'");
					
					if(!isset($vaResponse['data'])) $vaResponse['data'] = json_encode(array("MTI"=>"100","RC"=>"XT","MSG"=>""));
					$vaResponse['data']['MSG'] = "Berhasil";
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_GET_EDIT_PIN_PASSWORD){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_GET_LUPA_PIN_PASSWORD){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}
				
				#kirim otp hp jika otp = 1. tapi sementara pakai email
				$cOTP 			= isset($vaResponse['data']["MSG"]["OTP"])? $vaResponse['data']["MSG"]["OTP"] : '';
				$cOTPHP 		= isset($vaResponse['data']["MSG"]["OTPHP"])? $vaResponse['data']["MSG"]["OTPHP"] : '';
				$cOTPEmail 	= isset($vaResponse['data']["MSG"]["OTPEmail"])? $vaResponse['data']["MSG"]["OTPEmail"] : '';
				
				#cek dulu otp
				if($cOTP == "1"){
					if($cOTPHP!=""){
						# Kirim OTP HP
						$cNoHP	= $vaBody['HP'];
						$cNoHP 	= MBankingFunc::FormatNoHP($cNoHP);
						$vaBank = getDataAgen($cAgen);
						$nToken			= $vaResponse['data']['MSG']['OTPHP'];
						$cMessage 			= "Token untuk akun digital mobile anda: " . $nToken . ". Jangan berikan token ke orang lain";
						$vaDataMasking 	= array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "1", "AGEN" => $cAgen, "RES_MSG" => "Berhasil. Token akan dikirimkan via SMS. Mohon tunggu beberapa menit.");
						$cSMSMasking 		= SendSMSMasking($vaDataMasking);
						$vaSMSMasking 	= json_decode($cSMSMasking, true);
						$cResponse 			= $vaSMSMasking['text'];
						$vaResponse['data']['MSG'] = $cResponse;
					}else if($cOTPEmail!=""){
						# Kirim OTP EMail
						$vaBank     = getDataAgen($cAgen);
						$cEmail     = $vaBody['Email']; // penambahan fanani
						$cTujuan    = $vaBody['Email'];
						$nToken			= $vaResponse['data']['MSG']['OTPEmail'];
						// Penambahan terkait custom pemberitahuan (fanani)
						$cPesan = "Token untuk akun anda: " . $nToken . ". Jangan berikan token ke orang lain";

						$dDT       	= date("Y-m-d H:i:s");
						$cPesan  		= rawurlencode($cPesan);
						$vaInsert  	= array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cTujuan,"Subject"=>"Kode Aktivasi mBanking","Message"=>$cPesan,"UserName"=>"mBanking");
						$cTRX      	= objData::Insert("notifemail_sent",$vaInsert,false);

						$cResponse  = "Berhasil. Token akan dikirimkan via Email. Mohon tunggu beberapa menit.";
						$vaResponse['data']['MSG'] = $cResponse;
					}
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				
				objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>json_encode($vaResponse),"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				return $vaResponse['data'];
			}else if($cTrx == TRX_GET_DATA_DIGITAL){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG-REQ","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG-RES","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_GET_RESENDOTP){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					$cURL     = $dbRow['URL'];
					$cUserH2H = $dbRow['UserH2H'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}
				
				#kirim otp hp jika otp = 1. tapi sementara pakai email
				$cOTP = isset($vaResponse['data']["MSG"]["OTP"])? $vaResponse['data']["MSG"]["OTP"] : '';
				
				#cek dulu otp
				if($cOTP == "1"){
					/*
					# Kirim OTP HP
					$cNoHP	= $vaBody['HP'];
					$cNoHP 	= MBankingFunc::FormatNoHP($cHP);
					$vaBank = getDataAgen($cAgen);
					$nToken			= $vaResponse['data']['MSG']['OTPEmail'];
					$cMessage 			= "Token untuk akun digital mobile anda: " . $nToken . ". Jangan berikan token ke orang lain";
					$vaDataMasking 	= array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "1", "AGEN" => $cAgen, "RES_MSG" => "Berhasil. Token akan dikirimkan via SMS. Mohon tunggu beberapa menit.");
					$cSMSMasking 		= SendSMSMasking($vaDataMasking);
					$vaSMSMasking 	= json_decode($cSMSMasking, true);
					$cResponse 			= $vaSMSMasking['text'];
					*/
					
					# Kirim OTP EMail
					$vaBank     = getDataAgen($cAgen);
					$cEmail     = $vaBody['Email']; // penambahan fanani
					$cTujuan    = $vaBody['Email'];
					$nToken			= $vaResponse['data']['MSG']['OTPEmail'];
					// Penambahan terkait custom pemberitahuan (fanani)
					$cPesan = "Token untuk akun anda: " . $nToken . ". Jangan berikan token ke orang lain";

					$dDT       	= date("Y-m-d H:i:s");
					$cPesan  		= rawurlencode($cPesan);
					$vaInsert  	= array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cTujuan,"Subject"=>"Kode Aktivasi mBanking","Message"=>$cPesan,"UserName"=>"mBanking");
					$cTRX      	= objData::Insert("notifemail_sent",$vaInsert,false);

					$cResponse  = "Berhasil. Token akan dikirimkan via Email. Mohon tunggu beberapa menit.";
					$vaResponse['data']['MSG'] = $cResponse;
				}else if($cOTP == "2"){
					# Kirim OTP HP
					$cNoHP	= $vaBody['HP'];
					$cNoHP 	= MBankingFunc::FormatNoHP($cNoHP);
					$vaBank = getDataAgen($cAgen);
					$nToken			= $vaResponse['data']['MSG']['OTPHP'];
					$cMessage 			= "Token untuk akun digital mobile anda: " . $nToken . ". Jangan berikan token ke orang lain";
					$vaDataMasking 	= array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "1", "AGEN" => $cAgen, "RES_MSG" => "Berhasil. Token akan dikirimkan via SMS. Mohon tunggu beberapa menit.");
					$cSMSMasking 		= SendSMSMasking($vaDataMasking);
					$vaSMSMasking 	= json_decode($cSMSMasking, true);
					$cResponse 			= $vaSMSMasking['text'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cSMSMasking,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					
					$vaResponse['data']['MSG'] = $cResponse;
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_GET_DELREGNS){
				$cKodeBank  = $vaBody['Bank'];
				$cAData  		= isset($vaBody['DATA'])? $vaBody['DATA'] : "";
				$cHP				= isset($vaBody['HP'])? $vaBody['HP'] : "-";
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					$cURL     = $dbRow['URL'];
					$cUserH2H = $dbRow['UserH2H'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG-REQ","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG-REQ","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					
					if($cAData == "ACC"){
						objData::Delete("agen_smsbanking", "Nomor='" . $cHP . "' and Agen = '$cAgen' and Jenis = 'M'");
      			objData::Delete("agen_aktifasi", "HP='" . MBankingFunc::KodeNegara($cHP) . "' and Agen = '$cAgen'");
					}
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_GET_USN_CR){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					$cURL     = $dbRow['URL'];
					$cUserH2H = $dbRow['UserH2H'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}
				
				#kirim otp hp jika otp = 1. tapi sementara pakai email
				$cSLog 	= isset($vaResponse['data']["MSG"]["Log"])? $vaResponse['data']["MSG"]["Log"] : '';
				
				#edit data cbs
				if($cSLog == "1"){
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					$cURL     = $dbRow['URL'];
					$cUserH2H = $dbRow['UserH2H'];
					$vaBody 	= json_decode($cBody, true);
					$vaBody["MSG"]["CIF"] = $vaResponse['data']['MSG']['CIF'];
					$cBody		= json_encode($vaBody);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage	= "cCode=" . $cBody;
					$cRes  		= SendHTTPPost($cURL, $cMessage);
					$vaResp		= json_decode($cRes,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cRes,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					
					$vaResponse['data']['MSG'] = $vaResp['MSG'];
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_GET_CRREK){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				$cBase64 = isset($vaBody['Base64'])? $vaBody['Base64'] : ""; 
				if($cBase64!=""){
					// crt dir
					$cDir     = '../foto';
					if(!is_dir($cDir)) mkdir($cDir,0777) ;
					// rename file
					$permitted_chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
					$cNama			= substr(str_shuffle($permitted_chars), 0, 15);
					// Decode Base64 string into binary data
					$realImage 	= base64_decode($vaBody['Base64']);
					$nama 			= $cDir."/$cNama.jpg";
					// sv flie
					file_put_contents($nama, $realImage);

					# Upload ke CDS
					$cKeyFile       = md5(time().session_id()."1");

					$cCDSID             = "f8099b1aaf9c8ceec8d205a0b75e802d";
					$cUrlCDS            = "http://cds.sis1.net/cds/public/";
					$cFileName          = md5(microtime().session_id());

					$vaFileTmp          = new CurlFile($nama, mime_content_type($nama), $nama);
					$vaFile[$cFileName] = $vaFileTmp;
					$cBodyTmp           = cds::UploadFileTmp($vaFile,$cCDSID,"img");
					$vaBodyTmp          = json_decode($cBodyTmp,1);
					$vaBdy             	= array("FileKey"=>$cFileName ,"Dir"=>$vaBodyTmp['data']['cPathFile'],"Name"=>$vaBodyTmp['data']['file']['name'],"CDSID"=>$cCDSID) ;
					$cBdy              	= cds::UploadFile($vaBdy,$cCDSID,"img") ;
					$vaData             = json_decode($cBdy,true);
					$vaBdTmp 	= json_decode($cBody,1);
					$vaBdTmp['MSG']['Base64']		= $vaData['data'];
					$cBody		= json_encode($vaBdTmp);
					// del flie
					if (file_exists($nama)) {
						 unlink($nama);
					}
				}
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_GET_ACCREK){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}
				
				#kirim otp hp jika otp = 1. tapi sementara pakai email
				$cAlasanT 	= isset($vaResponse['data']["MSG"]["Alasan"])? $vaResponse['data']["MSG"]["Alasan"] : '';
				
				#edit data cbs
				if($cAlasanT != ""){
					# Kirim OTP HP
					$cNoHP	= $vaBody['HP'];
					$cNoHP 	= MBankingFunc::FormatNoHP($cNoHP);
					$vaBank = getDataAgen($cAgen);
					$cMessage 			= "Mohon maaf, pengajuan pembuatan akun digital mobile anda ditolak dengan alasan '" . $cAlasanT . "'";
					$vaDataMasking 	= array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "0", "AGEN" => $cAgen, "RES_MSG" => "Berhasil.");
					$cSMSMasking 		= SendSMSMasking($vaDataMasking);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cSMSMasking,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					//$vaSMSMasking 	= json_decode($cSMSMasking, true);
					//$cResponse 			= $vaSMSMasking['text'];
					
					# Kirim OTP EMail
					$vaBank     = getDataAgen($cAgen);
					$cEmail     = $vaBody['Email']; // penambahan fanani
					$cTujuan    = $vaBody['Email'];
					// Penambahan terkait custom pemberitahuan (fanani)
					$cPesan = "Mohon maaf, pengajuan pembuatan akun digital mobile anda ditolak dengan alasan : '" . $cAlasanT . "'. Mohon periksa kembali data yang dikirim atau hubungi CS kami. Terima kasih :)";

					$dDT       	= date("Y-m-d H:i:s");
					$cPesan  		= rawurlencode($cPesan);
					$vaInsert  	= array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cTujuan,"Subject"=>"Pengajuan Pembuatan akun Digital Bank Ditolak","Message"=>$cPesan,"UserName"=>"mBanking");
					$cTRX      	= objData::Insert("notifemail_sent",$vaInsert,false);
					
					$lDelAg 	= isset($vaResponse['data']["MSG"]["lDelAg"])? $vaResponse['data']["MSG"]["lDelAg"] : '';
					if($lDelAg=="1"){
						$cHPo = MBankingFunc::KodeNegara($vaBody['HP']);
						objData::Delete("agen_aktifasi","HP = '$cHPo' and Agen = '$cAgen'");
						$cHPo = $vaBody['HP'];
						objData::Delete("agen_smsbanking","Nomor = '$cHPo' and Agen = '$cAgen' and Jenis = 'M'");
					}
					
					$cResponse  = "Berhasil mengirimkan pesan alasan :).";
					$vaResponse['data']['MSG'] = $cResponse;
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_GET_ACCCHG){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					
					$cSts 	= isset($vaResponse['data']["MSG"]["Sts"])? $vaResponse['data']["MSG"]["Sts"] : '';
					
					if($cSts=="1"){
						$vaIns = array(
							"SIMSerial"			=> $vaBody['SIMSerial'],
						);
						$cHPo = MBankingFunc::KodeNegara($vaBody['HP']);
						objData::Edit("agen_aktifasi",$vaIns,"HP = '$cHPo' and Agen = '$cAgen'");
						$cHPo = $vaBody['HP'];
						objData::Edit("agen_smsbanking",$vaIns,"Nomor = '$cHPo' and Agen = '$cAgen' and Jenis = 'M'");
					}
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_GET_BLOCKACC){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}
				
				$lDelAg 	= isset($vaResponse['data']["MSG"]["lDelAg"])? $vaResponse['data']["MSG"]["lDelAg"] : '';
				if($lDelAg=="1"){
					$cHPo = MBankingFunc::KodeNegara($vaBody['HP']);
					objData::Delete("agen_aktifasi","HP = '$cHPo' and Agen = '$cAgen'");
					$cHPo = $vaBody['HP'];
					objData::Delete("agen_smsbanking","Nomor = '$cHPo' and Agen = '$cAgen' and Jenis = 'M'");

					# Kirim OTP EMail
					$vaBank     = getDataAgen($cAgen);
					$cEmail     = $vaBody['Email']; // penambahan fanani
					$cTujuan    = $vaBody['Email'];
					// Penambahan terkait custom pemberitahuan (fanani)
					$cPesan 		= "Mohon maaf, kami menginformasikan bahwa akun digital anda telah ditutup. Terima kasih :)";

					$dDT       	= date("Y-m-d H:i:s");
					$cPesan  		= rawurlencode($cPesan);
					$vaInsert  	= array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cTujuan,"Subject"=>"Penutupan akun digital bank","Message"=>$cPesan,"UserName"=>"mBanking");
					$cTRX      	= objData::Insert("notifemail_sent",$vaInsert,false);
					
					$cResponse  = "Berhasil melakukan nonaktivasi digital bank.";
					$vaResponse['data']['MSG'] = $cResponse;
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_GET_BELIPROMO){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREGPRM","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage	= "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIRESPRM","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_GET_UPDPOINT){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
				
				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}
				
				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_BLOKIR_ACC){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));

				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}
				
				$lDelAg 	= isset($vaResponse['data']["MSG"]["lDelAg"])? $vaResponse['data']["MSG"]["lDelAg"] : '';
				if($lDelAg=="1"){
					$cHPo = MBankingFunc::KodeNegara($vaBody['HP']);
					objData::Delete("agen_aktifasi","HP = '$cHPo' and Agen = '$cAgen'");
					$cHPo = $vaBody['HP'];
					objData::Delete("agen_smsbanking","Nomor = '$cHPo' and Agen = '$cAgen' and Jenis = 'M'");

					# Kirim OTP EMail
					$vaBank     = getDataAgen($cAgen);
					$cEmail     = $vaBody['Email']; // penambahan fanani
					$cTujuan    = $vaBody['Email'];
					// Penambahan terkait custom pemberitahuan (fanani)
					$cPesan 		= "Mohon maaf, kami menginformasikan bahwa akun digital anda telah ditutup. Terima kasih :)";

					$dDT       	= date("Y-m-d H:i:s");
					$cPesan  		= rawurlencode($cPesan);
					$vaInsert  	= array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cTujuan,"Subject"=>"Penutupan akun digital bank","Message"=>$cPesan,"UserName"=>"mBanking");
					$cTRX      	= objData::Insert("notifemail_sent",$vaInsert,false);
					
					$cResponse  = "Berhasil melakukan nonaktivasi digital bank.";
					$vaResponse['data']['MSG'] = $cResponse;
				}

				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_UNBLOKIR_ACC){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));

				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}

				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}else if($cTrx == TRX_UPSTATUS_BLOKIR){
				$cKodeBank  = $vaBody['Bank'];
				$dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));

				if ($dbRow = objData::GetRow($dbData)) {
					$cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cBody,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
					$cMessage   = "cCode=" . $cBody;
					$cU	= self::GetConfig('ss');
					$vaH = array(
						'authorization: ' . hash('sha256',$cBody.SNow()),
						'identity: ' . self::GetConfig('cicd'),
						'datetime: ' . SNow(),
						'Content-Type: application/x-www-form-urlencoded'
					);
					$cResponse  = SendHTTPPostMB($cU,$cMessage,'',false,$vaH);
					$cResponse  = ltrim($cResponse);
					$vaResponse = json_decode($cResponse,1);
					objData::Insert("sms_inbox",array("SMSFrom"=>"DIGIREG","Message"=>$cResponse,"DateTime"=>SNow(),"Agen"=>$cAgen),false);
				}

				if(!isset($vaResponse['data'])) $vaResponse['data'] = array("MTI"=>"100","RC"=>"XT","MSG"=>"Yahh, Request Tidak Dapat Terkirim Nih :(, Coba Beberapa Saat lagi.");
				return $vaResponse['data'];
			}
		}else if($cTrx == TRX_GET_INFO_DEVICELOG){
			/*
			HEREE
			$cAc 			= isset($vaBody['ACTION']) ? $vaBody['ACTION'] : "";
			$cHP 			= isset($vaBody['HP']) ? $vaBody['HP'] : "";
			$cDevice  = isset($vaBody['DEVICE']) ? $vaBody['DEVICE'] : "";
			
			# ACC
			if($cAc=="01"){
				# Edit agen_smsbanking
				$vaEdit = array(
					"SIMSerial"			=> "",
				);
				$dbData = objData::Edit("agen_smsbanking",$vaEdit,"HP = '$cHP' and Agen = '$cAgen' and Jenis = 'M'");
				
				# Edit agen_aktifasi
				$vaEdit = array(
					"SIMSerial"			=> "",
					"DateTime"			=> SNow()
				);
				$cHP = MBankingFunc::KodeNegara($cHP);
				$dbData = objData::Edit("agen_aktifasi",$vaEdit,"HP = '$cHP' and Agen = '$cAgen'");
				
				# Log
				
			}else 
			# REJECT
			if($cAc=="02"){
				$vaEdit = array(
					"Status"				=> "2",
					"DeviceMaster"	=> $cSIMSerial,
					"DateTime"			=> SNow()
				);
				$dbData = objData::Edit("agen_devicelog",$vaEdit,"HP = '$cHP' and Agen = '$cAgen' and SIMSerial = '$cDevice'");
				while($dbRow = objData::GetRow($dbData)){
					
				}
			}else
			# LOAD
			if($cAc=="00"){
				
				$dbData = objData::Browse("agen_devicelog","*","HP = '$cHP' and Agen = '$cAgen'");
				while($dbRow = objData::GetRow($dbData)){
					
				}
			}
			*/
			$vaResponse  = array("RC" => "XR", "MSG" => "URL tidak ditemukan");
		}else if ($cTrx == TRX_PRODUK) {
			if (isset($vaBody['JENIS']) == TRX_PRODUK_EMONEY) {
        $n = 0;
        $dbDataF = objData::Browse("stock_operator s", "s.Kode,s.Keterangan,s.Jenis,m.ABillerID", "s.Jenis = 'D'", array("left join mapp_de_transaksi m on s.Kode = m.Operator"), "s.Kode");
        while ($dbRowF = objData::GetRow($dbDataF)) {
          $vaResponse[++$n] = join("|", array($dbRowF['Kode'], $dbRowF['Keterangan'], $dbRowF['ABillerID']));
          $cResponse = "data terunduh";
        }
      } else {
        $dbData = objData::Browse("agen_smsbanking", "TrxKey,Agen,Nomor", $cWhere1);
        if ($dbRow = objData::GetRow($dbData)) {
          $cWhere   = "sh.Agen = '" . $dbRow['Agen'] . "'";
					
					//EDITAN RAFII
					if(isset($vaBody['JENISTRX'])){
						if($vaBody['JENISTRX'] != ''){
							$cJenisTrx = $vaBody['JENISTRX'];
							$cWhere .= " and mt.JenisTransaksi = '$cJenisTrx'";
							
							if($cJenisTrx == "12") $cWhere .= " and mo.Status = '1'";
						}
					}
					
					if(isset($vaBody['LIKE'])){
						if($vaBody['LIKE'] != ''){
							// Jika like lebih dari satu maka pisahkan dengan tanda '|'
							// format $vaBody['LIKE'] = 'field*record|field*record'
							$cLike 	= $vaBody['LIKE'];
							$vaLike	= explode("|",$cLike);
							for($x=0;$x<count($vaLike);$x++){
								$vaComp 	= explode("*",$vaLike[$x]);
								$cF		= $vaComp[0];
								$cR		= $vaComp[1];		
								// editan aldo
								if (strpos(strtolower($cR), "tfdana") !== false) {
    							$cWhere .= " and $cF like '%$cR'";
								} else {
    							$cWhere .= " and $cF like '%$cR%'";
								}
								
							}
						}
					}
					
					if(isset($vaBody['JENISTRX'])){
						if($vaBody['JENISTRX'] != ''){
							//var_dump($cWhere);
							$vaJoin   = array(
								"Left Join mapp_de_operator mo on mo.Kode = mt.Operator
								Left Join stock s On s.Kode = mt.Kode_Stock
								Left Join operator_nomor o On o.Operator = s.Operator
								Left Join stock_hj_agen sh On sh.Kode = s.Kode"
							);
						}
					}else{
						$vaJoin   = array("Left Join mapp_de_operator mo on mo.Kode = mt.Operator Left Join stock s On s.Kode = mt.Kode_Stock Left Join  stock_hj_agen sh On sh.Kode = s.Kode");
					}
					
					$cGroupBy = "";
					if(isset($vaBody['GROUPBY'])){
						if($vaBody['GROUPBY'] != ''){
							$cGB = $vaBody['GROUPBY'];
							$cGroupBy	.= "$cGB";
						}
					}
					//$cWhere .= " AND s.Status_Aktif = '0'";
					
					$cField   = "mt.Kode_Stock,mt.ABillerID,mt.AProductID,mt.Keterangan,mt.Nominal,mt.JenisTransaksi,mo.Kode,mo.Keterangan as KetOperator,s.HB, s.Margin As MarginStock, s.HJ as HJStock, s.Admin as AdminStock, sh.HJ as HJAgen, sh.Margin as MarginAgen, sh.Discount";
          $dbData   = objData::Browse("mapp_de_transaksi mt", $cField, $cWhere, $vaJoin, $cGroupBy, "ABilleriD,AProductID asc");
					$nNominal = 0;
          $n = 0;
					$cCIFSHA	= $dbRow['Agen'];
          while ($dbRow = objData::GetRow($dbData)) {
						if (strpos(strtolower($dbRow['Kode_Stock']), "inq") !== false) {
							$cKSHA 		= str_replace("INQ","PAY",$dbRow['Kode_Stock']);
							$dbSHA = objData::Browse("stock_hj_agen","Margin","Agen = '$cCIFSHA' and Kode = '$cKSHA'");
							if($drSHA = objData::GetRow($dbSHA)){
								$dbRow['MarginAgen'] = $drSHA['Margin'];
							}
						}
						
						$nNominal = $dbRow['HJStock'] + $dbRow['MarginAgen'];
						$nAdminAg	= $dbRow['AdminStock'] + 0;
						$vaResponse[++$n] = join("|", array($dbRow['Kode'], $dbRow['Kode_Stock'], $dbRow['KetOperator'], $dbRow['ABillerID'], $dbRow['AProductID'], $dbRow['Keterangan'], $nNominal, $dbRow['JenisTransaksi'],$dbRow['MarginAgen'],$nAdminAg));
						$cResponse = "data terunduh";
					}
					
					# masuk ke digital log
					$vaArrs = array(
						"DateTime" 	=> SNow(),
						"Message"		=> json_encode($vaBody),
						"IPAddress"	=> "Digital"
					);
					objData::Insert("digital_log", $vaArrs,false);
					//objData::Insert("digital_log", json_encode($vaResponse));
        }
      }
    } else if($cTrx == TRX_LOGIN_APP){
      $cResponse  = "";
      $vaResponse = array("MTI"=>DIGITAL_BANK_AMBILDATA,"RC"=>"XT","MSG"=>"");
      $cHP        = MBankingFunc::KodeNegara($vaBody['HP']);
      if(!empty($cHP) && !empty($cAgen) && !empty($cSim)){
        $cWhere = "HP='{$cHP}' AND Agen='{$cAgen}' AND SIMSerial='{$cSim}'";
        $dbData = objData::Browse("agen_aktifasi","Email",$cWhere);
        if($dbRow = objData::GetRow($dbData)){
          $vaResponse["RC"] = "00";
          $cResponse        = "Data akun atau perangkat terdaftar.";
          $cEmail           = $dbRow["Email"];
          if(!empty($cEmail)){
            $dDT       = date("Y-m-d H:i:s");
            $vaBank    = getDataAgen($cAgen);
            $cMessage  = "Terima kasih Anda telah menggunakan fasilitas digital dari {$vaBank["BANK"]}. Berikut merupakan informasi transaksi yang telah Anda lakukan:\n\n";
            $cMessage .= "<pre style='font-size:1.1em'>";
            $cMessage .= "Waktu     : $dDT\n";
            $cMessage .= "Aktivitas : Login\n";
            $cMessage .= "Status    : Berhasil\n\n";
            $cMessage .= "</pre>";
            $cMessage  = rawurlencode($cMessage);
            $vaInsert  = array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cEmail,"Subject"=>"Login Informasi","Message"=>$cMessage,"UserName"=>"mBanking");
            $cTRX      = objData::Insert("notifemail_sent",$vaInsert,false);
            if($cTRX != "1") $cResponse .= " Notifikasi email tidak dapat dikirimkan, ".objData::Error.".";
          }
        }else{
          $cResponse = "Data akun atau perangkat belum terdaftar.";
        }
      }else{
        $cResponse = "Data akun atau perangkat belum terdaftar.";
      }
      $vaResponse["MSG"] = $cResponse;
    } else if ($cTrx == TRX_STATUS_FITUR) {
      $dbData   = objData::Browse("agen_smsbanking", "SIMSerial", $cWhere1);
      if ($dbRow = objData::GetRow($dbData)) {
        $dbD = objData::Browse("agen_fitur", "*", "Kode = '" .  substr($dbRow['SIMSerial'], 0, 4) . "'");
        $vaResponse[0] = substr($dbRow['SIMSerial'], 0, 4);
        if ($dbr = objData::GetRow($dbD)) {
          $vaResponse[0] = join("|", array($dbr['MB_Pulsa'], $dbr['MB_BPJS'], $dbr['MB_PLN'], $dbr['MB_PDAM'], $dbr['MB_Telepon'], $dbr['MB_Cicilan'], $dbr['MB_Entertaiment']));
        }
      }
    } else if ($cTrx == TRX_GET_ADDITIONAL_PRODUCT) {
      $cRC        = "01";
      $cResponse  = "Data lain tidak ditemukan";
      $cBiller    = isset($vaBody['ABillerID']) ? $vaBody['ABillerID'] : "";
      $cProdukID  = isset($vaBody['AProductID']) ? $vaBody['AProductID'] : "";
      $dbData   = objData::Browse("mapp_de_transaksi", "JProductID", "ABillerID = '$cBiller' and AProductID = '$cProdukID'");
      if ($dbRow = objData::GetRow($dbData)) {
        $cRC       = "00";
        $cResponse = $dbRow["JProductID"];
      }
      $vaResponse[0] = join("|", array($cRC, $cResponse));
    } else if ($cTrx == TRX_PREFIX) {
      $n      = 0;
      $cField = "k.Kode,k.Operator,o.Kode as KodeOperator,o.Keterangan";
      $vaJoin = array("left join mapp_de_operator o on o.Kode = k.Provider");
      $dbData = objData::Browse("mapp_de_kodeprefix k", $cField, "", $vaJoin);
      while ($dbRow = objData::GetRow($dbData)) {
        $vaResponse[++$n] = join("|", array($dbRow['Kode'], $dbRow['Operator'], $dbRow['KodeOperator']));
        $cResponse = "data operator terunduh";
      }
    } else if ($cTrx == TRX_CEK_EMAIL_AKTIVASI) {
      /*$vaResponse  = array("RC"=>"19", "MSG"=>"");
        $cNoHP       = $mBanking->KodeNegara($vaBody['HP']) ;
        $cSIMSerial  = $vaBody['SIMSerial'] ;
        $dbData      = $objData->Browse("agen_aktifasi","Email","HP = '$cNoHP' and SIMSerial = '$cSIMSerial'") ;
        if($dbRow = $objData->GetRow($dbData)){
          $vaResponse = array("RC"=>"00", "MSG"=>$dbRow['Email']);
        }*/
      $vaResponse = array("RC" => "00", "MSG" => "");
    } else if ($cTrx == TRX_GET_TOKEN) {
			$cCif					= isset($vaBody['CIF']) ?$vaBody['CIF'] : '';
			if($cAgen == "A-000190"){
				if (strpos($cCif, '.') !== false){
					
				}else{
					$cCif = substr($cCif,0,3) . "." . substr($cCif,3);
				}
			}
      $cNoHP        = $vaBody['HP'];
      $cSIMSerial   = $vaBody['SIMSerial'];
      // $cEmail       = "";
      $cEmail 			= isset($vaBody['EMAIL']) ? $vaBody['EMAIL'] : ""; # penambahan cek email (rafi aldo)
			$lSendMSG     = isset($vaBody['SENDMSG']) ? $vaBody['SENDMSG'] : true; # kirim pemberitahuan via CBS (fanani)
			$lWABlast     = isset($vaBody['WABLAST']) ? $vaBody['WABLAST'] : "0"; # kirim pemberitahuan via CBS (fanani)
      $lSMSMasking  = isset($vaBody['SMS_MASKING']) ? $vaBody['SMS_MASKING'] : false; # kirim SMS menggunakan SMS masking (fanani)
      $lSMSFromCBS  = false;
      $lEmailFromCBS  = isset($vaBody['EMAILFROMCBS']) ? $vaBody['EMAILFROMCBS'] : false; # kirim Email menggunakan CBS (ODY)
      $cHP          	= MBankingFunc::KodeNegara($cNoHP); 
      // $cWhere       = "HP = '$cHP'";
			$cWhere       	= "HP = '$cHP' "; # penambahan cek email (aldo)
      if($cAgen != "") $cWhere .= " and Agen = '$cAgen'";
			if($cEmail != "" && $cAgen != "A-000268" ) $cWhere .= " and Email = '$cEmail'"; // sementara untuk if agen (anjuk ladang)
			if($cCif != '') $cWhere .= " and KodeCIF = '$cCif'";
      $dbData       = objData::Browse("agen_aktifasi", "HP,SIMSerial,Aktif,Email,CS", $cWhere);
      
      if ($dbRow = objData::GetRow($dbData)) {
        if ($dbRow['SIMSerial'] <> "" && $dbRow['Aktif'] == "1") {
          $cRC        = "01";
          $cResponse  = "Maaf, nomor HP : $cNoHP telah didaftarkan, cek kembali nomor anda.";
        } else if ($dbRow['Aktif'] == '0' && $dbRow['CS'] == '1') {
          $nToken     = rand(100000, 999999);
          $dbData     = objData::Edit("agen_aktifasi", array("mBankingToken" => $nToken, "SIMSerial" => $cSIMSerial, "DateTimeOTP" => SNow()), $cWhere);
          $cRC        = "00";

          // Penambahan terkait custom pemberitahuan (fanani)
          $cMessage   = "";
					/*
					if ($lSendMSG) {  # kirim pemberitahuan via CBS (fanani)
            $cAgenFitur = substr($cSIMSerial, 0, 4);
            $vaBodyReq["TRX"]    = "11";
            $vaBodyReq["Bank"]   = $cAgenFitur;
            $vaBodyReq["Config"] = ["msPemberitahuanToken"];
            $vaIsiReq["MTI"]     = "004";
            $vaIsiReq["MSG"]     = $vaBodyReq;
            $cIsiReq   = json_encode($vaIsiReq);
            $vaResBody = self::mBankingGetData($vaIsiReq["MSG"], $cIsiReq);
            if ($vaResBody["RC"] == "00") {
              $cMessage = $vaResBody["MSG"][0];
              $cMessage = str_replace("[cToken]", $nToken, $cMessage);
            }
          }
					*/
					
          if($lWABlast == "1"){
						$cURLWA 		= "";
						$cAgenFitur = substr($cSIMSerial, 0, 4);
						$dbAGF = objData::Browse("agen_fitur af", "a.URL", "af.Kode = '$cAgenFitur'", array("Left Join agen a on a.Kode = af.KodeAgen"));
						if($drAGF = objData::GetRow($dbAGF)){
							$cURLWA = $drAGF['URL'];
						}
						if($cURLWA==""){
							$vaMSG	= array(
								"TRX"			=> "",
								"Bank"		=> $cAgenFitur,
								"HP"			=> $cNoHP,
								"OTP"			=> $nToken
							);
							$vaBody	= array(
								"MTI" => "MTI",
								"MSG"	=> $vaMSG
							);
							$cBody			= json_encode($vaBody);
							$cURL     	= $cURLWA;
							$cMessage		= "cCode=" . $cBody;
							$cRes  			= SendHTTPPost($cURL, $cMessage);
							$vaResponse = json_decode($cRes,1);

							$cResponse = ($vaResponse["RC"] == "00") ? "Berhasil. Token akan dikirimkan via WhatsApp. Mohon tunggu beberapa menit." : "Token tidak berhasil dikirimkan via WhatsApp";
						}else{
							$cResponse = "Maaf, gagal mengirim OTP. Hubungi customer service untuk pengaduan.";
						}
					}else if ($lSMSMasking) {
						$cKDSM = substr($cSIMSerial,0,4);
						if($cAgen == "A-000172" || $cKDSM=="0055"  || $cAgen == "A-000153" || $cKDSM=="0059"){
							// Insert MHY Demo
							$cAgen = GetKeterangan($cKDSM,"KodeAgen","agen_fitur");
							$dDT       = date("Y-m-d H:i:s");
							$cMessage = "Token untuk akun digital mobile anda: " . $nToken . ". Jangan berikan token ke orang lain..";
							objData::Insert("token_digi_log",array("CTO"=>$cHP,"CFROM"=>$cAgen,"CBD"=>$cMessage,"DT"=>$dDT),false);
							
							$cResponse  = "Tunggu ya, Kode akan dikirim dalam beberapa menit. [cek pada menu log]";
						}else{
							$cNoHP = MBankingFunc::FormatNoHP($cHP);
							$vaBank = getDataAgen($cAgen);
							$cMessage = "Token untuk akun digital mobile anda: " . $nToken . ". Jangan berikan token ke orang lain";
							$vaDataMasking = array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "1", "AGEN" => $cAgen, "RES_MSG" => "Berhasil. Token akan dikirimkan via SMS. Mohon tunggu beberapa menit.");
							$cSMSMasking = SendSMSMasking($vaDataMasking);
							$vaSMSMasking = json_decode($cSMSMasking, true);
							$cResponse = $vaSMSMasking['text'];
						}
						/*
            $cNoHP = MBankingFunc::FormatNoHP($cHP);
            $vaBank = getDataAgen($cAgen);
            $cMessage = "Token untuk akun digital mobile anda: " . $nToken . ". Jangan berikan token ke orang lain";
            $vaDataMasking = array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "1", "AGEN" => $cAgen, "RES_MSG" => "Berhasil. Token akan dikirimkan via SMS. Mohon tunggu beberapa menit.");
            $cSMSMasking = SendSMSMasking($vaDataMasking);
            $vaSMSMasking = json_decode($cSMSMasking, true);
            $cResponse = $vaSMSMasking['text'];
						*/
          } else {
            if ($dbRow['Email'] <> "") {
              $vaBank     = getDataAgen($cAgen);
              $cEmail     = $dbRow['Email']; // penambahan fanani
              $cTujuan    = $dbRow['Email'];
              // Penambahan terkait custom pemberitahuan (fanani)
              if ($cMessage == "") $cMessage = "Token untuk akun anda: " . $nToken . ". Jangan berikan token ke orang lain";
              if ($lSendMSG) {  
								# kirim pemberitahuan via CBS (fanani)
								$dDT       = date("Y-m-d H:i:s");
								$cMessage  = rawurlencode($cMessage);
								$vaInsert  = array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cTujuan,"Subject"=>"Kode Aktivasi mBanking","Message"=>$cMessage,"UserName"=>"mBanking");
								$cTRX      = objData::Insert("notifemail_sent",$vaInsert,false);
              } else {
                $lSMSFromCBS = true;
              }
              $cResponse  = "Berhasil. Token akan dikirimkan via Email. Mohon tunggu beberapa menit.";
            } else {
              // kirim sms token ke nasabah
              $cNoHP     = MBankingFunc::FormatNoHP($cNoHP);
              $cNoModem  = "08563606500"; //$mBanking->GetNoModem($cNoHP);
              // Penambahan terkait custom pemberitahuan (fanani)
              if ($cMessage == "") $cMessage  = "Token untuk akun anda: " . $nToken;
              if ($lSendMSG) {  # kirim pemberitahuan via CBS (fanani)
                $vaField   = array("DateTime" => time(), "SMSTo" => $cNoHP, "IMEI" => $cNoModem, "Message" => $cMessage, "Priority" => 0);
                objData::Insert("sms_gateway_outbox", $vaField, false);
              } else {
                $lSMSFromCBS = true;
              }
              $cResponse  = "Berhasil. Token akan dikirimkan via SMS. Mohon tunggu beberapa menit.";
            }
						
            if ($lSMSFromCBS) {
              $cAgenFitur = substr($cSIMSerial, 0, 4);
              $vaBodyReq["TRX"]     = "68";
              $vaBodyReq["Bank"]    = $cAgenFitur;
              $vaBodyReq["Message"] = $cMessage;
              $vaBodyReq["HP"]      = $cNoHP;
              $vaBodyReq["OTP"]     = $nToken;
              $vaBodyReq["SMSRegular"] = false;
              $vaIsiReq["MTI"]     = "004";
              $vaIsiReq["MSG"]     = $vaBodyReq;
              $cIsiReq   = json_encode($vaIsiReq);
              $cResult   = self::mBankingGetData($vaIsiReq["MSG"], $cIsiReq);
              $vaResult  = json_decode($cResult, true);
              $cResponse = ($vaResult["RC"] == "00") ? "Berhasil. Token akan dikirimkan via SMS. Mohon tunggu beberapa menit." : "Token tidak berhasil dikirimkan via SMS";
            }
          }
        }else{
					$cRC        = "02";
        	$cResponse  = "Belum menyelesaikan pembukaan fasilitas. Silakan ke CS Bank terlebih dahulu.";
				}
      } else { 
        $cRC        = "02";
        $cResponse  = "Maaf, nomor HP : $cNoHP / email : $cEmail / CIF : $cCif tidak terdaftar";
        if(strpos($cSIMSerial,"860048407523085088") > 0 || 
           strpos($cSIMSerial,"898600484075230850") > 0 || 
           strpos($cSIMSerial,"62115937613921780") > 0) $cResponse = "We know exactly who you are."; 
      }
      $vaResponse[0] = join("|", array($cRC, $cResponse, $cEmail));
    } else if ($cTrx == TRX_GET_TRX) {
      $dTgl  = explode(" ", $vaBody['Tgl']);
      if (isset($vaBody['Kode'])) {
        if ($vaBody['Kode'] == "11") {
          $cKode = "PAYFINANCE";
        } else {
          $cKode = $vaBody['Kode'];
        }
      }

      $cIDPel = $vaBody['IDPel'];
      $va     = explode("-", $dTgl[0]);
      $dTgl   = join("-", array($va[0], str_pad($va[1], 2, "0", STR_PAD_LEFT), str_pad($va[2], 2, "0", STR_PAD_LEFT)));
      $dTgl   = Date2String($dTgl);

      $cWhereField    = MBankingFunc::GetIDPelanggan($cKode);
      $cWhereTambahan = " and $cWhereField = '$cIDPel'";
      if (isset($vaBody['TrxID'])) {
        $cWhereTambahan = " and IDTRXOrder = '" . $vaBody['TrxID'] . "'";
      }

      $dbData  = objData::Browse("pulsa_penjualan", "TRXOrderResponse,AdditionalData,Status,SN,HJ_Nasabah,IDTRXOrder,Supplier", "Tgl = '$dTgl' and Kode = '$cKode' $cWhereTambahan");
      if ($dbRow = objData::GetRow($dbData)) {
        $cData  = $dbRow['TRXOrderResponse'];
        $vaData   = str_replace("\\", "", $cData);
        $vaData2  = (array) json_decode($vaData, true);
        $cTotal   = $dbRow['HJ_Nasabah'];
        $cTrxID   = $dbRow['IDTRXOrder'];
        $cStatus  = $dbRow['Status'];
        $cSN      = $dbRow['SN'];
        $cAdditional    = $dbRow['AdditionalData'];
        $vaAdditional   = str_replace("\\", "", $cAdditional);
        $vaAdditional2  = (array) json_decode($vaAdditional, true);
        $cResponse = MBankingFunc::GetDataFastpay($vaData2, $cTotal, $cTrxID, $cStatus, $cKode, $cSN, $vaAdditional2, $dbRow['Supplier']);
      } else {
        // jika data tidak ada
        $cResponse = "Data tidak ada";
      }
      $vaResponse[0] = $cResponse;
    } else if ($cTrx == TRX_GET_KODE_BANK) {
			$cKodeAgn = substr($cSim, 0, 4);
			$cKodeBankTFOB 	= "";
			$cPermataSNAPTF	= "";
			$cDanamonSNAPTF	= "";
			$dbDataRQ  = objData::Browse("agen_smsbanking","Agen",$cWhere1);
			if ($dbRowRQ = objData::GetRow($dbDataRQ)) {
				$cRQKodeAgen = $dbRowRQ["Agen"];
				if (!empty($cRQKodeAgen)) {
					# periksa apakah layanan SNAP TF Bank Permata
					$vaConfigAgen 	= GetKonfigurasiAgen($cRQKodeAgen);
					$cKodeBankTFOB	= isset($vaConfigAgen["KodeBankTFOB"]) ? trim($vaConfigAgen["KodeBankTFOB"]) : "";
					$cPermataSNAPTF = isset($vaConfigAgen["PermataSNAPTF"]) ? $vaConfigAgen["PermataSNAPTF"] : "";
					$cDanamonSNAPTF = isset($vaConfigAgen["DanamonSNAPTF"]) ? $vaConfigAgen["DanamonSNAPTF"] : "";
				}
			}
			
      $n = 0;
      $dbData  = objData::Browse("bank_code","Kode,Nama,BankPermataID,NamaAlias,KodeBIFAST,TransferOnline,RTGS,LLG","Tampil = '1'");
      while ($dbRow = objData::GetRow($dbData)) {
				$cMetodeTFOnline 	= $dbRow['TransferOnline'];
				$cKodeBIFast   		= $dbRow['KodeBIFAST'];
				$cKodeBank   			= $dbRow['Kode'];
				$cBankTFOB				= "T"; # set TRUE, jika transfer over booking
				$cKodeKliring			= $dbRow["BankPermataID"];
				
				# jika layanan SNAP TF
				if ($cPermataSNAPTF == "1" || $cDanamonSNAPTF == "1") {
					# jika kode "", set transfer online jadi "T"
					if (empty($dbRow["Kode"])) $cMetodeTFOnline = "T";
					
					//if ($cKodeBIFast == aCfg("msKodeBIFASTPermata")) $cKodeBIFast = "";
					if ($cKodeBIFast == $cKodeBankTFOB) $cKodeBIFast = "";
					if ($dbRow['Kode'] == $cKodeBankTFOB) $cBankTFOB = "Y";
					if ($cPermataSNAPTF == "1") {
						if ($cKodeBank == $cKodeBankTFOB) $cKodeBIFast = "";
						$dbRow['RTGS'] = "T";
						$dbRow['LLG'] = "T";
						// Kode bank yang ditutup di permata bifast/llg/rtgsnya
						if($cKodeBank=="114"){
							//$cMetodeTFOnline	= "T";
							$dbRow['RTGS']		= "T";
							$dbRow['LLG']			= "T";
							//$cKodeBIFast 		= "";
						}
					}
					if ($cDanamonSNAPTF == "1") {
						if ($cKodeBank == $cKodeBankTFOB) $cKodeBIFast = "";
						$cKodeKliring = $cKodeBIFast;
					}
					# Menutup salah satu metode dengan hardcode, butuh kodeAgen saja
					//if($cKodeAgn=="0072"){
						//$cMetodeTFOnline	= "T";
					//}
				}
				
        $vaResponse[++$n] = join("|", array($dbRow['Kode'],$dbRow['Nama'],$cKodeKliring,$dbRow['NamaAlias'],$cKodeBIFast,$cMetodeTFOnline,$dbRow['RTGS'],$dbRow['LLG'],$cBankTFOB));
        $cResponse = "data kode bank terunduh";
      }
    } else if ($cTrx == TRX_GET_BERITA || $cTrx == TRX_GET_BROSUR || $cTrx == TRX_GET_CONFIG || $cTrx == TRX_GET_SYARAT ||
               $cTrx == TRX_GET_INFO_BANK || $cTrx == TRX_GET_PROMO || $cTrx == TRX_GET_POINT_PROMO || $cTrx == TRX_SEND_SMS_CBS || $cTrx == TRX_GET_RIWAYAT_PPOB) {
      
			if (isset($vaBody['Bank'])) {
        $cKodeBank  = $vaBody['Bank'];
        $cField     = "af.*,a.*";
        $cWhere     = "af.Kode = '$cKodeBank'";
        $vaJoin = array("Left Join agen a on a.Kode = af.KodeAgen");
        $dbData = objData::Browse("agen_fitur af", $cField, $cWhere, $vaJoin);
      }else if (isset($vaBody['SIMSerial'])) {
        $cSIMSerial = $vaBody['SIMSerial'];
        $cField     = "s.*,a.*";
        $cWhere     = "s.SIMSerial = '$cSIMSerial'";
        $vaJoin = array("Left Join agen a on a.Kode = s.Agen");
        $dbData = objData::Browse("agen_smsbanking s", $cField, $cWhere, $vaJoin);
      } 
			
      if ($dbRow = objData::GetRow($dbData)) {
        $cAgen    	= isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
				$cTrxKey    = isset($dbRow['TrxKey']) ? $dbRow['TrxKey'] : "";
				$cParseKey 	= $cAgen . $cTrxKey;
        $cURL     	= $dbRow['URL'];
        $cUserH2H 	= $dbRow['UserH2H'];
        $cMessage   = "cCode=" . $cBody;
				
				$vaHeaders 	= array(
					"X-Assist-Key" 	=> $cParseKey,
					"X-User-Agen"		=> "Assistindo_Switching_V3"
				);
				
				$cResponse  = SendHTTPPost($cURL, $cMessage,'', false, $vaHeaders);
        $cResponse  = ltrim($cResponse);
        $vaResponse = json_decode($cResponse, true);
				
				if(isset($vaBody['DATA'])){
					if($vaBody['DATA']=="transfer_online"){
						$cFeeTF 	= $dbRow['FeeSNAPTF'];
						$lFeeTG 	= $dbRow['FeeBertingkat'];
						$vaFeeTF 	= empty($cFeeTF) ? array() : json_decode(str_replace('\\', '',$cFeeTF),true);
						foreach ($vaResponse['MSG']['biaya_transaksi'] as $key => $value) {							
							if (array_key_exists(str_replace('INQ', '',$key), $vaFeeTF)) {
        					$vaResponse['MSG']['biaya_transaksi'][$key]['Fee'] = $vaFeeTF[str_replace('INQ', '',$key)];
							} else {
        					$vaResponse['MSG']['biaya_transaksi'][$key]['Fee'] = 0;
							}
						}
						if($lFeeTG == "1"){
							$vaResponse['MSG']['fee_bertingkat'] = json_decode(str_replace('\\', '',$dbRow['FeeBertingkatVal']),true);
						}else{
							$vaResponse['MSG']['fee_bertingkat'] = "";
						}
					}
				}
      } else {
        $vaResponse  = array("RC" => "XR", "MSG" => "URL tidak ditemukan");
      }
    } else if ($cTrx == TRX_GET_CONFIG) {
      if (isset($vaBody['Bank'])) {
        $cKodeBank  = $vaBody['Bank'];
        $cField     = "af.*,a.*";
        $cWhere     = "af.Kode = '$cKodeBank'";
        $vaJoin = array("Left Join agen a on a.Kode = af.KodeAgen");
        $dbData = objData::Browse("agen_fitur af", $cField, $cWhere, $vaJoin);
      }else if (isset($vaBody['SIMSerial'])) {
        $cSIMSerial = $vaBody['SIMSerial'];
        $cField     = "s.*,a.*";
        $cWhere     = "s.SIMSerial = '$cSIMSerial'";
        $vaJoin = array("Left Join agen a on a.Kode = s.Agen");
        $dbData = objData::Browse("agen_smsbanking s", $cField, $cWhere, $vaJoin);
      }

      if ($dbRow = objData::GetRow($dbData)) {
        $cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
        $cURL     = $dbRow['URL'];
        $cUserH2H = $dbRow['UserH2H'];
        $cMessage   = "cCode=" . $cBody;
        $cResponse  = SendHTTPPost($cURL, $cMessage);
        //echo "asem " . $cResponse;
        $cResponse  = ltrim($cResponse);
        $vaResponse = json_decode($cResponse, true);
      } else {
				$vaResponse  = array("RC" => "XR", "MSG" => "URL tidak ditemukan");
      }
    } else if ($cTrx == TRX_CHECK_ACTIVE_ACCOUNT) {
      $cNomor   = $vaBody['HP'];
      $cAgen    = $vaBody['AGEN'];
      $lSendMSG   	= isset($vaBody['SENDMSG']) ? $vaBody['SENDMSG'] : true; # kirim pemberitahuan via CBS (fanani)
      $lSMSMasking 	= isset($vaBody['SMS_MASKING']) ? $vaBody['SMS_MASKING'] : false; # kirim SMS menggunakan SMS masking (fanani)
      $lWABLAST = isset($vaBody['WABLAST']) ? $vaBody['WABLAST'] : "0"; 
      $cVVersi 	= isset($vaBody['VERSI']) ? $vaBody['VERSI'] : "";
			
      # true jika menggunakan OTP bukan dynamic link
      $lLoginOTP = isset($vaBody['LOGIN_OTP']) ? $vaBody['LOGIN_OTP'] : false;
      
			# masuk ke digital log
			//$vaArrsd = json_encode($vaBody);
			objData::Insert("digital_log",$vaBody,false);
			
      $lSMSFromCBS = false;
      $lEmailFromCBS  = isset($vaBody['EMAILFROMCBS']) ? $vaBody['EMAILFROMCBS'] : false; # kirim Email menggunakan CBS (ODY)
      $dbCek    = objData::Browse("agen_smsbanking", "Nomor,SIMSerial,Agen", "Nomor = '$cNomor' and Agen = '$cAgen' and Jenis = 'M'");
      if ($rwCek = objData::GetRow($dbCek)) {
        $cURL      = isset($vaBody['DATA']) ? $vaBody['DATA'] : "";
        if($cURL=="CEKAKTIVASI"){ 
					// ODY
          $cRC        = "00";
          $cResponse  = "Sudah Aktif";
					$cSIMSer  = isset($vaBody['SIMSerial']) ? $vaBody['SIMSerial'] : "";
					if($cSIMSer != $rwCek['SIMSerial']){
						$cRC = "XR";
						$cResponse  = "Tidak Aktif";
					}
          if($cRC == "00"){
            $cAgen      = isset($vaBody['AGEN']) ? $vaBody['AGEN'] : "";
            $cBMTI      = isset($vaBody['MTI']) ? $vaBody['MTI'] : "004";
            
            $cBMSG  = array(
              "TRX"         => $vaBody['TRX'],
              "FIREBASEID"  => $vaBody['FIREBASEID'],
              "CIF"         => $vaBody['CIF'],
              "SIMSerial"   => $cSIMSer
            );
            
            if($cAgen != ""){
              $vaB  = array(
                "MTI" => $cBMTI,
                "MSG" => $cBMSG
              );
              
              $cBody      = json_encode($vaB);
              $cURL       = GetKeterangan($cAgen,"URL","agen");
              $cMessage   = "cCode=" . $cBody;
              $cResponse  = SendHTTPPost($cURL, $cMessage);
              $cResponse  = ltrim($cResponse);
							//$cRC				= $cResponse;
              //$cResponse  = json_encode($cResponse);
            }else{
              $cRC        = "XR";
              $cResponse  = "Tidak Aktif";
            }
          }
        }else{
          $cTujuan   = isset($vaBody['EMAIL']) ? strtolower($vaBody['EMAIL']) : ""; # 06-03-21 Fanani
					if($cVVersi=="V2"){
						if($cAgen == "A-000128"){
							# V2 digunakan ketika ada device ID Berbeda ketika aktivasi ulang
							$cSIMSer  = isset($vaBody['SIMSerial']) ? $vaBody['SIMSerial'] : "";
							$cWhere   = "Nomor = '$cNomor' and Agen = '$cAgen'";
							objData::Edit("agen_smsbanking",array("SIMSerial"=>$cSIMSer),$cWhere);

							$cWhere   = "HP = '".FormatNoHPmBanking($cNomor)."' and Agen = '$cAgen'";
							$cWhere		.= " and Email = '" . $cTujuan . "'";
							objData::Edit("agen_aktifasi",array("SIMSerial"=>$cSIMSer),$cWhere);
							/*
							$cSIMSer  = isset($vaBody['SIMSerial']) ? $vaBody['SIMSerial'] : "";
							$cWhere   = "Nomor = '$cNomor' and Agen = '$cAgen'";
							objData::Edit("agen_smsbanking",array("SIMSerial"=>$cSIMSer),$cWhere);

							$cWhere   = "HP = '".FormatNoHPmBanking($cNomor)."' and Agen = '$cAgen'";
							$cWhere		.= " and Email = '" . $cTujuan . "'";
							objData::Edit("agen_aktifasi",array("SIMSerial"=>$cSIMSer),$cWhere);
							*/
						}
					}
					
          if($lLoginOTP){
            # buat token untuk login
            $nLoginOTP      = rand(100000, 999999);
            $cWhere         = "Nomor = '$cNomor' and Agen = '$cAgen'";
						//$cSIMS					= $rwCek[''];
            objData::Edit("agen_smsbanking",array("LoginOTP"=>$nLoginOTP),$cWhere);
						$cHPAA      = substr($cNomor,2);
						objData::Edit("agen_aktifasi",array("DateTimeOTP"=>SNow()),"Jenis = 'M' and Agen = '$cAgen' and HP like '%$cHPAA%'");
          
            $cMessage       = "Gunakan kode $nLoginOTP untuk melanjutkan login. Jangan berikan kode OTP ke siapapun";
            $cMessageEmail  = '<html lang="en"><head><meta charset="UTF-8">
						<meta name="viewport" content="width=device-width, initial-scale=1.0">
						<title>Verifikasi Email</title></head><body><h2>Verifikasi Email</h2>
						<p>Gunakan kode <strong>' . $nLoginOTP . '</strong> untuk melanjutkan login.</p></body></html>';
          }else{
            $cMessage  = "Buka link ini untuk melanjutkan login. $cURL ";
            $cMessageEmail = '<html lang="en"><head><meta charset="UTF-8">
						<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Verifikasi Email</title>
						</head><body><h2>Verifikasi Email</h2><p>Buka link ini melalui perangkat selular (handphone) anda untuk melanjutkan proses verifikasi email.</p>
            <a href="' . $cURL . '" style="text-decoration:none;">
						<button style="width: 100%;border: none;color: white;padding: 10px 20px;text-align: center;display: block;
						font-size: 16px;font-weight: bold;background-color: #04ABF3;">Verifikasi Email</button></a></body></html>';
          }
					
          if($lWABLAST == "1"){
						$cURLWA       = GetKeterangan($cAgen,"URL","agen");
						if($cURLWA==""){
							$vaMSG	= array(
								"TRX"			=> "",
								"Bank"		=> $cAgen,
								"HP"			=> $cNomor,
								"OTP"			=> $nLoginOTP
							);
							$vaBody	= array(
								"MTI" => "MTI",
								"MSG"	=> $vaMSG
							);
							$cBody			= json_encode($vaBody);
							$cURL     	= $cURLWA;
							$cMessage		= "cCode=" . $cBody;
							$cRes  			= SendHTTPPost($cURL, $cMessage);
							$vaResponse = json_decode($cRes,1);

							$cResponse = ($vaResponse["RC"] == "00") ? "Berhasil. Token akan dikirimkan via WhatsApp. Mohon tunggu beberapa menit." : "Token tidak berhasil dikirimkan via WhatsApp";
						}else{
							$cResponse = "Maaf, gagal mengirim OTP. Hubungi CS untuk pengaduan";
						}
					}else if ($lSMSMasking) {
						if($cAgen == "A-000172"|| $cAgen == "A-000153"){
							// Insert MHY
							$dDT       = date("Y-m-d H:i:s");
							objData::Insert("token_digi_log",array("CTO"=>$cNomor,"CFROM"=>$cAgen,"CBD"=>$cMessage,"DT"=>$dDT),false);
            	$cRC        = "00";
							$cResponse  = "Tunggu ya, Kode akan dikirim dalam beberapa menit. [cek pada menu log]";
						}else{
							$cNoHP = MBankingFunc::FormatNoHP($cNomor);
							$vaBank = getDataAgen($cAgen);
							$vaDataMasking = array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "1", "AGEN" => $cAgen, "RES_MSG" => "Tunggu ya, SMS akan dikirim dalam beberapa menit.");
							$cSMSMasking = SendSMSMasking($vaDataMasking);
							$vaSMSMasking = json_decode($cSMSMasking, true);
							$cRC = ($vaSMSMasking['status'] == "SUKSES") ? "00" : "XR";
							$cResponse = $vaSMSMasking['text'];
						}
						/*
            $cNoHP = MBankingFunc::FormatNoHP($cNomor);
            $vaBank = getDataAgen($cAgen);
            $vaDataMasking = array("HP" => $cNoHP, "MSG" => $cMessage, "OTP" => "1", "AGEN" => $cAgen, "RES_MSG" => "Tunggu ya, SMS akan dikirim dalam beberapa menit.");
            $cSMSMasking = SendSMSMasking($vaDataMasking);
            $vaSMSMasking = json_decode($cSMSMasking, true);
            $cRC = ($vaSMSMasking['status'] == "SUKSES") ? "00" : "01";
            $cResponse = $vaSMSMasking['text'];
						*/
          } else {
            // kirim sms berisi link untuk verifikasi 
            $cNoModem  = "08563606500"; //$mBanking->GetNoModem($cNoHP);
            $vaField   = array("DateTime" => time(), "SMSTo" => $cNomor, "IMEI" => $cNoModem, "Message" => $cMessage, "Priority" => 0);
            objData::Insert("sms_gateway_outbox", $vaField,false);

            // kirim email karena sms tidak terkirim
            $vaBank     = getDataAgen($cAgen);
            $cRC        = "00";
            $cResponse  = "Tunggu ya, email akan dikirim dalam beberapa menit.";
            if ($lSendMSG) {
              $cWhere = "HP = '".FormatNoHPmBanking($cNomor)."' and Agen = '$cAgen'";
              if($cTujuan <> "") $cWhere .= " and Email = '" . $cTujuan . "'"; # 06-03-21 Fanani
              $dbCekEmail= objData::Browse("agen_aktifasi","HP,Email",$cWhere);
              if($rwCekEmail = objData::GetRow($dbCekEmail)){
                $cEmail = $rwCekEmail['Email'];
                if($cEmail == $cTujuan && $cEmail <> ""){
									$dDT       = date("Y-m-d H:i:s");
									$cMessageEmail  = rawurlencode($cMessageEmail);
									$vaInsert  = array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cTujuan,"Subject"=>"Verifikasi Akunmu","Message"=>$cMessageEmail,"UserName"=>"mBanking");
									$cTRX      = objData::Insert("notifemail_sent",$vaInsert,false);
                }else{
                  $cRC        = "XR";
                  $cResponse  = "Maaf, anda belum pernah daftar sebelumnya [03].";
                }
              }else if($cAgen == "A-000177"){
                $dDT       = date("Y-m-d H:i:s");
                $cMessageEmail  = rawurlencode($cMessageEmail);
                $vaInsert  = array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cTujuan,"Subject"=>"Verifikasi Akunmu","Message"=>$cMessageEmail,"UserName"=>"mBanking");
                $cTRX      = objData::Insert("notifemail_sent",$vaInsert,false);
              } else{
                $cRC        = "XR";
                $cResponse  = "Maaf, anda belum pernah daftar sebelumnya [02].";
              }
            } else {
              $lSMSFromCBS = false;
            }
            if ($lSMSFromCBS) {
              $cAgenFitur = substr($vaBody['SIMSerial'], 0, 4);
              $vaBodyReq["TRX"]     = "68";
              $vaBodyReq["Bank"]    = $cAgenFitur;
              $vaBodyReq["Message"] = $cMessage;
              $vaBodyReq["HP"]      = $cNomor;
              $vaBodyReq["SMSRegular"] = true;
              $vaIsiReq["MTI"]     = "004"; 
              $vaIsiReq["MSG"]     = $vaBodyReq;
              $cIsiReq   = json_encode($vaIsiReq);
              $cResult   = mBankingGetData($vaIsiReq["MSG"], $cIsiReq);
              $vaResult  = json_decode($cResult, true);
              $cResponse = ($vaResult["RC"] == "00") ? "Tunggu ya, SMS akan dikirim dalam beberapa menit." : "Tidak berhasil mengirimkan SMS";
            }
          }
        }
      } else {
        $cRC        = "XR";
        $cResponse  = "Maaf, anda belum pernah daftar sebelumnya [01].";
      }
      $vaResponse[0] = join("|", array($cRC, $cResponse));
    } else if ($cTrx == TRX_CHECK_LATEST_APP) {
      $cKode    = $vaBody['AGEN'];
      $dbVersi    = objData::Browse("agen_fitur", "MB_VersiApp", "Kode = '$cKode'");
      if ($rwVersi = objData::GetRow($dbVersi)) {
        $cRC        = "00";
        $cResponse  = $rwVersi['MB_VersiApp'];
      }
      $vaResponse[0] = join("|", array($cRC, $cResponse));
    } else if ($cTrx == TRX_CEK_AKUN_PERANGKAT) {
      $vaResponse = PeriksaAkunPerangkat($vaBody);
    } else if ($cTrx == TRX_GET_RIWAYAT_VA) {
      $n = 0;
      $cAgen      = $vaBody['AGEN'];
      $cHP        = $vaBody['HP'];
      $dTglAwal   = $vaBody['TGLAWAL'];
      $dTglAkhir  = $vaBody['TGLAKHIR'];
      
      # cek untuk transaksi yg belum dibayar / sudah tapi masih menunggu
      $dbData = objData::Browse("supplier_payment","*","Agen = '$cAgen' and HP = '$cHP' and (Tgl >= '$dTglAwal' and Tgl <= '$dTglAkhir') and Jenis = 'S' and Proses = 1","","","ID Desc");
      while ($dbRow = objData::GetRow($dbData)) {
        $cStatus = "0"; $cStatusKet = "Belum dibayar/Menunggu status"; $cFaktur = "";
        $cChannel = $dbRow['Channel'];
        # cek transaksi yg belum dibayar dan sudah kadaluarsa/lebih dari 2 jam
        $past = strtotime($dbRow['DateTime']);
        if((time()-$past) >= 7200){
          $cStatus = "2"; $cStatusKet = "Sudah kadaluarsa"; $cFaktur = "";
        } 
        $vaResponse[$dbRow['TrxID']] = array("TGL"=>String2Date($dbRow['Tgl']),"JAM"=>date("H:i",strtotime($dbRow['DateTime'])),"TRXID"=>$dbRow['TrxID'],"FAKTURCBS"=>$cFaktur,"STATUS"=>$cStatus,
                                             "KETERANGAN"=>$cStatusKet,"NOMINAL"=>round($dbRow['Nominal']),"ADMIN"=>round($dbRow['Admin']),"TOTAL"=>round($dbRow['Total']),"RD"=>$dbRow['Keterangan'],
                                             "PRODUK"=>getNamaChannelProdukVA($dbRow['Request'],$dbRow['Jenis']),"NOMORVA"=>$dbRow['VANumber'],"DATA"=>$dbRow['AdditionalData'],"PRODUKBAYAR"=>$cChannel,"ADD"=>'['. $dbRow['Response'] .']');
        $cResponse = "data kode bank terunduh";
      }
      # cek untuk transaksi yg sudah dibayar
      $dbData = objData::Browse("supplier_payment","*","Agen = '$cAgen' and HP = '$cHP' and (Tgl >= '$dTglAwal' and Tgl <= '$dTglAkhir') and Jenis = 'I' and Proses = 1","","","ID Desc");
      while ($dbRow = objData::GetRow($dbData)) {          
        $cStatus = "1"; $cStatusKet = "Sudah dibayar"; $cFaktur = $dbRow['FakturAgen'];
        $vaResponse[$dbRow['TrxID']] = array("TGL"=>String2Date($dbRow['Tgl']),"JAM"=>date("H:i",strtotime($dbRow['DateTime'])),"TRXID"=>$dbRow['TrxID'],"FAKTURCBS"=>$cFaktur,"STATUS"=>$cStatus,
                                             "KETERANGAN"=>$cStatusKet,"NOMINAL"=>round($dbRow['Nominal']),"ADMIN"=>round($dbRow['Admin']),"TOTAL"=>round($dbRow['Total']),"RD"=>$dbRow['Keterangan'],
                                             "PRODUK"=>getNamaChannelProdukVA($dbRow['Request'],$dbRow['Jenis']),"NOMORVA"=>$dbRow['VANumber'],"DATA"=>$dbRow['AdditionalData'],"PRODUKBAYAR"=>$cChannel,"ADD"=>'['. $dbRow['Response'] .']');
        $cResponse = "data kode bank terunduh";
      }
    } else if($cTrx == TRX_GET_MBAKTIF){
			$vaArrs	= array();
			$cAgen  		= $vaBody['Agen'];
			$dTglAwal  	= $vaBody['TglAwal'];
			$dTglAkhir	= $vaBody['TglAkhir'];
			
      $cField = "HP, Email, KodeCIF, Aktif, DateTime";
      $cWhere	= "Agen = '$cAgen' and (DateTime >= '$dTglAwal' and DateTime <= '$dTglAkhir')";
      $dbData = objData::Browse("agen_aktifasi", $cField, $cWhere);
			while($dbRow = objData::GetRow($dbData)){
				$vaArrs[] = $dbRow;
			}
			
			$vaResponse  = array("RC" => "00", "MSG" => $vaArrs);
		} else if($cTrx == TRX_MB_LOGIN_LUPA_SANDI) {//raf
			/* 
			format request -- aldo
			{
			"MTI":"004",
			"MSG": { 
			"TRX":"84", 
			"HP":"0851232215110",
			"EMAIL":"tes@gmail.com",
			"SendSMS": "0",
			"SIMSerial":"0067858198f0be6f462c",
			"Agen":"A-000144",
			"BuatOTP":null,   ##### Apabila buat otp terisi false maka OTP harus terisi untuk pengecekan otp ####
			"OTP":null}}
			**/
			$cSimSerial 	= $vaBody["SIMSerial"];
			$cKodeAg 			= substr($vaBody["SIMSerial"],0,4);
			$cNomor				= $vaBody["HP"];
			$cHP					= FormatNoHPmBanking($cNomor);
			$cEmail				= isset($vaBody["EMAIL"]) ? $vaBody["EMAIL"] : "" ;
			$cAgen				= $vaBody["Agen"];
			if($cAgen == "") $cAgen = GetKeterangan($cKodeAg,"KodeAgen","agen_fitur");
			$lSendMSG   	= isset($vaBody['SENDMSG']) ? $vaBody['SENDMSG'] : true;
			$lBuatOTP			= isset($vaBody["BuatOTP"]) ? $vaBody["BuatOTP"] : true;
			$cOTP					= isset($vaBody['OTP']) ? $vaBody['OTP'] : "";
			$lSMSMasking 	= isset($vaBody['SendSMS']) ? $vaBody['SendSMS'] : true; // untuk kirim email SendSMS harus berisi "0";
			$cField 			= "HP, EMAIL, SIMSerial, OTP";
			$cWhere 			= "HP = '$cHP' and Agen = '$cAgen'"; //SIMSerial = '$cSimSerial' and 
			$dbData 			= objData::Browse("agen_aktifasi",$cField,$cWhere);
			if($lBuatOTP){
				$nOTP     = rand(100000, 999999);
				if($dbRow = objData::GetRow($dbData)){
					$dbSimSerial 	= isset($dbRow["SIMSerial"]) ? $dbRow["SIMSerial"] : "";
					$dbHP					=	isset($dbRow["HP"]) ? $dbRow["HP"] : "";
					$cWhere   		= "SIMSerial = '$dbSimSerial' and HP = '$dbHP' and Agen = '$cAgen'";
					objData::Edit("agen_aktifasi",array("OTP"=>$nOTP),$cWhere);
        	$vaBank    		= getDataAgen($cAgen);
					$cMessage     = "Kode OTP anda dari " . $vaBank["BANK"] . " adalah $nOTP. Jangan berikan kode kepada siapapun.";
					if ($lSMSMasking) {
						if($cAgen == "A-000172" || $cAgen == "A-000153"){
							// Insert MHY
							$dDT       = date("Y-m-d H:i:s");
							objData::Insert("token_digi_log",array("CTO"=>$cHP,"CFROM"=>$cAgen,"CBD"=>$cMessage,"DT"=>$dDT),false);
							
            	$cRC        = "00";
							$cResponse = "AR#SUKSES#Tunggu SMS pemberitahuan selanjutnya..";
						}else{
							$vaDataMasking = array("HP" => $cHP, "MSG" => $cMessage, "OTP" => "1", "AGEN" => $cAgen, "RES_MSG" => "Tunggu ya, SMS akan dikirim dalam beberapa menit.");
							$cSMSMasking 	= SendSMSMasking($vaDataMasking);
							$vaSMSMasking = json_decode($cSMSMasking, true);
							$cRC = ($vaSMSMasking['status'] == "SUKSES") ? "00" : "01";
							$cResponse = $vaSMSMasking['text'];
						}
						/*
						$vaDataMasking = array("HP" => $cHP, "MSG" => $cMessage, "OTP" => "1", "AGEN" => $cAgen, "RES_MSG" => "Tunggu ya, SMS akan dikirim dalam beberapa menit.");
						$cSMSMasking 	= SendSMSMasking($vaDataMasking);
						$vaSMSMasking = json_decode($cSMSMasking, true);
						$cRC = ($vaSMSMasking['status'] == "SUKSES") ? "00" : "01";
						$cResponse = $vaSMSMasking['text'];
						*/
      		} else {
        		if ($cEmail <> "") { 
          		if ($cMessage == "") $cMessage = "Kode OTP anda dari " . $vaBank["BANK"] ." adalah $nOTP. Jangan berikan kode kepada siapapun.";
          		// if ($lSendMSG) {
            		$dDT       = date("Y-m-d H:i:s");
            		$cMessage  = rawurlencode($cMessage);
            		$vaInsert  = array("DateTime"=>$dDT,"Agen"=>$cAgen,"Penerima"=>$cEmail,"Subject"=>"Kode Aktivasi Digital","Message"=>$cMessage,"UserName"=>"mBanking");
            		$cTRX      = objData::Insert("notifemail_sent",$vaInsert,false);
          		// } 
							
							$cRC = "00";
          		$cResponse = "AR#SUKSES#Tunggu Email pemberitahuan selanjutnya.";
        		} else {
          		$cNoHP     = MBankingFunc::FormatNoHP($cHP);
          		$cNoModem  = "08563606500"; //$mBanking->GetNoModem($cNoHP);
          		if ($cMessage == "") $cMessage = "Kode OTP anda dari " . $vaBank["BANK"] ." adalah $nOTP. Jangan berikan kode kepada siapapun.";
          		if ($lSendMSG) {
            		$vaField   = array("DateTime" => time(), "SMSTo" => $cNoHP, "IMEI" => $cNoModem, "Message" => $cMessage, "Priority" => 0);
            		objData::Insert("sms_gateway_outbox", $vaField,false);
          		}
							$cRC = "00";
          		$cResponse = "AR#SUKSES#Tunggu SMS pemberitahuan selanjutnya.";
        		}
      		}
				}else{
					$cRC        = "01";
					$cResponse  = "No. HP tidak ditemukan/Device berbeda";
				}
			}else{
				if($dbRow = objData::GetRow($dbData)){
					if ($dbRow['OTP'] == $cOTP) {
						$cRC        = "00";
						$cResponse = "SUKSES";
					} else {
						$cRC        = "01";
						$cResponse = "OTP yang Anda masukkan salah, periksa kembali kode OTP yang telah diberikan.";
					}
				}else{
					$cRC        = "01";
					$cResponse  = "No. HP tidak ditemukan/Device berbeda";
				}
			}
			$vaResponse[0] = join("|", array($cRC, $cResponse));
			//rafii
		}else {
      if (isset($vaBody['SIMSerial'])) {
        $cSIMSerial = $vaBody['SIMSerial'];
        $dbData = objData::Browse("agen_smsbanking s", "s.*,a.*", "s.SIMSerial = '$cSIMSerial'", array("Left Join agen a on a.Kode = s.Agen"));
      } else if (isset($vaBody['Bank'])) {
        $cKodeBank  = $vaBody['Bank'];
        $dbData = objData::Browse("agen_fitur af", "af.*,a.*", "af.Kode = '$cKodeBank'", array("Left Join agen a on a.Kode = af.KodeAgen"));
      }

      if ($dbRow = objData::GetRow($dbData)) {
        $cAgen    = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
        $cURL     = $dbRow['URL'];
        $cUserH2H = $dbRow['UserH2H'];
        $cMessage   = "cCode=" . $cBody;
        $cResponse  = SendHTTPPost($cURL, $cMessage);
        $cResponse  = ltrim($cResponse);
        $vaResponse = json_decode($cResponse);
        
        if ($cTrx == TRX_GET_IMAGE) {
          $cURL       = explode("/", $cURL);
          $vaReturn   = array();
          $img        = $vaResponse;
					
          for ($a = 0; $a < count($img); $a++) {
            $cFoto       = str_replace("../", "", $img[$a]);
            $cURLGambar  = $cURL[0] . "/" .  $cFoto;
            $cURLGambar  = str_replace(" ", "%20", $cURLGambar);
            $imagedata   = file_get_contents("http://$cURLGambar");
            $base64      = base64_encode($imagedata);
            $image       = 'data:image/jpeg;base64,' . $base64;
            $vaReturn[]  = $image;
          }

          if (count($vaReturn) > 0) {
            $vaResponse  = array("RC" => "00", "MSG" => $vaReturn);
          } else {
            $vaResponse  = array("RC" => "19", "MSG" => "Tidak ada data ditemukan");
          }
        }else if($cTrx == TRX_GET_POSPULSA){
          $vaResponse = json_decode($cResponse,1);
          if($vaResponse['DE039']==00){
            $vaResponseC = $vaResponse['DE048'];
            $nLen = count($vaResponseC);
            for($n=0;$n<$nLen;$n++){
              $cKodeTr = $vaResponseC[$n]['Kode'];
              $cTrxIDr = $vaResponseC[$n]['TrxID'];
              $dbDt = objData::Browse("mapp_de_transaksi","Keterangan", "Kode_Stock = '$cKodeTr'");
              if(objData::Rows($dbDt) > 0){
                $dbRt = objData::GetRow($dbDt);
                $vaResponse['DE048'][$n]['Kode'] = "Top Up ".$dbRt['Keterangan'];  
              }else{
                if (strpos($cKodeTr, 'PAYTFDANA') !== false) {
                  $vaResponse['DE048'][$n]['Kode'] = "Transfer Sesama Bank";
                }
                if (strpos($cTrxIDr, 'WP') !== false) {
                  $vaResponse['DE048'][$n]['Kode'] = "Setoran Virtual Account via ". $cKodeTr;
                }
              }
            }
          }
        }
      } else {
        $vaResponse  = array("RC" => "XR", "MSG" => "URL tidak ditemukan [E]");
      }
    }
    return $vaResponse;
  }
  
  static function ProsesNonISO($cRequest, $vaMSG){
    $vaResponse = array();
    $cSIMSerial = $vaMSG['SIMSerial'];
    $cField     = "s.*,a.*";
    $cWhere     = "s.SIMSerial = '$cSIMSerial'";
    $vaJoin = array("Left Join agen a on a.Kode = s.Agen");
    $dbData = objData::Browse("agen_smsbanking s", $cField, $cWhere, $vaJoin);
    if ($dbRow = objData::GetRow($dbData)) {
      $cAgen      = isset($dbRow['Agen']) ? $dbRow['Agen'] : $dbRow['KodeAgen'];
      $cURL       = $dbRow['URL'];
      $cUserH2H   = $dbRow['UserH2H'];
      $cMessage   = "cCode=" . $cRequest;
      $cResponse  = SendHTTPPost($cURL, $cMessage);                                                                                                                                                               
      $cResponse  = ltrim($cResponse);
      $vaResponse = json_decode($cResponse);
    }
    return $vaResponse;
  }
  
  private function mBanking($cRequest, $vaISO){
    $objISO   = new Iso8583();
    $cError   = "Sukses";
    $cDE003   = $vaISO['DE003'];
    $cDE004   = $vaISO['DE004'];
    $cDE048   = $vaISO['DE048'];
		$cDE048  	= preg_replace('/["\']/', '', $cDE048);
		$cDE039   = $vaISO['DE039'];
    if ($cDE003 == DE003_TRX_PURCHASE) {
      $cKodeAgen  = MBankingFunc::GetKodeAgenMobile($vaISO['DE061']);  
      $vaHrg      = MBankingFunc::GetHargaPPOB($cKodeAgen,$cDE004,$cDE048,$cDE003);
      $cDE004     = $vaHrg['HJ'];
      $cDE048     = $cDE048."*".$vaHrg['Margin'];
			
			/*
			// ini untuk setting produk gagal.
			if (substr($vaISO['DE003'], 0, 2) == "20"){
				$vaTransaksi    = explode("*", $cDE048);
				if($vaTransaksi[2] == "01") {
					$cError = "Gagal melanjutkan proses, pembelian pulsa prabayar sedang dalam maintenance";
					$cResponse  = MBankingFunc::JSON2ISO(false, $vaISO['DE003'], $cDE004, $vaISO['DE012'], $vaISO['DE013'], $vaISO['DE037'], "XR", $vaISO['DE044'], $cError, $vaISO['DE052'], $vaISO['DE061'], $vaISO['DE102'], $vaISO['DE103']);
					$vaResponse = MBankingFunc::ISO2Array($cResponse);
					$vaResponse['RC']       = "XR";
					$vaResponse['Message']  = $cError;
					return $vaResponse;
				}
			}
			*/
			
    } else if ($cDE003 == DE003_TRX_PASCA || $cDE003 == DE003_TRX_PASCA_2) {
      $cKodeAgen  = MBankingFunc::GetKodeAgenMobile($vaISO['DE061']);  
      $vaHrg      = MBankingFunc::GetHargaPPOB($cKodeAgen,$cDE004,$cDE048,$cDE003);
      $cDE048     = $cDE048."*".$vaHrg['Margin'];
    }

    // Jadikan array request, ke format ISO untuk keperluan log nantinya.
    if ($cDE003 == DE003_TRX_MUTASI_TABUNGAN) {
      $cBody = MBankingFunc::JSON2ISO(true, $vaISO['DE003'], $cDE004, $vaISO['DE012'], $vaISO['DE013'], $vaISO['DE037'], $vaISO['DE039'], $vaISO['DE044'], $cDE048, $vaISO['DE052'], $vaISO['DE061'], $vaISO['DE102'], $vaISO['DE103'], $vaISO['DE105'], $vaISO['DE106'], $vaISO['DE107'], $vaISO['DE108'], $vaISO['DE109'], $vaISO['DE110']);
    } else {
      $cBody = MBankingFunc::JSON2ISO(false, $vaISO['DE003'], $cDE004, $vaISO['DE012'], $vaISO['DE013'], $vaISO['DE037'], $vaISO['DE039'], $vaISO['DE044'], $cDE048, $vaISO['DE052'], $vaISO['DE061'], $vaISO['DE102'], $vaISO['DE103']);
    }

    $cMD5Sum        = md5($cBody);
    $lTrue          = false;
    $cKodeTrx       = substr($vaISO['DE003'], 0, 2);
    $cSIMSerial     = $vaISO['DE061'];
    if ($cKodeTrx == TRX_MB_AKTIVASI_NOMOR) {
      $vaISO48 = explode(" ", $vaISO['DE048']);
      $cSender = MBankingFunc::KodeNegara($vaISO48[2]);
      
      $dbData  = objData::Browse("agen_aktifasi aa", "aa.*,a.UserH2H,a.URL", "aa.Aktif = '0' and CS = '1' and aa.SIMSerial = '$cSIMSerial'", array("Left Join agen a on a.Kode = aa.Agen"));
      if ($dbRow = objData::GetRow($dbData)) {
        $cAgen 	= $dbRow['Agen'];
				$cEmail = $dbRow['Email'];
				$cHP 		= str_replace("+62","0",$dbRow['HP']);
        if ($dbRow['mBankingToken'] <> $vaISO48[0]) {
          $cError = "Token yang Anda masukkan salah, periksa kembali kode token yang telah diberikan.";
        } else if ($dbRow['HP'] <> $cSender) {
          $cError = "Nomor HP tidak ditemukan, periksa kembali nomor HP Anda";
        } else if ($dbRow['SIMSerial'] <> $cSIMSerial) {
          $cError = "Serial SIM tidak cocok dengan data kami. Coba lagi";
        } else {
					// Cek kadaluarsa OTP
					//$dbDns = objData::Browse("notifemail_sent","DateTimeKirim","Agen = '$cAgen' and Penerima = '$cEmail'","","","ID Desc","1");
					//$dbDsk = objData::Browse("smsmasking_kirim","DateTime as DateTimeKirim","Agen = '$cAgen' and NoHP = '$cHP'","","","ID Desc","1");
					$cHPAK = MBankingFunc::KodeNegara($dbRow['HP']);
					$dbDns = objData::Browse("agen_aktifasi","DateTimeOTP as DateTimeKirim","Agen = '$cAgen' and HP = '$cHPAK' and Jenis = 'M'","","","ID Desc","1");
					$dbDsk = objData::Browse("agen_aktifasi","DateTimeOTP as DateTimeKirim","Agen = '$cAgen' and HP = '$cHPAK' and Jenis = 'M'","","","ID Desc","1");
					
					$usZa	= GetKeterangan($cAgen,"UserZenziva","agen");
					if($usZa==""){
						$dbRns = objData::GetRow($dbDns);
						$dTimeKadaluarsa = strtotime("{$dbRns['DateTimeKirim']} + 15 minutes");
						$dTimeKadaluarsa = date('Y-m-d H:i:s', $dTimeKadaluarsa);
						$dtNow = SNow();
						
						if($dtNow > $dTimeKadaluarsa){
							$cError = "Token yang dimasukkan telah kadaluarsa, ulangi langkah pengiriman token lagi (E).";
						}else{
							$lTrue = true;
						}
					}else{
						$dbRsk = objData::GetRow($dbDsk);
						$dTimeKadaluarsa = strtotime("{$dbRsk['DateTimeKirim']} + 15 minutes");
						$dTimeKadaluarsa = date('Y-m-d H:i:s', $dTimeKadaluarsa);
						$dtNow = SNow();
						
						if($dtNow > $dTimeKadaluarsa){
							$cError = "Token yang dimasukkan telah kadaluarsa, ulangi langkah pengiriman token lagi (S).";
						}else{
							$lTrue = true;
						}
					}
        }
      } else {
        $cError = "Belum menyelesaikan pembukaan fasilitas. Silakan ke CS Bank terlebih dahulu.";
      }
			
			if(!$lTrue) {
				$cDE039 = "XR";
				$cDE048 = $cError;
			}
    } else if ($cKodeTrx == TRX_MB_LOGIN_VIA_EMAIL || $cKodeTrx == TRX_MB_LOGIN_VIA_OTP) {
      $cField     = "s.*,a.*";
      $cNomorHP1  = $vaISO['DE102'];
      $cWhere     = "s.Nomor = '$cNomorHP1' and s.Jenis = 'M'";
      $cAgen1     = $vaISO['DE103'];
      if ($cAgen1 <> "") $cWhere .= " and s.Agen = '$cAgen1'";
      $dbData     = objData::Browse("agen_smsbanking s", $cField, $cWhere, array("Left Join agen a on a.Kode = s.Agen"));
      if ($dbRow = objData::GetRow($dbData)){
        $lTrue = true;
				$cHP = MBankingFunc::KodeNegara($dbRow['Nomor']);
				$dbDD = objData::Browse("agen_aktifasi", "Email", "Agen = '$cAgen1' and HP = '$cHP'");
        $cEmail = "";
				if($dbRR = objData::GetRow($dbDD)){
					$cEmail = $dbRR['Email'];
				}
        # jika request login via OTP
        if($cKodeTrx == TRX_MB_LOGIN_VIA_OTP){
          $lTrue      = false;
          $vaDE48     = explode(" ", $cDE048);
          $cLoginOTP  = isset($vaDE48[1]) ? $vaDE48[1] : "";
          
          if(empty($cLoginOTP)){
            $cError = "Token yang Anda masukkan kosong, periksa kembali kode token yang telah diberikan";
          }else if($dbRow['LoginOTP'] <> $cLoginOTP){
            $cError = "Token yang Anda masukkan salah, periksa kembali kode token yang telah diberikan.";
          }else if($dbRow['Nomor'] <> $cNomorHP1){
            $cError = "Nomor HP tidak ditemukan, periksa kembali nomor HP Anda";
          }else if($dbRow['SIMSerial'] <> $cSIMSerial){
            $cError = "Serial SIM tidak cocok dengan data kami. Coba lagi.";
          }else{
						// Cek kadaluarsa OTP
						//$dbDns = objData::Browse("notifemail_sent","DateTimeKirim","Agen = '$cAgen' and Penerima = '$cEmail'","","","ID Desc","1");
						//$dbDsk = objData::Browse("smsmasking_kirim","DateTime as DateTimeKirim","Agen = '$cAgen' and NoHP = '$cHP'","","","ID Desc","1");
						$dbDns = objData::Browse("agen_aktifasi","DateTimeOTP as DateTimeKirim","Agen = '$cAgen1' and HP = '$cHP' and Jenis = 'M'","","","ID Desc","1");
						$dbDsk = objData::Browse("agen_aktifasi","DateTimeOTP as DateTimeKirim","Agen = '$cAgen1' and HP = '$cHP' and Jenis = 'M'","","","ID Desc","1");
						
						$usZa	= GetKeterangan($cAgen1,"UserZenziva","agen");
						if($usZa==""){
							$dbRns = objData::GetRow($dbDns);
							$dTimeKadaluarsa = strtotime("{$dbRns['DateTimeKirim']} + 15 minutes");
							$dTimeKadaluarsa = date('Y-m-d H:i:s', $dTimeKadaluarsa);
							$dtNow = SNow();
							
							if($dtNow > $dTimeKadaluarsa){
								$cError = "Token yang dimasukkan telah kadaluarsa, ulangi langkah kirim token lagi (E).";
							}else{
								$lTrue = true;
							}
						}else{
							$dbRsk = objData::GetRow($dbDsk);
							$dTimeKadaluarsa = strtotime("{$dbRsk['DateTimeKirim']} + 15 minutes");
							$dTimeKadaluarsa = date('Y-m-d H:i:s', $dTimeKadaluarsa);
							$dtNow = SNow();

							if($dtNow > $dTimeKadaluarsa){
								$cError = "Token yang dimasukkan telah kadaluarsa, ulangi langkah pengiriman token lagi (S).";
							}else{
								$lTrue = true;
							}
						}
          }
					if(!$lTrue) {
						$cDE039 = "XR";
						$cDE048 = $cError;
					};
        }
      }
			//$lTrue = true;
    } else {
      $cField = "s.*,a.*";
      $cWhere = "s.SIMSerial = '$cSIMSerial'";
      $vaJoin = array("Left Join agen a on a.Kode = s.Agen");
      $dbData = objData::Browse("agen_smsbanking s", $cField, $cWhere, $vaJoin);
      if ($dbRow = objData::GetRow($dbData)) $lTrue = true;
    }
		
    if ($lTrue) {
      $cAgen    = $dbRow['Agen'];
      $cURL     = $dbRow['URL'];
      $cUserH2H = $dbRow['UserH2H'];
      if (substr($vaISO['DE003'], 0, 2) == TRX_MB_AKTIVASI_NOMOR) {
        $cSender = MBankingFunc::FormatNoHP($dbRow['HP']);
      } else {
        $cSender = MBankingFunc::FormatNoHP($dbRow['Nomor']);
      }
			
			# UPD: tutup layanan PPOB jika akhir tahun
			$lTutupPPOBAkhirTahun = false;
			$lMaintenLayanan = false;
			$lTutupLayanan = false;
			switch ($cKodeTrx) {
				case TRX_MB_PURCHASE:
				case TRX_MB_PAYMENT:
				case TRX_MB_TRANSFER_DEBET:
				case TRX_MB_ZAKAT:
				case TRX_MB_INFAQ:
				case TRX_MB_WAKAF:
					// [24JAM] $lTutupPPOBAkhirTahun tidak diaktifkan — layanan 24 jam penuh
					// $lTutupPPOBAkhirTahun = true;
					//$lTutupLayanan = true;
					
					//if($cKodeTrx == TRX_MB_PURCHASE || $cKodeTrx == TRX_MB_PAYMENT){
						/*
						$lMaintenLayanan = true;
						# Jika jam kerja
						$hariIni 	= date('w');
						$dNw 			= date('H:i:s');

						if($hariIni >= 1 && $hariIni <= 5){
							if(strtotime($dNw) >= strtotime('09:00:00') && strtotime($dNw) <= strtotime('15:58:59')){
								$lTutupLayanan	= false;
								$lMaintenLayanan = false;
							}
						}
						*/
					//}else{
						//$lTutupLayanan = false;
					//}
					
					# Ngawi
					# Untuk buka tutup penataran pas gaji
					# tinggal masukkan agennya penataran nanti transaksinya pasti tertutup
					if($cAgen == "A-000274"){ // || $cAgen == "A-000300" 
						$lMaintenLayanan = true;
					}
					//penambahan agen
					if($cAgen == "A-000276" || $cAgen == "A-000300" || $cAgen == "A-000260" || $cAgen == "A-000270" || $cAgen == "A-000234" || $cAgen == "A-000144" || $cAgen == "A-000177"){ // $cAgen == "A-000144" $cAgen == "A-000265"
						
					}else{
						$cDE048 = preg_replace('/["\']/', '', $cDE048);
						$vaIsiDe48 = explode("*",$cDE048);
						$cTipeTRX = isset($vaIsiDe48[2])? "$vaIsiDe48[2]" : "";
						if(($cKodeTrx == TRX_MB_PURCHASE || $cTipeTRX=="PAYEMONEY") && $lMaintenLayanan==false && $lTutupLayanan==false){
							$vMon	= explode("*",$cDE048);
							$cKPR = $vMon[3];
							$vAr			= array(
								"MTI"	=> $this->GetConfig('mftfi'),
								"MSG"	=> array(
									"DE004"	=> $cDE004,
									"DE048"	=> $cDE048,
									"DE061"	=> $vaISO['DE061'],
									"DE102"	=> $vaISO['DE102'],
									"DE103"	=> $vaISO['DE103'],
									"AKode"			=> GetKeterangan($cKPR,"AKode","mapp_de_transaksi","Kode_Stock"),
									"ABillerID"	=> GetKeterangan($cKPR,"ABillerID","mapp_de_transaksi","Kode_Stock"),
									"AProductID"=> GetKeterangan($cKPR,"AProductID","mapp_de_transaksi","Kode_Stock")
								)
							);

							$cMS = json_encode($vAr);
							objData::Insert("sms_inbox", array("SMSFrom" => $cSender, "Agen" => $cAgen, "Jenis" => "I", "Protocol" => "M", "GroupSMS" => "B", "Message" => "DIGI INQ(Rq) ".$cMS, "DateTime" => time(), "Proses" => "1", "Response" => "mBanking", "IMEI" => "GPRS"));
							$cM	= "cCode=" . $cMS;
							$cU	= $this->GetConfig('s');
							$vaH = array(
								'authorization: ' . hash('sha256',$cMS.SNow()),
								'identity: ' . $this->GetConfig('cicd'),
								'datetime: ' . SNow(),
								'Content-Type: application/x-www-form-urlencoded'
							);
							$cResponse  = SendHTTPPost($cU,$cM,'',false,$vaH);
							$cResponse  = ltrim($cResponse);
							$vaResponse	= json_decode($cResponse,1);
							$vaResponse = json_decode($vaResponse["data"],1);
							$cNSeri = "";
							objData::Insert("sms_inbox", array("SMSFrom" => $cSender, "Agen" => $cAgen, "Jenis" => "I", "Protocol" => "M", "GroupSMS" => "B", "Message" => "DIGI INQ(Rs) ".$cResponse, "DateTime" => time(), "Proses" => "1", "Response" => "mBanking", "IMEI" => "GPRS"));
							if($vaResponse['RC']!="00"){
								$cRettt = isset($vaResponse['MSG'])? $vaResponse['MSG'] : "Respon digital kosong.";
								$cResponse = MBankingFunc::JSON2ISO(false,$vaISO['DE003'],$vaISO['DE004'],date("hi"),date("dm"),"000000000000","XT","0",$cRettt,"0000000000000000000000000000000000000000000000000000000000000000","000000000000000000000000","0","0");

								$vaResponse = MBankingFunc::ISO2Array($cResponse);

								return $vaResponse;
							}else{
								$cNSeri 	= $vaResponse['MSG'];
								$cDE048   = $cDE048."*".$cNSeri;
								$cBody 		= MBankingFunc::JSON2ISO(false, $vaISO['DE003'], $cDE004, $vaISO['DE012'], $vaISO['DE013'], $vaISO['DE037'], $vaISO['DE039'], $vaISO['DE044'], $cDE048, $vaISO['DE052'], $vaISO['DE061'], $vaISO['DE102'], $vaISO['DE103']);
								$cMD5Sum	= md5($cBody);
							}
						}
					}
					//if($cAgen=="A-000177" && $cSIMSerial=="00094cd2400c55820c5f") $lMaintenLayanan = false;
					
					break;
				default:
			}
			
			if($cAgen == "A-000276" || $cAgen == 'A-000172'){
				// Jika akp
				$cBody = "06" . substr($cBody,2);
			}
			
			if ($lTutupPPOBAkhirTahun) {
				date_default_timezone_set('Asia/Jakarta');
				$nCurrentTime = time();
				// [24JAM] TutupPPOB timestamp check DINONAKTIFKAN — layanan 24 jam penuh
				// if ($nCurrentTime >= TutupPPOB::AKHIRTAHUN_FROMTIME && $nCurrentTime <= TutupPPOB::AKHIRTAHUN_TOTIME) {
				// 	$lTutupPPOBAkhirTahun = true;
				// } else {
				// 	$lTutupPPOBAkhirTahun = false;
				// }
				$lTutupPPOBAkhirTahun = false; // [24JAM] selalu false
			}
			
			if($lTutupLayanan){
				$cPesser		= "Halo pengguna!, Saat ini transaksi diluar hari dan jam kerja (Senin s/d Jumat, 09.00-16.00 WIB) tidak dapat diproses. Mohon maaf atas ketidaknyamanan yang dialami. Terima kasih atas pengertian dan dukungan Anda.";
				$cResponse 	= MBankingFunc::JSON2ISO(false,$vaISO['DE003'],$vaISO['DE004'],date("hi"),date("dm"),"000000000000","XX","0",$cPesser,"0000000000000000000000000000000000000000000000000000000000000000","000000000000000000000000","0","0");
				$cResponse  = ltrim($cResponse);
				$vaResponse = MBankingFunc::ISO2Array($cResponse);
				
				$vaResponse['RC']	= "00";
				return $vaResponse;
			}
			
			if($lMaintenLayanan){
				$cResponse 	= MBankingFunc::JSON2ISO(false,$vaISO['DE003'],$vaISO['DE004'],date("hi"),date("dm"),"000000000000",MaintenPPOB::MAINTEN_RCODE,"0",MaintenPPOB::MAINTEN_MSG,"0000000000000000000000000000000000000000000000000000000000000000","000000000000000000000000","0","0");
				$cResponse  = ltrim($cResponse);
				$vaResponse = MBankingFunc::ISO2Array($cResponse);
				
				$vaResponse['RC']	= "00";
				return $vaResponse;
			}
			
			if ($lTutupPPOBAkhirTahun) {
				$cResponse = MBankingFunc::JSON2ISO(false,$vaISO['DE003'],$vaISO['DE004'],date("hi"),date("dm"),"000000000000",TutupPPOB::AKHIRTAHUN_RCODE,"0",TutupPPOB::AKHIRTAHUN_MSG_THNBARU,"0000000000000000000000000000000000000000000000000000000000000000","000000000000000000000000","0","0");
			} else {
				if ($cDE003 == DE003_TRX_INQUIRY_PPOB) {
					// Jika request adalah inquiry PPOB, maka gunakan metode lama.
					$nTimeStart = time();
					$dbData     = objData::Browse("xmpp_chatlog", "*", "MD5SUM = '$cMD5Sum' and JID = '$cSender' and DateTime >= '$nTimeStart'");
					if ($dbRow = objData::GetRow($dbData)) {
						objData::Insert("sms_inbox", array("SMSFrom" => $cSender, "Agen" => "None", "Jenis" => "I", "Protocol" => "M", "GroupSMS" => "B", "Message" => $cBody, "DateTime" => time(), "Proses" => "0", "Response" => "mBanking Resend Msg", "IMEI" => "GPRS"));
					} else {
						objData::Insert("xmpp_chatlog", array(
							"Jenis" => "I", "JID" => $cSender, "Protocol" => "M", "SC" => "C", "Supplier" => "", "IMID" => "GPRS", "Message" => $cBody,
							"CreatorID" => "", "DateTime" => time(), "Proses" => 0, "MD5Sum" => $cMD5Sum, "ISO" => "", "Nomor" => ""
						));
					}
					$cResponse = MBankingFunc::GetResponseFromGW($cSender, $vaISO);
					$cResponse = ltrim($cResponse);
				} else {
					// Jika request adalah transaksi bersifat on-us, gunakan metode baru
					// LOG REQUEST 
					
					objData::Insert("sms_inbox", array("SMSFrom" => $cSender, "Agen" => $cAgen, "Jenis" => "I", "Protocol" => "M", "GroupSMS" => "B", "Message" => $cBody, "DateTime" => time(), "Proses" => "1", "Response" => "mBanking", "IMEI" => "GPRS"));
					$cMessage   = "cCode=" . $cBody ;
					$cResponse  = SendHTTPPost($cURL, $cMessage);
					$cResponse  = ltrim($cResponse);
					if (substr($cResponse, 0, 4) == "0210" || substr($cResponse, 0, 4) == "0610") {
						$vaISOlog = $vaISO;
						$vaISO    = MBankingFunc::ISO2Array($cResponse);
						//penambahan agen
						if($cAgen == 'A-000276' || $cAgen == "A-000300" || $cAgen == "A-000260" || $cAgen == "A-000270" || $cAgen == "A-000234" || $cAgen == "A-000144" || $cAgen == "A-000177"){
							
						}else{
							if($vaISO['DE039']=="13"){
								$vaIsiDe48 = explode("*",$vaISOlog['DE048']);
								$cTipeTRX = isset($vaIsiDe48[2])? "$vaIsiDe48[2]" : "";
								if($cKodeTrx == TRX_MB_PURCHASE || $cTipeTRX=="PAYEMONEY"){
									$this::Ins2Digi($cDE004, $vaISOlog, $cSender, $cNSeri);
								}
							}else{
								$vaIsiDe48 = explode("*",$vaISOlog['DE048']);
								$cTipeTRX = isset($vaIsiDe48[2])? "$vaIsiDe48[2]" : "";

								if($cKodeTrx == TRX_MB_PURCHASE || $cTipeTRX=="PAYEMONEY"){
									$vArw			= array(
										"MTI"	=> "004",
										"MSG"	=> array(
											"Seri"	=> $cNSeri
										)
									);

									$cMS = json_encode($vArw);
									objData::Insert("sms_inbox", array("SMSFrom" => $cSender, "Agen" => $cAgen, "Jenis" => "I", "Protocol" => "M", "GroupSMS" => "B", "Message" => "DIGI DEL ".$cMS, "DateTime" => time(), "Proses" => "1", "Response" => "mBanking", "IMEI" => "GPRS"));
									$cM	= "cCode=" . $cMS;
									$cU	= $this->GetConfig('s');
									$vaH = array(
										'authorization: ' . hash('sha256',$cMS.SNow()),
										'identity: ' . $this->GetConfig('cicd'),
										'datetime: ' . SNow(),
										'Content-Type: application/x-www-form-urlencoded'
									);
									SendHTTPPost($cU,$cM,'',false,$vaH);
								}
							}
						}
						
						
						if ((substr($cResponse, 0, 4) == "0210" && $vaISO["DE003"] == "041000" && $vaISO["DE039"] == "00") || (substr($cResponse, 0, 4) == "0610" && $vaISO["DE003"] == "041000" && $vaISO["DE039"] == "00"))  {
							$cInfoNasabah = explode("|", $vaISO["DE048"]);
							$dbData       = objData::Browse("agen_smsbanking", "SIMSerial,Nomor,Agen", "Nomor = '" . $cInfoNasabah[1] . "' and SIMSerial = '" . $vaISO["DE061"] . "'");
							if (objData::Rows($dbData) == 0) {
								$vaField = array(
									"TrxKey" => $cInfoNasabah[2], "SIMSerial" => $vaISO["DE061"], "Nomor" => $cInfoNasabah[1], "FirebaseKey" => $cInfoNasabah[5],
									"TglAktifasi" => date("Y-m-d"), "TglKirimTerakhir" => time(), "Agen" => $cAgen, "Jenis" => "M"
								);
								objData::Insert("agen_smsbanking", $vaField);
								objData::Edit("agen_aktifasi", array("Aktif" => 1), "SIMSerial = '" . $vaISO["DE061"] . "' and HP = '" . MBankingFunc::KodeNegara($cInfoNasabah[1]) . "'");
							}

							// Dari transaksi Cek aktivasi mBanking 
						} else if ((substr($cResponse, 0, 4) == "0210" && $vaISO["DE003"] == "081000" && $vaISO["DE039"] == "00") || (substr($cResponse, 0, 4) == "0610" && $vaISO["DE003"] == "081000" && $vaISO["DE039"] == "00")) {
							$cInfoNasabah = explode("|", $vaISO["DE048"]);
							$cFirebase = isset($cInfoNasabah[5]) ? $cInfoNasabah[5] : "";
							objData::Edit("agen_smsbanking", array("StatusNomor" => "A", "TrxKey" => $cInfoNasabah[2], "FirebaseKey" => $cFirebase), "SIMSerial = '" . $vaISO["DE061"] . "'");
							objData::Edit("agen_aktifasi", array("Aktif" => 1), "SIMSerial = '" . $vaISO["DE061"] . "'");

							// dan login via email 
						} else if ((substr($cResponse, 0, 4) == "0210" && $vaISO["DE003"] == "601000" && $vaISO["DE039"] == "00") || (substr($cResponse, 0, 4) == "0610" && $vaISO["DE003"] == "601000" && $vaISO["DE039"] == "00")) {
							$cInfoNasabah = explode("|", $vaISO["DE048"]);
							$cFirebase = isset($cInfoNasabah[5]) ? $cInfoNasabah[5] : "";
							objData::Edit("agen_smsbanking", array("StatusNomor" => "A", "TrxKey" => $cInfoNasabah[2], "FirebaseKey" => $cFirebase, "SIMSerial" => $vaISO["DE061"]), "Nomor = '" . $vaISO["DE102"] . "' and Agen = '$cAgen'");
						}

						# update: kebutuhan log
						/*if (substr($cResponse,0,4) == "0210" && $vaISO["DE039"] == "00") {
							switch ($cKodeTrx) {
								case TRX_MB_AKTIVASI_NOMOR:
								case TRX_MB_LOGIN_VIA_EMAIL:
								case TRX_MB_LOGIN_VIA_OTP:
									$cLogHP				= $dbRow['HP'];
									$cLogDeviceID = "";
									$cLogAction		= "";
									$cLogMessage	= "";
									if (!empty($vaISO["DE061"])) $cLogDeviceID = $vaISO["DE061"];

									if ($cKodeTrx == TRX_MB_AKTIVASI_NOMOR) {
										$cLogAction		= "Aktivasi Fasilitas Digital";
										$cLogMessage 	= "Aktivasi fasilitas digital menggunakan aplikasi mobile untuk HP (%s)";
									} else if ($cKodeTrx == TRX_MB_LOGIN_VIA_EMAIL || $cKodeTrx == TRX_MB_LOGIN_VIA_OTP) {
										$cLogAction		= "Login Fasilitas Digital";
										$cLogMessage 	= "Login fasilitas digital menggunakan aplikasi mobile untuk HP (%s)";
										if ($cKodeTrx == TRX_MB_LOGIN_VIA_OTP) $cLogAction .= " (MTD: OTIPI)";
									}

									$cWhereLog = "Agen = '$cAgen' AND SIMSerial = '$cLogDeviceID' or HP = '$cLogHP'";
									$dbDataLog = objData::Browse("agen_aktifasi","HP,Email,KodeCIF",$cWhereLog);
									if ($dbRowLog = objData::GetRow($dbDataLog)) {
										$cLogHP 		 	= $dbRowLog["HP"];
										$cLogEmail 	 	= $dbRowLog["Email"];
										$cLogKodeCIF 	= $dbRowLog["KodeCIF"];
										$cLogDeviceID = $vaISO["DE061"];
										$cLogCounts		= objData::Rows($dbDataLog);
										$cLogAction  .= " (CNT: $cLogCounts)";
										$cLogMessage 	= sprintf($cLogMessage, $cLogHP);
										if (!empty(trim($cLogEmail))) {
											$cLogMessage .= " dan Email (%s)";
											$cLogMessage  = trim($cLogMessage);
											$cLogMessage  = sprintf($cLogMessage, $cLogEmail);
										}
										SwitchLog::SetKodeAgen($cAgen);
										SwitchLog::SetKodeCIF($cLogKodeCIF);
										SwitchLog::SetDeviceID($cLogDeviceID);
										SwitchLog::SetEvent(implode(" ", array(self::$cKodeLog, ": (Mobile App)", trim($cLogAction))));
										SwitchLog::SetMessage($cLogMessage);
										SwitchLog::Save();
									}
									break;
								default:
							}
						}*/
					}

					// LOG RESPONSE 
					if ($cResponse != "") objData::Insert("sms_inbox", array("SMSFrom" => $cSender, "Agen" => $cAgen, "Jenis" => "I", "Protocol" => "M", "GroupSMS" => "B", "Message" => $cResponse, "DateTime" => time(), "Proses" => "1", "Response" => "mBanking", "IMEI" => "GPRS"));

					// Log ke updpenjualanpulsa 
					$cSN = SMSBankingNoPengiriman();
					updPenjualanPulsa($cAgen, "P-03", "P", $cUserH2H, $cSN, $cSender);
				}
			}
    } else {
				if($cDE039!="XT" && $cDE039!="XR") $cDE039 = "02";
				$cResponse  = MBankingFunc::JSON2ISO(false, $vaISO['DE003'], $cDE004, $vaISO['DE012'], $vaISO['DE013'], $vaISO['DE037'], $cDE039, $vaISO['DE044'], $cDE048, $vaISO['DE052'], $vaISO['DE061'], $vaISO['DE102'], $vaISO['DE103']);
      	if(empty($cError)) $cError = "Gagal melanjutkan proses, nomor anda belum diaktivasi";
      	$vaValue    = array("SMSFrom" => $vaISO['DE061'], "Agen" => "None", "Jenis" => "I", "Protocol" => "M", "GroupSMS" => "B", "Message" => $cBody, "DateTime" => time(), "Proses" => "1", "Response" => "mBanking Close", "IMEI" => "GPRS");
      	objData::Insert("sms_inbox", $vaValue,false);
    }
    $cResponse  = ltrim($cResponse); // hilangkan white space di sebelah kiri string
    
    if (substr($cResponse, 0, 4) == "0200" || substr($cResponse, 0, 4) == "0210" || substr($cResponse, 0, 4) == "0600" || substr($cResponse, 0, 4) == "0610") {
      $vaResponse = MBankingFunc::ISO2Array($cResponse);
    } else { // bikin dia timeout jika terjadi sesuatu pada server
      $cResponse  = MBankingFunc::JSON2ISO(false,$vaISO['DE003'], $cDE004, $vaISO['DE012'], $vaISO['DE013'], $vaISO['DE037'],"11", $vaISO['DE044'],$cDE048, $vaISO['DE052'], $vaISO['DE061'], $vaISO['DE102'], $vaISO['DE103']) ;
      //$cResponse  = MBankingFunc::JSON2ISO(false,$vaISO['DE003'], $cDE004, $vaISO['DE012'], $vaISO['DE013'], $vaISO['DE037'],"11", $vaISO['DE044'],$cDE048, $vaISO['DE052'], $vaISO['DE061'], $vaISO['DE102'], $vaISO['DE103']) ;
      //$cResponse  = $mBanking->JSON2ISO(false, $vaISO['DE003'], $cDE004, $vaISO['DE012'], $vaISO['DE013'], $vaISO['DE037'], "99", $vaISO['DE044'], $cResponse, $vaISO['DE052'], $vaISO['DE061'], $vaISO['DE102'], $vaISO['DE103']);
      $vaResponse = MBankingFunc::ISO2Array($cResponse);
    }
    
    $vaResponse['RC']       = "00";
    $vaResponse['Message']  = $cError;
    return $vaResponse;
  }
	
	private function Ins2Digi($cHarga, $vaISO, $cSender, $cNSeri){
    $cKodeTrx       = substr($vaISO['DE003'], 0, 2);
		$cDE003   = $vaISO['DE003'];
    $cDE004   = $cHarga;
    $cDE048   = $vaISO['DE048'];
		$cDE039   = $vaISO['DE039'];
		
		$cKodeAgen  = MBankingFunc::GetKodeAgenMobile($vaISO['DE061']);  
		$vaHrg      = MBankingFunc::GetHargaPPOB($cKodeAgen,$cDE004,$cDE048,$cDE003);
		$cDE004     = $vaHrg['HJ'];
		$cDE048     = $cDE048."*".$vaHrg['Margin'];
		
		$vaIsiDe48 = explode("*",$cDE048);
		$cTipeTRX = isset($vaIsiDe48[2])? $vaIsiDe48[2] : "";
		if($cKodeTrx == TRX_MB_PURCHASE || $cTipeTRX=="PAYEMONEY"){
			$cDE004     = $cHarga;
			$vMon	= explode("*",$cDE048);
			$cKPR = $vMon[3];
			$vAr			= array(
				"MTI"	=> "003",
				"MSG"	=> array(
					"Seri"	=> $cNSeri,
					"DE004"	=> $cDE004,
					"DE048"	=> $cDE048,
					"DE061"	=> $vaISO['DE061'],
					"DE102"	=> $vaISO['DE102'],
					"DE103"	=> $vaISO['DE103'],
					"AKode"			=> GetKeterangan($cKPR,"AKode","mapp_de_transaksi","Kode_Stock"),
					"ABillerID"	=> GetKeterangan($cKPR,"ABillerID","mapp_de_transaksi","Kode_Stock"),
					"AProductID"=> GetKeterangan($cKPR,"AProductID","mapp_de_transaksi","Kode_Stock")
				)
			);

			$cMS = json_encode($vAr);
			objData::Insert("sms_inbox", array("SMSFrom" => $cSender, "Agen" => $cKodeAgen, "Jenis" => "I", "Protocol" => "M", "GroupSMS" => "B", "Message" => "DIGI INS(Rq) ".$cMS, "DateTime" => time(), "Proses" => "1", "Response" => "mBanking", "IMEI" => "GPRS"));
			$cM	= "cCode=" . $cMS;
			$cU	= $this->GetConfig('s');
			$vaH = array(
				'authorization: ' . hash('sha256',$cMS.SNow()),
				'identity: ' . $this->GetConfig('cicd'),
				'datetime: ' . SNow(),
				'Content-Type: application/x-www-form-urlencoded'
			);
			$cResponse  = SendHTTPPost($cU,$cM,'',false,$vaH);
			$cResponse  = ltrim($cResponse);
			$vaResponse	= json_decode($cResponse,1);
			$vaResponse = json_decode($vaResponse["data"],1);
			objData::Insert("sms_inbox", array("SMSFrom" => $cSender, "Agen" => $cKodeAgen, "Jenis" => "I", "Protocol" => "M", "GroupSMS" => "B", "Message" => "DIGI INS(Rs) ".$cResponse, "DateTime" => time(), "Proses" => "1", "Response" => "mBanking", "IMEI" => "GPRS"));
			if($vaResponse['RC']!="00"){
				$cRettt = $vaResponse['MSG'];
				$cResponse = MBankingFunc::JSON2ISO(false,$vaISO['DE003'],$vaISO['DE004'],date("hi"),date("dm"),"000000000000","XR","0",$cRettt,"0000000000000000000000000000000000000000000000000000000000000000","000000000000000000000000","0","0");
				
				$vaResponse = MBankingFunc::ISO2Array($cResponse);
				return $vaResponse;
			}
		}
	}
	
	/*assist pro*/
	private function LoadKirimUangAPro($va){
		//print_r('wleowleo');
		$va1	= array();
		
		$cUsn	= $va['Username'];
		# bikin filter per jenis transaksi
		$vaJenis = array(
			"P" => 0,
			"S" => 0,
		) ;
		
		$vaJenis ['P'] = 1 ;
		//if(isset($va ['ckSMS'])) $vaJenis ['S'] = 1 ;

		# bikin filter per agen
		$cAgen      = $va['Agen'];
		$cWhereAgen = "";
		if($cAgen <> "") $cWhereAgen = " and Agen = '$cAgen'";

		# bikin filter per supplier
		$cSupplier      = $va['Supplier'];
		$cWhereSupplier = "";
		if($cSupplier <> "") $cWhereSupplier = " and Supplier = '$cSupplier'";

		# bikin filter utk menampilkan yg sms gratis atau tidak
		$cWhereKode = "";
		$cWhereKode = " and p.Kode <> 'P-03' and p.Kode <> 'S-00' and p.Kode <> 'S'"; // abaikan sms gratis

		# bikin filter per jenis transaksi PPOB (pulsa, pln, emoney, dll)
		$cJenisTrx     = $va['JenisTrx'];
		$cWhereTrxPPOB = ""; 
		if($cJenisTrx <> "") $cWhereTrxPPOB = " and JenisTrx = '$cJenisTrx'";     

		# deklarasi variabel
		$nRow     = 0 ; 
		$nTotalHJ = 0 ;
		$nTotalHJPPN  = 0;
		$nTotalHB     = 0 ;
		$nTotalLaba   = 0 ;
		$nTotalPPn    = 0 ;
		$nTotalBonus  = 0 ;  
		$cDetail        = "<div style=background-color:#1E90FF;border-radius:3px;><b><i>Log</i></b></div>";  
		$cRefund        = "<div style=background-color:#53c9e0;border-radius:3px;><b><i>Refund</i></b></div>";
		$cPenyelesaian  = "<div style=background-color:#57ebb2;border-radius:3px;><b><i>Input Penyelesaian</i></b></div>";

		$cTglAwal    = Date2String($va['TglAwal']) ;
		$cTglAkhir   = Date2String($va['TglAkhir']) ;
		$vaJoin      = array("Left Join stock s on s.Kode = p.Kode","Left Join agen a on a.Kode = p.Agen","Left Join supplier sp on sp.Kode = p.Supplier") ; 
		$cSort       = $va['Sorting'];        
		$cOrderBy    = $cSort;
		$cWhereQuery = "p.Tgl >= '$cTglAwal' and p.Tgl <= '$cTglAkhir' and p.Status <> 'G' $cWhereAgen $cWhereSupplier $cWhereKode $cWhereTrxPPOB";

		# mulai proses load data berdasarkan rentang tgl serta filter yg dipilih
		for($i=substr($cTglAwal,0,4); $i<=substr($cTglAkhir,0,4); $i++){    
			$cTable = $i == date('Y') ? "pulsa_penjualan p" : "pulsa_penjualan_$i p";
			$dbData = objData::Browse($cTable,"p.*,a.Nama as NamaAgen,s.Nama As NamaStock,s.HB as HargaStock,sp.Nama as NamaSupplier",$cWhereQuery,$vaJoin,"",$cOrderBy) ;
			while($dbRow = objData::GetRow($dbData)){
				if($dbRow ['Status'] == "S" && isset($vaJenis [$dbRow ['Jenis']])){
					$cDT  = date("d-m-Y H:i",$dbRow['DateTime']);
					$nPPn = 0 ;

					$dbRow['TrxID'] = explode(".", $dbRow['TrxID'])[0]; # editan fuunani

					if(isset($vaJenis ['S'])){  
						//$nPPn   = 0 ;
						$dbD = objData::Browse("smsmasking_kirim","PPN","TRXID = '{$dbRow['TrxID']}'");   
						if($dbR = objData::GetRow($dbD)){
							$nPPn = $dbR['PPN'] ;
						}
						//$nPPn = HitungPPN($dbRow['TrxID']) ; //aCfg("msPPNSMSMasking") / 100 * $dbRow['HB'];  
					} 

					$cSN = $dbRow['SN'];
					if(substr($dbRow['Kode'],0,3) == "PLN") $cSN = $dbRow['PLN_Token'];
					if($dbRow['Kode'] == "PAYPLN"){
						$vator = json_decode( str_replace("\\","",$dbRow['TRXOrderResponse']),true );
						$cSN = isset($vator['swreferencenumber']) ? $vator['swreferencenumber'] : "";  
					}

					$cTrxID1 = $dbRow['TrxID'];
					$cTrxID2 = $dbRow['Fastpay_Ref'];

					# fanani: penambahan untuk emoney open denom
					$cKodeProduk = $dbRow['Kode'];
					if ($cKodeProduk == "PAYEMONEY") {
						$cKodeProduk = !empty($dbRow["OpenDenom_Kode"]) ? $dbRow["OpenDenom_Kode"] : $cKodeProduk;
					}
					
					# 2026-03-13: suspect $lSuspicious bernilai TRUE
					$lSuspicious = (strpos($dbRow['Kode'], 'PAYTFDANA') === 0 || strpos($dbRow['Kode'], 'PAYBIFAST') === 0) && stripos($dbRow["SN"], "Check Bank Statement") !== false;
					
					if ($lSuspicious) {
						$va1[$dbRow['ID']] = array(
							"No"=>++$nRow,
							$dbRow ['Nomor'],
							$cDT,
							$dbRow['HPSender'],
							$dbRow ['Agen'],
							$dbRow ['NamaAgen'],
							$cKodeProduk,
							$dbRow ['HP'],
							$dbRow ['Supplier'],
							$dbRow ['NamaSupplier'],
							$dbRow['ID_SMSBanking'],
							$cSN,
							$cRefund,
						) ;
					}
				}
			}
		}
		
		$vaArr = array(
			"Agen" 				=> $cAgen,
			"Dari" 				=> "LoadKirimUang",
			"Faktur"			=> "",
			"Message"			=> json_encode($va),
			"ID_SMSInbox"	=> "",
			"DateTime"		=> SNow()
		);
		objData::Insert("log_ppob_gagal",$vaArr,false);
		
		return $va1;
	}
	
	// Selasa 05-05-2026 Denta 
	private function RiwayatRefund($va){
		$va1	= array();
		
		$cUsn	= $va['Username'];
		# bikin filter per jenis transaksi
		$vaJenis = array(
			"P" => 0,
			"S" => 0,
		) ;
		
		$vaJenis ['P'] = 1 ;
		//if(isset($va ['ckSMS'])) $vaJenis ['S'] = 1 ;

		# bikin filter per agen
		$cAgen      = $va['Agen'];
		$cWhereAgen = "";
		if($cAgen <> "") $cWhereAgen = " and Agen = '$cAgen'";

		# bikin filter per supplier
		$cSupplier      = $va['Supplier'];
		$cWhereSupplier = "";
		if($cSupplier <> "") $cWhereSupplier = " and Supplier = '$cSupplier'";

		# bikin filter utk menampilkan yg sms gratis atau tidak
		$cWhereKode = "";
		$cWhereKode = " and p.Kode <> 'P-03' and p.Kode <> 'S-00' and p.Kode <> 'S'"; // abaikan sms gratis

		# bikin filter per jenis transaksi PPOB (pulsa, pln, emoney, dll)
		$cJenisTrx     = $va['JenisTrx'];
		$cWhereTrxPPOB = ""; 
		if($cJenisTrx <> "") $cWhereTrxPPOB = " and JenisTrx = '$cJenisTrx'";     

		# deklarasi variabel
		$nRow     			= 0 ; 
		$nTotalHJ 			= 0 ;
		$nTotalHJPPN  	= 0	;
		$nTotalHB     	= 0 ;
		$nTotalLaba   	= 0 ;
		$nTotalPPn    	= 0 ;
		$nTotalBonus  	= 0 ;  
		$cDetail        = "<div style=background-color:#1E90FF;border-radius:3px;><b><i>Log</i></b></div>";  
		$cRefund        = "<div style=background-color:#53c9e0;border-radius:3px;><b><i>Refund</i></b></div>";
		$cPenyelesaian  = "<div style=background-color:#57ebb2;border-radius:3px;><b><i>Input Penyelesaian</i></b></div>";

		$cTglAwal    		= Date2String($va['TglAwal']) ;
		$cTglAkhir   		= Date2String($va['TglAkhir']) ;
		$vaJoin      		= array("Left Join stock s on s.Kode = p.Kode","Left Join agen a on a.Kode = p.Agen","Left Join supplier sp on sp.Kode = p.Supplier") ; 
		$cSort       		= $va['Sorting'];        
		$cOrderBy    		= $cSort;
		$cWhereQuery 		= "p.Tgl >= '$cTglAwal' and p.Tgl <= '$cTglAkhir' and p.Status = 'G'  $cWhereAgen $cWhereSupplier $cWhereKode $cWhereTrxPPOB"; #and p.Status <> 'P'

		# mulai proses load data berdasarkan rentang tgl serta filter yg dipilih
		for($i=substr($cTglAwal,0,4); $i<=substr($cTglAkhir,0,4); $i++){    
			$cTable = $i == date('Y') ? "pulsa_penjualan p" : "pulsa_penjualan_$i p";
			$dbData = objData::Browse($cTable,"p.*,a.Nama as NamaAgen,s.Nama As NamaStock,s.HB as HargaStock,sp.Nama as NamaSupplier",$cWhereQuery,$vaJoin,"",$cOrderBy) ;
			while($dbRow = objData::GetRow($dbData)){
				if($vaJenis [$dbRow ['Jenis']] == '1'){
					$cDT	  = date("d-m-Y",$dbRow['DateTime']); # 13 mei
					$cTime	= date("H:i:s",$dbRow['DateTime']);
					$nPPn 	= 0 ;

					$dbRow['TrxID'] = explode(".", $dbRow['TrxID'])[0]; #

					if(isset($vaJenis ['S'])){  
						//$nPPn   = 0 ;
						$dbD = objData::Browse("smsmasking_kirim","PPN","TRXID = '{$dbRow['TrxID']}'");   
						if($dbR = objData::GetRow($dbD)){
							$nPPn = $dbR['PPN'] ;
						}
						//$nPPn = HitungPPN($dbRow['TrxID']) ; //aCfg("msPPNSMSMasking") / 100 * $dbRow['HB'];  
					} 

					$cSN = $dbRow['SN'];
					if(substr($dbRow['Kode'],0,3) == "PLN") $cSN = $dbRow['PLN_Token'];
					if($dbRow['Kode'] == "PAYPLN"){
						$vator = json_decode( str_replace("\\","",$dbRow['TRXOrderResponse']),true );
						$cSN = isset($vator['swreferencenumber']) ? $vator['swreferencenumber'] : "";  
					}

					$cTrxID1 = $dbRow['TrxID'];
					$cTrxID2 = $dbRow['Fastpay_Ref'];

					# fanani: penambahan untuk emoney open denom
					$cKodeProduk = $dbRow['Kode'];
					if ($cKodeProduk == "PAYEMONEY") {
						$cKodeProduk = !empty($dbRow["OpenDenom_Kode"]) ? $dbRow["OpenDenom_Kode"] : $cKodeProduk;
					}
					
					# 2026-03-13: suspect $lSuspicious bernilai TRUE
					//$lSuspicious = (strpos($dbRow['Kode'], 'PAYTFDANA') === 0 || strpos($dbRow['Kode'], 'PAYBIFAST') === 0) && stripos($dbRow["SN"], "Check Bank Statement") !== false;
					
					//if ($lSuspicious) {
					
						$cStartTime	= $dbRow['DateTime'];
						$waktu 			= $dbRow['WaktuGagal'] ??  "";
						$userRefund = isset($dbRow['UserRefund']) ? $dbRow['UserRefund'] : "";
					// <--------edit --------->
						$selisih = strtotime($waktu) - $cStartTime;
						$jam = floor($selisih / 3600);
						$menit = floor(($selisih % 3600) / 60);
						$detik = $selisih % 60;
						$cSelisih = sprintf('%02d:%02d:%02d', $jam, $menit, $detik);
					//

						$va1[$dbRow['ID']] = array(
							"No"=>++$nRow,
							$dbRow['ID_SMSBanking'],
							$dbRow ['Nomor'],
							$cDT,
							$cTime,
							$waktu,
							$cSelisih,
							$dbRow['HPSender'],
							$cKodeProduk,
							$dbRow ['HP'],
							$dbRow ['NamaSupplier'],
							$cSN,
							$dbRow['HJ_Nasabah'],
							$userRefund,
							//$cRefund, "no","fakturagen","nomor","tgl","jam","waktuRefund","selisih","sender","kode","nohp","namasupplier","hn","nominal","userRefund"
							// "no","nomor","tgl","sender","agen","namaAgen","kode","nohp","supplier","namasupplier","fakturagen","hn","nominal","Waktu Gagal", "userRefund"
						) ;
					//}
				}
			}
		}
		
		$vaArr = array(
			"Agen" 				=> $cAgen,
			"Dari" 				=> "RiwayatRefund",
			"Faktur"			=> "",
			"Message"			=> json_encode($va),
			"ID_SMSInbox"	=> "",
			"DateTime"		=> SNow()
		);
		objData::Insert("log_ppob_gagal",$vaArr,false);
		
		return $va1;
	}
	
	
	
	private function YukRefundTransaksi($va){
		//return "test";
		$cFakturCBS	= $va['FakturCBS'];
		$cAgen 			= $va['Agen'];
		$cFaktur		= $va['Faktur'];
		
		//$cMSG   = "cCode=" . json_encode(array("MTI"=>"010","KT"=>"10","Faktur"=>$cFakturCBS));
		
		$cUsn	= $va['Username'];
		$vaAgen = getDataAgen($cAgen);
		if($vaAgen['SupportRefundPPOB'] == 1){
			# 2026-03-13 fanani: code baru untuk menggagalkan
			# 11-05-2026 Denta: penambahan insert untuk menyimpan waktu refund dan user refund
			if (!empty($cFaktur)) {
				$vaInsert = array("WaktuGagal"=>SNow(), "UserRefund"=>$cUsn);
				$vaEdit = array("Status"=>"G");
				objData::Update("pulsa_penjualan",$vaInsert,"Nomor = '$cFaktur'");
				objData::Edit("pulsa_penjualan",$vaEdit,"Nomor = '$cFaktur'");
				objData::Delete("bukubesar","Faktur = '$cFaktur'");
			}
			
			//$cRes   = SendHTTPPost_old($cMSG, $vaAgen['URLMobile']);
			/*$cURL = $vaAgen['URL']; //$cURL == "" ? $vaAgen['URL'] : "http://mcoll.sis1.net:2735/assist-switching/index.php" ;
			$ch = curl_init($cURL);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 20);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $cMSG);
			curl_setopt($ch, CURLOPT_FAILONERROR, true);
			$cRes = curl_exec($ch);
			
			//$vaRes  = json_decode($cRes,true);
			$cData = "ok";//$vaRes == null ? $cRes : $vaRes['MSG'];
			# gagalkan di sisi assist pro
			if($cFaktur <> ''){
				$objData->Edit("pulsa_penjualan",array("Status"=>"G","SN"=>""),"Nomor = '$cFaktur'");
				$objData->Delete("bukubesar","Faktur = '$cFaktur'");
			}*/
			
			$vaArr = array(
				"Agen" 				=> $cAgen,
				"Dari" 				=> "OtorisasiKirimUang",
				"Faktur"			=> $cFakturCBS . " - " . $cFaktur,
				"Message"			=> json_encode($va),
				"ID_SMSInbox"	=> "",
				"DateTime"		=> SNow(),
			);
			objData::Insert("log_ppob_gagal",$vaArr);
			
			$cData = "Ok";
		}else{
			$cData = "Agen belum support refund PPOB. Hubungi anak atas ya";
		}
		return $cData;
	}
	
	private function LogActivity($va){
		//return "test";
		$cFakturCBS	= $va['FakturCBS'];
		$cAgen 			= $va['Agen'];
		$cFaktur		= $va['Faktur'];
		
		//$cMSG   = "cCode=" . json_encode(array("MTI"=>"010","KT"=>"10","Faktur"=>$cFakturCBS));
		
		$cUsn	= $va['Username'];
		$vaAgen = getDataAgen($cAgen);
		if($vaAgen['SupportRefundPPOB'] == 1){
			# 2026-03-13 fanani: code baru untuk menggagalkan
			# 11-05-2026 Denta: penambahan insert untuk menyimpan waktu refund dan user refund
			if (!empty($cFaktur)) {
				$vaInsert = array("WaktuGagal"=>SNow(), "UserRefund"=>$cUsn);
				$vaEdit = array("Status"=>"G");
				objData::Update("pulsa_penjualan",$vaInsert,"Nomor = '$cFaktur'");
				objData::Edit("pulsa_penjualan",$vaEdit,"Nomor = '$cFaktur'");
				objData::Delete("bukubesar","Faktur = '$cFaktur'");
			}
		
			
			$vaArr = array(
				"Agen" 				=> $cAgen,
				"Dari" 				=> "OtorisasiKirimUang",
				"Faktur"			=> $cFakturCBS . " - " . $cFaktur,
				"Message"			=> json_encode($va),
				"ID_SMSInbox"	=> "",
				"DateTime"		=> SNow(),
			);
			objData::Insert("log_ppob_gagal",$vaArr);
			
			$cData = "Ok";
		}else{
			$cData = "Agen belum support refund PPOB. Hubungi anak atas ya";
		}
		return $cData;
	}
}