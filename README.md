# Yggdrasil API for Blessing Skin

A [Blessing Skin Server](https://github.com/bs-community/blessing-skin-server) plugin that implements the
[Yggdrasil API specification](https://github.com/yushijinhun/authlib-injector/wiki/Yggdrasil-%E6%9C%8D%E5%8A%A1%E7%AB%AF%E6%8A%80%E6%9C%AF%E8%A7%84%E8%8C%83),
letting your skin site act as a custom authentication server for Minecraft via
[authlib-injector](https://github.com/yushijinhun/authlib-injector).

**This is a fork** that adds two extra capabilities on top of upstream:

- **Official (Mojang) account binding** — a player can link their skin-site character to a real Mojang
  account and join the server straight from the vanilla launcher, no authlib-injector required.
- **MUA union authentication** — this site can join the
  [MUA (Minecraft Union Alliance)](https://skin.mualliance.ltd/) federation, sharing a single signing key
  and player namespace with other member sites so cross-site logins work transparently.

## Requirements

- Blessing Skin Server `^6.0.2`

## Installation

Install and enable the plugin from the admin plugin manager, or place this repository under
`plugins/yggdrasil-api` and enable it from **Admin → Plugin Management**.

On first activation the plugin will:

- create the `uuid`, `ygg_log`, `mojang_verifications`, and `pending_mojang_bind` tables;
- generate a 4096-bit RSA keypair for signing profile data (stored under **Admin → Plugin Settings**);
- seed sensible defaults for token lifetimes, rate limiting, and union settings.

## API Routes

All Yggdrasil routes are served under `/api/yggdrasil`.

```
# Discovery
ANY  /api/yggdrasil                                              ConfigController@hello

# Authentication
POST /api/yggdrasil/authserver/authenticate
POST /api/yggdrasil/authserver/refresh
POST /api/yggdrasil/authserver/validate
POST /api/yggdrasil/authserver/invalidate
POST /api/yggdrasil/authserver/signout

# Session
POST /api/yggdrasil/sessionserver/session/minecraft/join
GET  /api/yggdrasil/sessionserver/session/minecraft/hasJoined

# Profiles
GET  /api/yggdrasil/sessionserver/session/minecraft/profile/{uuid}
POST /api/yggdrasil/api/profiles/minecraft

# Multi-backend re-signing (used by MUA union peers)
GET  /api/yggdrasil/restore
POST /api/yggdrasil/restore
```

Point authlib-injector (or any launcher supporting custom Yggdrasil servers) at
`https://your-site.example/api/yggdrasil`. A drag-and-drop button that generates the correct
`authlib-injector:yggdrasil-server:...` link is available on the user center home page.

## Configuration

All settings live under **Admin → Plugin Settings → Yggdrasil API**.

| Option | Description |
| --- | --- |
| UUID Algorithm | `v3` keeps UUIDs identical to offline-mode servers (recommended for compatibility); `v4` generates random UUIDs and is not offline-mode compatible. |
| Token Temporarily-expired Time | Seconds before an access token stops being *valid* for session actions, but can still be refreshed. |
| Token Completely-expired Time | Seconds before an access token can no longer be refreshed at all. |
| Log-in/Log-out Rate Limit | Minimum interval (ms) between authenticate/signout attempts, per username. |
| Additional Skin Domain Names Whitelist | Extra comma-separated hostnames allowed to serve textures, besides the site URL and current request host. |
| Limit Number of Roles for Batch Query | Max number of names accepted per `/api/profiles/minecraft` request. |
| Show "Quick Configuration" | Toggles the drag-and-drop launcher widget on the user center home page. |
| API Location Indicator | Sends the `X-Authlib-Injector-API-Location` header so clients can auto-discover the API root. |
| OpenSSL Private Key | The RSA key used to sign profile textures; can be regenerated with one click. |
| Union API Root / Member Key / Auto Update | See [MUA Union Authentication](#mua-union-authentication) below. |
| `/restore` endpoint | See [Multi-backend Restore](#multi-backend-restore) below. |

## Official (Mojang) Account Binding

Users can link a real Mojang account to their skin-site character from **User Center → Bind Premium
Account** (`/yggdrasil/mojang/bind`):

1. The user submits their Minecraft username. It's resolved against `api.mojang.com` to confirm the
   account exists and to normalize casing/UUID, and is stored as a pending request (valid 15 minutes).
2. The user joins the server with the **vanilla launcher**, logged into that same Mojang account.
3. When `hasJoined` is called, the plugin verifies the session with
   `sessionserver.mojang.com`, matches it against the pending request by UUID, and — provided the user
   already has a character on the skin site — completes the binding automatically.

Once bound, the Mojang account can join directly (no authlib-injector needed) and reuses the skin-site
character's data. Skin/cape are pulled from the linked Mojang profile whenever the local character has none
set. A user can unbind at any time from the same page.

## MUA Union Authentication

If this site is a member of the MUA alliance, set **Union Member Key** (issued after onboarding) in the
plugin settings, then configure/trigger sync from the same page:

- **Pull server list** / **Pull shared private key** — fetches the alliance's current member list and the
  shared signing key from the central server (`union_api_root`).
- **Push all local players** — pushes every local `(name → uuid)` mapping to the central server.
- **Network diagnose** — round-trips a nonce with the central server to sanity-check connectivity.

Beyond manual triggers, the plugin keeps itself in sync automatically:

- Creating, renaming, or deleting a character pushes an update to the central server in the background
  (silently skipped if no member key is configured).
- `hasJoined` requests that don't match a local session or a bound Mojang account are forwarded to the
  union central server, so a player from *any* member site can join this server. If the forwarded profile's
  name collides with a local character, it's automatically suffixed (e.g. `_MUA`, `_SJMC`) and re-signed
  with the shared key before being returned to the client.
- Trusted callbacks from the central server (`/api/union/member/*` — server list/key pushes, UUID remaps,
  diagnostics) are verified via RSA signature + nonce/timestamp checks (`UnionHostVerify` middleware) to
  prevent spoofing and replay.

## Multi-backend Restore

`POST /api/yggdrasil/restore` accepts an arbitrary Yggdrasil profile payload and re-signs each of its
`properties[].value` entries with this site's private key. This lets union member sites exchange profiles
signed with different keys while keeping the alliance's shared public key valid everywhere. Disable it via
the **`/restore` endpoint** setting if you don't participate in cross-site profile exchange.

## Admin Log Page

**Admin → Yggdrasil Logs** lists recent authentication/session activity (sign in, refresh, validate, sign
out, join attempts, etc.), including the acting user, character, parameters, and IP. Detailed request/
response logging to `storage/logs` can additionally be enabled with the `YGG_VERBOSE_LOG` environment
variable, for debugging only — leave it off in production.

## Version Notes

See [CHANGELOG.md](./CHANGELOG.md) for the full history. Plugins after v2.0.0 no longer support
[authlib-agent](https://github.com/yushijinhun/authlib-agent).

## Further Reading

See the [Wiki](https://github.com/bs-community/yggdrasil-api/wiki) for launcher configuration guides and
more detail.
