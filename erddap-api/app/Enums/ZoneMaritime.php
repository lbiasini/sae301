<?php

namespace App\Enums;

enum ZoneMaritime: string
{
    case ATLANTIQUE_NORD = 'Atlantique Nord';
    case MEDITERRANEE = 'Méditerranée';
    case MANCHE = 'Manche';
    case GOLFE_GASCOGNE = 'Golfe de Gascogne';
    case MER_DU_NORD = 'Mer du Nord';

    public function slug(): string
    {
        return match($this) {
            self::ATLANTIQUE_NORD => 'atlantique-nord',
            self::MEDITERRANEE => 'mediterranee',
            self::MANCHE => 'manche',
            self::GOLFE_GASCOGNE => 'golfe-gascogne',
            self::MER_DU_NORD => 'mer-du-nord',
        };
    }

    public function boundingBox(): array
    {
        return match($this) {
            self::ATLANTIQUE_NORD => ['latMin' => 40.0, 'latMax' => 50.0, 'lonMin' => -20.0, 'lonMax' => -5.0],
            self::MEDITERRANEE => ['latMin' => 36.0, 'latMax' => 44.0, 'lonMin' => 3.0, 'lonMax' => 10.0],
            self::MANCHE => ['latMin' => 49.0, 'latMax' => 51.0, 'lonMin' => -5.0, 'lonMax' => 2.0],
            self::GOLFE_GASCOGNE => ['latMin' => 44.0, 'latMax' => 48.0, 'lonMin' => -5.0, 'lonMax' => -1.0],
            self::MER_DU_NORD => ['latMin' => 51.0, 'latMax' => 56.0, 'lonMin' => 2.0, 'lonMax' => 8.0],
        };
    }
}
