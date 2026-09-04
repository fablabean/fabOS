<?php

namespace App\Filament\Resources\CandidateBatches;

use App\Models\CandidateBatch;
use App\Services\Projects\LoteDeCandidatos;
use App\Services\Projects\ProjectException;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CandidateBatchesTable
{
    /**
     * El texto de la lista, venga de donde venga: el archivo subido manda
     * sobre lo pegado, porque quien sube un archivo no va a pegar ademas.
     */
    private static function textoDe(array $estado): string
    {
        $archivo = $estado['archivo'] ?? null;

        if (is_array($archivo)) {
            $archivo = reset($archivo);
        }

        if ($archivo instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
            return (string) $archivo->get();
        }

        if (is_string($archivo) && $archivo !== '' && \Illuminate\Support\Facades\Storage::disk('local')->exists($archivo)) {
            return (string) \Illuminate\Support\Facades\Storage::disk('local')->get($archivo);
        }

        return (string) ($estado['lista'] ?? '');
    }

    private static function analisis(callable $get): array
    {
        $texto = self::textoDe(['archivo' => $get('archivo'), 'lista' => $get('lista')]);

        if (trim($texto) === '') {
            return ['separador' => "\t", 'cabecera' => true, 'columnas' => [], 'filas' => 0, 'mapa' => [], 'muestra' => []];
        }

        $a = app(LoteDeCandidatos::class)->analizar($texto);

        // Si la persona corrigio el interruptor de la cabecera, se respeta.
        $cabecera = $get('cabecera');

        if ($cabecera !== null && (bool) $cabecera !== $a['cabecera']) {
            $a = self::reanalizarConCabecera($texto, (bool) $cabecera);
        }

        return $a;
    }

    private static function reanalizarConCabecera(string $texto, bool $cabecera): array
    {
        $servicio = app(LoteDeCandidatos::class);
        $a = $servicio->analizar($texto);

        if ($cabecera === $a['cabecera']) {
            return $a;
        }

        // Forzar la lectura: con o sin cabecera segun diga la persona.
        $lineas = preg_split('/\r\n|\r|\n/', trim(preg_replace('/^\xEF\xBB\xBF/', '', $texto)));
        $primera = str_getcsv($lineas[0], $a['separador'], '"', '\\');
        $columnas = $cabecera
            ? array_map(fn ($c, $i) => trim($c) !== '' ? trim($c) : 'Columna ' . ($i + 1), $primera, array_keys($primera))
            : array_map(fn ($i) => 'Columna ' . ($i + 1), array_keys($primera));
        $datos = $cabecera ? array_slice($lineas, 1) : $lineas;

        return [
            'separador' => $a['separador'],
            'cabecera'  => $cabecera,
            'columnas'  => array_values($columnas),
            'filas'     => count(array_filter($datos, fn ($l) => trim($l) !== '')),
            'mapa'      => $servicio->sugerirMapa(array_values($columnas), $cabecera),
            'muestra'   => array_map(fn ($l) => array_map('trim', str_getcsv($l, $a['separador'], '"', '\\')), array_slice($datos, 0, 3)),
        ];
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Lote')
                    ->weight('medium')
                    ->searchable()
                    ->description(fn (CandidateBatch $r) => $r->source),

                TextColumn::make('candidates_count')
                    ->label('Candidatos')
                    ->counts('candidates')
                    ->alignEnd(),

                TextColumn::make('pendientes')
                    ->label('Sin evaluar')
                    ->alignEnd()
                    ->state(fn (CandidateBatch $r) => $r->pendientes())
                    ->color(fn (CandidateBatch $r) => $r->pendientes() ? 'warning' : 'gray'),

                TextColumn::make('aceptados')
                    ->label('Aceptados')
                    ->alignEnd()
                    ->state(fn (CandidateBatch $r) => $r->aceptados())
                    // Lo aceptado que todavia no es proyecto es el trabajo que
                    // queda: sin esto se queda ahi sin que nadie lo note.
                    ->description(fn (CandidateBatch $r) => $r->sinConvertir()
                        ? $r->sinConvertir() . ' sin convertir'
                        : null),

                TextColumn::make('received_on')->label('Llegó')->date('d/m/Y')->placeholder('—'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => CandidateBatch::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'abierto'  => 'warning',
                        'evaluado' => 'info',
                        default    => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(CandidateBatch::ESTADOS),
            ])
            ->recordActions([
                // Pegar la lista tal como llega: de una hoja de calculo, de un
                // correo. Pedirle a quien pega que primero convierta el formato
                // es pedirle que no lo haga.
                /*
                 * Pegar o subir la lista, y decir que columna va a donde.
                 *
                 * Cada convocatoria manda su tablero con sus propias columnas:
                 * puntaje, ruta, modalidad, valor a financiar. Antes las
                 * columnas eran fijas y lo que no cabia se perdia. Ahora se
                 * mira la lista, se propone un mapa a partir de la cabecera,
                 * quien importa lo corrige, y lo que no tiene sitio se guarda
                 * como dato extra con el nombre de su columna.
                 */
                Action::make('pegar')
                    ->label('Pegar o subir la lista')
                    ->iconButton()
                    ->tooltip('Pegar o subir la lista')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('gray')
                    ->modalHeading('Meter la lista de candidatos')
                    ->modalDescription('Pega de una hoja de cálculo o sube el CSV. Abajo dices qué columna va a dónde antes de confirmar.')
                    ->modalSubmitActionLabel('Importar')
                    ->modalWidth('4xl')
                    ->schema([
                        FileUpload::make('archivo')
                            ->label('El archivo (CSV o texto separado)')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'text/tab-separated-values', 'application/vnd.ms-excel'])
                            ->maxSize(5120)
                            ->live()
                            ->helperText('Con la fila de encabezados. Sirven punto y coma, tabulador o coma.'),

                        Textarea::make('lista')
                            ->label('O pégala aquí')
                            ->rows(6)
                            ->live(onBlur: true)
                            ->placeholder("Proyecto\tOrganización\tContacto\nSensores para invernadero\tAgroTech SAS\tLaura Díaz"),

                        Toggle::make('cabecera')
                            ->label('La primera fila son los encabezados')
                            ->live()
                            ->default(fn ($get) => self::analisis($get)['cabecera'] ?? true),

                        /*
                         * El mapa: una fila por columna encontrada, con lo que
                         * el sistema propone ya puesto. Se dibuja cuando hay
                         * algo que mirar.
                         */
                        Group::make()
                            ->columnSpanFull()
                            ->visible(fn ($get) => (self::analisis($get)['filas'] ?? 0) > 0 || (self::analisis($get)['columnas'] ?? []) !== [])
                            ->schema(function ($get) {
                                $a = self::analisis($get);
                                $campos = [
                                    Placeholder::make('resumen_analisis')
                                        ->label('Lo que se ve')
                                        ->content(count($a['columnas']) . ' columnas · ' . $a['filas'] . ' filas'
                                            . ($a['cabecera'] ? ' · con encabezados' : ' · sin encabezados')),
                                ];

                                foreach ($a['columnas'] as $i => $columna) {
                                    $ejemplo = trim((string) ($a['muestra'][0][$i] ?? ''));

                                    $campos[] = Select::make('mapa.' . $i)
                                        ->label($columna)
                                        ->options(LoteDeCandidatos::DESTINOS)
                                        ->default($a['mapa'][$i] ?? 'extra')
                                        ->helperText($ejemplo !== '' ? 'Ej.: ' . mb_strimwidth($ejemplo, 0, 60, '…') : null);
                                }

                                return $campos;
                            }),
                    ])
                    ->action(function (CandidateBatch $record, array $data) {
                        $texto = self::textoDe($data);
                        $mapa = array_map('strval', $data['mapa'] ?? []);

                        try {
                            $cuantos = app(LoteDeCandidatos::class)->importar(
                                $record, $texto, $mapa, (bool) ($data['cabecera'] ?? true),
                            );
                        } catch (ProjectException $e) {
                            Notification::make()->danger()->title('No se importó')->body($e->getMessage())->persistent()->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title($cuantos === 1 ? 'Entró 1 candidato' : "Entraron {$cuantos} candidatos")
                            ->body('Ábrelo para evaluarlos uno a uno. Lo que no tenía columna quedó como dato extra.')
                            ->send();
                    }),

                // Convertir de una vez todo lo aceptado.
                Action::make('convertir')
                    ->label('Convertir lo aceptado')
                    ->iconButton()
                    ->tooltip('Convertir lo aceptado en proyectos')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->visible(fn (CandidateBatch $r) => $r->sinConvertir() > 0)
                    ->requiresConfirmation()
                    ->modalHeading('Convertir lo aceptado en proyectos')
                    ->modalDescription(fn (CandidateBatch $r) => 'Se crean ' . $r->sinConvertir()
                        . ' proyectos, cada uno con su código y en la etapa de idea. '
                        . 'Lo que se escribió al evaluarlos queda en el resumen.')
                    ->modalSubmitActionLabel('Crearlos')
                    ->action(function (CandidateBatch $record) {
                        try {
                            $cuantos = app(LoteDeCandidatos::class)
                                ->convertirLoAceptado($record, auth()->user());
                        } catch (ProjectException $e) {
                            Notification::make()->danger()->title('No se pudo')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title($cuantos === 1 ? 'Se creó 1 proyecto' : "Se crearon {$cuantos} proyectos")
                            ->send();
                    }),

                EditAction::make()->iconButton()->tooltip('Editar'),

                /*
                 * Borrar un lote. Se lleva sus candidatos -son la lista- pero
                 * no los proyectos que ya salieron de el: esos ya viven
                 * solos, con su codigo y su equipo. Quien puede lo decide la
                 * matriz.
                 */
                DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Borrar el lote')
                    ->modalHeading(fn (CandidateBatch $r) => 'Borrar «' . $r->name . '»')
                    ->modalDescription(fn (CandidateBatch $r) => 'Se van sus '
                        . $r->candidates()->count() . ' candidatos y lo que se anotó al evaluarlos. '
                        . (($n = $r->candidates()->whereNotNull('project_id')->count()) > 0
                            ? "Los {$n} proyectos que ya salieron de este lote se quedan: ya viven solos."
                            : 'Ningún candidato se convirtió en proyecto todavía.')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalHeading('Borrar los lotes seleccionados')
                        ->modalDescription('Se van con sus candidatos. Los proyectos que ya salieron de ellos se quedan.'),
                ]),
            ]);
    }
}
