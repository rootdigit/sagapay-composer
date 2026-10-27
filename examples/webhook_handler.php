<?php
/**
 * SagaPay PHP SDK - Webhook Handler Example
 *
 * This file should be accessible via the URL you provided in the ipnUrl parameter
 * when creating deposits or withdrawals.
 */

// Include Composer autoload
require_once __DIR__ . '/../vendor/autoload.php';

use SagaPay\SDK\Client;
use SagaPay\SDK\WebhookHandler;
use SagaPay\SDK\Exception;

// Initialize the SagaPay client with your API credentials
$client = new Client('your-api-key', 'your-api-secret');

// Your signing secret: dashboard → Webhooks → Signing secret. It is NOT your
// API secret. Notifications whose X-Sagapay-Merchant-Signature does not match
// it are rejected. Keep it out of source control.
$signingSecret = getenv('SAGAPAY_SIGNING_SECRET') ?: null;

// The third argument is optional: it also refuses notifications older than a day.
$webhookHandler = new WebhookHandler($client, $signingSecret, WebhookHandler::DEFAULT_MAX_AGE_SECONDS);

try {
    // Verify the signature over the exact raw request body, then parse it
    $webhookData = $webhookHandler->processWebhook(getallheaders(), file_get_contents('php://input'));
} catch (Exception $e) {
    error_log("SagaPay webhook rejected: {$e->getMessage()} (Code: {$e->getCode()})");
    http_response_code(401);
    exit;
}

// Log the webhook for debugging (optional)
error_log('SagaPay Webhook received: ' . json_encode($webhookData));

// Extract important fields
$transactionId = $webhookData['id'];
$type = $webhookData['type']; // 'DEPOSIT' or 'WITHDRAWAL'
$status = $webhookData['status']; // 'COMPLETED' (the only status sent today)
$address = $webhookData['address'];
$asset = $webhookData['asset'] ?? null; // e.g. 'USDT'
$contractAddress = $webhookData['contractAddress'] ?? null; // null for the native coin
$amount = $webhookData['amount'];
$udf = $webhookData['udf'] ?? null; // Your custom reference field

// IPNs are delivered at least once, and a replayed request carries a valid
// signature too: act on each transaction id only once, e.g. with a unique
// column in your database.
// if (alreadyProcessed($transactionId)) { $webhookHandler->sendSuccessResponse(); }

if ($status === 'COMPLETED') {
    if ($type === 'DEPOSIT') {
        // Check $asset, $contractAddress and $amount against the order
        // before you mark it paid.
        // updateOrderStatus($udf, 'paid');
        error_log("Deposit {$transactionId} completed: {$amount} {$asset} received at {$address}");
    } elseif ($type === 'WITHDRAWAL') {
        // updateWithdrawalStatus($udf, 'completed');
        error_log("Withdrawal {$transactionId} completed: {$amount} sent to {$address}");
    }
}

// Send success response. If your own processing fails, answer 5xx instead so
// SagaPay retries the notification.
$webhookHandler->sendSuccessResponse();
