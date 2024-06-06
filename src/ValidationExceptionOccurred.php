<?php

namespace TiMacDonald\Pulse;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ValidationExceptionOccurred
{
    public function __construct(
        public Request $request,
        public ValidationException $exception,
    ) {
        // ...
    }
}
