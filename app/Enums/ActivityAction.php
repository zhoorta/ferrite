<?php

namespace App\Enums;

enum ActivityAction: string
{
    case Uploaded = 'uploaded';
    case CreatedFolder = 'created_folder';
    case Renamed = 'renamed';
    case Moved = 'moved';
    case Copied = 'copied';
    case Trashed = 'trashed';
    case Restored = 'restored';
    case Purged = 'purged';
    case Downloaded = 'downloaded';
    case LinkCreated = 'link_created';
    case LinkRevoked = 'link_revoked';
    case LinkDownloaded = 'link_downloaded';
    case Shared = 'shared';
    case Unshared = 'unshared';
}
