<?php
/**
 * SagaPay PHP SDK - Utilities
 * 
 * @package   SagaPay\SDK
 * @author    SagaPay Team
 * @copyright Copyright (c) 2025, SagaPay (https://sagapay.io)
 * @license   MIT
 * @version   1.0.0
 */

namespace SagaPay\SDK;

/**
 * SagaPay Utilities Class
 */
class Util
{
    /**
     * Validate required parameters
     * 
     * @param array $params         Parameters to validate
     * @param array $requiredParams List of required parameter keys
     * @throws Exception If any required parameter is missing
     */
    public static function validateRequiredParams(array $params, array $requiredParams): void
    {
        foreach ($requiredParams as $param) {
            if (!isset($params[$param]) || $params[$param] === '') {
                throw new Exception("Missing required parameter: {$param}", Exception::INVALID_PARAM);
            }
        }
    }
    
    /**
     * Generate HMAC signature
     * 
     * @param string $payload Data to sign
     * @param string $secret  Secret key
     * @return string Signature
     */
    public static function generateSignature(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }
    
    /**
     * Verify HMAC signature
     *
     * Accepts both a bare hex digest and the "sha256=<hex>" format used by
     * the X-Sagapay-Merchant-Signature webhook header. Compares in constant
     * time.
     *
     * @param string $payload   Data that was signed
     * @param string $signature Signature to verify
     * @param string $secret    Secret key
     * @return bool Whether the signature is valid (false for an empty secret)
     */
    public static function verifySignature(string $payload, string $signature, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        // Strip the optional "sha256=" prefix from the provided signature
        $signature = trim($signature);
        if (strncasecmp($signature, 'sha256=', 7) === 0) {
            $signature = substr($signature, 7);
        }
        if (preg_match('/\A[0-9a-f]{64}\z/i', $signature) !== 1) {
            return false;
        }

        $expectedSignature = self::generateSignature($payload, $secret);
        return hash_equals($expectedSignature, strtolower($signature));
    }

    /**
     * Whether a notification's timestamp is recent enough to act on
     *
     * A guard against an old, validly signed notification being replayed. The
     * timestamp is set when the transaction completes and stays the same on
     * every retry, so keep $maxAgeSeconds longer than the retry schedule
     * (about 7.5 hours). A notification resent from the dashboard also keeps
     * its original timestamp, and is rejected once it is older than the limit.
     *
     * This is a secondary check. The main defence against replays is to
     * process each notification id only once.
     *
     * @param array    $payload       Parsed webhook payload (only 'timestamp' is read)
     * @param int      $maxAgeSeconds Oldest age to accept; defaults to 24 hours
     * @param int|null $now           Current Unix time, for tests
     * @return bool False for a missing or unparseable timestamp, one older than
     *              $maxAgeSeconds, or one more than 5 minutes in the future
     */
    public static function isIpnFresh(array $payload, int $maxAgeSeconds = 86400, ?int $now = null): bool
    {
        $timestamp = $payload['timestamp'] ?? null;
        if (!is_string($timestamp) || $timestamp === '') {
            return false;
        }
        try {
            $sent = (new \DateTimeImmutable($timestamp))->getTimestamp();
        } catch (\Exception $e) {
            return false;
        }
        $now ??= time();
        return $sent <= $now + 300 && $now - $sent <= $maxAgeSeconds;
    }

    /**
     * Get current timestamp in ISO 8601 format
     * 
     * @return string ISO 8601 timestamp
     */
    public static function getIsoTimestamp(): string
    {
        return (new \DateTime())->format('c');
    }
}