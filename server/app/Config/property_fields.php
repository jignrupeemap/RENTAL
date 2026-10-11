<?php
declare(strict_types=1);

/**
 * Field map derived from Rental flow.xlsx. ONE source of truth used by:
 *   - the posting wizard (which inputs to render on each step)
 *   - server-side validation (which fields are required / allowed)
 *   - the property detail page (which facts to display)
 *
 * Keyed by "profile": property_types.field_profile for Rent/Lease, and
 * 'pg' for the PG flow. Per-type differences that come from the sheet
 * (Flat No vs Shop No vs Plot No, Building Name shown or not) are stored
 * on the property_types row itself (unit_label, show_building_name).
 *
 * Field rules: 'required' | 'optional'. Fields not listed for a profile are
 * hidden in the form AND rejected/cleared on save.
 */
return [
    // Steps of the wizard (spec section 7). Each field below names its step.
    'steps' => [
        1 => 'Property Type',
        2 => 'Property Details',
        3 => 'Location',
        4 => 'Facilities',
        5 => 'Rent',
        6 => 'Photos & Video',
        7 => 'Owner Details',
        8 => 'Preview & Submit',
    ],

    'options' => [
        'bedrooms'         => [1 => '1 BHK', 2 => '2 BHK', 3 => '3 BHK', 4 => '4 BHK', 5 => '5+ BHK'],
        'bathrooms'        => [1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5+'],
        'balconies'        => [0 => '0', 1 => '1', 2 => '2', 3 => '3', 4 => '4+'],
        'furnishing'       => ['furnished' => 'Furnished', 'semi_furnished' => 'Semi-Furnished', 'unfurnished' => 'Unfurnished'],
        'area_unit'        => ['sqft' => 'Sq Ft', 'sqyd' => 'Sq Yard'],
        'pg_available_for' => ['boys' => 'Boys', 'girls' => 'Girls', 'any' => 'Any'],
        'pg_suitable_for'  => ['students' => 'Students', 'professionals' => 'Professionals', 'both' => 'Both'],
        'pg_services'      => ['food' => 'Food', 'laundry' => 'Laundry', 'car_washing' => 'Car Washing', 'other' => 'Other'],
    ],

    'profiles' => [
        // Rent/Lease → Residential: Flat, Bungalow, Farmhouse/Villa (sheet cols B-D)
        'residential' => [
            2 => ['title' => 'required', 'description' => 'optional', 'bedrooms' => 'required', 'bathrooms' => 'required',
                  'balconies' => 'optional', 'area_value' => 'optional', 'area_unit' => 'optional',
                  'furnishing' => 'required', 'available_from' => 'required'],
            3 => ['unit_no' => 'optional', 'building_name' => 'optional', 'project_name' => 'optional', 'landmark' => 'optional',
                  'address_line' => 'required', 'city_id' => 'required', 'locality_id' => 'required', 'pincode' => 'required', 'map' => 'required'],
            4 => ['amenities' => 'optional'],
            5 => ['rent' => 'required', 'security_deposit' => 'optional', 'maintenance' => 'optional', 'other_charges' => 'optional'],
        ],

        // Rent/Lease → Commercial units: Office, Shop, Showroom, House/Building,
        // Restaurant, Hospital, School, Hotel, Gym, Hall (sheet cols F-P)
        'commercial' => [
            2 => ['title' => 'required', 'description' => 'optional', 'area_value' => 'required', 'area_unit' => 'required',
                  'furnishing' => 'required', 'available_from' => 'required'],
            3 => ['unit_no' => 'optional', 'building_name' => 'optional', 'project_name' => 'optional', 'landmark' => 'optional',
                  'address_line' => 'required', 'city_id' => 'required', 'locality_id' => 'required', 'pincode' => 'required', 'map' => 'required'],
            4 => ['amenities' => 'optional'], // Personal Washroom, Power Backup ...
            5 => ['rent' => 'required', 'security_deposit' => 'optional', 'maintenance' => 'optional', 'other_charges' => 'optional'],
        ],

        // Rent/Lease → Commercial Plot: no furnishing (sheet col J)
        'commercial_plot' => [
            2 => ['title' => 'required', 'description' => 'optional', 'area_value' => 'required', 'area_unit' => 'required',
                  'available_from' => 'required'],
            3 => ['unit_no' => 'optional', 'project_name' => 'optional', 'landmark' => 'optional',
                  'address_line' => 'required', 'city_id' => 'required', 'locality_id' => 'required', 'pincode' => 'required', 'map' => 'required'],
            4 => ['amenities' => 'optional'],
            5 => ['rent' => 'required', 'security_deposit' => 'optional', 'other_charges' => 'optional'],
        ],

        // Rent/Lease → Industrial: Factory, Warehouse/Godown, Industrial Plot,
        // Cold Storage, Shed (sheet cols R-V). Landmark, Sq Ft / Sq Yard,
        // Electricity Backup, RCC Road; no furnishing.
        'industrial' => [
            2 => ['title' => 'required', 'description' => 'optional', 'area_value' => 'required', 'area_unit' => 'required',
                  'available_from' => 'optional'],
            3 => ['unit_no' => 'optional', 'project_name' => 'optional', 'landmark' => 'required',
                  'address_line' => 'required', 'city_id' => 'required', 'locality_id' => 'required', 'pincode' => 'required', 'map' => 'required'],
            4 => ['amenities' => 'optional'],
            5 => ['rent' => 'required', 'security_deposit' => 'optional', 'maintenance' => 'optional', 'other_charges' => 'optional'],
        ],

        // PG → Flat / Bungalow (PG sheet)
        'pg' => [
            2 => ['title' => 'required', 'description' => 'optional', 'bedrooms' => 'required', 'bathrooms' => 'required',
                  'balconies' => 'optional', 'furnishing' => 'required', 'available_from' => 'required',
                  'pg_available_for' => 'required', 'pg_suitable_for' => 'required'],
            3 => ['unit_no' => 'optional', 'building_name' => 'optional', 'project_name' => 'optional', 'landmark' => 'optional',
                  'address_line' => 'required', 'city_id' => 'required', 'locality_id' => 'required', 'pincode' => 'required', 'map' => 'required'],
            4 => ['amenities' => 'optional', 'pg_services' => 'optional'], // Food, Laundry, Car Washing (extra cost)
            5 => ['rent' => 'required', 'security_deposit' => 'optional', 'other_charges' => 'optional'],
        ],
    ],

    // Steps 6 and 7 are identical for every profile.
    'common' => [
        6 => ['photos' => 'required', 'cover_media_id' => 'required', 'video' => 'optional', 'video_url' => 'optional'],
        7 => ['contact_name' => 'required', 'contact_mobile_1' => 'required', 'contact_mobile_2' => 'optional', 'contact_email' => 'optional'],
    ],
];
