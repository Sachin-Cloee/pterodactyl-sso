# Paymenter SSO (Pterodactyl 2.x panel extension)

Signs customers into a Pterodactyl 2.x panel from a
[Paymenter](https://paymenter.org) installation with signed, single-use login
links. The Paymenter side (the `PterodactylSSO` server extension) builds the
link; this extension verifies it and starts the panel session through the
panel's own login service, so two-factor accounts get the normal checkpoint.

> Pterodactyl 1.x does not need this package — the 1.x auto-login flow uses the
> WEMX-style SSO endpoint already configured in Paymenter.

## Requirements

- Pterodactyl Panel 2.x (`^2.0.0-dev`)
- PHP 8.3+
- HTTPS on both the panel and the billing site

## Install

Copy this directory to the panel host, then run:

```sh
php artisan p:extension:install /path/to/paymenter-sso --enable
```

Or install it from the admin area under **Admin → Extensions**.

Afterwards make sure the web server user owns the new files:

```sh
chown -R www-data:www-data /var/www/pterodactyl/extensions/paymenter-sso \
  /var/www/pterodactyl/public/assets/extensions
```

## Configure

1. Open **Admin → Extensions → Paymenter SSO → Settings**.
2. Set **Shared secret** to the same value as the **SSO Secret Key** on the
   Paymenter server row for this panel.
3. Reload the panel.

In Paymenter, on the matching server row: set **Panel Version** to
`Pterodactyl 2.x`, keep the same secret, and (optionally) adjust
**SSO Login Path** — default `/extensions/paymenter-sso/login`.

## Protocol

The link contains no long-lived credentials, only a signature:

```
payload   = "paymenter-sso|{user}|{expires}|{nonce}|{server}"
signature = hex(hmac_sha256(payload, shared secret))
URL       = /extensions/paymenter-sso/login?user=…&expires=…&nonce=…&server=…&signature=…
```

- `user` — panel user id; resolved server-side
- `expires` — unix timestamp; links older than 30 s (clock skew) or more than
  300 s in the future are rejected
- `nonce` — 32 hex chars; accepted exactly once
- `server` — optional server identifier to land on after login; signed, format
  validated

## Security properties

- HMAC-SHA256 over every parameter; `hash_equals` comparison.
- Short-lived, bounded links; replay prevented by an atomic single-use nonce
  (`Cache::add`), kept longer than the link can live.
- The panel user is loaded from the database — no caller-supplied identity is
  trusted.
- Login completion uses `Pterodactyl\Contracts\Users\CompletesLogins`; TOTP
  accounts continue through the standard `/auth/login` checkpoint.
- Rejections are logged (reason + IP) and redirect to `/auth/login?sso_error=…`
  without leaking details.

If the panel runs behind multiple app servers, use a shared cache store
(Redis, Memcached, database) so nonce single-use works across them.

## Manual test checklist

- [ ] `php artisan p:extension:list` shows the extension as enabled.
- [ ] Admin → Extensions → Paymenter SSO → Settings stores the secret (field
      shows blank after saving; that is expected).
- [ ] From Paymenter, **Auto Login to Panel** lands on the panel signed in.
- [ ] The same link opened a second time fails (`sso_error=used`).
- [ ] A tampered link (`user=` changed) fails (`sso_error=invalid`).
- [ ] A user with TOTP enabled is asked for the code after the redirect.

## Publishing

This directory is a self-contained Composer package (its own `composer.json`,
PSR-4 autoload, `extension.json` manifest). To publish on Packagist, split it
into its own repository and adjust `name` in `composer.json` to your vendor
namespace if `paymenter/pterodactyl-sso` is not yours.

## License

MIT — see [LICENSE.md](LICENSE.md).
