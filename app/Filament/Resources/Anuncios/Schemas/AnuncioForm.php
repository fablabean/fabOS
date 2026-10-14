<?php

namespace App\Filament\Resources\Anuncios\Schemas;

use App\Models\Anuncio;
use App\Models\AnuncioProgramacion;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class AnuncioForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Lo que se dice')
                ->description('El texto se convierte en voz con el servidor del FabLab. Se genera una sola vez al guardar, y otra vez solo si cambias el texto o la voz.')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('titulo')
                        ->label('Título')
                        ->placeholder('Horario de diciembre')
                        ->required()
                        ->maxLength(120)
                        ->helperText('Se lee en pantalla encima del reproductor. No se locuta.'),

                    Textarea::make('texto')
                        ->label('Texto del anuncio')
                        ->required()
                        ->rows(6)
                        ->maxLength(Anuncio::MAX_CARACTERES)
                        ->helperText('Hasta 5.000 caracteres. Horas, cifras y precios se leen bien escritos con números: «10:00», «1.250», «$45.000».'),

                    ToggleButtons::make('voz')
                        ->label('Voz')
                        ->options(Anuncio::VOCES)
                        ->default('mujer')
                        ->inline()
                        ->required(),
                ]),

            Section::make('El audio')
                ->columnSpanFull()
                ->visible(fn (?Anuncio $record) => $record !== null)
                ->schema([
                    Placeholder::make('estado_del_audio')
                        ->hiddenLabel()
                        ->content(fn (?Anuncio $record) => self::estado($record)),
                ]),

            Section::make('Horario fijo')
                ->description('Para que suene solo, siempre a la misma hora. Suena en el equipo que tenga abierta la pantalla «Parlante» del panel. Respeta las fechas de abajo, pero no si sale en la portada.')
                ->columnSpanFull()
                ->collapsible()
                ->schema([
                    Repeater::make('programaciones')
                        ->hiddenLabel()
                        ->relationship()
                        ->defaultItems(0)
                        ->addActionLabel('Añadir un horario')
                        ->columns(4)
                        ->itemLabel(fn (array $state) => filled($state['hora'] ?? null) && filled($state['dias'] ?? null)
                            ? (new AnuncioProgramacion($state))->resumen() : null)
                        ->schema([
                            CheckboxList::make('dias')
                                ->label('Días')
                                ->options(AnuncioProgramacion::DIAS)
                                ->columns(7)
                                ->required()
                                ->bulkToggleable()
                                ->columnSpanFull(),

                            TimePicker::make('hora')
                                ->label('Hora')
                                ->seconds(false)
                                ->required(),

                            Select::make('repeticiones')
                                ->label('Veces seguidas')
                                ->options([1 => 'Una vez', 2 => 'Dos veces', 3 => 'Tres veces'])
                                ->default(1)
                                ->selectablePlaceholder(false)
                                ->live()
                                ->required(),

                            TextInput::make('pausa')
                                ->label('Pausa entre una y otra')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(600)
                                ->default(30)
                                ->suffix('segundos')
                                ->visible(fn ($get) => (int) $get('repeticiones') > 1)
                                ->required(),
                        ]),
                ]),

            Section::make('Cuándo se oye')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Toggle::make('is_active')
                        ->label('Sale en la portada')
                        ->default(true)
                        ->helperText('Apagado no sale, pero se conserva con su audio.')
                        ->columnSpanFull(),

                    Toggle::make('favorito')
                        ->label('Favorito')
                        ->helperText('Sale en la barra de arriba del panel, para lanzarlo a un clic. Sirve aunque no salga en la portada.')
                        ->columnSpanFull(),

                    DateTimePicker::make('starts_at')
                        ->label('Empieza a oírse')
                        ->helperText('Opcional. Se puede dejar escrito hoy y que aparezca solo el lunes.'),

                    DateTimePicker::make('ends_at')
                        ->label('Deja de oírse')
                        ->after('starts_at')
                        ->helperText('Opcional. El anuncio de un evento se apaga solo cuando el evento pasa.'),
                ]),
        ]);
    }

    /**
     * En qué va el audio, con el reproductor si ya está.
     *
     * El reproductor va aquí y no solo en la portada: quien escribió el anuncio
     * tiene que poder oírlo antes de que lo oiga todo el mundo. Una sigla mal
     * leída o un número dicho al revés solo se descubren escuchando.
     */
    private static function estado(?Anuncio $anuncio): HtmlString
    {
        if ($anuncio === null) {
            return new HtmlString('');
        }

        if ($anuncio->tieneAudioAlDia()) {
            return new HtmlString(
                '<audio controls preload="none" style="width:100%" src="' . e($anuncio->audioUrl()) . '"></audio>'
            );
        }

        return new HtmlString(match ($anuncio->audio_estado) {
            'fallo' => '<strong>No se pudo generar.</strong> ' . e($anuncio->audio_error ?? '')
                . ' El servidor de voz solo responde desde la red del FabLab. Desde la lista, «Volver a generar».',
            'generando' => '<strong>Generándose.</strong> Tarda más o menos la mitad de lo que dura el audio. Vuelve a abrir el anuncio en un momento.',
            default => '<strong>En cola.</strong> El servidor de voz atiende una petición a la vez; en cuanto le toque, el audio aparece aquí.',
        });
    }
}
