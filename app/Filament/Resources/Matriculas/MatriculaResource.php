<?php

namespace App\Filament\Resources\Matriculas;

use App\Filament\Componentes\CampoDeTelefono;
use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Matriculas\Pages\CreateMatricula;
use App\Filament\Resources\Matriculas\Pages\EditMatricula;
use App\Filament\Resources\Matriculas\Pages\ListMatriculas;
use App\Models\Matricula;
use App\Models\UserCategory;
use App\Services\Ledger\LedgerService;
use App\Services\Personas\MatriculaService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Educación Continua matricula a su gente (§5, §12).
 *
 * Es la puerta por la que un bootcamp, un curso o un diplomado entran al
 * laboratorio: quién, en qué programa, hasta cuándo. Al matricular nace la
 * cuenta si no existía, la persona recibe la subcategoría del programa —que
 * decide tarifa, dotación y con cuánto saldo arranca— y queda escrito quién
 * la anotó. Es una sección propia para poder abrírsela a Educación Continua
 * sin darle el resto de Personas.
 */
class MatriculaResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Matricula::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $modelLabel = 'Matrícula';

    protected static ?string $pluralModelLabel = 'Matrículas';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Formación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Educación continua';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Quién')
                ->description('Si ya tiene cuenta con ese correo, se usa esa. Idealmente el correo corporativo: es por el que después se filtra.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(120)
                        ->hiddenOn('edit'),

                    TextInput::make('email')
                        ->label('Correo')
                        ->email()
                        ->required()
                        ->maxLength(160)
                        ->hiddenOn('edit'),

                    CampoDeTelefono::make('phone')->hiddenOn('edit'),

                    TextInput::make('persona')
                        ->label('Persona')
                        ->disabled()
                        ->dehydrated(false)
                        ->visibleOn('edit')
                        ->formatStateUsing(fn (?Matricula $record) => $record?->user
                            ? $record->user->name . ' · ' . $record->user->email
                            : null)
                        ->columnSpanFull(),
                ]),

            Section::make('El programa')
                ->columns(2)
                ->schema([
                    Select::make('user_category_id')
                        ->label('Programa')
                        ->options(fn () => UserCategory::deEstudiante()
                            ->where('slug', '!=', 'estudiante')
                            ->orderBy('position')
                            ->get()
                            ->mapWithKeys(fn (UserCategory $c) => [$c->id => $c->name . ' · nace con '
                                . number_format($c->welcome_minor / config('fabos.currency.minor_units'), 0) . ' ' . config('fabos.currency.code')]))
                        ->required()
                        ->helperText('Es la subcategoría de estudiante que recibe: decide su tarifa, su dotación y con cuánto saldo arranca. Las cifras se editan en Personas → Categorías.'),

                    TextInput::make('program_name')
                        ->label('Nombre del programa')
                        ->required()
                        ->maxLength(160)
                        ->placeholder('Bootcamp de IoT 2026-2'),

                    DatePicker::make('starts_on')->label('Empieza'),

                    DatePicker::make('ends_on')
                        ->label('Termina')
                        ->helperText('Al cerrarla, la persona vuelve a estudiante general. El saldo que tenga se queda.'),

                    Textarea::make('notes')->label('Notas')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = config('fabos.lab.timezone');

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Quién')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Matricula $r) => $r->user?->email),

                TextColumn::make('category.name')
                    ->label('Programa')
                    ->badge()
                    ->color('info')
                    ->description(fn (Matricula $r) => $r->program_name),

                TextColumn::make('ends_on')
                    ->label('Hasta')
                    ->date('d/m/Y')
                    ->placeholder('sin fecha')
                    ->description(fn (Matricula $r) => $r->vigente() ? 'vigente' : 'terminada')
                    ->color(fn (Matricula $r) => $r->vigente() ? null : 'gray'),

                TextColumn::make('saldo')
                    ->label('Saldo')
                    ->alignEnd()
                    ->state(fn (Matricula $r) => $r->user
                        ? number_format(app(LedgerService::class)->saldoDe($r->user) / config('fabos.currency.minor_units'), 2, ',', '.') . ' ' . config('fabos.currency.code')
                        : null)
                    ->toggleable(),

                TextColumn::make('registeredBy.name')->label('Anotó')->placeholder('—')->toggleable(),

                TextColumn::make('created_at')
                    ->label('Desde')
                    ->formatStateUsing(fn ($state) => $state?->timezone($tz)->format('d/m/Y'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user_category_id')
                    ->label('Programa')
                    ->options(fn () => UserCategory::deEstudiante()->orderBy('position')->pluck('name', 'id')),

                TernaryFilter::make('vigente')
                    ->label('Vigente')
                    ->queries(
                        true: fn ($q) => $q->vigentes(),
                        false: fn ($q) => $q->whereDate('ends_on', '<=', Matricula::hoy()),
                    ),
            ])
            ->recordActions([
                Action::make('cerrar')
                    ->label('Terminó')
                    ->iconButton()
                    ->tooltip('Cerrar la matrícula: vuelve a estudiante general')
                    ->icon('heroicon-o-flag')
                    ->color('gray')
                    ->visible(fn (Matricula $r) => $r->vigente())
                    ->requiresConfirmation()
                    ->modalDescription('La matrícula se cierra hoy y la persona vuelve a estudiante general, salvo que tenga otro programa vigente. El saldo que tenga se queda.')
                    ->action(function (Matricula $record) {
                        app(MatriculaService::class)->cerrar($record);

                        Notification::make()->title('Matrícula cerrada')->success()->send();
                    }),

                Action::make('persona')
                    ->label('Ver su ficha')
                    ->iconButton()
                    ->tooltip('Ver la ficha de la persona')
                    ->icon('heroicon-o-user')
                    ->color('gray')
                    ->url(fn (Matricula $r) => '/admin/users/' . $r->user_id . '/edit'),

                EditAction::make()->iconButton()->tooltip('Editar'),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListMatriculas::route('/'),
            'create' => CreateMatricula::route('/create'),
            'edit'   => EditMatricula::route('/{record}/edit'),
        ];
    }
}
