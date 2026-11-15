<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusSeat extends Model
{
    protected $fillable = [
        'bus_id',
        'seat_number',
        'row_number',
        'column_number',
        'seat_type',
        'is_active',
    ];

protected $casts = [
        'is_active' => 'boolean',
    ];

public function bus()
    {
        return $this->belongsTo(Bus::class);
    }
}
