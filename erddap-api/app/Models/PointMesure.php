<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PointMesure extends Model
{
    use HasFactory;

    // Assurez-vous que le nom de la table est correct (sae3.01_pointmesure ou pointmesure)
    protected $table = 'pointmesure'; 
    protected $primaryKey = 'PM_id';
    public $timestamps = false; // Pas de created_at/updated_at

    protected $fillable = [
        'latitude',
        'longitude',
        'dateMesure',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'dateMesure' => 'date',
    ];

    public function salinite()
    {
        // Assurez-vous que la clé de liaison est correcte (PM_id)
        return $this->hasOne(Salinite::class, 'PM_id');
    }

    public function temperature(){
    // Utilisation de PM_id comme clé étrangère dans la table temperature
    return $this->hasOne(Temperature::class, 'Point_id', 'PM_id');
    }

    public function chlorophylle()
    {
        return $this->hasOne(Chlorophylle::class, 'PM_id');
    }
}