<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Temperature extends Model
{
    use HasFactory;

    protected $table = 'temperature';
    protected $primaryKey = 'T_id';
    public $timestamps = false; // Pas de created_at/updated_at

    protected $fillable = [
        'Point_id', // Attention : Le schéma utilise 'Point_id' ici !
        'temperature',
    ];

    protected $casts = [
        'temperature' => 'float',
    ];
    
    public function pointMesure()
    {
        // Attention : On utilise 'Point_id' comme clé étrangère
        return $this->belongsTo(PointMesure::class, 'Point_id'); 
    }
}