<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusMaintenance extends Model
{
    protected $fillable = [
        'bus_id',
        'starts_at',
        'ends_at',
        'reason',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function bus()
{
    return $this->belongsTo(Bus::class);}
}
