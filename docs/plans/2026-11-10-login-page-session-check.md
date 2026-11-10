---
title: "Plan: Login-page session check"
description: "Ask the identity site before rendering the login page: sign in automatically, offer one click, or show no network button."
status: done
date: 2026-11-10
size: bounded
specs: ["single-sign-on"]
---

## Intent

A network button that fails after the click is a dead end, most of all for site-local users. Spenser wants the page to know first, across mapped domains and with a different identity site per network, and to sign in automatically by default. Success: a visitor with a usable identity session lands signed in; everyone else sees a plain login form.

## Acceptance criteria

- `authorize` with `prompt=none` never sends anyone to the identity-site login: no session or out of scope returns `error=no_session` to the client callback.
- An anonymous GET of a client site's login form starts one check; POSTs, other actions, interim logins, flow callbacks, and URLs already carrying a check result do not.
- With `auto_sign_in` on, a session in scope signs the visitor in and honors a validated `redirect_to`.
- After logout or with `reauth`, or with `auto_sign_in` off, the page shows "Continue as {name}" instead of signing in.
- Without a usable session the page shows no network button and no notice; the SAML button is unaffected.
- The check writes no transient for anonymous visitors without a session.
- `auto_sign_in` is a per-network setting (default on) in the settings screen and `wp pv-wpms-sso status`.
- `pv_wpms_sso/check_session_on_login_page` returning false restores the always-visible button and probe.

## Non-goals

- OpenID Connect or other new external providers.
- Fixing the cross-domain probe; it remains for same-domain installs with the check disabled.
- Propagating logout.

## Design

New `SessionCheck` class owns the decision to start, the cookie codec, the return URL, and the hint store; `ClientFlow` recognizes a pending check in its callback and either signs in (shared with the button flow) or stores a hint; `AuthorizeEndpoint` learns `prompt=none`; `LoginScreen` renders the network button only for a hint when the check is on. State is a 2-minute HttpOnly cookie holding a random state, the validated return target, and the mode. The ticket path is unchanged, so state, audience, scope, and single use are still enforced at redemption. A hint is a 10-minute transient keyed by a random token in the return URL, written only on success. Tests: integration for `prompt=none`, start conditions, cookie codec, and hints; BDD scenario `SSO-010`; browser journeys for automatic sign-in, the hidden button, and one-click after logout. Runner-up designs are in decision 0012.

## Spec delta

- `docs/specs/single-sign-on.md`: ADDED login-page session check, `prompt=none`, `auto_sign_in`, the filter; MODIFIED when the button renders and what the probe is for.

## Docs impact

- `README.md`: how it works, settings table, filter.
- `docs/guides/security.md`: threat-table row. `docs/guides/testing.md`: browser coverage.
- `CHANGELOG.md`: amend the unpublished 0.1.0 notes.

## Decisions

- [0012: Check the identity session by redirect before offering SSO](../decisions/0012-check-the-identity-session-before-offering-sso.md)

## Tasks

1. Session check, setting, tests, docs. Commit: `feat(sso): check the identity session before offering single sign-on`.

## Rulings

- [quiet refusal] Out of scope and no session return the same `no_session` — the page must not explain a refusal nobody asked for — cost if wrong: low.
- [result in URL] The check result travels as a query argument, not a cookie, so signing in elsewhere and reopening the login page re-checks immediately — cost if wrong: low, one more round trip.
- [cookie mode] The auto/hint mode sits in the visitor's own cookie; tampering only changes whether their own valid session needs a click — cost if wrong: low.

## Deferred

- None.
