<?php

namespace App\Services\Callbacks\Auth;

use App\Exceptions\Callbacks\InvalidCallbackSignatureException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MailgunSignatureAuthenticator implements CallbackAuthenticatorInterface
{
    /** @param array<string, mixed> $adapterConfig */
    public function verify(Request $request, array $adapterConfig): void
    {
        $clientIp = (string) $request->ip();
        $body = $request->json()->all();

        $signatureBlock = $body['signature'] ?? null;

        $timestamp = isset($signatureBlock['timestamp']) ? (string) $signatureBlock['timestamp'] : null;
        $token = isset($signatureBlock['token']) ? (string) $signatureBlock['token'] : null;
        $signature = isset($signatureBlock['signature']) ? (string) $signatureBlock['signature'] : null;

        if ($timestamp === null || $token === null || $signature === null) {
            $this->logAndThrow($adapterConfig, 'malformed', $clientIp);
        }

        $replayWindow = (int) ($adapterConfig['replay_window_seconds'] ?? 300);
        if (abs(time() - (int) $timestamp) > $replayWindow) {
            $this->logAndThrow($adapterConfig, 'timestamp_out_of_window', $clientIp);
        }

        $signingKey = (string) ($adapterConfig['signing_key'] ?? '');
        $expected = hash_hmac('sha256', $timestamp.$token, $signingKey);

        if (! hash_equals($expected, $signature)) {
            $this->logAndThrow($adapterConfig, 'signature_mismatch', $clientIp);
        }
    }

    /** @param array<string, mixed> $adapterConfig */
    private function logAndThrow(array $adapterConfig, string $reason, string $clientIp): never
    {
        Log::warning('Callback authentication failed', [
            'adapter' => $adapterConfig['adapter'] ?? 'unknown',
            'reason' => $reason,
            'client_ip' => $clientIp,
        ]);

        throw new InvalidCallbackSignatureException($reason);
    }
}
