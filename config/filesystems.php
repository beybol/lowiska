<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Cloud Run: uploady trwałe na buckecie GCS fundamentu (zadanie 005, ADR-0013/0014
        // w gcp-foundation). Bez konfiguracji poświadczeń — tożsamość runtime to Application
        // Default Credentials z metadata servera Cloud Run, nie klucz JSON.
        'gcs' => [
            'driver' => 'gcs',
            'bucket' => env('GOOGLE_CLOUD_STORAGE_BUCKET'),
            'visibility' => 'public',
            // Bucket fundamentu ma uniform_bucket_level_access=true bezwarunkowo — dostęp
            // wyłącznie przez IAM, zero ACL per-obiekt. Bez tego handlera domyślny
            // PortableVisibilityHandler próbuje ustawić legacy ACL przy każdym uploadzie i pęka:
            // "Cannot insert legacy ACL for an object when uniform bucket-level access is enabled".
            // Nie usuwaj tego wpisu.
            'visibility_handler' => \League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility::class,
            // Świadomy rozjazd z resztą tego pliku (wszędzie indziej false): cichy `false` przy
            // nieudanym zapisie do bucketa (sieć, uprawnienia) jest dokładnie tą kategorią cichej
            // awarii, którą zadanie 005 ma usunąć.
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
