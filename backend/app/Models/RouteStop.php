<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteStop extends Model
{
    //
    protected $fillable = [
        'route_id',
        'stop_id',
        'stop_order',
        'estimated_offset_minutes',
    ];

    protected $casts = [
        'route_id' => 'integer',
        'stop_id' => 'integer',
        'stop_order' => 'integer',
        'estimated_offset_minutes' => 'integer',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(Stop::class);
    }
    public $timestamps = false;
}
