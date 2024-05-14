<?php

use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;
use TiMacDonald\Pulse\Cards\ValidationErrors;

it('renders', function () {
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
            expect($errors)->toHaveCount(1);

            expect($errors[0])->toEqual(literal(
                method: 'POST',
                uri: '/register',
                action: 'App\Http\Controllers\RegisterController@store',
                bag: null,
                name: 'email',
                message: null,
                count: 1,
                key_hash: md5(json_encode([
                    'POST',
                    '/register',
                    'App\Http\Controllers\RegisterController@store',
                    'default',
                    'email',
                ])),
            ));

            return true;
        });
});

it('optionally supports', function () {
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
            expect($errors)->toHaveCount(1);

            expect($errors[0])->toEqual(literal(
                method: 'POST',
                uri: '/register',
                action: 'App\Http\Controllers\RegisterController@store',
                bag: null,
                name: 'email',
                message: 'The email field is required.',
                count: 1,
                key_hash: md5(json_encode([
                    'POST',
                    '/register',
                    'App\Http\Controllers\RegisterController@store',
                    'default',
                    'email',
                    'The email field is required.',
                ])),
            ));

            return true;
        });
});

it('supports error bags', function () {
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

            expect($errors)->toHaveCount(1);

            expect($errors[0])->toEqual(literal(
                method: 'POST',
                uri: '/register',
                action: 'App\Http\Controllers\RegisterController@store',
                bag: 'custom_1',
                name: 'email',
                message: 'The email field is required.',
                count: 1,
                key_hash: md5(json_encode([
                    'POST',
                    '/register',
                    'App\Http\Controllers\RegisterController@store',
                    'custom_1',
                    'email',
                    'The email field is required.',
                ])),
            ));

            return true;
        });
});
