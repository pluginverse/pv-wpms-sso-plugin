---
title: "0011: Hand off only accounts that can reach another site"
description: "A local login starts the identity-site hand-off only for super admins and multi-site members; site-local users stay on their site."
status: accepted
date: 2026-11-10
decision-makers: "Spenser Hale (requested), Claude (draft)"
---

## Context

[Decision 0009](0009-bridge-completed-local-sessions.md) sends every completed local login through the identity site. The release review showed the cost: three extra cross-domain redirects for every user, and an error page after a successful login whenever the identity site is unreachable or holds another account, including for users who can never use single sign-on. Spenser's rule: normal site users are not redirected; only network-level users are. Platforms also grant network reach in their own ways (per-network super admins, custom network roles in user meta), so the plugin cannot know every case.

## Options considered

1. **Hand off super admins and multi-site members, with a filter**: decided from WordPress's own data at login. Site-local users never leave their site. A platform with its own notion of network staff answers through the filter.
2. **Super admins only**: simplest, but drops the multi-site member journeys the `site_members` scope exists for.
3. **Keep handing off everyone**: no rule to explain, but keeps the cost above for the majority who gain nothing.

## Decision

We will start the hand-off only when the account can plausibly reach another site: it is a super admin of the source network or of any network that designates the same identity site, or the source network's scope is wider than `super_admins` and the account is a member of more than one site. `pv_wpms_sso/hand_off_local_login` (bool, `WP_User`, source blog ID) overrides the result. This narrows who enters the 0009 flow; the flow itself, and destination authorization, are unchanged.

## Consequences

Site-local logins no longer depend on the identity site. A site-local user who clicks the SSO button is asked to sign in at the identity site and is then refused by scope as before. A multi-site member whose source network keeps the default `super_admins` scope is not handed off even if another network would admit them; the filter covers that. Eligibility costs one membership lookup at login, and a pass over the networks for accounts that are not super admins of the source network.

## Revisit when

A deployment needs eligibility by role or by destination, or the number of networks makes the per-login pass measurable.

## Links

- Spec: [Single sign-on](../specs/single-sign-on.md)
- Plan: [Hand-off eligibility](../plans/2026-11-10-hand-off-eligibility.md)
