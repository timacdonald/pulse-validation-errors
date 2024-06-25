<?php

namespace Tests;

use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Middleware as InertiaMiddleware;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse as SymfonyJsonResponse;
use Tests\TestClasses\DummyComponent;
use Throwable;
use TiMacDonald\Pulse\Recorders\ValidationErrors;
use TiMacDonald\Pulse\ValidationExceptionOccurred;

class RecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Pulse::handleExceptionsUsing(fn (Throwable $e) => throw $e);

        Config::set([
            'pulse.ingest.trim.lottery' => [1, 1],
            'pulse.recorders.'.ValidationErrors::class => [],
        ]);
    }

    protected function tearDown(): void
    {
        if (Pulse::wantsIngesting()) {
            throw new RuntimeException('There are pending entries.');
        }

        parent::tearDown();
    }

    public function test_it_captures_validation_errors_from_the_session()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Route::post('users', fn () => Request::validate([
            'email' => 'required',
        ]))->middleware('web');

        $response = $this->post('users');

        $response->assertStatus(302);
        $response->assertInvalid('email');
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users","Closure","default","email"]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users","Closure","default","email"]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_captures_validation_errors_from_the_session_with_dedicated_bags()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Route::post('users', fn () => Request::validateWithBag('foo', [
            'email' => 'required',
        ]))->middleware('web');

        $response = $this->post('users');

        $response->assertStatus(302);
        $response->assertInvalid('email', 'foo');
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users","Closure","foo","email"]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users","Closure","foo","email"]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_captures_validation_errors_from_the_session_with_multiple_bags()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Route::post('users', function () {
            return Redirect::back()->withErrors(['email' => 'The email field is required.'])
                ->withErrors(['email' => 'The email field is required.'], 'custom_1')
                ->withErrors(['email' => 'The email field is required.'], 'custom_2');
        })->middleware('web');

        $response = $this->post('users');

        $response->assertStatus(302);
        $response->assertInvalid('email');
        $response->assertInvalid('email', 'custom_1');
        $response->assertInvalid('email', 'custom_2');
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(3, $entries);
        $this->assertSame('["POST","\/users","Closure","default","email"]', $entries[0]->key);
        $this->assertSame('["POST","\/users","Closure","custom_1","email"]', $entries[1]->key);
        $this->assertSame('["POST","\/users","Closure","custom_2","email"]', $entries[2]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame([
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
        ], $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 12, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_captures_validation_error_keys_from_livewire_components()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Livewire::component('dummy', DummyComponent::class);

        Str::createRandomStringsUsing(fn () => 'random-string');
        Livewire::test(DummyComponent::class)
            ->call('save')
            ->assertHasErrors('email');

        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/livewire-unit-test-endpoint\/random-string","via \/livewire\/update","default","email"]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/livewire-unit-test-endpoint\/random-string","via \/livewire\/update","default","email"]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_ignore_livewire_exceptions_that_are_not_validation_exceptions()
    {
        Livewire::component('dummy', DummyComponent::class);

        try {
            Livewire::test(DummyComponent::class)->call('throw');
        } catch (RuntimeException $e) {
            $this->assertSame('Whoops!', $e->getMessage());
        }

        $count = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->count());
        $this->assertSame(0, $count);
        $count = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->count());
        $this->assertSame(0, $count);
    }

    public function test_it_captures_validation_error_messages_from_livewire_components()
    {
        Livewire::component('dummy', DummyComponent::class);

        Str::createRandomStringsUsing(fn () => 'random-string');
        Livewire::test(DummyComponent::class)
            ->call('save')
            ->assertHasErrors('email');

        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/livewire-unit-test-endpoint\/random-string","via \/livewire\/update","default","email","The email field is required."]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/livewire-unit-test-endpoint\/random-string","via \/livewire\/update","default","email","The email field is required."]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_does_not_capture_validation_errors_from_redirects_when_there_is_no_session()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Route::post('users', fn () => Request::validate([
            'email' => 'required',
        ]));

        $response = $this->post('users');

        $response->assertStatus(302);
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(0, $entries);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertCount(0, $aggregates);
    }

    public function test_it_does_not_capture_validation_errors_from_redirects_when_the_errors_key_is_not_a_ViewErrorBag_with_session()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Route::post('users', fn () => redirect()->back()->with('errors', 'Something happened!'))->middleware('web');

        $response = $this->post('users');

        $response->assertStatus(302);
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(0, $entries);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertCount(0, $aggregates);
    }

    public function test_it_captures_one_entry_for_a_field_when_multiple_errors_are_present_for_the_given_field_from_the_session()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Route::post('users', fn () => Request::validate([
            'email' => 'string|min:5',
        ]))->middleware('web');

        $response = $this->post('users', [
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
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users","Closure","default","email"]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users","Closure","default","email"]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_captures_a_generic_error_when_it_is_unable_to_parse_the_validation_error_fields_from_the_session()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Route::post('users', fn () => response('<p>An error occurred.</p>', 422))->middleware('web');

        $response = $this->post('users');

        $response->assertStatus(422);
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users","Closure","default","__laravel_unknown"]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users","Closure","default","__laravel_unknown"]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_captures_API_validation_errors()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Route::post('users', fn () => Request::validate([
            'email' => 'required',
        ]))->middleware('api');

        $response = $this->postJson('users');

        $response->assertStatus(422);
        $response->assertInvalid('email');
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users","Closure","default","email"]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users","Closure","default","email"]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_captures_unknown_API_validation_error_for_non_Illuminate_Json_responses()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Route::post('users', fn () => new SymfonyJsonResponse(['errors' => ['email' => 'Is required.']], 422))
            ->middleware('api');

        $response = $this->postJson('users');

        $response->assertStatus(422);
        $response->assertInvalid('email');
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users","Closure","default","__laravel_unknown"]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users","Closure","default","__laravel_unknown"]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_captures_unknown_API_validation_error_for_non_array_Json_content()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Route::post('users', fn () => new IlluminateJsonResponse('An error occurred.', 422))
            ->middleware('api');

        $response = $this->postJson('users');

        $response->assertStatus(422);
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users","Closure","default","__laravel_unknown"]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users","Closure","default","__laravel_unknown"]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_captures_unknown_API_validation_error_for_array_content_mising_errors_key()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Route::post('users', fn () => new IlluminateJsonResponse(['An error occurred.'], 422))
            ->middleware('api');

        $response = $this->postJson('users');

        $response->assertStatus(422);
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users","Closure","default","__laravel_unknown"]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users","Closure","default","__laravel_unknown"]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_captures_unknown_API_validation_error_for_errors_key_that_does_not_contain_an_array()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Route::post('users', fn () => new IlluminateJsonResponse(['errors' => 'An error occurred.'], 422))
            ->middleware('api');

        $response = $this->postJson('users');

        $response->assertStatus(422);
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users","Closure","default","__laravel_unknown"]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users","Closure","default","__laravel_unknown"]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_captures_unknown_API_validation_error_for_errors_key_that_contains_a_list()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Route::post('users', fn () => new IlluminateJsonResponse(['errors' => ['An error occurred.']], 422))
            ->middleware('api');

        $response = $this->postJson('users');

        $response->assertStatus(422);
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users","Closure","default","__laravel_unknown"]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users","Closure","default","__laravel_unknown"]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_captures_inertia_validation_errors()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Route::post('users', fn () => Request::validate([
            'email' => 'required',
        ]))->middleware(['web', InertiaMiddleware::class]);

        $response = $this->post('users', [], ['X-Inertia' => '1']);

        $response->assertStatus(302);
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users","Closure","default","email"]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users","Closure","default","email"]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_captures_inertia_validation_non_post_errors()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Route::put('users/{user}', fn () => Request::validate([
            'email' => 'required',
        ]))->middleware(['web', InertiaMiddleware::class]);

        $response = $this->put('users/5', [], ['X-Inertia' => '1']);

        $response->assertStatus(303);
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["PUT","\/users\/{user}","Closure","default","email"]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["PUT","\/users\/{user}","Closure","default","email"]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_captures_inertia_validation_errors_with_multiple_bags()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', false);
        Route::post('users', function () {
            return Redirect::back()->withErrors(['email' => 'The email field is required.'])
                ->withErrors(['email' => 'The email field is required.'], 'custom_1')
                ->withErrors(['email' => 'The email field is required.'], 'custom_2');
        })->middleware(['web', InertiaMiddleware::class]);

        $response = $this->post('users', [], ['X-Inertia' => '1']);

        $response->assertStatus(302);
        $response->assertInvalid('email');
        $response->assertInvalid('email', 'custom_1');
        $response->assertInvalid('email', 'custom_2');
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(3, $entries);
        $this->assertSame('["POST","\/users","Closure","default","email"]', $entries[0]->key);
        $this->assertSame('["POST","\/users","Closure","custom_1","email"]', $entries[1]->key);
        $this->assertSame('["POST","\/users","Closure","custom_2","email"]', $entries[2]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame([
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
        ], $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 12, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_can_capture_messages_for_session_based_validation_errors()
    {
        Route::post('users', fn () => Request::validate([
            'email' => 'required',
        ]))->middleware('web');

        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', true);
        $response = $this->post('users');

        $response->assertStatus(302);
        $response->assertInvalid('email');
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users","Closure","default","email","The email field is required."]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users","Closure","default","email","The email field is required."]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_can_capture_messages_for_API_based_validation_errors()
    {
        Route::post('users', fn () => Request::validate([
            'email' => 'required',
        ]))->middleware('api');

        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', true);
        $response = $this->postJson('users');

        $response->assertStatus(422);
        $response->assertInvalid('email');
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users","Closure","default","email","The email field is required."]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users","Closure","default","email","The email field is required."]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_can_capture_messages_for_inertia_based_validation_errors()
    {
        Route::post('users', fn () => Request::validate([
            'email' => 'required',
        ]))->middleware(['web', InertiaMiddleware::class]);

        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', true);
        $response = $this->post('users', [], ['X-Inertia' => '1']);

        $response->assertStatus(302);
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users","Closure","default","email","The email field is required."]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users","Closure","default","email","The email field is required."]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_can_capture_message_for_inertia_based_validation_errors_for_mutliple_bags()
    {
        Route::post('users', function () {
            return Redirect::back()->withErrors(['email' => 'The email field is required.'])
                ->withErrors(['email' => 'The email field is required.'], 'custom_1')
                ->withErrors(['email' => 'The email field is required.'], 'custom_2');
        })->middleware(['web', InertiaMiddleware::class]);

        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', true);
        $response = $this->post('users', [], ['X-Inertia' => '1']);

        $response->assertStatus(302);
        $response->assertInvalid('email');
        $response->assertInvalid('email', 'custom_1');
        $response->assertInvalid('email', 'custom_2');
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertSame('["POST","\/users","Closure","default","email","The email field is required."]', $entries[0]->key);
        $this->assertSame('["POST","\/users","Closure","custom_1","email","The email field is required."]', $entries[1]->key);
        $this->assertSame('["POST","\/users","Closure","custom_2","email","The email field is required."]', $entries[2]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame([
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
        ], $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 12, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_can_capture_messages_for_generic_validation_errors()
    {
        Route::post('users', fn () => response('<p>An error occurred.</p>', 422))->middleware('web');

        Config::set('pulse.recorders.'.ValidationErrors::class.'.capture_messages', true);
        $response = $this->post('users');

        $response->assertStatus(422);
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users","Closure","default","__laravel_unknown","__laravel_unknown"]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users","Closure","default","__laravel_unknown","__laravel_unknown"]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_ignores_unknown_routes()
    {
        $this->get('unknown-route')->assertNotFound();
    }

    public function test_it_can_sample()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class.'.sample_rate', 0.1);
        Route::post('users', fn () => Request::validate([
            'email' => 'required',
        ]))->middleware('web');

        $this->post('users');
        $this->post('users');
        $this->post('users');
        $this->post('users');
        $this->post('users');
        $this->post('users');
        $this->post('users');
        $this->post('users');
        $this->post('users');
        $this->post('users');

        $count = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->count());
        $this->assertEqualsWithDelta(1, $count, 4);
    }

    public function test_it_can_group_URLs()
    {
        Config::set('pulse.recorders.'.ValidationErrors::class, [
            'sample_rate' => 1,
            'groups' => [
                '#^/users/.*$#' => '/users/{user}',
            ],
        ]);

        Route::post('users/timacdonald', fn () => Request::validate([
            'email' => 'required',
        ]))->middleware('web');

        $response = $this->post('users/timacdonald');

        $response->assertStatus(302);
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users\/{user}","Closure","default","email","The email field is required."]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users\/{user}","Closure","default","email","The email field is required."]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_can_ignore_entries_based_on_the_error_message()
    {
        Route::post('users', fn () => Request::validate([
            'name' => 'required',
            'email' => 'required',
        ]))->middleware('web');

        Pulse::filter(fn ($entry) => match ($entry->type) {
            'validation_error' => ! Str::contains($entry->key, [
                '"The email field is required."',
            ]),
            // ...
        });

        $response = $this->post('users');

        $response->assertStatus(302);
        $response->assertInvalid(['name', 'email']);
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(1, $entries);
        $this->assertSame('["POST","\/users","Closure","default","name","The name field is required."]', $entries[0]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame(array_fill(0, 4, '["POST","\/users","Closure","default","name","The name field is required."]'), $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 4, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }

    public function test_it_captures_validation_errors_from_custom_event()
    {
        Route::post('users', function () {
            try {
                Request::validate([
                    'name' => 'required',
                    'email' => 'required',
                ]);
            } catch (ValidationException $e) {
                Event::dispatch(new ValidationExceptionOccurred(request(), $e));

                throw $e;
            }
        })->middleware('web');

        $response = $this->post('users');

        $response->assertStatus(302);
        $response->assertInvalid(['name', 'email']);
        $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->where('type', 'validation_error')->get());
        $this->assertCount(2, $entries);
        $this->assertSame('["POST","\/users","Closure","default","name","The name field is required."]', $entries[0]->key);
        $this->assertSame('["POST","\/users","Closure","default","email","The email field is required."]', $entries[1]->key);
        $aggregates = Pulse::ignore(fn () => DB::table('pulse_aggregates')->where('type', 'validation_error')->orderBy('period')->get());
        $this->assertSame([
            '["POST","\/users","Closure","default","name","The name field is required."]',
            '["POST","\/users","Closure","default","email","The email field is required."]',
            '["POST","\/users","Closure","default","name","The name field is required."]',
            '["POST","\/users","Closure","default","email","The email field is required."]',
            '["POST","\/users","Closure","default","name","The name field is required."]',
            '["POST","\/users","Closure","default","email","The email field is required."]',
            '["POST","\/users","Closure","default","name","The name field is required."]',
            '["POST","\/users","Closure","default","email","The email field is required."]',
        ], $aggregates->pluck('key')->all());
        $this->assertSame(array_fill(0, 8, 'count'), $aggregates->pluck('aggregate')->all());
        $this->assertTrue($aggregates->pluck('value')->every(fn ($value) => $value == 1.0));
    }
}
