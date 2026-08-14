<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A raiz serve o painel Filament; sem autenticação, redireciona para o login.
     */
    public function test_the_application_serves_the_panel_at_the_root(): void
    {
        $this->get('/')->assertRedirect(route('filament.admin.auth.login'));
    }
}
