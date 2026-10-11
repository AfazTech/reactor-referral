<?php

namespace AfazTech\ReactorReferral;

/**
 * Event names dispatched by the referral package.
 *
 * Consumers subscribe through the framework's EventDispatcher:
 *
 *   $dispatcher->on(\AfazTech\ReactorReferral\Events::REFERRAL_CONFIRMED,
 *       function (int $referredId, int $referrerId, string $code) {
 *           // Grant the reward here.
 *       });
 */
final class Events
{
    /**
     * Fired the moment a referral link is resolved and the new user's
     * row is linked to their referrer. The referral is still pending at
     * this stage; no reward should be granted yet.
     *
     * Arguments: (int $referredUserId, int $referrerUserId, string $code)
     */
    public const REFERRAL_CAPTURED = 'referral.captured';

    /**
     * Fired when ReferralManager::confirm() promotes a pending
     * referral to confirmed. This is the only event that should trigger
     * a reward.
     *
     * Arguments: (int $referredUserId, int $referrerUserId, string $code)
     */
    public const REFERRAL_CONFIRMED = 'referral.confirmed';
}
