<?php

namespace Yggdrasil\Controllers;

use DB;
use Schema;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;

class MojangBindController extends Controller
{
    public function index()
    {
        $uid = auth()->user()->uid;

        $binding = Schema::hasTable('mojang_verifications')
            ? DB::table('mojang_verifications')->where('user_id', $uid)->first()
            : null;

        $pending = Schema::hasTable('pending_mojang_bind')
            ? DB::table('pending_mojang_bind')
                ->where('user_id', $uid)
                ->where('created_at', '>=', now()->subMinutes(15))
                ->first()
            : null;

        $hasPlayer = Player::where('uid', $uid)->exists();

        return view('Yggdrasil::bind', [
            'binding'   => $binding,
            'pending'   => $pending,
            'hasPlayer' => $hasPlayer,
            'success'   => session('success'),
            'error'     => session('error'),
        ]);
    }

    public function requestBind(Request $request)
    {
        $mojangName = trim($request->input('mojang_name', ''));

        if (! preg_match('/^[a-zA-Z0-9_]{1,16}$/', $mojangName)) {
            return redirect(url('yggdrasil/mojang/bind'))
                ->with('error', trans('Yggdrasil::bind.error.invalid_name'));
        }

        $uid = auth()->user()->uid;

        if (! Player::where('uid', $uid)->exists()) {
            return redirect(url('yggdrasil/mojang/bind'))
                ->with('error', trans('Yggdrasil::bind.error.no_player'));
        }

        if (Schema::hasTable('mojang_verifications') &&
            DB::table('mojang_verifications')->where('user_id', $uid)->exists()) {
            return redirect(url('yggdrasil/mojang/bind'))
                ->with('error', trans('Yggdrasil::bind.error.already_bound'));
        }

        // Query Mojang for the canonical username and UUID: this both confirms the account really
        // exists and resolves any case-sensitivity ambiguity.
        $resolved = $this->resolveMojangProfile($mojangName);

        if (! $resolved) {
            return redirect(url('yggdrasil/mojang/bind'))
                ->with('error', trans('Yggdrasil::bind.error.mojang_not_found', ['name' => $mojangName]));
        }

        // Check whether this premium account is already bound by another user
        if (Schema::hasTable('mojang_verifications') &&
            DB::table('mojang_verifications')
                ->where('mojang_uuid', $resolved['uuid'])
                ->where('user_id', '!=', $uid)
                ->exists()) {
            return redirect(url('yggdrasil/mojang/bind'))
                ->with('error', trans('Yggdrasil::bind.error.mojang_taken'));
        }

        // Clean up any expired bind requests
        DB::table('pending_mojang_bind')
            ->where('created_at', '<', now()->subMinutes(15))
            ->delete();

        $values = ['mojang_name' => $resolved['name'], 'created_at' => now()];
        if (Schema::hasColumn('pending_mojang_bind', 'mojang_uuid')) {
            $values['mojang_uuid'] = $resolved['uuid'];
        }

        DB::table('pending_mojang_bind')->updateOrInsert(['user_id' => $uid], $values);

        return redirect(url('yggdrasil/mojang/bind'))
            ->with('success', trans('Yggdrasil::bind.success.request_submitted', ['name' => $resolved['name']]));
    }

    /**
     * Query Mojang for the canonical username and UUID of a premium account.
     * Returns null if the account isn't found or a network error occurs.
     */
    protected function resolveMojangProfile(string $name): ?array
    {
        try {
            $response = Http::get('https://api.mojang.com/users/profiles/minecraft/'.$name);

            if ($response->status() !== 200) {
                return null;
            }

            $uuid = str_replace('-', '', $response->json('id') ?? '');
            $canonical = $response->json('name');

            if (! $uuid || ! $canonical) {
                return null;
            }

            return ['uuid' => $uuid, 'name' => $canonical];
        } catch (\Exception $e) {
            return null;
        }
    }

    public function cancelBind()
    {
        DB::table('pending_mojang_bind')
            ->where('user_id', auth()->user()->uid)
            ->delete();

        return redirect(url('yggdrasil/mojang/bind'))
            ->with('success', trans('Yggdrasil::bind.success.cancelled'));
    }

    public function unbind()
    {
        DB::table('mojang_verifications')
            ->where('user_id', auth()->user()->uid)
            ->delete();

        return redirect(url('yggdrasil/mojang/bind'))
            ->with('success', trans('Yggdrasil::bind.success.unbound'));
    }
}
