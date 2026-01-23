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
use Illuminate\Support\Facades\DB;
use App\Enums\ZoneMaritime;

class DataController extends Controller
{
    protected $allowedDatasets = [
        'noaacwBLENDEDsstDNDaily' => [
            'variable' => 'analysed_sst', 
            'model' => 'temperature',
            'bdd_field' => 'temperature',
            'res' => 0.05,
            'dimensions' => ['time', 'latitude', 'longitude']
        ],
        // REVISION : Utilisation du dataset journalier (Daily) pour correspondre aux graphiques
        'noaacwSMOSsss3day' => [
            'variable' => 'sss',
            'model' => 'salinite',
            'bdd_field' => 'sss',
            'res' => 0.25,
            'dimensions' => ['time', 'latitude', 'longitude']
        ],
        'noaacwNPPVIIRSchlaDaily' => [
            'variable' => 'chlor_a',
            'model' => 'chlorophylle',
            'bdd_field' => 'chla',
            'res' => 0.0375,
            'dimensions' => ['time', 'altitude', 'latitude', 'longitude']
        ],
    ];

    /**
     * CARTOGRAPHIE : Discovery (Scan des mers françaises)
     */
    public function getDiscoveryData(Request $request)
    {
        $debug = [];
        $features = [];
        $gridMap = []; // Stockage temporaire pour fusionner les données : "lat_lon" => ['temp' => [], 'salt' => [], 'chla' => []]

        try {
            set_time_limit(120); // On augmente le temps limite car on va faire 3 requêtes
            
            // On boucle sur TOUS les datasets pour avoir Temp + Sel + Chla sur la même carte
            foreach ($this->allowedDatasets as $dsKey => $config) {
                // Grille : On demande un point tous les 2 degrés pour couvrir l'Europe sans surcharger
                // [(LatMin):Pas:(LatMax)]
                $url = "https://coastwatch.noaa.gov/erddap/griddap/{$dsKey}.json?{$config['variable']}";
                // FIX : On demande uniquement la DERNIÈRE donnée disponible (plus rapide et sûr)
                $url .= "[(last)]"; 
                if (in_array('altitude', $config['dimensions'])) $url .= "[(0.0)]";
                $url .= "[(35):2:(55)][(-15):2:(12)]"; // Grille Europe (Pas de 2°)

                $debug[$dsKey] = ['url' => $url];

                try {
                    $response = Http::timeout(10)->withOptions(['verify' => false])->get($url);
                    $debug[$dsKey]['status'] = $response->status();

                    if ($response->successful()) {
                        $data = $response->json();
                        $rows = $data['table']['rows'] ?? [];
                        $cols = $data['table']['columnNames'];
                        $idxVal = array_search($config['variable'], $cols);
                        $idxLat = array_search('latitude', $cols);
                        $idxLon = array_search('longitude', $cols);

                        if ($idxVal === false || $idxLat === false || $idxLon === false) continue;

                        foreach ($rows as $row) {
                            if (!isset($row[$idxVal]) || $row[$idxVal] === null) continue;
                            
                            // Clé unique pour le point géographique (arrondi pour regrouper)
                            $lat = round((float)$row[$idxLat], 2);
                            $lon = round((float)$row[$idxLon], 2);
                            $key = "{$lat}_{$lon}";

                            if (!isset($gridMap[$key])) {
                                $gridMap[$key] = ['lat' => $lat, 'lon' => $lon, 'temperature' => [], 'salinite' => [], 'chlorophylle' => []];
                            }
                            // On stocke toutes les valeurs trouvées sur la semaine pour faire une moyenne
                            $gridMap[$key][$config['model']][] = round((float)$row[$idxVal], 3);
                        }
                    }
                } catch (\Exception $e) {
                    $debug[$dsKey]['error'] = $e->getMessage();
                }
            }

            // Construction du GeoJSON final avec les moyennes
            foreach ($gridMap as $pt) {
                $avgTemp = !empty($pt['temperature']) ? array_sum($pt['temperature']) / count($pt['temperature']) : null;
                $avgSalt = !empty($pt['salinite']) ? array_sum($pt['salinite']) / count($pt['salinite']) : null;
                $avgChla = !empty($pt['chlorophylle']) ? array_sum($pt['chlorophylle']) / count($pt['chlorophylle']) : null;

                // On n'ajoute le point que si on a au moins une donnée
                if ($avgTemp !== null || $avgSalt !== null || $avgChla !== null) {
                    $features[] = [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => [$pt['lon'], $pt['lat']]],
                        'properties' => [
                            'temp' => $avgTemp ? round($avgTemp, 1) : null,
                            'salt' => $avgSalt ? round($avgSalt, 1) : null,
                            'chla' => $avgChla ? round($avgChla, 3) : null,
                            'date' => 'Dernière mesure'
                        ]
                    ];
                }
            }
            return response()->json(['type' => 'FeatureCollection', 'features' => $features, 'debug' => $debug])
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        } catch (\Exception $e) { 
            return response()->json(['error' => $e->getMessage(), 'debug' => $debug], 500)
                ->header('Access-Control-Allow-Origin', '*');
        }
    }


    private function getOrFetchValue($datasetId, $lat, $lon, $dateStr, &$debug)
    {
        $config = $this->allowedDatasets[$datasetId];
        $point = PointMesure::where('latitude', $lat)->where('longitude', $lon)->whereDate('dateMesure', $dateStr)->first();

        if ($point && $point->{$config['model']}) {
            $debug[] = ['source' => 'DB_CACHE', 'model' => $config['model'], 'date' => $dateStr];
            return $point->{$config['model']}->{$config['bdd_field']};
        }

        return $this->fetchFromNoaaPlage($datasetId, $lat, $lon, $dateStr, $debug);
    }

    private function fetchFromNoaaPlage($datasetId, $lat, $lon, $dateStr, &$debug)
    {
        $config = $this->allowedDatasets[$datasetId];
        // FIX: On cherche sur toute la journée (00h à 23h59) pour éviter de rater l'heure exacte du satellite
        $timeStart = $dateStr . 'T00:00:00Z';
        $timeEnd = $dateStr . 'T23:59:59Z';
        
        // FIX: On adapte la taille de la recherche à la résolution du satellite
        // Pour la salinité (0.25°), une boite de 0.05° est trop petite et tombe souvent "entre" les points.
        // On double la marge de sécurité pour être sûr d'attraper un pixel (approx 50-100km)
        $delta = max(0.1, $config['res'] * 2); 
        $latMin = $lat - $delta; $latMax = $lat + $delta;
        $lonMin = $lon - $delta; $lonMax = $lon + $delta;
        
        $url = "https://coastwatch.noaa.gov/erddap/griddap/{$datasetId}.json?{$config['variable']}";
        $url .= "[({$timeStart}):1:({$timeEnd})]";
        if (in_array('altitude', $config['dimensions'])) $url .= "[(0.0)]";
        $url .= "[({$latMin}):1:({$latMax})][({$lonMin}):1:({$lonMax})]";

        $logEntry = ['url' => $url, 'dataset' => $datasetId];

        try {
            $response = Http::timeout(15)->withOptions(['verify' => false])->get($url);
            $logEntry['status'] = $response->status();

            if ($response->successful()) {
                $rows = $response->json()['table']['rows'] ?? [];
                $logEntry['rows_found'] = count($rows);
                
                // On prend la première valeur non-nulle trouvée dans la zone
                foreach ($rows as $row) {
                    $valIdx = array_search($config['variable'], $response->json()['table']['columnNames']);
                    if (isset($row[$valIdx]) && $row[$valIdx] !== null) {
                        $val = $row[$valIdx];
                        $this->saveToMysql($datasetId, $lat, $lon, $dateStr, $val);
                        $logEntry['value'] = $val;
                        $debug[] = $logEntry;
                        return $val;
                    }
                }
            } else {
                $logEntry['error_body'] = substr($response->body(), 0, 200);
            }
        } catch (\Exception $e) { $logEntry['error'] = $e->getMessage(); }
        
        $debug[] = $logEntry;
        return null;
    }

    protected function saveToMysql($datasetId, $lat, $lon, $dateStr, $value)
    {
        if ($value === null) return;
        $config = $this->allowedDatasets[$datasetId];
        $point = PointMesure::firstOrCreate(['latitude' => round($lat, 3), 'longitude' => round($lon, 3), 'dateMesure' => $dateStr]);
        
        if ($config['model'] === 'temperature') Temperature::updateOrCreate(['Point_id' => $point->PM_id], ['temperature' => $value]);
        elseif ($config['model'] === 'salinite') Salinite::updateOrCreate(['PM_id' => $point->PM_id], ['sss' => $value]);
        // FIX: Ajout de 'taux_incertitude' => 0 pour éviter l'erreur SQL "Field doesn't have a default value"
        elseif ($config['model'] === 'chlorophylle') Chlorophylle::updateOrCreate(['PM_id' => $point->PM_id], ['chla' => $value, 'taux_incertitude' => 0]);
    }

    public function getAllStoredPoints()
    {
        try {
            $points = PointMesure::with(['temperature', 'salinite', 'chlorophylle'])->get();
            $features = $points->map(function($p) {
                return [
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [(float)$p->longitude, (float)$p->latitude]],
                    'properties' => [
                        'temp' => $p->temperature ? $p->temperature->temperature : null,
                        'salt' => $p->salinite ? $p->salinite->sss : null,
                        'chla' => $p->chlorophylle ? $p->chlorophylle->chla : null,
                        'date' => Carbon::parse($p->dateMesure)->format('d/m/Y')
                    ]
                ];
            });
            return response()->json(['type' => 'FeatureCollection', 'features' => $features]);
        } catch (\Exception $e) { return response()->json(['error' => $e->getMessage()], 500); }
    }

    public function resetDatabase()
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Temperature::truncate(); Salinite::truncate(); Chlorophylle::truncate(); PointMesure::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        return response()->json(['status' => 'success']);
    }

    
}