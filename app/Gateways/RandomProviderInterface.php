<?php

namespace App\Gateways;

interface RandomProviderInterface
{
    /**
     * Возвращает псевдослучайное число с плавающей точкой в диапазоне от 0.0 до 1.0
     */
    public function nextFloat(): float;
}
