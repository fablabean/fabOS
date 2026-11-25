<?php

namespace App\Filament\Resources\Auditorias;

use App\Filament\Componentes\ArchivoPrivado;
use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Auditorias\Pages\CreateAuditoria;
use App\Filament\Resources\Auditorias\Pages\EditAuditoria;
use App\Filament\Resources\Auditorias\Pages\ListAuditorias;
use App\Models\Auditoria;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Las auditorías de calidad, internas y externas (ISO 9001, proceso INV-200).
 *
 * Una por visita: cuándo fue, quién auditó, qué procedimientos miró, qué
 * resultó y qué mejoras salieron de ahí, con su responsable y su estado. La
 * próxima auditoría empieza leyendo cómo quedaron las mejoras de la anterior.
 */
class AuditoriaResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Auditoria::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $modelLabel = 'Auditoría';

    protected static ?string $pluralModelLabel = 'Auditorías';

    protected static ?string $recordTitleAttribute = 'titulo';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Documentación';
    }

    public static function form(Schema $schema): Schema
    {
        $tz = config('fabos.lab.timezone');

        return $schema->columns(1)->components([
            Section::make('La auditoría')
                ->columns(2)
                ->schema([
                    Select::make('tipo')
                        ->label('Tipo')
                        ->options(Auditoria::TIPOS)
                        ->required()
                        ->default('externa'),

                    DateTimePicker::make('realizada_at')
                        ->label('Fecha y hora')
                        ->seconds(false)
                        ->timezone($tz)
                        ->required(),

                    TextInput::make('titulo')
                        ->label('Título')
                        ->required()
                        ->maxLength(200)
                        ->placeholder('Auditoría externa ISO 9001 · noviembre 2026')
                        ->columnSpanFull(),

                    TextInput::make('auditor')
                        ->label('Auditor(a) o ente')
                        ->maxLength(200),

                    TextInput::make('proceso')
                        ->label('Proceso')
                        ->default('INV-200 · Innovación, Desarrollo y Transferencia de Conocimiento')
                        ->maxLength(200),

                    Textarea::make('alcance')
                        ->label('Alcance')
                        ->rows(2)
                        ->placeholder('Servicios laboratorios de investigación (INV-114, 115, 116, 118) y escenarios de realidad virtual (INV-113).')
                        ->columnSpanFull(),
                ]),

            Section::make('Resultado')
                ->columns(2)
                ->schema([
                    Select::make('resultado')
                        ->label('Resultado')
                        ->options(Auditoria::RESULTADOS)
                        ->default('pendiente')
                        ->required(),

                    ArchivoPrivado::previsualizar(FileUpload::make('informe_path'))
                        ->label('Informe de la auditoría')
                        ->disk('local')
                        ->directory('calidad')
                        ->maxSize(20480),

                    Textarea::make('resumen')
                        ->label('Resumen')
                        ->rows(4)
                        ->columnSpanFull(),
                ]),

            Section::make('Hallazgos y mejoras')
                ->description('Lo que señaló la auditoría y, si pide una mejora, quién la hace, para cuándo y en qué va.')
                ->schema([
                    Repeater::make('hallazgos')
                        ->hiddenLabel()
                        ->addActionLabel('Añadir hallazgo')
                        ->defaultItems(0)
                        ->collapsible()
                        ->itemLabel(fn (array $state) => trim(
                            (Auditoria::TIPOS_DE_HALLAZGO[$state['tipo'] ?? ''] ?? 'Hallazgo')
                            . (filled($state['procedimiento'] ?? null) ? ' · ' . $state['procedimiento'] : '')
                        ))
                        ->columns(4)
                        ->schema([
                            Select::make('tipo')
                                ->label('Tipo')
                                ->options(Auditoria::TIPOS_DE_HALLAZGO)
                                ->required(),
                            Select::make('procedimiento')
                                ->label('Procedimiento')
                                ->options(array_combine(array_keys(Auditoria::PROCEDIMIENTOS), array_keys(Auditoria::PROCEDIMIENTOS))),
                            Select::make('estado')
                                ->label('Estado de la mejora')
                                ->options(Auditoria::ESTADOS_DE_MEJORA)
                                ->default('abierta'),
                            DatePicker::make('fecha_compromiso')
                                ->label('Para cuándo'),
                            Textarea::make('descripcion')
                                ->label('Qué se encontró')
                                ->required()
                                ->rows(2)
                                ->columnSpan(2),
                            Textarea::make('accion')
                                ->label('Mejora o acción')
                                ->rows(2)
                                ->columnSpan(2),
                            TextInput::make('responsable')
                                ->label('Responsable')
                                ->maxLength(160)
                                ->columnSpan(2),
                            TextInput::make('evidencia_url')
                                ->label('Evidencia (enlace)')
                                ->url()
                                ->maxLength(500)
                                ->columnSpan(2),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = config('fabos.lab.timezone');

        return $table
            ->defaultSort('realizada_at', 'desc')
            ->columns([
                TextColumn::make('realizada_at')
                    ->label('Fecha y hora')
                    ->dateTime('d/m/Y H:i', $tz)
                    ->sortable(),
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Auditoria::TIPOS[$state] ?? $state)
                    ->color(fn ($state) => $state === 'externa' ? 'info' : 'gray'),
                TextColumn::make('titulo')
                    ->label('Título')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Auditoria $r) => $r->auditor),
                TextColumn::make('resultado')
                    ->label('Resultado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Auditoria::RESULTADOS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'conforme'          => 'success',
                        'con_observaciones' => 'warning',
                        'no_conforme'       => 'danger',
                        default             => 'gray',
                    }),
                TextColumn::make('hallazgos')
                    ->label('Hallazgos')
                    ->state(fn (Auditoria $r) => count($r->hallazgos ?? []))
                    ->description(fn (Auditoria $r) => ($n = $r->mejorasAbiertas()) ? $n . ' mejoras abiertas' : null),
            ])
            ->filters([
                SelectFilter::make('tipo')->label('Tipo')->options(Auditoria::TIPOS),
                SelectFilter::make('resultado')->label('Resultado')->options(Auditoria::RESULTADOS),
            ])
            ->recordActions([
                Action::make('presentacion')
                    ->iconButton()
                    ->icon('heroicon-o-presentation-chart-bar')
                    ->color('gray')
                    ->tooltip('Presentación INV-200 con las evidencias')
                    ->url(fn () => route('calidad.caracterizacion'))
                    ->openUrlInNewTab(),
                EditAction::make()->iconButton()->tooltip('Editar'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListAuditorias::route('/'),
            'create' => CreateAuditoria::route('/create'),
            'edit'   => EditAuditoria::route('/{record}/edit'),
        ];
    }
}
