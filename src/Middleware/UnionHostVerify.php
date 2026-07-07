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

        // Fetch the union's public key (a connection failure is treated as a verification failure)
        try {
            $publicKey = Http::timeout(5.0)->get(option('union_api_root'))->json('union_host_signature_public_key');
        } catch (\Exception $e) {
            Log::channel('ygg')->info('Union host verification failure: Cannot fetch public key. '.$e->getMessage());
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        if (! $publicKey) {
            Log::channel('ygg')->info('Union host verification failure: Public key missing in upstream response.');
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        if (openssl_verify($body.$timestamp.$nonce, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            Log::channel('ygg')->info('Union host verification failure: Invalid signature.');
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        Cache::put('union_host_signature_'.$nonce, $signature, 60);

        return $next($request);
    }
}
