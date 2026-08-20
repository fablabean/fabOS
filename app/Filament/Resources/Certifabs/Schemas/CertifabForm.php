<?php

namespace App\Filament\Resources\Certifabs\Schemas;

use App\Models\Certifab;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Otorgar un certifab (§10).
 *
 * Quien lo otorga queda registrado automáticamente: es una habilitación
 * trazable, no un favor recordado. Por eso el campo no se deja editar a mano.
 */
class CertifabForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('A quién y sobre qué')
                    ->columns(2)
                    ->schema([
                        Select::make('user_id')
                            ->label('Persona')
                            ->relationship('user', 'name')
                            ->searchable(['name', 'email'])
                            ->preload()
                            ->required()
                            ->columnSpanFull(),

                        Select::make('risk_family_id')
                            ->label('Familia de riesgo')
                            ->relationship('riskFamily', 'name')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->helperText('Habilita todos los equipos de la familia.')
                            ->disabled(fn ($get) => filled($get('asset_id'))),

                        Select::make('asset_id')
                            ->label('…o un equipo puntual')
                            ->relationship('asset', 'name')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->helperText('Para equipos que requieren inducción propia.')
                            ->disabled(fn ($get) => filled($get('risk_family_id'))),
                    ]),

                Section::make('Alcance y vigencia')
                    ->columns(2)
                    ->schema([
                        Select::make('level')
                            ->label('Nivel')
                            ->options(array_combine(Certifab::NIVELES, Certifab::NIVELES))
                            ->default('byte')
                            ->required()
                            ->helperText('El nivel tera da hasta 12 horas de autonomía.'),

                        Select::make('granted_via')
                            ->label('Cómo se obtuvo')
                            ->options([
                                'asesoria' => 'Asesoría con el responsable',
                                'curso'    => 'Aprobó el curso',
                                'migracion' => 'Carga inicial',
                            ])
                            ->default('asesoria')
                            ->required(),

                        TextInput::make('max_autonomous_minutes')
                            ->label('Autonomía propia (min)')
                            ->numeric()
                            ->placeholder('usar la del equipo')
                            ->helperText('Solo si esta persona tiene un límite distinto.'),

                        DateTimePicker::make('expires_at')
                            ->label('Vence')
                            ->seconds(false)
                            ->helperText('Vacío = sin vencimiento.'),

                        Textarea::make('notes')
                            ->label('Notas')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
