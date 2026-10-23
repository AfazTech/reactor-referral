<?php

namespace AfazTech\ReactorReferral;

use AfazTech\ReactorReferral\Contracts\ReferralManagerInterface;
use AfazTech\ReactorReferral\Support\CodeGenerator;
use AfazTech\ReactorReferral\Support\ReferralConfig;
use Reactor\Contracts\ServiceProviderInterface;
use Reactor\Core\Container;

/**
 * Registers referral package services in the container.
 *
 * Config binding is provided as an empty default. The host application
 * overrides ReferralConfig in its own service provider to customise
 * code length and prefix.
 *
 * This package is pure API: it ships no handler, no middleware, no
 * language files and no automatic hooks. It exposes only:
 *
 *   - ReferralManagerInterface (code generation, deep-link composition,
 *     attribution lookup, statistics, confirmation)
 *   - Referral model, migration and Events constants
 *
 * How attribution is captured is up to the host application. The
 * standard approach is a small middleware listening to /start with a
 * payload, or a check inside the /start handler itself. Either way,
 * when the app decides a referral is legitimate, it calls
 * ReferralManager::confirm() which fires Events::REFERRAL_CONFIRMED.
 */
class ReferralServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        if (!$container->has(ReferralConfig::class)) {
            $container->singleton(ReferralConfig::class, function () {
                return new ReferralConfig();
            });
        }

        if (!$container->has(CodeGenerator::class)) {
            $container->singleton(CodeGenerator::class, function () {
                return new CodeGenerator();
            });
        }

        if (!$container->has(ReferralManagerInterface::class)) {
            $container->singleton(ReferralManagerInterface::class, function (Container $c) {
                return new ReferralManager(
                    $c->get(\Neili\Client::class),
                    $c->get(ReferralConfig::class),
                    $c->get(\Reactor\Core\EventDispatcher::class),
                    $c->get(\Reactor\Contracts\LoggerInterface::class),
                    $c->get(CodeGenerator::class),
                );
            });
        }
    }

    public function boot(Container $container): void
    {
        // Nothing to boot: this package registers no middleware and no
        // handlers. Attribution and confirmation are the host
        // application's responsibility.
    }
}
