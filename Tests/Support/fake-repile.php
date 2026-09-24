<?php

$dir = getenv('REPILE_FAKE_DIR');
file_put_contents($dir.'/requests.jsonl', json_encode([
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    'body' => file_get_contents('php://input'),
])."\n", FILE_APPEND);

$response = json_decode((string) @file_get_contents($dir.'/response.json'), true) ?: [];
http_response_code($response['status'] ?? 200);
header('Content-Type: '.($response['type'] ?? 'application/json'));
echo $response['body'] ?? '{}';
