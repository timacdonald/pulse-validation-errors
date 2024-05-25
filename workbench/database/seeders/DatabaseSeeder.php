<?php

namespace Workbench\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Laravel\Pulse\Facades\Pulse;
use Orchestra\Testbench\Factories\UserFactory;

class DatabaseSeeder extends Seeder
{
    protected array $errors = [
        [
            'POST',
            '/user',
            'App\Http\Controllers\UserController@store',
            'default',
            'password',
            'The password field must be at least 8 characters.'
        ],
        [
            'PATCH',
            '/episodes',
            'App\Http\Controllers\EpisodeController@update',
            'default',
            'version',
            'The version field is required.'
        ],
        [
            'POST',
            '/shows',
            'App\Http\Controllers\ShowController@store',
            'default',
            'website',
            'The website field must start with one of the following: http://, https://.'
        ],
        [
            'POST',
            '/episodes',
            'App\Http\Controllers\EpisodeController@store',
            'default',
            'version',
            'The version field is required.'
        ],
        [
            'POST',
            '/episodes',
            'App\Http\Controllers\EpisodeController@store',
            'default',
            'version',
            'The version field is required.'
        ],
        [
            'DELETE',
            '/episodes',
            'App\Http\Controllers\EpisodeController@destroy',
            'default',
            'title_confirmation',
            'The title confirmation does not match the episode title.',
        ],
        [
            'POST',
            '/episodes',
            'App\Http\Controllers\EpisodeController@store',
            'default',
            'custom_feed_url',
            'The custom feed url field must be a valid URL.',
        ],
        [
            'PATCH',
            '/users',
            'App\Http\Controllers\UserController@update',
            'default',
            'password',
            'The given password has appeared in a data leak. Please choose a different password.',
        ],
    ];

    protected array $featureErrors = [
        // No error message...
        [
            'POST',
            '/users',
            'App\Http\Controllers\UserController@store',
            'default',
            'email',
        ],
        // Custom error bag, no message...
        [
            'POST',
            '/users',
            'App\Http\Controllers\UserController@store',
            'named_error_bag',
            'email',
        ],
        // Custom error bag, with message...
        [
            'POST',
            '/users',
            'App\Http\Controllers\UserController@store',
            'named_error_bag',
            'email',
            'The email field is required.',
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $start = 100;
        shuffle($this->errors);
        $now = CarbonImmutable::now();

        foreach ($this->errors as $error) {
            $start = rand($start, $start + 175);

            for ($i = 0; $i < $start; $i++) {
                Pulse::record(
                    type: 'validation_error',
                    timestamp: $now,
                    key: json_encode($error, flags: JSON_THROW_ON_ERROR),
                )->count();
            }

            foreach ([2, 7, 25] as $hours) {
                $lt = rand($start - 175, $start);

                for ($i = 0; $i < $lt; $i++) {
                    Pulse::record(
                        type: 'validation_error',
                        key: json_encode($error, flags: JSON_THROW_ON_ERROR),
                        timestamp: $now->subHours($hours),
                    )->count();
                }
            }
        }

        foreach ($this->featureErrors as $error) {
            Pulse::record(
                type: 'validation_error',
                key: json_encode($error, flags: JSON_THROW_ON_ERROR),
            )->count();
        }

        Pulse::ingest();
    }
}
