<?php
// pull_and_enqueue.php
// Murni melakukan penarikan data dari tabel sumber dan memasukkan ke tabel send_wa_history

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config.php';

// Fungsi simpan log ke folder ../logs
function tulisLogKeFile($pesan) {
    $direktoriLog = __DIR__ . '/../logs';
    
    if (!is_dir($direktoriLog)) {
        mkdir($direktoriLog, 0755, true);
    }
    
    $namaFileLog = $direktoriLog . '/proses_data_' . date('dmy') . '.log';
    $logFormat = '[' . date('Y-m-d H:i:s') . '] ' . $pesan . PHP_EOL;
    file_put_contents($namaFileLog, $logFormat, FILE_APPEND);
}

function getDbConnectionSimple($config)
{
    try {
        $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['user'], $config['pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        $msgErr = "Koneksi database gagal: " . $e->getMessage();
        echo $msgErr . PHP_EOL;
        tulisLogKeFile($msgErr);
        exit(1);
    }
}

// =========================================================================
// REFAKTORING FUNGSI AMBIL DATA
// =========================================================================

/**
 * Mengambil data invoice iuran berdasarkan periode berjalan
 */
function getInvoiceIuran($pdo)
{
    $sql = "SELECT tn.kode_tub, tn.wa, tn.nama, ii.invoice_number, ii.periode, ii.jumlah, ii.payment 
            FROM _tenant tn 
            INNER JOIN `_invoice_iuran` ii ON tn.id_tenant = ii.id_tenant 
            WHERE ii.periode = '0426'
              AND (ii.jumlah - ii.payment) > 0
              AND tn.wa <> ''
            ORDER BY ii.periode ASC 
            LIMIT 100";
    
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mengambil data invoice utility (Listrik/Air) berdasarkan periode berjalan
 * Catatan: Sesuaikan nama tabel dan kolom utility jika berbeda di database Anda
 */
function getInvoiceUtility($pdo)
{
    $sql = "SELECT tn.kode_tub, tn.wa, tn.nama, iu.invoice_number, iu.periode, iu.jumlah, iu.payment 
            FROM _tenant tn 
            INNER JOIN `_invoice_utility` iu ON tn.id_tenant = iu.id_tenant 
            WHERE iu.periode = DATE_FORMAT(NOW(), '%m%y') 
              AND (iu.jumlah - iu.payment) > 0
              AND tn.wa <> ''
            ORDER BY iu.periode ASC 
            LIMIT 100";
            
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fungsi pembantu untuk memformat periode '0526' menjadi 'Mey 2026'
 */
function formatPeriodeIndo($periodeStr)
{
    $bulanDigit = substr($periodeStr, 0, 2);
    $tahunDigit = substr($periodeStr, 2, 2);
    $tahunPenuh = "20" . $tahunDigit;

    $daftarBulan = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', 
        '04' => 'April',   '05' => 'Mey',      '06' => 'Juni', 
        '07' => 'Juli',    '08' => 'Agustus',  '09' => 'September', 
        '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
    ];

    $namaBulan = isset($daftarBulan[$bulanDigit]) ? $daftarBulan[$bulanDigit] : $bulanDigit;
    return $namaBulan . " " . $tahunPenuh;
}

/**
 * Fungsi memproses dan memasukkan data ke antrean wa history
 */
function enqueueData($rows, $tipeInvoice, $hariIni, &$nomorUrut, $stmt_insert)
{
    $insertedCount = 0;
    
    foreach ($rows as $row) {
        // Generate Code: YYYYMMDD00001
        $formattedCode = $hariIni . str_pad($nomorUrut, 5, '0', STR_PAD_LEFT);
        
        // Format Periode ke "Mey 2026"
        $periodeFormat = formatPeriodeIndo($row['periode']);
        $sisaTagihan = $row['jumlah'] - $row['payment'];
        
        // Bedakan sedikit template pesan berdasarkan tipe invoice jika diperlukan
        $pesanWhatsApp = "Kepada Yth.\n" .
            "Bapak/Ibu " . $row['nama'] . " [" . $row['kode_tub'] . "]\n" .
            "Di Tempat\n\n" .
            "Dengan hormat,\n" .
            "Bersama pesan ini, kami lampirkan/kirimkan invoice " . $tipeInvoice . " untuk unit tenant " . $row['kode_tub'] . " dengan rincian sebagai berikut:\n\n" .
            "Nomor Invoice: " . $row['invoice_number'] . "\n" .
            "Periode: " . $periodeFormat . "\n" .
            "Total Tagihan: Rp " . number_format($sisaTagihan, 0, ',', '.') . ",-\n\n" .
            "Mohon dapat segera melakukan pembayaran sebelum jatuh tempo. Apabila Bapak/Ibu sudah melakukan pembayaran, mohon abaikan pemberitahuan ini.\n\n" .
            "Atas perhatian dan kerja samanya, kami ucapkan terima kasih.";

        // Eksekusi insert data
        $stmt_insert->execute([
            ':code'         => $formattedCode,
            ':nomor_wa'     => $row['wa'],
            ':penerima'     => $row['nama'],
            ':no_transaksi' => $row['invoice_number'],
            ':message'      => $pesanWhatsApp,
            ':logs_send'    => "",
            ':status'       => 1
        ]);

        $nomorUrut++; // Terus bertambah untuk baris berikutnya
        $insertedCount++;
    }
    
    return $insertedCount;
}

// =========================================================================
// JALANKAN PROSES UTAMA
// =========================================================================

// Koneksi ke database
$pdo = getDbConnectionSimple($db_config);
$totalDiproses = 0;

try {
    // 1. Hitung / Ambil nomor urut awal hari ini dari database
    $hariIni = date('20260401');
    $sql_cek_urut = "SELECT code FROM `_send_wa_history` 
                     WHERE code LIKE :hariIni 
                     ORDER BY code DESC LIMIT 1";
    $stmt_cek = $pdo->prepare($sql_cek_urut);
    $stmt_cek->execute([':hariIni' => $hariIni . '%']);
    $lastRecord = $stmt_cek->fetch(PDO::FETCH_ASSOC);

    if ($lastRecord) {
        $noUrutTerakhir = (int) substr($lastRecord['code'], -5);
        $nomorUrut = $noUrutTerakhir + 1;
    } else {
        $nomorUrut = 1;
    }

    // 2. Siapkan prepare statement INSERT yang akan digunakan bersama
    $sql_insert = "INSERT INTO `_send_wa_history`
                   (code, nomor_wa, penerima, no_transaksi, message, logs_send, waktu_kirim, status)
                   VALUES 
                   (:code, :nomor_wa, :penerima, :no_transaksi, :message, :logs_send, NOW(), :status)";
    $stmt_insert = $pdo->prepare($sql_insert);

    // -----------------------------------------------------------------
    // PROSES 1: INVOICE IURAN
    // -----------------------------------------------------------------
    tulisLogKeFile("Memulai penarikan data Invoice Iuran...");
    $dataIuran = getInvoiceIuran($pdo);
    
    if (count($dataIuran) > 0) {
        $jumlahIuran = enqueueData($dataIuran, "Layanan", $hariIni, $nomorUrut, $stmt_insert);
        $totalDiproses += $jumlahIuran;
        tulisLogKeFile("Berhasil mengantrekan {$jumlahIuran} data Invoice Iuran.");
    } else {
        tulisLogKeFile("Tidak ada data Invoice Iuran hari ini.");
    }

    // -----------------------------------------------------------------
    // PROSES 2: INVOICE UTILITY
    // -----------------------------------------------------------------
    tulisLogKeFile("Memulai penarikan data Invoice Utility...");
    $dataUtility = getInvoiceUtility($pdo);
    
    if (count($dataUtility) > 0) {
        // Variabel $nomorUrut dilemparkan kembali dan otomatis melanjutkan urutan terakhir dari Proses 1
        $jumlahUtility = enqueueData($dataUtility, "Maintainance", $hariIni, $nomorUrut, $stmt_insert);
        $totalDiproses += $jumlahUtility;
        tulisLogKeFile("Berhasil mengantrekan {$jumlahUtility} data Invoice Utility.");
    } else {
        tulisLogKeFile("Tidak ada data Invoice Utility hari ini.");
    }

} catch (Exception $e) {
    $msgErr = "Terjadi kesalahan saat memproses data: " . $e->getMessage();
    echo $msgErr . PHP_EOL;
    tulisLogKeFile($msgErr);
    exit(1);
}

// Output respon akhir
$msgSelesai = "Selesai: Total keseluruhan {$totalDiproses} baris data berhasil diproses ke _send_wa_history.";
echo $msgSelesai . PHP_EOL;
tulisLogKeFile($msgSelesai);

exit(0);