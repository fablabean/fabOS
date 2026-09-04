<?php

namespace App\Models;

use App\Models\Concerns\TieneMaterialDeApoyo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una pantalla de teoria de un curso (§9).
 *
 * Cortas y en orden. Un manual de veinte paginas no lo lee nadie antes de un
 * examen; seis pantallas de dos minutos, si.
 */
class CourseLesson extends Model
{
    use TieneMaterialDeApoyo;

    protected $fillable = ['course_id', 'position', 'title', 'body', 'media_path', 'video_url'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
