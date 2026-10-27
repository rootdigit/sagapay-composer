<?php
// Stand-in for the SagaPay API, for `php -S`. Writes each request's headers
// to the file named by SAGAPAY_TEST_LOG, then answers like /create-withdrawal,
// or redirects when the path is /redirect/create-withdrawal.
$log = getenv('SAGAPAY_TEST_LOG');
file_put_contents($log, json_encode([
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
]) . "\n", FILE_APPEND);

if (str_starts_with($_SERVER['REQUEST_URI'], '/redirect/')) {
    http_response_code(307);
    header('Location: /elsewhere');
    echo '{}';
    return true;
}

http_response_code(201);
header('Content-Type: application/json');
echo json_encode(['id' => 'w-1', 'status' => 'PENDING', 'fee' => '0']);
return true;
