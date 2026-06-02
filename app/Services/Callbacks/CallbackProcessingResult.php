<?php

namespace App\Services\Callbacks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final readonly class CallbackProcessingResult
{
    private function __construct(private string $type) {}

    public static function updated(): self
    {
        return new self('updated');
    }

    public static function alreadyFinalized(): self
    {
        return new self('already_finalized');
    }

    public function toResponse(): Response|JsonResponse
    {
        if ($this->type === 'updated') {
            return response()->noContent();
        }

        return response()->json(['message' => 'Already finalized']);
    }
}
