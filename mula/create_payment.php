<?php
header('Content-Type: application/json');

// =====================================================
// 1. KREDENSIAL DOKU SANDBOX
//    Ambil dari DOKU Back Office > Integration > API Keys
//    Client ID berbentuk BRN-xxxx-xxxx
//    Secret Key berbentuk SK-xxxx (bukan API key lain)
// =====================================================
$clientId  = trim('BRN-0211-1792390106492');
$secretKey = trim('SK-ZeZNe8eEbvQ73l60RW8S');

$isLocal   = true; // ubah ke false saat sudah online (hosting)

// =====================================================
// 2. AMBIL DATA PESANAN DARI FRONTEND
// =====================================================
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['orderNo']) || empty($input['total']) || empty($input['customerName'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Data pesanan tidak valid']);
    exit;
}

// =====================================================
// 3. SUSUN DATA TRANSAKSI
// =====================================================
$orderNo = preg_replace('/[^A-Za-z0-9\-_]/', '', $input['orderNo']); // buang # dan karakter aneh
$amount  = (int) round($input['total']);

$params = array(
    'order' => array(
        'invoice_number' => $orderNo,
        'amount'         => $amount,
        'callback_url'   => $input['callbackUrl'] ?? ''
    ),
    'payment' => array(
        'payment_due_date' => 60 // menit
    ),
    'customer' => array(
        'name' => $input['customerName']
    )
);

// =====================================================
// 4. SIGNATURE (urutan komponen WAJIB persis begini)
// =====================================================
$requestId     = bin2hex(random_bytes(12));
$dateTime      = gmdate('Y-m-d\TH:i:s\Z');
$targetService = '/checkout/v1/payment';

$jsonBody = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$digest   = base64_encode(hash('sha256', $jsonBody, true));

$rawSignature = "Client-Id:" . $clientId . "\n"
              . "Request-Id:" . $requestId . "\n"
              . "Request-Timestamp:" . $dateTime . "\n"
              . "Request-Target:" . $targetService . "\n"
              . "Digest:" . $digest;

$signature = base64_encode(hash_hmac('sha256', $rawSignature, $secretKey, true));

// =====================================================
// 5. KIRIM KE DOKU SANDBOX
// =====================================================
$ch = curl_init('https://api-sandbox.doku.com' . $targetService);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Client-Id: ' . $clientId,
    'Request-Id: ' . $requestId,
    'Request-Timestamp: ' . $dateTime,
    'Signature: HMACSHA256=' . $signature
));

// XAMPP/Laragon lokal sering tidak punya CA bundle
if ($isLocal) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Gagal terhubung ke DOKU: ' . $curlErr]);
    exit;
}

// =====================================================
// 6. KEMBALIKAN HASIL KE FRONTEND
// =====================================================
$result = json_decode($response, true);

if ($httpCode >= 200 && $httpCode < 300 && isset($result['response']['payment']['url'])) {
    echo json_encode([
        'payment_url' => $result['response']['payment']['url'],
        'token_id'    => $result['response']['payment']['token_id'] ?? null
    ]);
} else {
    http_response_code($httpCode ?: 500);
    echo json_encode([
        'error'  => 'DOKU menolak request',
        'detail' => $result ?: $response
    ]);
}