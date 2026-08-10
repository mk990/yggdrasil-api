<?php

namespace Yggdrasil\Controllers;

use DB;
use Log;
use Cache;
use Schema;
use App\Models\User;
use App\Models\Player;
use Yggdrasil\Models\Token;
use Illuminate\Http\Request;
use Yggdrasil\Models\Profile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Yggdrasil\Exceptions\ForbiddenOperationException;
use Yggdrasil\Utils\UnionClient;

class SessionController extends Controller
{
    public function joinServer(Request $request)
    {
        $accessToken = $request->input('accessToken');
        $selectedProfile = $request->input('selectedProfile');
        $serverId = $request->input('serverId');

        Log::channel('ygg')->info("Player [$selectedProfile] is trying to join server [$serverId] with access token [$accessToken]");

        $result = DB::table('uuid')->where('uuid', $selectedProfile)->first();

        if (! $result) {
            // Reportedly Mojang returns 403 in this case
            throw new ForbiddenOperationException(
                trans('Yggdrasil::exceptions.uuid', ['profile' => $selectedProfile])
            );
        }

        $player = Player::where('name', $result->name)->first();

        if (! $player) {
            // Delete the now-stale UUID mapping (e.g. the corresponding character was deleted)
            DB::table('uuid')->where('uuid', $selectedProfile)->delete();

            throw new ForbiddenOperationException(
                trans('Yggdrasil::exceptions.uuid', ['profile' => $selectedProfile])
            );
        }

        $identification = strtolower($player->user->email);

        Log::channel('ygg')->info("Player [$selectedProfile]'s name is [$player->name], belongs to user [$identification]");

        $token = Token::lookup($accessToken);
        if ($token && $token->isValid()) {

            Log::channel('ygg')->info("All access tokens issued for user [$identification] are as listed", [$token]);

            if ($token->accessToken != $accessToken) {
                throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.token.invalid'));
            }

            if ($token->profileId != $selectedProfile) {
                throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.player.not-matched'));
            }

            if ($player->user->permission == User::BANNED) {
                throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.user.banned'));
            }

            // Joined the server; cache for 120 seconds (matches the hasJoinedServer side)
            Cache::put("SERVER_$serverId", ['profile' => $selectedProfile, 'ip' => $request->ip()], 120);
        } else {
            // The user owning the specified character hasn't issued any token
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.token.missing'));
        }

        Log::channel('ygg')->info("Player [$selectedProfile] successfully joined the server [$serverId]");

        ygg_log([
            'action' => 'join',
            'user_id' => $player->uid,
            'player_id' => $player->pid,
            'parameters' => json_encode($request->except('accessToken')),
        ]);

        return response('')->setStatusCode(204);
    }

