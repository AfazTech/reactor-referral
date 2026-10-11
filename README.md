# Reactor Referral

Deep-link referral tracking for **[Reactor](https://github.com/afaztech/reactor-framework)** bots.

> **Built specifically for the Reactor PHP framework.**
> Requires `afaztech/reactor-framework ^0.5.0` and will not work standalone.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Framework](https://img.shields.io/badge/reactor-%5E0.5.0-orange.svg)](https://github.com/afaztech/reactor-framework)

---

## Overview

Reactor Referral turns `https://t.me/YourBot?start=ref_XXXX` into a full attribution pipeline. When a new user arrives through someone's link, the package records who invited them. When you decide that referral has earned its reward — typically after the user has passed every gate your bot requires — you call a single method and the package fires an event. Your application subscribes to that event and grants whatever reward it wants.

The package **does not grant any reward itself**. It generates codes, tracks attribution, and emits events. Reward logic lives entirely in the host application.

### Separation of capture and confirmation

```
/start ref_XXXX
       │
       ▼
ReferralCaptureMiddleware         ← records invited_by, fires REFERRAL_CAPTURED
       │                             status: pending
       ▼
SyncUserMiddleware
       │
       ▼
ForceJoinMiddleware / any gate
       │
       ▼
StartHandler
       │
       ▼
ReferralManager::confirm()        ← fires REFERRAL_CONFIRMED
                                     status: confirmed
```

The capture middleware never blocks an update, and it runs at priority `60` — above `SyncUserMiddleware` (`50`) and above any gate middleware such as force-join (`30`). The referral row therefore exists before any other package observes the user, but no reward is granted until you say so.

Because confirmation is manual, you can gate it on anything: passing a forced-join check, sending N messages, waiting 24 hours, or completing a quiz. The referral package itself knows nothing about those conditions.

---

## Requirements

- PHP >= 8.1
- `afaztech/reactor-framework` ^0.5.0
- A Reactor application with a working database connection

---

## Installation

```bash
composer require afaztech/reactor-referral
php reactor.php migrate
```

The service provider is auto-discovered through Composer metadata (`extra.reactor.providers`), and the migration is picked up automatically from `extra.reactor.migrations_namespace`.

---

## Configuration

The package is configured through the fluent `ReferralConfig` value object. Bind a customised instance in your application's service provider — it runs after the package provider, so your binding wins.

```php
// app/Providers/AppServiceProvider.php

namespace App\Providers;

use Reactor\Core\Container;
use Reactor\Contracts\ServiceProviderInterface;
use AfazTech\ReactorReferral\Support\ReferralConfig;

class AppServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(ReferralConfig::class, function () {
            return (new ReferralConfig())
                ->codeLength(8)
                ->codePrefix('ref_');
        });
    }

    public function boot(Container $container): void {}
}
```

### Available setters

| Method | Default | Description |
| --- | --- | --- |
| `codeLength(int)` | `8` | Number of random alphabet characters in the code body. Clamped to `[1, 32]`. |
| `codePrefix(string)` | `ref_` | Prefix prepended to every generated code. Only `A–Z`, `a–z`, `0–9` and `_` are kept. |

A generated code looks like `ref_aB3xY9kQ` with the default settings.

---

## Language keys

Add these keys to every `lang/*.json` file:

```json
{
    "referral_link_message": "Your invite link:\n{link}\n\nConfirmed: {confirmed}\nPending: {pending}\nTotal: {total}",
    "referral_share_button": "Share",
    "referral_share_text": "Join this bot!"
}
```

`referral_link_message` receives four placeholders: `{link}`, `{confirmed}`, `{pending}`, `{total}`.

---

## Usage

### Getting the manager

```php
use AfazTech\ReactorReferral\Contracts\ReferralManagerInterface;

$referral = $container->get(ReferralManagerInterface::class);
```

### Generating a user's invite link

```php
$link = $referral->getLink($userId);
// https://t.me/YourBot?start=ref_aB3xY9kQ
```

The user's code is generated lazily. Calling `getLink()` for the first time creates the code and persists it; subsequent calls return the same value.

If you only need the code:

```php
$code = $referral->getCode($userId);
```

### Reading statistics

```php
$stats = $referral->stats($userId);
// ['confirmed' => 12, 'pending' => 3, 'total' => 15]
```

### Listing referred users

```php
$list = $referral->listReferred($userId, offset: 0, limit: 50);
// Referral[]
```

Results are ordered by most recent first. `limit` is clamped to `[1, 200]`.

### Looking up a referrer by code

```php
$referrer = $referral->findByCode('ref_aB3xY9kQ');
if ($referrer !== null) {
    echo $referrer->user_id;
}
```

### Confirming a referral

This is the reward trigger. Call it once your application has decided the referral is legitimate.

```php
$confirmed = $referral->confirm($referredUserId);
// true  → the row transitioned from pending to confirmed and REFERRAL_CONFIRMED fired
// false → nothing happened (no row, no referrer, or already confirmed)
```

The method is idempotent: repeated calls return `false` and never re-fire the event.

The optional second argument is a free-form tag used for logging and analytics:

```php
$referral->confirm($referredUserId, 'force_join_passed');
$referral->confirm($referredUserId, 'activity_threshold');
```

### Wiring confirmation into your handlers

Because capture happens in middleware but confirmation is manual, the natural place to call `confirm()` is inside your own `StartHandler` (or any handler that runs only after every gate has been cleared):

```php
<?php
namespace App\Handlers;

use AfazTech\ReactorReferral\Contracts\ReferralManagerInterface;
use Reactor\Attributes\Group;
use Reactor\Attributes\OnUpdate;
use Reactor\Attributes\Text;

#[Group('private')]
#[Text(name: '/start', isCommand: true, priority: 100)]
#[OnUpdate('message')]
class StartHandler extends BaseHandler
{
    public function __construct(
        // ... existing dependencies ...
        private ReferralManagerInterface $referral,
    ) {
        parent::__construct(/* ... */);
    }

    protected function handle(array $params = []): void
    {
        $userId = $this->getUserId();
        if ($userId !== null) {
            $this->referral->confirm($userId, 'start_handler');
        }

        // ... send the welcome message ...
    }
}
```

By the time `StartHandler` runs, `ForceJoinMiddleware` has already passed (or blocked) the update, so a successful call to `confirm()` means the user is fully cleared.

If you have several gates and want confirmation to happen only after the user has been around for a while, register a listener instead and confirm from there:

```php
$dispatcher->on(Events::JOIN_PASSED, function (int $userId) use ($referral) {
    $referral->confirm($userId, 'force_join_passed');
});
```

---

## Events

Subscribe with the framework's `EventDispatcher`.

```php
use AfazTech\ReactorReferral\Events;
use Reactor\Core\EventDispatcher;

$dispatcher = $container->get(EventDispatcher::class);

$dispatcher->on(Events::REFERRAL_CAPTURED,
    function (int $referredId, int $referrerId, string $code) {
        // The user just arrived through a referral link.
        // Status is still 'pending'; do not grant a reward yet.
    });

$dispatcher->on(Events::REFERRAL_CONFIRMED,
    function (int $referredId, int $referrerId, string $code) {
        // Grant the reward here.
    });
```

| Event | Arguments | Fires when |
| --- | --- | --- |
| `REFERRAL_CAPTURED` | `(int $referredId, int $referrerId, string $code)` | A `/start ref_XXX` payload is resolved and the referral row is linked. |
| `REFERRAL_CONFIRMED` | `(int $referredId, int $referrerId, string $code)` | `ReferralManager::confirm()` promotes a pending row. |

A complete reward example:

```php
$dispatcher->on(Events::REFERRAL_CONFIRMED,
    function (int $referredId, int $referrerId, string $code) use ($client, $db) {
        // Give the referrer 10 points.
        $db->table('users')->where('user_id', $referrerId)->increment('points', 10);

        // Notify them.
        $client->sendMessage(
            $referrerId,
            "🎉 Someone joined through your link! You earned 10 points."
        )->await();
    });
```

Nothing in the referral package changes if you later decide to grant premium days, unlock a feature, or send a sticker pack instead. You just rewrite the listener.

---

## Self-referral guard

The only anti-fraud check the package performs: a user cannot use their own code.

When `ReferralCaptureMiddleware` resolves a `/start ref_XXX` payload, it compares the code's owner to the incoming user. On a match, the update is dropped silently and no row is written. No other heuristics — IP checks, device fingerprints, signup velocity — are applied. If you need them, add them in your own `EventDispatcher` listener on `REFERRAL_CAPTURED` before confirming.

---

## Database schema

A single table.

### `referrals`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint, PK | |
| `user_id` | bigint, unique | Telegram user ID. One row per user for their entire lifetime. |
| `code` | varchar(32), unique, nullable | Generated lazily the first time the user opens their invite page. |
| `invited_by` | bigint, nullable | `user_id` of whoever invited this user. `null` when the user arrived without a referral link. |
| `status` | varchar(16) | `pending` or `confirmed`. |
| `confirmed_at` | timestamp, nullable | Set when `status` becomes `confirmed`. |
| `created_at`, `updated_at` | timestamps | |
| — | index | `(invited_by, status)` |
| — | index | `(status, created_at)` |

The unique `user_id` constraint means a user's row carries both their outbound code (as referrer) and their inbound attribution (as referred). Duplicate invites cannot overwrite an existing attribution: once `invited_by` is set, `ReferralCaptureMiddleware` ignores further `/start ref_XXX` payloads from the same user.

---

## Middleware priority

| Class | Priority | Update types |
| --- | --- | --- |
| `ReferralCaptureMiddleware` | 60 | `message` |

Priority `60` places the middleware above every other middleware the framework's default chain registers (`SyncUserMiddleware` at `50`, `ForceJoinMiddleware` at `30`), so the referral row exists before any other package reads the user. The middleware always returns `false`, so it never short-circuits the pipeline.

---

## Handlers

The package ships one handler, auto-discovered at boot.

### `MyReferralLinkHandler`

Responds to `/myinvite` and `/ref`. Sends the caller their invite link, a summary of confirmed/pending/total referrals, and an inline "Share" button that opens Telegram's native share sheet pre-filled with the link.

Every string is resolved through the language manager. Add the keys listed in [Language keys](#language-keys) to every `lang/*.json` file.

---

## Testing locally

To develop against a local checkout, add a path repository in your application's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "../reactor-referral",
        "options": { "symlink": true }
    }
],
"require": {
    "afaztech/reactor-referral": "@dev"
}
```

The framework must also be a path repository or a tagged release of `^0.5.0`. When the framework is checked out locally, add a `branch-alias` to its `composer.json` so `dev-main` is treated as `0.5.x-dev`:

```json
"extra": {
    "branch-alias": {
        "dev-main": "0.5.x-dev"
    }
}
```

---

## License

MIT. See [LICENSE](LICENSE)