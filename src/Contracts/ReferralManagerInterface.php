<?php

namespace AfazTech\ReactorReferral\Contracts;

use AfazTech\ReactorReferral\Models\Referral;

/**
 * Public API of the referral package.
 *
 * The manager is responsible for code generation, deep-link
 * composition, attribution lookup and manual confirmation. It never
 * grants rewards: that responsibility belongs to the host application,
 * which subscribes to Events::REFERRAL_CONFIRMED and reacts
 * accordingly.
 */
interface ReferralManagerInterface
{
    /**
     * Return the user's invite link, generating their code if this is
     * the first time their invite page is accessed.
     */
    public function getLink(int $userId): string;

    /**
     * Return the user's invite code, generating it if missing.
     */
    public function getCode(int $userId): string;

    /**
     * Return aggregated statistics for the given referrer.
     *
     * @return array{confirmed: int, pending: int, total: int}
     */
    public function stats(int $userId): array;

    /**
     * Return the list of users this user has invited, most recent
     * first.
     *
     * @return array<int, Referral>
     */
    public function listReferred(int $userId, int $offset = 0, int $limit = 50): array;

    /**
     * Find the referral row that owns the given code, or null when the
     * code does not exist.
     */
    public function findByCode(string $code): ?Referral;

    /**
     * Promote a pending referral to confirmed.
     *
     * The call is idempotent: it returns false and fires no event when
     * the row is missing, has no referrer, or is already confirmed.
     * On the first successful call the row's status becomes
     * 'confirmed' and Events::REFERRAL_CONFIRMED is dispatched.
     *
     * @param int    $referredUserId The user whose referral is being confirmed.
     * @param string $reason         Free-form tag for logging and analytics.
     *
     * @return bool True when the row transitioned from pending to confirmed.
     */
    public function confirm(int $referredUserId, string $reason = 'default'): bool;
}
