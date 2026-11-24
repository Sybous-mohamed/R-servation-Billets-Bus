<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Route extends Model
{
    //
    protected $fillable = [
        'route_name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function routeStops(): HasMany
    {
        return $this->hasMany(RouteStop::class)
            ->orderBy('stop_order');
    }

    public function routeFares(): HasMany
    {
        return $this->hasMany(RouteFare::class);
    }
}
