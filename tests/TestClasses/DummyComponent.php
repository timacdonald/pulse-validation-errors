<?php

namespace Tests\TestClasses;

use Livewire\Component;

class DummyComponent extends Component
{
    public string $email = '';

    public function save()
    {
        $this->validate(['email' => 'required']);
    }

    public function render()
    {
        return '<div>Dummy</div>';
    }
}
