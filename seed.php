<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Laravel\Pulse\Facades\Pulse;

Artisan::command('seed', function () {
    $count = 841;
    for ($i = 0; $i < $count; $i++) {
        Pulse::record(
            type: 'validation_error',
            key: json_encode([
                'POST',
                '/user',
                'App\Http\Controllers\UserController@store',
                'default',
                'password',
                'The password field must be at least 8 characters.'
            ], flags: JSON_THROW_ON_ERROR),
        )->count();
    }
    $count = 622;
    for ($i = 0; $i < $count; $i++) {
        Pulse::record(
            type: 'validation_error',
            key: json_encode([
                'PATCH',
                '/episodes',
                'App\Http\Controllers\EpisodeController@update',
                'default',
                'version',
                'The version field is required.'
            ], flags: JSON_THROW_ON_ERROR),
        )->count();
    }

    $count = 588;
    for ($i = 0; $i < $count; $i++) {
        Pulse::record(
            type: 'validation_error',
            key: json_encode([
                'POST',
                '/shows',
                'App\Http\Controllers\ShowController@store',
                'default',
                'website',
                'The website field must start with one of the following: http://, https://.'
            ], flags: JSON_THROW_ON_ERROR),
        )->count();
    }

    $count = 582;
    for ($i = 0; $i < $count; $i++) {
        Pulse::record(
            type: 'validation_error',
            key: json_encode([
                'POST',
                '/episodes',
                'App\Http\Controllers\EpisodeController@store',
                'default',
                'version',
                'The version field is required.'
            ], flags: JSON_THROW_ON_ERROR),
        )->count();
    }

    $count = 288;
    for ($i = 0; $i < $count; $i++) {
        Pulse::record(
            type: 'validation_error',
            key: json_encode([
                'DELETE',
                '/episodes',
                'App\Http\Controllers\EpisodeController@destroy',
                'default',
                'title_confirmation',
                'The title confirmation does not match the episode title.',
            ], flags: JSON_THROW_ON_ERROR),
        )->count();
    }

    $count = 205;
    for ($i = 0; $i < $count; $i++) {
        Pulse::record(
            type: 'validation_error',
            key: json_encode([
                'POST',
                '/episodes',
                'App\Http\Controllers\EpisodeController@store',
                'default',
                'custom_feed_url',
                'The custom feed url field must be a valid URL.',
            ], flags: JSON_THROW_ON_ERROR),
        )->count();
    }

    $count = 107;
    for ($i = 0; $i < $count; $i++) {
        Pulse::record(
            type: 'validation_error',
            key: json_encode([
                'PATCH',
                '/users',
                'App\Http\Controllers\UserController@update',
                'default',
                'password',
                'The given password has appeared in a data leak. Please choose a different password.',
            ], flags: JSON_THROW_ON_ERROR),
        )->count();
    }

    Pulse::ingest();
});

