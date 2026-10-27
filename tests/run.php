<?php
// Dependency-free tests: php tests/run.php

spl_autoload_register(function (string $class): void {
    $prefix = 'SagaPay\\SDK\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../src/' . substr($class, strlen($prefix)) . '.php';
    }
});

use SagaPay\SDK\Client;
use SagaPay\SDK\Exception;
use SagaPay\SDK\Util;
use SagaPay\SDK\WebhookHandler;

$failures = 0;
$count = 0;

function check(string $name, callable $test): void
{
    global $failures, $count;
    $count++;
    try {
        $test();
        echo "ok   {$name}\n";
    } catch (\Throwable $e) {
        $failures++;
        echo "FAIL {$name}: {$e->getMessage()}\n";
    }
}

function assertTrue(bool $condition, string $message = 'expected true'): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function assertThrows(callable $fn, int $code): void
{
    try {
        $fn();
    } catch (Exception $e) {
        assertTrue($e->getCode() === $code, "expected code {$code}, got {$e->getCode()}: {$e->getMessage()}");
        return;
    }
    throw new \RuntimeException('expected an exception');
}

// A merchant signing secret is a 64-character hex string, used as the HMAC
// key exactly as written.
const SECRET = 'a3f1c2e4b5d697881234567890abcdef00112233445566778899aabbccddeeff';
const BODY = '{"id":"8f2c1b6e","type":"DEPOSIT","status":"COMPLETED","address":"0x742d35Cc6634C0532925a3b844Bc454e4438f44e","networkType":"BEP20","asset":"USDT","contractAddress":"0x55d398326f99059fF775485246999027B3197955","amount":"100.5","udf":"order-123","txHash":"0xabc123","timestamp":"2026-07-24T00:00:00.000Z"}';

function sign(string $body, string $secret = SECRET): string
{
    return 'sha256=' . hash_hmac('sha256', $body, $secret);
}

$client = new Client('key', 'secret');
$handler = new WebhookHandler($client, SECRET);

check('accepts the merchant signature header, any casing', function () use ($handler) {
    $data = $handler->processWebhook(['X-SAGAPAY-MERCHANT-SIGNATURE' => sign(BODY)], BODY);
    assertTrue($data['id'] === '8f2c1b6e');
    assertTrue($data['asset'] === 'USDT');
});

check('accepts a single-element header list', function () use ($handler) {
    $data = $handler->processWebhook([WebhookHandler::SIGNATURE_HEADER => [sign(BODY)]], BODY);
    assertTrue($data['amount'] === '100.5');
});

check('rejects a repeated header', function () use ($handler) {
    assertThrows(fn () => $handler->processWebhook([WebhookHandler::SIGNATURE_HEADER => [sign(BODY), sign(BODY)]], BODY), Exception::INVALID_SIGNATURE);
});

check('ignores the retired X-Sagapay-Signature header', function () use ($handler) {
    assertThrows(fn () => $handler->processWebhook(['X-Sagapay-Signature' => sign(BODY)], BODY), Exception::INVALID_SIGNATURE);
});

check('rejects a tampered body', function () use ($handler) {
    $tampered = str_replace('100.5', '999999', BODY);
    assertThrows(fn () => $handler->processWebhook([WebhookHandler::SIGNATURE_HEADER => sign(BODY)], $tampered), Exception::INVALID_SIGNATURE);
});

check('rejects a signature made with another secret', function () use ($handler) {
    assertThrows(fn () => $handler->processWebhook([WebhookHandler::SIGNATURE_HEADER => sign(BODY, 'other')], BODY), Exception::INVALID_SIGNATURE);
});

check('rejects every webhook without a signing secret', function () use ($client) {
    $parseOnly = new WebhookHandler($client);
    assertThrows(fn () => $parseOnly->processWebhook([WebhookHandler::SIGNATURE_HEADER => sign(BODY, '')], BODY), Exception::INVALID_SIGNATURE);
    assertTrue(!Util::verifySignature(BODY, sign(BODY, ''), ''));
});

check('signature formats', function () {
    $hex = substr(sign(BODY), 7);
    assertTrue(Util::verifySignature(BODY, $hex, SECRET), 'bare hex');
    assertTrue(Util::verifySignature(BODY, 'SHA256=' . strtoupper($hex), SECRET), 'upper case');
    foreach (['', 'sha256=', substr($hex, 0, 62), $hex . '00', 'zz' . substr($hex, 2)] as $bad) {
        assertTrue(!Util::verifySignature(BODY, $bad, SECRET), "accepted {$bad}");
    }
});

