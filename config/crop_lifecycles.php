<?php

/*
|--------------------------------------------------------------------------
| Crop Lifecycle Thresholds (PhilRice / DA-BPI)
|--------------------------------------------------------------------------
| Day ranges are inclusive, counted from date_planted (Day 0). Unknown
| varieties fall through exact match → keyword match → crop `default`.
*/

return [

    'crops' => [

        'rice' => [
            'aliases' => ['rice', 'palay'],
            'classifications' => [
                'early_inbred' => [
                    'label' => 'Early Inbred',
                    'total_maturity_days' => 110,
                    'stages' => [
                        'seedling' => [0, 14],
                        'vegetative' => [15, 45],
                        'reproductive' => [46, 80],
                        'maturity' => [81, 110],
                    ],
                    'varieties' => [
                        'PSB Rc 10',
                        'NSIC Rc 118',
                        'NSIC Rc 120',
                        'NSIC Rc 130',
                    ],
                ],
                'medium_inbred' => [
                    'label' => 'Medium Inbred',
                    'total_maturity_days' => 122,
                    'stages' => [
                        'seedling' => [0, 20],
                        'vegetative' => [21, 55],
                        'reproductive' => [56, 90],
                        'maturity' => [91, 122],
                    ],
                    'varieties' => [
                        'NSIC Rc 222',
                        'NSIC Rc 216',
                        'NSIC Rc 160',
                        'PSB Rc 82',
                    ],
                ],
                'late_inbred' => [
                    'label' => 'Late Inbred / Traditional',
                    'total_maturity_days' => 135,
                    'stages' => [
                        'seedling' => [0, 22],
                        'vegetative' => [23, 65],
                        'reproductive' => [66, 105],
                        'maturity' => [106, 135],
                    ],
                    'varieties' => [
                        'NSIC Rc 300',
                        'Dinorado',
                        'Malagkit',
                    ],
                ],
                'hybrid_rice' => [
                    'label' => 'Hybrid Rice',
                    'total_maturity_days' => 112,
                    'stages' => [
                        'seedling' => [0, 15],
                        'vegetative' => [16, 48],
                        'reproductive' => [49, 82],
                        'maturity' => [83, 112],
                    ],
                    'varieties' => [
                        'Hybrid Rice',
                        'Mestiso 19',
                        'Mestiso 20',
                        'SL-8H',
                        'Bigante Plus',
                    ],
                ],
                'default' => [
                    'label' => 'Default Rice',
                    'total_maturity_days' => 115,
                    'stages' => [
                        'seedling' => [0, 20],
                        'vegetative' => [21, 55],
                        'reproductive' => [56, 85],
                        'maturity' => [86, 115],
                    ],
                    'varieties' => [],
                ],
            ],
        ],

        'corn' => [
            'aliases' => ['corn', 'mais'],
            'classifications' => [
                'hybrid_yellow' => [
                    'label' => 'Hybrid Yellow Corn',
                    'total_maturity_days' => 110,
                    'stages' => [
                        'seedling' => [0, 12],
                        'vegetative' => [13, 45],
                        'reproductive' => [46, 80],
                        'maturity' => [81, 110],
                    ],
                    'varieties' => [
                        'Hybrid Yellow',
                        'Pioneer Hybrid',
                        'Dekalb Hybrid',
                        'NK 6410',
                        'Pioneer 30T80',
                        'DEKALB 8899S',
                        'NK8840',
                        'Bioseed',
                    ],
                ],
                'hybrid_white' => [
                    'label' => 'Hybrid White Corn',
                    'total_maturity_days' => 102,
                    'stages' => [
                        'seedling' => [0, 10],
                        'vegetative' => [11, 40],
                        'reproductive' => [41, 75],
                        'maturity' => [76, 102],
                    ],
                    'varieties' => [
                        'Hybrid White',
                        'IPB Var 6',
                        'Macho White',
                    ],
                ],
                'opv' => [
                    'label' => 'Traditional / OPV Corn',
                    'total_maturity_days' => 95,
                    'stages' => [
                        'seedling' => [0, 10],
                        'vegetative' => [11, 35],
                        'reproductive' => [36, 70],
                        'maturity' => [71, 95],
                    ],
                    'varieties' => [
                        'Open-Pollinated Variety (OPV)',
                        'Tiniguib',
                        'Native White Flint',
                    ],
                ],
                'sweet' => [
                    'label' => 'Sweet / Glutinous Corn',
                    'total_maturity_days' => 75,
                    'stages' => [
                        'seedling' => [0, 8],
                        'vegetative' => [9, 30],
                        'reproductive' => [31, 55],
                        'maturity' => [56, 75],
                    ],
                    'varieties' => [
                        'Sweet Corn',
                        'Glutinous Corn',
                        'Green Corn',
                    ],
                ],
                'default' => [
                    'label' => 'Default Corn',
                    'total_maturity_days' => 105,
                    'stages' => [
                        'seedling' => [0, 12],
                        'vegetative' => [13, 45],
                        'reproductive' => [46, 75],
                        'maturity' => [76, 105],
                    ],
                    'varieties' => [],
                ],
            ],
        ],

    ],

    /*
     | Keyword fallback is checked in declaration order. Corn OPV tokens
     | (native / flint) must beat "white" so Native White Flint stays OPV.
     */
    'keywords' => [
        'rice' => [
            'hybrid_rice' => ['hybrid', 'mestiso', 'mestizo', 'bigante', 'sl-8', 'sl8'],
            'late_inbred' => ['dinorado', 'malagkit', 'traditional', 'late'],
            'early_inbred' => ['early'],
            'medium_inbred' => ['medium'],
        ],
        'corn' => [
            'sweet' => ['sweet', 'glutinous', 'green corn', 'boiled'],
            'opv' => ['opv', 'traditional', 'native', 'tiniguib', 'flint', 'open-pollinated', 'open pollinated'],
            'hybrid_white' => ['white'],
            'hybrid_yellow' => ['hybrid', 'pioneer', 'dekalb', 'nk', 'bioseed', 'yellow'],
        ],
    ],

];
