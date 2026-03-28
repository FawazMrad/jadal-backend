<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyQuestion extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'survey_id',
        'question_text',
        'type',
        'options',
        'order_index',
    ];

    protected function casts(): array
    {
        return [
            'options'     => 'array',
            'order_index' => 'integer',
            'type'        => 'string',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }
}
