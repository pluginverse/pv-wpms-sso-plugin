// Writes .wordpress-org/*.png: artwork from the SVG sources in assets/listing/
// and screenshots of the real plugin in the disposable browser environment.
const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../../..');
const output = name => path.join(root, '.wordpress-org', name);
const origin = name => `https://${name}.test:9443`;

async function renderSvg(browser, source, width, height, name) {
  const page = await browser.newPage({viewport: {width, height}});
  const svg = fs.readFileSync(path.join(root, 'assets/listing', source), 'utf8');
  await page.setContent(`<style>html,body{margin:0}svg{display:block;width:${width}px;height:${height}px}</style>${svg}`);
  await page.screenshot({path: output(name), clip: {x: 0, y: 0, width, height}});
  await page.close();
}

test('artwork from vector sources', async ({browser}) => {
  await renderSvg(browser, 'icon.svg', 256, 256, 'icon-256x256.png');
  await renderSvg(browser, 'icon.svg', 128, 128, 'icon-128x128.png');
  await renderSvg(browser, 'banner.svg', 1544, 500, 'banner-1544x500.png');
  await renderSvg(browser, 'banner.svg', 772, 250, 'banner-772x250.png');
});

test('screenshot 1: one click after signing out', async ({page}) => {
  await page.setViewportSize({width: 1280, height: 860});
  await page.goto(`${origin('northcoast')}/wp-login.php`);
  await page.getByLabel('Username or Email Address').fill('alex');
  await page.getByLabel('Password', {exact: true}).fill('browser-test-password');
  await page.getByLabel('Second factor').fill('654321');
  await page.getByRole('button', {name: 'Log In', exact: true}).click();
  await expect(page).toHaveURL(`${origin('northcoast')}/wp-admin/`);
  await page.goto(`${origin('southmountain')}/wp-login.php`);
  await expect(page).toHaveURL(`${origin('southmountain')}/wp-admin/`);
  await page.goto(`${origin('southmountain')}/wp-login.php?action=logout`);
  await page.getByRole('link', {name: 'log out'}).click();
  await page.goto(`${origin('southmountain')}/wp-login.php?loggedout=true`);
  await expect(page.locator('#pv-wpms-sso-button')).toContainText('Continue as');
  // The second-factor field belongs to the test fixture, not to the plugin.
  await page.locator('#browser-factor').evaluate(field => field.closest('p').remove());
  await page.locator('.pv-wpms-sso-avatar').evaluate(image => image.complete || new Promise(done => { image.onload = image.onerror = done; }));
  await page.screenshot({path: output('screenshot-1.png')});
});

test('screenshots 2 and 3: network settings', async ({page}) => {
  await page.setViewportSize({width: 1280, height: 860});
  // The fixture's root site is plain HTTP while its identity site is HTTPS, so
  // browser redirects between them cannot complete. Sign in without following them.
  await page.request.post('http://localhost:8894/wp-login.php', {
    form: {log: 'admin', pwd: 'password', 'wp-submit': 'Log In', testcookie: '1'},
    headers: {Cookie: 'wordpress_test_cookie=WP%20Cookie%20check'},
    maxRedirects: 0,
  });
  await page.goto('http://localhost:8894/wp-admin/network/settings.php?page=pv-wpms-sso');
  await expect(page.getByRole('heading', {name: 'Network Single Sign-On'})).toBeVisible();
  await page.screenshot({path: output('screenshot-2.png')});
  const saml = page.getByRole('heading', {name: /External single sign-on/});
  await saml.scrollIntoViewIfNeeded();
  await saml.evaluate(heading => window.scrollTo(0, heading.getBoundingClientRect().top + window.scrollY - 60));
  await page.screenshot({path: output('screenshot-3.png')});
});
