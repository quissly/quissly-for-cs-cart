<?php

declare(strict_types=1);

namespace Quissly\Search\Exception;

final class AuthException extends QuisslyException
{
    private string $detail;

    /** @param string $detail Quissly's reason for refusing, as it said it ('' when it gave none) */
    public function __construct(string $message, int $httpStatus = 0, ?\Throwable $previous = null, string $detail = '')
    {
        parent::__construct($message, $httpStatus, $previous);
        $this->detail = $detail;
    }

    public function detail(): string
    {
        return $this->detail;
    }

    public function shouldFallbackToNative(): bool
    {
        return false;
    }

    public function isMerchantAlert(): bool
    {
        return true;
    }
}
