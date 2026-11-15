<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bus extends Model
{
    
protected $fillable = [
    'registration_number',
    'bus_name',
    'capacity',
    'status'
];

function seats(){
    return $this->hasMany(BusSeat::class);
}
public function equipments(){
        return $this->belongsToMany(Equipment::class,'bus_equipments');
    }
public function maintenances(){
    return $this->hasMany(BusMaintenance::class);
}

}