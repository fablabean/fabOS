<?php

namespace App\Filament\Resources\ProfessionalProfiles\Tables;

use App\Filament\Componentes\NuevaPersona;
use App\Models\ProfessionalProfile;
use App\Services\Personas\CuentaDelPerfil;
use App\Services\Personas\EntregaALaUniversidad;
use App\Services\Personas\PerfilException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * La lista de quién puede trabajar con el laboratorio (§5).
 *
 * Lo que se mira primero es quién está listo para presentarse y quién no, y por
 * eso esa columna va antes que el estado: un perfil «propuesto» al que le falta
 * el RUT no se puede mandar a ninguna parte.
 */
class ProfessionalProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            // Con los documentos cargados de una vez: el badge de completitud
            // los cuenta por fila, y sin esto serian tantas consultas como
            // perfiles.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['documents', 'area', 'user']))
            ->columns([
                TextColumn::make('name')
                    ->label('Quién')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (ProfessionalProfile $r) => $r->specialty),

                TextColumn::make('person_kind')
                    ->label('Persona')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state) => ProfessionalProfile::PERSONAS[$state] ?? $state)
                    ->placeholder('—'),

                TextColumn::make('document_number')
                    ->label('Documento')
                    ->searchable()
                    ->state(fn (ProfessionalProfile $r) => $r->documento())
                    ->placeholder('—'),

                TextColumn::make('area.name')->label('Área')->toggleable()->placeholder('—'),

                TextColumn::make('completo')
                    ->label('Para presentar')
                    ->badge()
                    ->state(fn (ProfessionalProfile $r) => $r->estaListo()
                        ? 'Listo'
                        : 'Faltan ' . count($r->loQueFalta()))
                    ->color(fn (ProfessionalProfile $r) => $r->estaListo() ? 'success' : 'warning')
                    // Qué falta, sin abrir la ficha: es la pregunta que se hace
                    // quien está armando la entrega.
                    ->tooltip(fn (ProfessionalProfile $r) => $r->estaListo()
                        ? null
                        : implode(' · ', $r->loQueFalta())),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ProfessionalProfile::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'inscrito'   => 'success',
                        'presentado' => 'info',
                        'propuesto'  => 'warning',
                        default      => 'gray',
                    })
                    ->description(fn (ProfessionalProfile $r) => $r->vendor_code),

                TextColumn::make('user.name')
                    ->label('Cuenta')
                    ->placeholder('— sin cuenta')
                    ->toggleable(),

                TextColumn::make('submitted_at')
                    ->label('Presentado')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(ProfessionalProfile::ESTADOS),

                SelectFilter::make('area_id')->label('Área')->relationship('area', 'name'),

                SelectFilter::make('person_kind')
                    ->label('Tipo de persona')
                    ->options(ProfessionalProfile::PERSONAS),

                Filter::make('listos')
                    ->label('Listos para presentar')
                    ->query(fn (Builder $query) => $query->whereIn('id', ProfessionalProfile::idsListos())),

                TernaryFilter::make('con_cuenta')
                    ->label('Ya tiene cuenta')
                    ->nullable()
                    ->attribute('user_id'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('hoja')
                        ->label('Ver la hoja')
                        ->icon('heroicon-o-document-text')
                        ->url(fn (ProfessionalProfile $r) => route('perfiles.hoja', $r))
                        ->openUrlInNewTab(),

                    self::proponer(),
                    self::crearleCuenta(),
                    self::anotarCodigo(),
                    self::descartar(),

                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                self::entregaPdf(),
                self::entregaCsv(),

                BulkActionGroup::make([
                    /*
                     * Borrar uno a uno y no con el `DeleteBulkAction` de serie:
                     * el borrado masivo puede resolverse en la base sin pasar
                     * por Eloquent, y entonces no se dispara el gancho que
                     * borra los archivos. Quedarian cedulas sueltas en el
                     * disco sin ninguna ficha que las explicara.
                     */
                    BulkAction::make('borrar')
                        ->label('Borrar')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Borrar los perfiles seleccionados')
                        ->modalDescription('Se van con sus documentos: los archivos se borran del servidor. No se puede deshacer.')
                        ->action(function (Collection $records) {
                            $records->each->delete();

                            Notification::make()->title('Borrados, con sus documentos')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->emptyStateHeading('Todavía no hay perfiles')
            ->emptyStateDescription('Aquí se consolida quién puede trabajar con el laboratorio, con los papeles que la Universidad pide para inscribirlo como proveedor.')
            ->description('Un perfil no es una cuenta ni es todavía un proveedor. De aquí sale la hoja que se le manda a compras, y de aquí se le crea cuenta el día que haga falta.');
    }

    // --------------------------------------------------------- las acciones

    private static function proponer(): Action
    {
        return Action::make('proponer')
            ->label('Proponer')
            ->icon('heroicon-o-hand-thumb-up')
            ->color('warning')
            ->visible(fn (ProfessionalProfile $r) => $r->status === 'borrador')
            ->requiresConfirmation()
            ->modalHeading('Proponer este perfil')
            ->modalDescription('Queda marcado como listo para presentarse a la Universidad en la próxima entrega.')
            ->action(function (ProfessionalProfile $record) {
                // Si le falta algo, se dice qué: un botón que no hace nada y no
                // explica por qué se pulsa tres veces antes de preguntar.
                if (! $record->estaListo()) {
                    Notification::make()
                        ->title('Todavía no se puede proponer')
                        ->body('Falta: ' . implode(' · ', $record->loQueFalta()))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $record->update(['status' => 'propuesto']);

                Notification::make()->title('Propuesto')->success()->send();
            });
    }

    private static function crearleCuenta(): Action
    {
        return Action::make('cuenta')
            ->label('Crearle cuenta')
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->visible(fn (ProfessionalProfile $r) => ! $r->yaTieneCuenta())
            ->modalHeading('Darle cuenta en el sistema')
            ->modalDescription('Nace activa y validada, con su correo. Sin rol del panel no entra al backoffice: usa el sitio y «Mi cuenta», que es lo que necesita para ver sus reservas.')
            ->modalSubmitActionLabel('Crear la cuenta')
            ->schema(fn () => array_slice(NuevaPersona::formulario(), 3))
            ->action(function (ProfessionalProfile $record, array $data) {
                try {
                    $persona = app(CuentaDelPerfil::class)->crear($record, $data);
                } catch (PerfilException $e) {
                    Notification::make()
                        ->title('No se pudo')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Cuenta de ' . $persona->name)
                    ->body('Ya puede entrar con su correo.')
                    ->success()
                    ->send();
            });
    }

    private static function anotarCodigo(): Action
    {
        return Action::make('inscrito')
            ->label('Anotar código de proveedor')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (ProfessionalProfile $r) => $r->status === 'presentado')
            ->modalHeading('La Universidad ya lo inscribió')
            ->schema([
                TextInput::make('vendor_code')
                    ->label('Código de proveedor')
                    ->required()
                    ->maxLength(40),

                DatePicker::make('registered_at')->label('Inscrito el')->default(now()),
            ])
            ->action(function (ProfessionalProfile $record, array $data) {
                $record->update([
                    'status'        => 'inscrito',
                    'vendor_code'   => $data['vendor_code'],
                    'registered_at' => $data['registered_at'] ?? now(),
                ]);

                Notification::make()->title('Inscrito como proveedor')->success()->send();
            });
    }

    private static function descartar(): Action
    {
        return Action::make('descartar')
            ->label('Descartar')
            ->icon('heroicon-o-x-circle')
            ->color('gray')
            ->visible(fn (ProfessionalProfile $r) => $r->status !== 'descartado')
            ->modalHeading('Descartar este perfil')
            ->modalDescription('Sale de la lista y deja de contar. No se borra: queda escrito por qué, para no volver a discutirlo dentro de seis meses.')
            ->schema([
                Textarea::make('motivo')->label('Por qué')->required()->rows(2)->maxLength(255),
            ])
            ->action(function (ProfessionalProfile $record, array $data) {
                $record->update([
                    'status' => 'descartado',
                    'notes'  => trim(($record->notes ? $record->notes . "\n\n" : '') . 'Descartado: ' . $data['motivo']),
                ]);

                Notification::make()->title('Descartado')->success()->send();
            });
    }

    private static function entregaPdf(): BulkAction
    {
        return BulkAction::make('entregaPdf')
            ->label('Hoja para la Universidad (PDF)')
            ->icon('heroicon-o-document-arrow-down')
            ->color('success')
            ->modalHeading('Preparar la entrega a la Universidad')
            ->modalDescription('Una hoja por perfil, con sus datos de contratación y qué documentos tiene. Los adjuntos no van dentro: se piden por el panel.')
            ->modalSubmitActionLabel('Bajar el PDF')
            ->schema([
                Toggle::make('sellar')
                    ->label('Marcarlos como presentados')
                    ->default(true)
                    ->helperText('Deja fecha de presentación. Los que ya estén inscritos o descartados no se tocan.'),
            ])
            ->action(function (Collection $records, array $data) {
                $entrega = app(EntregaALaUniversidad::class);

                if ($data['sellar'] ?? false) {
                    $sellados = $entrega->sellar($records);

                    if ($sellados > 0) {
                        Notification::make()
                            ->title($sellados === 1 ? 'Uno marcado como presentado' : $sellados . ' marcados como presentados')
                            ->success()
                            ->send();
                    }
                }

                return $entrega->hojas($records);
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function entregaCsv(): BulkAction
    {
        return BulkAction::make('entregaCsv')
            ->label('Planilla para compras (CSV)')
            ->icon('heroicon-o-table-cells')
            ->color('gray')
            // No sella: bajar la planilla para revisarla no es presentarla.
            ->action(function (Collection $records) {
                $csv = app(EntregaALaUniversidad::class)->csv($records);
                $nombre = 'perfiles-' . now(config('fabos.lab.timezone'))->format('Y-m-d') . '.csv';

                return response()->streamDownload(fn () => print($csv), $nombre, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                ]);
            })
            ->deselectRecordsAfterCompletion();
    }
}
