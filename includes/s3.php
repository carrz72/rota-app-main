<?php
// Minimal S3 uploader using Signature V4 for small files
function s3_upload_file($bucket, $key, $filePath) {
    $accessKey = getenv('AWS_ACCESS_KEY_ID') ?: ($_ENV['AWS_ACCESS_KEY_ID'] ?? null);
    $secretKey = getenv('AWS_SECRET_ACCESS_KEY') ?: ($_ENV['AWS_SECRET_ACCESS_KEY'] ?? null);
    $region = getenv('AWS_REGION') ?: ($_ENV['AWS_REGION'] ?? 'us-east-1');
    if (!$accessKey || !$secretKey) throw new Exception('AWS credentials not configured');
    $host = "$bucket.s3.amazonaws.com";
    $service = 's3';
    $method = 'PUT';
    $uri = '/' . ltrim($key, '/');
    $payload = file_get_contents($filePath);
    if ($payload === false) throw new Exception('Failed to read file for upload');
    $t = new DateTime('now', new DateTimeZone('UTC'));
    $amzDate = $t->format('Ymd\THis\Z');
    $dateStamp = $t->format('Ymd');

    $hashedPayload = hash('sha256', $payload);
    $headers = [
        'Host' => $host,
        'x-amz-content-sha256' => $hashedPayload,
        'x-amz-date' => $amzDate
    ];
    $canonicalHeaders = '';
    $signedHeadersArr = [];
    foreach ($headers as $k => $v) { $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n"; $signedHeadersArr[] = strtolower($k); }
    $signedHeaders = implode(';', $signedHeadersArr);
    $canonicalRequest = $method . "\n" . $uri . "\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $hashedPayload;
    $algorithm = 'AWS4-HMAC-SHA256';
    $credentialScope = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';
    $stringToSign = $algorithm . "\n" . $amzDate . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);
    // Create signing key
    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    $authorization = $algorithm . ' Credential=' . $accessKey . '/' . $credentialScope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
    $curl = curl_init();
    $url = 'https://' . $host . $uri;
    $curlHeaders = ["Authorization: $authorization", 'x-amz-content-sha256: ' . $hashedPayload, 'x-amz-date: ' . $amzDate];
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($curl, CURLOPT_HTTPHEADER, $curlHeaders);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HEADER, true);
    $resp = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($resp === false || $code < 200 || $code >= 300) {
        $err = curl_error($curl);
        curl_close($curl);
        throw new Exception('S3 upload failed: ' . $code . ' ' . $err . ' ' . substr($resp,0,200));
    }
    curl_close($curl);
    return true;
}
