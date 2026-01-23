<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\PointMesure;
use App\Models\Salinite;
use App\Models\Temperature;
use App\Models\Chlorophylle;
use Carbon\Carbon;
use App\Enums\ZoneMaritime;

class DataStatsController extends Controller
{
    protected $allowedDatasets = [
        'noaacwBLENDEDsstDNDaily' => [
            'service' => 'griddap', 
            'variable' => 'analysed_sst', 
            'dimensions' => ['time', 'latitude', 'longitude'],
            'model' => 'temperature',
            'bdd_field' => 'temperature',
            'unit' => 'degree_C',
            'res' => 0.05,
        ],
        'noaacwSMOSsss3day' => [
            'service' => 'griddap', 
            'variable' => 'sss',
            'dimensions' => ['time', 'latitude', 'longitude'],
            'model' => 'salinite',
            'bdd_field' => 'sss',
            'unit' => 'PSU',
            'res' => 0.25,
        ],
        'noaacwNPPVIIRSchlaDaily' => [
            'service' => 'griddap', 
            'variable' => 'chlor_a',
            'dimensions' => ['time', 'altitude', 'latitude', 'longitude'],
            'model' => 'chlorophylle',
            'bdd_field' => 'chla',
            'unit' => 'mg_m3',
            'res' => 0.035,
        ],
    ];

