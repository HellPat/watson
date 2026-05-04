<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Single-file Symfony kernel — minimum needed for the router + console
 * subsystems so watson (running as a standalone CLI) has something real
 * to introspect via `bin/console debug:router`.
 *
 * No watson bundle is registered — watson is purely outside-in now.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new \Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
        ];
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader): void
    {
        $container->extension('framework', [
            'secret' => 'fixture',
            'router' => ['utf8' => true],
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'test' => false,
            'messenger' => [
                'default_bus' => 'messenger.bus.default',
                'buses' => [
                    'messenger.bus.default' => null,
                ],
            ],
        ]);

        // Fixture user-side command — proves watson picks up app:ping in
        // its list-entrypoints output via Application::all().
        $container->services()
            ->set(\App\Command\PingCommand::class)
            ->autoconfigure()
            ->tag('console.command');

        // Fixture messenger handler — autoconfigure picks up the
        // `#[AsMessageHandler]` attribute and tags it for us.
        $container->services()
            ->set(\App\MessageHandler\PingHandler::class)
            ->autoconfigure();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // Attribute routes from src/Controller.
        $routes->import(__DIR__ . '/Controller', 'attribute');
    }
}
