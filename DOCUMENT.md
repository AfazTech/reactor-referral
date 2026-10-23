# Reactor Referral — Documentation

Complete reference for the `afaztech/reactor-referral` package.

---

## Table of Contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Core Concepts](#core-concepts)
- [API Reference](#api-reference)
  - [Invite Links and Codes](#invite-links-and-codes)
  - [Statistics and Listing](#statistics-and-listing)
  - [Confirmation](#confirmation)
  - [Lookup](#lookup)
- [Attribution Flow](#attribution-flow)
- [Events](#events)
- [Storage](#storage)
- [Error Handling](#error-handling)
- [Examples](#examples)
- [Testing](#testing)

---

## Overview

Reactor Referral turns `https://t.me/YourBot?start=ref_XXXX` into a full attribution pipeline. It generates per-user referral codes, composes invite links, tracks who invited whom, and emits events when a referral is confirmed.

The package is deliberately scoped:

- **Code generation** — per-user invite codes, lazily generated on first access.
- **Link composition** — combines the bot's username with the user's code into a `?start=` deep link.
- **Attribution state** — a single `referrals` row per user, holding both their outbound code (as referrer) and inbound attribution (as referred).
- **Confirmation** — a manual transition from `pending` to `confirmed`, driven by the host application.
- **Statistics** — counts of confirmed and pending referrals per referrer.

The package **does not grant rewards, does not capture referral payloads, and does not ship any handler or middleware**. It is a pure state + events layer:

- **Capture** is the host application's job: parse `/start ref_XXX`, call `findByCode()`, write the attribution row (or use the `Referral` model directly), emit the `REFERRAL_CAPTURED` event.
- **Reward** is the host application's job: listen to `REFERRAL_CONFIRMED` and act.
- **Confirmation timing** is the host application's decision: call `confirm()` when the referral is deemed legitimate (after passing a gate, after N messages, after a delay).

The package provides the primitives. The application decides the policy.

---

## Requirements

- PHP >= 8.1
- `afaztech/reactor-framework` ^0.5.0

The framework provides `Neili\Client` (used only for `getMe` to resolve the bot's username), `DatabaseManagerInterface`, `EventDispatcher`, and `LoggerInterface`.

---

## Installation

```bash
composer require afaztech/reactor-referral
./reactor migrate
```

The migration creates the `referrals` table. The service provider is auto-discovered via Composer metadata.

---

## Configuration

The package is configured through the fluent `ReferralConfig` value object. Bind a customised instance in the application's service provider — it runs after the package provider, so the binding wins.

```php
use AfazTech\ReactorReferral\Support\ReferralConfig;

$container->singleton(ReferralConfig::class, fn () => (new ReferralConfig())
    ->codeLength(8)
    ->codePrefix('ref_'));
```

| Setter | Default | Description |
|--------|---------|-------------|
| `codeLength(int $length)` | `8` | Number of random alphabet characters in the code body. Clamped to `[1, 32]`. |
| `codePrefix(string $prefix)` | `ref_` | Prefix prepended to every generated code. Only `A-Z`, `a-z`, `0-9`, and `_` are kept. |

With the defaults, a generated code looks like `ref_aB3xY9kQ`.

### Custom Code Generator

`CodeGenerator` produces the random body of the code. It is bound as a singleton and can be replaced:

```php
use AfazTech\ReactorReferral\Support\CodeGenerator;

$container->singleton(CodeGenerator::class, fn () => new class extends CodeGenerator {
    public function generate(int $length, string $prefix): string
    {
        // Return a code with a predictable format for testing.
    }
});
```

The manager calls `generate($length, $prefix)` and retries up to 5 times if the result collides with an existing code. If all retries collide (astronomically unlikely with 8 random characters), the manager appends `bin2hex(random_bytes(6))` as a fallback.

### Accessing the Manager

```php
use AfazTech\ReactorReferral\Contracts\ReferralManagerInterface;

$referral = $container->get(ReferralManagerInterface::class);
```

The binding is a singleton.

---

## Core Concepts

### Referral Row

A single row per user in the `referrals` table, holding:

| Field | Meaning |
|-------|---------|
| `user_id` | The user's Telegram id (unique, one row per lifetime) |
| `code` | The user's own invite code (nullable until generated) |
| `invited_by` | The referrer's user id, or `null` |
| `status` | `pending` or `confirmed` |
| `confirmed_at` | Set when `status` becomes `confirmed` |

The unique `user_id` constraint means each user has exactly one row for their entire lifetime — the same row carries both their outbound code (as referrer) and their inbound attribution (as referred).

### Pending vs Confirmed

- **pending** — the user was attributed to a referrer, but no reward has been granted yet. Set by the host application when the referral is captured.
- **confirmed** — the referral has been validated. The `REFERRAL_CONFIRMED` event has fired. Reward conditions are the host application's choice.

The package never transitions `pending → confirmed` on its own. Only an explicit `confirm()` call does that.

### Lazy Code Generation

A user's code is generated only when `getCode()` or `getLink()` is called for the first time. Users who never invite anyone never have a code written to the database. This keeps the table small even for large bots.

### Self-Referral

A user cannot use their own code. Detection is the host application's job: after `findByCode($payload)`, compare `$referrer->user_id` with the incoming `$userId` and reject on a match.

---

## API Reference

### Invite Links and Codes

#### `getLink(int $userId): string`

Returns the user's `?start=` invite link. The code is generated lazily on first call.

```php
$link = $referral->getLink($userId);
// https://t.me/YourBot?start=ref_aB3xY9kQ
```

**Behavior:**

1. Resolves the bot's username via `getMe` (cached in-process for the manager's lifetime).
2. Returns `''` if the username could not be resolved (e.g. `getMe` failed).
3. Calls `getCode()` to ensure the code exists.
4. Composes `https://t.me/{username}?start={code}`.

**Note:** the returned link is only usable by the host application if it actually handles `?start=ref_XXX` payloads. See [Attribution Flow](#attribution-flow).

#### `getCode(int $userId): string`

Returns the user's own invite code. Generates it if missing.

```php
$code = $referral->getCode($userId);
// ref_aB3xY9kQ
```

**Behavior:**

1. Looks up the row by `user_id`.
2. If a code is already stored, returns it.
3. Otherwise generates a unique code via `generateUniqueCode()` and either:
   - creates a new row (`code`, `invited_by = null`, `status = 'pending'`), or
   - updates the existing row (which may already carry `invited_by` from a prior capture).

---

### Statistics and Listing

#### `stats(int $userId): array`

Returns aggregate counts for the given referrer.

```php
$stats = $referral->stats($userId);
// ['confirmed' => 12, 'pending' => 3, 'total' => 15]
```

- `confirmed` — rows where `invited_by = $userId AND status = 'confirmed'`
- `pending` — rows where `invited_by = $userId AND status = 'pending'`
- `total` — sum of both

Users attributed to the referrer but whose row has some other status (never the case today) are not counted.

#### `listReferred(int $userId, int $offset = 0, int $limit = 50): array`

Returns an array of `Referral` models representing the users this referrer has invited, ordered by most recent first.

| Parameter | Default | Description |
|-----------|---------|-------------|
| `$offset` | `0` | Number of rows to skip |
| `$limit` | `50` | Page size, clamped to `[1, 200]` |

```php
$page1 = $referral->listReferred($userId, offset: 0, limit: 50);
$page2 = $referral->listReferred($userId, offset: 50, limit: 50);
```

Each row carries `user_id`, `status`, `confirmed_at`, and `created_at`, which the caller can use for display.

---

### Confirmation

#### `confirm(int $referredUserId, string $reason = 'default'): bool`

Promotes a `pending` referral to `confirmed`.

**Behavior:**

1. Looks up the row by `user_id`.
2. Returns `false` when the row does not exist, has no `invited_by`, or is already `confirmed`.
3. Otherwise sets `status = confirmed`, `confirmed_at = now()`, saves.
4. Dispatches `Events::REFERRAL_CONFIRMED` with `($referredUserId, $referrerId, $code)`.
5. Logs an info entry with the `reason` tag.

Returns `true` when the row transitioned from pending to confirmed.

**Idempotent:** repeated calls return `false` and never re-fire the event.

**Reason tag:** free-form string used only for logging and analytics. Common values:

```php
$referral->confirm($userId, 'force_join_passed');
$referral->confirm($userId, 'start_handler');
$referral->confirm($userId, 'activity_threshold');
```

---

### Lookup

#### `findByCode(string $code): ?Referral`

Returns the referral row that owns the given code, or `null`.

```php
$referrer = $referral->findByCode('ref_aB3xY9kQ');
if ($referrer !== null) {
    $referrerId = (int) $referrer->user_id;
}
```

Returns `null` for an empty code. The lookup uses the unique index on `code`, so it is O(1) at the database level.

---

## Attribution Flow

The package provides the primitives. The host application wires them into a capture flow. A minimal capture middleware:

```php
<?php

namespace App\Middleware;

use AfazTech\ReactorReferral\Contracts\ReferralManagerInterface;
use AfazTech\ReactorReferral\Models\Referral;
use Reactor\Attributes\Middleware;
use Reactor\Attributes\OnUpdate;
use Reactor\Contracts\MiddlewareInterface;
use Reactor\Enums\MiddlewareMode;

#[Middleware(priority: 60, mode: MiddlewareMode::GLOBAL)]
#[OnUpdate('message')]
class CaptureReferralMiddleware implements MiddlewareInterface
{
    public function __construct(private ReferralManagerInterface $manager) {}

    public function handle(array $update): bool
    {
        $text = $update['message']['text'] ?? '';
        if (!preg_match('#^/start(?:@\w+)?\s+(\S+)$#', (string) $text, $m)) {
            return false;
        }

        $code = $m[1];
        $userId = (int) ($update['message']['from']['id'] ?? 0);
        if ($userId === 0) {
            return false;
        }

        $referrer = $this->manager->findByCode($code);
        if ($referrer === null) {
            return false;
        }

        // Self-referral guard.
        if ((int) $referrer->user_id === $userId) {
            return false;
        }

        // Never overwrite an existing attribution.
        $existing = Referral::query()->where('user_id', $userId)->first();
        if ($existing !== null) {
            if ($existing->invited_by !== null || $existing->status !== 'pending') {
                return false;
            }
            $existing->invited_by = (int) $referrer->user_id;
            $existing->save();
        } else {
            Referral::create([
                'user_id'    => $userId,
                'code'       => null,
                'invited_by' => (int) $referrer->user_id,
                'status'     => 'pending',
            ]);
        }

        return false; // never block
    }
}
```

Then, when the referral is deemed legitimate (typically from the `/start` handler after every gate has passed):

```php
$referral->confirm($userId, 'start_handler');
```

The package never writes to the `referrals` table on its own initiative except when generating codes and confirming rows. Capture is entirely the application's responsibility.

---

## Events

Subscribe via the framework's `EventDispatcher`:

```php
use AfazTech\ReactorReferral\Events;
use Reactor\Core\EventDispatcher;

$dispatcher = $container->get(EventDispatcher::class);

$dispatcher->on(Events::REFERRAL_CONFIRMED, function (
    int $referredId,
    int $referrerId,
    string $code,
) use ($client, $db) {
    // Grant the reward here.
    $db->table('users')->where('user_id', $referrerId)->increment('points', 10);

    $client->sendMessage(
        $referrerId,
        '🎉 Someone joined through your link! You earned 10 points.'
    )->await();
});
```

| Event | Arguments | Fires when |
|-------|-----------|------------|
| `REFERRAL_CONFIRMED` | `(int $referredId, int $referrerId, string $code)` | `ReferralManager::confirm()` promotes a pending row to confirmed |

The `REFERRAL_CAPTURED` constant remains in the `Events` class for backward compatibility but is **not dispatched by the package**. The host application's capture middleware can dispatch it directly via `EventDispatcher` if desired:

```php
$dispatcher->dispatch(Events::REFERRAL_CAPTURED, $userId, $referrerId, $code);
```

---

## Storage

### `referrals`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | |
| `user_id` | bigint, unique | One row per Telegram user |
| `code` | varchar(32), unique, nullable | Generated lazily; `null` until first access |
| `invited_by` | bigint, nullable | Referrer's user id |
| `status` | varchar(16) | `pending` or `confirmed` |
| `confirmed_at` | timestamp, nullable | Set when `status` becomes `confirmed` |
| `created_at`, `updated_at` | timestamps | |
| — | index | `(invited_by, status)` |
| — | index | `(status, created_at)` |

The unique `user_id` constraint makes the row the single source of truth for both roles (outbound code and inbound attribution). The unique `code` constraint makes lookup O(1).

The `(invited_by, status)` index accelerates `stats()` and `listReferred()`. The `(status, created_at)` index accelerates admin queries like "all confirmed referrals this week".

---

## Error Handling

| Situation | Behavior |
|-----------|----------|
| `getMe` fails during `getLink()` | Returns `''`; warning logged |
| Code collision during generation | Retried up to 5 times; falls back to hex suffix on persistent collision |
| `confirm()` on missing row | Returns `false`, no event |
| `confirm()` on row with no `invited_by` | Returns `false`, no event |
| `confirm()` on already-confirmed row | Returns `false`, no event |
| `findByCode()` with empty string | Returns `null` |
| `listReferred()` with `limit` outside `[1, 200]` | Clamped silently |

All public API methods either return a value or throw `ReferralException` (unused by the current implementation but available for host-application extensions).

The package does not catch exceptions from `Neili\Client::getMe()` in `getLink()` except to fall back to `''`. Any other exception (database, code generator) propagates to the caller.

---

## Examples

### Building an invite command

```php
<?php

namespace App\Handlers;

use AfazTech\ReactorReferral\Contracts\ReferralManagerInterface;
use App\Handlers\BaseHandler;
use Reactor\Attributes\OnUpdate;
use Reactor\Attributes\Text;

#[Text(name: '/ref', isCommand: true, priority: 80)]
#[OnUpdate('message')]
class MyRefCommand extends BaseHandler
{
    public function __construct(
        // ... base handler dependencies ...
        private ReferralManagerInterface $referral,
    ) {
        parent::__construct(/* ... */);
    }

    protected function handle(array $params = []): void
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return;
        }

        $link  = $this->referral->getLink($userId);
        $stats = $this->referral->stats($userId);

        $this->reply(
            "Your invite link:\n{$link}\n\n"
            . "✅ Confirmed: {$stats['confirmed']}\n"
            . "⏳ Pending: {$stats['pending']}\n"
            . "📊 Total: {$stats['total']}"
        );
    }
}
```

### Gating confirmation on force-join

```php
use AfazTech\ReactorJoin\Events as JoinEvents;

$dispatcher->on(JoinEvents::JOIN_PASSED, function (int $userId) use ($referral) {
    $referral->confirm($userId, 'force_join_passed');
});
```

### Confirmation from the `/start` handler

```php
protected function handle(array $params = []): void
{
    $userId = $this->getUserId();
    if ($userId !== null) {
        // By the time this handler runs, any force-join middleware
        // has already passed (or blocked) the update.
        $this->referral->confirm($userId, 'start_handler');
    }

    // ... show the main menu ...
}
```

### Reward listener

```php
use AfazTech\ReactorReferral\Events;

$dispatcher->on(Events::REFERRAL_CONFIRMED, function (
    int $referredId,
    int $referrerId,
    string $code,
) use ($client, $db) {
    // Increment a counter.
    $db->table('users')->where('user_id', $referrerId)->increment('referral_count');

    // Unlock a feature.
    if ($db->table('users')->where('user_id', $referrerId)->value('referral_count') >= 5) {
        $db->table('users')->where('user_id', $referrerId)->update(['premium' => true]);
    }

    // Notify the referrer.
    $client->sendMessage($referrerId, '🎉 You earned a reward for referring someone!')->await();
});
```

### Fraud detection hook

```php
use AfazTech\ReactorReferral\Events;

$dispatcher->on(Events::REFERRAL_CAPTURED, function (
    int $referredId,
    int $referrerId,
    string $code,
) use ($db) {
    // The host application may choose to reject suspicious referrals.
    // For example, reject if the referrer signed up less than an hour
    // before the referred user.
    $referrerCreatedAt = $db->table('users')
        ->where('user_id', $referrerId)
        ->value('created_at');

    if (strtotime((string) $referrerCreatedAt) > time() - 3600) {
        // Flag for review; do not confirm later.
    }
});
```

Because `confirm()` is manual, the host application can gate it on any condition — signup age, activity level, country, reputation — without modifying the package.

### Scheduled audit of stale pending referrals

```php
$schedule->call(function (Container $c) {
    $db = $c->get(\Reactor\Contracts\DatabaseManagerInterface::class);

    // Automatically confirm referrals older than 7 days that are still pending.
    $stale = $db->table('referrals')
        ->where('status', 'pending')
        ->where('invited_by', '!=', null)
        ->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-7 days')))
        ->pluck('user_id')
        ->toArray();

    $referral = $c->get(\AfazTech\ReactorReferral\Contracts\ReferralManagerInterface::class);
    foreach ($stale as $userId) {
        $referral->confirm((int) $userId, 'auto_after_7_days');
    }
})->daily();
```

---

## Testing

`ReferralManagerInterface` is a container-bound singleton and can be overridden in tests.

### Mocking the manager

```php
use AfazTech\ReactorReferral\Contracts\ReferralManagerInterface;

$referral = $this->createMock(ReferralManagerInterface::class);
$referral->method('getLink')->willReturn('https://t.me/Bot?start=ref_abc');
$referral->method('stats')->willReturn([
    'confirmed' => 10,
    'pending'   => 2,
    'total'     => 12,
]);
$referral->method('confirm')->willReturn(true);

$container->instance(ReferralManagerInterface::class, $referral);
```

### Testing code generation

Override `CodeGenerator` to produce predictable codes:

```php
$generator = new class extends CodeGenerator {
    public function generate(int $length, string $prefix): string
    {
        return $prefix . str_repeat('a', $length);
    }
};

$container->instance(CodeGenerator::class, $generator);
```

Then `getCode($userId)` returns `ref_aaaaaaaa` deterministically.

### Testing `confirm()` idempotency

```php
$referral->confirm(42, 'test');
$second = $referral->confirm(42, 'test');

$this->assertTrue($first);
$this->assertFalse($second);
```

### Testing events

```php
$dispatcher = $container->get(\Reactor\Core\EventDispatcher::class);

$fired = false;
$dispatcher->on(\AfazTech\ReactorReferral\Events::REFERRAL_CONFIRMED, function () use (&$fired) {
    $fired = true;
});

$referral->confirm(42);

$this->assertTrue($fired);
```

### Integration tests

Run migrations against a throwaway SQLite file. Use the real manager, seed the `referrals` table, and assert:

- `getCode()` inserts a row on first call and returns the same code on second.
- `getLink()` returns `''` when `Neili\Client::getMe()` fails.
- `confirm()` on a row with `invited_by = null` returns `false`.
- `stats()` counts only `pending` and `confirmed` rows.

---

## License

This project is licensed under the [MIT License](LICENSE).