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
 * subsystems so watson:list-entrypoints and watson:blastradius have something
 * real to talk to.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new \Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
            new \Watson\Symfony\WatsonBundle(),
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
        ]);

        // watson commands — autowire pulls RouterInterface;
        // %kernel.project_dir% for the scalar arg.
        $container->services()
            ->set(\Watson\Symfony\Console\ListEntrypointsCommand::class)
            ->autowire()
            ->autoconfigure()
            ->arg('$projectDir', '%kernel.project_dir%')
            ->tag('console.command');

        $container->services()
            ->set(\Watson\Symfony\Console\BlastradiusCommand::class)
            ->autowire()
            ->autoconfigure()
            ->arg('$projectDir', '%kernel.project_dir%')
            ->tag('console.command');

        // Fixture user-side command — proves watson picks up app:ping in
        // its list-entrypoints output via Application::all().
        $container->services()
            ->set(\App\Command\PingCommand::class)
            ->autoconfigure()
            ->tag('console.command');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // Attribute routes from src/Controller.
        $routes->import(__DIR__ . '/Controller', 'attribute');
    }
}
