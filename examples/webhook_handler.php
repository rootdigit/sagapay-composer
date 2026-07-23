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

// Optional platform-issued IPN secret (NOT your API secret). When null,
// signature verification is skipped and the verifyWithServer() call below
// is the primary check.
$ipnSecret = null; // e.g. 'your-platform-ipn-secret'

// Create webhook handler
$webhookHandler = new WebhookHandler($client, $ipnSecret);

try {
    // Get the request headers
    $headers = getallheaders();

    // Get the raw request body
    $body = file_get_contents('php://input');

    // Process and validate the webhook
    $webhookData = $webhookHandler->processWebhook($headers, $body);

    // Confirm the notification against the SagaPay API (recommended,
    // especially when no IPN secret is configured)
    if (!$webhookHandler->verifyWithServer($webhookData)) {
        throw new Exception('IPN could not be verified with the server', Exception::INVALID_SIGNATURE);
    }

    // Log the webhook for debugging (optional)
    error_log('SagaPay Webhook received: ' . json_encode($webhookData));

    // Extract important fields
    $transactionId = $webhookData['id'];
    $type = $webhookData['type']; // 'DEPOSIT' or 'WITHDRAWAL'
    $status = $webhookData['status']; // 'COMPLETED' (the only status sent today)
    $address = $webhookData['address'];
    $amount = $webhookData['amount'];
    $udf = $webhookData['udf'] ?? null; // Your custom reference field
    $txHash = $webhookData['txHash'] ?? null;

    // IPNs are delivered at least once, so the same notification may arrive
    // more than once. Make your processing idempotent, e.g. skip transaction
    // IDs you have already handled.
    if ($status === 'COMPLETED') {
        if ($type === 'DEPOSIT') {
            // Handle successful deposit
            // For example, update order status in your system
            // updateOrderStatus($udf, 'paid');
            error_log("Deposit {$transactionId} completed: {$amount} received at {$address}");
        } elseif ($type === 'WITHDRAWAL') {
            // Handle successful withdrawal
            // updateWithdrawalStatus($udf, 'completed');
            error_log("Withdrawal {$transactionId} completed: {$amount} sent to {$address}");
        }
    }

    // Send success response
    $webhookHandler->sendSuccessResponse();

} catch (Exception $e) {
    // Log the error
    error_log("SagaPay Webhook Error: {$e->getMessage()} (Code: {$e->getCode()})");

    // Send error response (still returns HTTP 200 to prevent retries)
    $webhookHandler->sendErrorResponse($e->getMessage(), $e->getCode());
}
