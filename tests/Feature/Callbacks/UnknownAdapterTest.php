<?php

namespace Tests\Feature\Callbacks;

class UnknownAdapterTest extends CallbackTestCase
{
    public function test_unknown_adapter_is_rejected_by_router_with_404(): void
    {
        $response = $this->postJson('/api/callbacks/unknown-provider', [
            'event-data' => ['event' => 'delivered'],
        ]);

        $response->assertNotFound();
    }
}
