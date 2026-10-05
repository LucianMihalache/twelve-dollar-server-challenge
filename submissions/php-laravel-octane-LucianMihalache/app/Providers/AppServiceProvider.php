<?php

namespace App\Providers;

use App\Support\DirectRoutes;
use App\Support\Profile;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Octane's route table, with the two endpoints that carry a post id added (see DirectRoutes).
        $this->app->singleton('octane', DirectRoutes::class);
    }

    public function boot(): void
    {
        // The per-request stopwatch: does nothing unless the server was started with FEED_PROFILE=1.
        Profile::register();
    }
}
