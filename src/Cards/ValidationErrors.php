<?php

namespace TiMacDonald\Pulse\Cards;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Livewire\Card;
use Laravel\Pulse\Livewire\Concerns\HasPeriod;
use Laravel\Pulse\Livewire\Concerns\RemembersQueries;
use Livewire\Attributes\Lazy;
use TiMacDonald\Pulse\Recorders\ValidationErrors as ValidationErrorsRecorder;

/**
 * @internal
 */
#[Lazy]
class ValidationErrors extends Card
{
    use HasPeriod, RemembersQueries;

    /**
     * Render the component.
     */
    public function render(): Renderable
    {
        [$validationErrors, $time, $runAt] = $this->remember(
            fn () => Pulse::aggregate(
                'validation_error',
                ['count'],
                $this->periodAsInterval(),
            )->map(function ($row) {
                [$method, $uri, $action, $bag, $name] = json_decode($row->key, flags: JSON_THROW_ON_ERROR);

                return (object) [
                    'bag' => $bag,
                    'uri' => $uri,
                    'name' => $name,
                    'action' => $action,
                    'method' => $method,
                    'count' => $row->count,
                ];
            }),
        );

        return View::make('timacdonald::validation-errors', [
            'time' => $time,
            'runAt' => $runAt,
            'validationErrors' => $validationErrors,
            'config' => Config::get('pulse.recorders.'.ValidationErrorsRecorder::class),
        ]);
    }

    /**
     * Define any CSS that should be loaded for the component.
     *
     * @return string|\Illuminate\Contracts\Support\Htmlable|array<int, string|\Illuminate\Contracts\Support\Htmlable>|null
     */
    protected function css()
    {
        return __DIR__.'/../../dist/validation.css';
    }
}
