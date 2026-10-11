<?php

namespace AfazTech\ReactorReferral;

use AfazTech\ReactorReferral\Contracts\ReferralManagerInterface;
use AfazTech\ReactorReferral\Models\Referral;
use AfazTech\ReactorReferral\Support\CodeGenerator;
use AfazTech\ReactorReferral\Support\ReferralConfig;
use Carbon\Carbon;
use Neili\Client;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\EventDispatcher;

/**
 * Default ReferralManagerInterface implementation.
 *
 * The referrals table is the single source of truth. Code generation
 * happens lazily: a user's code is only created when they open their
 * invite page, which keeps the table free of unused codes for users
 * who never invite anyone.
 */
class ReferralManager implements ReferralManagerInterface
{
    private ?string $botUsername = null;

    public function __construct(
        private Client $client,
        private ReferralConfig $config,
        private EventDispatcher $dispatcher,
        private LoggerInterface $logger,
        private CodeGenerator $codeGenerator = new CodeGenerator(),
    ) {
    }

    public function getLink(int $userId): string
    {
        $username = $this->getBotUsername();
        if ($username === '') {
            return '';
        }

        $code = $this->getCode($userId);

        return "https://t.me/{$username}?start={$code}";
    }

    public function getCode(int $userId): string
    {
        $referral = Referral::query()->where('user_id', $userId)->first();

        if ($referral !== null && !empty($referral->code)) {
            return (string) $referral->code;
        }

        $code = $this->generateUniqueCode();

        if ($referral === null) {
            Referral::create([
                'user_id'    => $userId,
                'code'       => $code,
                'invited_by' => null,
                'status'     => 'pending',
            ]);
        } else {
            $referral->code = $code;
            $referral->save();
        }

        return $code;
    }

    public function stats(int $userId): array
    {
        $confirmed = (int) Referral::query()
            ->where('invited_by', $userId)
            ->where('status', 'confirmed')
            ->count();

        $pending = (int) Referral::query()
            ->where('invited_by', $userId)
            ->where('status', 'pending')
            ->count();

        return [
            'confirmed' => $confirmed,
            'pending'   => $pending,
            'total'     => $confirmed + $pending,
        ];
    }

    public function listReferred(int $userId, int $offset = 0, int $limit = 50): array
    {
        return Referral::query()
            ->where('invited_by', $userId)
            ->orderByDesc('id')
            ->offset(max(0, $offset))
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->all();
    }

    public function findByCode(string $code): ?Referral
    {
        if ($code === '') {
            return null;
        }

        return Referral::query()->where('code', $code)->first();
    }

    public function confirm(int $referredUserId, string $reason = 'default'): bool
    {
        $referral = Referral::query()->where('user_id', $referredUserId)->first();

        if ($referral === null) {
            return false;
        }
        if ($referral->invited_by === null) {
            return false;
        }
        if ($referral->status === 'confirmed') {
            return false;
        }

        $referral->status       = 'confirmed';
        $referral->confirmed_at = Carbon::now();
        $referral->save();

        $this->dispatcher->dispatch(
            Events::REFERRAL_CONFIRMED,
            $referredUserId,
            (int) $referral->invited_by,
            (string) ($referral->code ?? '')
        );

        $this->logger->info('Referral confirmed', [
            'referred_user_id' => $referredUserId,
            'referrer_user_id' => (int) $referral->invited_by,
            'reason'           => $reason,
        ]);

        return true;
    }

    private function generateUniqueCode(): string
    {
        $length = $this->config->getCodeLength();
        $prefix = $this->config->getCodePrefix();

        // A short retry loop is more than enough: with a 52-character
        // alphabet and the default length of 8, the collision
        // probability is negligible.
        for ($i = 0; $i < 5; $i++) {
            $code = $this->codeGenerator->generate($length, $prefix);
            if (!Referral::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        // Fallback for the practically impossible case of repeated
        // collisions: append a random suffix to break the tie.
        return $prefix . bin2hex(random_bytes(6));
    }

    private function getBotUsername(): string
    {
        if ($this->botUsername !== null) {
            return $this->botUsername;
        }

        try {
            $response = $this->client->getMe()->await();
            $this->botUsername = (string) ($response['result']['username'] ?? '');
        } catch (\Throwable $e) {
            $this->logger->warning('getMe failed while resolving bot username', [
                'error' => $e->getMessage(),
            ]);
            $this->botUsername = '';
        }

        return $this->botUsername;
    }
}
