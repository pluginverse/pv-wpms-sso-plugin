---
title: "0012: Check the identity session by redirect before offering SSO"
description: "The login page asks the identity site through one top-level round trip, signs in automatically when it can, and shows no network button when it cannot."
status: accepted
date: 2026-11-10
decision-makers: "Spenser Hale (approved, auto sign-in as the network default), Claude (draft)"
---

## Context

The network button was always shown, so a user who could not use it found out only after clicking, and a site-local user was sent to the identity site to type a password that could never work. Spenser: no button that leads to a notice. The existing "Continue as" probe was meant to personalize the button, but a browser run showed it cannot: across registrable domains the identity site's `SameSite=Lax` login cookie is not sent on a background request. Hidden-iframe storage bridges (cross-storage, `postMessage`) fail for the same reason: Safari, Firefox, and Chrome partition an embedded origin's storage by the top-level site. Identity sites differ per network, so the answer must come from the identity site each network designates.

## Options considered

1. **Top-level round trip from the login page** (`prompt=none`): cookies are always sent on navigation, so it works in every browser. One extra redirect per login-page visit.
2. **Embedded probe or storage bridge**: no redirect, but works only in Chrome's default mode or when all sites share a registrable domain.
3. **Identifier first, then look up the buttons**: no redirect, but an unauthenticated endpoint would disclose which accounts hold network privileges.
4. **Keep the button, return a notice when no session exists**: the click that leads to a notice is what is being removed.

## Decision

We will have a client site's login page send anonymous visitors once through its network's identity site with `prompt=none`. A session in scope returns a ticket: the visitor is signed in at once when the network's `auto_sign_in` setting is on (default) and the visit is not a logout or forced re-authentication; otherwise the page shows "Continue as {name}". Anything else returns quietly and the page shows the WordPress form without a network button. The check keeps its state in an HttpOnly cookie instead of a transient, so anonymous traffic to `wp-login.php` writes nothing to the database. `pv_wpms_sso/check_session_on_login_page` turns the check off, restoring the always-visible button.

## Consequences

Nobody can click a network button that will fail, and signed-in staff reach a mapped site without a click. Every anonymous login-page visit costs one round trip to the identity site; because it shares the installation, its availability is the site's own, but its DNS and certificate now matter to the login page. The result is carried in the return URL, so reloading that URL does not re-check; opening the login page afresh does. Sign-in at the identity site itself is no longer reachable from the network button while the check is on: users sign in on the site in front of them, and eligible accounts are handed off (decision 0011).

## Revisit when

Browsers offer a storage-access path that needs no prompt, or the extra round trip becomes measurable on login-heavy sites.

## Links

- Spec: [Single sign-on](../specs/single-sign-on.md)
- Plan: [Login-page session check](../plans/2026-11-10-login-page-session-check.md)
