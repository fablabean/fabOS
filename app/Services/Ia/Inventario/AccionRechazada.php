<?php

namespace App\Services\Ia\Inventario;

use RuntimeException;

/**
 * El inventario no acepta lo que se quería guardar, y por qué.
 *
 * El mensaje se le enseña tal cual a la persona: tiene que decir qué hay que
 * corregir, no qué falló por dentro.
 */
class AccionRechazada extends RuntimeException {}
