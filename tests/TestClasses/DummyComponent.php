<?php

namespace Tests\TestClasses;

use Livewire\Component;
use RuntimeException;

class DummyComponent extends Component
{
    public string $email = '';

    public function save(): void
    {
        $this->validate(['email' => 'required']);
    }

    public function throw(): void
    {
        throw new RuntimeException('Whoops!');
    }

    public function render(): string
    {
        return '<div>Dummy</div>';
    }
}
