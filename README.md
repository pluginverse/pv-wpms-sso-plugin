<img src="assets/icon.svg" width="64" height="64" alt="">

# PV WPMS SSO — Plugin Verse WordPress Multisite Single Sign On

One identity site, one login — credentials **and two-factor** — then signed single-use tickets into every site of a domain-mapped WordPress Multisite install. Super admins stop re-typing their password and re-doing 2FA for every mapped domain. Works across networks of a [wp-multi-network](https://github.com/johnbillion/wp-multi-network) (same-database) install too.

## The problem it solves

WordPress auth cookies are host-scoped. On a network of domain-mapped sites, a session on the network's main site is invisible on every mapped domain: to work on `shop.example.net` after signing in at `network.example.com`, you re-enter credentials and pass two-factor again — per domain, every time.

## How it works

1. Opening a site's `wp-login.php` makes one invisible round trip to the network's **identity site** — its root site, or any designated site in the install — asking whether this browser already has a session there that may enter this site.
2. It does? A 60-second **HMAC-signed, single-use ticket** comes back and you are **signed in automatically**, no click. (Per network you can switch this to a **"Continue as {name}"** button; right after signing out you always get the button, so logging out stays logged out.)
3. It does not, or your account may not enter this site? You get the normal WordPress login form with **no SSO button** — nothing to click that could fail. Sign in on the site as usual.
4. The site verifies every ticket (signature, expiry, issuer/audience binding, state, single-use claim) and sets **its own** auth cookies for its own domain. No shared cookies, no third-party cookies, no iframes — it works in every browser.

Each network picks its own identity site, so one installation can run several independent sign-on domains: sessions never cross between networks that designate different identity sites.

Replay, login CSRF, and open redirects are closed by design — the [security guide](docs/guides/security.md) has the threat table.

For accounts that can reach another site — super admins, and members of more than one site when the scope allows members — a completed local login on a participating mapped site also establishes the identity-site session through a browser-bound hand-off, then returns you to your original site. Site-local users are never redirected: their login does not touch the identity site. Later, use the destination's SSO button without repeating that login. The hand-off preserves local password/2FA handling and never grants a site role. A conflicting account already signed into the identity site is refused rather than replaced.

For membership-limited access, configure **Who can use SSO** as **Members of the destination site** (`site_members`) on each network. One-site users stay local; multi-site users can reach their member sites; cross-network users need a role on the specific destination site. **Any account in the shared installation** (`network_users`) deliberately grants broader access and does not check network membership. The default remains super admins only.

Existing local sessions that predate a hand-off are not discovered across domains. Complete a participating local login or sign into the identity site first. Two-factor providers that bypass the final WordPress `login_redirect` filter need a provider-specific integration.

## Features

- **Root-site or designated-site identity** per network; designating another network's site gives cross-network SSO with zero extra registration.
- **Scopes**: super admins only (default), members of the destination site, or any account in the shared installation — enforced at mint *and* at redemption, filterable.
- **Login-page session check**: automatic sign-in or one-click "Continue as", and no button for anyone it would refuse.
- **External SSO via SAML 2.0** (OneLogin, Okta, any SAML IdP): one more button on every login screen, terminating at the identity site and continuing through the same ticket flow. Email-based user mapping with opt-in auto-provisioning. See [External SSO (SAML)](#external-sso-saml).
- **Network settings screen** (Settings → SSO) and **WP-CLI** (`wp pv-wpms-sso status`, `wp pv-wpms-sso rotate-key`).
- **Key management**: one HMAC key per identity site, lazily generated, rotatable from the UI or CLI, overridable via a `PV_WPMS_SSO_KEY` wp-config constant.
- Bundled php-saml for external SSO; PHP 8.2+, WordPress 6.5+, network-activated. Test tooling is development-only.

## Installation

Upload `pv-wpms-sso.zip` from a [release](https://github.com/pluginverse/pv-wpms-sso-plugin/releases) (it bundles the SAML library) and **Network Activate**. Then open **Network Admin → Settings → SSO**.

With Composer, add the repository and require a tagged version:

```sh
composer config repositories.pv-wpms-sso vcs https://github.com/pluginverse/pv-wpms-sso-plugin
composer require pluginverse/pv-wpms-sso:^0.1
```

Composer installs the SAML library into your project's `vendor/`; details and requirements are in [Releasing](docs/guides/releasing.md#composer). From a clone, run `composer install --no-dev` inside the plugin folder.

### Configuring

| Setting | Values | Default |
|---|---|---|
| Enable single sign-on | on / off | on |
| Identity site | root site of this network, or a designated site ID (any site of the install) | root site |
| Who can use SSO | super admins / destination-site members / any account in the shared installation | super admins |
| Automatic sign-in | on (sign in at once) / off (show "Continue as {name}") | on |
| Button label | free text | "Sign in with {network name}" |

For platforms that forbid database-stored secrets, define in wp-config:

```php
define( 'PV_WPMS_SSO_KEY', bin2hex( random_bytes( 32 ) ) ); // 64 hex chars
```

Cross-network (wp-multi-network, same database): in network B's settings, set the identity site to a site of network A. That's it — the client network's setting **is** the registration.

## External SSO (SAML)

When your users live in OneLogin (or any SAML 2.0 IdP), the identity site becomes the SAML service provider — one IdP application per network, one button on every login screen. A user with a live OneLogin session clicks through with no prompt anywhere; the assertion terminates at the identity site and the ticket flow carries them into the site they asked for.

Setup, under **Network Admin → Settings → SSO → External single sign-on (SAML)**:

1. Enable it and paste the three IdP application values (entity ID, single sign-on URL, X.509 certificate).
2. Copy the shown **metadata URL** and **ACS URL** into the OneLogin application (SAML 2.0 connector). Set the NameID to email and keep the default *Sign SAML Response* (message-signed, RSA-SHA256).
3. Pick a button label (default "Sign in with OneLogin") and decide whether unknown emails may auto-provision network users (off by default).

Users signing in through SAML pass the same destination-network scope as everyone else — set **Who can use single sign-on** to `network_users` or `site_members` when the IdP is the front door.

## Hooks

### Filters

- `pv_wpms_sso/button_label` — the SSO button label (default: "Sign in with {network name}").
- `pv_wpms_sso/user_may_sso` (bool, $user_id, $client_blog_id) — override the scope policy; applies at mint and redemption.
- `pv_wpms_sso/check_session_on_login_page` (bool, $blog_id) — return false to stop login pages asking the identity site; the classic always-visible button returns.
- `pv_wpms_sso/hand_off_local_login` (bool, $user, $source_blog_id) — whether a completed local login is handed off to the identity site. Default: super admins of any network using that identity site, and multi-site members unless the scope is super admins only. Return true for staff your platform grants network reach some other way.
- `pv_wpms_sso/allowed_redirect_hosts` — extra allowed redirect hosts (list of `host` / `host:port`), empty by default.

### Actions

- `pv_wpms_sso/sso_login` ($user_id, $blog_id, $ticket) — fires after a successful SSO login, before the final redirect.
- `pv_wpms_sso/saml_login` ($user_id, $blog_id) — fires after a successful SAML login on the identity site.
- `pv_wpms_sso/saml_validation_failed` ($reason, $current_user_id) — validation failure detail for admin logging; never shown to users.

## Development

```sh
composer install        # dev tooling
composer test-unit      # pure unit tests, no WordPress needed
composer env:start      # wp-env multisite environment (Docker)
composer test           # full suite (unit + integration) under wp-env
composer phpstan        # static analysis, level 9
composer test-bdd        # executable user journeys in wp-env
composer test-trace      # stable behavior ID validation
npm ci
npx playwright install chromium
npm run browser:start   # separate, disposable mapped-domain environment
npm run browser:seed
npm run test:browser     # real HTTPS cookies, network routing, local test IdP
npm run browser:stop
```

Suite boundaries and provider limitations are documented in [Testing](docs/guides/testing.md). Versions are cut as described in [Releasing](docs/guides/releasing.md).

Design docs live in [`docs/`](docs/index.md): the living [spec](docs/specs/single-sign-on.md), [decision records](docs/decisions/), and the [security guide](docs/guides/security.md).

## Roadmap

- [x] Executable BDD plus HTTPS browser tests of mapped-domain and cross-network sign-in
- [ ] `roles:` scope values (e.g., editors of the destination site)
- [ ] Multiple SAML IdPs per network

## License

GPL-3.0-or-later — see [LICENSE.txt](LICENSE.txt). The built package bundles [onelogin/php-saml](https://github.com/SAML-Toolkits/php-saml) (MIT) and its dependencies.
