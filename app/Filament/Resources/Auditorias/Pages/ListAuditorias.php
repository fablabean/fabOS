<?php

namespace App\Filament\Resources\Auditorias\Pages;

use App\Filament\Resources\Auditorias\AuditoriaResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAuditorias extends ListRecords
{
    protected static string $resource = AuditoriaResource::class;

    public function getSubheading(): ?string
    {
        return 'Las auditorías de calidad del laboratorio, internas y externas: cuándo fueron, qué resultó y en qué van las mejoras.';
    }

    protected function getHeaderActions(): array
    {
        return [
            // La del auditor: el formato del documento del proceso, sin ajustes ni hallazgos.
            Action::make('presentacion')
                ->label('Presentación INV-200')
                ->icon('heroicon-o-presentation-chart-bar')
                ->color('gray')
                ->tooltip('La versión para el auditor')
                ->url(fn () => route('calidad.caracterizacion'))
                ->openUrlInNewTab(),

            // La de trabajo: estados, ajustes y hallazgos. Nunca pública.
            Action::make('interna')
                ->label('Versión interna')
                ->icon('heroicon-o-document-magnifying-glass')
                ->color('gray')
                ->url(fn () => route('calidad.caracterizacion.interna'))
                ->openUrlInNewTab(),

            /*
             * Abrir la presentación al público, y volverla a cerrar.
             *
             * Para el día de la auditoría: el auditor la abre desde su equipo
             * sin cuenta. Pasada la visita se apaga y vuelve a ser del equipo.
             * Los enlaces a las evidencias siguen pidiendo sesión.
             */
            Action::make('publica')
                ->label(fn () => \App\Http\Controllers\CalidadController::presentacionPublica() ? 'Pública: sí' : 'Pública: no')
                ->icon(fn () => \App\Http\Controllers\CalidadController::presentacionPublica() ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
                ->color(fn () => \App\Http\Controllers\CalidadController::presentacionPublica() ? 'success' : 'gray')
                ->tooltip('Si la presentación INV-200 se ve sin iniciar sesión')
                ->visible(fn () => auth()->user()?->hasAnyRole([\App\Models\User::ROL_ADMINISTRADOR, \App\Models\User::ROL_SUPERADMIN]) ?? false)
                ->requiresConfirmation()
                ->modalHeading(fn () => \App\Http\Controllers\CalidadController::presentacionPublica()
                    ? 'Cerrar la presentación al público'
                    : 'Abrir la presentación al público')
                ->modalDescription(fn () => \App\Http\Controllers\CalidadController::presentacionPublica()
                    ? 'Volverá a pedir iniciar sesión.'
                    : 'Cualquiera con el enlace ' . route('calidad.caracterizacion') . ' la verá sin iniciar sesión, con las cifras y las auditorías registradas. Los enlaces a las evidencias del panel siguen pidiendo sesión, y la versión interna sigue siendo solo del equipo.')
                ->action(function () {
                    $abrir = ! \App\Http\Controllers\CalidadController::presentacionPublica();
                    \App\Models\Setting::put(\App\Http\Controllers\CalidadController::PRESENTACION_PUBLICA, $abrir, 'calidad');

                    \Filament\Notifications\Notification::make()->success()
                        ->title($abrir ? 'La presentación es pública' : 'La presentación vuelve a ser del equipo')
                        ->send();
                }),

            /*
             * Lo que operó antes de FabOS, estimado a mano. La presentación lo
             * suma en la portada y lo pone aparte en cada evidencia, marcado
             * como estimado: el auditor ve qué contó el sistema y qué no.
             */
            Action::make('historico')
                ->label('Cifras anteriores')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->visible(fn () => auth()->user()?->hasAnyRole([\App\Models\User::ROL_ADMINISTRADOR, \App\Models\User::ROL_SUPERADMIN]) ?? false)
                ->modalHeading('Cifras estimadas antes de FabOS')
                ->modalDescription('Lo que operó el laboratorio antes de que FabOS lo registrara. Se muestra como estimado («≈») junto a la cifra del sistema. Deja en blanco lo que no se sepa.')
                ->modalWidth('2xl')
                ->fillForm(fn () => [
                    'periodo' => \App\Http\Controllers\CalidadController::historico()['periodo'],
                    'valores' => \App\Http\Controllers\CalidadController::historico()['valores'],
                ])
                ->schema([
                    \Filament\Forms\Components\TextInput::make('periodo')
                        ->label('Periodo que cubre')
                        ->placeholder('2023 – agosto 2026')
                        ->maxLength(80),
                    \Filament\Schemas\Components\Grid::make(2)->schema(
                        collect(\App\Http\Controllers\CalidadController::CIFRAS_HISTORICAS)
                            // Texto y no número: un campo numérico del navegador
                            // descarta en silencio «1.200» o «≈300», y la cifra
                            // se perdía sin aviso. Aquí se toman solo los dígitos.
                            ->map(fn ($nombre, $clave) => \Filament\Forms\Components\TextInput::make("valores.{$clave}")
                                ->label($nombre)
                                ->inputMode('numeric')
                                ->rule('nullable')
                                ->rule('regex:/^\D{0,3}[\d.,\s]+\D{0,8}$/u')
                                ->validationMessages(['regex' => 'Escribe un número, por ejemplo 1200 o 1.200.'])
                                ->placeholder('—'))
                            ->values()
                            ->all()
                    ),
                ])
                ->action(function (array $data) {
                    $valores = collect($data['valores'] ?? [])
                        ->map(fn ($v) => preg_replace('/\D+/', '', (string) $v))
                        ->filter(fn ($v) => $v !== '')
                        ->map(fn ($v) => (int) $v)
                        ->all();

                    \App\Models\Setting::put(\App\Http\Controllers\CalidadController::HISTORICO, [
                        'periodo' => $data['periodo'] ?? null,
                        'valores' => $valores,
                    ], 'calidad');

                    // Lo que quedó, para que se note si algo no entró.
                    $nombres = \App\Http\Controllers\CalidadController::CIFRAS_HISTORICAS;
                    $visibles = collect($valores)->filter()->map(fn ($v, $k) => ($nombres[$k] ?? $k) . ': ' . number_format($v, 0, ',', '.'));

                    \Filament\Notifications\Notification::make()->success()
                        ->title('Cifras anteriores guardadas')
                        ->body($visibles->isEmpty()
                            ? 'Ninguna mayor que cero: la presentación no muestra cifras anteriores.'
                            : $visibles->join(' · '))
                        ->send();
                }),

            CreateAction::make()->label('Registrar auditoría'),
        ];
    }
}
