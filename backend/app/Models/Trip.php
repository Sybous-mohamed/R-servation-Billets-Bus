<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    protected $fillable = [
        'route_id',
        'recurring_schedule_id',
        'bus_id',
        'departure_at',
        'expected_arrival_at',
        'actual_departure_at',
        'actual_arrival_at',
        'status',
        'delay_minutes',
    ];

    protected $casts = [
        'route_id' => 'integer',
        'recurring_schedule_id' => 'integer',
        'bus_id' => 'integer',
        'departure_at' => 'datetime',
        'expected_arrival_at' => 'datetime',
        'actual_departure_at' => 'datetime',
        'actual_arrival_at' => 'datetime',
        'delay_minutes' => 'integer',
    ];

    // Trajet du voyage
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    // Bus du voyage
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    // Programme qui a généré le voyage
    public function recurringSchedule(): BelongsTo
    {
        return $this->belongsTo(RecurringSchedule::class);
    }

    // Arrêts du voyage
    public function tripStops(): HasMany
    {
        return $this->hasMany(TripStop::class);
    }
}
