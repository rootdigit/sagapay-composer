<?php
/**
 * SagaPay PHP SDK - Webhook Handler
 * 
 * @package   SagaPay\SDK
 * @author    SagaPay Team
 * @copyright Copyright (c) 2025, SagaPay (https://sagapay.net)
 * @license   MIT
 * @version   1.0.0
 */

namespace SagaPay\SDK;

/**
 * SagaPay Webhook Handler Class
 */
class WebhookHandler
{
    /**
     * @var Client
     */
    private Client $client;

    /**
     * Platform-issued IPN signing secret (optional)
     */
    private ?string $ipnSecret;

    /**
     * Constructor
     *
     * @param Client      $client    SagaPay client
     * @param string|null $ipnSecret Optional platform-issued IPN secret used to verify
     *                               the X-Sagapay-Signature header. This is NOT your
     *                               API secret. When null, signature verification is
     *                               skipped and verifyWithServer() (Client::verifyIpn())
     *                               is the primary check.
     */
    public function __construct(Client $client, ?string $ipnSecret = null)
    {
        $this->client = $client;
        $this->ipnSecret = $ipnSecret;
    }

    /**
     * Process webhook data
     *
     * @param array  $headers Request headers
     * @param string $body    Request body (raw JSON)
     * @return array Validated webhook data
     * @throws Exception If validation fails
     */
    public function processWebhook(array $headers, string $body): array
    {
        // Verify the signature only when a platform IPN secret is configured
        if ($this->ipnSecret !== null) {
            // Normalize header names (getallheaders() may return mixed casing)
            $headers = array_change_key_case($headers, CASE_LOWER);

            // Get the signature from headers
            $signature = $headers['x-sagapay-signature'] ?? null;

            if (!$signature) {
                throw new Exception('Missing SagaPay signature in headers', Exception::INVALID_SIGNATURE);
            }

            // Validate the signature
            if (!Util::verifySignature($body, $signature, $this->ipnSecret)) {
                throw new Exception('Invalid webhook signature', Exception::INVALID_SIGNATURE);
            }
        }

        // Parse the body
        $payload = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON in webhook payload', Exception::INVALID_RESPONSE);
        }

        // Validate required fields
        $requiredFields = ['id', 'type', 'status', 'address', 'networkType', 'amount', 'timestamp'];
        Util::validateRequiredParams($payload, $requiredFields);

        return $payload;
    }

    /**
     * Verify an IPN payload against the SagaPay API
     *
     * Confirms the notification with the server via Client::verifyIpn(). Use this
     * as the primary verification step, especially when no IPN secret is configured
     * for signature verification.
     *
     * @param array $payload Webhook payload (as returned by processWebhook())
     * @return bool Whether the server confirmed the notification
     * @throws Exception If the request fails
     */
    public function verifyWithServer(array $payload): bool
    {
        return $this->client->verifyIpn(
            $payload['txHash'] ?? '',
            $payload['type'] ?? '',
            $payload['amount'] ?? '',
            $payload['address'] ?? ''
        );
    }
    
    /**
     * Send success response for the webhook
     * 
     * @param array $data Optional additional data to include in response
     */
    public function sendSuccessResponse(array $data = []): void
    {
        http_response_code(200);
        $response = array_merge(['received' => true], $data);
        echo json_encode($response);
        exit;
    }
    
    /**
     * Send error response for the webhook
     * 
     * @param string $message Error message
     * @param int    $code    Error code
     */
    public function sendErrorResponse(string $message, int $code = 0): void
    {
        http_response_code(200); // Still return 200 to prevent retries
        echo json_encode([
            'received' => false,
            'error' => $message,
            'code' => $code
        ]);
        exit;
    }
}