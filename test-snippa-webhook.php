<?php
// test-snippa-webhook.php
// Standalone script to test the Snippa webhook endpoint locally

$url = 'https://edible-sandbox.local/wp-json/snippa/v1/webhook';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local dev/self-signed certs

$response = curl_exec($ch);
if ($response === false) {
    echo 'cURL error: ' . curl_error($ch);
} else {
    echo "Response from webhook endpoint:\n";
    echo $response;
}
curl_close($ch); 