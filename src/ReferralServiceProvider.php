<?php

namespace AfazTech\ReactorReferral;

use AfazTech\ReactorReferral\Contracts\ReferralManagerInterface;
use AfazTech\ReactorReferral\Support\CodeGenerator;
use AfazTech\ReactorReferral\Support\ReferralConfig;
use Reactor\Contracts\ServiceProviderInterface;
use Reactor\Core\Container;
use Reactor\Core\Router;
use Reactor\Enums\MiddlewareMode;

/**
 * Registers referral package services and wires the discovery of the
 * package's handlers and middleware.
 *
 * Config binding is provided as an empty default. The host application
 * overrides ReferralConfig in its own service provider to customise
 * code length and prefix.
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
        if ($container->has(Router::class)) {
            $router = $container->get(Router::class);
            $router->discover(__DIR__ . '/Handlers', 'AfazTech\\ReactorReferral\\Handlers');
        }

        $this->registerMiddleware($container);
    }

    private function registerMiddleware(Container $container): void
    {
        $existing = $container->has('middlewares') ? ($container->get('middlewares') ?? []) : [];

        $ours = [
            [
                'class'          => \AfazTech\ReactorReferral\Middleware\ReferralCaptureMiddleware::class,
                // Priority 60 places capture above SyncUserMiddleware (50)
                // and above the force-join middleware (30), so the
                // referral row exists before any other package observes
                // the user.
                'priority'       => 60,
                'mode'           => MiddlewareMode::GLOBAL,
                'groups'         => [],
                'allowedUpdates' => ['message'],
            ],
        ];

        $known = [];
        foreach ($existing as $row) {
            if (isset($row['class'])) {
                $known[$row['class']] = true;
            }
        }

        foreach ($ours as $row) {
            if (isset($known[$row['class']])) {
                continue;
            }
            $existing[] = $row;
        }

        usort($existing, function (array $a, array $b): int {
            return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
        });

        $container->forgetInstance('middlewares');
        $container->singleton('middlewares', fn () => $existing);
    }
}
