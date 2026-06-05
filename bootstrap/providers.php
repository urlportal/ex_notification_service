<?php

use App\Providers\AppServiceProvider;
use App\Providers\CallbackServiceProvider;
use App\Providers\GatewayServiceProvider;
use App\Providers\RescueSweepConfigServiceProvider;
use App\Providers\RetryConfigServiceProvider;

return [
    AppServiceProvider::class,
    CallbackServiceProvider::class,
    GatewayServiceProvider::class,
    RescueSweepConfigServiceProvider::class,
    RetryConfigServiceProvider::class,
];
