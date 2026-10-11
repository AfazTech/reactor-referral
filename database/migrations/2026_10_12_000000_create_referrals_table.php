<?php

namespace AfazTech\ReactorReferral\Migrations;

use Reactor\Database\Migrations\Migration;
use Reactor\Contracts\DatabaseManagerInterface;

class CreateReferralsTable extends Migration
{
    public function __construct(DatabaseManagerInterface $db)
    {
        parent::__construct($db);
    }

    public function up(): void
    {
        if (!$this->db->schema()->hasTable('referrals')) {
            $this->db->schema()->create('referrals', function ($table) {
                $table->id();
                // Owner of the code. One row per Telegram user.
                $table->bigInteger('user_id')->unique();
                // Referral code. Nullable because a row can be created
                // by the capture middleware before the user ever opens
                // their own invite page; the code is then generated
                // lazily on first access.
                $table->string('code', 32)->nullable()->unique();
                // Referrer's user_id, or null when the user arrived
                // without a referral link.
                $table->bigInteger('invited_by')->nullable();
                // 'pending' until ReferralManager::confirm() is called,
                // then 'confirmed'.
                $table->string('status', 16)->default('pending');
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
                $table->index(['invited_by', 'status']);
                $table->index(['status', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        $this->db->schema()->dropIfExists('referrals');
    }
}
