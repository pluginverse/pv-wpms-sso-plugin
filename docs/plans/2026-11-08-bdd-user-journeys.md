---
title: "Plan: Test user journeys and bridge completed local logins"
description: "Add executable BDD for mapped-domain and multi-network access, then implement the missing session hand-off from a local login to the identity site."
status: approved
date: 2026-11-08
size: architectural
specs: ["single-sign-on", "saml-sso"]
---

## Intent

A person signs into Northcoast Business with their password and two-factor, then uses SSO to reach Southmountain Business without authenticating again. Those sites use different HTTPS domains and cannot share cookies. The same experience must work across networks sharing the WordPress database, without granting access to a site where the person has no role. OneLogin is another way to establish the same identity session.

Spenser confirmed that network membership does not grant access to every site: a user may SSO only into sites where they already have a role. A site-local user has one site membership; a network-local user has memberships on several sites in one network; a cross-network user has memberships on sites in multiple networks. These are examples of existing membership, not three new user types.

Spenser requested executable BDD first, followed by implementation of the missing behaviors.

## Existing behavior and baseline

- The existing flow reuses a session on the configured identity site. It cannot reuse a session that exists only on Northcoast's separate domain.
- The existing `site_members` scope implements destination-site membership. Configure the journey fixtures with that scope on both networks.
- The existing `network_users` scope means any existing account in the shared installation. It does not enforce network membership. Decision 0004 deliberately permits that broader setting; this work must not silently reinterpret it.
- Existing SAML support validates signed responses and continues through the ticket flow. OneLogin accounts remain subject to destination authorization.
- Baseline on 2026-11-08: `composer phpstan` passed; `composer test-unit` passed with 36 tests and 53 assertions; `composer test` passed in Docker with 61 tests and 146 assertions.
- The host reports a duplicate Herd extension warning during PHPStan. wp-env reports deprecated combined development/test configuration. Neither failed the baseline; neither establishes the browser behavior below.

## Example map

### Story: Reuse a completed login across mapped domains

- Rule: A completed local login establishes a reusable identity session.
  - Example: Alex signs into Northcoast, completes its two-factor challenge, and later uses SSO on Southmountain without another credential prompt.
  - Example: Alex has not completed Northcoast's two-factor challenge; the identity site must not establish Alex's session.
- Rule: Reusing SSO must preserve the requested destination.
  - Example: Alex requests Southmountain's post editor and reaches that editor after SSO.
- Rule: Authentication must not silently switch another identity.
  - Example: The identity site already has Morgan's session when Alex completes a Northcoast login; the flow refuses to replace Morgan's session silently.

### Story: Existing memberships bound SSO access

- Rule: A site-local account stays local.
  - Example: Casey has a role only on Northcoast; Southmountain refuses Casey's SSO request.
- Rule: A network-local account can reach its other member sites.
  - Example: Alex has roles on Northcoast and Southmountain in the Coast network; SSO into Southmountain succeeds.
  - Example: Alex has no role on a third Coast site; that site refuses SSO.
- Rule: Shared infrastructure does not grant cross-network access.
  - Example: Riley has roles on Northcoast in Coast and Ridgeview in Mountain; Ridgeview accepts Riley through the shared identity site.
  - Example: Alex has roles only in Coast; Ridgeview refuses Alex even though both networks trust the same identity site.
- Rule: A permission removed during a flow prevents destination login.
  - Example: Riley's Ridgeview role is removed after ticket issuance but before redemption; Ridgeview refuses login.

### Story: Sign in through OneLogin

- Rule: An existing authorized account can enter through OneLogin.
  - Example: OneLogin authenticates Riley, whose WordPress account already has a Ridgeview role; Riley lands on Ridgeview as the same account.
- Rule: External authentication does not grant site membership.
  - Example: OneLogin authenticates Casey, who has no Southmountain role; Southmountain refuses login.
  - Example: An unknown OneLogin account is refused when automatic provisioning is disabled.

## Acceptance criteria

