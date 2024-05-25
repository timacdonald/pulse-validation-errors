<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use TiMacDonald\Pulse\Recorders\ValidationErrors;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        config(['pulse.recorders' => [
            ValidationErrors::class => [
                'enabled' => env('PULSE_VALIDATION_ERRORS_ENABLED', true),
                'sample_rate' => env('PULSE_VALIDATION_ERRORS_SAMPLE_RATE', 1),
            ],
        ]]);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
