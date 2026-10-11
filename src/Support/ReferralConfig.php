<?php

namespace AfazTech\ReactorReferral\Support;

/**
 * Fluent configuration for the referral package.
 *
 * Bind a configured instance in the host application's service
 * provider to customise code generation:
 *
 *   $container->singleton(ReferralConfig::class, fn () => (new ReferralConfig())
 *       ->codeLength(10)
 *       ->codePrefix('inv_')
 *   );
 *
 * The package itself does not grant any reward. Host applications
 * subscribe to Events::REFERRAL_CONFIRMED and implement their own
 * reward logic.
 */
final class ReferralConfig
{
    private int $codeLength = 8;
    private string $codePrefix = 'ref_';

    public function codeLength(int $length): self
    {
        $this->codeLength = max(1, min(32, $length));
        return $this;
    }

    public function codePrefix(string $prefix): self
    {
        // Only alphanumerics and underscore are allowed in the prefix
        // so that the resulting code is safe to embed in a Telegram
        // deep link without further escaping.
        $this->codePrefix = preg_replace('/[^A-Za-z0-9_]/', '', $prefix) ?? '';
        return $this;
    }

    public function getCodeLength(): int
    {
        return $this->codeLength;
    }

    public function getCodePrefix(): string
    {
        return $this->codePrefix;
    }
}
