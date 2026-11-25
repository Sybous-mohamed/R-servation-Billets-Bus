<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringSchedule extends Model
{
    protected $fillable = [
        'route_id',
        'default_bus_id',
        'departure_time',
        'starts_on',
        'ends_on',
        'is_active',
    ];

    protected $casts = [
        'route_id' => 'integer',
        'default_bus_id' => 'integer',
        'starts_on' => 'date:Y-m-d',
        'ends_on' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    // Trajet associé au programme
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    // Bus affecté au programme
    public function bus(): BelongsTo
    {
        return $this->belongsTo(
            Bus::class,
            'default_bus_id'
        );
    }

    // Jours de fonctionnement
    public function days(): HasMany
    {
        return $this->hasMany(
            RecurringScheduleDay::class,
            'recurring_schedule_id'
        );
    }
}
