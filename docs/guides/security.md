---
title: Security policy
description: Trust model, key handling, and the specific attacks this plugin must resist, with the code that answers each.
---

## Trust model

- All sites share one database (single multisite network or wp-multi-network multi-network install). The HMAC key and replay registry are readable by every trusted party by construction.
- The destination network's settings authorize a flow (decision 0004). The IdP mints a ticket only for blogs whose network designates it; the destination redeems only tickets from its configured IdP.
- Two-factor is enforced at the original login, either on the identity site or a participating source site. Local-session proof requires the source's actual authenticated cookie after login completes; no identity is accepted from an early authentication hook or an unverified user ID. The source's authentication plugins must withhold a usable login cookie until their challenges finish.
- A completed local login may establish hub identity through the [browser-bound hand-off](../decisions/0009-bridge-completed-local-sessions.md). The hub starts the transaction; the source proves its current browser session; the hub checks state, cookie, expected user, source trust/availability, issuer/audience, proof expiry, and atomic transaction single-use before setting cookies. Different existing hub identities are refused. Local authentication remains usable after a hand-off failure.
- Local-session proofs use a purpose-separated derived HMAC key; ordinary SSO tickets cannot establish hub identity. Hub authentication never creates site memberships. Rotating the hub key also invalidates outstanding local-session proofs.
- The SSO authorize/probe GET endpoints explicitly validate a logged-in cookie after core REST's nonce-less cookie reset. Only these endpoint handlers read that identity; all other REST routes retain WordPress's normal nonce policy. Authorization/state binding still govern tickets, and origin/scope checks still gate probe data.

## Keys

- One HMAC-SHA256 key per IdP site: autoload-off blog option `pv_wpms_sso_key`, 64 hex chars from `random_bytes(32)`, generated lazily on first use.
- `PV_WPMS_SSO_KEY` in wp-config overrides the stored key (for platforms that forbid DB-stored secrets).
- Rotation (settings page or WP-CLI) invalidates all outstanding tickets at once; outstanding lifetime is at most 60 seconds.
- Keys are never logged, never included in any response, and compared only with `hash_equals`.

## Attacks the protocol must resist

| Attack | Mitigation |
|---|---|
| Local-session replay / login CSRF | Hub-created expiring browser state, expected-user and source binding, purpose-separated signed proof, and an atomic transaction claim; no cookie from a pending login is sufficient |
| Hub account substitution | A completed source login cannot silently replace another existing hub identity |
| Ticket replay | 60-second `exp`, atomic `INSERT` claim of `jti` in the global `pv_wpms_sso_jti` table (unique PK); a claimed ticket never verifies twice |
| Ticket forgery | HMAC-SHA256 over the payload with the IdP key; constant-time comparison |
| SAML assertion forgery | php-saml strict-mode XML-DSig validation against the configured IdP certificate (message-signed, RSA-SHA256); audience, destination, and timestamp windows enforced |
| SAML replay | every AuthnRequest ID stored in a 10-minute transient; a response is honored only when its `InResponseTo` resolves to a stored, unconsumed request, deleted on read; the ticket JTI claim then closes the client leg |
| Login CSRF / session fixation | Random 128-bit `state` must match the destination's transient *and* its HttpOnly `pv_wpms_sso_state` cookie; the SAML client hop reuses the same binding; a fresh session cookie is set after redemption (`wp_set_auth_cookie`), never copied from the IdP |
| Open redirect | Every redirect target (`redirect_to`, IdP callback URLs) is validated against the destination site's own URL host; extra hosts only via the `pv_wpms_sso/allowed_redirect_hosts` filter; final hop is `wp_safe_redirect` |
| Privilege escalation | Scope checked at mint *and* at redemption; `aud` must equal the destination blog ID and `iss` the configured IdP; the `user_may_sso` filter applies on both sides |
| Information leak via probe | `GET /wp-json/pv-wpms-sso/v1/session` answers `{authenticated:false}` unless the `Origin` matches a registered site URL's scheme, hostname, and port; user details only for credentialed, in-scope, allowlisted origins; `Cache-Control: no-store` |
| Unauthorized provisioning | Auto-provisioning is off by default; the identity site's network opts in and picks the role; provisioned users still face the destination network's SSO scope |
| Timing side channels | `hash_equals` for signatures; opaque errors ("access denied") on login screens rather than which check failed |

## Reporting

Security issues are reported privately via the GitHub repository's security advisories, not in public issues.

## Deployment acceptance

Use HTTPS on every participating domain and host-scoped WordPress cookies. The browser suite uses a disposable mapped-domain installation, an isolated test certificate, a deterministic test-only second factor, and a local signed-response IdP. Run the actual deployment's two-factor provider and OneLogin application through these journeys before claiming compatibility with that provider or tenant. A provider bypassing the final `login_redirect` filter will not automatically establish the hub session.
