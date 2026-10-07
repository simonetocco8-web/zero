<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class DesignSystemTest extends TestCase
{
    public function test_component_variants_and_accessible_states_render(): void
    {
        view()->share('errors', new ViewErrorBag);
        $html = Blade::render(<<<'BLADE'
            <x-button>Primary</x-button><x-button variant="secondary">Secondary</x-button><x-button variant="danger">Danger</x-button>
            <x-card><x-badge variant="warning">In verifica</x-badge><x-alert variant="danger">Errore</x-alert></x-card>
            <x-loading-state /><x-empty-state title="Vuoto" description="Nessun elemento" />
            <x-modal name="test" title="Titolo finestra">Contenuto</x-modal><x-toast />
            <x-field name="test-input" label="Input" /><x-select name="test-select" label="Select"><option>Uno</option></x-select>
            <x-textarea name="test-textarea" label="Textarea" /><x-checkbox name="test-checkbox" label="Checkbox" />
            BLADE);

        foreach (['btn-primary', 'btn-secondary', 'btn-danger', 'role="alert"', 'role="status"', 'aria-labelledby="test-title"', 'for="test-input"', 'for="test-select"', 'for="test-textarea"', 'for="test-checkbox"'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }
}
