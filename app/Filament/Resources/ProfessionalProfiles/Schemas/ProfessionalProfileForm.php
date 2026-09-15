<?php

namespace App\Filament\Resources\ProfessionalProfiles\Schemas;

use App\Filament\Componentes\CampoDeTelefono;
use App\Models\ProfessionalProfile;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * La ficha de quien puede trabajar con el laboratorio (§5).
 *
 * Solo el nombre es obligatorio. Lo demás se va llenando según se consigue, y
 * la pantalla dice en todo momento qué falta para poder presentarlo: un
 * formulario que exija el RUT para dejar constancia de que existe un tallerista
 * es un formulario que nadie abre.
 */
class ProfessionalProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Quién es')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(160)
                            ->placeholder('Ana Pérez'),

                        TextInput::make('specialty')
                            ->label('Qué hace')
                            ->maxLength(160)
                            ->placeholder('Tallerista de textiles')
                            ->helperText('Es lo que se lee cuando hay que acordarse de a quién llamar.'),

                        Select::make('area_id')
                            ->label('Área')
                            ->relationship('area', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Todo el laboratorio'),

                        TextInput::make('email')
                            ->label('Correo')
                            ->email()
                            ->maxLength(160)
                            ->helperText('Sin correo no se le puede crear cuenta después: es la llave con la que se entra.'),

                        CampoDeTelefono::make('phone'),

                        TextInput::make('rate_note')
                            ->label('Lo que cobra')
                            ->maxLength(160)
                            ->placeholder('$180.000 la sesión de 4 horas')
                            ->helperText('Tal como lo dijo. Cada quien cobra por cosas distintas.'),

                        TextInput::make('portfolio_url')
                            ->label('Portafolio')
                            ->url()
                            ->maxLength(255)
                            ->placeholder('https://')
                            ->columnSpanFull(),

                        Textarea::make('notes')->label('Notas')->rows(2)->columnSpanFull(),
                    ]),

                Section::make('Con quién se firma')
                    ->description('Lo que la Universidad pide para inscribir a alguien como proveedor.')
                    ->columns(2)
                    ->schema([
                        Select::make('person_kind')
                            ->label('Tipo de persona')
                            ->options(ProfessionalProfile::PERSONAS)
                            ->live(),

                        Select::make('document_type')
                            ->label('Tipo de documento')
                            ->options(ProfessionalProfile::DOCUMENTOS)
                            ->live(),

                        TextInput::make('document_number')->label('Número')->maxLength(40),

                        TextInput::make('document_dv')
                            ->label('Dígito de verificación')
                            ->maxLength(1)
                            // Solo el NIT lo lleva; en una cedula no significa
                            // nada y pedirlo siempre invita a inventarlo.
                            ->visible(fn (Get $get) => $get('document_type') === 'NIT'),

                        TextInput::make('legal_name')
                            ->label('Razón social')
                            ->maxLength(180)
                            ->helperText('Como aparece en el RUT.')
                            ->visible(fn (Get $get) => $get('person_kind') === 'juridica'),

                        TextInput::make('representative')
                            ->label('Representante legal')
                            ->maxLength(120)
                            ->visible(fn (Get $get) => $get('person_kind') === 'juridica'),

                        TextInput::make('address')->label('Dirección')->maxLength(200),
                        TextInput::make('city')->label('Ciudad')->maxLength(120),

                        TextInput::make('country')
                            ->label('País')
                            ->maxLength(60)
                            ->placeholder('Colombia')
                            ->helperText('Solo si no es Colombia.'),
                    ]),

                Section::make('Impuestos')
                    ->description('Es lo que determina la retención. Si no está aquí, compras lo pide por WhatsApp y la contratación se detiene una semana.')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        Toggle::make('vat_liable')->label('Responsable de IVA'),

                        Select::make('tax_regime')
                            ->label('Régimen')
                            ->options(ProfessionalProfile::REGIMENES),

                        TextInput::make('ciiu_code')
                            ->label('Actividad económica (CIIU)')
                            ->maxLength(8)
                            ->placeholder('7410'),

                        TextInput::make('tax_responsibilities')
                            ->label('Responsabilidades')
                            ->maxLength(200)
                            ->placeholder('5 - Impto. renta y complementario')
                            ->helperText('Los códigos de la casilla 53 del RUT, tal como aparecen ahí.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Cómo se le paga')
                    ->description('El número de cuenta no se escribe aquí: va dentro de la certificación bancaria, que se adjunta abajo. Un número de cuenta en una columna se exporta, se filtra y acaba en un chat.')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('bank_name')->label('Banco')->maxLength(80),

                        Select::make('bank_account_kind')
                            ->label('Tipo de cuenta')
                            ->options(ProfessionalProfile::CUENTAS),
                    ]),

                Section::make('Seguridad social')
                    ->description('Es lo que pide la Universidad para contratar, no un control de asistencia. El pago mensual va como planilla adjunta: una fecha escrita aquí mentiría a los treinta días.')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('eps_name')->label('EPS')->maxLength(80),
                        TextInput::make('pension_fund')->label('Fondo de pensión')->maxLength(80),
                        TextInput::make('arl_name')->label('ARL')->maxLength(80),

                        Select::make('arl_risk_level')
                            ->label('Clase de riesgo')
                            ->options(ProfessionalProfile::RIESGOS),
                    ]),

                Section::make('Autorización de tratamiento de datos')
                    ->description('Ley 1581 de 2012. Aquí hay datos personales de alguien: hay que poder decir cuándo autorizó guardarlos y para qué, sin abrir un PDF. Es lo que se responde el día que pida que los borren.')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        DatePicker::make('consent_at')->label('Autorizó el'),

                        Select::make('consent_channel')
                            ->label('Por')
                            ->options(ProfessionalProfile::CANALES_DE_AUTORIZACION),

                        Textarea::make('consent_purpose')
                            ->label('Para qué autorizó')
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('Presentar su perfil a la Universidad para inscripción como proveedor.'),
                    ]),

                Section::make('En qué va')
                    ->columns(2)
                    ->schema([
                        Select::make('status')
                            ->label('Estado')
                            ->options(ProfessionalProfile::ESTADOS)
                            ->default('borrador')
                            ->required()
                            ->helperText('Presentado e inscrito los sellan las acciones del listado, con su fecha.'),

                        TextInput::make('vendor_code')
                            ->label('Código de proveedor')
                            ->maxLength(40)
                            ->helperText('El que devuelve la Universidad cuando lo inscribe.'),
                    ]),
            ]);
    }
}
