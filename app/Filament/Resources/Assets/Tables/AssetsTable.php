<?php

namespace App\Filament\Resources\Assets\Tables;

use App\Models\Asset;
use App\Services\Assets\DuplicarActivo;
use App\Services\Qr\QrRenderer;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class AssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultGroup('area.name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Equipo')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    // El QR se imprime y se pega en la máquina: identificarla
                    // en el listado es más útil que ver el token completo.
                    ->description(fn (Asset $record) => $record->asset_tag ?: ($record->pool_key ? "grupo: {$record->pool_key}" : null)),

                TextColumn::make('riskFamily.name')
                    ->label('Familia de riesgo')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('kind')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => Asset::TIPOS[$state] ?? $state)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Asset::ESTADOS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'operativo'         => 'success',
                        'mantenimiento'     => 'warning',
                        'fuera_de_servicio' => 'danger',
                        default             => 'gray',
                    })
                    ->sortable(),

                IconColumn::make('is_reservable')
                    ->label('Reservable')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('unattended_use')
                    ->label('Desatendido')
                    ->boolean()
                    ->tooltip('El trabajo corre sin la persona presente')
                    ->toggleable(),

                TextColumn::make('autonomous_minutes')
                    ->label('Autonomía')
                    ->formatStateUsing(fn ($state) => $state ? self::duracion($state) : 'requiere check')
                    ->tooltip('Hasta cuánto puede reservar quien tiene certifab, sin visto bueno del responsable')
                    ->toggleable(),

                TextColumn::make('location.name')
                    ->label('Ubicación')
                    ->placeholder('sin asignar')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('dependencies_count')
                    ->label('Depende de')
                    ->counts('dependencies')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn ($state) => $state ?: '—')
                    ->tooltip('Equipos que deben estar operativos para poder usarlo')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('area')
                    ->label('Área')
                    ->relationship('area', 'name')
                    ->preload()
                    ->multiple(),

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Asset::ESTADOS),

                SelectFilter::make('kind')
                    ->label('Tipo')
                    ->options(Asset::TIPOS),

                TernaryFilter::make('is_reservable')
                    ->label('Reservable')
                    ->placeholder('Todos')
                    ->trueLabel('Solo reservables')
                    ->falseLabel('Solo accesorios'),

                TernaryFilter::make('unattended_use')
                    ->label('Uso desatendido')
                    ->placeholder('Todos')
                    ->trueLabel('Solo desatendidos')
                    ->falseLabel('Solo presenciales'),

                TrashedFilter::make(),
            ])
            ->headerActions([
                Action::make('etiquetas')
                    ->label('Hoja de etiquetas QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->url(fn () => route('etiquetas'))
                    ->openUrlInNewTab()
                    ->tooltip('Los QR para pegar en cada máquina, listos para imprimir'),
            ])
            /*
             * Solo iconos, con su globo al pasar por encima.
             *
             * Con el texto al lado, tres acciones se comen el ancho de la
             * ultima columna y empujan fuera de pantalla lo que se vino a
             * leer. Es lo mismo que ya se hizo en la tabla de reservas.
             */
            ->recordActions([
                self::duplicar(),
                self::verQr(),
                EditAction::make()->iconButton()->tooltip('Editar'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    private static function duracion(int $minutos): string
    {
        if ($minutos < 60) {
            return "{$minutos} min";
        }

        $horas = intdiv($minutos, 60);
        $resto = $minutos % 60;

        return $resto ? "{$horas} h {$resto} min" : "{$horas} h";
    }

    /**
     * El QR de una maquina concreta, sin pasar por la hoja completa.
     *
     * La hoja de etiquetas sirve para etiquetar el laboratorio entero de una
     * vez. Pero lo que se necesita a diario es otra cosa: una maquina nueva,
     * una etiqueta que se despego, un QR que alguien rayo. Para eso, imprimir
     * 82 etiquetas para usar una es absurdo.
     */
    private static function verQr(): Action
    {
        return Action::make('qr')
            ->label('QR')
            ->iconButton()
            ->tooltip('Ver el QR de esta maquina')
            ->icon('heroicon-o-qr-code')
            ->color('gray')
            ->modalHeading(fn (Asset $record) => 'QR de ' . $record->name)
            ->modalDescription('Escanearlo abre la ficha del equipo: registrar llegada, salida o reportar una falla.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->modalContent(function (Asset $record) {
                // El token se crea al pedirlo por primera vez: un activo sin
                // token no tendria QR que enseñar.
                if (! $record->qr_token) {
                    $record->forceFill(['qr_token' => (string) Str::uuid()])->save();
                }

                $url = route('escaneo.equipo', $record->qr_token);

                return new HtmlString(
                    '<div style="text-align:center;padding:1rem">'
                    . app(QrRenderer::class)->svg($url, 220)
                    . '<p style="margin-top:1rem;font-weight:700">' . e($record->name) . '</p>'
                    . '<p style="font-size:.8rem;opacity:.6;word-break:break-all">' . e($url) . '</p>'
                    . '<p style="font-size:.8rem;opacity:.75;margin-top:.75rem">'
                    . 'Imprime esta ventana, o usa la hoja completa para etiquetar varias máquinas.'
                    . '</p></div>'
                );
            });
    }

    /**
     * Copiar una ficha que ya existe, una o varias veces (§7).
     *
     * Llega una tanda de multimetros iguales al que ya esta fichado. Volver a
     * llenar el formulario entero -familia de riesgo, modo de reserva,
     * autonomia, dependencias- es la clase de tarea que se hace mal a la
     * cuarta, y una ficha mal copiada es una maquina que se reserva con las
     * reglas de otra.
     *
     * El alta por cantidad ya existia para lo que se ficha de cero; esto es lo
     * mismo cuando la primera ya esta puesta.
     */
    private static function duplicar(): Action
    {
        return Action::make('duplicar')
            ->label('Duplicar')
            ->iconButton()
            ->tooltip('Crear copias de esta maquina')
            ->icon('heroicon-o-square-2-stack')
            ->color('gray')
            ->modalHeading(fn (Asset $record) => 'Duplicar ' . $record->name)
            ->modalDescription(
                'Cada copia es una ficha aparte, numerada, con su hoja de vida y su '
                . 'mantenimiento. Se copian las condiciones de uso, las dependencias y quienes '
                . 'pueden asesorar. La placa, el serie y el QR no: son de cada aparato y se '
                . 'anotan con el aparato delante.'
            )
            ->modalSubmitActionLabel('Crear las copias')
            ->schema([
                TextInput::make('cuantas')
                    ->label('Cuantas copias')
                    ->numeric()
                    ->default(1)
                    ->minValue(1)
                    ->maxValue(DuplicarActivo::MAXIMAS)
                    ->required()
                    ->helperText('Hasta ' . DuplicarActivo::MAXIMAS . '. Se numeran siguiendo a las que ya hay.'),
            ])
            ->action(function (Asset $record, array $data) {
                $copias = app(DuplicarActivo::class)->copiar($record, (int) $data['cuantas']);

                Notification::make()
                    ->success()
                    ->title($copias->count() === 1
                        ? 'Copia creada: ' . $copias->first()->name
                        : 'Se crearon ' . $copias->count() . ' fichas')
                    ->body('De ' . $copias->first()->name . ' a ' . $copias->last()->name
                        . '. Falta anotarles la placa y el serie.')
                    ->send();
            });
    }
}
