<?php

use App\Events\PlayerWasAdded;
use App\Events\PlayerWillBeDeleted;
use App\Services\Hook;
use Blessing\Filter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Yggdrasil\Models\Profile;

require __DIR__.'/src/Utils/helpers.php';

return function (Filter $filter, Dispatcher $events) {
    if (env('YGG_VERBOSE_LOG')) {
        config(['logging.channels.ygg' => [
            'driver' => 'single',
            'path' => ygg_log_path(),
        ]]);
    } else {
        config(['logging.channels.ygg' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ]]);
    }

    // Sites upgraded from an older version keep using the old UUID generation algorithm by default
    if (DB::table('uuid')->count() > 0 && !Option::get('ygg_uuid_algorithm')) {
        Option::set('ygg_uuid_algorithm', 'v4');
    }

    // Auto-generate a private key on first use
    if (option('ygg_private_key') == '') {
        option(['ygg_private_key' => ygg_generate_rsa_keys()['private']]);
    }

    // Log request/response details
    if (request()->is('api/yggdrasil/*')) {
        ygg_log_http_request_and_response();
    }

    // Keep the UUID consistent after a user renames their character
    $callback = function ($model) {
        $new = $model->getAttribute('name');
        $original = $model->getOriginal('name');

        if (!$original || $original === $new) return;

        // If we got here, the new name is no longer used by anyone else,
        // so it's safe to delete any leftover UUID mapping for it.
        DB::table('uuid')->where('name', $new)->delete();
        DB::table('uuid')->where('name', $original)->update(['name' => $new]);
    };

    // Only keep the UUID consistent after a rename when the "randomly generated" algorithm is in use,
    // since the other algorithm is meant to stay maximally compatible with offline mode, so it's left alone.
    if (option('ygg_uuid_algorithm') == 'v4') {
        App\Models\Player::updating($callback);
    }

    // ===== MUA union authentication: sync character changes to the central server =====
    // Only attempt to sync when union_member_key is configured, to avoid pointless requests in local dev / non-member environments.
    $unionPush = function (string $method, string $url, array $payload = null) {
        if (option('union_member_key') === '') {
            return;
        }
        try {
            $req = Http::timeout(5.0)->withHeaders(['X-Union-Member-Key' => option('union_member_key')]);
            $response = $payload === null ? $req->{$method}($url) : $req->{$method}($url, $payload);
            if (! $response->successful()) {
                Log::channel('ygg')->info('Union sync failed.', [
                    'method' => $method, 'url' => $url, 'status' => $response->status(),
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('ygg')->info('Union sync exception: '.$e->getMessage(), ['url' => $url]);
        }
    };

    $events->listen(PlayerWasAdded::class, function ($event) use ($unionPush) {
        $player = $event->player;
        $uuid = Profile::getUuidFromName($player->name);
        $unionPush('post', option('union_api_root').'/profile', [
            'id' => $uuid,
            'name' => $player->name,
        ]);
        Log::channel('ygg')->info("Player [$player->name] added; union sync issued.");
    });

    $events->listen(PlayerWillBeDeleted::class, function ($event) use ($unionPush) {
        $player = $event->player;
        // Look up the uuid table directly here, to avoid getUuidFromName allocating a new UUID
        // if it hits an empty mapping after the character has already been deleted.
        $row = DB::table('uuid')->where('name', $player->name)->first();
        if ($row) {
            $unionPush('delete', option('union_api_root').'/profile/'.$row->uuid);
            Log::channel('ygg')->info("Player [$player->name] deleted; union sync issued.");
        }
    });

    // Character rename: reconcile with the central server in two cases.
    //   - v4 algorithm: the fork's `updating` hook has already rewritten (old_name, old_uuid) in place
    //     to (new_name, old_uuid); the UUID doesn't change, so a PUT /profile/{uuid} rename suffices.
    //   - v3 algorithm: the new name produces a freshly computed UUID (a namespaced hash of the name),
    //     so the old and new UUIDs differ and we need a DELETE of the old one + POST of the new one.
    $events->listen('player.renamed', function ($player, $old) use ($unionPush) {
        if (! $old || $old->name === $player->name) {
            return;
        }

        $newUuid = Profile::getUuidFromName($player->name);
        $oldRow = DB::table('uuid')->where('name', $old->name)->first();

        if ($oldRow && $oldRow->uuid !== $newUuid) {
            // v3 path: the old UUID is still in the table (the `updating` hook isn't enabled for this algorithm)
            $unionPush('delete', option('union_api_root').'/profile/'.$oldRow->uuid);
            $unionPush('post', option('union_api_root').'/profile', [
                'id' => $newUuid,
                'name' => $player->name,
            ]);
        } else {
            // v4 path: the UUID doesn't change, it's just a rename
            $unionPush('put', option('union_api_root').'/profile/'.$newUuid, [
                'name' => $player->name,
            ]);
        }

        Log::channel('ygg')->info("Player renamed [{$old->name} -> {$player->name}]; union sync issued.");
    });

    // Add a "Quick Launcher Configuration" section to the user center home page
    if (option('ygg_show_config_section')) {
        $filter->add('grid:user.index', function ($grid) {
            $grid['widgets'][0][0][] = 'Yggdrasil::dnd';

            return $grid;
        });
        Hook::addScriptFileToPage(plugin('yggdrasil-api')->assets('dnd.js'), ['user']);
    }

    // Add a "Yggdrasil Logs" item to the admin menu
    Hook::addMenuItem('admin', 4, [
        'title' => 'Yggdrasil::log.title',
        'link'  => 'admin/yggdrasil-log',
        'icon'  => 'fa-history'
    ]);

    // Add a "Bind Premium Account" item to the user center menu
    Hook::addMenuItem('user', 4, [
        'title' => 'Yggdrasil::bind.menu_title',
        'link'  => 'yggdrasil/mojang/bind',
        'icon'  => 'fa-gamepad'
    ]);

    // Register API routes
    Hook::addRoute(function () {
        Route::namespace('Yggdrasil\Controllers')
            ->prefix('api/yggdrasil')
            ->group(function () {
                Route::any('', 'ConfigController@hello');

                require __DIR__.'/routes.php';
            });

        // ===== MUA union authentication: trusted inbound callbacks (accessed with a signature by the central server) =====
        Route::namespace('Yggdrasil\Controllers')->group(function () {
            Route::middleware(['Yggdrasil\Middleware\UnionHostVerify'])
                ->prefix('api/union/member')
                ->group(function () {
                    Route::post('updatelist',       'UnionController@updateList');
                    Route::post('updateprivatekey', 'UnionController@updatePrivateKey');
                    Route::post('updatebackendkey', 'UnionController@serverUpdatesBackendKey');
                    Route::post('sync',             'UnionController@triggerSync');
                    Route::post('remapuuid',        'UnionController@remapUUID');
                    Route::post('diagnose',         'UnionController@diagnose');
                });

            // Union hello (no signature required; lets the central server poll version numbers)
            Route::get('api/union/member', 'UnionController@hello');
        });

        Route::middleware(['web', 'auth', 'role:admin'])
            ->namespace('Yggdrasil\Controllers')
            ->prefix('admin')
            ->group(function () {
                Route::get('yggdrasil-log', 'ConfigController@logPage');

                Route::post(
                    'plugins/config/yggdrasil-api/generate',
                    'ConfigController@generate'
                );

                // Admin manually triggers union sync
                Route::prefix('union')->group(function () {
                    Route::post('member/updatelist',       'UnionController@updateList');
                    Route::post('member/updateprivatekey', 'UnionController@updatePrivateKey');
                    Route::post('member/sync',             'UnionController@triggerSync');
                    Route::post('member/diagnose',         'UnionController@triggerDiagnose');
                });
            });

        // Premium account binding page (accessible to regular users)
        Route::middleware(['web', 'auth'])
            ->namespace('Yggdrasil\Controllers')
            ->prefix('yggdrasil/mojang')
            ->group(function () {
                Route::get('bind', 'MojangBindController@index');
                Route::post('bind', 'MojangBindController@requestBind');
                Route::post('cancel-bind', 'MojangBindController@cancelBind');
                Route::post('unbind', 'MojangBindController@unbind');
            });
    });

    // Globally add the ALI HTTP response header
    if (option('ygg_enable_ali')) {
        $kernel = app()->make(Illuminate\Contracts\Http\Kernel::class);
        $kernel->pushMiddleware(Yggdrasil\Middleware\AddApiIndicationHeader::class);
    }
};