    public function hasJoinedServer(Request $request)
    {
        $name = $request->input('username');
        $serverId = $request->input('serverId');
        $ip = $request->input('ip');

        Log::channel('ygg')->info("Checking if player [$name] has joined the server [$serverId] with IP [$ip]");

        // Check whether an external-login join request was made
        if ($session = Cache::get("SERVER_$serverId")) {
            $cachedProfile = is_array($session) ? ($session['profile'] ?? null) : $session;
            $cachedIp     = is_array($session) ? ($session['ip'] ?? null) : null;

            $profile = $cachedProfile ? Profile::createFromUuid($cachedProfile) : null;

            if ($profile && $name === $profile->name) {
                // IP check: only compare when both sides have an IP, skip if either is missing
                if ($ip && $cachedIp && $ip !== $cachedIp) {
                    Log::channel('ygg')->warning("Player [$name] IP mismatch: expected [$cachedIp], got [$ip]");
                    return response('')->setStatusCode(204);
                }

                Cache::forget("SERVER_$serverId");
                Log::channel('ygg')->info("Player [$name] was in the server [$serverId]");

                $response = $profile->serialize(false);
                Log::channel('ygg')->info("Returning player [$name]'s profile", [$response]);

                ygg_log(array_merge([
                    'action' => 'has_joined',
                    'user_id' => $profile->player->uid,
                    'player_id' => $profile->player->pid,
                    'parameters' => json_encode($request->except('username')),
                ], ($ip ? compact('ip') : [])));

                return response()->json()->setContent($response);
            }
        }

        // No local cache hit; try forwarding verification to Mojang (premium account fallback)
        if (Schema::hasTable('mojang_verifications')) {
            $profile = $this->hasJoinedMojang($name, $serverId);
            if ($profile) {
                Log::channel('ygg')->info("Player [$name] verified via Mojang, returning bound profile [{$profile->name}]");

                $response = $profile->serialize(false);

                ygg_log(array_merge([
                    'action' => 'has_joined',
                    'user_id' => $profile->player->uid,
                    'player_id' => $profile->player->pid,
                    'parameters' => json_encode($request->except('username')),
                ], ($ip ? compact('ip') : [])));

                return response()->json()->setContent($response);
            }
        }

        // Mojang didn't hit either: forward to the MUA central server so it can fan out to union member sites.
        // Profiles signed out by the central server use the union's shared private key, so our public key can verify them.
        if (option('union_member_key') !== '') {
            Log::channel('ygg')->info("Forwarding hasJoined for player [$name] to MUA union upstream.");
            $forwarded = $this->hasJoinedUnion($name, $serverId, $ip);
            if ($forwarded !== null) {
                // Name-collision handling: the MUA central server's hasJoined doesn't rewrite the response's
                // `name`, so when there's a duplicate name within the union, a local player and a cross-site
                // player would join the same proxy under the same name, triggering "you already connected to
                // the proxy". Following the same approach the MUA main site uses as authlib: append a `_MUA`
                // suffix to the cross-site player's name in the hasJoined response, and correspondingly rewrite
                // the embedded `profileName` in `properties[].value` and re-sign it with this site's (= the
                // union's shared) private key.
                $forwardedUuid = strtolower(str_replace('-', '', $forwarded['id'] ?? ''));
                $forwardedName = $forwarded['name'] ?? '';
                $localCollision = DB::table('uuid')
                    ->whereRaw('LOWER(name) = ?', [strtolower($forwardedName)])
                    ->whereRaw('LOWER(REPLACE(uuid, ?, ?)) <> ?', ['-', '', $forwardedUuid])
                    ->exists();

                if ($localCollision) {
                    $newName = $this->resolveCollisionName($forwardedName, $forwardedUuid);
                    Log::channel('ygg')->warning(
                        "Cross-site name collision: union profile [$forwardedName / $forwardedUuid] ".
                        "conflicts with a local player. Renaming forwarded profile to [$newName] and resigning."
                    );
                    $forwarded = $this->renameAndResign($forwarded, $newName);
                }

                Log::channel('ygg')->info("Player [$name] verified via MUA union, returning forwarded profile.");
                return response()->json($forwarded);
            }
        }

        Log::channel('ygg')->info("Player [$name] was not in the server [$serverId]");
        return response('')->setStatusCode(204);
    }

    /**
     * Passes hasJoined through to the MUA central server. Returns the JSON array as-is
     * (including properties[].signature), or null if the central server returns 204/5xx
     * or an exception occurs, so the caller falls back to the default 204.
     */
    protected function hasJoinedUnion(string $name, string $serverId, ?string $ip): ?array
    {
        $apiRoot = option('union_api_root');
        if (! $apiRoot) {
            return null;
        }

        // union_api_root looks like https://skin.mualliance.ltd/api/union; the union's central
        // server is itself a yggdrasil implementation, with hasJoined under /api/yggdrasil.
        $base = preg_replace('#/api/union/?$#', '', rtrim($apiRoot, '/'));
        $url  = $base.'/api/yggdrasil/sessionserver/session/minecraft/hasJoined';

        $query = ['username' => $name, 'serverId' => $serverId];
        if ($ip) {
            $query['ip'] = $ip;
        }

        try {
            $response = Http::timeout(5.0)->get($url, $query);
        } catch (\Exception $e) {
            Log::channel('ygg')->warning("Union hasJoined forwarding failed: ".$e->getMessage());
            return null;
        }

        if ($response->status() !== 200) {
            return null;
        }

        $profile = $response->json();
        if (! is_array($profile) || empty($profile['id']) || empty($profile['name'])) {
            return null;
        }

        Log::channel('ygg')->info("Union hasJoined forwarded profile.", [$profile]);

        return $profile;
    }

