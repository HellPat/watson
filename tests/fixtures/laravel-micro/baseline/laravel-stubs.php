<?php

declare(strict_types=1);

// Minimal stubs of the Laravel surface watson's fixture exercises.
// Real laravel/framework stays out of the fixture so tests are hermetic;
// this file gives mago-analyzer enough type information to resolve calls.

namespace Illuminate\Console {
    class Command
    {
        public const SUCCESS = 0;
        public const FAILURE = 1;
        public const INVALID = 2;

        protected $signature = '';
        protected $description = '';

        public function __construct()
        {
        }
    }
}

namespace Illuminate\Contracts\Queue {
    interface ShouldQueue
    {
    }

    interface ShouldQueueAfterCommit extends ShouldQueue
    {
    }
}

namespace Illuminate\Support\Facades {
    class Route
    {
        public static function get(string $uri, mixed $action = null): self
        {
            return new self();
        }
        public static function post(string $uri, mixed $action = null): self
        {
            return new self();
        }
        public static function put(string $uri, mixed $action = null): self
        {
            return new self();
        }
        public static function patch(string $uri, mixed $action = null): self
        {
            return new self();
        }
        public static function delete(string $uri, mixed $action = null): self
        {
            return new self();
        }
        public static function options(string $uri, mixed $action = null): self
        {
            return new self();
        }
        public static function any(string $uri, mixed $action = null): self
        {
            return new self();
        }
        public static function match(array $methods, string $uri, mixed $action = null): self
        {
            return new self();
        }
        public static function resource(string $name, string $controller): self
        {
            return new self();
        }
        public static function apiResource(string $name, string $controller): self
        {
            return new self();
        }
        public static function singleton(string $name, string $controller): self
        {
            return new self();
        }
        public static function apiSingleton(string $name, string $controller): self
        {
            return new self();
        }
        public static function redirect(string $from, string $to): self
        {
            return new self();
        }
        public static function permanentRedirect(string $from, string $to): self
        {
            return new self();
        }
        public static function view(string $path, string $view): self
        {
            return new self();
        }
        public static function middleware(mixed $middleware): self
        {
            return new self();
        }
        public function prefix(string $prefix): self
        {
            return $this;
        }
        public function group(\Closure $callback): self
        {
            return $this;
        }
        public function name(string $name): self
        {
            return $this;
        }
    }

    class Artisan
    {
        public static function command(string $signature, \Closure $callback): mixed
        {
            return null;
        }
    }

    class Schedule
    {
        public static function command(string $command): self
        {
            return new self();
        }
        public static function job(object $job): self
        {
            return new self();
        }
        public static function call(\Closure $callback): self
        {
            return new self();
        }
        public function daily(): self
        {
            return $this;
        }
        public function dailyAt(string $time): self
        {
            return $this;
        }
        public function hourly(): self
        {
            return $this;
        }
        public function everyMinute(): self
        {
            return $this;
        }
    }
}
