<?php

namespace Yggdrasil\Models;

use DB;
use Log;
use Cache;
use Schema;
use App\Models\Player;
use App\Models\Texture;
use Yggdrasil\Utils\UUID;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Yggdrasil\Exceptions\IllegalArgumentException;

class Profile
{
    public $uuid;
    public $name;
    public $player;
    public $model = "default";
    public $skin;
    public $cape;

    public function sign($data, $key)
    {
        openssl_sign($data, $sign, $key);

        return $sign;
    }

    public function serialize($unsigned = null)
    {
        // Infer from the URL if the `unsigned` parameter isn't explicitly specified
        if (is_null($unsigned)) {
            $unsigned = is_null(request('unsigned')) || request('unsigned') === 'true';
        }

        $textures = [
            'timestamp' => round(microtime(true) * 1000),
            'profileId' => UUID::format($this->uuid),
            'profileName' => $this->name,
            'isPublic' => true,
            'textures' => [],
        ];

        // Check the RSA private key
        if ($unsigned === false) {
            $key = openssl_pkey_get_private(option('ygg_private_key'));

            if (! $key) {
                throw new IllegalArgumentException(
                    trans('Yggdrasil::config.rsa.invalid')
                );
            }

            $textures['signatureRequired'] = true;
        }

        // Avoid a bug that can prevent textures from loading on BungeeCord servers
        app('url')->forceRootUrl(option('site_url'));

        $hasMojangBinding = Schema::hasTable('mojang_verifications') &&
            DB::table('mojang_verifications')->where('user_id', $this->player->uid)->exists();

        if ($this->skin != "") {
            $textures['textures']['SKIN'] = [
                'url' => url("textures/{$this->skin}"),
            ];

            if ($this->model == "slim") {
                $textures['textures']['SKIN']['metadata'] = ['model' => 'slim'];
            }
        } elseif ($hasMojangBinding) {
            // This character has no skin set, fetch it from the bound Mojang account instead.
            $skin = $this->fetchProfileFromMojang('SKIN');
            if ($skin) {
                $textures['textures']['SKIN'] = $skin;
            }
        }

        if ($this->cape != "") {
            $textures['textures']['CAPE'] = [
                'url' => url("textures/{$this->cape}")
            ];
        } elseif ($hasMojangBinding) {
            // This character has no cape set, fetch it from the bound Mojang account instead.
            $cape = $this->fetchProfileFromMojang('CAPE');
            if ($cape) {
                $textures['textures']['CAPE'] = $cape;
            }
        }

        $result = [
            'id' => UUID::format($this->uuid),
            'name' => $this->name,
            'properties' => [
                [
                    'name' => 'textures',
                    'value' => base64_encode(
                        json_encode($textures, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT)
                    ),
                ],
            ],
        ];

        if ($unsigned === false) {
            // Sign each property
            foreach ($result['properties'] as &$prop) {
                $signature = $this->sign($prop['value'], $key);

                $prop['signature'] = base64_encode($signature);
            }

            unset($prop);
            openssl_free_key($key);
        }

        return json_encode($result, JSON_UNESCAPED_SLASHES);
    }

    public function __toString()
    {
        return $this->serialize();
    }

    public static function getUuidFromName($name)
    {
        $result = DB::table('uuid')->where('name', $name)->first();

        if (! $result) {
            // Allocate a new UUID
            $result = UUID::generateMinecraftUuid($name)->clearDashes();
            DB::table('uuid')->insert(['name' => $name, 'uuid' => $result]);

            Log::channel('ygg')->info("New uuid [$result] allocated to player [$name]");
        } else {
            $result = $result->uuid;
        }

        return $result;
    }

    public static function createFromUuid($uuid)
    {
        $result = DB::table('uuid')->where('uuid', $uuid)->first();

        if ($result && ($player = Player::where('name', $result->name)->first())) {
            return static::createFromPlayer($player);
        }
    }

    public static function createFromPlayer(Player $player)
    {
        $profile = new static();
        $model = 'default';
        if ($t = Texture::find($player->tid_skin)) {
            $model = $t->type == 'steve' ? 'default' : 'slim';
        }

        $profile->uuid = static::getUuidFromName($player->name);
        $profile->name = $player->name;
        $profile->model = $model;
        $profile->player = $player;
        $profile->skin = optional($player->skin)->hash;
        $profile->cape = optional($player->cape)->hash;

        return $profile;
    }

    protected function fetchProfileFromMojang($type)
    {
        $type = strtoupper($type);

        $binding = DB::table('mojang_verifications')
            ->where('user_id', $this->player->uid)
            ->first();

        if (! $binding) {
            return null;
        }

        $mojangUuid = $binding->mojang_uuid;

        $profile = Cache::get('mojang_profile_'.$mojangUuid, function () use ($mojangUuid) {
            try {
                $response = Http::get('https://sessionserver.mojang.com/session/minecraft/profile/'.$mojangUuid);
                if ($response->ok()) {
                    $body = $response->json();
                    Cache::put('mojang_profile_'.$mojangUuid, $body, 300);

                    return $body;
                } else {
                    return null;
                }
            } catch (\Exception $e) {
                return null;
            }
        });

        if (! $profile) {
            return null;
        }
        $property = Arr::first($profile['properties'], function ($item) {
            return $item['name'] === 'textures';
        });
        if (! $property) {
            return null;
        }
        return Arr::get(json_decode(base64_decode($property['value']), true)['textures'], $type);
    }
}
