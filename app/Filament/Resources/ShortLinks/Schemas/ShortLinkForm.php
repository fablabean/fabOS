<?php

namespace App\Filament\Resources\ShortLinks\Schemas;

use App\Models\ShortLink;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ShortLinkForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('El enlace')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Para qué es')
                            ->required()
                            ->placeholder('Cartel de la convocatoria de spinoffs')
                            ->helperText('Para reconocerlo dentro de seis meses. No se enseña a nadie.')
                            ->columnSpanFull(),

                        TextInput::make('target')
                            ->label('A dónde lleva')
                            ->required()
                            ->url()
                            ->maxLength(2000)
                            ->placeholder('https://fablabean.com/proyectos/solicitar')
                            ->helperText('Se puede cambiar cuando quieras: el código impreso sigue sirviendo.')
                            ->columnSpanFull(),

                        /*
                         * El codigo se genera solo, y se puede escribir.
                         *
                         * Sin O ni 0, sin I ni 1: se teclea de un cartel cuando
                         * la camara no enfoca, y un codigo mal copiado no lleva
                         * a ninguna parte —o peor, lleva a otro sitio—.
                         */
                        TextInput::make('code')
                            ->label('Código')
                            ->required()
                            ->maxLength(32)
                            ->unique(ignoreRecord: true)
                            ->default(fn () => ShortLink::nuevoCodigo())
                            ->prefix(fn () => rtrim(config('app.url'), '/') . '/qr/')
                            ->helperText('Se genera uno al azar. Puedes ponerle el que quieras si va a ir escrito en un sitio donde se lea.')
                            ->rule('regex:/^[A-Za-z0-9-]+$/'),

                        Toggle::make('is_active')
                            ->label('Activo')
                            ->default(true)
                            ->helperText('Apagado, el código responde que ya no está activo en vez de llevar a ningún sitio.'),

                        DateTimePicker::make('expires_at')
                            ->label('Deja de servir el')
                            ->helperText('Opcional. Un cartel de un evento que ya pasó se apaga solo, sin que nadie tenga que acordarse.'),

                        Textarea::make('notes')
                            ->label('Notas')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('El código')
                    ->visibleOn('edit')
                    ->schema([
                        Placeholder::make('qr')
                            ->label('')
                            ->content(fn (?ShortLink $record) => $record
                                ? new HtmlString(self::tarjeta($record))
                                : ''),
                    ]),
            ]);
    }

    /** El QR, la dirección y lo que lleva escaneado, para imprimir o copiar. */
    private static function tarjeta(ShortLink $enlace): string
    {
        $qr = app(\App\Services\Qr\QrRenderer::class)->svg($enlace->url(), 160);
        $visitas = $enlace->visits()->count();

        return sprintf(
            '<div style="display:flex;gap:1.4rem;align-items:center;flex-wrap:wrap">'
            . '<div style="background:#fff;padding:.5rem;border-radius:8px">%s</div>'
            . '<div>'
            . '<p style="font-family:ui-monospace,Consolas,monospace;font-size:1.05rem;margin:0 0 .3rem">%s</p>'
            . '<p style="margin:0;color:rgb(107 114 128);font-size:.85rem">%s</p>'
            . '<p style="margin:.5rem 0 0;font-size:.85rem">%s</p>'
            . '</div></div>',
            $qr,
            e($enlace->url()),
            $visitas === 1 ? '1 visita' : $visitas . ' visitas',
            $enlace->vigente()
                ? 'Lleva a <strong>' . e($enlace->destinoCorto()) . '</strong>'
                : '<strong>No está activo</strong>: responde que el código ya no sirve.',
        );
    }
}
