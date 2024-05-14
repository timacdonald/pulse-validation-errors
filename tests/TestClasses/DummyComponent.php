<?php

namespace Tests\TestClasses;

use Livewire\Component;

class DummyComponent extends Component
{
    public string $name = '';

    public function save()
    {
        $this->validate(['name' => 'required']);
    }

    public function render()
    {
        return "<div>Dummy</div>";
    }
}