- Gherkin features use `Feature`, `Rule`, and declarative Given/When/Then steps. Every scenario has exactly one stable ID, using `SSO-001` onward for network SSO and `SAML-001` onward for OneLogin.
- Behat runs the scenarios against WordPress and the plugin's actual endpoints/domain interfaces in the existing disposable wp-env test environment. Steps do not substitute a second implementation of access policy.
- Scenarios supporting currently implemented behavior pass before runtime changes. The local-login journey fails because the identity session is absent, rather than because steps are undefined or skipped.
- Supporting PHPUnit tests carry the same IDs as filterable `@group` metadata. The skill's trace checker reports no duplicate, orphan, or untagged scenario IDs.
- A completed local login can establish the identity-site session through verified browser redirects. A pending password or two-factor challenge cannot establish it.
- The destination network's `site_members` policy is checked at issuance and redemption. The flow never adds a site role or network privilege.
- Site-local, network-local, allowed cross-network, forbidden cross-network, and permission-revocation examples are executable.
- OneLogin success and denial examples use a locally generated test signing key and signed SAML responses; no real OneLogin credentials or tenant are needed for automated tests.
- A focused browser suite proves distinct HTTPS origins receive separate cookies and that the local-login-to-hub-to-destination flow works. Passing domain-level scenarios alone is not reported as proof of browser cookies or two-factor compatibility.
- `composer phpstan`, `composer test-unit`, `composer test`, BDD, trace validation, and the focused browser suite pass before completion. Any unavailable check is reported explicitly.

## Non-goals

- New user categories, network-wide role grants, automatic destination membership, or an independent permission database.
- Changing the default `super_admins` scope or silently narrowing the explicitly broad `network_users` scope.
- Cross-install or separate-database SSO, logout synchronization, or multiple SAML providers.
- Live OneLogin tenant setup or a claim that a test identity provider proves a real tenant's configuration.
- Universal compatibility with every third-party two-factor plugin. The test suite must identify its test provider; production providers require their own acceptance run.

## Design

### Test tooling

Use Behat 4 as a development-only PHP dependency, with features under `tests/Behavior/features/` and thin step definitions over WordPress fixtures and actual plugin entry points. Behat 4 supports PHP 8.2 and modern Gherkin rules. Keep PHPUnit for unit and integration tests. Add a checked-in trace script from the BDD skill, with attribution, and a documented command that scans only project features and tests.

The runner-up is Cucumber-JS for everything. It is appropriate for browser work but would require a JavaScript-to-PHP fixture interface even for server-side acceptance checks. A documentation-only collection of feature files is insufficient because the requested acceptance criteria must execute.

For the focused browser proof, use Playwright with a disposable WordPress fixture, test-only HTTPS certificates, and separate reserved `.test` hostnames for the hub, Northcoast, Southmountain, and Ridgeview. Browser host resolution and test proxying must not edit the user's system hosts file or trust store. Test traffic must stay local. The browser fixture exercises normal login, a deterministic test-only second-factor challenge, SSO, and denied destinations. Test certificates, generated keys, state, and reports are not committed.

### Preserve local logins; establish the hub afterward

Recommended approach: retain each site's ordinary login and two-factor flow. After that flow completes, direct the browser through a short hand-off that establishes the configured identity site's session before returning to the original site's validated destination. The source must prove that the browser holds its completed authenticated session; a posted user ID, a shared database row, or an early authentication hook is not proof.

The hand-off has these security requirements:

1. The identity site starts a browser-bound, expiring transaction with a fresh state cookie and stored source/return-site information.
2. The browser returns to the registered source site, which checks its actual authenticated session and issues a short-lived, single-use proof for that identity site and transaction.
3. The identity site validates the state cookie and transaction, source trust, signature, audience, user binding, expiry, and atomic replay claim before setting its own auth cookie. A local-login proof cannot be redeemed as an ordinary destination ticket; use an explicitly distinct protocol purpose.
4. If the hub already holds a different account's session, refuse silent replacement and show an actionable account-conflict notice. A matching session can continue.
5. Return only to the original registered source site and its validated local path. Keep normal destination redirects behind `RedirectGuard` and `wp_safe_redirect`.
6. Continue to authorize every later destination independently. Establishing a hub session conveys identity, not a role on the hub or another site.

The selected hook must run only after login completes, and the source proof must recheck the browser cookie on the later request. A failure must leave the successful local login usable, report that SSO setup failed, and avoid redirect loops.

