<?php
// pull_and_enqueue.php
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

/**
 * Mengambil data gabungan Iuran dan Utility berdasarkan periode tertentu
 */
function getGabunganInvoice($pdo, $periode)
{
    $sql = "SELECT 
                tn.kode_tub, 
                tn.nama, 
                tn.wa,
                COALESCE(ii.periode, iu.periode) AS periode,
                IFNULL(ii.invoice_number, '-') AS no_iuran,
                IFNULL(ii.jumlah - ii.payment, 0) AS tagihan_iuran,
                IFNULL(iu.invoice_number, '-') AS no_utility,
                IFNULL(iu.jumlah - iu.payment, 0) AS tagihan_utility
            FROM _tenant tn
            LEFT JOIN _invoice_iuran ii ON tn.id_tenant = ii.id_tenant AND ii.periode = :periode
            LEFT JOIN _invoice_utility iu ON tn.id_tenant = iu.id_tenant AND iu.periode = :periode
            WHERE tn.wa <> '' 
              AND (
                  (ii.jumlah - ii.payment) > 0 
                  OR (iu.jumlah - iu.payment) > 0
              )
            ORDER BY tn.kode_tub ASC
            LIMIT 100";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':periode' => $periode]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Format periode '0526' menjadi 'Mei 2026'
 */
function formatPeriodeIndo($periodeStr)
{
    $bulanDigit = substr($periodeStr, 0, 2);
    $tahunDigit = substr($periodeStr, 2, 2);
    $tahunPenuh = "20" . $tahunDigit;

    $daftarBulan = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', 
        '04' => 'April',   '05' => 'Mei',      '06' => 'Juni', 
        '07' => 'Juli',    '08' => 'Agustus',  '09' => 'September', 
        '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
    ];

    $namaBulan = isset($daftarBulan[$bulanDigit]) ? $daftarBulan[$bulanDigit] : $bulanDigit;
    return $namaBulan . " " . $tahunPenuh;
}

// =========================================================================
// JALANKAN PROSES UTAMA
// =========================================================================

$pdo = getDbConnectionSimple($db_config);
$totalDiproses = 0;

// Tentukan periode yang ingin ditarik (Contoh: '0321' sesuai query kamu)
$periodeTarget = date('md'); 

try {
    // 1. Hitung / Ambil nomor urut awal hari ini dari database
    $hariIni = date('Ymd');
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

    // 2. Siapkan prepare statement INSERT
    $sql_insert = "INSERT INTO `_send_wa_history`
                   (code, nomor_wa, penerima, kode_tub, no_transaksi, message, logs_send, waktu_kirim, status)
                   VALUES 
                   (:code, :nomor_wa, :penerima, :kode_tub, :no_transaksi, :message, :logs_send, NOW(), :status)";
    $stmt_insert = $pdo->prepare($sql_insert);

    // 3. Tarik data gabungan
    tulisLogKeFile("Memulai penarikan data gabungan Invoice Iuran & Utility periode {$periodeTarget}...");
    $dataInvoice = getGabunganInvoice($pdo, $periodeTarget);
    
    if (count($dataInvoice) > 0) {
        foreach ($dataInvoice as $row) {
            $formattedCode = $hariIni . str_pad($nomorUrut, 5, '0', STR_PAD_LEFT);
            $periodeFormat = formatPeriodeIndo($row['periode']);
            
            // Susun rincian dinamis berdasarkan komponen yang ada nilainya
            $rincianTagihan = "";
            $totalTagihan = 0;
            $nomorInvoiceGabungan = [];

            if ($row['tagihan_iuran'] > 0) {
                $rincianTagihan .= "- Tagihan Iuran (" . $row['no_iuran'] . "): Rp " . number_format($row['tagihan_iuran'], 0, ',', '.') . ",-\n";
                $totalTagihan += $row['tagihan_iuran'];
                $nomorInvoiceGabungan[] = $row['no_iuran'];
            }

            if ($row['tagihan_utility'] > 0) {
                $rincianTagihan .= "- Tagihan Utility (" . $row['no_utility'] . "): Rp " . number_format($row['tagihan_utility'], 0, ',', '.') . ",-\n";
                $totalTagihan += $row['tagihan_utility'];
                $nomorInvoiceGabungan[] = $row['no_utility'];
            }

            // Teks pesan WhatsApp gabungan
            $pesanWhatsApp = "Kepada Yth.\n" .
                "Bapak/Ibu " . $row['nama'] . " [" . $row['kode_tub'] . "]\n" .
                "Di Tempat\n\n" .
                "Dengan hormat,\n" .
                "Bersama pesan ini, kami sampaikan rincian tagihan untuk unit tenant " . $row['kode_tub'] . " Periode " . $periodeFormat . " sebagai berikut:\n\n" .
                $rincianTagihan . "\n" .
                "Total Yang Harus Dibayar: *Rp " . number_format($totalTagihan, 0, ',', '.') . ",-*\n\n" .
                "Mohon dapat segera melakukan pembayaran sebelum jatuh tempo. Apabila Bapak/Ibu sudah melakukan pembayaran, mohon konfirmasi ke petugas penagihan.\n\n" .
                "Atas perhatian dan kerja samanya, kami ucapkan terima kasih.";

            // Simpan daftar invoice ke kolom no_transaksi (pisahkan koma jika ada dua)
            $noTransaksiSimpan = implode(', ', $nomorInvoiceGabungan);

            // Eksekusi insert data
            $stmt_insert->execute([
                ':code'         => $formattedCode,
                ':nomor_wa'     => $row['wa'],
                ':penerima'     => $row['nama'],
                ':kode_tub'     => $row['kode_tub'],
                ':no_transaksi' => $noTransaksiSimpan,
                ':message'      => $pesanWhatsApp,
                ':logs_send'    => "",
                ':status'       => 1
            ]);

            $nomorUrut++;
            $totalDiproses++;
        }
        tulisLogKeFile("Berhasil mengantrekan {$totalDiproses} data gabungan.");
    } else {
        tulisLogKeFile("Tidak ada data tagihan yang ditemukan untuk periode ini.");
    }

} catch (Exception $e) {
    $msgErr = "Terjadi kesalahan saat memproses data: " . $e->getMessage();
    echo $msgErr . PHP_EOL;
    tulisLogKeFile($msgErr);
    exit(1);
}

// Output respon akhir
$msgSelesai = "Selesai: Total keseluruhan {$totalDiproses} baris data berhasil digabungkan ke _send_wa_history.";
echo $msgSelesai . PHP_EOL;
tulisLogKeFile($msgSelesai);

exit(0);