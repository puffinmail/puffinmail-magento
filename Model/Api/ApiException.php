<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model\Api;

/**
 * A failed Store API call. getCode() is the HTTP status (0 for network errors); getErrorCode() is the API's
 * `code` (unauthorized, account_inactive, invalid_request, engine_error, ...).
 */
class ApiException extends \RuntimeException
{
    /**
     * ApiException constructor.
     *
     * @param string $message
     * @param int $status HTTP status, 0 for network errors
     * @param string $errorCode the API's error `code`
     * @param int $retryAfter seconds from a Retry-After header, 0 if none
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message,
        int $status,
        private readonly string $errorCode = 'api_error',
        private readonly int $retryAfter = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * The API's error `code` (unauthorized, account_inactive, invalid_request, ...).
     *
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Seconds from a Retry-After header, 0 if none.
     *
     * @return int
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    /**
     * Network errors, timeouts, rate limits and server errors are worth retrying; other 4xx responses are not.
     *
     * @return bool
     */
    public function isRetryable(): bool
    {
        $status = $this->getCode();
        return $status === 0 || $status === 408 || $status === 429 || $status >= 500;
    }

    /**
     * The API key is wrong or was revoked.
     *
     * @return bool
     */
    public function isUnauthorized(): bool
    {
        return $this->getCode() === 401;
    }
}
