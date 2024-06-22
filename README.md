# Validation errors card for Laravel Pulse

A card for Laravel Pulse to show validation errors impacting users.

<p align="center"><img src="https://raw.githubusercontent.com/timacdonald/pulse-validation-errors/main/art/screenshot.png" alt="Validation errors card for Laravel Pulse"></p>

## Installation

First, install the package via composer:

```sh
composer require timacdonald/pulse-validation-errors
```

Next, add the recorder to your `config/pulse.php`:

```php

return [
    // ...

    'recorders' => [
        TiMacDonald\Pulse\Recorders\ValidationErrors::class => [
            'enabled' => env('PULSE_VALIDATION_ERRORS_ENABLED', true),
            'sample_rate' => env('PULSE_VALIDATION_ERRORS_SAMPLE_RATE', 1),
            'capture_messages' => true,
            'ignore' => [
                // '#^/login$#',
                // '#^/register$#',
                // '#^/forgot-password$#',
            ],
            'groups' => [
                // '#^/products/.*$#' => '/products/{user}',
            ],
        ],

        // ...
    ],
];
```

> [!Warning]
> Make sure to configure the `sample_rate` for your application. This card may capture a lot of data if you have a lot of users hitting validation errors.

Next, add the card to your `resources/views/vendor/pulse/dashboard.php`:

```blade
<x-pulse>
    <livewire:pulse.validation-errors cols="8" rows="4" />

    <!-- ... -->
</x-pulse>
```

Finally, get to improving your user experience. At LaraconUS I gave a [talk on how much our validation sucks](https://youtu.be/MMc2TzBY6l4?si=UEu8dLuRK4XT30yK). If you are here, you likely also care about how your users experience validation errors on your app, so I'd love you to give it a watch.


## Features

- Supports multiple error bags
- Supports session based validation errors
- Supports API validation errors
- Support Inertia validation errors
- Support Livewire validation errors
- Fallback for undetectable validation errors (based on 422 response status)
- Capture generic validation exceptions for custom response types

## Ignore specific error messages

You may ignore specific endpoints via the recorders `ignore` key, however in some situations you may need more complex ignore rules. You can use [Pulse's built in `Pulse::filter` method](https://laravel.com/docs/11.x/pulse#filtering) to achieve this.

Here is an example where we are ignore a specific error message:

```php
use Laravel\Pulse\Entry;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Value;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Pulse::filter(fn ($entry): bool => match ($entry->type) {
        'validation_error' => ! Str::contains($entry->key, [
            'The password is incorrect.',
            'Your password has appeared in a data leak.',
            // ...
        ]),
        // ...
        default => true,
    });
}
```

## Capture validation errors for custom response formats

If you are returning custom response formats, you may see `__laravel_unknown` in the dashboard instead of the input names and error messages. This is because the package parses the response body to determine the validation errors. When the body is in an unrecognised format it is unable to parse the keys and messages from the response.

You should instead dispatch the `ValidationExceptionOccurred` event to pass the validation messages to the card's recorder. You may do this wherever you are converting your exceptions into responses. This usually happens in the `app/Exceptions/Handler`:

```php
<?php

namespace App\Exceptions\Handler;

use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Laravel\Pulse\Facades\Pulse;
use Throwable;
use TiMacDonald\Pulse\ValidationExceptionOccurred

class Handler
{
    // ...

    public function render($request, Throwable $e)
    {
        if ($e instanceof ValidationException) {
            Pulse::rescue(fn () => Event::dispatch(new ValidationExceptionOccurred($request, $e)));
        }

        // custom exception rendering logic...
    }
}
```
