<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | Files are uploaded in chunks of `chunk_size` bytes (keep it below the web server's body
    | limit) and appended to a temporary file until complete. Unfinished uploads older than
    | `upload_ttl_hours` are pruned by `uploads:prune`.
    |
    */

    'chunk_size' => (int) env('SHED_CHUNK_SIZE', 5 * 1024 * 1024),

    'upload_ttl_hours' => (int) env('SHED_UPLOAD_TTL_HOURS', 24),

    'tmp_path' => env('SHED_TMP_PATH', storage_path('app/private/shed-tmp')),

    /*
    |--------------------------------------------------------------------------
    | Default local disk
    |--------------------------------------------------------------------------
    |
    | Created on first use when no storage disk is marked as default.
    |
    */

    'local_root' => env('SHED_LOCAL_ROOT', storage_path('app/private/shed')),

];
