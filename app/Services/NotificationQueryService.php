<?php

namespace App\Services;

use App\Models\Notification;
use App\Repositories\NotificationRepositoryInterface;
use App\Repositories\SubscriberRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationQueryService
{
    public function __construct(
        private readonly NotificationRepositoryInterface $notifications,
        private readonly SubscriberRepositoryInterface $subscribers,
    ) {}

    public function getNotification(string $id): Notification
    {
        return $this->notifications->findById($id);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Notification>
     */
    public function paginateForSubscriber(string $subscriberId, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->subscribers->findById($subscriberId);

        return $this->notifications->paginateBySubscriber($subscriberId, $filters, $perPage);
    }
}
