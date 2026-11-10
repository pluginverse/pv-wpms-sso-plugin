# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-10

### Added

- Reuse completed local sign-ins across mapped HTTPS domains through a browser-bound, single-use hand-off to the identity site. Preserve local password/two-factor login and destination-site membership; refuse conflicting hub identities.
- Hand off only accounts that can reach another site — super admins of any network using the identity site, and members of several sites when the scope allows members. Site-local users are never redirected. The `pv_wpms_sso/hand_off_local_login` filter overrides the rule.
- Executable BDD for local, network, cross-network, and OneLogin journeys, with stable behavior IDs and isolated HTTPS browser checks.
- SSO button on each site's login screen delegating to the network's root or designated identity site, with signed single-use tickets, a credentialed session probe that personalizes the button ("Continue as {name}" with avatar), and super-admin-default scope configurable per network. Works across networks of a wp-multi-network (same-database) install.
- Network admin settings screen (Settings → SSO): enable/disable, identity site selection (root or designated, cross-network capable), scope, button label, signing-key rotation.
- WP-CLI commands: `wp pv-wpms-sso status`, `wp pv-wpms-sso rotate-key`.
- External single sign-on via SAML 2.0 (OneLogin, Okta, any SAML IdP): an additional login button, SP metadata endpoint, strict signature/audience/replay validation at the identity site, email-based user mapping with opt-in auto-provisioning, and the same ticket hand-off into every site of the network. Powered by bundled onelogin/php-saml (MIT).

### Fixed

- Keep every query argument of the post-login destination through the identity-site hand-off, and stop a crafted destination from overriding hand-off parameters.
- Keep WordPress's default landing page after a local login: members who cannot edit posts reach their profile, and accounts without a role on the site are redirected by WordPress as before.
- Tolerate unexpected `login_redirect` values from other plugins instead of failing the login.
- Remove local-session hand-off transients on uninstall.
- Preserve root-relative return paths on subdirectory sites and match session-probe origins by scheme, hostname, and port.
- Return SAML users to their registered mapped-domain destination instead of the hub fallback; use the destination dashboard when no return path was requested.
- Recognize the hub's valid login cookie during nonce-less SSO REST redirects and session probes, without changing authentication on other REST routes.
- Enforce destination scope inside ticket redemption and reject archived/deleted/spam sites as trusted SSO sources or clients.
- Describe the broad `network_users` scope as any account in the shared installation, rather than implying network membership.