Alternative: route all initial sign-ins through the identity site. This reuses more of today's protocol and has less code, but changes where credentials and two-factor are entered and does not reuse an independently completed local login. It is not the proposed default for the stated journey.

Third-party-cookie access and scanning other domains for sessions are excluded: unrelated domains cannot read one another's cookies, and third-party-cookie availability cannot be a prerequisite.

### Membership and networks

Use existing destination-site memberships for ordinary users under `site_members`. Both networks explicitly designate the shared identity site. Fixtures create two real network records sharing the users table, not just several blogs on one network. Server-side tests set the network context as well as the blog context; browser requests prove that actual host routing selects the expected network.

Preserve the existing deliberate scope choices and super-admin behavior from decision 0004. Correct misleading documentation or labels that describe `network_users` as a network-membership check; explain that membership-limited deployments use `site_members`.

### OneLogin

Reuse the existing hub-only SAML service provider, email mapping, and signed-response fixtures. Test an existing user with destination membership, an authenticated user without that membership, and an unknown account with provisioning disabled. Reuse the same destination checks as password-based SSO. Do not introduce SAML role synchronization.

## Proposed scenario wording

These are design examples, not claims that the behavior already exists:

```gherkin
@mapped-domains
Feature: Reuse a completed local login
  Rule: A completed login can be reused on another member site
    @SSO-001
    Scenario: Move from Northcoast to Southmountain without authenticating again
      Given Alex has a role on Northcoast Business
      And Alex has a role on Southmountain Business
      And Alex completed password and two-factor sign-in on Northcoast Business
      When Alex requests single sign-on to Southmountain Business
      Then Alex is signed into Southmountain Business
      And Alex is not asked for a password or second factor again

@site-access
Feature: Keep existing site access boundaries
  Rule: Shared identity does not grant a site role
    @SSO-002
    Scenario: A site-local user cannot enter another mapped site
      Given Casey has a role only on Northcoast Business
      And Casey completed sign-in on Northcoast Business
      When Casey requests single sign-on to Southmountain Business
      Then Southmountain Business refuses access
      And Casey remains signed into Northcoast Business

  Rule: A person can cross networks only into a member site
    @SSO-003
    Scenario: A user with roles in both networks can use their destination site
      Given Riley has a role on Northcoast Business in the Coast network
      And Riley has a role on Ridgeview Business in the Mountain network
      And both networks trust the same identity site
      And Riley completed sign-in on Northcoast Business
      When Riley requests single sign-on to Ridgeview Business
      Then Riley is signed into Ridgeview Business

    @SSO-004
    Scenario: A network-local user cannot enter another network's site
      Given Alex has roles only in the Coast network
      And both networks trust the same identity site
      And Alex completed sign-in on Northcoast Business
      When Alex requests single sign-on to Ridgeview Business
      Then Ridgeview Business refuses access

@external-sign-in
Feature: Sign in through OneLogin
  Rule: External identity remains subject to destination membership
    @SAML-001
    Scenario: An existing member enters their site through OneLogin
      Given Riley has a role on Ridgeview Business
      And Riley's OneLogin identity matches their WordPress account
      When Riley requests OneLogin sign-in to Ridgeview Business
      Then Riley is signed into Ridgeview Business as their existing account
```

The complete features also cover same-network denial, permission removal, unfinished two-factor, conflicting hub identities, invalid hand-offs, replay, and OneLogin denial. Each gets a distinct ID when written; these five IDs stay attached to their examples.

## Spec delta

- `docs/specs/single-sign-on.md`: add completed-local-login hand-off, refusal of conflicting hub identities, browser-bound proof and failure behavior; link executable scenarios; replace the deferred browser-test statement once the browser test exists.
- `docs/specs/single-sign-on.md`: clarify `network_users` as shared-installation account existence and `site_members` as the membership-preserving choice. Do not change existing scope semantics in this clarification.
- `docs/specs/saml-sso.md`: link executable OneLogin acceptance scenarios and distinguish local signed-response tests from live provider validation.
- Apply these statements only with the corresponding implementation and passing tests, never while this plan is a draft.

## Docs impact