check('matches the SagaPay signer', function () use ($client) {
    // Produced by the SagaPay signer (core-api utils/ipnSignature.js) for a
    // test merchant; keeps this SDK in step with what the platform sends.
    $secret = 'f59949bf79a73e05a41f10d2333a41b80644430f5220f468171392df355663a7';
    $body = '{"id":"dep-1","type":"DEPOSIT","status":"COMPLETED","address":"0xabc","networkType":"BEP20","asset":"USDT","contractAddress":"0x55d398326f99059fF775485246999027B3197955","amount":"100.5","udf":null,"txHash":"0xdef","timestamp":"2026-10-27T00:00:00.000Z"}';
    $header = 'sha256=a9841956a4b8ccf048f953081db6892e69da181b5792dfcf8604ea4795f6a469';
    $data = (new WebhookHandler($client, $secret))->processWebhook([WebhookHandler::SIGNATURE_HEADER => $header], $body);
    assertTrue($data['id'] === 'dep-1');
    assertTrue(!Util::verifySignature(str_replace('100.5', '100.6', $body), $header, $secret));
});

check('rejects a body that is not a JSON object', function () use ($handler) {
    assertThrows(fn () => $handler->processWebhook([WebhookHandler::SIGNATURE_HEADER => sign('123')], '123'), Exception::INVALID_RESPONSE);
});

check('isIpnFresh window', function () {
    $sent = strtotime('2026-07-24T00:00:00Z');
    $payload = json_decode(BODY, true);
    assertTrue(Util::isIpnFresh($payload, 3600, $sent + 60), 'recent');
    assertTrue(!Util::isIpnFresh($payload, 3600, $sent + 3601), 'old');
    assertTrue(Util::isIpnFresh($payload, WebhookHandler::DEFAULT_MAX_AGE_SECONDS, $sent + 8 * 3600), 'outlasts retries');
    assertTrue(!Util::isIpnFresh($payload, WebhookHandler::DEFAULT_MAX_AGE_SECONDS, $sent + 25 * 3600), 'a day old');
    assertTrue(Util::isIpnFresh($payload, 3600, $sent - 60), 'slight skew');
    assertTrue(!Util::isIpnFresh($payload, 3600, $sent - 600), 'far future');
    assertTrue(!Util::isIpnFresh(['timestamp' => 'garbage']), 'garbage');
    assertTrue(!Util::isIpnFresh([]), 'missing');
});

check('maxAgeSeconds rejects a stale webhook', function () use ($client) {
    $strict = new WebhookHandler($client, SECRET, WebhookHandler::DEFAULT_MAX_AGE_SECONDS);
    // BODY's timestamp is in 2026-07, long past
    assertThrows(fn () => $strict->processWebhook([WebhookHandler::SIGNATURE_HEADER => sign(BODY)], BODY), Exception::INVALID_SIGNATURE);
});

check('rejects an idempotency key that could inject headers', function () use ($client) {
    $params = ['networkType' => 'BEP20', 'contractAddress' => '0', 'address' => '0xabc', 'amount' => '1', 'ipnUrl' => 'https://example.com/ipn'];
    assertThrows(fn () => $client->createWithdrawal($params, "key\r\nX-Evil: 1"), Exception::INVALID_PARAM);
});

// Client requests against a local server
$log = tempnam(sys_get_temp_dir(), 'sagapay-test');
$port = 18000 + random_int(0, 999);
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/api_router.php'],
    [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
    $pipes,
    null,
    ['SAGAPAY_TEST_LOG' => $log]
);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
$requests = function () use ($log): array {
    $lines = array_filter(explode("\n", (string) file_get_contents($log)));
    file_put_contents($log, '');
    return array_map(fn ($line) => json_decode($line, true), array_values($lines));
};
$params = ['networkType' => 'BEP20', 'contractAddress' => '0', 'address' => '0xabc', 'amount' => '1', 'ipnUrl' => 'https://example.com/ipn'];

check('createWithdrawal sends the Idempotency-Key header', function () use ($port, $params, $requests) {
    $local = new Client('key', 'secret', "http://127.0.0.1:{$port}");
    $result = $local->createWithdrawal($params, 'payout-123');
    assertTrue($result['id'] === 'w-1');
    $seen = $requests();
    assertTrue(($seen[0]['headers']['idempotency-key'] ?? null) === 'payout-123', json_encode($seen));
    $local->createWithdrawal($params);
    assertTrue(!isset($requests()[0]['headers']['idempotency-key']), 'no key by default');
});

check('redirects are not followed', function () use ($port, $params, $requests) {
    $local = new Client('key', 'secret', "http://127.0.0.1:{$port}/redirect");
    assertThrows(fn () => $local->createWithdrawal($params), Exception::API_ERROR);
    $seen = $requests();
    assertTrue(count($seen) === 1 && $seen[0]['path'] === '/redirect/create-withdrawal', json_encode($seen));
});

proc_terminate($server);
proc_close($server);
unlink($log);

echo "\n{$count} tests, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
