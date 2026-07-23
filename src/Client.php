<?php
/**
 * SagaPay PHP SDK - Main Client
 * 
 * @package   SagaPay\SDK
 * @author    SagaPay Team
 * @copyright Copyright (c) 2025, SagaPay (https://sagapay.net)
 * @license   MIT
 * @version   1.0.0
 */

namespace SagaPay\SDK;

/**
 * SagaPay Client Class
 */
class Client
{
    /**
     * API credentials
     */
    private string $apiKey;
    private string $apiSecret;
    
    /**
     * API base URL
     */
    private string $baseUrl = 'https://api2.sagapay.net';
    
    /**
     * Constructor
     * 
     * @param string      $apiKey    Your SagaPay API key
     * @param string      $apiSecret Your SagaPay API secret
     * @param string|null $baseUrl   Optional custom API base URL
     */
    public function __construct(string $apiKey, string $apiSecret, ?string $baseUrl = null)
    {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        
        if ($baseUrl !== null) {
            $this->baseUrl = rtrim($baseUrl, '/');
        }
    }
    
    /**
     * Create a deposit address
     * 
     * @param array $params Parameters for creating a deposit
     * @return array Response data
     * @throws Exception If the request fails
     */
    public function createDeposit(array $params): array
    {
        $requiredParams = ['networkType', 'contractAddress', 'amount', 'ipnUrl'];
        Util::validateRequiredParams($params, $requiredParams);
        
        return $this->request('POST', '/create-deposit', $params);
    }
    
    /**
     * Create a withdrawal
     * 
     * @param array $params Parameters for creating a withdrawal
     * @return array Response data
     * @throws Exception If the request fails
     */
    public function createWithdrawal(array $params): array
    {
        $requiredParams = ['networkType', 'contractAddress', 'address', 'amount', 'ipnUrl'];
        Util::validateRequiredParams($params, $requiredParams);
        
        return $this->request('POST', '/create-withdrawal', $params);
    }
    
    /**
     * Check transaction status by address or ID
     *
     * @param string      $type    Transaction type ("deposit" or "withdrawal")
     * @param string|null $address Blockchain address to check (optional if id is provided)
     * @param string|null $id      Transaction ID to check (optional if address is provided)
     * @return array Response data
     * @throws Exception If the request fails
     */
    public function checkTransactionStatus(string $type, ?string $address = null, ?string $id = null): array
    {
        if (!in_array($type, ['deposit', 'withdrawal'])) {
            throw new Exception("Type must be 'deposit' or 'withdrawal'", Exception::INVALID_PARAM);
        }

        if (empty($address) && empty($id)) {
            throw new Exception("Either address or id is required", Exception::INVALID_PARAM);
        }

        $params = ['type' => $type];
        if (!empty($address)) {
            $params['address'] = $address;
        }
        if (!empty($id)) {
            $params['id'] = $id;
        }

        return $this->request('GET', '/check-transaction-status', $params);
    }
    
    /**
     * Fetch wallet balance
     * 
     * @param string $address         Blockchain address to check
     * @param string $networkType     Network type
     * @param string $contractAddress Contract address (optional, use "0" for native currency)
     * @return array Response data
     * @throws Exception If the request fails
     */
    public function fetchWalletBalance(string $address, string $networkType, string $contractAddress = '0'): array
    {
        return $this->request('GET', '/fetch-wallet-balance', [
            'address' => $address,
            'networkType' => $networkType,
            'contractAddress' => $contractAddress
        ]);
    }
    
    /**
     * Verify an IPN notification against the SagaPay API
     *
     * Confirms that a webhook/IPN you received corresponds to a real transaction
     * on SagaPay. This endpoint authenticates via the request body (apiKey/apiSecret),
     * so no x-api-key/x-api-secret headers are sent.
     *
     * @param string $txnHash Transaction hash from the IPN payload
     * @param string $type    Transaction type ("DEPOSIT" or "WITHDRAWAL")
     * @param string $amount  Amount from the IPN payload
     * @param string $address Blockchain address from the IPN payload
     * @return bool Whether the server verified the notification
     * @throws Exception If the request fails
     */
    public function verifyIpn(string $txnHash, string $type, string $amount, string $address): bool
    {
        $response = $this->request('POST', '/verify-ipn', [
            'txnHash' => $txnHash,
            'type' => strtoupper($type),
            'amount' => $amount,
            'address' => $address,
            'apiKey' => $this->apiKey,
            'apiSecret' => $this->apiSecret
        ], false);

        return (bool)($response['verified'] ?? false);
    }

    /**
     * Get API key
     *
     * @return string API key
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }
    
    /**
     * Get API secret
     * 
     * @return string API secret
     */
    public function getApiSecret(): string
    {
        return $this->apiSecret;
    }

    /**
     * Make an API request
     *
     * @param string $method   HTTP method
     * @param string $path     API endpoint path
     * @param array  $params   Request parameters
     * @param bool   $withAuth Whether to send the x-api-key/x-api-secret headers
     * @return array Response data
     * @throws Exception If the request fails
     */
    private function request(string $method, string $path, array $params = [], bool $withAuth = true): array
    {
        $url = $this->baseUrl . $path;
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        if ($withAuth) {
            $headers[] = 'x-api-key: ' . $this->apiKey;
            $headers[] = 'x-api-secret: ' . $this->apiSecret;
        }

        $ch = curl_init();

        if ($method === 'GET') {
            $url .= '?' . http_build_query($params);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw new Exception('cURL error: ' . $curlError, Exception::NETWORK_ERROR);
        }

        // Store raw response for debugging purposes
        $rawResponse = $response;

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response: ' . $rawResponse, Exception::INVALID_RESPONSE);
        }

        if ($httpCode >= 400) {
            // The server returns its human-readable message in the "error" key:
            // { "error": "..." }
            $errorString = null;

            if (isset($data['error']) && is_string($data['error'])) {
                $errorString = $data['error'];
            } elseif (isset($data['message']) && is_string($data['message'])) {
                $errorString = $data['message'];
            }

            $message = $errorString ?? ('API error (HTTP ' . $httpCode . ')');

            throw new Exception($message, Exception::API_ERROR, $httpCode, $errorString);
        }

        return $data;
    }
}