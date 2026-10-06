# Paymenter SSO (Pterodactyl 2.x panel extension)

Signs customers into a Pterodactyl 2.x panel from a
[Paymenter](https://paymenter.org) installation with signed, single-use login
links. The Paymenter side (the `PterodactylSSO` server extension) builds the
link; this extension verifies it and starts the panel session through the
panel's own login service, subject to configurable security gates — by default
administrators and two-factor accounts cannot use SSO at all.

> Pterodactyl 1.x does not need this package — the 1.x auto-login flow uses the
> WEMX-style SSO endpoint already configured in Paymenter.

## Requirements

- Pterodactyl Panel 2.x (`^2.0.0-dev`)
- PHP 8.3+
- HTTPS on both the panel and the billing site

## Install

Download the latest `pterodactyl-sso-<version>.pteroext` from the
[releases page](https://github.com/Sachin-Cloee/pterodactyl-sso/releases),
copy it to the panel host and run:

```sh
php artisan p:extension:install /path/to/pterodactyl-sso-1.0.0.pteroext --enable
```

You can also install from a checkout of this repository, or upload the package
in the admin area under **Admin → Extensions**.

Afterwards make sure the web server user owns the new files:

```sh
chown -R www-data:www-data /var/www/pterodactyl/extensions/paymenter-sso \
  /var/www/pterodactyl/public/assets/extensions
```

## Configure

Open **Admin → Extensions → Paymenter SSO → Settings**:

| Setting | Default | Effect |
| --- | --- | --- |
| **Shared secret** | — | Must match the **SSO Secret Key** on the Paymenter server row for this panel. |
| **Require HTTPS** | on | Rejects any auto-login request that did not arrive over HTTPS. |
| **Block root administrators** | on | Rejects auto-login for accounts with root administrator rights. |
| **Block two-factor accounts** | on | Rejects auto-login for accounts with 2FA enabled. When turned off, those users instead continue through the normal two-factor checkpoint. |

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
- HTTPS-only by default (configurable for local development panels).
- Root administrators are rejected by default: a compromised billing session
  must never yield an administrative panel session.
- Two-factor accounts are rejected by default, so SSO can never act as an
  alternative path into a protected account. If you turn this off, those
  accounts still complete through `Pterodactyl\Contracts\Users\CompletesLogins`
  and receive the standard `/auth/login` checkpoint — there is no bypass.
- The panel user is loaded from the database — no caller-supplied identity is
  trusted.
- Successful logins are audited by the panel itself (`DirectLogin` →
  `auth:success` activity log). Rejected links are logged with the reason,
  user id where known, and source IP.

| Rejection | `sso_error` | Meaning |
| --- | --- | --- |
| Not HTTPS | `insecure` | Request arrived over plain HTTP. |
| Root admin | `admin-blocked` | Account has root administrator rights. |
| 2FA account | `two-factor-blocked` | Account has two-factor authentication enabled. |
| Unknown user | `unknown-user` | Signed user id no longer exists. |
| Replayed | `used` | Nonce already consumed. |
| Expired | `expired` | Link outside the allowed time window. |
| Bad signature / input | `invalid` | Malformed or tampered link. |
| No secret | `unavailable` | Shared secret is not configured. |

If the panel runs behind multiple app servers, use a shared cache store
(Redis, Memcached, database) so nonce single-use works across them.

## Manual test checklist

- [ ] `php artisan p:extension:list` shows the extension as enabled.
- [ ] Admin → Extensions → Paymenter SSO → Settings stores the secret (field
      shows blank after saving; that is expected).
- [ ] From Paymenter, **Auto Login to Panel** lands on the panel signed in for
      a regular account.
- [ ] The same link opened a second time fails (`sso_error=used`).
- [ ] A tampered link (`user=` changed) fails (`sso_error=invalid`).
- [ ] A root administrator's link fails (`sso_error=admin-blocked`).
- [ ] A TOTP-enabled user's link fails (`sso_error=two-factor-blocked` while the
      block is on; with the block off, the login continues to the checkpoint).
- [ ] With **Require HTTPS** on, an HTTP request fails (`sso_error=insecure`).
- [ ] Panel Activity log shows an `auth:success` entry for an accepted login.

## Repository and Packagist

- Source and releases: <https://github.com/Sachin-Cloee/pterodactyl-sso>
- Packagist: [`sachin-cloee/pterodactyl-sso`](https://packagist.org/packages/sachin-cloee/pterodactyl-sso)
- Issues: <https://github.com/Sachin-Cloee/pterodactyl-sso/issues>

Pterodactyl extensions are installed with `p:extension:install`, not via
`composer require`. Packagist and the releases page exist to provide versioned,
downloadable archives.

## License

MIT — see [LICENSE.md](LICENSE.md).
