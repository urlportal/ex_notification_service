<?php

namespace App\Services\Callbacks;

use App\Dto\ProviderCallbackDto;
use App\Enums\NotificationStatus;
use App\Enums\ProviderCallbackStatus;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Repositories\NotificationRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CallbackProcessingService
{
    public function __construct(
        private readonly NotificationRepositoryInterface $notificationRepo,
    ) {}

    public function process(ProviderCallbackDto $dto): CallbackProcessingResult
    {
        $notification = $this->notificationRepo->findByProviderMessageId(
            $dto->providerMessageId,
            $dto->channel,
        );

        if ($notification === null) {
            throw new NotFoundException('Resource not found');
        }

        return DB::transaction(function () use ($dto, $notification) {
            $locked = $this->notificationRepo->lockForUpdateById($notification->id);

            if ($locked === null) {
                throw new NotFoundException('Resource not found');
            }

            if (in_array($locked->status, [NotificationStatus::Delivered, NotificationStatus::Dropped], true)) {
                return CallbackProcessingResult::alreadyFinalized();
            }

            if ($locked->status === NotificationStatus::Queued) {
                throw new ConflictException('Notification has not been sent yet');
            }

            if ($dto->status === ProviderCallbackStatus::Delivered) {
                $this->notificationRepo->markDelivered($locked->id, $dto->occurredAt);
            } else {
                $this->notificationRepo->markDroppedFromCallback($locked->id, $dto->occurredAt, (string) $dto->error);
            }

            Log::info('Callback обработан', [
                'notification_id' => $locked->id,
                'notification_request_id' => $locked->request_id,
                'status' => $dto->status->value,
                'channel' => $dto->channel->value,
            ]);

            return CallbackProcessingResult::updated();
        });
    }
}
