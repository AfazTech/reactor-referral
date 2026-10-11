<?php

namespace AfazTech\ReactorReferral\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single referral row.
 *
 * The row is keyed by user_id and holds three orthogonal pieces of
 * information:
 *
 *   - code       : the user's own invite code, generated lazily the
 *                  first time their invite page is opened.
 *   - invited_by : the user_id of whoever invited this user, or null.
 *   - status     : 'pending' until the host application confirms the
 *                  referral through ReferralManager::confirm(), then
 *                  'confirmed'.
 *
 * The unique (user_id) constraint means a user has exactly one row for
 * their entire lifetime; the same row carries both the outbound code
 * and the inbound referral.
 */
class Referral extends Model
{
    protected $table = 'referrals';

    protected $fillable = [
        'user_id',
        'code',
        'invited_by',
        'status',
        'confirmed_at',
    ];

    protected $casts = [
        'user_id'      => 'integer',
        'invited_by'   => 'integer',
        'confirmed_at' => 'datetime',
    ];
}
