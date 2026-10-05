<?php

namespace App\Providers;

use App\Support\DirectRoutes;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Octane's route table, with the two endpoints that carry a post id added (see DirectRoutes).
        $this->app->singleton('octane', DirectRoutes::class);
    }
}
