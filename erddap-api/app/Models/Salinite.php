<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Salinite extends Model
{
    use HasFactory;

    protected $table = 'salinite';
    protected $primaryKey = 'S_id';
    public $timestamps = false; // Pas de created_at/updated_at

    protected $fillable = [
        'PM_id',
        'sss',
        'sss_dif',
    ];

    protected $casts = [
        'sss' => 'float',
        'sss_dif' => 'float',
    ];
    
    public function pointMesure()
    {
        return $this->belongsTo(PointMesure::class, 'PM_id');
    }
}