<?php

namespace App\Services\Iot;

use RuntimeException;

/** Algo que el dispositivo no deja hacer. El mensaje se muestra tal cual. */
class IotException extends RuntimeException
{
    public const YA_TIENE_CUENTA = 1;
}
