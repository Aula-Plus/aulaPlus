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

        /*
        | Subjects' curricular program PDFs (App\Support\SyllabusStorage).
        | Always private: files are only ever reached through short-lived
        | temporary URLs (CLAUDE.md rule 7). SYLLABUS_DISK picks the backend:
        | "s3" (any S3-compatible private bucket, AWS_* vars) — required in
        | production, where the container's local disk does not survive a
        | redeploy — or "local" (development; signed URLs served by the app).
        */
        'syllabi' => match (env('SYLLABUS_DISK', 'local')) {
            's3' => [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION'),
                'bucket' => env('AWS_BUCKET'),
                'endpoint' => env('AWS_ENDPOINT'),
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                'visibility' => 'private',
                'throw' => true,
                'report' => false,
            ],
            default => [
                'driver' => 'local',
                'root' => storage_path('app/private'),
                // Own URL prefix: each served disk needs a unique one.
                'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/syllabus-files',
                'serve' => true,
                'visibility' => 'private',
                'throw' => true,
                'report' => false,
            ],
        },

        /*
        | Students' clinical reports, PDF or Word (App\Support\Reports\ReportStorage).
        | Always private (clinical data about minors, rule 11): files are only
        | ever reached through short-lived temporary URLs (CLAUDE.md rule 7).
        | REPORT_DISK picks the backend:
        | "s3" (any S3-compatible private bucket, AWS_* vars) — required in
        | production, where the container's local disk does not survive a
        | redeploy — or "local" (development; signed URLs served by the app).
        */
        'reports' => match (env('REPORT_DISK', 'local')) {
            's3' => [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION'),
                'bucket' => env('AWS_BUCKET'),
                'endpoint' => env('AWS_ENDPOINT'),
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                'visibility' => 'private',
                'throw' => true,
                'report' => false,
            ],
            default => [
                'driver' => 'local',
                'root' => storage_path('app/private'),
                // Own URL prefix: each served disk needs a unique one.
                'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/report-files',
                'serve' => true,
                'visibility' => 'private',
                'throw' => true,
                'report' => false,
            ],
        },

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
