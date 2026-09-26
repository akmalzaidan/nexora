<?php

namespace App\Support\Audit;

/**
 * Removes sensitive values from data destined for the audit trail (Phase 20A).
 *
 * The audit table is append-only and broadly readable by governance users, so
 * it must never contain credentials. This filter drops keys matching the
 * sensitive-name list at any depth and never inspects values — only key names —
 * so a value that merely *contains* the word "password" is not enough to leak:
 * the whole entry is removed.
 *
 * Non-string scalar and array values pass through untouched; objects and
 * closures are never serializable here and are dropped.
 */
final class SensitiveValueFilter
{
    /**
     * Keys that are never written to the audit trail, matched case-insensitively.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'password confirmation',
        'new_password',
        'new_password_confirmation',
        'old_password',
        'current_password',
        'remember_token',
        'access_token',
        'refresh_token',
        'bearer_token',
        'api_key',
        'authorization',
        'cookie',
        'session_secret',
        'secret',
        'token',
        'plain_text_token',
    ];

    /**
     * @param  mixed  $values  Arbitrary payload about to be stored in the audit trail.
     * @return array<string, mixed> Safe copy with sensitive entries removed.
     */
    public static function clean(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return self::filter($values);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private static function filter(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::filter($value);

                continue;
            }

            if (is_object($value) || is_resource($value) || $value === null) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private static function isSensitive(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (strtolower($key) === $sensitive) {
                return true;
            }
        }

        return false;
    }
}
