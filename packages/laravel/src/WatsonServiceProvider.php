<?php

declare(strict_types=1);

namespace Watson\Laravel;

use Illuminate\Support\ServiceProvider;
use Watson\Laravel\Console\ListEntrypointsCommand;

/**
 * Auto-registered via `extra.laravel.providers` in composer.json. Hooks the
 * watson Artisan commands into any Laravel project that has us in
 * `composer require --dev`. No manual `config/app.php` edit needed.
 */
final class WatsonServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ListEntrypointsCommand::class,
            ]);
        }
    }
}
