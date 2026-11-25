<?php

namespace App\Jobs;

use App\Services\Ia\ClasificadorDeTweets;
use App\Services\TweetsEnVivo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Le pasa un tweet del muro en vivo a la IA del FabLab y deja el resultado en
 * el muro. En la cola, de a uno: la sala entera publica a la vez.
 */
class ClasificarTweetEnVivo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public string $sala, public string $id) {}

    public function handle(TweetsEnVivo $tweets, ClasificadorDeTweets $ia): void
    {
        $t = $tweets->tweet($this->sala, $this->id);
        if (! $t || $t['estado'] !== 'pensando') {
            return;
        }

        try {
            $tweets->resultado($this->sala, $this->id, $ia->clasificar($t['texto']));
        } catch (\Throwable $e) {
            Log::warning('Tweet en vivo: ' . $e->getMessage());
            $tweets->resultado($this->sala, $this->id, null);
        }
    }
}
