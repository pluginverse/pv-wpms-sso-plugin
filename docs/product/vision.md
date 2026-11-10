---
title: Vision
description: Who PV WPMS SSO is for, the problem it solves, and what it will not become.
---

## Who it is for

Administrators of WordPress Multisite installs — including multi-network installs built with wp-multi-network and domain-mapped site networks — who sign in to many sites on different domains as part of their daily work.

## The problem

WordPress auth cookies are host-scoped. On a network of domain-mapped sites, a user signed in on the network's main site is anonymous on every mapped domain: to work on `shop.example.net` after signing in at `network.example.com`, they must re-enter the same username and password and pass two-factor again, for every domain, every time the cookie expires. Network and super admins feel this most: they touch every site.

## What it is

PV WPMS SSO (Plugin Verse WordPress Multisite Single Sign On) adds a "Sign in with {network name}" button to each site's login screen. Clicking it delegates authentication to one identity site — the network's root site, or any designated site in the install — where a single existing session (already past two-factor) mints a short-lived, single-use signed ticket that logs the user into the site they asked for, cookie and all. The button's inline probe asks the identity site whether a session already exists, so users see "Continue as {name}" with their photo instead of a second credential prompt.

For super admins and members of several sites, a completed local sign-in can establish that identity session through a browser-bound hand-off, preserving the source site's password and two-factor flow. Subsequent SSO into another mapped domain does not ask again. Under `site_members`, ordinary users can enter only sites where they already hold a role, including explicitly assigned sites in another network.

## What it is not

- Not an identity provider for third-party apps: no SAML, OIDC, or OAuth server.
- Not a two-factor plugin: it rides on whatever protects the original source or identity-site login.
- Not a cross-database or cross-install SSO: trust assumes one shared WordPress database (the wp-multi-network model).
- Not a session synchronizer: it does not mirror logouts or manage session lifetimes across domains.

## How we know it works

An administrator signs in once (credentials + two-factor) at the identity site and then reaches any site's admin on the network through the SSO button with no second credential prompt — and a user outside the configured scope stays out.
