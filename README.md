# Yggdrasil API for Blessing Skin

This plugin basically implements the [Yggdrasil API specification](https://github.com/yushijinhun/authlib-injector/wiki/Yggdrasil%20%E6%9C%8D%E5%8A%A1%E7%AB%AF%E6%8A%80%E6%9C%AF%E8%A7%84%E8%8C%83), and can be used with [authlib-injector](https://github.com/yushijinhun/authlib-injector).

Fork: Added official account binding feature. Players with a skin server account can bind an official account and use that official account to log in as if they were using their bound skin server account.

## API Routes

```
routes.php

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
```

## Usage

Please refer to the [Wiki](https://github.com/bs-community/yggdrasil-api/wiki) of this project.

## Version Notes

The changelog for this plugin can be viewed here: [CHANGELOG](https://github.com/bs-community/yggdrasil-api/blob/master/CHANGELOG.md).

Note that plugins after v2.0.0 no longer support [authlib-agent](https://github.com/yushijinhun/authlib-agent).
