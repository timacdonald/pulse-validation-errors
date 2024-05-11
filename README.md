# Validation errors card for Laravel Pulse

A card for Laravel Pulse to show validation errors impacting users.

<p align="center"><img src="https://raw.githubusercontent.com/timacdonald/pulse-validation-errors/main/art/screenshot.jpg" alt="Validation errors card for Laravel Pulse"></p>

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
            'capture_messages' => false,
            'ignore' => [
                // '#^/login$#',
                // '#^/register$#',
                // '#^/forgot-password$#',
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
- Fallback for undetectable validation errors (based on 422 response status)
