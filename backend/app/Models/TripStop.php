<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripStop extends Model
{
    protected $fillable = [
        'trip_id',
        'route_stop_id',
        'stop_order',
        'scheduled_arrival_at',
        'scheduled_departure_at',
        'actual_arrival_at',
        'actual_departure_at',
    ];

    public $timestamps = false;

    protected $casts = [
        'trip_id' => 'integer',
        'route_stop_id' => 'integer',
        'stop_order' => 'integer',
        'scheduled_arrival_at' => 'datetime',
        'scheduled_departure_at' => 'datetime',
        'actual_arrival_at' => 'datetime',
        'actual_departure_at' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function routeStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class);
    }
}
