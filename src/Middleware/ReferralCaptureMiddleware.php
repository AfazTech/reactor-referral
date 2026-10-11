<?php

namespace AfazTech\ReactorReferral\Middleware;

use AfazTech\ReactorReferral\Contracts\ReferralManagerInterface;
use AfazTech\ReactorReferral\Events;
use AfazTech\ReactorReferral\Models\Referral;
use AfazTech\ReactorReferral\Support\ReferralConfig;
use Reactor\Attributes\Middleware;
use Reactor\Attributes\OnUpdate;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\MiddlewareInterface;
use Reactor\Core\EventDispatcher;
use Reactor\Enums\MiddlewareMode;

/**
 * Captures the referral payload attached to a /start command.
 *
 * The middleware never blocks an update: it records the attribution
 * and returns false so the pipeline continues normally. This keeps the
 * referral layer orthogonal to every other package; whether the user
 * ultimately reaches a handler (or is blocked by force-join) is
 * decided further down the chain.
 *
 * The captured referral is left in the 'pending' state. The host
 * application promotes it to 'confirmed' through
 * ReferralManager::confirm(), typically from its own StartHandler once
 * every gate has been cleared.
 */
#[Middleware(priority: 60, mode: MiddlewareMode::GLOBAL)]
#[OnUpdate('message')]
class ReferralCaptureMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ReferralManagerInterface $manager,
        private ReferralConfig $config,
        private EventDispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(array $update): bool
    {
        $text = $update['message']['text'] ?? '';
        if (!is_string($text) || $text === '') {
            return false;
        }

        // Match "/start" optionally followed by "@BotUsername" and a
        // single whitespace-separated payload token.
        if (!preg_match('#^/start(?:@\w+)?\s+(\S+)$#', $text, $m)) {
            return false;
        }

        $payload = $m[1];
        $prefix  = $this->config->getCodePrefix();

        if ($prefix !== '' && !str_starts_with($payload, $prefix)) {
            return false;
        }

        $fromId = $update['message']['from']['id'] ?? null;
        if (!is_numeric($fromId)) {
            return false;
        }
        $userId = (int) $fromId;

        $referrer = $this->manager->findByCode($payload);
        if ($referrer === null) {
            return false;
        }

        // Self-referral guard: a user cannot invite themselves.
        if ((int) $referrer->user_id === $userId) {
            return false;
        }

        $referrerId = (int) $referrer->user_id;
        $code       = (string) $referrer->code;

        $existing = Referral::query()->where('user_id', $userId)->first();

        if ($existing !== null) {
            // Never overwrite an existing attribution, and never touch
            // a referral that has already been confirmed or rejected.
            if ($existing->invited_by !== null) {
                return false;
            }
            if ($existing->status !== 'pending') {
                return false;
            }

            $existing->invited_by = $referrerId;
            $existing->save();
        } else {
            // The code for the new user is intentionally left null and
            // generated lazily by ReferralManager::getCode() the first
            // time they open their own invite page.
            Referral::create([
                'user_id'    => $userId,
                'code'       => null,
                'invited_by' => $referrerId,
                'status'     => 'pending',
            ]);
        }

        $this->dispatcher->dispatch(Events::REFERRAL_CAPTURED, $userId, $referrerId, $code);

        $this->logger->debug('Referral captured', [
            'referred_user_id' => $userId,
            'referrer_user_id' => $referrerId,
            'code'             => $code,
        ]);

        return false;
    }
}
