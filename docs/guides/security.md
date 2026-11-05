---
title: Security policy
description: Trust model, key handling, and the specific attacks this plugin must resist, with the code that answers each.
---

## Trust model

- All sites share one database (single multisite network or wp-multi-network multi-network install). The HMAC key and replay registry are readable by every trusted party by construction.
- The destination network's settings authorize a flow (decision 0004). The IdP mints a ticket only for blogs whose network designates it; the destination redeems only tickets from its configured IdP.
- Two-factor is enforced once, at the IdP login, by whatever 2FA plugin the IdP runs. The SSO ticket asserts "a valid, post-2FA session existed at mint time", nothing more.

## Keys

- One HMAC-SHA256 key per IdP site: autoload-off blog option `pv_wpms_sso_key`, 64 hex chars from `random_bytes(32)`, generated lazily on first use.
- `PV_WPMS_SSO_KEY` in wp-config overrides the stored key (for platforms that forbid DB-stored secrets).
- Rotation (settings page or WP-CLI) invalidates all outstanding tickets at once; outstanding lifetime is at most 60 seconds.
- Keys are never logged, never included in any response, and compared only with `hash_equals`.

## Attacks the protocol must resist

| Attack | Mitigation |
|---|---|
| Ticket replay | 60-second `exp`, atomic `INSERT` claim of `jti` in the global `pv_wpms_sso_jti` table (unique PK); a claimed ticket never verifies twice |
| Ticket forgery | HMAC-SHA256 over the payload with the IdP key; constant-time comparison |
| Login CSRF / session fixation | Random 128-bit `state` must match the destination's transient *and* its HttpOnly `pv_wpms_sso_state` cookie; a fresh session cookie is set after redemption (`wp_set_auth_cookie`), never copied from the IdP |
| Open redirect | Every redirect target (`redirect_to`, IdP callback URLs) is validated against the destination site's own URL host; extra hosts only via the `pv_wpms_sso/allowed_redirect_hosts` filter; final hop is `wp_safe_redirect` |
| Privilege escalation | Scope checked at mint *and* at redemption; `aud` must equal the destination blog ID and `iss` the configured IdP; the `user_may_sso` filter applies on both sides |
| Information leak via probe | `GET /wp-json/pv-wpms-sso/v1/session` answers `{authenticated:false}` unless the `Origin` matches a registered site domain; user details only for credentialed, in-scope, allowlisted origins; `Cache-Control: no-store` |
| Timing side channels | `hash_equals` for signatures; opaque errors ("access denied") on login screens rather than which check failed |

## Reporting

Security issues are reported privately via the GitHub repository's security advisories, not in public issues.
