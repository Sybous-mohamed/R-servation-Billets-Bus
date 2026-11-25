<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringScheduleDay extends Model
{
    protected $table = 'recurring_schedule_days';

    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = [
        'recurring_schedule_id',
        'weekday',
    ];

    protected $casts = [
        'recurring_schedule_id' => 'integer',
        'weekday' => 'integer',
    ];

    public function recurringSchedule(): BelongsTo
    {
        return $this->belongsTo(
            RecurringSchedule::class,
            'recurring_schedule_id'
        );
    }
}
