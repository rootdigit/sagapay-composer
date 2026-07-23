<?php
/**
 * SagaPay PHP SDK - Basic Usage Example
 */

// Include Composer autoload
require_once __DIR__ . '/../vendor/autoload.php';

use SagaPay\SDK\Client;
use SagaPay\SDK\Exception;

// Initialize the SagaPay client with your API credentials
$client = new Client('your-api-key', 'your-api-secret');

try {
    // Example 1: Create a deposit address
    echo "Creating deposit address...\n";
    $deposit = $client->createDeposit([
        'networkType' => 'BEP20',
        'contractAddress' => '0', // Use '0' for native tokens (BNB in this case)
        'amount' => '0.5',
        'ipnUrl' => 'https://yourwebsite.com/webhook.php',
        'udf' => 'order-123', // Optional reference
        'type' => 'TEMPORARY' // TEMPORARY (24h expiry) or PERMANENT
    ]);
    
    echo "✓ Deposit address created:\n";
    echo "  ID: {$deposit['id']}\n";
    echo "  Address: {$deposit['address']}\n";
    echo "  Expires: {$deposit['expiresAt']}\n";
    echo "  Status: {$deposit['status']}\n\n";
    
    // Example 2: Create a withdrawal
    echo "Creating withdrawal...\n";
    $withdrawal = $client->createWithdrawal([
        'networkType' => 'ERC20',
        'contractAddress' => '0xdAC17F958D2ee523a2206206994597C13D831ec7', // USDT on Ethereum
        'address' => '0x742d35Cc6634C0532925a3b844Bc454e4438f44e',
        'amount' => '10.5',
        'ipnUrl' => 'https://yourwebsite.com/webhook.php',
        'udf' => 'withdrawal-456' // Optional reference
    ]);
    
    echo "✓ Withdrawal created:\n";
    echo "  ID: {$withdrawal['id']}\n";
    echo "  Status: {$withdrawal['status']}\n";
    echo "  Fee: {$withdrawal['fee']}\n\n";
    
    // Example 3: Check transaction status
    echo "Checking transaction status...\n";
    $address = '0x742d35Cc6634C0532925a3b844Bc454e4438f44e';
    $txStatus = $client->checkTransactionStatus('deposit', $address);
    
    echo "✓ Transaction status retrieved:\n";
    echo "  Address: {$txStatus['address']}\n";
    echo "  Type: {$txStatus['transactionType']}\n";
    echo "  Count: {$txStatus['count']}\n";
    
    if ($txStatus['count'] > 0) {
        echo "  Transactions:\n";
        foreach ($txStatus['transactions'] as $i => $tx) {
            echo "    #{$i} ID: {$tx['id']}, Status: {$tx['status']}, Amount: {$tx['amount']}\n";
        }
    }
    echo "\n";
    
    // Example 4: Fetch wallet balance
    echo "Fetching wallet balance...\n";
    $balance = $client->fetchWalletBalance(
        '0x742d35Cc6634C0532925a3b844Bc454e4438f44e',
        'ERC20',
        '0xdAC17F958D2ee523a2206206994597C13D831ec7' // USDT on Ethereum
    );
    
    echo "✓ Wallet balance retrieved:\n";
    echo "  Address: {$balance['address']}\n";
    echo "  Token: {$balance['token']['symbol']} ({$balance['token']['name']})\n";
    echo "  Balance: {$balance['balance']['formatted']} {$balance['token']['symbol']}\n";
    
} catch (Exception $e) {
    echo "✗ Error: {$e->getMessage()}\n";
    echo "  Code: {$e->getCode()}\n";
    
    if ($e->getHttpCode()) {
        echo "  HTTP Status: {$e->getHttpCode()}\n";
    }
}