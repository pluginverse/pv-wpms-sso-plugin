---
title: Testing
description: PHPUnit, executable user journeys, stable behavior IDs, and isolated HTTPS browser acceptance.
---

## Suite boundaries

- `composer test-unit`: pure PHPUnit tests on the host, without WordPress or Docker.
- `composer phpstan`: level 9 static analysis on runtime PHP.
- `composer env:start`, then `composer test`: PHPUnit unit and WordPress multisite integration tests in wp-env. These tests use the disposable test database, never a production database.
- `composer test-bdd`: Behat 4 runs Gherkin in `tests/Behavior/features/`, using WordPress's transaction-isolated fixture lifecycle and real plugin methods. The fixture creates separate network records sharing one users table and explicitly switches network as well as blog context. Domain-level acceptance does not prove browser-cookie behavior.
- `npm run test:browser`: Playwright checks real HTTPS redirects and separate host cookies against an independently configured disposable WordPress environment.

The BDD wrapper uses an explicit feature path, so a tag selection matching no scenarios fails instead of reporting an empty successful run.

Run BDD and PHPUnit sequentially: both reinstall the root wp-env test database. The browser environment has its own configuration, cache identity, database, and ports, so it does not seed or reset the normal development site.

## Browser setup

```sh
composer install
npm ci
npx playwright install chromium
npm run browser:start
npm run browser:seed
npm run test:browser
npm run browser:stop
```

Linux CI uses `npx playwright install --with-deps chromium`. Docker, Node 22.13 or newer, and OpenSSL are required. Ports 8894 and 9443 must be free. The proxy binds only to loopback and permits only the fixture domains. It forwards the original Host to the disposable WordPress instance. Test certificates are generated in a temporary directory and removed at shutdown. Chromium resolves reserved `.test` domains locally; no OS hosts file or trust store changes are needed.

The fixture MU plugin is mounted only by `tests/Browser/.wp-env.json` and requires both the explicit test constant and `WP_ENVIRONMENT_TYPE=local`. It must never be deployed. Its deterministic second factor is `654321`; fixture passwords and IdP keys are test data for the disposable environment. Keys are generated locally, stored only in its database, and replaced when seeded again. The test provider signs real SAML responses; php-saml performs production validation at the hub ACS.

Fixtures use the `site_members` scope on both networks. Casey belongs only to Northcoast; Alex belongs to Northcoast and Southmountain; Riley belongs to Northcoast and Ridgeview on different networks. Morgan provides an existing conflicting hub account. No fixture user is a super admin.

The browser suite checks completed local login, separate Secure/HttpOnly cookies, preservation of the requested destination, site-local denial without source logout, allowed and denied cross-network SSO, missing second factor, conflicting hub identities, and the OneLogin hand-off. Generated reports stay under ignored `test-results/`.

## Behavior IDs

Each scenario has exactly one stable tag: `SSO-NNN` or `SAML-NNN`. Do not reuse retired IDs. Supporting PHPUnit methods/classes use filterable `@group` metadata; browser test names include their supported ID. Step definitions translate declarative steps into plugin operations and assertions, without implementing another authorization policy.

```sh
composer test-bdd -- --tags=@SSO-001
composer test -- --group SSO-001
npm run test:browser -- --grep SSO-001
composer test-trace
```

The trace checker comes from `spenserhale/skills` (`bdd-gherkin`, MIT; attribution in `bin/trace_ids.py`). It rejects duplicate IDs, orphan test IDs, and untagged scenarios. IDs record claimed relationships and do not prove coverage. The checked-in features and their actual executions provide separate evidence.

## Regression evidence

Before implementation, `SSO-001` failed because the normal login redirect stayed on Northcoast rather than starting the hub hand-off. `SSO-008` failed when the domain-level redemption method returned a ticket despite revoked membership; scope now runs inside redemption. A separate regression reproduced WordPress clearing authentication on a nonce-less REST request despite a valid logged-in cookie. An archived-source regression reproduced an already-issued local proof being accepted after source archival. The HTTPS SAML test also exposed the checked cross-domain callback host being absent from WordPress's safe-redirect allowlist and an empty return path producing a blank response. Both now have regressions.

Invalid state/cookie pairs, changed user/login/source/audience, purpose confusion, expiry, account conflicts, removed trust, root-relative subdirectory return paths, and atomic replay races are covered in `LocalSessionBridgeTest`. Keep these focused security cases in PHPUnit rather than multiplying stakeholder scenarios.

## Provider and tooling limits

Passing the deterministic second-factor and local SAML provider proves the plugin's boundaries, not universal compatibility. Run the real production two-factor plugin and OneLogin tenant through the same journeys before deployment. A two-factor plugin must withhold usable WordPress cookies until completion and must reach WordPress's final `login_redirect` filter for automatic local-session hand-off.

Third-party-cookie blocking can prevent button personalization; top-level SSO does not depend on that probe succeeding. Existing sessions that never established hub identity require a participating login or direct hub login.

The host currently emits a duplicate Herd module warning, and the root wp-env configuration uses deprecated combined development/test options. Neither warning is a passing-test substitute. The pinned development-only `@wordpress/env` dependency also currently brings moderate transitive npm advisories; `npm audit fix` cannot resolve them without changing its supported dependency graph. None of these Node packages is shipped in the plugin build.
