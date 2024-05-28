<?php

namespace Tests\TestClasses;

use Livewire\Component;

class DummyComponent extends Component
{
    public string $email = '';

    public function save(): void
    {
        $this->validate(['email' => 'required']);
    }

    public function render(): string
    {
        return '<div>Dummy</div>';
    }
}
