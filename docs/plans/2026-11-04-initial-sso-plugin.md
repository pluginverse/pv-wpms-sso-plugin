---
title: "Plan: Initial SSO plugin"
description: Build and ship PV WPMS SSO v1 — signed-ticket SSO for domain-mapped multisite and same-database multi-network installs.
status: done
date: 2026-11-04
size: architectural
specs: ["single-sign-on"]
---

## Intent

On domain-mapped multisite installs (and multi-network via wp-multi-network), super admins must re-authenticate and re-pass two-factor on every mapped domain because auth cookies are host-scoped. This change delivers a network-configurable single sign-on: a per-site login button that delegates to a designated identity site, with a signed single-use ticket coming back, and a session probe that personalizes the button.

## Acceptance criteria

- A network-activated plugin adds a "Sign in with {network}" button to `wp-login.php` of every site in a network whose settings enable SSO.
- Starting SSO from a site redirects to the network's configured IdP site (its root site, or the designated blog ID — possibly a site of another network in the same database).
- A user with a valid IdP session who passes scope gets redirected back with a ticket and lands logged in on the destination site, with working auth cookies for that domain.
- A user without an IdP session is prompted at the IdP login (two-factor included, once) and the flow then completes automatically.
- A ticket fails verification when: signature mismatch, expired (>60s), wrong audience, wrong issuer, state mismatch, or jti replay.
- A user outside the configured scope (default: super admins) is refused with a readable error, both at mint and at redemption.
- `redirect_to` targets outside the destination site are rejected (open-redirect guard).
- The session probe endpoint answers cross-origin, with credentials, only for origins that are registered sites of the install.
- `wp pv-wpms-sso status` reports per-network configuration; key rotation is available from the network settings page and WP-CLI.

## Non-goals

- SAML/OIDC/OAuth server features, third-party clients.
- Cross-database or cross-install trust.
- Logout propagation or session management across domains.
- Role-based scopes beyond `super_admins` / `site_members` / `network_users` (filter exists for custom policy).
- Blocks/Gutenberg UI; the settings page is server-rendered PHP.

## Design

