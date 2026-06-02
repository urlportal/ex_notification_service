<?php

namespace Tests\Feature\Callbacks;

use Illuminate\Support\Facades\DB;

class IgnoredEventTest extends CallbackTestCase
{
    public function test_mailgun_opened_event_returns_204_without_db_changes(): void
    {
        $notification = $this->createNotification();
        $originalStatus = $notification->status->value;

        DB::enableQueryLog();

        $response = $this->postJson(
            '/api/callbacks/mailgun',
            $this->mailgunOpenedPayload((string) $notification->provider_message_id)
        );

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertNoContent();

        $updateQueries = array_filter(
            $queries,
            fn (array $q) => str_contains(strtolower($q['query']), 'update')
        );
        $this->assertEmpty($updateQueries, 'Не должно быть UPDATE-запросов для игнорируемого события');

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => $originalStatus,
        ]);
    }
}
