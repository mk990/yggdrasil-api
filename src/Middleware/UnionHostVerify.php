<?php

namespace Yggdrasil\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Yggdrasil\Exceptions\ForbiddenOperationException;

/**
 * Verifies signed requests on trusted callbacks (api/union/member/*) from the MUA union's
 * central server into this site.
 *
 * The central server attaches three headers on every callback, X-Message-Signature/Timestamp/Nonce:
 *   - signature = base64( SHA256withRSA(body + timestamp + nonce) )
 *   - the verification public key is fetched from the union_host_signature_public_key field of GET {union_api_root}
 *   - timestamp is tolerated within -10s ~ +30s; a nonce cannot be replayed within 60s
 */
class UnionHostVerify
{
    const PUBLIC_KEY_CACHE_KEY = 'union_host_signature_public_key';
    const PUBLIC_KEY_CACHE_TTL = 300; // 5 minutes

    public function handle($request, Closure $next)
    {
        $signature = $request->header('X-Message-Signature');
        $timestamp = $request->header('X-Message-Timestamp');
        $nonce = $request->header('X-Message-Nonce');
        $body = $request->getContent();

        if (! $signature || ! $timestamp || ! $nonce) {
            Log::channel('ygg')->info('Union host verification failure: Missing signature headers.');
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        // Anti-replay: the same nonce is only accepted once within 60s
        if (Cache::has('union_host_signature_'.$nonce)) {
            Log::channel('ygg')->info('Union host verification failure: Invalid nonce.');
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        // Timestamp skew check
        if ($timestamp < time() - 10 || $timestamp > time() + 30) {
            Log::channel('ygg')->info('Union host verification failure: Invalid timestamp.');
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        $payload = $body.$timestamp.$nonce;
        $decodedSignature = base64_decode($signature);

        $publicKey = $this->fetchPublicKey();
        if (! $publicKey) {
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        if (openssl_verify($payload, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            // The cached key may be stale if the hub rotated its signing key since we last cached
            // it. Refetch once and retry before giving up, so a legitimate rotation doesn't have
            // to wait out the cache TTL.
            $freshKey = $this->fetchPublicKey(true);
            $verified = $freshKey && $freshKey !== $publicKey
                && openssl_verify($payload, $decodedSignature, $freshKey, OPENSSL_ALGO_SHA256) === 1;

            if (! $verified) {
                Log::channel('ygg')->info('Union host verification failure: Invalid signature.');
                throw new ForbiddenOperationException('Union host verification failure.');
            }
        }

        Cache::put('union_host_signature_'.$nonce, $signature, 60);

        return $next($request);
    }

    /**
     * Fetch the central server's host-callback signature public key, cached for
     * self::PUBLIC_KEY_CACHE_TTL seconds so we don't round-trip to the hub on every request.
     * Pass $forceRefresh to bypass the cache, e.g. after a signature verification failure that
     * might be caused by the hub having rotated its key since we last cached it.
     */
    protected function fetchPublicKey(bool $forceRefresh = false): ?string
    {
        if (! $forceRefresh) {
            $cached = Cache::get(self::PUBLIC_KEY_CACHE_KEY);
            if ($cached) {
                return $cached;
            }
        }

        try {
            $publicKey = Http::timeout(5.0)->get(option('union_api_root'))->json('union_host_signature_public_key');
        } catch (\Exception $e) {
            Log::channel('ygg')->info('Union host verification failure: Cannot fetch public key. '.$e->getMessage());
            return null;
        }

        if (! $publicKey) {
            Log::channel('ygg')->info('Union host verification failure: Public key missing in upstream response.');
            return null;
        }

        Cache::put(self::PUBLIC_KEY_CACHE_KEY, $publicKey, self::PUBLIC_KEY_CACHE_TTL);

        return $publicKey;
    }
}