    /**
     * Main stats method for charts
     */
    public function getStats(Request $request)
    {
        \Log::info('API Stats Request reçue:', $request->all());
        
        // Augmentation du temps d'exécution pour éviter le timeout 500 sur les longues périodes
        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        try {
            $zoneSlug = $request->query('zone');
            $dateDebut = $request->query('date_debut');
            $dateFin = $request->query('date_fin');

            if (!$zoneSlug || !$dateDebut || !$dateFin) {
                return response()->json(['error' => 'Paramètres manquants'], 400);
            }

            // Récupération sécurisée de la zone
            $zone = null;
            if (class_exists(ZoneMaritime::class)) {
                $zone = collect(ZoneMaritime::cases())->first(fn($z) => $z->slug() === $zoneSlug);
            }
            
            if (!$zone) {
                // Fallback si l'Enum échoue ou zone inconnue
                return response()->json(['error' => "Zone introuvable: $zoneSlug"], 404);
            }

            $bbox = $zone->boundingBox();
            // Sécurité : Vérifier que la bbox est valide
            if (!is_array($bbox) || count($bbox) < 4) {
                return response()->json(['error' => 'Configuration de zone invalide (bbox manquante)'], 500);
            }
            // Correction : On s'assure que le tableau est indexé (0, 1, 2, 3) pour éviter "Undefined array key 0"
            $bbox = array_values($bbox);
            // Indices: 0=latMin, 1=latMax, 2=lonMin, 3=lonMax
            $centerLatRaw = round(($bbox[0] + $bbox[1]) / 2, 2);
            $centerLonRaw = round(($bbox[2] + $bbox[3]) / 2, 2);
            \Log::info("Zone: $zoneSlug, Center: $centerLatRaw, $centerLonRaw");

            $results = ['dates' => [], 'temperature' => [], 'salinite' => [], 'chlorophylle' => []];
            $start = Carbon::parse($dateDebut);
            $end = Carbon::parse($dateFin);

            // Génération de la liste des dates
            for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                $results['dates'][] = $date->toDateString();
            }

            foreach ($this->allowedDatasets as $dsId => $config) {
                // Ajustement aux coordonnées de la grille (snapping) pour éviter les nulls NOAA
                $res = $config['res'] ?? 0.01;
                // Arrondi à 5 décimales pour éviter les erreurs de précision flottante
                $lat = round(round($centerLatRaw / $res) * $res, 5);
                $lon = round(round($centerLonRaw / $res) * $res, 5);

                // Optimisation : Récupération groupée des données en base pour éviter le N+1 requêtes
                // Utilisation d'une plage (epsilon) pour palier aux imprécisions des flottants en BDD
                $existingPoints = PointMesure::whereBetween('latitude', [$lat - 0.0001, $lat + 0.0001])
                    ->whereBetween('longitude', [$lon - 0.0001, $lon + 0.0001])
                    ->whereBetween('dateMesure', [$start->toDateString(), $end->toDateString()])
                    ->with($config['model'])
                    ->get();
                
                // Groupement par date pour gérer les éventuels doublons de points
                $pointsByDate = $existingPoints->groupBy(fn($pm) => substr($pm->dateMesure, 0, 10));

                foreach ($results['dates'] as $dateStr) {
                    $val = null;
                    $found = false;

                    if ($pointsByDate->has($dateStr)) {
                        // On parcourt tous les points trouvés pour cette date (cas des doublons)
                        foreach ($pointsByDate->get($dateStr) as $point) {
                            $relation = $config['model'];
                            // Si la relation existe, on prend la valeur et on arrête de chercher
                            if ($point->$relation) {
                                $val = $point->$relation->{$config['bdd_field']};
                                $found = true;
                                break;
                            }
                        }
                    }

                    // Si pas trouvé en base, alors seulement on fetch l'API
                    if (!$found) {
                        // Optimisation : Ne pas interroger l'API pour le futur (évite les requêtes inutiles)
                        if ($dateStr > date('Y-m-d')) {
                            $val = null;
                        } else {
                            $val = $this->fetchAndStore($dsId, $lat, $lon, $dateStr);
                        }
                    }

                    $results[$config['model']][] = $val;
                }
            }

            return response()->json($results);
        } catch (\Throwable $e) {
            \Log::error("Erreur DataStatsController: " . $e->getMessage());
            return response()->json(['error' => 'Erreur Serveur', 'message' => $e->getMessage()], 500);
        }
    }


    private function getOrFetchValue($datasetId, $lat, $lon, $dateStr)
    {
        $config = $this->allowedDatasets[$datasetId];
        
        //On cherche dans la bdd
        $point = PointMesure::whereBetween('latitude', [$lat - 0.0001, $lat + 0.0001])
            ->whereBetween('longitude', [$lon - 0.0001, $lon + 0.0001])
            ->whereDate('dateMesure', $dateStr)
            ->first();

        if ($point) {
            $relation = $config['model'];
            $measure = $point->$relation()->first();
            if ($measure) return $measure->{$config['bdd_field']};
        }

        // requete api si les données ne figurent pas dans la bdd
        return $this->fetchAndStore($datasetId, $lat, $lon, $dateStr);
    }

    private function fetchAndStore($datasetId, $lat, $lon, $dateStr)
    {
        $config = $this->allowedDatasets[$datasetId];
        $time = $dateStr . 'T12:00:00Z';
        
        $queryParts = [];
        foreach ($config['dimensions'] as $dim) {
            if ($dim === 'time') {
                $queryParts[] = "[($time):1:($time)]";
            } elseif ($dim === 'altitude') {
                $queryParts[] = "[(0.0):1:(0.0)]";
            } elseif ($dim === 'latitude' || $dim === 'longitude') {
                // Utilisation d'une plage (epsilon) pour éviter les erreurs d'arrondi flottant
                // et s'assurer de tomber sur un point de grille valide
                $val = ($dim === 'latitude') ? $lat : $lon;
                $epsilon = 0.001;
                $min = $val - $epsilon;
                $max = $val + $epsilon;
                $queryParts[] = "[($min):1:($max)]";
            }
        }

        $url = "https://coastwatch.noaa.gov/erddap/griddap/{$datasetId}.json?" . $config['variable'] . implode('', $queryParts);
        \Log::info("Appel NOAA URL: $url");

        try {
            $response = Http::withOptions(['verify' => false])->timeout(10)->get($url);
            \Log::info("Réponse NOAA Code: " . $response->status());
            
            if ($response->successful()) {
                $data = $response->json();
                
                $valeur = null;

                // Extraction sécurisée de la valeur si elle existe
                if (!empty($data['table']['rows']) && is_array($data['table']['rows'])) {
                    $columnNames = $data['table']['columnNames'] ?? [];
                    $idx = array_search($config['variable'], $columnNames);
                    $firstRow = $data['table']['rows'][0] ?? [];
                    if ($idx !== false && isset($firstRow[$idx])) {
                        $valeur = $firstRow[$idx];
                    }
                }

                // IMPORTANT : On sauvegarde le résultat (valeur ou NULL) pour le cache
                // Cela empêche de re-requêter l'API indéfiniment pour des données inexistantes
                $this->saveToMysql($datasetId, $lat, $lon, $dateStr, $valeur);
                
                return ($valeur !== null) ? (float)$valeur : null;
            } elseif ($response->status() === 404) {
                // Si 404 (pas de données sur la grille), on sauvegarde NULL pour éviter de re-requêter inutilement
                $this->saveToMysql($datasetId, $lat, $lon, $dateStr, null);
            }
        } catch (\Exception $e) {
            \Log::error("NOAA Fail for $datasetId: " . $e->getMessage());
        }
        return null;
    }

    protected function saveToMysql($datasetId, $lat, $lon, $dateStr, $value)
    {
        $config = $this->allowedDatasets[$datasetId];
        
        // Recherche floue pour éviter de créer un doublon si un point existe déjà très proche
        $pointMesure = PointMesure::whereBetween('latitude', [$lat - 0.0001, $lat + 0.0001])
            ->whereBetween('longitude', [$lon - 0.0001, $lon + 0.0001])
            ->whereDate('dateMesure', $dateStr)
            ->first();

        // Si pas trouvé, on crée le point (métadonnée) pour y attacher ensuite la mesure
        if (!$pointMesure) {
            $pointMesure = PointMesure::create(['latitude' => $lat, 'longitude' => $lon, 'dateMesure' => $dateStr]);
        }

        // On garde null si c'est null, sinon on cast en float
        $val = ($value !== null) ? (float)$value : null;

        try {
            if ($config['model'] === 'temperature') {
                // Correction : Utilisation de Point_id selon le modèle Temperature
                Temperature::updateOrCreate(['Point_id' => $pointMesure->PM_id], ['temperature' => $val]);
            } elseif ($config['model'] === 'salinite') {
                Salinite::updateOrCreate(['PM_id' => $pointMesure->PM_id], ['sss' => $val]);
            } elseif ($config['model'] === 'chlorophylle') {
                Chlorophylle::updateOrCreate(['PM_id' => $pointMesure->PM_id], ['chla' => $val]);
            }
        } catch (\Exception $e) {
            \Log::error("SQL Save Fail: " . $e->getMessage());
        }
    }

    /**
     * Scanner pour trouver des données valides (Debug)
     */
    public function findValidData(Request $request)
    {
        $datasetId = $request->query('datasetId', 'noaacwSMOSsssDaily');
        $lat = (float)$request->query('lat', 15.0);
        $lon = (float)$request->query('lon', -40.0);
        $config = $this->allowedDatasets[$datasetId] ?? null;

        if (!$config) return response()->json(['error' => 'Dataset invalide']);

        for ($i = 1; $i <= 30; $i++) {
            $time = now()->subDays($i)->startOfDay()->format('Y-m-d\T00:00:00\Z');
            
            $url = "https://coastwatch.noaa.gov/erddap/griddap/{$datasetId}.json?{$config['variable']}";
            $url .= "[({$time}):1:({$time})]";
            if ($datasetId === 'noaacwSMOSsssDaily') $url .= "[(0.0):1:(0.0)]";
            $url .= "[({$lat}):1:({$lat})][({$lon}):1:({$lon})]";

            try {
                $response = Http::timeout(10)->withOptions(['verify' => false])->get($url);
                if ($response->successful()) {
                    $resData = $response->json();
                    $rows = $resData['table']['rows'];
                    $colIndex = array_search($config['variable'], $resData['table']['columnNames']);
                    $valeur = ($rows && isset($rows[0][$colIndex])) ? $rows[0][$colIndex] : null;

                    if ($valeur !== null && is_numeric($valeur)) {
                        return response()->json([
                            'status' => 'SUCCESS',
                            'message' => "Donnée trouvée après $i tentatives !",
                            'valeur' => $valeur,
                            'date' => $time,
                            'coords' => "[$lat, $lon]",
                            'test_url' => url("/api/datasets/{$datasetId}?latMin={$lat}&lonMin={$lon}&time={$time}")
                        ]);
                    }
                }
            } catch (\Exception $e) { continue; }
        }

        return response()->json(['status' => 'FAILED', 'message' => 'Aucune donnée numérique sur 30 jours.']);
    }

    /**
     * Récupère les points pour la Carte (Filtré)
     */
    public function getAllStoredPoints()
    {
        try {
            $points = PointMesure::with(['temperature', 'salinite'])
                ->where(function($query) {
                    $query->whereHas('temperature', function($q) { $q->whereNotNull('temperature'); })
                          ->orWhereHas('salinite', function($q) { $q->whereNotNull('sss'); });
                })->get();

            return response()->json(['status' => 'success', 'count' => $points->count(), 'points' => $points]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * API principale NOAA / Cache (Debug/Fetch direct)
     */
    public function getDatasetData(Request $request, $datasetId)
    {
        if (!isset($this->allowedDatasets[$datasetId])) {
            return response()->json(['error' => 'Dataset inconnu'], 404);
        }

        $config = $this->allowedDatasets[$datasetId];
        // Snapping aux coordonnées de la grille
        $lat = round(round((float)$request->get('latMin', 45.0) / $config['res']) * $config['res'], 5);
        $lon = round(round((float)$request->get('lonMin', 0.0) / $config['res']) * $config['res'], 5);
        $time = $request->get('time', now()->subDays(4)->startOfDay()->format('Y-m-d\T00:00:00\Z'));

        // Tentative via cache ou fetch
        $valeur = $this->getOrFetchValue($datasetId, $lat, $lon, Carbon::parse($time)->toDateString());

        return response()->json([
            'source' => 'NOAA ERDDAP / Cache',
            'data' => ['valeur' => $valeur, 'date' => $time, 'latitude' => $lat, 'longitude' => $lon]
        ]);
    }
}