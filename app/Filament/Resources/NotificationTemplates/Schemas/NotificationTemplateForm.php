<?php

namespace App\Filament\Resources\NotificationTemplates\Schemas;

use App\Models\NotificationTemplate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * El texto de un aviso.
 *
 * La clave y las variables no se tocan: las decide el código, que es quien las
 * llena. Lo que se edita aquí es lo que la gente lee.
 */
class NotificationTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Cuándo se manda')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nombre')->required(),

                        TextInput::make('key')
                            ->label('Clave')
                            ->required()
                            ->disabled(fn (?NotificationTemplate $record) => $record !== null)
                            ->dehydrated()
                            ->helperText('La decide el código: es el evento que dispara el aviso.'),

                        TextInput::make('description')
                            ->label('Qué lo dispara')
                            ->columnSpanFull(),

                        Select::make('channel')
                            ->label('Canal')
                            ->options(NotificationTemplate::CANALES)
                            ->default('email')
                            ->required(),

                        Toggle::make('is_active')
                            ->label('Activa')
                            ->default(true)
                            ->helperText('Apagada, el evento se registra pero no se manda nada.'),

                        Toggle::make('is_essential')
                            ->label('Esencial')
                            ->helperText('Lo esencial no se puede silenciar: su ausencia haría que alguien pierda el viaje o se entere tarde.'),
                    ]),

                Section::make('Qué dice')
                    ->description(fn (?NotificationTemplate $record) => $record?->variables
                        ? 'Variables disponibles: ' . collect($record->variables)->map(fn ($v) => '{' . $v . '}')->implode(', ')
                        : 'Escribe las variables entre llaves, por ejemplo {nombre_pila}.')
                    ->schema([
                        TextInput::make('subject')
                            ->label('Asunto')
                            ->maxLength(255),

                        Textarea::make('body')
                            ->label('Texto')
                            ->rows(12)
                            ->required()
                            ->helperText('Una variable que nadie llene se borra sola: nunca aparece cruda en el correo.'),
                    ]),
            ]);
    }
}
