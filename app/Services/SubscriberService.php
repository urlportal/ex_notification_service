<?php

namespace App\Services;

use App\Exceptions\AlreadyExistsException;
use App\Http\DTO\Subscribers\SubscriberDto;
use App\Models\Subscriber;
use App\Repositories\SubscriberRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class SubscriberService
{
    public function __construct(
        private readonly SubscriberRepositoryInterface $repository,
    ) {}

    public function create(SubscriberDto $dto): Subscriber
    {
        if ($dto->phone !== null && $this->repository->existsByPhone($dto->phone)) {
            throw new AlreadyExistsException('The phone has already been taken.');
        }

        if ($dto->email !== null && $this->repository->existsByEmail($dto->email)) {
            throw new AlreadyExistsException('The email has already been taken.');
        }

        return $this->repository->create($dto);
    }

    public function findById(string $id): Subscriber
    {
        return $this->repository->findById($id);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Subscriber>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($filters);
    }

    public function update(string $id, SubscriberDto $dto): Subscriber
    {
        $subscriber = $this->repository->findById($id);

        if ($dto->phone !== null && $this->repository->existsByPhone($dto->phone, $id)) {
            throw new AlreadyExistsException('The phone has already been taken.');
        }

        if ($dto->email !== null && $this->repository->existsByEmail($dto->email, $id)) {
            throw new AlreadyExistsException('The email has already been taken.');
        }

        return $this->repository->update($subscriber, $dto);
    }

    public function delete(string $id): void
    {
        $subscriber = $this->repository->findById($id);
        $this->repository->delete($subscriber);
    }
}