    protected function hasJoinedMojang(string $name, string $serverId): ?Profile
    {
        try {
            $response = Http::get('https://sessionserver.mojang.com/session/minecraft/hasJoined', [
                'username' => $name,
                'serverId' => $serverId,
            ]);

            if ($response->status() !== 200) {
                return null;
            }

            $mojangUuid = str_replace('-', '', $response->json('id') ?? '');
            if (! $mojangUuid) {
                return null;
            }

            Log::channel('ygg')->info("Mojang verified player [$name] with uuid [$mojangUuid]");

            $binding = DB::table('mojang_verifications')
                ->where('mojang_uuid', $mojangUuid)
                ->first();

            if (! $binding) {
                // Check whether there's a pending bind request, and auto-complete the binding if so
                if (Schema::hasTable('pending_mojang_bind')) {
                    $query = DB::table('pending_mojang_bind')
                        ->where('created_at', '>=', now()->subMinutes(15));

                    if (Schema::hasColumn('pending_mojang_bind', 'mojang_uuid')) {
                        // Prefer matching by UUID: unaffected by case or renames.
                        // Older bind requests without a UUID fall back to matching by name (case-insensitive).
                        $query->where(function ($q) use ($mojangUuid, $name) {
                            $q->where('mojang_uuid', $mojangUuid)
                                ->orWhere(function ($q2) use ($name) {
                                    $q2->whereNull('mojang_uuid')
                                        ->whereRaw('LOWER(mojang_name) = ?', [strtolower($name)]);
                                });
                        });
                    } else {
                        $query->whereRaw('LOWER(mojang_name) = ?', [strtolower($name)]);
                    }

                    $pending = $query->orderBy('created_at', 'desc')->first();

                    if ($pending) {
                        $pendingUser = User::find($pending->user_id);
                        $pendingPlayer = $pendingUser && $pendingUser->permission != User::BANNED
                            ? Player::where('uid', $pending->user_id)->first()
                            : null;

                        if (! $pendingPlayer) {
                            Log::channel('ygg')->warning("Pending bind for user [{$pending->user_id}] has no player character — create a character on the skin server first.");
                        }

                        if ($pendingPlayer) {
                            DB::table('mojang_verifications')->updateOrInsert(
                                ['user_id' => $pending->user_id],
                                ['mojang_uuid' => $mojangUuid]
                            );
                            DB::table('pending_mojang_bind')
                                ->where('user_id', $pending->user_id)
                                ->delete();

                            Log::channel('ygg')->info("Auto-bound Mojang [$mojangUuid / $name] to bs user [{$pending->user_id}]");
                            return Profile::createFromPlayer($pendingPlayer);
                        }
                    }
                }

                Log::channel('ygg')->info("Mojang uuid [$mojangUuid] has no binding, rejecting.");
                return null;
            }

            $user = User::find($binding->user_id);
            if (! $user || $user->permission == User::BANNED) {
                return null;
            }

            $player = Player::where('uid', $binding->user_id)->first();
            if (! $player) {
                Log::channel('ygg')->warning("Bound user [{$binding->user_id}] has no player character — create a character on the skin server.");
                return null;
            }

            return Profile::createFromPlayer($player);
        } catch (\Exception $e) {
            Log::channel('ygg')->warning("Mojang hasJoined forwarding failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Renames a forwarded profile to $newName and re-signs properties[].value.
     *
     * What gets rewritten:
     *   - the top-level `name`
     *   - `properties[].value` (after base64-decoding, the embedded JSON also has a `profileName`
     *     field that needs to be kept in sync)
     *   - `properties[].signature` (re-signed with this site's = the union's shared private key)
     *
     * Every member of the union verifies signatures against the same public key, so it will validate.
     */
    protected function renameAndResign(array $profile, string $newName): array
    {
        $profile['name'] = $newName;

        $key = openssl_pkey_get_private(option('ygg_private_key'));
        if (! $key) {
            Log::channel('ygg')->warning('Cannot resign forwarded profile: private key invalid; returning unsigned rename.');
            // A launcher usually can't join without a valid signature anyway — we can't rescue this case,
            // but at least the rename itself has been applied.
            foreach ($profile['properties'] ?? [] as &$prop) {
                if (($prop['name'] ?? '') === 'textures') {
                    $decoded = json_decode(base64_decode($prop['value']), true);
                    if (is_array($decoded)) {
                        $decoded['profileName'] = $newName;
                        $prop['value'] = base64_encode(ygg_encode_texture_payload($decoded));
                    }
                }
                unset($prop['signature']);
            }
            unset($prop);
            return $profile;
        }

        foreach ($profile['properties'] ?? [] as &$prop) {
            if (($prop['name'] ?? '') === 'textures') {
                $decoded = json_decode(base64_decode($prop['value']), true);
                if (is_array($decoded)) {
                    $decoded['profileName'] = $newName;
                    $prop['value'] = base64_encode(ygg_encode_texture_payload($decoded));
                }
            }

            openssl_sign($prop['value'], $signature, $key);
            $prop['signature'] = base64_encode($signature);
        }
        unset($prop);

        openssl_free_key($key);

        return $profile;
    }

    /**
     * Picks a locally non-conflicting new name for a cross-site profile when there's a name collision.
     *
     * Suffix source: queries the MUA central server's `/profile/unmapped/byuuid/{uuid}` and takes
     * `backend_scopes.self` as the short code of the skin site the profile belongs to (e.g. MUA / SJMC / PKUMC).
     *
     * Falls back to a `_UNION` suffix if the central-server lookup fails or no code is returned.
     * If the suffixed name still collides, keeps appending `2`, `3`, ... until it doesn't.
     */
    protected function resolveCollisionName(string $original, string $uuid): string
    {
        $code = $this->fetchUnionBackendCode($uuid) ?: 'UNION';
        $candidate = $original.'_'.$code;
        $i = 2;
        while (DB::table('uuid')->whereRaw('LOWER(name) = ?', [strtolower($candidate)])->exists()) {
            $candidate = $original.'_'.$code.$i;
            $i++;
        }
        return $candidate;
    }

    /**
     * Queries the MUA central server for the site code a profile belongs to (e.g. "MUA" / "SJMC" / "PKUMC").
     * Returns null on failure, letting the caller fall back to the default suffix.
     */
    protected function fetchUnionBackendCode(string $uuid): ?string
    {
        $apiRoot = option('union_api_root');
        $memberKey = option('union_member_key');
        if (! $apiRoot || ! $memberKey) {
            return null;
        }

        $url = rtrim($apiRoot, '/').'/profile/unmapped/byuuid/'.$uuid;

        try {
            $response = UnionClient::request('get', $url, null, 3.0);
        } catch (\Exception $e) {
            Log::channel('ygg')->warning("Union byuuid lookup failed for [$uuid]: ".$e->getMessage());
            return null;
        }

        if ($response->status() !== 200) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data) || empty($data)) {
            return null;
        }

        // The endpoint returns an array; we expect the first item to match, and take backend_scopes.self
        $code = $data[0]['backend_scopes']['self'] ?? null;
        if (! is_string($code) || $code === '') {
            return null;
        }

        return $code;
    }

}
