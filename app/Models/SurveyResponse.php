<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lo que respondió una persona en la encuesta de una edición (§9). */
class SurveyResponse extends Model
{
    protected $fillable = ['course_edition_id', 'enrollment_id', 'answers', 'submitted_at'];

    protected function casts(): array
    {
        return [
            'answers'      => 'array',
            'submitted_at' => UtcDateTime::class,
        ];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(CourseEdition::class, 'course_edition_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
