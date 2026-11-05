---
title: "0004: Super admins by default; the destination network's settings govern scope"
description: Only super admins may SSO by default; each network chooses super_admins / site_members / network_users, and the network that owns the destination site is the one whose settings authorize the flow.
status: accepted
date: 2026-11-04
decision-makers: "Spenser Hale, GLM (OpenCode drafting agent)"
---

## Context

The plugin's founding problem is administrators re-authenticating per mapped domain, but other deployments may want wider SSO. In a multi-network install, "who may SSO" could plausibly be governed by the IdP's network or by the destination site's network, and `is_super_admin()` itself is network-scoped (each network's `site_admins`).

Both ends of the flow can evaluate policy: the IdP before minting a ticket, the destination before redeeming it.

## Options considered

1. **IdP network's settings govern everything**: simple, but a client network could not tighten or loosen policy for its own sites.
2. **Destination network's settings govern; IdP enforces them too; both sides check**: the destination network owns the risk (its cookies are being issued), the IdP enforces the same policy when minting (defense in depth), and cross-network flows stay consistent because both sides read the destination network's one settings row.
3. **Hard-coded super-admin check, no setting**: least flexibility; the configuration option the product brief explicitly asks for ("that is, your auth version") would not exist.

## Decision

We will scope SSO by the destination network's settings, enforced on both sides, with super admins as the default scope, because the destination network is the one issuing its own session cookies and must own that policy.

Concretely, the network option `pv_wpms_sso.scope` takes:

- `super_admins` (default): the user must be a super admin. At the IdP, `is_super_admin()` is checked in the IdP's network context (that is where the user's session lives); at redemption the destination additionally requires the user be a super admin of its own network — for single-network installs identical, for multi-network installs it keeps each network's admin list authoritative for its sites.
- `site_members`: the user is a member of the destination site, or a super admin (WordPress's own access model).
- `network_users`: any existing user in the install (same users table).

A `pv_wpms_sso/user_may_sso` filter lets a site override the policy entirely; both ends apply it.

## Consequences

- Single-network installs (the common case) behave exactly like "super admins can sign in anywhere", which is the founding requirement.
- Multi-network installs with different admin lists per network get per-network authority: an IdP network's super admin who is not a super admin of the destination network must fall back to `site_members`-style access there. That is deliberate: the destination network's admin list is the authority for its sites.
- Enforcing twice costs one `is_super_admin()`/`is_user_member_of_blog()` call per flow leg; negligible at login frequency.

## Revisit when

A deployment asks for role-based scopes (for example "editors of the destination site") — add a `roles:` scope value rather than a new mechanism.

## Links

- Spec: [Single sign-on](../specs/single-sign-on.md)
- Plan: [Initial SSO plugin](../plans/2026-11-04-initial-sso-plugin.md)
