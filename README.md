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
]);
```

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

SagaPay sends webhook notifications (IPNs) to your specified `ipnUrl` when a transaction completes. Use the WebhookHandler to process these notifications:

```php
<?php
// webhook.php
require_once 'vendor/autoload.php';

use SagaPay\SDK\Client;
use SagaPay\SDK\WebhookHandler;

$client = new Client('your-api-key', 'your-api-secret');

// Optional platform-issued IPN secret (NOT your API secret). When null,
// signature verification is skipped and verifyWithServer() below is the
// primary check.
$ipnSecret = null; // e.g. 'your-platform-ipn-secret'

$webhookHandler = new WebhookHandler($client, $ipnSecret);

try {
    // Process and validate the webhook
    $webhookData = $webhookHandler->processWebhook(getallheaders(), file_get_contents('php://input'));

    // Confirm the notification against the SagaPay API (recommended)
    if (!$webhookHandler->verifyWithServer($webhookData)) {
        throw new \SagaPay\SDK\Exception('IPN could not be verified with the server');
    }

    // Handle the validated webhook data
    $transactionId = $webhookData['id'];
    $type = $webhookData['type']; // 'DEPOSIT' or 'WITHDRAWAL'
    $status = $webhookData['status']; // 'COMPLETED' (the only status sent today)
    $address = $webhookData['address'];
    $amount = $webhookData['amount'];
    $udf = $webhookData['udf'] ?? null; // Your custom reference field

    // IPNs are delivered at least once — the same notification may arrive
    // more than once, so make your processing idempotent (e.g. skip
    // transaction IDs you have already handled).
    if ($status === 'COMPLETED' && $type === 'DEPOSIT') {
        // Process successful payment
        // e.g., updateOrderStatus($udf, 'paid');
    }

    // Send success response
    $webhookHandler->sendSuccessResponse();

} catch (\SagaPay\SDK\Exception $e) {
    // Log the error and send error response
    error_log("Webhook error: " . $e->getMessage());
    $webhookHandler->sendErrorResponse($e->getMessage(), $e->getCode());
}
```

## Webhook Payload Format

SagaPay currently sends an IPN when a transaction completes, so `status` is always `COMPLETED`. Note that `type` uses UPPERCASE values on the wire:

```json
{
  "id": "transaction-uuid",
  "type": "DEPOSIT|WITHDRAWAL",
  "status": "COMPLETED",
  "address": "0x123abc...",
  "networkType": "ERC20|BEP20|TRC20|POLYGON|SOLANA",
  "amount": "10.5",
  "udf": "your-optional-user-defined-field",
  "txHash": "0xabc123...",
  "timestamp": "2025-03-16T14:30:00Z"
}
```

`udf` and `txHash` may be `null`. Delivery is at least once: the same notification can arrive more than once, so make your webhook processing idempotent.

### Webhook Signature

Each IPN request carries an `X-Sagapay-Signature` header in the form `sha256=<hex>`, where `<hex>` is the HMAC-SHA256 of the exact raw request body keyed with a platform-issued IPN secret. This secret is NOT your API secret. If you have one, pass it as the second constructor argument of `WebhookHandler` to enable signature verification:

```php
$webhookHandler = new WebhookHandler($client, 'your-platform-ipn-secret');
```

When you don't have an IPN secret, leave the argument out (or pass `null`) — the signature check is skipped and server-side verification below is the primary check.

### Verifying an IPN with the Server

The primary way to confirm an IPN is to ask the SagaPay API directly via `Client::verifyIpn()` (or the `WebhookHandler::verifyWithServer()` convenience wrapper shown above). This endpoint authenticates with your API key/secret in the request body, so it needs no extra configuration:

```php
$verified = $client->verifyIpn(
    $webhookData['txHash'] ?? '', // Transaction hash from the IPN
    $webhookData['type'],         // 'DEPOSIT' or 'WITHDRAWAL'
    $webhookData['amount'],       // Amount from the IPN
    $webhookData['address']       // Address from the IPN
);

if (!$verified) {
    // Reject the notification
}
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