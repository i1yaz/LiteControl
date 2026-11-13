<?php

namespace App\Services;

class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a cryptographically random Base32-encoded secret.
     */
    public function generateSecret(int $length = 16): string
    {
        $secret = '';
        $bytes = random_bytes($length);

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[ord($bytes[$i]) & 31];
        }

        return $secret;
    }

    /**
     * Verify a 6-digit TOTP code against a Base32 secret.
     */
    public function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $normalizedCode = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{6}$/', $normalizedCode)) {
            return false;
        }

        $timeSlice = (int) floor(($timestamp ?? time()) / 30);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $expected = $this->getCodeForTimeSlice($secret, $timeSlice + $offset);

            if (hash_equals($expected, $normalizedCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate the 6-digit TOTP code for a given timestamp.
     */
    public function getCode(string $secret, ?int $timestamp = null): string
    {
        $timeSlice = (int) floor(($timestamp ?? time()) / 30);

        return $this->getCodeForTimeSlice($secret, $timeSlice);
    }

    /**
     * Build an otpauth:// URI for authenticator apps.
     */
    public function getOtpAuthUri(string $issuer, string $label, string $secret): string
    {
        $encodedIssuer = rawurlencode($issuer);
        $encodedLabel = rawurlencode($label);

        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $encodedIssuer,
            $encodedLabel,
            $secret,
            $encodedIssuer
        );
    }

    /**
     * Generate a list of single-use recovery codes.
     *
     * @return array<int, string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
        }

        return $codes;
    }

    private function getCodeForTimeSlice(string $secret, int $timeSlice): string
    {
        $key = $this->base32Decode($secret);
        $time = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $time, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
        $binaryString = '';

        foreach (str_split($secret) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $binaryString .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($binaryString, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}

