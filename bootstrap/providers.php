<?php

use App\Providers\AppServiceProvider;
use App\Providers\CallbackServiceProvider;
use App\Providers\GatewayServiceProvider;
use App\Providers\RetryConfigServiceProvider;

return [
    AppServiceProvider::class,
    CallbackServiceProvider::class,
    GatewayServiceProvider::class,
    RetryConfigServiceProvider::class,
];
