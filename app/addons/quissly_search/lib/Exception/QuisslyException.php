<?php

declare(strict_types=1);

namespace Quissly\Search\Exception;

/**
 * Base for every Quissly failure. Subclasses encode the fallback policy from
 * the fallback matrix so the interceptor can switch on
 * type instead of re-deriving behavior from status codes.
 */
abstract class QuisslyException extends \RuntimeException
{
    /** @var int HTTP status that triggered this (0 for transport/timeout) */
    protected int $httpStatus;

    public function __construct(string $message, int $httpStatus = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = $httpStatus;
    }

    /** HTTP status that triggered this (0 for transport/timeout). */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Whether a page-1 failure of this kind should silently degrade to native
     * CS-Cart search. Auth/payment/plan problems return false — they are
     * merchant-config issues, not transient, and must NOT swap the result set.
     */
    abstract public function shouldFallbackToNative(): bool;

    /**
     * Whether this is a merchant-facing config/billing problem that warrants an
     * admin notice (401/402/403).
     */
    public function isMerchantAlert(): bool
    {
        return false;
    }
}
