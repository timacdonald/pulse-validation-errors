<?php

use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Middleware as InertiaMiddleware;
use Laravel\Pulse\Facades\Pulse;
use Symfony\Component\HttpFoundation\JsonResponse as SymfonyJsonResponse;
use TiMacDonald\Pulse\Recorders\ValidationErrors;

use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Config::set('pulse.ingest.trim.lottery', [1, 1]);
    Pulse::handleExceptionsUsing(fn (Throwable $e) => throw $e);
    Pulse::register([ValidationErrors::class => []]);
    Config::set('pulse.recorders.'.ValidationErrors::class, [
        'sample_rate' => 1,
    ]);
});

afterEach(function () {
    if (Pulse::wantsIngesting()) {
        throw new RuntimeException('There are pending entries.');
    }
});

it('captures validation errors from the session', function () {
    Route::post('users', fn () => Request::validate([
        'email' => 'required',
    ]))->middleware('web');

    $response = post('users');

    $response->assertStatus(302);
    $response->assertInvalid('email');
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(1);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","email"]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe(array_fill(0, 4, '["POST","\/users","Closure","default","email"]'));
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 4, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('captures validation errors from the session with dedicated bags', function () {
    Route::post('users', fn () => Request::validateWithBag('foo', [
        'email' => 'required',
    ]))->middleware('web');

    $response = post('users');

    $response->assertStatus(302);
    $response->assertInvalid('email', 'foo');
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(1);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","foo","email"]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe(array_fill(0, 4, '["POST","\/users","Closure","foo","email"]'));
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 4, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('captures validation errors from the session with multiple bags', function () {
    Route::post('users', function () {
        return Redirect::back()->withErrors(['email' => 'The email field is required.'])
            ->withErrors(['email' => 'The email field is required.'], 'custom_1')
            ->withErrors(['email' => 'The email field is required.'], 'custom_2');
    })->middleware('web');

    $response = post('users');

    $response->assertStatus(302);
    $response->assertInvalid('email');
    $response->assertInvalid('email', 'custom_1');
    $response->assertInvalid('email', 'custom_2');
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(3);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","email"]');
    expect($entries[1]->key)->toBe('["POST","\/users","Closure","custom_1","email"]');
    expect($entries[2]->key)->toBe('["POST","\/users","Closure","custom_2","email"]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe([
        '["POST","\/users","Closure","default","email"]',
        '["POST","\/users","Closure","custom_1","email"]',
        '["POST","\/users","Closure","custom_2","email"]',
        '["POST","\/users","Closure","default","email"]',
        '["POST","\/users","Closure","custom_1","email"]',
        '["POST","\/users","Closure","custom_2","email"]',
        '["POST","\/users","Closure","default","email"]',
        '["POST","\/users","Closure","custom_1","email"]',
        '["POST","\/users","Closure","custom_2","email"]',
        '["POST","\/users","Closure","default","email"]',
        '["POST","\/users","Closure","custom_1","email"]',
        '["POST","\/users","Closure","custom_2","email"]',
    ]);
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 12, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('does not capture validation errors from redirects when there is no session', function () {
    Route::post('users', fn () => Request::validate([
        'email' => 'required',
    ]));

    $response = post('users');

    $response->assertStatus(302);
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(0);
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates)->toHaveCount(0);
});

it('does not capture validation errors from redirects when the "errors" key is not a ViewErrorBag with session', function () {
    Route::post('users', fn () => redirect()->back()->with('errors', 'Something happened!'))->middleware('web');

    $response = post('users');

    $response->assertStatus(302);
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(0);
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates)->toHaveCount(0);
});

it('captures one entry for a field when multiple errors are present for the given field from the session', function () {
    Route::post('users', fn () => Request::validate([
        'email' => 'string|min:5',
    ]))->middleware('web');

    $response = post('users', [
        'email' => 4,
    ]);

    $response->assertStatus(302);
    $response->assertInvalid([
        'email' => [
            'The email field must be a string.',
            'The email field must be at least 5 characters.',
        ],
    ]);
    $response->assertInvalid(['email' => 'The email field must be at least 5 characters.']);
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(1);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","email"]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe(array_fill(0, 4, '["POST","\/users","Closure","default","email"]'));
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 4, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('captures a generic error when it is unable to parse the validation error fields from the session', function () {
    Route::post('users', fn () => response('<p>An error occurred.</p>', 422))->middleware('web');

    $response = post('users');

    $response->assertStatus(422);
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(1);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","__laravel_unknown"]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe(array_fill(0, 4, '["POST","\/users","Closure","default","__laravel_unknown"]'));
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 4, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('captures API validation errors', function () {
    Route::post('users', fn () => Request::validate([
        'email' => 'required',
    ]))->middleware('api');

    $response = postJson('users');

    $response->assertStatus(422);
    $response->assertInvalid('email');
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(1);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","email"]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe(array_fill(0, 4, '["POST","\/users","Closure","default","email"]'));
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 4, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('captures "unknown" API validation error for non Illuminate Json responses', function () {
    Route::post('users', fn () => new SymfonyJsonResponse(['errors' => ['email' => 'Is required.']], 422))
        ->middleware('api');

    $response = postJson('users');

    $response->assertStatus(422);
    $response->assertInvalid('email');
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(1);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","__laravel_unknown"]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe(array_fill(0, 4, '["POST","\/users","Closure","default","__laravel_unknown"]'));
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 4, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('captures "unknown" API validation error for non array Json content', function () {
    Route::post('users', fn () => new IlluminateJsonResponse('An error occurred.', 422))
        ->middleware('api');

    $response = postJson('users');

    $response->assertStatus(422);
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(1);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","__laravel_unknown"]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe(array_fill(0, 4, '["POST","\/users","Closure","default","__laravel_unknown"]'));
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 4, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('captures "unknown" API validation error for array content mising "errors" key', function () {
    Route::post('users', fn () => new IlluminateJsonResponse(['An error occurred.'], 422))
        ->middleware('api');

    $response = postJson('users');

    $response->assertStatus(422);
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(1);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","__laravel_unknown"]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe(array_fill(0, 4, '["POST","\/users","Closure","default","__laravel_unknown"]'));
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 4, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('captures "unknown" API validation error for "errors" key that does not contain an array', function () {
    Route::post('users', fn () => new IlluminateJsonResponse(['errors' => 'An error occurred.'], 422))
        ->middleware('api');

    $response = postJson('users');

    $response->assertStatus(422);
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(1);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","__laravel_unknown"]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe(array_fill(0, 4, '["POST","\/users","Closure","default","__laravel_unknown"]'));
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 4, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('captures "unknown" API validation error for "errors" key that contains a list', function () {
    Route::post('users', fn () => new IlluminateJsonResponse(['errors' => ['An error occurred.']], 422))
        ->middleware('api');

    $response = postJson('users');

    $response->assertStatus(422);
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(1);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","__laravel_unknown"]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe(array_fill(0, 4, '["POST","\/users","Closure","default","__laravel_unknown"]'));
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 4, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('captures inertia validation errors', function () {
    Route::post('users', fn () => Request::validate([
        'email' => 'required',
    ]))->middleware(['web', InertiaMiddleware::class]);

    $response = post('users', [], ['X-Inertia' => '1']);

    $response->assertStatus(302);
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(1);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","email"]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe(array_fill(0, 4, '["POST","\/users","Closure","default","email"]'));
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 4, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('captures inertia validation errors with multiple bags', function () {
    Route::post('users', function () {
        return Redirect::back()->withErrors(['email' => 'The email field is required.'])
            ->withErrors(['email' => 'The email field is required.'], 'custom_1')
            ->withErrors(['email' => 'The email field is required.'], 'custom_2');
    })->middleware(['web', InertiaMiddleware::class]);

    $response = post('users', [], ['X-Inertia' => '1']);

    $response->assertStatus(302);
    $response->assertInvalid('email');
    $response->assertInvalid('email', 'custom_1');
    $response->assertInvalid('email', 'custom_2');
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(3);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","email"]');
    expect($entries[1]->key)->toBe('["POST","\/users","Closure","custom_1","email"]');
    expect($entries[2]->key)->toBe('["POST","\/users","Closure","custom_2","email"]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe([
        '["POST","\/users","Closure","default","email"]',
        '["POST","\/users","Closure","custom_1","email"]',
        '["POST","\/users","Closure","custom_2","email"]',
        '["POST","\/users","Closure","default","email"]',
        '["POST","\/users","Closure","custom_1","email"]',
        '["POST","\/users","Closure","custom_2","email"]',
        '["POST","\/users","Closure","default","email"]',
        '["POST","\/users","Closure","custom_1","email"]',
        '["POST","\/users","Closure","custom_2","email"]',
        '["POST","\/users","Closure","default","email"]',
        '["POST","\/users","Closure","custom_1","email"]',
        '["POST","\/users","Closure","custom_2","email"]',
    ]);
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 12, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('can capture messages for session based validation errors', function () {
    Route::post('users', fn () => Request::validate([
        'email' => 'required',
    ]))->middleware('web');

    Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', true);
    $response = post('users');

    $response->assertStatus(302);
    $response->assertInvalid('email');
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(1);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","email","The email field is required."]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe(array_fill(0, 4, '["POST","\/users","Closure","default","email","The email field is required."]'));
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 4, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('can capture messages for API based validation errors', function () {
    Route::post('users', fn () => Request::validate([
        'email' => 'required',
    ]))->middleware('api');

    Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', true);
    $response = postJson('users');

    $response->assertStatus(422);
    $response->assertInvalid('email');
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(1);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","email","The email field is required."]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe(array_fill(0, 4, '["POST","\/users","Closure","default","email","The email field is required."]'));
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 4, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('can capture messages for inertia based validation errors', function () {
    Route::post('users', fn () => Request::validate([
        'email' => 'required',
    ]))->middleware(['web', InertiaMiddleware::class]);

    Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', true);
    $response = post('users', [], ['X-Inertia' => '1']);

    $response->assertStatus(302);
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(1);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","email","The email field is required."]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe(array_fill(0, 4, '["POST","\/users","Closure","default","email","The email field is required."]'));
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 4, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('can capture message for inertia based validation errors for mutliple bags', function () {
    Route::post('users', function () {
        return Redirect::back()->withErrors(['email' => 'The email field is required.'])
            ->withErrors(['email' => 'The email field is required.'], 'custom_1')
            ->withErrors(['email' => 'The email field is required.'], 'custom_2');
    })->middleware(['web', InertiaMiddleware::class]);

    Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', true);
    $response = post('users', [], ['X-Inertia' => '1']);

    $response->assertStatus(302);
    $response->assertInvalid('email');
    $response->assertInvalid('email', 'custom_1');
    $response->assertInvalid('email', 'custom_2');
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","email","The email field is required."]');
    expect($entries[1]->key)->toBe('["POST","\/users","Closure","custom_1","email","The email field is required."]');
    expect($entries[2]->key)->toBe('["POST","\/users","Closure","custom_2","email","The email field is required."]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe([
        '["POST","\/users","Closure","default","email","The email field is required."]',
        '["POST","\/users","Closure","custom_1","email","The email field is required."]',
        '["POST","\/users","Closure","custom_2","email","The email field is required."]',
        '["POST","\/users","Closure","default","email","The email field is required."]',
        '["POST","\/users","Closure","custom_1","email","The email field is required."]',
        '["POST","\/users","Closure","custom_2","email","The email field is required."]',
        '["POST","\/users","Closure","default","email","The email field is required."]',
        '["POST","\/users","Closure","custom_1","email","The email field is required."]',
        '["POST","\/users","Closure","custom_2","email","The email field is required."]',
        '["POST","\/users","Closure","default","email","The email field is required."]',
        '["POST","\/users","Closure","custom_1","email","The email field is required."]',
        '["POST","\/users","Closure","custom_2","email","The email field is required."]',
    ]);
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 12, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});

it('can capture messages for generic validation errors', function () {
    Route::post('users', fn () => response('<p>An error occurred.</p>', 422))->middleware('web');

    Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', true);
    $response = post('users');

    $response->assertStatus(422);
    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->whereType('validation_error')->get());
    expect($entries)->toHaveCount(1);
    expect($entries[0]->key)->toBe('["POST","\/users","Closure","default","__laravel_unknown","__laravel_unknown"]');
    $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->whereType('validation_error')->orderBy('period')->get());
    expect($aggregates->pluck('key')->all())->toBe(array_fill(0, 4, '["POST","\/users","Closure","default","__laravel_unknown","__laravel_unknown"]'));
    expect($aggregates->pluck('aggregate')->all())->toBe(array_fill(0, 4, 'count'));
    expect($aggregates->pluck('value')->every(fn ($value) => $value == 1.0))->toBe(true);
});
