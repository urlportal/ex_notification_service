<?php

namespace App\Enums;

enum NotificationPriority: string
{
    case Transactional = 'transactional';
    case Marketing = 'marketing';
}
