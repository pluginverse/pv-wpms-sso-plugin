---
title: "0006: Bundle onelogin/php-saml for the SAML client"
description: The SAML SP side uses onelogin/php-saml v4 (MIT) as a composer runtime dependency, vendored into the production build; all SAML wiring no-ops without it.
status: accepted
date: 2026-11-04
decision-makers: "Spenser Hale, GLM (OpenCode drafting agent)"
---

## Context

The plugin so far has zero runtime dependencies: a class-map autoloader, no composer at runtime. SAML 2.0 needs XML-DSig signature validation, deflate/binding encoding, audience/destination/clock validation — the security-critical part of the whole feature. Getting XML-DSig subtly wrong is a credential-bypass bug.

## Options considered

1. **Bundle `onelogin/php-saml` v4 (MIT)**: proven, audited, used by most WordPress SSO plugins; MIT is GPL-3-compatible. Source repo gains a composer runtime dependency; the production build vendors it via CI.
2. **Hand-rolled minimal SP**: zero third-party code, but we become the authors of an XML-DSig verifier — the exact thing one should not hand-roll.
3. **Optional runtime dependency**: plugin ships without SAML unless the site installs php-saml itself — zero shipped code, but nobody gets the feature out of the box.

## Decision

We will bundle onelogin/php-saml v4 as a composer runtime dependency, vendored into the `-built` branch by CI, because the signature validation core must not be hand-rolled and the feature must work out of the box.

Concretely:

- `composer.json` gains `"onelogin/php-saml": "^4.0"`.
- The main plugin file requires `vendor/autoload.php` when the file exists; when it does not (source checkout without `composer install`), every SAML hook simply never registers and network SSO is unaffected. A network admin notice appears if SAML settings exist but the library is missing.
- CI's build job runs `composer install --no-dev --optimize-autoloader` before the production commit, so the built plugin carries `vendor/`.
- MIT attribution recorded in README and LICENSE notes.

## Consequences

- The built plugin ships ~1 MB of vendored PHP; the source repo stays readable.
- We use the library surgically: `AuthnRequest` building and `Response` validation directly, with our own transient-backed `InResponseTo` store instead of the library's session state.
- Two autoloaders (ours + composer's) coexist; both are pure `spl_autoload_register` chains, no conflict.

## Revisit when

php-saml v4 goes unmaintained (its successor, or a maintained fork, becomes the safer bundle), or a WordPress-core SAML API appears.

## Links

- Spec: [SAML SSO](../specs/saml-sso.md)
- Plan: [SAML SSO](../plans/2026-11-04-saml-sso.md)
