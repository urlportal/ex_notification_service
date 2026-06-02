<?php

namespace App\Services\Callbacks\Auth;

use App\Exceptions\Callbacks\InvalidCallbackSignatureException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

class IpWhitelistAuthenticator implements CallbackAuthenticatorInterface
{
    /** @param array<string, mixed> $adapterConfig */
    public function verify(Request $request, array $adapterConfig): void
    {
        $clientIp = (string) $request->ip();
        $rawCidrs = $adapterConfig['allowed_cidrs'] ?? null;

        $cidrs = array_values(array_filter(
            array_map('trim', explode(',', (string) $rawCidrs)),
            fn (string $cidr) => $cidr !== '',
        ));

        if (empty($cidrs) || ! IpUtils::checkIp($clientIp, $cidrs)) {
            Log::warning('Callback authentication failed', [
                'adapter' => $adapterConfig['adapter'] ?? 'unknown',
                'reason' => 'ip_not_whitelisted',
                'client_ip' => $clientIp,
            ]);

            throw new InvalidCallbackSignatureException('ip_not_whitelisted');
        }
    }
}
