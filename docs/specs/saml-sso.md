---
title: "SAML SSO"
description: External single sign-on via SAML 2.0 (OneLogin, Okta, ...) terminating at the identity site and continuing through the ticket flow.
status: current
---

## Intent

Networks whose users live in an external identity provider need a way in without local WordPress passwords: an "Sign in with OneLogin" button on every login screen that carries a user with a live IdP session through to the site they wanted — across all mapped domains, with exactly one external prompt (and none at all when the IdP session already exists).

## Behavior

- External SSO is a hub-and-spoke flow: the network's identity site is the only SAML service provider (decision 0005). One IdP application per network — entity ID `{identity site}/wp-json/pv-wpms-sso/v1/saml/metadata`, ACS `{identity site}/wp-login.php?action=pv_wpms_sso_saml_acs`.
- The identity site's network stores the IdP application in the network option `pv_wpms_sso_saml`: enabled (default false), idp_entity_id, idp_sso_url, idp_x509cert (PEM or base64 body, collapsed on save), button_label (default "Sign in with OneLogin"), auto_provision (default false), default_role (subscriber). Partial saves merge over stored values.
- Every login screen of the network (client sites and the identity site itself) shows the external button when the library is present, network SSO is enabled, and the identity site's network has SAML configured. It renders alongside the network SSO button.
- Client-site start (`wp-login.php?pv_wpms_sso=saml_start`): creates the same state binding as network SSO (10-minute transient + HttpOnly cookie on the client), then hops to the identity site's entry carrying `client_blog_id` + `state`. Identity-site start (`action=pv_wpms_sso_saml_start`): the SP flow with no client.
- SP flow: a php-saml `AuthnRequest` (email NameID format) is deflated and sent to the IdP SSO URL via redirect binding; the request ID is stored in a 10-minute transient holding `{client_blog_id, state}`. `RelayState` mirrors the client parameters for the IdP's benefit; redemption trusts only the stored transient.
- ACS (POST): `OneLogin\Saml2\Response` validation in strict mode — message signature against the configured certificate (RSA-SHA256, `wantMessagesSigned`), audience = this SP's entity ID, destination = the ACS URL, timestamp window — plus the replay closure: `InResponseTo` must resolve to a stored, unexpired, unconsumed request transient, which is deleted on read.
- User mapping (decision 0007): email from NameID when it contains `@`, else the first of `email` / `mail` / `user.email` / `urn:oid:0.9.2342.19200300.100.1.3`; exact email match to an existing user; when `auto_provision` is on, unknown emails get a network user (username = sanitized local part, deduped; uncommunicated random password; configured default role on the identity site; display name from `displayName` or first+last attributes). No match and no provisioning: `saml_unknown_user`.
- On success the ACS sets the identity site's cookies and fires `pv_wpms_sso/saml_login`. With a stored client, it applies the same mint-time policy as `authorize` (trust + destination-network scope) and redirects to the client callback with a ticket from `TicketIssuer` — everything after identity confirmation is the network SSO flow. Without a client, it redirects to the user's dashboard.
- The ACS permits the generated callback host only after checking it against the stored client site URL, then uses `wp_safe_redirect`. An empty final return path lands on the destination dashboard.
- Validation failures redirect to a login screen with `saml_invalid_response`; the reason is available to admins via the `pv_wpms_sso/saml_validation_failed` action, never shown to the browser.
- The SP metadata endpoint (`GET /wp-json/pv-wpms-sso/v1/saml/metadata`) serves the SP entity descriptor; it responds 404 on sites that are not the network's identity site.
- SAML users are subject to the destination network's `pv_wpms_sso.scope` at mint and redemption like anyone else — with the default `super_admins` scope they are kept out of client sites until the network widens the scope.
- All SAML wiring no-ops when the bundled library is absent (`saml_library_available()`); network SSO is unaffected and the settings page shows a warning.

## Acceptance criteria

- Settings defaults, certificate collapsing, partial-save merge (`tests/Unit/SamlSettingsTest.php`, `tests/Integration/KeyStoreAndSettingsTest.php`)
- Email mapping NameID-first, attribute fallback, none (`tests/Integration/SamlFlowTest.php`)
- SP metadata served on the identity site with ACS and email NameID format (`tests/Integration/SamlFlowTest.php`)
- Start builds a deflated AuthnRequest redirect with stored request transient and RelayState (`tests/Integration/SamlFlowTest.php`)
- Full crypto round trip: response signed with a generated IdP keypair validates, provisions the user, and issues an audience-bound ticket to the client site (`tests/Integration/SamlFlowTest.php`)
- Replayed response, response to an unknown request, wrong signing key, foreign audience, and unknown user without provisioning are all rejected (`tests/Integration/SamlFlowTest.php`)

- Executable OneLogin examples: `tests/Behavior/features/onelogin.feature` (`SAML-001`–`SAML-003`), using real signed SAML responses and the existing php-saml validation path.
- Browser acceptance uses a local test IdP and separate HTTPS domains. It proves the browser hand-off, not a production OneLogin application's configuration.

## Non-goals

- IdP-initiated (unsolicited) responses; SLO/logout propagation.
- Multiple IdPs per network; per-site SP topology; signed AuthnRequests.
- Attribute-driven role assignment or group authorization.
- OIDC / OAuth providers.

## Constraints

- Requests and responses must complete within 10 minutes of the AuthnRequest.
- Message-signed responses required (RSA-SHA256); assertion-only signing is rejected — configure OneLogin/Okta to sign the response (the default).
- The plugin ships php-saml in `vendor/` via the built package; a source checkout without `composer install` has SAML inactive.

## Decisions

- [0005: Terminate external SSO at the identity site](../decisions/0005-terminate-external-sso-at-the-identity-site.md)
- [0006: Bundle onelogin/php-saml for the SAML client](../decisions/0006-bundle-onelogin-php-saml-for-the-saml-client.md)
- [0007: Map SAML users by email, provision on opt-in](../decisions/0007-map-saml-users-by-email-provision-on-opt-in.md)
