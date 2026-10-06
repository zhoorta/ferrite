<?php

namespace App\Enums;

enum NodeType: string
{
    case File = 'file';
    case Folder = 'folder';
}
