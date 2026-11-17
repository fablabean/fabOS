<?php

namespace Tests\Feature;

use Tests\TestCase;

/** La presentacion del laboratorio se abre sin sesion, desde un enlace compartido. */
class ConoceElFablabTest extends TestCase
{
    public function test_se_abre_sin_sesion(): void
    {
        $this->get('/conoce-el-fablab')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('Todo empieza', false)
            ->assertSee('Edificio Fundadores · piso 3', false);
    }
}
