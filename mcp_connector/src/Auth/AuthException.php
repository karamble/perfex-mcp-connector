<?php

namespace PerfexMcp\Auth;

/**
 * Thrown when a request fails authentication or rate limiting. The exception
 * code carries the HTTP status the endpoint should return (401 or 429).
 */
final class AuthException extends \RuntimeException
{
    public function __construct(string $message, int $httpStatus = 401)
    {
        parent::__construct($message, $httpStatus);
    }

    public function httpStatus(): int
    {
        $code = $this->getCode();

        return $code >= 400 ? $code : 401;
    }
}
