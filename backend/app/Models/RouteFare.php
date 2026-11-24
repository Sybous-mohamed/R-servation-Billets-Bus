<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteFare extends Model
{
    //
    protected $fillable = [
        'route_id',
        'from_route_stop_id',
        'to_route_stop_id',
        'amount',
        'currency',
    ];

    protected $casts = [
        'route_id' => 'integer',
        'from_route_stop_id' => 'integer',
        'to_route_stop_id' => 'integer',
        'amount' => 'decimal:2',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function fromRouteStop(): BelongsTo
    {
        return $this->belongsTo(
            RouteStop::class,
            'from_route_stop_id'
        );
    }

    public function toRouteStop(): BelongsTo
    {
        return $this->belongsTo(
            RouteStop::class,
            'to_route_stop_id'
        );
    }
    public $timestamps = false;
}
