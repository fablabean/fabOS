<?php

namespace App\Models;

use App\Filament\Componentes\ArchivoPrivado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Un papel de un perfil profesional (§5).
 *
 * Archivo subido o enlace: obligar a subir el fichero hace que la gente
 * documente por fuera del sistema, y entonces el sistema deja de saber quién
 * tiene qué. Lo que se sube va al **disco privado**: aquí dentro hay cédulas,
 * RUT y certificaciones bancarias, y de eso no puede haber una URL adivinable.
 */
class ProfileDocument extends Model
{
    protected $fillable = [
        'profile_id', 'kind', 'title', 'file_path', 'url',
        'uploaded_by', 'issued_on', 'expires_on', 'notes',
    ];

    public const TIPOS = [
        'hoja_de_vida'     => 'Hoja de vida',
        'identidad'        => 'Documento de identidad',
        'rut'              => 'RUT',
        // El numero de cuenta vive aqui dentro, y solo aqui: en una columna se
        // exporta, se filtra y acaba en un chat.
        'banco'            => 'Certificación bancaria',
        'seguridad_social' => 'Planilla de seguridad social',
        'antecedentes'     => 'Antecedentes (Procuraduría, Contraloría, Policía)',
        'titulo'           => 'Título o certificación',
        'camara'           => 'Cámara de comercio',
        'autorizacion'     => 'Autorización de tratamiento de datos',
        'portafolio'       => 'Portafolio',
        'otro'             => 'Otro',
    ];

    protected function casts(): array
    {
        return [
            'issued_on'  => 'date',
            'expires_on' => 'date',
        ];
    }

    /**
     * Quitar el documento se lleva su archivo.
     *
     * Ninguna restricción de la base borra ficheros: sin esto, cada documento
     * corregido dejaría el anterior en el disco para siempre, y serían cédulas.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $documento) {
            if ($documento->file_path && Storage::disk('local')->exists($documento->file_path)) {
                Storage::disk('local')->delete($documento->file_path);
            }
        });
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ProfessionalProfile::class, 'profile_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function tipoLegible(): string
    {
        return self::TIPOS[$this->kind] ?? $this->kind;
    }

    /** Hay algo de verdad detrás: un archivo o un enlace. */
    public function existe(): bool
    {
        return filled($this->url) || filled($this->file_path);
    }

    /** Caducó. Los antecedentes y la planilla vencen; un título no. */
    public function estaVencido(): bool
    {
        return $this->expires_on !== null && $this->expires_on->isPast();
    }

    /** Dónde está de verdad: archivo privado o enlace externo. */
    public function enlace(): ?string
    {
        if ($this->url) {
            return $this->url;
        }

        if (! $this->file_path || ! Storage::disk('local')->exists($this->file_path)) {
            return null;
        }

        // Se baja con el titulo, pero con la extension del archivo: «RUT de Ana
        // Perez.pdf», no «RUT de Ana Perez» a secas, que el sistema de quien lo
        // recibe no sabe con que abrir.
        $extension = pathinfo($this->file_path, PATHINFO_EXTENSION);
        $nombre = $this->title ?: basename($this->file_path);

        if ($extension && ! str_ends_with(mb_strtolower($nombre), '.' . mb_strtolower($extension))) {
            $nombre .= '.' . $extension;
        }

        return ArchivoPrivado::url($this->file_path, $nombre, descargar: true);
    }
}
