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
    | Versions
    |--------------------------------------------------------------------------
    |
    | Replacing a file (an upload over the same name, answered "Replace") keeps its earlier content as a version,
    | up to `versions_keep` per file, oldest dropped first; 0 turns versions off and a replace deletes the old
    | content as before. `versions_days` also drops versions older than that many days (0: no age limit). Versions
    | count against the owner's quota. See docs/versions.md.
    |
    */

    'versions_keep' => (int) env('FERRITE_VERSIONS_KEEP', 5),

    'versions_days' => (int) env('FERRITE_VERSIONS_DAYS', 0),

    /*
    |--------------------------------------------------------------------------
    | Integrity scrub
    |--------------------------------------------------------------------------
    |
    | How many stored blobs the nightly scrub reads back and compares with their sha256 (the least recently
    | verified first, so it covers everything over time). 0 turns the schedule off; you can still run
    | `php artisan ferrite:storage:check --verify` by hand. See docs/storage-check.md.
    |
    */

    'scrub_blobs' => (int) env('FERRITE_SCRUB_BLOBS', 0),

    /*
    |--------------------------------------------------------------------------
    | Encryption at rest
    |--------------------------------------------------------------------------
    |
    | Disks created with "Encrypt files" store encrypted blobs (docs/encryption.md). `file_key` is 32
    | random bytes, base64 encoded, kept in `.env` next to `APP_KEY` and backed up with it: without it
    | every encrypted file is gone. `file_key_id` names it inside each blob; after a rotation the
    | retired keys stay readable through `file_keys_old` ("id:base64,id:base64") until `files:rekey`.
    |
    */

    'file_key' => env('FERRITE_FILE_KEY'),

    'file_key_id' => (int) env('FERRITE_FILE_KEY_ID', 1),

    'file_keys_old' => env('FERRITE_FILE_KEYS_OLD'),

    // The text extracted for content search is stored in the database unencrypted. Files on encrypted disks are
    // left out of it unless this is true (names are always searchable): the point of encrypting is that a leaked
    // database or backup should not reveal what is in the files.
    'search_encrypted' => (bool) env('FERRITE_SEARCH_ENCRYPTED', false),

    /*
    |--------------------------------------------------------------------------
    | Content search
    |--------------------------------------------------------------------------
    |
    | Text files and PDFs are read in the background and their text is added to the search index.
    | Set FERRITE_SEARCH_CONTENTS=false to turn it off (names are always searchable). Only the first
    | `search_max_text_kb` of a text file are indexed; a PDF over `search_max_pdf_mb` is skipped.
    | PDFs need the `pdftotext` command (poppler-utils); without it they are skipped.
    |
    */

    'search_contents' => (bool) env('FERRITE_SEARCH_CONTENTS', true),

    'search_max_text_kb' => (int) env('FERRITE_SEARCH_MAX_TEXT_KB', 2048),

    'search_max_pdf_mb' => (int) env('FERRITE_SEARCH_MAX_PDF_MB', 50),

    'pdftotext' => env('FERRITE_PDFTOTEXT', 'pdftotext'),

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

    /*
    |--------------------------------------------------------------------------
    | Landing page
    |--------------------------------------------------------------------------
    |
    | When true, visitors who are not signed in see the project page at "/" instead of the
    | sign-in form (used on ferrite.stackcare.pt). Leave it off for an ordinary install.
    |
    */

    'landing' => (bool) env('FERRITE_LANDING', false),

    'repo_url' => env('FERRITE_REPO_URL', 'https://github.com/zhoorta/ferrite'),

    'demo_url' => env('FERRITE_DEMO_URL'),

    /*
    |--------------------------------------------------------------------------
    | Demo mode
    |--------------------------------------------------------------------------
    |
    | A public try-out instance: the sign-in page offers "Try the demo", which creates a throwaway
    | account with sample files and a small quota. Accounts older than `ttl_minutes` are deleted
    | with their files (`demo:prune`, every 10 minutes). Registration is closed, the account,
    | password and security settings are locked, sharing (links and users) is off, and uploads are
    | limited to small images, PDFs and plain text (`max_file_mb`), checked by name and by content.
    | `contact` is shown in the demo banner for takedown requests.
    |
    */

    'demo' => [
        'enabled' => (bool) env('FERRITE_DEMO', false),
        'ttl_minutes' => (int) env('FERRITE_DEMO_TTL_MINUTES', 60),
        'quota_mb' => (int) env('FERRITE_DEMO_QUOTA_MB', 20),
        'max_accounts' => (int) env('FERRITE_DEMO_MAX_ACCOUNTS', 100),
        'max_file_mb' => (int) env('FERRITE_DEMO_MAX_FILE_MB', 2),
        'contact' => env('FERRITE_DEMO_CONTACT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connecting apps
    |--------------------------------------------------------------------------
    |
    | The native apps sign in through the browser: the app opens `/connect`, you approve there (after the normal
    | sign-in, with 2FA) and the app receives a token. The page only ever returns to one of these exact addresses,
    | so a link cannot send the code anywhere else. Comma-separated; see docs/api.md.
    |
    */

    'connect_redirects' => array_values(array_filter(array_map('trim', explode(',', (string) env('FERRITE_CONNECT_REDIRECTS', 'ferrite://auth'))))),

];
