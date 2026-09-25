<?php
/**
 * SagaPay PHP SDK - Exception
 *
 * @package   SagaPay\SDK
 * @author    SagaPay Team
 * @copyright Copyright (c) 2025, SagaPay (https://sagapay.io)
 * @license   MIT
 * @version   1.0.0
 */

namespace SagaPay\SDK;

/**
 * SagaPay Exception Class
 */
class Exception extends \Exception
{
    /**
     * Error codes
     */
    public const NETWORK_ERROR = 1000;
    public const INVALID_RESPONSE = 1001;
    public const API_ERROR = 1002;
    public const INVALID_PARAM = 1003;
    public const INVALID_SIGNATURE = 1004;

    /**
     * HTTP status code
     */
    private int $httpCode;

    /**
     * Error string from API response
     */
    private ?string $errorString = null;

    /**
     * Constructor
     *
     * @param string $message     Error message
     * @param int    $code        Error code
     * @param int    $httpCode    HTTP status code
     * @param string $errorString Error string from API (optional)
     */
    public function __construct(string $message, int $code = 0, int $httpCode = 0, ?string $errorString = null)
    {
        parent::__construct($message, $code);
        $this->httpCode = $httpCode;
        $this->errorString = $errorString;
    }

    /**
     * Get HTTP status code
     *
     * @return int HTTP status code
     */
    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    /**
     * Set error string
     *
     * @param string $errorString Error string from API
     * @return self
     */
    public function setErrorString(string $errorString): self
    {
        $this->errorString = $errorString;
        return $this;
    }

    /**
     * Get error string
     *
     * @return string|null Error string from API
     */
    public function getErrorString(): ?string
    {
        return $this->errorString;
    }
}