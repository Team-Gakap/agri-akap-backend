<?php

namespace Database\Seeders;

use App\Models\Barangay;
use Illuminate\Database\Seeder;

/**
 * Overwrites tbl_barangays lat/lng with precise micro-location pins
 * so the Open-Meteo bulk fetcher queries distinct cells per barangay.
 *
 * Dataset format: short name => "latitude, longitude"
 * Short names are mapped to official DB names via NAME_ALIASES.
 */
class BarangayCoordinateSeeder extends Seeder
{
    /**
     * CSV short names → official names stored in tbl_barangays.
     *
     * @var array<string, string>
     */
    private const NAME_ALIASES = [
        'Cabugao' => 'Cabugao (Poblacion)',
        'San Manuel' => 'San Manuel (formerly Atelan)',
        'Silauan Norte' => 'Silauan Norte (Poblacion)',
        'Silauan Sur' => 'Silauan Sur (Poblacion)',
        'Soyung' => 'Soyung (Poblacion)',
        'Taggappan' => 'Taggappan (Poblacion)',
    ];

    public function run(): void
    {
        foreach ($this->coordinates() as $name => $coordinateString) {
            [$latitude, $longitude] = $this->parseCoordinateString($coordinateString);

            if ($latitude === null || $longitude === null) {
                continue;
            }

            Barangay::updateOrCreate(
                ['name' => $this->resolveName($name)],
                [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * All 64 Echague pins from field GPS ("lat, lng").
     *
     * @return array<string, string>
     */
    protected function coordinates(): array
    {
        return [
            'Angoluan' => '16.720884693540548, 121.66533693003596',
            'Annafunan' => '16.711183667476295, 121.72236749238249',
            'Arabiat' => '16.641828926097638, 121.60501337457588',
            'Aromin' => '16.69709835995235, 121.75949486232037',
            'Babaran' => '16.6818241184303, 121.72482823546623',
            'Bacradal' => '16.62816981339197, 121.68801115117748',
            'Benguet' => '16.6358772123196, 121.87340251576173',
            'Buneg' => '16.708116701285867, 121.64815620861457',
            'Busilelao' => '16.674484868473343, 121.69642177938401',
            'Cabugao' => '16.7068067554664, 121.67195415901148',
            'Caniguing' => '16.653168397031518, 121.74097428754921',
            'Carulay' => '16.718238667298486, 121.70955022871843',
            'Castillo' => '16.736473597337962, 121.6895421041257',
            'Dammang East' => '16.68995810304097, 121.67869110080821',
            'Dammang West' => '16.68255072566982, 121.6742309945296',
            'Diasan' => '16.624444288128355, 121.81649890656958',
            'Dicaraoyan' => '16.681426820356393, 121.85057785066633',
            'Dugayong' => '16.70365451654166, 121.7065232711821',
            'Fugu' => '16.72726373650271, 121.65567826734588',
            'Garit Norte' => '16.662681980798954, 121.66068718001495',
            'Garit Sur' => '16.651486084846656, 121.6668021289106',
            'Gucab' => '16.702777131712306, 121.68680163083174',
            'Gumbauan' => '16.751716267586115, 121.67516955281314',
            'Ipil' => '16.693433043286603, 121.64242080563977',
            'Libertad' => '16.667224049516285, 121.64169356111466',
            'Mabbayad' => '16.66088219315658, 121.85146369523117',
            'Mabuhay' => '16.72297158221077, 121.71824922885578',
            'Madadamian' => '16.6415625486244, 121.83834287933306',
            'Magleticia' => '16.687204643215143, 121.81688363151969',
            'Malibago' => '16.734489462285573, 121.6769101202565',
            'Maligaya' => '16.69042625266742, 121.65219400092845',
            'Malitao' => '16.675063347207146, 121.66495812250734',
            'Narra' => '16.68373647340062, 121.79080364765024',
            'Nilumisu' => '16.625911415527483, 121.74799303925568',
            'Pag-asa' => '16.69985325683167, 121.72769381884021',
            'Pangal Norte' => '16.61859674524987, 121.66842344067236',
            'Pangal Sur' => '16.60077761975013, 121.66531224057903',
            'Rumang-ay' => '16.64797506429124, 121.67976709946004',
            'Salay' => '16.699871672864514, 121.70388811628318',
            'Salvacion' => '16.81707164699836, 121.66829255366355',
            'San Antonio Minit' => '16.641080024676683, 121.64212030101449',
            'San Antonio Ugad' => '16.719795656019027, 121.69999623493702',
            'San Carlos' => '16.63082253636107, 121.77554509095063',
            'San Fabian' => '16.72017476881195, 121.68406214962103',
            'San Felipe' => '16.614798687042992, 121.71434681605035',
            'San Juan' => '16.637905366975115, 121.66472839746996',
            'San Manuel' => '16.611905568729355, 121.63096724376553',
            'San Miguel' => '16.58164939314907, 121.9786597146932',
            'San Salvador' => '16.63540141684654, 121.71294804413931',
            'Santa Ana' => '16.623779122446994, 121.61024796386951',
            'Santa Cruz' => '16.69029536266407, 121.70597184564113',
            'Santa Maria' => '16.592644603253703, 121.61734987026566',
            'Santa Monica' => '16.678451872564466, 121.62309868732603',
            'Santo Domingo' => '16.71498381527055, 121.6995934767343',
            'Silauan Norte' => '16.7146710207227, 121.67105199221298',
            'Silauan Sur' => '16.706388513836647, 121.68156086588853',
            'Sinabbaran' => '16.72801445157383, 121.70444667872314',
            'Soyung' => '16.701478906510456, 121.66391107686333',
            'Taggappan' => '16.701628043405215, 121.67549249737084',
            'Tuguegarao' => '16.710093435698806, 121.6897607891699',
            'Villa Campo' => '16.657956023923564, 121.80504603172425',
            'Villa Fermin' => '16.594076143183898, 121.6421664760774',
            'Villa Rey' => '16.672829217506425, 121.82499252473231',
            'Villa Victoria' => '16.580859998028963, 121.65888038369627',
        ];
    }

    /**
     * @return array{0:?float, 1:?float}
     */
    protected function parseCoordinateString(string $coordinateString): array
    {
        $parts = array_map('trim', explode(',', $coordinateString, 2));

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return [null, null];
        }

        return [(float) $parts[0], (float) $parts[1]];
    }

    protected function resolveName(string $name): string
    {
        return self::NAME_ALIASES[$name] ?? $name;
    }
}
