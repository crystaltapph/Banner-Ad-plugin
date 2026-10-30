/* Run after tests/browser.cjs against the disposable Compose site. */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const fixture = JSON.parse(fs.readFileSync(process.env.BAP_FIXTURE || '/tmp/banner-fixture.json', 'utf8'));
const base = process.env.BAP_TEST_URL || 'http://127.0.0.1:8080';
const out = process.env.BAP_TEST_OUTPUT || '/tmp/banner-studio-results';
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; console.log(`PASS ${message}`); };
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
  const errors = [];
  try {
    const admin = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await admin.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${base}/wp-login.php`);
    await page.locator('#user_login').fill('banner-tester');
    await page.locator('#user_pass').fill('local-ui-test-only');
    await Promise.all([page.waitForURL('**/wp-admin/**'), page.locator('#wp-submit').click()]);
    await page.goto(`${base}/wp-admin/admin.php?page=bap-banners`);
    await page.getByRole('link', { name: 'Browser test in-content', exact: true }).click();
    while (await page.locator('[data-creative-index]:not([hidden])').count() > 1) {
      await page.locator('[data-creative-index]:not([hidden])').last().getByRole('button', { name: 'Remove creative', exact: true }).click();
    }
    const choose = async (row, index, title) => {
      await row.getByRole('button', { name: 'Choose image', exact: true }).nth(index).click();
      const library = page.getByRole('tab', { name: 'Media Library' });
      if (await library.isVisible()) await library.click();
      await page.locator(`.media-modal:visible .attachment[aria-label="${title}"]`).click();
      await page.getByRole('button', { name: 'Use this image', exact: true }).click();
    };
    const add = page.getByRole('button', { name: '+ Add creative', exact: true });
    for (let i = 1; i < 4; i++) {
      await add.click();
      const row = page.locator(`[data-creative-index="${i}"]`);
      await choose(row, 0, `Test desktop ${i + 1} artwork`);
      await choose(row, 1, `Test mobile ${i + 1} artwork`);
      await row.locator('[data-creative-field="alt"]').fill(`Offer ${i + 1}`);
      await row.locator('[data-creative-field="url"]').fill(`${base}/offer-${i + 1}`);
    }
    await page.locator('#bap-url').fill(`${base}/offer-1`);
    check(await add.isDisabled(), 'Editor prevents adding a fifth creative');
    await page.getByLabel('Randomly select a creative on each post/page visit').check();
    await page.locator('[name="banner[post_types][]"][value="page"]').check();
    await page.getByRole('button', { name: 'Mobile', exact: true }).click();
    await page.locator('#bap-preview-creative').selectOption('3');
    check((await page.locator('#bap-preview-image').getAttribute('src')).includes('banner-test-mobile4'), 'Preview can select the fourth mobile creative');
    await page.screenshot({ path: `${out}/four-creatives-editor.png`, fullPage: true });
    await Promise.all([page.waitForURL('**bap_notice=saved'), page.getByRole('button', { name: 'Save banner', exact: true }).first().click()]);
    check(await page.getByText('4 creative(s) · Random per visit', { exact: true }).isVisible(), 'Library shows four creatives and random mode');
    await page.getByRole('link', { name: 'Browser test in-content', exact: true }).click();
    check(await page.locator('[data-creative-index]:not([hidden])').count() === 4, 'All four creatives persist after saving');
    check(await page.locator('#bap-c3-url').inputValue() === `${base}/offer-4`, 'Fourth creative link persists independently');
    const last = page.locator('[data-creative-index="3"]');
    await last.getByRole('button', { name: 'Remove creative', exact: true }).click();
    check(await add.isEnabled() && await last.isHidden(), 'Removing a creative frees an editor slot');
    await add.click();
    check(await last.locator('[data-creative-field="url"]').inputValue() === '' && await last.locator('[data-creative-field="desktop_image"]').inputValue() === '0', 'Re-added slot does not retain deleted links or artwork');
    // Restore unsaved edits before testing the saved public page.
    await page.reload();
    const url = `${base}/?page_id=${fixture.page}`;
    const response = await page.request.get(url);
    check(response.ok(), 'Page containing the random campaign responds successfully');
    const cachedHTML = await response.text();
    check((cachedHTML.match(/data-bap-creative=/g) || []).length === 4, 'Cacheable HTML contains the complete four-creative pool');
    // Replay byte-identical HTML for every choice, simulating a full-page cache.
    for (let index = 0; index < 4; index++) {
      const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
      await context.addInitScript(value => { Math.random = () => value; }, (index + 0.1) / 4);
      await context.route(url, route => route.fulfill({ status: 200, contentType: 'text/html; charset=UTF-8', body: cachedHTML }));
      const visit = await context.newPage();
      visit.on('pageerror', error => errors.push(error.message));
      const imageRequests = [];
      visit.on('request', request => { if (/banner-test-(desktop|mobile)\d*\.png/.test(request.url())) imageRequests.push(request.url()); });
      await visit.goto(url);
      const banner = visit.locator('.bap-banner').first();
      await banner.scrollIntoViewIfNeeded();
      await visit.waitForFunction(() => document.querySelector('.bap-random-choice img')?.naturalWidth > 0);
      const suffix = index ? String(index + 1) : '';
      check(await banner.getAttribute('data-bap-selected') === String(index), `Cached visit can select creative ${index + 1}`);
      check(await banner.locator('.bap-random-choice a').getAttribute('href') === `${base}/offer-${index + 1}`, `Creative ${index + 1} uses its own destination link`);
      check(await banner.locator('.bap-random-choice img').evaluate((img, file) => img.currentSrc.endsWith(file), `banner-test-desktop${suffix}.png`), `Creative ${index + 1} loads its desktop artwork`);
      check(imageRequests.length > 0 && imageRequests.every(request => request.endsWith(`banner-test-desktop${suffix}.png`)), `Unselected creatives do not download on desktop visit ${index + 1}`);
      await visit.setViewportSize({ width: 390, height: 844 });
      await visit.waitForFunction(file => { const img = document.querySelector('.bap-random-choice img'); return img?.complete && img.naturalWidth > 0 && img.currentSrc.endsWith(file); }, `banner-test-mobile${suffix}.png`);
      check(await banner.getAttribute('data-bap-selected') === String(index), `Resizing preserves creative ${index + 1} and its matching link`);
      check(await visit.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `Random creative ${index + 1} fits the mobile viewport`);
      check(imageRequests.every(request => request.endsWith(`banner-test-desktop${suffix}.png`) || request.endsWith(`banner-test-mobile${suffix}.png`)), `Only the selected creative's artwork downloads after resizing visit ${index + 1}`);
      if (index === 3) await visit.screenshot({ path: `${out}/random-mobile.png` });
      await context.close();
    }
    const noJS = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 844 } });
    const fallback = await noJS.newPage();
    await fallback.goto(url);
    await fallback.locator('.bap-random-choice').scrollIntoViewIfNeeded();
    check(await fallback.locator('.bap-random-choice a').getAttribute('href') === `${base}/offer-1` && await fallback.locator('.bap-random-choice img').isVisible(), 'Without JavaScript the first creative remains visible and linked');
    // Turning off rotation is a deterministic first-creative mode.
    await page.getByLabel('Randomly select a creative on each post/page visit').uncheck();
    await Promise.all([page.waitForURL('**bap_notice=saved'), page.getByRole('button', { name: 'Save banner', exact: true }).first().click()]);
    const fixed = await page.request.get(url);
    const fixedHTML = await fixed.text();
    check(!fixedHTML.includes('data-bap-creative=') && fixedHTML.includes('/offer-1') && !fixedHTML.includes('/offer-4'), 'Disabling random mode serves only the first creative');
    await page.getByRole('link', { name: 'Browser test in-content', exact: true }).click();
    await page.getByLabel('Randomly select a creative on each post/page visit').check();
    await page.getByLabel('Placement', { exact: true }).selectOption('sticky');
    await Promise.all([page.waitForURL('**bap_notice=saved'), page.getByRole('button', { name: 'Save banner', exact: true }).first().click()]);
    const stickyContext = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true });
    await stickyContext.addInitScript(() => { Math.random = () => 0.99; });
    const stickyPage = await stickyContext.newPage();
    stickyPage.on('pageerror', error => errors.push(error.message));
    await stickyPage.goto(url);
    await stickyPage.evaluate(() => window.scrollTo(0, 600));
    await stickyPage.waitForFunction(() => { const banner = document.querySelector('.bap-sticky'); return banner && !banner.hidden && banner.querySelector('img')?.naturalWidth > 0; });
    check(await stickyPage.locator('.bap-sticky .bap-random-choice a').getAttribute('href') === `${base}/offer-4`, 'Mobile sticky placement supports the fourth randomly selected link');
    check(await stickyPage.locator('.bap-sticky img').evaluate(img => img.getBoundingClientRect().height <= 100), 'Random mobile sticky artwork respects the height limit');
    check(await stickyPage.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'Random sticky creative fits mobile without horizontal overflow');
    await stickyPage.getByRole('button', { name: 'Dismiss advertisement' }).click();
    check(await stickyPage.locator('.bap-sticky').isHidden(), 'Random sticky campaign remains dismissible');
    check(errors.length === 0, `No enhancement JavaScript errors: ${errors.join('; ')}`);
    console.log(`\n${checks} enhancement browser checks passed.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
