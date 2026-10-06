<?php

namespace App\Enums;

enum Permission: string
{
    case View = 'view';
    case Edit = 'edit';
}
