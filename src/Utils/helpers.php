<?php

use Carbon\Carbon;
use Vectorface\Whip\Whip;

if (! function_exists('ygg_log_path')) {

    function ygg_log_path()
    {
        $dbConfig = config('database.connections.'.config('database.default'));
        $mask = substr(md5(json_encode($dbConfig)), 0, 8);

        return storage_path("logs/yggdrasil-$mask.log");
    }
}

if (! function_exists('ygg_generate_rsa_keys')) {

    function ygg_generate_rsa_keys($config = [])
    {
        $config = array_merge($config, [
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => plugin('yggdrasil-api')->getPath().'/assets/openssl.cnf'
        ]);

        $res = openssl_pkey_new($config);

        if (! $res) {
            throw new Exception(openssl_error_string(), 1);
        }

        openssl_pkey_export($res, $privateKey, null, $config);

        return [
            'private' => $privateKey,
            'public'  => openssl_pkey_get_details($res)['key']
        ];
    }
}

if (! function_exists('ygg_encode_texture_payload')) {

    /**
     * Encode the `textures` property payload exactly the way Mojang's session server does:
     * Gson pretty printing, two-space indent, " : " between key and value.
     *
     * A compact json_encode() is perfectly valid JSON, but a lot of third-party tooling
     * (player-head mods in particular) never decodes the base64 at all — it slices the
     * base64 string on a hardcoded token like `cHJvZmlsZUlk` ("profileId"). That token only
     * appears when "profileId" starts on a three-byte boundary, which Mojang's formatting
     * guarantees and compact JSON does not.
     */
    function ygg_encode_texture_payload($value, $depth = 0)
    {
        if (! is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        if ($value === []) {
            return '{}';
        }

        $indent = str_repeat('  ', $depth + 1);
        $entries = [];

        foreach ($value as $key => $item) {
            $entries[] = $indent
                .json_encode((string) $key, JSON_UNESCAPED_SLASHES)
                .' : '
                .ygg_encode_texture_payload($item, $depth + 1);
        }

        return "{\n".implode(",\n", $entries)."\n".str_repeat('  ', $depth).'}';
    }
}

if (! function_exists('ygg_log_http_request_and_response')) {

    function ygg_log_http_request_and_response()
    {
        Log::channel('ygg')->info('============================================================');
        Log::channel('ygg')->info(request()->method(), [request()->path()]);

        Event::listen('kernel.handled', function ($request, $response) {
            $statusCode = $response->getStatusCode();
            $statusText = Symfony\Component\HttpFoundation\Response::$statusTexts[$statusCode];
            Log::channel('ygg')->info(sprintf('HTTP/%s %s %s', $response->getProtocolVersion(), $statusCode, $statusText));
        });
    }
}

if (! function_exists('ygg_log')) {

    function ygg_log($params)
    {
        // This feeds the admin log page and is always recorded; YGG_VERBOSE_LOG only
        // controls the extra request/response dump written to storage/logs.
        $data = array_merge([
            'action' => 'undefined',
            'user_id' => 0,
            'player_id' => 0,
            'parameters' => '[]',
            'ip' => (new Whip())->getValidIpAddress(),
            'time' => Carbon::now(),
        ], $params);

        return DB::table('ygg_log')->insert($data);
    }
}
