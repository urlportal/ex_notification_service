<?php

namespace App\Services\Callbacks\Auth;

use App\Exceptions\Callbacks\InvalidCallbackSignatureException;
use Illuminate\Http\Request;

interface CallbackAuthenticatorInterface
{
    /**
     * @throws InvalidCallbackSignatureException
     */
    /** @param array<string, mixed> $adapterConfig */
    public function verify(Request $request, array $adapterConfig): void;
}
