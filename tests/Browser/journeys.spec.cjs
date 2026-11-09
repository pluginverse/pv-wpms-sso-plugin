const {test, expect} = require('@playwright/test');
const origin = name => `https://${name}.test:9443`;

async function login(page, person, site = 'northcoast', factor = true) {
  await page.goto(`${origin(site)}/wp-login.php`);
  await page.getByLabel('Username or Email Address').fill(person);
  await page.getByLabel('Password', {exact:true}).fill('browser-test-password');
  if (factor && person !== 'morgan') await page.getByLabel('Second factor').fill('654321');
  await page.getByRole('button', {name:'Log In', exact:true}).click();
}

async function sso(page, site, path = '/wp-admin/edit.php') {
  await page.goto(`${origin(site)}/wp-login.php?redirect_to=${encodeURIComponent(origin(site)+path)}`);
  await page.locator('#pv-wpms-sso-button').click();
}

async function identity(page, site) {
  await page.goto(`${origin(site)}/?pv_browser_identity=1`);
  return JSON.parse(await page.locator('body').innerText());
}

test('SSO-001 completed local login creates separate hub and destination cookies', async ({page, context}) => {
  await login(page, 'alex');
  await expect(page).toHaveURL(`${origin('northcoast')}/wp-admin/`);
  const before = (await context.cookies()).filter(cookie => cookie.name.startsWith('wordpress_logged_in'));
  expect(before.map(cookie => cookie.domain).sort()).toEqual(['auth.test', 'northcoast.test']);
  expect(before.every(cookie => cookie.secure && cookie.httpOnly)).toBe(true);
  await sso(page, 'southmountain');
  await expect(page).toHaveURL(`${origin('southmountain')}/wp-admin/edit.php`);
  const after = (await context.cookies()).filter(cookie => cookie.name.startsWith('wordpress_logged_in'));
  expect(after.map(cookie => cookie.domain).sort()).toEqual(['auth.test', 'northcoast.test', 'southmountain.test']);
  expect((await identity(page, 'southmountain')).user).toBe('alex');
});

test('SSO-002 site-local user is refused without losing source login', async ({page}) => {
  await login(page, 'casey');
  await expect(page).toHaveURL(`${origin('northcoast')}/wp-admin/`);
  await sso(page, 'southmountain');
  await expect(page).toHaveURL(/pv_wpms_sso_error=access_denied/);
  expect((await identity(page, 'southmountain')).user).toBe('');
  expect((await identity(page, 'northcoast')).user).toBe('casey');
});

test('SSO-003 membership permits SSO across actual networks', async ({page}) => {
  await login(page, 'riley');
  await expect(page).toHaveURL(`${origin('northcoast')}/wp-admin/`);
  const coast = await identity(page, 'northcoast');
  await sso(page, 'ridgeview');
  await expect(page).toHaveURL(`${origin('ridgeview')}/wp-admin/edit.php`);
  const mountain = await identity(page, 'ridgeview');
  expect(mountain.user).toBe('riley');
  expect(mountain.network).not.toBe(coast.network);
});

test('SSO-004 shared identity does not permit an unassigned network', async ({page}) => {
  await login(page, 'alex');
  await expect(page).toHaveURL(`${origin('northcoast')}/wp-admin/`);
  await sso(page, 'ridgeview');
  await expect(page).toHaveURL(/pv_wpms_sso_error=access_denied/);
  expect((await identity(page, 'ridgeview')).user).toBe('');
});

test('SSO-005 unfinished second factor issues no authenticated cookies', async ({page, context}) => {
  await login(page, 'alex', 'northcoast', false);
  await expect(page.getByText('Complete the test second factor.')).toBeVisible();
  expect((await context.cookies()).filter(cookie => cookie.name.startsWith('wordpress_logged_in'))).toEqual([]);
});

test('SSO-006 another hub identity remains signed in after conflict', async ({page}) => {
  await login(page, 'morgan', 'auth');
  await expect(page).toHaveURL(`${origin('auth')}/wp-admin/`);
  await login(page, 'alex');
  await expect(page.getByText(/A different account is signed into the identity site/)).toBeVisible();
  expect((await identity(page, 'auth')).user).toBe('morgan');
  expect((await identity(page, 'northcoast')).user).toBe('alex');
});

test('SAML-001 OneLogin response carries an existing member to requested site', async ({page}) => {
  await page.goto(`${origin('ridgeview')}/wp-login.php`);
  await page.locator('#pv-wpms-sso-saml-button').click();
  await expect(page).toHaveURL(/onelogin\.test/);
  await page.getByRole('button', {name:'Continue from test OneLogin'}).click();
  await expect(page).toHaveURL(`${origin('ridgeview')}/wp-admin/`);
  expect((await identity(page, 'ridgeview')).user).toBe('riley');
});
