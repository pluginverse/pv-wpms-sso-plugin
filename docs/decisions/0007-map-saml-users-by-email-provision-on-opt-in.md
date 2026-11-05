---
title: "0007: Map SAML users by email, provision on opt-in"
description: SAML assertions map to WordPress users by exact email match; networks can opt into auto-provisioning unknown users with a default role.
status: accepted
date: 2026-11-04
decision-makers: "Spenser Hale, GLM (OpenCode drafting agent)"
---

## Context

A SAML assertion arrives with a NameID (usually an email) and attributes. WordPress multisite has one users table shared by the network (and, same-DB, by every network). The user in front of the assertion may or may not exist yet. Provisioning everyone by default turns any IdP account into a network user — that is a network-administrator decision, not a plugin default.

## Options considered

1. **Email match, provisioning as a per-network opt-in** (with default role): existing installs match their people immediately; greenfield installs can flip one toggle. The IdP's assertion counts as email verification.
2. **Strict match only**: safest, but every external deployment needs user-sync tooling before anyone can log in.
3. **Always JIT-provision**: frictionless for greenfield, but silently widens the user base to the entire IdP directory.

## Decision

We will map by exact, case-insensitive email match and create users only when the network enabled `auto_provision`, with `default_role` applied, because the default must not grow the user population silently (decision 0004's spirit: the destination network owns its policy).

Concretely:

- Email source: NameID when it contains `@`; otherwise the first present attribute among `email`, `mail`, `user.email`, `urn:oid:0.9.2342.19200300.100.1.3`.
- Provisioned username: sanitized email local part, numeric suffix on collision; display name from `displayname`/`displayName` or first + last attributes; role `default_role` (subscriber unless configured).
- No local password is set for provisioned users (a random unusable one), so SSO remains the only way in.
- No match + provisioning off: refused with `saml_unknown_user`, no information about which addresses exist.

## Consequences

- Case-insensitive matching must be indexed (`user_email` collation) — one lookup per ACS.
- Provisioned users pass the same destination-network scope as everyone else; a `super_admins` default keeps them out of client sites until scope widens — networks using OneLogin as the front door will set `site_members` or `network_users`.
- Renames/secondary emails in the IdP are out of scope (no attribute-sync contract in v1).

## Revisit when

An install needs attribute-driven role assignment or group-based authorization (a `roles:` scope from decision 0004's revisit clause is the natural landing spot).

## Links

- Spec: [SAML SSO](../specs/saml-sso.md)
- Plan: [SAML SSO](../plans/2026-11-04-saml-sso.md)
- Decision: [0004: Super admins by default; destination network governs scope](0004-super-admins-by-default-destination-network-governs-scope.md)
