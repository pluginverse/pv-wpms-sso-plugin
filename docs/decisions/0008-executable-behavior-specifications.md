---
title: "0008: Use executable Gherkin and stable behavior IDs"
description: "Use executable Gherkin and stable behavior IDs without changing destination authorization."
status: accepted
date: 2026-11-09
decision-makers: "Spenser Hale"
---

## Context

The [user journey plan](../plans/2026-11-08-bdd-user-journeys.md) requires executable acceptance checks and reuse of completed local logins across mapped domains.

## Options considered

Use Behat 4 as a development-only PHP acceptance runner, retaining PHPUnit for lower-level tests and Playwright for browser cookie guarantees. Scenarios carry stable SSO-NNN or SAML-NNN IDs. Copy the skill trace checker with attribution; identifiers record relationships rather than proving coverage. Documentation-only features would not verify behavior; a JavaScript-only runner would add a PHP fixture boundary to every acceptance test.

## Decision

Adopt the approach above, approved by Spenser on 2026-11-09.

## Consequences

Test tooling is development-only. Local session proof adds a security boundary requiring regression tests for state, user, trust, expiry, protocol purpose, and replay. SSO continues to use destination membership under `site_members`; the broad `network_users` scope is unchanged.

## Revisit when

The installation no longer shares a database or a production two-factor provider cannot complete the local cookie hand-off.
