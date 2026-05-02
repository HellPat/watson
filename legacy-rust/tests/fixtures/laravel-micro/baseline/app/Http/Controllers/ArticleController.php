<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Pinger;

final class ArticleController
{
    public function __construct(private readonly Pinger $pinger)
    {
    }

    public function index(): string
    {
        return $this->pinger->ping('articles.index');
    }

    public function store(): string
    {
        return $this->pinger->ping('articles.store');
    }
}