- `docs/guides/testing.md`: create the currently deferred guide with suite commands, fixture boundaries, stable IDs, trace limitations, browser setup, and red/green evidence.
- `docs/guides/security.md`: document source-session trust, proof purpose, state binding, replay prevention, identity-conflict handling, and the completed-two-factor boundary.
- `docs/product/vision.md` and `README.md`: describe completed local login reuse once shipped; distinguish source login from later one-click SSO; clarify the broad account scope.
- `CHANGELOG.md`: add the local-login reuse behavior under Unreleased in the implementation commit.
- `AGENTS.md`: link the new testing guide once it exists.

## Decisions

Spenser approved these decisions on 2026-11-09:

- Add Behat 4 and stable behavior IDs as the PHP acceptance-test convention; retain PHPUnit and use a focused Playwright suite for browser-only guarantees.
- Extend identity-site trust to completed local sessions through a browser-bound, purpose-separated, single-use hand-off. This extends the authentication model described by decisions 0002 and 0003 and the security guide; document the extension in a new decision record rather than silently changing those records.

Decision 0004 remains authoritative for configurable scopes; decision 0005 remains authoritative for hub-only SAML.

## Tasks

> **For agentic workers:** Execute inline using hdd-executing-plans and hdd-test-driven-development. Each runtime change carries its tests and documentation.

**Goal:** Reuse completed local sessions without expanding site access.

**Architecture:** `LocalSessionBridge` owns browser-bound local-session proof; existing ticket and SAML flows own destination access. Behat uses the WordPress test fixture; Playwright verifies real HTTPS cookies.

**Tech Stack:** PHP 8.2, WordPress multisite, Behat 4, PHPUnit 9, Playwright.

- [ ] Add `behat.dist.php`, `tests/Behavior/`, `bin/behavior.sh`, and `bin/trace_ids.py`; run `composer test-bdd` and confirm the local-login redirect assertion fails before runtime changes.
- [ ] Add `src/LocalSessionBridge.php` with `filterLoginRedirect`, `begin`, `prove`, `complete`, and the static login handler. Register hooks in `pv-wpms-sso.php`. Test real source-cookie validation and separate the proof signing key from destination tickets using HMAC key derivation.
- [ ] Extend `tests/Integration/` for invalid state, expired state, replay, trust removal, cookie mismatch, wrong audience/purpose, and account conflict. Run `composer test -- --group SSO-001` before and after implementation.
- [ ] Add mapped-domain and actual multi-network BDD fixtures; use `site_members` and assert issuance and redemption independently. Link PHPUnit groups to stable behavior IDs.
- [ ] Add `tests/Browser/` with an isolated wp-env config, local HTTPS proxy, reserved `.test` origins, and a test-only second factor; run `npm run test:browser` to prove cookie and redirect behavior.
- [ ] Update both specs, security/testing guides, README, vision, changelog, and CI; run all suites and trace validation, review the diff, and commit the complete change.


1. Review this design; create a feature branch and commit the approved plan and new decision records before writing test or runtime code.
2. Install Behat as a dev dependency, add its WordPress bootstrap and scenario driver, write features from the example map, and link supporting PHPUnit tests by behavior ID. Record which scenarios fail and why before runtime changes.
3. Implement the local-login hand-off at the narrowest appropriate entry points. Add regression tests for state/cookie mismatch, wrong source or audience, protocol-purpose confusion, replay, unfinished authentication, account conflicts, and safe return destinations. Keep hook registration lazy and static.
4. Complete same-network and cross-network membership tests, including permission removal at redemption. Correct misleading broad-scope labels and docs without changing the accepted policy.
5. Bind OneLogin examples to signed local SAML fixtures and verify success and refusal paths through destination authorization.
6. Add the focused HTTPS browser flow and test-only second-factor fixture; verify separate cookies and actual host/network routing. Wire BDD and trace checks into CI, excluding test tooling from built packages.
7. Apply spec, guide, product, and changelog updates with their code. Run all acceptance checks, inspect the complete diff, and report residual limitations.

## Deferred

- Live OneLogin acceptance requires a real tenant/application and is separate from automated signed-response validation.
- A browser that was already logged into a spoke before this feature existed does not automatically reveal that domain's cookie to the hub. The new flow establishes the hub session after a participating local login; support for pre-existing unbridged sessions would require an additional explicit source-selection or migration flow.
