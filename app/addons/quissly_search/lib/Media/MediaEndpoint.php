<?php

declare(strict_types=1);

namespace Quissly\Search\Media;

use Quissly\Search\Exception\QuisslyException;

/**
 * The storefront's voice and image search endpoints (the WooCommerce plugin's
 * /voice and /image proxy routes): take the recorded audio or resized photo, sign
 * and forward it to Quissly server-side (visitors can never forge a Quissly call),
 * keep the ordered ids behind a token, and answer with what the browser needs to
 * open the results page.
 *
 * Public by design (search is public): each endpoint is off unless its switch is
 * on, throttled per visitor, and size-capped.
 */
final class MediaEndpoint
{
    public const QUERY_VAR_VOICE = 'quissly_voice';
    public const QUERY_VAR_IMAGE = 'quissly_img';

    public const RATE_MAX = 10;
    public const RATE_WINDOW_SECONDS = 60;

    /** ~5 s of 16 kHz mono 16-bit WAV is ~160 KB raw, ~215 KB base64. */
    public const MAX_AUDIO_BASE64 = 300000;
    /** A 1024 px JPEG is well under this. */
    public const MAX_IMAGE_BASE64 = 2000000;

    public const PAGE_SIZE = 24;

    private TokenStore $tokens;
    private RateLimiter $limiter;

    /** @var callable(string, string): array{ids:list<int>, transcription:string} (kind, base64) — may throw QuisslyException */
    private $search;

    public function __construct(TokenStore $tokens, RateLimiter $limiter, callable $search)
    {
        $this->tokens = $tokens;
        $this->limiter = $limiter;
        $this->search = $search;
    }

    /**
     * @param string $kind 'voice' | 'image'
     * @return array{status:int, body:array<string, mixed>}
     */
    public function handle(string $kind, string $base64, bool $enabled, string $visitor, int $now): array
    {
        if (!in_array($kind, ['voice', 'image'], true) || !$enabled) {
            return ['status' => 404, 'body' => ['error' => 'not_found']];
        }
        if (!$this->limiter->allow($kind . '|' . $visitor, self::RATE_MAX, self::RATE_WINDOW_SECONDS, $now)) {
            return ['status' => 429, 'body' => ['error' => 'rate_limited']];
        }
        $max = $kind === 'voice' ? self::MAX_AUDIO_BASE64 : self::MAX_IMAGE_BASE64;
        if ($base64 === '' || strlen($base64) > $max || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $base64)) {
            return ['status' => 400, 'body' => ['error' => $base64 === '' ? 'empty' : 'invalid_payload']];
        }

        try {
            $found = ($this->search)($kind, $base64);
        } catch (QuisslyException $e) {
            return ['status' => 502, 'body' => ['error' => 'search_unavailable']];
        }

        return ['status' => 200, 'body' => [
            'token'         => $this->tokens->put($found['ids'], $now),
            'transcription' => $kind === 'voice' ? trim($found['transcription']) : '',
            'query_var'     => $kind === 'voice' ? self::QUERY_VAR_VOICE : self::QUERY_VAR_IMAGE,
        ]];
    }
}
