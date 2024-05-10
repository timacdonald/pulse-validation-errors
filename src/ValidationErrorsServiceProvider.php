<?php

namespace TiMacDonald\Pulse;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Livewire\LivewireManager;
use TiMacDonald\Pulse\Cards\ValidationErrors;

/**
 * @internal
 */
class ValidationErrorsServiceProvider extends BaseServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'timacdonald');

        $this->callAfterResolving('livewire', function (LivewireManager $livewire) {
            $livewire->component('pulse.validation-errors', ValidationErrors::class);
        });
    }
}
