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
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        | Private store for medical files that must only ever be reachable
        | through an authorized controller action: message attachments and
        | prescriptions, when a Cloudinary upload fails and the local fallback
        | takes over.
        |
        | Three properties make that guarantee hold:
        |
        | - The root sits outside storage/app/public, so the public/storage
        |   symlink cannot reach it and the web server cannot serve it as a
        |   static file.
        | - It is not the "local" disk. That disk is serve => true, which makes
        |   Laravel register GET|PUT /storage/{path} routes for it; those demand
        |   a signed URL, but signed URLs are not this application's
        |   authorization model and must not become a second way in.
        | - serve => false and no visibility/url key, so no framework route and
        |   no Storage::url() can ever address these files.
        |
        | Both file types share the disk but never a directory: attachments live
        | under message-attachments/{session_id}/ and prescriptions under
        | consultation-prescriptions/{session_id}/, which are exactly the
        | relative paths already stored in the database.
        */
        'message_attachments' => [
            'driver' => 'local',
            'root' => storage_path('app/medical'),
            'serve' => false,
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

        'cloudinary' => [
            'driver' => 'cloudinary',
            'url' => env('CLOUDINARY_URL'),
            'secure' => true,
            'throw' => false,
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
