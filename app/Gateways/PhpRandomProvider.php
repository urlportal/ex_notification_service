<?php

namespace App\Gateways;

class PhpRandomProvider implements RandomProviderInterface
{
    public function nextFloat(): float
    {
        return mt_rand() / mt_getrandmax();
    }
}
