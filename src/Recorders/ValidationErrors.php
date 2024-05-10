<?php

namespace TiMacDonald\Pulse\Recorders;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\ViewErrorBag;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Recorders\Concerns\Ignores;
use Laravel\Pulse\Recorders\Concerns\LivewireRoutes;
use Laravel\Pulse\Recorders\Concerns\Sampling;
use stdClass;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * @internal
 */
class ValidationErrors
{
    use Ignores, LivewireRoutes, Sampling;

    /**
     * The events to listen for.
     *
     * @var list<class-string>
     */
    public array $listen = [
        RequestHandled::class,
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

    /**
     * Record validation errors.
     */
    public function record(RequestHandled $event): void
    {
        [$request, $response] = [
            $event->request,
            $event->response,
        ];

        $this->pulse->lazy(function () use ($request, $response) {
            if (! $this->shouldSample()) {
                return;
            }

            [$path, $via] = $this->resolveRoutePath($request);

            if ($this->shouldIgnore($path)) {
                return;
            }

            $this->parseValidationErrors($request, $response)->each(fn ($values) => $this->pulse->record(
                'validation_error',
                json_encode([$request->method(), $path, $via, ...$values], flags: JSON_THROW_ON_ERROR),
            )->count());
        });
    }

    /**
     * Parse validation errors.
     *
     * @return \Illuminate\Support\Collection<int, array{ 0: string, 1: string }>
     */
    protected function parseValidationErrors(Request $request, SymfonyResponse $response): Collection
    {
        return $this->parseSessionValidationErrors($request, $response)
            ?? $this->parseJsonValidationErrors($request, $response)
            ?? $this->parseInertiaValidationErrors($request, $response)
            ?? $this->parseUnknownValidationErrors($request, $response)
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
            $response->getStatusCode() !== 302 ||
            ! $request->hasSession() ||
            ! ($errors = $request->session()->get('errors', null)) instanceof ViewErrorBag
        ) {
            return null;
        }

        return collect($errors->getBags())->flatMap(
            fn ($bag, $bagName) => array_map(fn ($inputName) => [$bagName, $inputName], $bag->keys())
        );
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

        return collect($errors)->keys()->map(fn ($inputName) => ['default', $inputName]);
    }

    /**
     * Parse Inertia validation errors.
     *
     * @return null|\Illuminate\Support\Collection<int, array{ 0: string, 1: string }>
     */
    protected function parseInertiaValidationErrors(Request $request, SymfonyResponse $response): ?Collection
    {
        if (
            $request->isMethodSafe() ||
            ! $request->header('X-Inertia') ||
            ! $response instanceof JsonResponse ||
            ! is_array($response->original) ||
            ! array_key_exists('props', $response->original) ||
            ! is_array($response->original['props']) ||
            ! array_key_exists('errors', $response->original['props']) ||
            ! ($errors = $response->original['props']['errors']) instanceof stdClass
        ) {
            return null;
        }

        if (is_string(($errors = collect($errors))->first())) {
            return $errors->keys()->map(fn ($inputName) => ['default', $inputName]);
        }

        return $errors->flatMap(
            fn ($bag, $bagName) => collect($bag)->keys()->map(fn ($inputName) => [$bagName, $inputName])
        );
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

        return collect([['default', '__laravel_unknown']]);
    }
}
