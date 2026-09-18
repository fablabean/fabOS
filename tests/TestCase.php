<?php

namespace Tests;

use App\Support\Roles;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Los roles se leen una vez por peticion y se recuerdan en memoria; entre
     * una prueba y la siguiente la base se vacia pero la memoria no, y un rol
     * creado en una prueba apareceria en la siguiente.
     */
    protected function tearDown(): void
    {
        Roles::olvidar();

        parent::tearDown();
    }
}
