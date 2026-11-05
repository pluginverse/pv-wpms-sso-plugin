---
title: "0003: Store trust against the IdP site, not per network"
description: The HMAC key lives as an option on the designated IdP site and the replay registry in one global table, so every network in the same database can trust one IdP.
status: accepted
date: 2026-11-04
decision-makers: "Spenser Hale, GLM (OpenCode drafting agent)"
---

## Context

The plugin must support both a single network (root site or a designated site as IdP) and multi-network installs built with wp-multi-network — which share one database: one `wp_blogs` table, one users table, per-blog options, per-network site meta. Network options (`sitemeta`) are scoped per network ID, so anything stored there is invisible to other networks.

Trust data: (a) the HMAC signing key, (b) the single-use replay registry, (c) the pointer that says which site a network uses as its IdP.

## Options considered

1. **Store the key as a network option on the IdP's network**: leaks across networks the wrong way — client networks on other networks cannot read another network's `sitemeta` without hardcoding IDs, and two networks designating the same IdP site would need key copies.
2. **A per-network client registry on the IdP (client IDs and secrets per network)**: explicit, OAuth-like, but all networks share one database, so the registry only re-derives what `wp_blogs` and each network's settings already say.
3. **Blog-scoped storage: key as an option on the IdP blog itself, replay registry as one global table, IdP pointer in each network's settings**: every side of a flow reads the same rows regardless of which network it belongs to; `PV_WPMS_SSO_KEY` in wp-config can override the stored key.

## Decision

We will store trust against the IdP site itself, because blog options and global tables are the only storage shared by every network of a wp-multi-network install.

Concretely:

- The signing key is an autoload-off option (`pv_wpms_sso_key`) on the IdP blog; `KeyStore` generates it lazily (64 hex chars from `random_bytes`) and any network that resolves its IdP to that blog reads the same key.
- `PV_WPMS_SSO_KEY` (wp-config constant) overrides the stored key, for managed platforms that forbid lazily generated secrets in the database.
- The replay registry is the global `{base_prefix}pv_wpms_sso_jti` table (decision 0002).
- Each network's settings (`pv_wpms_sso` network option) hold `idp_blog_id` (0 = that network's main site), so any network can point at any site in the install, including another network's site — that is cross-network SSO with zero extra configuration.

## Consequences

- Cross-network SSO works with no client registration step; the client network's `idp_blog_id` IS the registration.
- Rotating a key on the IdP site affects every network trusting it; that is the intended blast radius and the settings page says so.
- The design assumes one shared database. Split-database installs (HyperDB shard-by-network, separate DBs per network) are out of scope until the protocol itself changes (decision 0002's revisit clause).

## Revisit when

A network's database is split from the install, or an install wants to trust an IdP on a different WordPress installation.

## Links

- Spec: [Single sign-on](../specs/single-sign-on.md)
- Plan: [Initial SSO plugin](../plans/2026-11-04-initial-sso-plugin.md)
- Decision: [0002: Mint HMAC-signed, single-use SSO tickets](0002-mint-hmac-signed-single-use-sso-tickets.md)
