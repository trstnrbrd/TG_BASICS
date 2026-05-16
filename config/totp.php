<?php
/**
 * Minimal RFC 6238 TOTP implementation — no Composer required.
 * Compatible with Google Authenticator, Authy, Microsoft Authenticator.
 */

function totp_generate_secret(int $length = 32): string {
    $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    $bytes  = random_bytes($length);
    for ($i = 0; $i < $length; $i++) {
        $secret .= $chars[ord($bytes[$i]) & 31];
    }
    return $secret;
}

function totp_base32_decode(string $secret): string {
    $map    = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
    $secret = strtoupper(str_replace(' ', '', $secret));
    $bits   = '';
    foreach (str_split($secret) as $ch) {
        if (!isset($map[$ch])) continue;
        $bits .= str_pad(decbin($map[$ch]), 5, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $output .= chr(bindec($byte));
    }
    return $output;
}

function totp_code(string $secret, int $offset = 0): string {
    $key       = totp_base32_decode($secret);
    $timestamp = floor(time() / 30) + $offset;
    $msg       = pack('N*', 0) . pack('N*', $timestamp);
    $hash      = hash_hmac('sha1', $msg, $key, true);
    $offset_b  = ord($hash[19]) & 0xf;
    $code      = (
        ((ord($hash[$offset_b])   & 0x7f) << 24) |
        ((ord($hash[$offset_b+1]) & 0xff) << 16) |
        ((ord($hash[$offset_b+2]) & 0xff) <<  8) |
         (ord($hash[$offset_b+3]) & 0xff)
    ) % 1000000;
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

function totp_verify(string $secret, string $code, int $window = 1): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret, $i), $code)) return true;
    }
    return false;
}

function totp_qr_uri(string $secret, string $label, string $issuer = 'TG Customworks'): string {
    $label  = rawurlencode($issuer . ':' . $label);
    $issuer = rawurlencode($issuer);
    return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
}

function totp_generate_recovery_codes(int $count = 8): array {
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $raw    = strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
        $codes[] = $raw;
    }
    return $codes;
}
