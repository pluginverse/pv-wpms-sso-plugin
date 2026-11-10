---
title: "0009: Bridge completed local sessions to the identity site"
description: "Bridge completed local sessions to the identity site without changing destination authorization."
status: accepted
date: 2026-11-09
decision-makers: "Spenser Hale"
---

## Context

The [user journey plan](../plans/2026-11-08-bdd-user-journeys.md) requires executable acceptance checks and reuse of completed local logins across mapped domains.

## Options considered

After a completed local login, send the browser through a hub-created transaction, back to the source to prove its real authenticated cookie, and back to the hub. Use expiring HttpOnly SameSite=Lax browser state, a trusted source, expected-user binding, a purpose-separated HMAC key derived from the hub key, and an atomic replay claim. Refuse a conflicting hub identity. Only then set hub cookies. Never add roles or relax destination policy. This extends decisions 0002 and 0003 with a reverse session-proof flow; ordinary destination tickets remain unchanged. Routing every password login through the hub would require moving local login and two-factor, so preserve local authentication instead.

## Decision

Adopt the approach above, approved by Spenser on 2026-11-09.

## Consequences

Test tooling is development-only. Local session proof adds a security boundary requiring regression tests for state, user, trust, expiry, protocol purpose, and replay. SSO continues to use destination membership under `site_members`; the broad `network_users` scope is unchanged.

## Revisit when

The installation no longer shares a database or a production two-factor provider cannot complete the local cookie hand-off.
