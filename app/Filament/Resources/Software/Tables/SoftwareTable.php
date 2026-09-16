<?php

namespace App\Filament\Resources\Software\Tables;

use App\Models\Software;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ordenada por lo que vence antes (§19).
 *
 * No por nombre: a esta pantalla no se entra a leer el inventario, se entra
 * porque algo hay que renovar. Lo que no tiene fecha va al final, que es donde
 * corresponde a lo que no corre prisa.
 */
class SoftwareTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw('renueva_el is null')
                ->orderBy('renueva_el'))
            ->columns([
                TextColumn::make('nombre')
                    ->label('Programa')
                    ->searchable()
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (Software $r) => collect([$r->fabricante, $r->descripcion])
                        ->filter()->implode(' · ')),

                TextColumn::make('tipo')
                    ->label('Dónde')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state) => Software::TIPOS[$state] ?? $state)
                    ->toggleable(),

                TextColumn::make('modelo_licencia')
                    ->label('Licencia')
                    ->formatStateUsing(fn (?string $state) => Software::MODELOS[$state] ?? $state)
                    ->color('gray')
                    ->toggleable(),

                /*
                 * Los puestos, con los ocupados delante.
                 *
                 * «3 de 5» contesta de un vistazo la pregunta con la que se
                 * abre el semestre; el numero de comprados a secas, no.
                 */
                TextColumn::make('puestos')
                    ->label('Puestos')
                    ->alignEnd()
                    ->state(fn (Software $r) => $r->puestos === null
                        ? $r->puestosOcupados().' asignados'
                        : $r->puestosOcupados().' de '.$r->puestos)
                    ->color(fn (Software $r) => $r->seFueDePuestos() ? 'danger' : null)
                    ->tooltip(fn (Software $r) => $r->seFueDePuestos()
                        ? 'Hay más gente usándolo que puestos pagados'
                        : null),

                TextColumn::make('instalaciones_count')
                    ->label('Equipos')
                    ->counts('instalaciones')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('costo')
                    ->label('Costo')
                    ->alignEnd()
                    ->state(fn (Software $r) => $r->costo === null
                        ? null
                        : config('fabos.money.symbol').number_format($r->costo, 0, ',', '.'))
                    ->description(fn (Software $r) => Software::CICLOS[$r->ciclo] ?? null)
                    ->placeholder('sin cifra')
                    ->toggleable(),

                /*
                 * Cuando se renueva, y lo que eso significa hoy.
                 *
                 * La fecha sola obliga a hacer la resta mentalmente. «En 12
                 * dias» o «vencio hace 3» se lee sin pensar, que es lo que
                 * hace que alguien actue.
                 */
                TextColumn::make('renueva_el')
                    ->label('Se renueva')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('sin fecha')
                    ->description(fn (Software $r) => self::cuantoFalta($r))
                    ->color(fn (Software $r) => match ($r->comoEsta()) {
                        'vencido' => 'danger',
                        'por_renovar' => 'warning',
                        default => null,
                    }),

                TextColumn::make('responsable.name')
                    ->label('Responsable')
                    // Sin responsable la renovacion es de nadie: se marca.
                    ->placeholder('sin asignar')
                    ->color(fn (Software $r) => $r->responsable_id ? null : 'danger')
                    ->toggleable(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (Software $r) => match ($r->comoEsta()) {
                        'baja' => 'Dado de baja',
                        'vencido' => 'Vencido',
                        'por_renovar' => 'Toca renovar',
                        default => 'Al día',
                    })
                    ->color(fn (Software $r) => match ($r->comoEsta()) {
                        'baja' => 'gray',
                        'vencido' => 'danger',
                        'por_renovar' => 'warning',
                        default => 'success',
                    }),
            ])
            ->filters([
                SelectFilter::make('tipo')->label('Dónde corre')->options(Software::TIPOS),
                SelectFilter::make('modelo_licencia')->label('Licencia')->options(Software::MODELOS),
                SelectFilter::make('area_id')->label('Área')->relationship('area', 'name'),
                SelectFilter::make('responsable_id')->label('Responsable')->relationship('responsable', 'name'),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(Software::ESTADOS)
                    // Lo dado de baja no estorba salvo que se busque: la lista
                    // se abre para vigilar lo que se esta pagando.
                    ->default('activo'),

                Filter::make('por_renovar')
                    ->label('Solo lo que toca renovar')
                    ->query(fn (Builder $query) => $query->porRenovar()),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Editar'),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateHeading('Todavía no hay software apuntado')
            ->emptyStateDescription('Aquí van los programas instalados y las suscripciones. Lo que de verdad hace falta apuntar es cuándo se renueva cada uno: es lo que evita que una licencia caduque en mitad de un semestre.');
    }

    /** La resta hecha, que es lo que se lee sin pensar. */
    private static function cuantoFalta(Software $software): ?string
    {
        $dias = $software->diasParaRenovar();

        if ($dias === null) {
            return null;
        }

        return match (true) {
            $dias < 0 => 'venció hace '.abs($dias).' días',
            $dias === 0 => 'vence hoy',
            $dias === 1 => 'mañana',
            default => 'en '.$dias.' días',
        };
    }
}
