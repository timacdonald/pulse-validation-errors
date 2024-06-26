<?php

namespace Tests;

use Illuminate\Support\Facades\Config;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use TiMacDonald\Pulse\Cards\ValidationErrors;
use TiMacDonald\Pulse\Recorders\ValidationErrors as ValidationErrorsRecorder;

class CardTest extends TestCase
{
    public function test_it_renders()
    {
        Pulse::record(
            type: 'validation_error',
            key: json_encode([
                'POST',
                '/register',
                'App\Http\Controllers\RegisterController@store',
                'default',
                'email',
            ], flags: JSON_THROW_ON_ERROR),
        )->count();
        Pulse::ingest();

        Livewire::test(ValidationErrors::class, ['lazy' => false])
            ->assertViewHas('errors', function ($errors) {
                $this->assertCount(1, $errors);

                $this->assertEquals($errors[0], (object) [
                    'method' => 'POST',
                    'uri' => '/register',
                    'action' => 'App\Http\Controllers\RegisterController@store',
                    'bag' => null,
                    'name' => 'email',
                    'message' => null,
                    'count' => 1,
                    'key_hash' => md5('["POST","\/register","App\\\\Http\\\\Controllers\\\\RegisterController@store","default","email"]'),
                ]);

                return true;
            });
    }

    public function test_it_optionally_supports_messages()
    {
        Pulse::record(
            type: 'validation_error',
            key: json_encode([
                'POST',
                '/register',
                'App\Http\Controllers\RegisterController@store',
                'default',
                'email',
                'The email field is required.',
            ], flags: JSON_THROW_ON_ERROR),
        )->count();
        Pulse::ingest();

        Livewire::test(ValidationErrors::class, ['lazy' => false])
            ->assertViewHas('errors', function ($errors) {
                $this->assertCount(1, $errors);

                $this->assertEquals($errors[0], (object) [
                    'method' => 'POST',
                    'uri' => '/register',
                    'action' => 'App\Http\Controllers\RegisterController@store',
                    'bag' => null,
                    'name' => 'email',
                    'message' => 'The email field is required.',
                    'count' => 1,
                    'key_hash' => md5('["POST","\/register","App\\\\Http\\\\Controllers\\\\RegisterController@store","default","email","The email field is required."]'),
                ]);

                return true;
            });
    }

    public function test_it_supports_error_bags()
    {
        Pulse::record(
            type: 'validation_error',
            key: json_encode([
                'POST',
                '/register',
                'App\Http\Controllers\RegisterController@store',
                'custom_1',
                'email',
                'The email field is required.',
            ], flags: JSON_THROW_ON_ERROR),
        )->count();
        Pulse::ingest();

        Livewire::test(ValidationErrors::class, ['lazy' => false])
            ->assertViewHas('errors', function ($errors) {
                $this->assertCount(1, $errors);

                $this->assertEquals($errors[0], (object) [
                    'method' => 'POST',
                    'uri' => '/register',
                    'action' => 'App\Http\Controllers\RegisterController@store',
                    'bag' => 'custom_1',
                    'name' => 'email',
                    'message' => 'The email field is required.',
                    'count' => 1,
                    'key_hash' => md5('["POST","\/register","App\\\\Http\\\\Controllers\\\\RegisterController@store","custom_1","email","The email field is required."]'),
                ]);

                return true;
            });
    }

    public function test_it_shows_sample_rate_when_active()
    {
        for ($i = 0; $i < 99; $i++) {
            Pulse::record(
                type: 'validation_error',
                key: json_encode([
                    'POST',
                    '/register',
                    'App\Http\Controllers\RegisterController@store',
                    'custom_1',
                    'email',
                    'The email field is required.',
                ], flags: JSON_THROW_ON_ERROR),
            )->count();
        }
        Pulse::ingest();

        Livewire::test(ValidationErrors::class, ['lazy' => false])
            ->assertSee(' 99')
            ->assertDontSee('Sample rate');

        Config::set('pulse.recorders.'.ValidationErrorsRecorder::class.'.sample_rate', 0.3);

        Livewire::test(ValidationErrors::class, ['lazy' => false])
            ->assertSeeHtml('title="Sample rate: 0.3, Raw value: 99">~330');
    }

    public function test_it_provides_custom_css()
    {
        /** @var ValidationErrors */
        $card = app(ValidationErrors::class);

        $card->dehydrate();

        $this->assertStringContainsString('#validation-card', Pulse::css());
    }

    public function test_it_registers_the_card()
    {
        $registery = app(ComponentRegistry::class);

        $name = $registery->getName(ValidationErrors::class);

        $this->assertSame('pulse.validation-errors', $name);
    }
}
