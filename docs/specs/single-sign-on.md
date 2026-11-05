---
title: "Single sign-on"
description: One identity site, one login (two-factor included), signed single-use tickets into every site of a network — including cross-network, same database.
status: current
---

## Intent

Network and super admins of domain-mapped multisite installs must re-enter credentials and re-pass two-factor on every mapped domain, because WordPress auth cookies are host-scoped. This feature gives each site's login screen a button that delegates authentication to one configured identity site (IdP) and turns its validated session into a fresh session on the site the user asked for.

## Behavior

- The plugin is network-activated and multisite-only. Each network's configuration lives in the network option `pv_wpms_sso`: `enabled` (default true), `idp_blog_id` (0 = that network's main site; any blog ID of the install otherwise — pointing at another network's site is cross-network SSO), `scope`, `button_label`.
- A "Sign in with {network name}" button renders on `wp-login.php` of every site whose network has SSO enabled and that is not the IdP site itself. `pv_wpms_sso/button_label` filters the label.
- Clicking the button hits `wp-login.php?pv_wpms_sso=start&redirect_to=...`: the client stores a random 128-bit state in a 10-minute transient and an HttpOnly `pv_wpms_sso_state` cookie, then redirects to the IdP's `GET /wp-json/pv-wpms-sso/v1/authorize?client_blog_id=...&state=...`.
- The authorize endpoint honors a request only when the client blog's network is enabled and designates the current site as its IdP (`Settings::resolveIdpBlogId`). Anonymous users are redirected to the IdP login with the authorize URL in `redirect_to` — credentials and two-factor happen exactly once, there.
- A user in scope gets a 60-second HMAC-SHA256 ticket (claims: iss, aud, sub, login, email, display, avatar, state, jti, iat, exp) and a 302 back to the client's `wp-login.php?pv_wpms_sso=callback&state=...&ticket=...`. Out-of-scope users are redirected back with `error=access_denied`; unconfigured clients with `error=untrusted_client`.
- The client callback redeems the ticket: query state = cookie state = transient state (all three), signature and expiry verified, issuer/audience bound to the configured IdP and current blog, ticket state matching, user existing with unchanged login, and the jti claimed exactly once in the global `{base_prefix}pv_wpms_sso_jti` table. Any failure redirects to the login screen with a readable notice.
- On success the client sets its own auth cookies (`wp_set_auth_cookie`, remember-me true), fires `pv_wpms_sso/sso_login`, and redirects to the validated `redirect_to` (default: the site's wp-admin). Redirect targets outside the site are rejected; extra hosts only via the `pv_wpms_sso/allowed_redirect_hosts` filter.
- The login screen probes the IdP's `GET /wp-json/pv-wpms-sso/v1/session` (credentialed fetch) and, when a session in scope exists, personalizes the button to "Continue as {display name}" with the user's avatar. The endpoint answers with data only for `Origin` values that resolve to a registered site whose network designates this site as IdP; it is `no-store`.
- Scope (`pv_wpms_sso.scope` of the destination network, decision 0004): `super_admins` (default — IdP checks `is_super_admin()` in the IdP network context; redemption re-checks the destination network's super-admin list), `site_members` (member of the destination site or super admin), `network_users` (any existing user). The `pv_wpms_sso/user_may_sso` filter overrides the policy and applies at mint and redemption.
- The signing key is one HMAC key per IdP site: autoload-off blog option `pv_wpms_sso_key`, generated lazily, readable by every network of the database; a `PV_WPMS_SSO_KEY` wp-config constant (64 hex chars) overrides it everywhere.

## Acceptance criteria

- Ticket round trip, tamper/wrong-key rejection, expiry, and future-iat rejection (`tests/Unit/TicketSignerTest.php`)
- Settings defaults, scope validation, idp clamp (`tests/Unit/SettingsTest.php`)
- Redirect targets: same-host ok, foreign host/port/scheme/protocol-relative rejected, relative paths only when `/`-rooted, filter hosts honored (`tests/Unit/RedirectGuardTest.php`)
- Scope matrix for the three scopes (`tests/Unit/ScopePolicyTest.php`)
- Authorize mints an audience-bound ticket for a super admin, sends anonymous users to the IdP login, denies out-of-scope users, and rejects unconfigured clients (`tests/Integration/SsoFlowTest.php`)
- Session probe answers only for registered, trusting origins (`tests/Integration/SsoFlowTest.php`)
- Ticket redemption round trip, replay rejection, foreign audience rejection; state single-use and cookie binding; jti single-use claim (`tests/Integration/SsoFlowTest.php`)

## Non-goals

- SAML/OIDC/OAuth server features or third-party clients.
- Cross-database or cross-install trust (one shared database assumed; see decision 0003).
- Logout propagation or session management across domains.
- Browser end-to-end test of the two-domain redirect dance (deferred; see plan).

## Constraints

- WordPress 6.5+, PHP 8.2+, network activation (`Network: true`).
- Tickets live at most 60 seconds; state at most 10 minutes; jti rows purged opportunistically on claim.
- The session probe relies on WordPress core's credentialed CORS handling for REST GETs; the origin allowlist gates the data.

## Decisions

- [0002: Mint HMAC-signed, single-use SSO tickets](../decisions/0002-mint-hmac-signed-single-use-sso-tickets.md)
- [0003: Store trust against the IdP site](../decisions/0003-store-trust-against-the-idp-site.md)
- [0004: Super admins by default; destination network governs scope](../decisions/0004-super-admins-by-default-destination-network-governs-scope.md)
