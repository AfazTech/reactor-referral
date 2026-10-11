<?php

namespace AfazTech\ReactorReferral\Support;

/**
 * Random referral code generator.
 *
 * The alphabet is restricted to ASCII letters so that the resulting
 * code is short, unambiguous when read out loud, and safe to embed in
 * a URL path segment.
 */
class CodeGenerator
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Generate a random code composed of the given prefix and $length
     * alphabet characters.
     */
    public function generate(int $length, string $prefix): string
    {
        $length = max(1, $length);
        $max    = strlen(self::ALPHABET) - 1;
        $body   = '';

        for ($i = 0; $i < $length; $i++) {
            $body .= self::ALPHABET[random_int(0, $max)];
        }

        return $prefix . $body;
    }
}