Single plugin, network-activated, one code path for intra- and cross-network SSO (they differ only in whose `idp_blog_id` a network's settings hold). Components in `src/`:

- `Settings` — per-network option `pv_wpms_sso` (enabled, idp_blog_id, scope, button_label) and IdP resolution (0 → network main site).
- `KeyStore` — HMAC key stored as an autoload-off blog option on the IdP site; `PV_WPMS_SSO_KEY` constant override; rotation.
- `Ticket` / `TicketSigner` — HMAC-SHA256 signed payload, 60s TTL, `hash_equals` verify (decision 0002).
- `JtiTable` — global single-use registry; atomic INSERT claim, opportunistic purge.
- `StateStore` — random state in a 10-minute transient mirrored by an HttpOnly cookie.
- `RedirectGuard` — final-destination and callback URL validation against site URLs, extendable by filter.
- `ScopePolicy` — pure scope evaluation (decision 0004), facts injected by callers.
- `AuthorizeEndpoint` (IdP REST) — mints tickets, sends anonymous users to IdP login with `redirect_to` continuation.
- `SessionEndpoint` (IdP REST) — CORS credentialed probe; origin allowlist from registered site domains.
- `LoginScreen` (client) — button + inline JS + CSS on `wp-login.php`; error notices for refused flows.
- `ClientFlow` (client) — `login_init` handler: start (state + redirect to IdP) and callback (redeem ticket, set cookies, redirect).
- `SettingsPage` — network admin settings screen (Settings → SSO), key rotation.
- `Commands` — WP-CLI `status`, `rotate-key`.

Wiring follows the wordpress-performance skill: static lazy callbacks `['Class','method']`, REST routes gated on `rest_api_init`, admin page on `network_admin_menu`, login-screen pieces on `login_init`/`login_footer`, CLI on `cli_init`; no I/O in constructors.

Testing: pure unit tests for signer, state, guard, scope, settings (PHP callables injected for WP functions); integration tests with wp-phpunit for endpoints and flow where the multisite test factory allows. CI mirrors the Cloudflare Enterprise Cache plugin: lint + PHPStan on host, PHPUnit via wp-env in Docker, then a production `-built` branch.

Runner-up: a "shared session table + JS cookie writer" design (single sign-on by writing cookies for every domain via parallel requests). Rejected: needs per-domain requests from the browser, breaks with HttpOnly + SameSite on mapped domains, and cannot do cross-network.

## Spec delta

- `docs/specs/single-sign-on.md`: ADDED — the whole file (first spec, arrives with the implementation commits).

## Docs impact

- `README.md`: full plugin readme (features, configuration, hooks, roadmap).
- `CHANGELOG.md`: initial release entry.
- `docs/guides/security.md`: created with the security policy for the protocol above.

## Decisions

- [0002: Mint HMAC-signed, single-use SSO tickets](../decisions/0002-mint-hmac-signed-single-use-sso-tickets.md)
- [0003: Store trust against the IdP site](../decisions/0003-store-trust-against-the-idp-site.md)
- [0004: Super admins by default; destination network governs scope](../decisions/0004-super-admins-by-default-destination-network-governs-scope.md)

## Tasks

1. **Plugin skeleton + protocol core** — `pv-wpms-sso.php` (header, autoloader, wiring), `Settings`, `KeyStore`, `Ticket`, `TicketSigner`, `JtiTable`, unit tests. Commit: `feat(core): plugin skeleton, settings, key store, signed tickets, jti registry`.
2. **Flow endpoints** — `AuthorizeEndpoint`, `SessionEndpoint`, `StateStore`, `RedirectGuard`, `ScopePolicy`, `ClientFlow`, `LoginScreen`, unit + integration tests, spec. Commit: `feat(sso): browser SSO flow with authorize, session probe, client login`.
3. **Admin + CLI** — `SettingsPage`, `Commands`, tests. Commit: `feat(admin): network settings page and WP-CLI commands`.
4. **Tooling + readme** — composer/phpstan/phpunit/wp-env configs, CI workflow, README, CHANGELOG entry, spec final pass. Commit: `build(ci): quality gates, wp-env test rig, built-branch deploy`.

## Rulings

- [naming] Plugin slug `pv-wpms-sso`, namespace `PV\WpmsSso`, REST namespace `pv-wpms-sso/v1`, text domain `pv-wpms-sso` — matches Plugin Verse convention — cost if wrong: low, rename before release.
- [storage] Settings network option key `pv_wpms_sso`; state transient `pv_wpms_sso_state_{hash}`; state cookie `pv_wpms_sso_state` — cost if wrong: low.
- [ux] Button and probe JS are inline on the login screen (no enqueueable assets) — the whole addition is two small inline blocks; a build step would cost more than it buys — cost if wrong: low, extract later.
- [license] GPL-3.0-or-later with the Plugin Verse brand-protection additional permission, mirroring the Cloudflare Enterprise Cache plugin header — cost if wrong: low.
- [compat] PHP 8.2 floor, WordPress 6.5 floor, network-activated only (`Network: true`) — matches the reference plugin's modern baseline — cost if wrong: low.
- [autoloading] Runtime class-map autoloader in the main plugin file, Composer used for dev tooling only — zero runtime dependency, mirrors reference plugin — cost if wrong: low.
- [rest] Authorize returns 302 responses through REST; session probe returns JSON with credentialed CORS — REST gives us route constants and versioning without a rewrite-rule endpoint — cost if wrong: medium, switch authorize to `init`-phase endpoint.
- [tooling] Build tooling (composer, phpstan, phpunit, tests bootstrap) landed with task 1 instead of task 4 so every commit is verifiable — cost if wrong: low.
- [bootstrap] wp-env container detection uses WORDPRESS_DB_HOST, because composer's wp-phpunit autoload file exports WP_PHPUNIT__DIR on every host — cost if wrong: medium, unit suite would demand a DB.
- [jti] JTI rows store `hash('sha256', jti)` so the registry holds fixed-width hashes, not raw ticket IDs — cost if wrong: low.

## Deferred

- Full end-to-end browser test of the cross-domain flow (two mapped hosts) — needs a multi-host wp-env fixture; deferred until the flow is deployed somewhere real.
- `roles:` scope values — see decision 0004 revisit clause.
