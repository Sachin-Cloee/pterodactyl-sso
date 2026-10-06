# Paymenter SSO for Pterodactyl 2.x

[![Latest version](https://img.shields.io/packagist/v/sachin-cloee/pterodactyl-sso)](https://packagist.org/packages/sachin-cloee/pterodactyl-sso)
[![License](https://img.shields.io/packagist/l/sachin-cloee/pterodactyl-sso)](LICENSE.md)
[![Pterodactyl 2.x](https://img.shields.io/badge/Pterodactyl-2.x-blue)](https://pterodactyl.io)

One-click **single sign-on (SSO)** from [Paymenter](https://paymenter.org) to
your **Pterodactyl 2.x** game server panel. Customers press **Auto Login to
Panel** in the billing area and land in the panel already signed in — no second
login, no password to share.

This is the panel-side extension. The Paymenter side is the `PterodactylSSO`
server extension, which builds the signed login link. On Pterodactyl 1.x, use
the existing `/sso-wemx` endpoint instead of this package.

## Features

- One-click login from Paymenter to Pterodactyl — no shared passwords.
- Signed, single-use links that expire in under a minute.
- HTTPS required by default.
- Root administrators blocked by default; admins sign in themselves.
- Two-factor accounts blocked by default (they can be allowed, and still get
  the normal 2FA prompt — never a bypass).
- Accepted logins are recorded in the panel activity log; rejected links are
  logged with the reason and IP.

## Requirements

- Pterodactyl Panel 2.x (pre-release)
- PHP 8.3+
- HTTPS on the panel and the billing site

## Install

### Release archive (recommended)

Download the latest `pterodactyl-sso-<version>.pteroext` from the
[releases page](https://github.com/Sachin-Cloee/pterodactyl-sso/releases), then
run on the panel host:

```sh
php artisan p:extension:install /path/to/pterodactyl-sso-1.0.0.pteroext --enable
```

### With Composer

```sh
cd /var/www/pterodactyl
COMPOSER_ROOT_VERSION=2.0.0 composer require sachin-cloee/pterodactyl-sso
php artisan p:extension:install vendor/sachin-cloee/pterodactyl-sso --enable
```

The `p:extension:install` command is always required — Pterodactyl 2.x only
loads extensions from its `extensions/` directory, so the package is copied
there as `extensions/paymenter-sso`.

`COMPOSER_ROOT_VERSION` is needed on panels installed from a release archive:
without a git checkout Composer assumes the root package is `1.0.0`, which
trips the `roave/security-advisories` conflict rule for `pterodactyl/panel
<=1.12.4` and fails the install. On a git-based panel you can omit it.

Then make sure the web server user owns the installed files:

```sh
chown -R www-data:www-data /var/www/pterodactyl/extensions/paymenter-sso \
  /var/www/pterodactyl/public/assets/extensions
```

To update later, install the newer release archive (with Composer: `composer
update sachin-cloee/pterodactyl-sso` and re-run `p:extension:install`).

## Configure

**Admin → Extensions → Paymenter SSO → Settings:**

| Setting | Default | Description |
| --- | --- | --- |
| Shared secret | — | Must match the **SSO Secret Key** on the Paymenter server row for this panel. |
| Require HTTPS | on | Rejects auto-login requests that did not arrive over HTTPS. |
| Block root administrators | on | Admins sign in with their own credentials. |
| Block two-factor accounts | on | When off, those users still get the normal 2FA prompt instead of a bypass. |

In Paymenter, set the matching server row to **Panel Version: Pterodactyl 2.x**
and keep the same secret. The login path defaults to
`/extensions/paymenter-sso/login`.

## How it works

Paymenter redirects the customer with a URL that carries no credentials — only
a signature:

```
payload   = "paymenter-sso|{user}|{expires}|{nonce}|{server}"
signature = hex(hmac_sha256(payload, shared secret))
```

The extension verifies the signature, enforces the time window and single-use
nonce, applies the policy gates, then completes the login through the panel's
own login service. Rejected links redirect to `/auth/login?sso_error=<reason>`:
`insecure`, `admin-blocked`, `two-factor-blocked`, `unknown-user`, `used`,
`expired`, `invalid` or `unavailable`.

If the panel runs behind multiple app servers, use a shared cache store
(Redis, Memcached, database) so the single-use nonce works across them.

## License

MIT — see [LICENSE.md](LICENSE.md).
