<?php

namespace App\Services;

/**
 * RFC 6238 TOTP implementatie (Time-based One-Time Password).
 * Voldoet aan: RFC 6238 (TOTP), RFC 4226 (HOTP), RFC 4648 (Base32).
 */
class TotpService
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const DRIFT_WINDOWS = [-1, 0, 1];
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function getCode(string $base32Secret, ?int $timestamp = null): string
    {
        $window = intdiv($timestamp ?? time(), self::PERIOD);

        return $this->calculateHotpCode($base32Secret, $window);
    }

    public function verify(string $base32Secret, string $code, ?int $timestamp = null): bool
    {
        $window = intdiv($timestamp ?? time(), self::PERIOD);

        foreach (self::DRIFT_WINDOWS as $drift) {
            $expected = $this->calculateHotpCode($base32Secret, $window + $drift);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    private function calculateHotpCode(string $base32Secret, int $counter): string
    {
        $keyBytes = $this->base32Decode($base32Secret);
        $counterBytes = pack('J', $counter);

        $hash = hash_hmac('sha1', $counterBytes, $keyBytes, true);

        $offset = ord($hash[19]) & 0x0F;

        $truncated =
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF);

        $code = $truncated % (10 ** self::DIGITS);

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $bytes): string
    {
        $binary = '';
        foreach (str_split($bytes) as $byte) {
            $binary .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $padLength = (int) (ceil(strlen($binary) / 5) * 5);
        $result = '';
        foreach (str_split(str_pad($binary, $padLength, '0'), 5) as $chunk) {
            $result .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $result;
    }

    private function base32Decode(string $encoded): string
    {
        $binary = '';
        foreach (str_split(strtoupper($encoded)) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $result = '';
        $length = (int) (floor(strlen($binary) / 8) * 8);
        foreach (str_split(substr($binary, 0, $length), 8) as $chunk) {
            $result .= chr(bindec($chunk));
        }

        return $result;
    }
}
