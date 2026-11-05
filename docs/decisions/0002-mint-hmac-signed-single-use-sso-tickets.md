---
title: "0002: Mint HMAC-signed, single-use SSO tickets"
description: SSO uses browser redirects carrying a 60-second HMAC-SHA256 ticket bound to user, origin site, destination site, and state, with replay blocked by a global JTI table.
status: accepted
date: 2026-11-04
decision-makers: "Spenser Hale, GLM (OpenCode drafting agent)"
---

## Context

Domain-mapped multisite sites cannot read each other's auth cookies: cookies are host-scoped, so an administrator signed in on the network's main site is anonymous on every mapped domain and must re-enter credentials and pass two-factor again for each site. We need a flow that turns one validated session on an identity site ("IdP") into a fresh session on the site the user actually wants, without a second credential prompt, and without creating an open redirect, login CSRF, or replay token.

Relevant constraints: the trust anchor must work across networks of a multi-network install (same database, via wp-multi-network), and two-factor plugins (for example wordpress-com-two-factor) must gate exactly once — at the IdP login.

## Options considered

1. **Signed one-time ticket carried through browser redirects (OAuth 2.0 authorization-code shaped, minus the back-channel)**: the client site redirects to the IdP with a state parameter; the IdP mints a short-lived HMAC-signed ticket bound to (issuer blog, audience blog, user, state); the client redeems it by verifying the signature and claiming the ticket's ID exactly once. No server-to-server call is needed because both sides share one database.
2. **Authorization code with a back-channel token exchange**: closest to textbook OAuth; the extra round trip buys nothing when both sides read the same database, and it adds an async failure mode.
3. **Shared auth cookies via COOKIE_DOMAIN manipulation**: impossible across different mapped domains; browsers scope cookies by host.
4. **Long-lived bearer token in the redirect**: any leak replays forever; violates single-use.

## Decision

We will mint an HMAC-SHA256-signed, single-use ticket carried through browser redirects, because it gives OAuth-shaped properties (state binding, audience binding, expiry, single use) with no back-channel and no new crypto dependencies.

Concretely:

- The ticket payload is JSON: `iss` (IdP blog ID), `aud` (destination blog ID), `sub` (user ID), `login`, `email`, `display`, `avatar`, `state`, `jti`, `iat`, `exp` (= `iat` + 60 seconds).
- The token is `base64url(payload) + "." + base64url(hmac_sha256(key, payload))`, compared with `hash_equals`.
- Replay is blocked by `INSERT`ing the `jti` hash into a global table (`{base_prefix}pv_wpms_sso_jti`); the unique primary key makes the claim atomic. Expired rows are deleted opportunistically on claim.
- The client binds the flow with a random `state` stored in a 10-minute transient and mirrored in an HttpOnly cookie; the callback requires query state = transient state = cookie state.
- Not-logged-in users at the IdP are sent to the IdP login with the authorize URL in `redirect_to`, so credentials and two-factor are prompted exactly once, at the IdP.

## Consequences

- Ticket lifetime bounds replay windows to 60 seconds and makes rotation cheap (outstanding tickets die with the key).
- The global JTI table is a new (tiny) table; it is shared across networks of the install, which is what makes single-use work cross-network.
- Time-of-check to time-of-use races are closed by the atomic INSERT, not by read-then-write state.
- If the database is ever split per network, this protocol stops working; decision 0003 records that boundary.

## Revisit when

A cross-database (or cross-install) trust need appears — then the protocol needs a public-key signature and a back-channel, i.e. a real decision, not a key rotation.

## Links

- Spec: [Single sign-on](../specs/single-sign-on.md)
- Plan: [Initial SSO plugin](../plans/2026-11-04-initial-sso-plugin.md)
- Decision: [0003: Store trust against the IdP site](0003-store-trust-against-the-idp-site.md)
