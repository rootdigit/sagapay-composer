<?php
/**
 * SagaPay PHP SDK - Webhook Handler
 * 
 * @package   SagaPay\SDK
 * @author    SagaPay Team
 * @copyright Copyright (c) 2025, SagaPay (https://sagapay.io)
 * @license   MIT
 * @version   1.0.0
 */

namespace SagaPay\SDK;

/**
 * SagaPay Webhook Handler Class
 *
 * Verifies the X-Sagapay-Merchant-Signature header of every notification with
 * your signing secret, then parses it. Notifications are delivered at least
 * once, and a replayed request carries a valid signature too, so act on each
 * notification id only once.
 */
class WebhookHandler
{
    /**
     * The header that carries an IPN's signature
     */
    public const SIGNATURE_HEADER = 'X-Sagapay-Merchant-Signature';

    /**
     * A sensible maximum age: 24 hours, well above the IPN retry schedule
     * (about 7.5 hours)
     */
    public const DEFAULT_MAX_AGE_SECONDS = 86400;

    /**
     * @var Client
     */
    private Client $client;

    /**
     * Merchant signing secret, from the dashboard's Webhooks page
     */
    private ?string $signingSecret;

    /**
     * Oldest notification to accept, in seconds; null for no limit
     */
    private ?int $maxAgeSeconds;

    /**
     * Constructor
     *
     * @param Client      $client        SagaPay client
     * @param string|null $signingSecret Your signing secret: copy it from the dashboard
     *                                   (Webhooks → Signing secret). This is NOT your API
     *                                   secret. Without it, every notification is rejected.
     * @param int|null    $maxAgeSeconds When set, also reject notifications older than this
     *                                   (see Util::isIpnFresh()). Keep it above the ~7.5 hour
     *                                   retry schedule; DEFAULT_MAX_AGE_SECONDS is sensible.
     */
    public function __construct(Client $client, ?string $signingSecret = null, ?int $maxAgeSeconds = null)
    {
        $this->client = $client;
        $this->signingSecret = $signingSecret;
        $this->maxAgeSeconds = $maxAgeSeconds;
    }

    /**
     * Verify and parse a webhook
     *
     * @param array  $headers Request headers, e.g. getallheaders(); names are matched
     *                        case-insensitively
     * @param string $body    The exact raw request body, e.g. file_get_contents('php://input')
     * @return array Validated webhook data
     * @throws Exception If the signature is missing or wrong, the payload is invalid, or
     *                   (with $maxAgeSeconds) it is stale
     */
    public function processWebhook(array $headers, string $body): array
    {
        if ($this->signingSecret === null || $this->signingSecret === '') {
            throw new Exception(
                'No signing secret configured: copy it from the dashboard\'s Webhooks page',
                Exception::INVALID_SIGNATURE
            );
        }

        // Normalize header names (getallheaders() may return mixed casing)
        $headers = array_change_key_case($headers, CASE_LOWER);
        $signature = $headers[strtolower(self::SIGNATURE_HEADER)] ?? null;

        // Some frameworks give each header as a list. A repeated header is
        // never valid; do not guess which one to trust.
        if (is_array($signature)) {
            $signature = count($signature) === 1 ? reset($signature) : null;
        }

        if (!is_string($signature) || $signature === '') {
            throw new Exception('Missing SagaPay signature in headers', Exception::INVALID_SIGNATURE);
        }

        if (!Util::verifySignature($body, $signature, $this->signingSecret)) {
            throw new Exception('Invalid webhook signature', Exception::INVALID_SIGNATURE);
        }

        // Parse the body
        $payload = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            throw new Exception('Invalid JSON in webhook payload', Exception::INVALID_RESPONSE);
        }

        // Validate required fields
        $requiredFields = ['id', 'type', 'status', 'address', 'networkType', 'amount', 'timestamp'];
        Util::validateRequiredParams($payload, $requiredFields);

        if ($this->maxAgeSeconds !== null && !Util::isIpnFresh($payload, $this->maxAgeSeconds)) {
            throw new Exception('Webhook timestamp is outside the allowed age', Exception::INVALID_SIGNATURE);
        }

        return $payload;
    }

    /**
     * Confirm an IPN payload's transaction with the SagaPay API
     *
     * Calls Client::verifyIpn(). processWebhook() has already checked the
     * signature; this network call is an extra check on top of it.
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
     * Answers 200 so SagaPay does not retry; use it for notifications you
     * reject. If your own processing failed and you want the notification
     * again, answer with a 5xx instead.
     *
     * @param string $message Error message
     * @param int    $code    Error code
     */
    public function sendErrorResponse(string $message, int $code = 0): void
    {
        http_response_code(200);
        echo json_encode([
            'received' => false,
            'error' => $message,
            'code' => $code
        ]);
        exit;
    }
}