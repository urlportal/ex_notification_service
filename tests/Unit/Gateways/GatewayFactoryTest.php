<?php

namespace Tests\Unit\Gateways;

use App\Enums\NotificationChannel;
use App\Gateways\EmailGatewayInterface;
use App\Gateways\GatewayFactory;
use App\Gateways\SmsGatewayInterface;
use Tests\TestCase;

class GatewayFactoryTest extends TestCase
{
    private GatewayFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->app->make(GatewayFactory::class);
    }

    public function test_makes_sms_gateway_for_sms_channel(): void
    {
        $gateway = $this->factory->make(NotificationChannel::Sms);

        $this->assertInstanceOf(SmsGatewayInterface::class, $gateway);
    }

    public function test_makes_email_gateway_for_email_channel(): void
    {
        $gateway = $this->factory->make(NotificationChannel::Email);

        $this->assertInstanceOf(EmailGatewayInterface::class, $gateway);
    }

    public function test_throws_invalid_argument_exception_for_unsupported_channel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $factory = new GatewayFactory($this->app);

        // Очищаем channelMap, чтобы любой канал попал в ветку "не зарегистрирован"
        $prop = new \ReflectionProperty(GatewayFactory::class, 'channelMap');
        $original = $prop->getValue();
        $prop->setValue(null, []);

        try {
            $factory->make(NotificationChannel::Sms);
        } finally {
            $prop->setValue(null, $original);
        }
    }
}
