<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Booking\AsesoriaService;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Booking\EspacioBookingService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Crear una reserva desde el panel (§7, §10).
 *
 * El formulario de antes era el crudo de Filament: «reservable type»,
 * «reservable id», el costo en centavos, el estado a mano. Nadie sabía qué era
 * cada campo, y lo que se escribía ahí entraba a la base sin pasar por nada:
 * sin comprobar si la máquina estaba libre, si la persona tenía certifab, ni
 * cuánto costaba.
 *
 * Aquí se pregunta lo mismo que en el sitio público —para quién, qué, cuándo—
 * y se reserva por el MISMO servicio que usa la gente desde su teléfono. Si el
 * equipo está ocupado o la persona no está habilitada, se dice igual que
 * allí. Un panel que se salta las reglas es un panel que las vuelve inútiles.
 *
 * Tres cosas se reservan aquí: una asesoría —alguien acompaña—, un equipo por
 * cuenta propia, o un espacio. La producción NO: se programa desde el
 * proyecto, que es donde queda el costo y el material.
 */
class CreateReservation extends CreateRecord
{
    protected static string $resource = ReservationResource::class;

    protected static ?string $title = 'Nueva reserva';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Qué se reserva')
                ->columns(2)
                ->schema([
                    ToggleButtons::make('tipo')
                        ->label('Tipo')
                        ->options([
                            'asesoria'  => 'Asesoría',
                            'autonomia' => 'Equipo por su cuenta',
                            'espacio'   => 'Un espacio',
                        ])
                        // Los botones de Filament no llevan descripcion cada
                        // uno en esta version: va debajo, de una vez.
                        ->helperText('Asesoría: alguien del equipo acompaña, sin certifab. Por su cuenta: la persona usa la máquina sola y tiene que estar habilitada. La producción no va aquí: se programa desde el proyecto.')
                        ->default('asesoria')
                        ->inline()
                        ->required()
                        ->live()
                        ->columnSpanFull(),

                    Select::make('user_id')
                        ->label('Para quién')
                        ->options(fn () => User::where('status', 'activo')->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (User $u) => [$u->id => $u->name . ' · ' . $u->email]))
                        ->searchable()
                        ->required()
                        ->helperText('Se busca por nombre o correo. Si no está, se crea aquí mismo con el botón de al lado.')
                        /*
                         * Si la persona no existe, se crea sin salir de aqui.
                         *
                         * Llama alguien de fuera, se le toma la reserva, y no
                         * tiene cuenta: pedirle a quien atiende que vaya a
                         * Personas, la cree y vuelva es el paso en que la
                         * reserva se anota en un papel. Nace como invitada,
                         * sin permiso de reservar maquinas por su cuenta —una
                         * llamada no habilita a nadie—, y con el correo se le
                         * reutiliza la cuenta si ya la tenia: dos cuentas con
                         * el mismo correo parten su historial en dos.
                         */
                        ->createOptionForm([
                            TextInput::make('name')->label('Nombre')->required()->maxLength(120),
                            TextInput::make('email')->label('Correo')->email()->required()->maxLength(160),
                            TextInput::make('phone')->label('Teléfono')->tel()->maxLength(40),
                        ])
                        ->createOptionModalHeading('Nueva persona')
                        ->createOptionUsing(function (array $data): int {
                            $correo = mb_strtolower(trim($data['email']));

                            $persona = User::whereRaw('lower(email) = ?', [$correo])->first()
                                ?? User::create([
                                    'name'             => trim($data['name']),
                                    'email'            => $correo,
                                    'phone'            => $data['phone'] ?? null,
                                    'status'           => 'activo',
                                    'user_category_id' => UserCategory::where('slug', 'invitado')->value('id'),
                                ]);

                            return $persona->id;
                        }),

                    /*
                     * El equipo, con su área delante: «Impresión 3D · Prusa
                     * MK4» se encuentra escribiendo cualquiera de las dos.
                     */
                    Select::make('asset_id')
                        ->label('Qué equipo')
                        ->options(fn () => Asset::with('area')
                            ->where('is_reservable', true)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Asset $a) => [
                                $a->id => ($a->area?->name ? $a->area->name . ' · ' : '') . $a->name,
                            ]))
                        ->searchable()
                        ->required(fn ($get) => $get('tipo') === 'autonomia')
                        ->visible(fn ($get) => $get('tipo') === 'autonomia')
                        ->helperText('Solo los que se reservan. Si la máquina pide visto bueno, la reserva queda como solicitud.'),

                    /*
                     * Para la asesoría se elige SOBRE QUÉ: una máquina concreta
                     * o el área en general. El asesor lo elige el sistema por
                     * turno, igual que en el sitio: aquí nadie decide a dedo
                     * quién atiende.
                     */
                    Select::make('ambito')
                        ->label('Sobre qué')
                        ->options(function () {
                            $asesorias = app(AsesoriaService::class);

                            $equipos = Asset::with('area')->has('advisors')->orderBy('name')->get()
                                ->mapWithKeys(fn (Asset $a) => [
                                    'asset:' . $a->id => ($a->area?->name ? $a->area->name . ' · ' : '') . $a->name,
                                ]);

                            $areas = Area::orderBy('position')->get()
                                ->filter(fn (Area $a) => $asesorias->seAsesora($a))
                                ->mapWithKeys(fn (Area $a) => ['area:' . $a->id => 'General de ' . $a->name]);

                            return $areas->union($equipos)->all();
                        })
                        ->searchable()
                        ->required(fn ($get) => $get('tipo') === 'asesoria')
                        ->visible(fn ($get) => $get('tipo') === 'asesoria')
                        ->helperText('Una máquina, o el área en general. Quién atiende lo decide el turno, como en el sitio.'),

                    Select::make('space_ids')
                        ->label('Qué espacios')
                        ->multiple()
                        ->options(fn () => Space::where('is_reservable', true)->orderByDesc('es_todo')->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->live()
                        ->required(fn ($get) => $get('tipo') === 'espacio')
                        ->visible(fn ($get) => $get('tipo') === 'espacio')
                        ->helperText('Uno o varios, en una sola reserva. «Todo el laboratorio» va solo: ya incluye los demás. Fuera de la jornada del equipo queda como solicitud.'),

                    /*
                     * El laboratorio entero se reserva de dos maneras que no
                     * se parecen: un recorrido —treinta personas a la vez, en
                     * grupos, sin cerrar nada— o una operación que lo toma
                     * completo. Desde el sitio solo se pide recorrido; cerrar
                     * es de aquí.
                     */
                    ToggleButtons::make('modalidad')
                        ->label('Para qué se toma')
                        ->options([
                            EspacioBookingService::RECORRIDO => 'Recorrido',
                            EspacioBookingService::OPERACION => 'Operación: cerrarlo entero',
                        ])
                        ->default(EspacioBookingService::RECORRIDO)
                        ->inline()
                        ->visible(fn ($get) => $get('tipo') === 'espacio' && self::esTodo($get('space_ids')))
                        ->helperText('Un recorrido no bloquea nada: caben 30 personas a la vez, en dos grupos de 15. Cerrarlo entero no deja reservar ni una sala ni una máquina mientras dure.'),

                    Select::make('participantes')
                        ->label('Cuántas personas')
                        ->options(array_combine(range(1, 30), range(1, 30)))
                        ->default(1)
                        ->required(fn ($get) => $get('tipo') === 'espacio')
                        ->visible(fn ($get) => $get('tipo') === 'espacio'),

                    /*
                     * Quiénes del equipo acompañan. Ninguno, uno o todos: una
                     * charla puede no necesitar a nadie y un recorrido de
                     * treinta lo llevan dos o tres.
                     */
                    Select::make('acompanantes')
                        ->label('Quién acompaña del equipo')
                        ->multiple()
                        ->options(fn () => User::role(User::ROLES_BACKOFFICE)->where('status', 'activo')->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->columnSpanFull()
                        ->helperText('Opcional. Quedan anotados en la reserva y salen en la lista.'),
                ]),

            Section::make('Cuándo')
                ->columns(2)
                ->schema([
                    DateTimePicker::make('starts_at')
                        ->label('Empieza')
                        ->seconds(false)
                        ->minutesStep(15)
                        ->required(),

                    DateTimePicker::make('ends_at')
                        ->label('Termina')
                        ->seconds(false)
                        ->minutesStep(15)
                        ->required()
                        ->after('starts_at')
                        ->helperText('Lo que se cobra es lo que de verdad se use: esto es lo que se aparta.'),

                    Textarea::make('proposito')
                        ->label('Para qué')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull()
                        ->placeholder('Cortar las piezas del prototipo · Revisar un diseño antes de imprimir'),
                ]),
        ]);
    }

    /**
     * Por el mismo servicio que el sitio público. Lo que no se puede reservar
     * allí tampoco se puede aquí, y se dice con las mismas palabras.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tz = config('fabos.lab.timezone');
        $desde = Carbon::parse($data['starts_at'], $tz);
        $hasta = Carbon::parse($data['ends_at'], $tz);
        $quien = User::findOrFail($data['user_id']);
        $paraQue = $data['proposito'] ?? null;

        try {
            $reserva = match ($data['tipo']) {
                'autonomia' => app(BookingService::class)->reservar(
                    $quien, Asset::findOrFail($data['asset_id']), $desde, $hasta, $paraQue,
                ),
                'espacio' => app(EspacioBookingService::class)->reservarVarios(
                    $quien, Space::whereIn('id', array_map('intval', (array) ($data['space_ids'] ?? [])))->get()->all(),
                    $desde, $hasta, (int) ($data['participantes'] ?? 1), [], $paraQue,
                    $data['modalidad'] ?? null, array_map('intval', $data['acompanantes'] ?? []),
                ),
                'asesoria' => $this->agendarAsesoria($quien, $data['ambito'], $desde, $hasta, $paraQue),
            };
        } catch (BookingException $e) {
            Notification::make()->danger()->title('No se pudo reservar')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        }

        // Los acompañantes de una asesoría o de un equipo se anotan aquí; los
        // del espacio ya los anotó su servicio.
        if ($data['tipo'] !== 'espacio' && ! empty($data['acompanantes'])) {
            $reserva->companions()->sync(
                User::role(User::ROLES_BACKOFFICE)->whereIn('id', array_map('intval', $data['acompanantes']))->pluck('id')->all(),
            );
        }

        return $reserva;
    }

    private static function esTodo($spaceIds): bool
    {
        $ids = array_filter((array) $spaceIds);

        return $ids !== [] && Space::whereIn('id', $ids)->where('es_todo', true)->exists();
    }

    private function agendarAsesoria(User $quien, string $ambito, Carbon $desde, Carbon $hasta, ?string $paraQue): Reservation
    {
        [$clase, $id] = explode(':', $ambito, 2);

        $sobreQue = $clase === 'area' ? Area::findOrFail($id) : Asset::findOrFail($id);

        $reserva = app(AsesoriaService::class)->agendar($quien, $sobreQue, $desde, $hasta, $paraQue);

        // Null es «nadie puede»: la persona ya tiene algo a esa hora, o ningún
        // asesor de ese equipo está en jornada y libre. Se dice, no se calla.
        if ($reserva === null) {
            throw new BookingException(
                'Nadie puede atender esa asesoría a esa hora: o la persona ya tiene algo, o ningún asesor '
                . 'de ese equipo está en jornada y libre. Prueba otra hora, o mira el calendario del equipo.',
            );
        }

        return $reserva;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        $r = $this->getRecord();

        return $r->status === 'confirmada'
            ? 'Reserva confirmada'
            : 'Reserva anotada como solicitud: queda pendiente del visto bueno.';
    }
}
