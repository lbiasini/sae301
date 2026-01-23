<?php
// routes/api.php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DataStatsController;
use App\Enums\ZoneMaritime;
use App\Http\Controllers\Api\DataController;
//use App\Models\PointMesure;

Route::middleware('api')->group(function () {
    // 1. Routes pour la Carte

    Route::get('/map/discovery', [DataController::class, 'getDiscoveryData']);

    Route::get('/map-points', [DataController::class, 'getAllStoredPoints']);
    // Route principale pour récupérer les données des datasets
    // Utilise l'ID du dataset (SST ou Salinité) comme paramètre.
    // Exemple : /api/datasets/noaacwSMAPSSSDaily?time=...&latMin=...
    Route::get('/datasets/{datasetId}', [DataStatsController::class, 'getDatasetData']);
    Route::get('/stats', [DataStatsController::class, 'getStats']);
    Route::get('/zones', function () {
    // On transforme l'Enum en une liste utilisable par le FrontEnd
    $zones = collect(ZoneMaritime::cases())->map(fn($zone) => [
        'name' => $zone->value,
        'slug' => $zone->slug(),
        'bbox' => $zone->boundingBox(),
    ]);
    return response()->json($zones)->header('Access-Control-Allow-Origin', '*');
    });
});