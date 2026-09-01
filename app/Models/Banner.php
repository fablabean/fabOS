<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Una lamina del banner de la portada (§3, portal publico).
 *
 * Lo primero que ve quien llega sin conocer el laboratorio. Se administra
 * desde el panel porque anunciar una feria o una convocatoria es trabajo de
 * quien comunica, no de quien despliega: cuando cambiar una frase cuesta un
 * despliegue, la portada se queda contando lo del semestre pasado.
 *
 * Tres decisiones que estan en los campos y conviene no perder:
 *
 *  · **La vigencia.** Una lamina puede nacer con fecha de caducidad. Lo que
 *    anuncia un evento se apaga solo el dia que el evento pasa; nadie tiene
 *    que acordarse, y esa es la unica razon por la que funciona.
 *  · **El velo.** Cuanto se oscurece el fondo. Una foto clara con letra clara
 *    encima no se lee, y eso no puede depender de la foto que suban.
 *  · **El resaltado con asteriscos.** El titulo se escribe en una caja de
 *    texto: `*asi*` se resalta. Antes iba `<em>` a mano en un fichero de
 *    configuracion, que se podia porque solo lo tocaba quien programa.
 */
class Banner extends Model
{
    protected $table = 'banners';

    protected $fillable = [
        'position', 'is_active',
        'rotulo', 'titulo', 'texto',
        'fondo_tipo', 'fondo_color', 'fondo_path', 'poster_path', 'fondo_pos', 'velo',
        'efecto', 'alineacion',
        'accion_texto', 'accion_url', 'accion2_texto', 'accion2_url',
        'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position'  => 'integer',
            'velo'      => 'integer',
            'starts_at' => UtcDateTime::class,
            'ends_at'   => UtcDateTime::class,
        ];
    }

    /** Como se pinta el fondo. */
    public const FONDOS = [
        'color'  => 'Color plano',
        'imagen' => 'Foto o ilustracion',
        'video'  => 'Video',
    ];

    /**
     * Como entra el titulo.
     *
     * Son efectos de ENTRADA, no bucles: algo que se mueve sin parar detras de
     * un texto que hay que leer cansa a los diez segundos. El unico que
     * insiste es el brillo, y solo sobre la palabra resaltada.
     */
    public const EFECTOS = [
        'ninguno'    => 'Sin efecto',
        'subir'      => 'Las palabras suben',
        'cortina'    => 'Cortina de color',
        'desenfoque' => 'Entra desenfocado',
        'maquina'    => 'Maquina de escribir',
        'brillo'     => 'Brillo en lo resaltado',
    ];

    public const ALINEACIONES = [
        'izquierda' => 'A la izquierda',
        'centro'    => 'Centrado',
    ];

    /**
     * Lo que se enseña ahora mismo: encendida y en fecha.
     *
     * Sin fechas, vale siempre. Con ellas, aparece y desaparece sola.
     */
    public function scopeVigente(Builder $q): Builder
    {
        $ahora = now();

        return $q->where('is_active', true)
            ->where(fn ($s) => $s->whereNull('starts_at')->orWhere('starts_at', '<=', $ahora))
            ->where(fn ($s) => $s->whereNull('ends_at')->orWhere('ends_at', '>=', $ahora));
    }

    /**
     * Las laminas de la portada, con red debajo.
     *
     * Si no hay ninguna vigente —porque todas caducaron el mismo dia, porque
     * alguien las apago todas, porque la instalacion es nueva— se cae de pie a
     * la configuracion. Una portada sin banner no es un fallo visible: es un
     * sitio que de pronto no dice que es.
     *
     * @return Collection<int,Banner>
     */
    public static function paraLaPortada(): Collection
    {
        $laminas = static::query()->vigente()->orderBy('position')->orderBy('id')->get();

        return $laminas->isNotEmpty() ? $laminas : static::deLaConfiguracion();
    }

    /**
     * Las de `config/fabos.php`, como modelos sin guardar.
     *
     * Sin guardar a proposito: son el valor por defecto del codigo, no una
     * decision del laboratorio. El dia que alguien edite el banner, lo suyo
     * pisa a esto y estas no estorban en la tabla.
     *
     * @return Collection<int,Banner>
     */
    public static function deLaConfiguracion(): Collection
    {
        return collect(config('fabos.hero', []))->map(fn (array $l) => new static([
            'rotulo'     => $l['rotulo'] ?? null,
            'titulo'     => preg_replace('/<em>(.*?)<\/em>/u', '*$1*', $l['titulo'] ?? ''),
            'texto'      => $l['texto'] ?? null,
            'fondo_tipo' => 'imagen',
            'fondo_path' => $l['imagen'] ?? null,
            'velo'       => 70,
            'efecto'     => 'subir',
            'alineacion' => 'izquierda',
        ]))->values();
    }

    public function esVideo(): bool
    {
        return $this->fondo_tipo === 'video' && filled($this->fondo_path);
    }

    public function fondoUrl(): ?string
    {
        return $this->urlDe($this->fondo_path);
    }

    public function posterUrl(): ?string
    {
        return $this->urlDe($this->poster_path);
    }

    /**
     * Las ilustraciones que vienen con fabOS estan en `public/img`; lo que se
     * sube desde el panel, en el disco publico. Se distinguen por la ruta y no
     * por una columna mas: la columna habria que explicarla en el formulario,
     * y a quien sube una foto no le importa donde acaba el fichero.
     */
    private function urlDe(?string $ruta): ?string
    {
        if (blank($ruta)) {
            return null;
        }

        return str_starts_with($ruta, 'img/')
            ? asset($ruta)
            : asset('storage/' . $ruta);
    }

    /**
     * El titulo, con el resaltado puesto.
     *
     * Se escapa PRIMERO y se marca despues: lo que se escribe en el editor es
     * texto, y un `<script>` escrito ahi tiene que salir en pantalla como
     * letras, no ejecutarse. Los asteriscos son la unica marca que se
     * interpreta.
     */
    public function tituloHtml(): string
    {
        return preg_replace('/\*(.+?)\*/u', '<em>$1</em>', e($this->titulo));
    }

    /**
     * Los botones de esta lamina, si los tiene.
     *
     * @return array<int,array{texto:string,url:string}>
     */
    public function acciones(): array
    {
        return collect([
            ['texto' => $this->accion_texto,  'url' => $this->accion_url],
            ['texto' => $this->accion2_texto, 'url' => $this->accion2_url],
        ])
            ->filter(fn (array $a) => filled($a['texto']) && filled($a['url']))
            ->values()
            ->all();
    }

    /** El rotulo, o la identidad del laboratorio si no lleva uno propio. */
    public function rotuloVisible(): string
    {
        return $this->rotulo
            ?: config('fabos.lab.institution') . ' · ' . config('fabos.lab.city');
    }
}
