<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Carbon Locale(Language)
    |--------------------------------------------------------------------------
    |
    | Option to whether change the language for carbon library or not.
    |
    */
    'carbon' => true,

    /*
    |--------------------------------------------------------------------------
    | Language display name
    |--------------------------------------------------------------------------
    |
    | Option to whether display the language in English or Native.
    |
    */
    'native' => true,

    /*
    |--------------------------------------------------------------------------
    | All Locales (Languages)
    |--------------------------------------------------------------------------
    |
    | Uncomment the languages that your site supports - or add new ones.
    | These are sorted by the native name, which is the order you might show them in a language selector.
    |
    */

    'locales' => [
        'en' => ['name' => 'English', 'script' => 'Latn', 'native' => 'English', 'flag_code' => 'us'],
        'pl' => ['name' => 'Polish', 'script' => 'Latn', 'native' => 'Polski', 'flag_code' => 'pl'],
    ]
];

