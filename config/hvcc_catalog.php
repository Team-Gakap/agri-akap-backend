<?php

/**
 * DA-HVCDP (RA 7900) high-value commercial crop taxonomy.
 * Frontend mirror: agri-akap-frontend/src/constants/hvccCatalog.ts
 */
return [
    'categories' => [
        'Fruits' => ['Pineapple', 'Banana', 'Melon', 'Watermelon', 'Mango'],
        'Vegetables' => ['Eggplant', 'Tomato', 'Bitter Gourd (Ampalaya)', 'String Beans', 'Okra'],
        'Beverage / Industrial Crops' => ['Coffee', 'Cacao'],
        'Root Crops / Tubers' => ['Ube (Purple Yam)', 'Sweet Potato (Kamote)', 'Cassava'],
        'Other Fruits' => ['Papaya', 'Calamansi', 'Pomelo', 'Durian', 'Lanzones', 'Rambutan', 'Dragon Fruit', 'Mangosteen', 'Jackfruit'],
    ],

    /** Categories that report Number of Hills/Trees on DA-HVCDP accomplishment forms. */
    'tree_fruit_categories' => [
        'Fruits',
        'Other Fruits',
        'Beverage / Industrial Crops',
    ],

    'override_reasons' => [
        'tenant_endorsed' => 'Tenant/Lessee Endorsed by Barangay Captain',
        'pending_rsbsa_batch' => 'Pending RSBSA Enrollment (Encoded in Batch)',
        'emergency_calamity_hvcdp' => 'Emergency Calamity Buffer Relief (Under DA-HVCDP)',
    ],
];
