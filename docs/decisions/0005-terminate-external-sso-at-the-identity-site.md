---
title: "0005: Terminate external SSO at the identity site"
description: The identity site is the only SAML service provider; client sites hand off through the existing ticket flow, so OneLogin needs exactly one app entry per network.
status: accepted
date: 2026-11-04
decision-makers: "Spenser Hale, GLM (OpenCode drafting agent)"
---

## Context

Domain-mapped networks have many hostnames. A standard SAML IdP application (OneLogin, Okta) is configured with one ACS URL per app. If every site were its own SP, a network of N mapped domains would need N IdP app entries (or app-per-domain churn every time a site is mapped). The plugin already has a hub: the identity site mints tickets that carry a session to any client site.

## Options considered

1. **Hub-and-spoke: the identity site is the only SP**: client sites start the flow by creating their state (same as network SSO), hand `client_blog_id` + `state` through `RelayState`, the ACS consumes the assertion on the identity site, then the existing ticket mint carries the user to the originating site. One IdP app per network.
2. **Per-site SPs**: each site has its own ACS; no dependency on the identity site, but N app entries per network, per-domain configuration in the IdP, and none of the existing flow is reusable.

## Decision

We will terminate external SSO at the identity site (option 1), because the identity site is already the session authority for ticket minting, and one IdP app entry per network is the operable configuration for domain-mapped installs.

## Consequences

- OneLogin configuration: entity ID and ACS URL of the identity site only, stable across every mapped domain.
- Clicking the external button on the identity site logs into the identity site directly (no RelayState hand-off needed).
- External users land on client sites with the same scope checks as network users — the destination network's `pv_wpms_sso.scope` governs, so a `super_admins` default blocks provisioned external users until widened. Deliberate and documented.
- The identity site's availability becomes a dependency for external SSO on client sites, exactly as it already is for network SSO.

## Revisit when

An install demands multiple IdP apps, unsolicited IdP-initiated flows, or an external IdP per site rather than per network.

## Links

- Spec: [SAML SSO](../specs/saml-sso.md)
- Plan: [SAML SSO](../plans/2026-11-04-saml-sso.md)
- Decision: [0003: Store trust against the IdP site](0003-store-trust-against-the-idp-site.md)
