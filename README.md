# Reactor Referral

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Reactor](https://img.shields.io/badge/Reactor-Framework-orange.svg)](https://github.com/afaztech/reactor)

Referral tracking for [Reactor](https://github.com/afaztech/reactor) Telegram bots.

## Features

- Deep-link referral attribution
- Lazy referral code generation per user
- Manual referral confirmation
- Referral statistics per user
- Configurable code length and prefix
- Self-referral prevention
- Custom referral code generator

## Requirements

- `afaztech/reactor-framework` ^0.5.0

## Installation

```bash
composer require afaztech/reactor-referral
./reactor migrate
```

## Usage

```php
use AfazTech\ReactorReferral\Contracts\ReferralManagerInterface;

$referral = $container->get(ReferralManagerInterface::class);

// Get a user's invite link.
$link = $referral->getLink($userId);

// Read their referral statistics.
$stats = $referral->stats($userId);

// Confirm a referral once the reward conditions are met.
$referral->confirm($referredUserId, 'force_join_passed');
```

## Documentation

For detailed documentation, see [DOCUMENT.md](DOCUMENT.md).

## Contributing

Contributions are welcome. Please read the contribution guidelines.

## License

This project is licensed under the [MIT License](LICENSE).