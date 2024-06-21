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
        [$errors, $time, $runAt] = $this->remember(
            fn () => Pulse::aggregate(
                'validation_error',
                ['count'],
                $this->periodAsInterval(),
            )->map(function (object $row) { // @phpstan-ignore argument.type
                /** @var object{ key: string, count: int }  $row */
                [$method, $uri, $action, $bag, $name, $message] = json_decode($row->key, flags: JSON_THROW_ON_ERROR) + [5 => null];

                return (object) [
                    'bag' => match ($bag) {
                        'default' => null,
                        default => $bag,
                    },
                    'uri' => $uri,
                    'name' => $name,
                    'action' => $action,
                    'method' => $method,
                    'message' => $message,
                    'count' => $row->count,
                    'key_hash' => md5($row->key),
                ];
            }));

        return View::make('timacdonald::validation-errors', [
            'time' => $time,
            'runAt' => $runAt,
            'errors' => $errors,
            'config' => [
                'enabled' => true,
                'sample_rate' => 1,
                'capture_messages' => true,
                'ignore' => [],
                ...Config::get('pulse.recorders.'.ValidationErrorsRecorder::class, []), // @phpstan-ignore arrayUnpacking.nonIterable
            ],
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
