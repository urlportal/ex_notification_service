<?php

namespace App\Enums;

enum ProviderCallbackStatus: string
{
    case Delivered = 'delivered';
    case Dropped = 'dropped';
}
