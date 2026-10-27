# SagaPay PHP SDK

SagaPay (https://sagapay.io) is the world's first free, non-custodial blockchain payment gateway service provider, enabling businesses to seamlessly integrate cryptocurrency payments without holding customer funds. With enterprise-grade security and zero transaction fees, SagaPay empowers merchants to accept crypto payments across multiple blockchains while maintaining full control of their digital assets.

## Installation

Install the SagaPay PHP SDK via Composer:

```bash
composer require rootdigit/sagapay
```

## Quick Start

```php
<?php
// Initialize the SagaPay client
require_once 'vendor/autoload.php';

use SagaPay\SDK\Client;

$client = new Client('your-api-key', 'your-api-secret');

// Create a deposit address
try {
    $deposit = $client->createDeposit([
        'networkType' => 'BEP20',
        'contractAddress' => '0', // Use '0' for native coins
        'amount' => '1.5',
        'ipnUrl' => 'https://yourwebsite.com/webhook.php',
        'udf' => 'order-123',
        'type' => 'TEMPORARY'
    ]);
    
    echo "Deposit address created: " . $deposit['address'];
} catch (\SagaPay\SDK\Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

## Features

- Deposit address generation
- Withdrawal processing
- Transaction status checking
- Wallet balance fetching
- Multi-chain support (ERC20, BEP20, TRC20, POLYGON, SOLANA)
- Webhook notifications (IPN)
- Custom UDF field support
- Zero transaction fees
- Non-custodial architecture

## API Reference

### Create Deposit

```php
$deposit = $client->createDeposit([
    'networkType' => 'BEP20',          // Required: ERC20, BEP20, TRC20, POLYGON, SOLANA
    'contractAddress' => '0',          // Required: Contract address or '0' for native coins
    'amount' => '1.5',                 // Required: Expected deposit amount
    'ipnUrl' => 'https://example.com/webhook.php',  // Required: URL for notifications
    'udf' => 'order-123',              // Optional: User-defined field
    'type' => 'TEMPORARY'              // Optional: TEMPORARY or PERMANENT
]);
```

### Create Withdrawal

```php
$withdrawal = $client->createWithdrawal([
    'networkType' => 'ERC20',          // Required: ERC20, BEP20, TRC20, POLYGON, SOLANA
    'contractAddress' => '0xdAC17F...', // Required: Contract address or '0' for native coins
    'address' => '0x742d35C...',       // Required: Destination wallet address
    'amount' => '10.5',                // Required: Withdrawal amount
    'ipnUrl' => 'https://example.com/webhook.php',  // Required: URL for notifications
    'udf' => 'withdrawal-456'          // Optional: User-defined field
], 'payout-456');                      // Optional, recommended: idempotency key
```

Withdrawals need an API key created with **Withdrawals** turned on, which requires two-factor authentication on your account.

The second argument (1-128 letters, digits, `-` or `_`) is sent as an `Idempotency-Key` header, so that a retry after a timeout or network error cannot send the money twice. For 24 hours, a repeat with the same key returns the first withdrawal instead of creating another; while the first request is still running, a repeat fails with HTTP 409. Use one key per payout, such as your own payout id.

### Check Transaction Status

```php
// By address
$status = $client->checkTransactionStatus('deposit', '0x742d35C...');

// By transaction ID
$status = $client->checkTransactionStatus('deposit', null, 'deposit-uuid');
```

### Fetch Wallet Balance

```php
$balance = $client->fetchWalletBalance(
    '0x742d35C...',  // Address
    'ERC20',         // Network type
    '0xdAC17F...'    // Contract address (use '0' for native currency)
);
```

## Handling Webhooks (IPN)

SagaPay sends webhook notifications (IPNs) to your specified `ipnUrl` when a transaction completes. Each one carries a signature over the exact raw request body:

```
X-Sagapay-Merchant-Signature: sha256=<hex HMAC-SHA256 of the raw body>
```

It is keyed with your **signing secret**. Copy it from the dashboard: **Webhooks → Signing secret**. It is not your API secret, and nobody else's secret can produce a valid signature for your notifications. `WebhookHandler` checks the signature on every notification, and a handler without a secret rejects them all.

```php
<?php
// webhook.php
require_once 'vendor/autoload.php';

use SagaPay\SDK\Client;
use SagaPay\SDK\Exception;
use SagaPay\SDK\WebhookHandler;

$client = new Client('your-api-key', 'your-api-secret');

$webhookHandler = new WebhookHandler(
    $client,
    getenv('SAGAPAY_SIGNING_SECRET') ?: null,     // Webhooks → Signing secret
    WebhookHandler::DEFAULT_MAX_AGE_SECONDS       // Optional: refuse notifications older than a day
);

try {
    // Verifies X-Sagapay-Merchant-Signature over the exact raw body, then parses it.
    $webhookData = $webhookHandler->processWebhook(getallheaders(), file_get_contents('php://input'));
} catch (Exception $e) {
    error_log('Rejected webhook: ' . $e->getMessage());
    http_response_code(401);
    exit;
}

// Process each id once. Delivery is at-least-once, and a replayed request
// carries a valid signature too.
if (!markProcessed($webhookData['id'])) { // e.g. INSERT ... ON CONFLICT DO NOTHING
    $webhookHandler->sendSuccessResponse();
}

if ($webhookData['type'] === 'DEPOSIT') {
    // Check what arrived before you credit it.
    creditOrder($webhookData['udf'], $webhookData['asset'], $webhookData['contractAddress'], $webhookData['amount']);
} else {
    markPayoutSent($webhookData['udf'], $webhookData['txHash']);
}

// Answer 2xx within 20 seconds. If your own processing fails, answer 5xx
// instead so SagaPay retries.
$webhookHandler->sendSuccessResponse();
```

The old `X-Sagapay-Signature` header was keyed with a secret shared by every merchant. It is retired: SagaPay no longer sends it, and this SDK no longer reads it.

### Replay protection

A captured webhook still has a valid signature, so the signature alone does not stop someone resending it. Protect against that:

- **Process each `id` once.** Record the ids you have handled (a unique column works) and answer duplicates with a 200 without acting on them again. This is the main defence and also covers normal retries.
- **Optionally check the age** with the third `WebhookHandler` argument, or `Util::isIpnFresh($webhookData, $maxAgeSeconds)`. Keep the limit above the retry schedule (about 7.5 hours): retries reuse the original `timestamp`. A notification you resend from the dashboard also keeps it, so a resend older than your limit will be refused.
- **Check what you are crediting.** Match `asset`, `contractAddress`, `amount` and `address` against the order before you fulfil it.

## Webhook Payload Format

When SagaPay sends a webhook to your endpoint, it will include the following payload:

```json
{
  "id": "transaction-uuid",
  "type": "DEPOSIT|WITHDRAWAL",
  "status": "COMPLETED",
  "address": "0x123abc...",
  "networkType": "ERC20|BEP20|TRC20|POLYGON|SOLANA",
  "asset": "USDT",
  "contractAddress": "0x55d398326f99059fF775485246999027B3197955",
  "amount": "10.5",
  "udf": "your-optional-user-defined-field",
  "txHash": "0xabc123...",
  "timestamp": "2025-03-16T14:30:00Z"
}
```

- `asset` and `contractAddress` say what was received; `contractAddress` is `null` for the chain's native coin. `networkType` alone does not tell 100 TRX from 100 USDT.
- `udf` and `txHash` may be `null`.
- `timestamp` is set when the transaction completes and does not change on retries.
- SagaPay makes up to 6 delivery attempts, retrying after 1 min, 5 min, 15 min, 1 h and 6 h.

### Confirming with the API

`Client::verifyIpn()` (or the `WebhookHandler::verifyWithServer()` wrapper) asks the SagaPay API to confirm a notification's transaction. It authenticates with your API key/secret in the request body. Check the signature first; this network call is an extra check on top of it:

```php
$verified = $client->verifyIpn(
    $webhookData['txHash'] ?? '', // Transaction hash from the IPN
    $webhookData['type'],         // 'DEPOSIT' or 'WITHDRAWAL'
    $webhookData['amount'],       // Amount from the IPN
    $webhookData['address']       // Address from the IPN
);
```

## Testing

```bash
php tests/run.php
```

## Error Handling

All methods in the SagaPay SDK can throw a `SagaPay\SDK\Exception`. This exception contains:

- Message: Error description
- Code: Error code
- HTTP code: HTTP status code (when applicable)

```php
try {
    $client->createDeposit($params);
} catch (\SagaPay\SDK\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    echo "HTTP Status: " . $e->getHttpCode() . "\n";
}
```

## License

This SDK is released under the MIT License.

## Support

For questions or support, please contact support@sagapay.io or visit [https://sagapay.io](https://sagapay.io).