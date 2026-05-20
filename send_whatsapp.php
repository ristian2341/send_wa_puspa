<?php

// send_whatsapp.php (menggunakan Fonnte API & MySQL)
// 1) Konfigurasi Fonnte API & Database
// 2) PHP native cURL & PDO
// 3) Run di CLI atau via AJAX

require_once __DIR__ . '/config.php';

function getDbConnection($config)
{
    try {
        $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['user'], $config['pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => "Koneksi database gagal: " . $e->getMessage()]);
        exit;
    }
}

function sendWhatsappFonnte(array $cfg, $target, $message)
{
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => array(
            'target' => $target,
            'message' => $message,
            'countryCode' => '62', // otomatis tambahkan 62 di depan jika nomor diawali 0
        ),
        CURLOPT_HTTPHEADER => array(
            'Authorization: ' . $cfg['token']
        ),
    ));

    $response = curl_exec($curl);
    $errno = curl_errno($curl);
    $error = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($errno !== 0) {
        return [
            'success' => false,
            'error' => 'cURL error ' . $errno . ': ' . $error,
            'http_code' => $httpCode,
        ];
    }

    $decoded = json_decode($response, true);
    // Fonnte API biasanya return success berupa boolean di $decoded['status']
    $ok = isset($decoded['status']) && $decoded['status'] == true;

    return [
        'success' => $ok,
        'http_code' => $httpCode,
        'response' => isset($decoded) ? $decoded : $response,
        'error' => $ok ? null : (isset($decoded['reason']) ? $decoded['reason'] : 'Unknown error dari Fonnte')
    ];
}

function ensureLogsDir()
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function writeSendLog($code, $target, $sendResult)
{
    $dir = ensureLogsDir();
    $time = date('Y-m-d H:i:s');
    
    // Tentukan status string berdasarkan hasil API Fonnte
    $statusText = $sendResult['success'] ? 'SUCCESS' : 'FAILED';
    $errorReason = !$sendResult['success'] ? ' | Error: ' . json_encode($sendResult['error']) : '';

    // Format log satu baris yang informatif dan rapi
    $logEntry = "[{$time}] [{$statusText}] Code: {$code} | Target: {$target} | Message: \"{$errorReason}" . PHP_EOL;
    
    // Nama file log harian tunggal (Contoh: wa_send_2026-05-20.log)
    $dailyFile = $dir . '/wa_send_' . date('Y-m-d') . '.log';
    
    // Simpan/tambahkan log ke file harian (FILE_APPEND)
    file_put_contents($dailyFile, $logEntry, FILE_APPEND);
}


function run()
{
    global $settings, $db_config;

    $is_cli = php_sapi_name() === 'cli';

    if ($is_cli) {
        echo "\n--- Auto Send WA (Fonnte + MySQL) ---\n";
        if (empty($settings['token'])) {
            echo "ERROR: token belum diset di variable \$settings.\n";
            exit(1);
        }
    }

    $pdo = getDbConnection($db_config);

    // Ambil data antrean dengan status 0
    $stmt = $pdo->query("SELECT * FROM _send_wa_history WHERE status = 1 limit 10");
    $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [
        'total_processed' => 0,
        'success_count' => 0,
        'failed_count' => 0,
        'details' => []
    ];

    if (count($queue) > 0) {
        $updateStmt = $pdo->prepare("UPDATE _send_wa_history SET status = :status, logs_send = :logs_send WHERE code = :code");

        foreach ($queue as $row) {
            // Asumsi nama kolom yang sering dipakai: id, nomor HP (target/phone), pesan (message/pesan)
            // Ganti nama kolom sesuai dengan field di table database Anda!
            $code = $row['code'];
            $nomor_wa = isset($row['nomor_wa']) ? $row['nomor_wa'] : (isset($row['phone']) ? $row['phone'] : (isset($row['no_hp']) ? $row['no_hp'] : ''));
            $message = isset($row['message']) ? $row['message'] : (isset($row['pesan']) ? $row['pesan'] : '');

            if (empty($nomor_wa) || empty($message)) {
                $results['failed_count']++;
                $results['details'][] = ['code' => $code, 'success' => false, 'error' => 'Nomor tujuan (target/phone) atau pesan (message/pesan) kosong di database'];
                $updateStmt->execute([
                    'status' => 3,
                    'logs_send' => json_encode(['error' => 'Target atau message kosong']),
                    'code' => $code
                ]);
                continue;
            }

            // Kirim pesan via Fonnte
            $sendResult = sendWhatsappFonnte($settings, $nomor_wa, $message);
    
            // Update status (2 = success, 3 = failed) dan simpan logs
            $newStatus = $sendResult['success'] ? 2 : 3;
            $updateStmt->execute([
                'status' => $newStatus,
                'logs_send' => json_encode($sendResult),
                'code' => $code
            ]);

            // tulis log ke file
            try {
                writeSendLog($code, $nomor_wa, $sendResult);
            } catch (Exception $e) {
                // jangan ganggu proses utama
            }

            $results['total_processed']++;
            if ($sendResult['success']) {
                $results['success_count']++;
            } else {
                $results['failed_count']++;
            }

            $results['details'][] = [
                'code' => $code,
                'target' => $nomor_wa,
                'success' => $sendResult['success'],
                'api_response' => $sendResult
            ];

            // Opsional: Kasih jeda kecil (1 detik) antar pesan untuk menghindari pemblokiran / rate limit Fonnte
            sleep(1);
        }
    }

    $finalOutput = [
        'success' => true,
        'message' => count($queue) > 0 ? 'Proses antrean selesai' : 'Tidak ada antrean (status=1) di database.',
        'data' => $results
    ];

    if ($is_cli) {
        if (count($queue) == 0) {
            echo "Tidak ada antrean (status=0).\n";
        } else {
            echo "Total diproses: " . $results['total_processed'] . "\n";
            echo "Berhasil: " . $results['success_count'] . "\n";
            echo "Gagal: " . $results['failed_count'] . "\n";
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode($finalOutput);
    }
}

run();
