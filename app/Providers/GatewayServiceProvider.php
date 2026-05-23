<?php

namespace App\Providers;

use App\Gateways\EmailGatewayInterface;
use App\Gateways\GatewayFactory;
use App\Gateways\PhpRandomProvider;
use App\Gateways\RandomProviderInterface;
use App\Gateways\SmsGatewayInterface;
use App\Gateways\Stubs\EmailGatewayStub;
use App\Gateways\Stubs\SmsGatewayStub;
use Illuminate\Support\ServiceProvider;

class GatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GatewayFactory::class);

        if ($this->app->environment('production')) {
            return;
        }

        $this->app->bind(RandomProviderInterface::class, PhpRandomProvider::class);
        $this->app->bind(SmsGatewayInterface::class, SmsGatewayStub::class);
        $this->app->bind(EmailGatewayInterface::class, EmailGatewayStub::class);
    }
}
