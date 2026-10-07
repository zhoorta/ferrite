<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Behind a reverse proxy
    |--------------------------------------------------------------------------
    |
    | Comma-separated IPs/CIDRs of proxies whose X-Forwarded-* headers are believed (so https and
    | the client IP are seen correctly), or "*" for any. Empty when reached directly.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | Open to everyone when true. When false, sign-up is only possible while there are no users
    | at all (the first account becomes the admin); after that, admins add users.
    |
    */

    'registration' => (bool) env('FERRITE_REGISTRATION', false),

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

    'chunk_size' => (int) env('FERRITE_CHUNK_SIZE', 5 * 1024 * 1024),

    'upload_ttl_hours' => (int) env('FERRITE_UPLOAD_TTL_HOURS', 24),

    // A file whose copy to its disk has not finished this many hours after the job started is
    // given up on by `uploads:prune` (the job itself is limited to six hours). One still waiting
    // in the queue is given up after a week.
    'processing_timeout_hours' => (int) env('FERRITE_PROCESSING_TIMEOUT_HOURS', 12),

    'tmp_path' => env('FERRITE_TMP_PATH', storage_path('app/private/ferrite-tmp')),

    /*
    |--------------------------------------------------------------------------
    | Trash
    |--------------------------------------------------------------------------
    |
    | Items stay restorable for this many days; then `trash:purge` deletes them for good.
    |
    */

    'trash_days' => (int) env('FERRITE_TRASH_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Activity log
    |--------------------------------------------------------------------------
    |
    | How long entries are kept before `activity:prune` removes them.
    |
    */

    'activity_days' => (int) env('FERRITE_ACTIVITY_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Default local disk
    |--------------------------------------------------------------------------
    |
    | Created on first use when no storage disk is marked as default.
    |
    */

    'local_root' => env('FERRITE_LOCAL_ROOT', storage_path('app/private/ferrite')),

];
