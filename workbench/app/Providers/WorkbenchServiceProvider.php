<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use TiMacDonald\Pulse\Recorders\ValidationErrors;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        Config::set([
            'pulse.recorders' => [
                ValidationErrors::class => [
                    // ...
                ],
            ],
        ]);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
