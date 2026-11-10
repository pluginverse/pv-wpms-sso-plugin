---
title: "Plan: Hand-off eligibility"
description: "Stop redirecting site-local users through the identity site after a local login."
status: done
date: 2026-11-10
size: bounded
specs: ["single-sign-on"]
---

## Intent

Every completed local login currently travels through the identity site. Site-local users gain nothing from it and inherit its failure modes. Spenser: normal site users should not be redirected; only network users and super-admin-like staff should. Success: a one-site member logs in and stays on their site; super admins and multi-site members still get the hand-off; a platform can add its own rule.

## Acceptance criteria

- A member of exactly one site, not a super admin, is not redirected after login.
- A super admin of the source network is handed off, with or without site memberships.
- A super admin of another network that designates the same identity site is handed off.
- A member of several sites is handed off unless the source network's scope is `super_admins`.
- `pv_wpms_sso/hand_off_local_login` can grant and refuse.
- Browser: a site-local user gets no identity-site cookie from a local login.

## Non-goals

- Role-based eligibility or reading any platform's custom role storage.
- Changing the hand-off protocol or destination authorization.

## Design

`LocalSessionBridge::filterLoginRedirect` asks a private eligibility check before building the hand-off URL. Super admin status comes from `is_super_admin()` and, failing that, each network's `site_admins` option where that network is enabled and resolves to the same identity site. Membership comes from `get_blogs_of_user()`. Tests: integration cases per criterion, a new BDD scenario `SSO-009`, and the browser journey for the site-local user. Runner-up, super admins only, lost because it removes the multi-site member journeys.

## Spec delta

- `docs/specs/single-sign-on.md`: MODIFIED "Reuse a completed local login" — the hand-off starts only for eligible accounts; ADDED the filter.

## Docs impact

- `README.md`: who is handed off, the new filter.
- `docs/guides/security.md`, `docs/product/vision.md`: eligibility sentence.
- `CHANGELOG.md`: amend the unpublished 0.1.0 notes.

## Decisions

- [0011: Hand off only accounts that can reach another site](../decisions/0011-hand-off-only-accounts-that-can-reach-another-site.md)

## Tasks

1. Eligibility check, tests, docs. Commit: `feat(sso): hand off only accounts that can reach another site`.

## Rulings

- [changelog] 0.1.0 is prepared but not tagged, so its notes are edited in place instead of opening a new version — cost if wrong: low.
- [scope proxy] The source network's scope stands in for the unknown destination's — cost if wrong: low, the filter restores the hand-off.

## Deferred

- None.
