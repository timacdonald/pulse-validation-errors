<?php

namespace TiMacDonald\Pulse\Recorders;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Laravel\Pulse\Concerns\ConfiguresAfterResolving;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Recorders\Concerns\Groups;
use Laravel\Pulse\Recorders\Concerns\Ignores;
use Laravel\Pulse\Recorders\Concerns\LivewireRoutes;
use Laravel\Pulse\Recorders\Concerns\Sampling;
use Livewire\Component;
use Throwable;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Livewire\Livewire;
use Livewire\LivewireManager;
use TiMacDonald\Pulse\ValidationExceptionOccurred;

/**
 * @internal
 */
class ValidationErrors
{
    use Groups,
        Ignores,
        Sampling,
        LivewireRoutes,
        ConfiguresAfterResolving;

    /**
     * The events to listen for.
     *
     * @var list<class-string>
     */
    public array $listen = [
        RequestHandled::class,
        ValidationExceptionOccurred::class,
    ];

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected Pulse $pulse,
        protected Repository $config,
    ) {
        //
    }

    public function register(callable $record, Application $app): void
    {
        $this->afterResolving($app, 'livewire', function (LivewireManager $livewire) use ($record, $app) {
            $livewire->listen('exception', function (Component $component, Throwable $exception) use ($record, $app) {
                if (! $exception instanceof ValidationException) {
                    return;
                }

                with($app['request'], function (Request $request) use ($record, $exception) {
                    // Livewire can reuse the same request instance when polling or
                    // performing grouped requests.
                    $request->attributes->remove('pulse_validation_messages_recorded');

                    $record(new ValidationExceptionOccurred($request, $exception));
                });
            });
        });
    }

    /**
     * Record validation errors.
     */
    public function record(ValidationExceptionOccurred|RequestHandled $event): void
    {
        if (
            $event->request->route() === null ||
            ! $this->shouldSample()
        ) {
            return;
        }

        $this->pulse->lazy(function () use ($event) {
            if ($event->request->attributes->has('pulse_validation_messages_recorded')) {
                return;
            }

            [$path, $via] = $this->resolveRoutePath($event->request);

            if ($this->shouldIgnore($path)) {
                return;
            }

            $event->request->attributes->set('pulse_validation_messages_recorded', true);

            $path = $this->group($path);

            $this->parseValidationErrors($event)->each(fn ($values) => $this->pulse->record(
                'validation_error',
                json_encode([$event->request->method(), $path, $via, ...$values], flags: JSON_THROW_ON_ERROR),
            )->count());
        });
    }

    /**
     * Parse validation errors.
     *
     * @return \Illuminate\Support\Collection<int, array{ 0: string, 1: string }>
     */
    protected function parseValidationErrors(ValidationExceptionOccurred|RequestHandled $event): Collection
    {
        if ($event instanceof ValidationExceptionOccurred) {
            return $this->parseValidationExceptionMessages($event->request, $event->exception);
        }

        return $this->parseSessionValidationErrors($event->request, $event->response)
            ?? $this->parseJsonValidationErrors($event->request, $event->response)
            ?? $this->parseUnknownValidationErrors($event->request, $event->response)
            ?? collect([]);
    }

    /**
     * Parse session validation errors.
     *
     * @return null|\Illuminate\Support\Collection<int, array{ 0: string, 1: string }>
     */
    protected function parseSessionValidationErrors(Request $request, SymfonyResponse $response): ?Collection
    {
        if (
            ! $request->hasSession() ||
            ! in_array($response->getStatusCode(), [302, 303]) ||
            ! ($errors = $request->session()->get('errors', null)) instanceof ViewErrorBag
        ) {
            return null;
        }

        if ($this->shouldCaptureMessages()) {
            return collect($errors->getBags())
                ->flatMap(fn ($bag, $bagName) => collect($bag->messages())
                    ->flatMap(fn ($messages, $inputName) => array_map(
                        fn ($message) => [$bagName, $inputName, $message], $messages)
                    ));
        }

        return collect($errors->getBags())->flatMap(
            fn ($bag, $bagName) => array_map(fn ($inputName) => [$bagName, $inputName], $bag->keys())
        );
    }

    /**
     * Parse validation exception errors.
     *
     * @return null|\Illuminate\Support\Collection<int, array{ 0: string, 1: string }>
     */
    protected function parseValidationExceptionMessages(Request $request, ValidationException $exception): ?Collection
    {
        if ($this->shouldCaptureMessages()) {
            return collect($exception->validator->errors())
                // Livewire is adding all the errors in a "list" merged in with
                // the expected validation errors. We will reject any of those
                // with "list" keys and just maintain those with input name
                // keys.
                ->reject(fn ($value, $key) => ! is_string($key))
                ->flatMap(fn ($messages, $inputName) => array_map(
                    fn ($message) => [$exception->errorBag, $inputName, $message], $messages)
                );
        }

        return collect($exception->validator->errors()->keys())
            ->map(fn ($inputName) => [$exception->errorBag, $inputName]);
    }

    /**
     * Parse JSON validation errors.
     *
     * @return null|\Illuminate\Support\Collection<int, array{ 0: string, 1: string }>
     */
    protected function parseJsonValidationErrors(Request $request, SymfonyResponse $response): ?Collection
    {
        if (
            $response->getStatusCode() !== 422 ||
            ! $response instanceof JsonResponse ||
            ! is_array($response->original) ||
            ! array_key_exists('errors', $response->original) ||
            ! is_array($response->original['errors']) ||
            array_is_list($errors = $response->original['errors'])
        ) {
            return null;
        }

        if ($this->shouldCaptureMessages()) {
            return collect($errors)->flatMap(fn ($messages, $inputName) => array_map(
                fn ($message) => ['default', $inputName, $message], $messages)
            );
        }

        return collect($errors)->keys()->map(fn ($inputName) => ['default', $inputName]);
    }

    /**
     * Parse unknown validation errors.
     *
     * @return null|\Illuminate\Support\Collection<int, array{ 0: string, 1: string }>
     */
    protected function parseUnknownValidationErrors(Request $request, SymfonyResponse $response): ?Collection
    {
        if ($response->getStatusCode() !== 422) {
            return null;
        }

        return collect([[
            'default',
            '__laravel_unknown',
            ...($this->shouldCaptureMessages() ? ['__laravel_unknown'] : [])
        ]]);
    }

    /**
     * Determine if the card should capture messages.
     */
    protected function shouldCaptureMessages(): bool
    {
        return $this->config->get('pulse.recorders.'.static::class.'.capture_messages', true);
    }
}
