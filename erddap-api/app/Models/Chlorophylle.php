<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chlorophylle extends Model
{
    use HasFactory;

    protected $table = 'chlorophylle_a';
    protected $primaryKey = 'chla_id';
    public $timestamps = false;

    protected $fillable = [
        'PM_id',
        'chla',
        'taux_incertitude',
    ];

    protected $casts = [
        'chla' => 'float',
    ];
    
    public function pointMesure()
    {
        return $this->belongsTo(PointMesure::class, 'PM_id');
    }
}