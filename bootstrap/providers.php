<?php

use App\Providers\AppServiceProvider;
use App\Providers\GatewayServiceProvider;
use App\Providers\RetryConfigServiceProvider;

return [
    AppServiceProvider::class,
    GatewayServiceProvider::class,
    RetryConfigServiceProvider::class,
];
