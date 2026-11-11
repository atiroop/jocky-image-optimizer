import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const fixture = JSON.parse(process.env.JIO_BROWSER_FIXTURE || '{}');
const baseUrl = process.env.JIO_BROWSER_BASE_URL || '';
assert.ok(baseUrl.startsWith('http://127.0.0.1:'), 'Browser suite must target the disposable local HTTP site.');
assert.equal(fixture.wordpress, '7.1.4', 'Browser matrix must use the pinned WordPress version.');
assert.equal(fixture.elementor, '4.4.0', 'Browser matrix must use the pinned Elementor version.');

const failures = [];
const browser = await chromium.launch({ headless: true });
console.log(`Playwright Chromium ${browser.version()}; WordPress ${fixture.wordpress}; Elementor ${fixture.elementor}`);

async function withPage(viewport, callback) {
  const context = await browser.newContext({ viewport, deviceScaleFactor: 1 });
  const page = await context.newPage();
  const responses = new Map();
  page.on('requestfailed', (request) => {
    if (request.resourceType() === 'image') failures.push(`${request.url()} failed: ${request.failure()?.errorText}`);
  });
  page.on('response', (response) => {
    if (response.request().resourceType() !== 'image') return;
    responses.set(response.url(), {
      status: response.status(),
      mime: (response.headers()['content-type'] || '').split(';')[0].toLowerCase(),
    });
    if (response.status() >= 400) failures.push(`${response.url()} returned ${response.status()}`);
  });
  try {
    await callback(page, responses);
    assert.deepEqual(failures, [], 'No broken image requests are allowed.');
  } finally {
    await context.close();
  }
}

function controlUrl(parameters) {
  const url = new URL('/?jio_browser=control', baseUrl);
  for (const [key, value] of Object.entries(parameters)) url.searchParams.set(key, String(value));
  return url.toString();
}

async function control(parameters) {
  const response = await fetch(controlUrl(parameters), {
    cache: 'no-store',
    headers: { 'X-JIO-Browser-Token': fixture.elementor_token },
  });
  assert.equal(response.status, 200, `Fixture control failed: ${parameters.operation}`);
  return response.json();
}

function elementSelector(asset) {
  return `img.wp-image-${fixture.attachments[asset].id}`;
}

const preservedAttributes = ['src', 'srcset', 'sizes', 'width', 'height', 'alt', 'loading', 'decoding', 'fetchpriority', 'class', 'data-jio-browser-fixture'];
const attributeBaselines = new Map();
const wordpressBaselines = new Map();

async function attributesFromHtml(page, html) {
  return page.evaluate((markup) => {
    const documentFromMarkup = new DOMParser().parseFromString(markup, 'text/html');
    const image = documentFromMarkup.querySelector('img');
    if (!image) return null;
    return Object.fromEntries(Array.from(image.attributes, (attribute) => [attribute.name, attribute.value]));
  }, html);
}

async function recordWordPressBaseline(page, asset) {
  const response = await control({ operation: 'render', asset });
  assert.ok(response.baseline_html.includes('<img'), 'WordPress pre-delivery HTML was captured.');
  assert.ok(response.rendered_html.includes('<img'), 'Rendered image HTML was captured.');
  const baseline = await attributesFromHtml(page, response.baseline_html);
  assert.ok(baseline, 'WordPress baseline contains an image element.');
  const previousBaseline = wordpressBaselines.get(asset);
  if (previousBaseline) {
    for (const name of preservedAttributes) {
      assert.equal(baseline[name] ?? null, previousBaseline[name] ?? null, `WordPress baseline ${name} remains stable across delivery settings (${asset}).`);
    }
  }
  wordpressBaselines.set(asset, baseline);
  console.log(`WordPress pre-JIO HTML (${asset}, delivery ${response.frontend_delivery ? 'ON' : 'OFF'}): ${response.baseline_html}`);
  console.log(`WordPress post-JIO HTML (${asset}, delivery ${response.frontend_delivery ? 'ON' : 'OFF'}): ${response.rendered_html}`);
  const rendered = await attributesFromHtml(page, response.rendered_html);
  if (rendered) {
    for (const name of preservedAttributes) {
      assert.equal(rendered[name] ?? null, baseline[name] ?? null, `JIO output preserves WordPress ${name} (${asset}).`);
    }
  }
  return baseline;
}

async function inspectLoadedImage(page, selector, expectedMime, responses, expectedOriginal = false, expectedAsset = null, baselineKey = null) {
  const image = page.locator(selector).first();
  await image.waitFor({ state: 'attached' });
  await page.waitForFunction((query) => {
    const img = document.querySelector(query);
    return img instanceof HTMLImageElement && img.complete && img.naturalWidth > 0;
  }, selector);
  const result = await image.evaluate((img) => ({
    src: img.getAttribute('src'),
    srcset: img.getAttribute('srcset'),
    sizes: img.getAttribute('sizes'),
    widthAttribute: img.getAttribute('width'),
    heightAttribute: img.getAttribute('height'),
    currentSrc: img.currentSrc,
    alt: img.alt,
    width: img.width,
    height: img.height,
    naturalWidth: img.naturalWidth,
    naturalHeight: img.naturalHeight,
    loading: img.getAttribute('loading'),
    decoding: img.getAttribute('decoding'),
    fetchpriority: img.getAttribute('fetchpriority'),
    fixtureAttribute: img.getAttribute('data-jio-browser-fixture'),
    classAttribute: img.getAttribute('class'),
    attributes: Object.fromEntries(Array.from(img.attributes, (attribute) => [attribute.name, attribute.value])),
    responsiveWebp: img.closest('picture')?.querySelector('source[type="image/webp"]')?.srcset || '',
    responsiveSizes: img.closest('picture')?.querySelector('source[type="image/webp"]')?.sizes || '',
    hasPicture: Boolean(img.closest('picture')),
    pictureCount: document.querySelectorAll('picture').length,
    nestedPictures: document.querySelectorAll('picture picture').length,
    picture: img.closest('picture')?.outerHTML || '',
  }));
  assert.ok(result.naturalWidth > 0 && result.naturalHeight > 0, 'The browser decoded a non-empty image.');
  assert.ok(result.width > 0 && result.height > 0, 'Intrinsic display dimensions are present.');
  assert.ok(result.alt.length > 0, 'Accessible alt text is preserved.');
  const asset = expectedAsset || (selector.includes('jioimg1') ? 'png' : selector.includes('jioibox1') ? 'jpeg' : selector.includes(String(fixture.attachments.jpeg.id)) ? 'jpeg' : 'png');
  const baseline = attributeBaselines.get(baselineKey || asset);
  if (baseline) {
    for (const name of preservedAttributes) {
      assert.equal(result.attributes?.[name] ?? null, baseline[name] ?? null, `WordPress ${name} behavior is preserved by JIO.`);
    }
  }
  assert.equal(result.nestedPictures, 0, 'Delivery must never nest picture wrappers.');
  assert.equal(Number(result.widthAttribute), Number(fixture.attachments[asset].metadata.width), 'Original width attribute is preserved.');
  assert.equal(Number(result.heightAttribute), Number(fixture.attachments[asset].metadata.height), 'Original height attribute is preserved.');
  if (expectedOriginal) {
    assert.equal(result.src, new URL(fixture.attachments[asset].url).href, 'Fallback src remains the original attachment URL.');
    assert.ok(!result.currentSrc.includes('.jio.'), 'Browser selected a WordPress original responsive candidate.');
    assert.equal(result.hasPicture, false, 'Uncertain or unavailable derivatives leave the original image markup unchanged.');
    assert.equal(responses.get(result.currentSrc)?.mime, asset === 'jpeg' ? 'image/jpeg' : 'image/png');
  } else {
    assert.equal(result.hasPicture, true, `An eligible derivative is emitted in one picture wrapper (${selector}); rendered image: ${result.picture || result.src}; currentSrc: ${result.currentSrc}`);
    const requested = responses.get(result.currentSrc);
    assert.ok(requested, 'The selected derivative must have a real browser network response.');
    assert.equal(requested.status, 200);
    assert.equal(requested.mime, expectedMime, 'The selected resource MIME must match the browser-selected source format.');
    assert.ok(result.currentSrc.includes('.jio.'), 'The loaded image must be a registered derivative URL.');
    assert.ok(result.picture.includes(`type="${expectedMime}"`), 'The picture contains the selected format source.');
  }
  console.log(`Loaded ${expectedOriginal ? 'original' : expectedMime}: ${result.currentSrc} (${result.naturalWidth}x${result.naturalHeight})`);
  return result;
}

async function openGutenberg(page) {
  const response = await page.goto(fixture.gutenberg_url, { waitUntil: 'networkidle' });
  assert.equal(response.status(), 200);
  const rendered = await page.locator('img').evaluateAll((images) => images.map((img) => img.className));
  console.log(`Gutenberg page ${response.url()} returned ${rendered.length} image tags: ${JSON.stringify(rendered)}`);
  for (const asset of ['png', 'jpeg']) {
    assert.ok(rendered.some((className) => className.split(/\s+/).includes(`wp-image-${fixture.attachments[asset].id}`)), `Gutenberg rendered the ${asset} attachment image.`);
  }
}

async function openElementor(page, responses) {
  const url = new URL('/?jio_browser=elementor', baseUrl);
  await page.setExtraHTTPHeaders({ 'X-JIO-Browser-Token': fixture.elementor_token });
  const response = await page.goto(url.toString(), { waitUntil: 'networkidle' });
  assert.equal(response.status(), 200, 'The actual Elementor frontend rendering API must return successfully.');
  assert.equal(response.headers()['x-jio-elementor-version'], fixture.elementor);
  const lookupCount = Number(response.headers()['x-jio-registry-lookups']);
  assert.ok(Number.isFinite(lookupCount) && lookupCount <= 8, `Frontend derivative registry queries are bounded; saw ${lookupCount}.`);
  assert.ok(!response.request().url().includes('wp-admin'), 'Elementor rendering occurs on a frontend request.');
  const traceHeader = response.headers()['x-jio-elementor-trace'] || '';
  assert.ok(traceHeader, 'Elementor runtime instrumentation returned its widget path trace.');
  const trace = JSON.parse(Buffer.from(traceHeader, 'base64').toString('utf8'));
  const elementCache = response.headers()['x-jio-elementor-element-cache'];
  const documentCache = response.headers()['x-jio-elementor-document-cache'];
  assert.ok(['enabled', 'disabled'].includes(elementCache), 'Elementor Element Cache state is reported.');
  assert.ok(['warm', 'cold'].includes(documentCache), 'Elementor document cache state is reported.');
  console.log(`Elementor cache state: element=${elementCache}, document=${documentCache}, hook calls=${trace.length}`);
  return { responses, trace, elementCache, documentCache };
}

try {
  assert.equal(fixture.attachments.png.sha256, fixture.original_hashes.png);
  assert.equal(fixture.attachments.jpeg.sha256, fixture.original_hashes.jpeg);

  // Delivery OFF must leave ordinary Gutenberg content on the original files.
  let state = await control({ operation: 'settings', enabled: 0, preference: 'auto' });
  assert.equal(state.frontend_delivery, false);
  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    await recordWordPressBaseline(page, 'png');
    await recordWordPressBaseline(page, 'jpeg');
    await openGutenberg(page);
    const png = await inspectLoadedImage(page, elementSelector('png'), 'image/png', responses, true, 'png');
    const jpeg = await inspectLoadedImage(page, elementSelector('jpeg'), 'image/jpeg', responses, true, 'jpeg');
    attributeBaselines.set('png', png.attributes);
    attributeBaselines.set('jpeg', jpeg.attributes);
    assert.equal(await page.locator('picture').count(), 0);
  });

  // Auto mode: Chromium must actually request/decode AVIF for Gutenberg and Elementor.
  state = await control({ operation: 'settings', enabled: 1, preference: 'auto' });
  assert.equal(state.cache_notice, 'manual', 'A real setting update records the external-cache purge notice.');
  assert.equal(state.wp_rocket_available, false, 'WP Rocket is intentionally absent from this test installation.');
  await withPage({ width: 1280, height: 900 }, async (page) => {
    await recordWordPressBaseline(page, 'png');
    await recordWordPressBaseline(page, 'jpeg');
  });
  await control({ operation: 'settings', enabled: 0, preference: 'auto' });
  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    const elementor = await openElementor(page, responses);
    assert.equal(elementor.elementCache, 'disabled');
    assert.equal(elementor.documentCache, 'cold');
    assert.ok(elementor.trace.some((entry) => entry.name === 'image'));
    assert.ok(elementor.trace.some((entry) => entry.name === 'image-box'));
    const png = await inspectLoadedImage(page, '[data-id="jioimg1"] img', 'image/png', responses, true, 'png', 'elementor-png');
    const jpeg = await inspectLoadedImage(page, '[data-id="jioibox1"] img', 'image/jpeg', responses, true, 'jpeg', 'elementor-jpeg');
    attributeBaselines.set('elementor-png', png.attributes);
    attributeBaselines.set('elementor-jpeg', jpeg.attributes);
  });
  state = await control({ operation: 'settings', enabled: 1, preference: 'auto' });
  assert.equal(state.frontend_delivery, true);
  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    await openGutenberg(page);
    const png = await inspectLoadedImage(page, elementSelector('png'), 'image/avif', responses);
    assert.ok(png.picture.indexOf('type="image/avif"') < png.picture.indexOf('type="image/webp"'), 'Auto mode orders AVIF before WebP.');
    await inspectLoadedImage(page, elementSelector('jpeg'), 'image/avif', responses);
    assert.equal(await page.locator(`figure:has(${elementSelector('png')}) a`).getAttribute('href'), `${baseUrl}/jio-png-linked-target/`);
    assert.match(await page.locator(`figure:has(${elementSelector('png')}) figcaption`).innerText(), /JIO browser caption/);
  });

  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    const { trace } = await openElementor(page, responses);
    console.log(`Elementor widget rendering trace: ${JSON.stringify(trace)}`);
    for (const [name, asset] of [['image', 'png'], ['image-box', 'jpeg']]) {
      const widgetTrace = trace.find((entry) => entry.name === name && Number(entry.attachment_id) === Number(fixture.attachments[asset].id));
      assert.ok(widgetTrace, `Elementor ${name} rendering trace resolves the actual ${asset} attachment ID.`);
      assert.equal(widgetTrace.img_src_before_adapter, new URL(fixture.attachments[asset].url).href, `Elementor ${name} output uses the selected WordPress attachment source.`);
      assert.equal(widgetTrace.picture_after_adapter, true, `The public widget content adapter adds a picture wrapper for ${name}.`);
    }
    const imageWidget = await inspectLoadedImage(page, '[data-id="jioimg1"] img', 'image/avif', responses, false, 'png', 'elementor-png');
    const imageBox = await inspectLoadedImage(page, '[data-id="jioibox1"] img', 'image/avif', responses, false, 'jpeg', 'elementor-jpeg');
    assert.ok(imageWidget.currentSrc.endsWith('.jio.avif'));
    assert.ok(imageBox.currentSrc.endsWith('.jio.avif'));
    assert.equal(await page.locator('[data-id="jioimg1"] a').getAttribute('href'), `${baseUrl}/elementor-image-target/`);
    assert.equal(await page.locator('[data-id="jioibox1"] .elementor-image-box-img a').getAttribute('href'), `${baseUrl}/elementor-box-target/`);
    assert.match(await page.locator('[data-id="jioimg1"]').innerText(), /JIO Elementor image caption/);
    assert.match(await page.locator('[data-id="jioibox1"]').innerText(), /JIO Elementor Image Box/);
    const background = await page.locator('[data-id="jiobg01"]').evaluate((element) => ({
      image: getComputedStyle(element).backgroundImage,
      pictures: element.querySelectorAll('picture').length,
      img: element.querySelectorAll('img').length,
    }));
    assert.ok(background.image.includes(new URL(fixture.attachments.jpeg.url).pathname), 'Elementor background keeps its original URL.');
    assert.equal(background.pictures, 0, 'Unsupported background images remain untouched.');
    assert.equal(background.img, 0, 'Unsupported background images remain CSS backgrounds.');
    console.log('Elementor Image and Image Box rendered through Elementor 4.4.0; CSS background retained its original source.');
  });
  state = await control({ operation: 'settings', enabled: 0, preference: 'auto' });
  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    const elementor = await openElementor(page, responses);
    assert.equal(elementor.elementCache, 'disabled');
    assert.ok(elementor.trace.some((entry) => entry.name === 'image'));
    assert.ok(elementor.trace.some((entry) => entry.name === 'image-box'));
    await inspectLoadedImage(page, '[data-id="jioimg1"] img', 'image/png', responses, true, 'png', 'elementor-png');
    await inspectLoadedImage(page, '[data-id="jioibox1"] img', 'image/jpeg', responses, true, 'jpeg', 'elementor-jpeg');
    assert.equal(await page.locator('picture').count(), 0, 'Delivery OFF removes Elementor picture wrappers when Element Caching is disabled.');
  });
  await control({ operation: 'settings', enabled: 1, preference: 'auto' });

  // Prefer-WebP and WebP-only source sets must result in actual WebP transfers.
  await control({ operation: 'settings', enabled: 1, preference: 'webp_only' });
  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    await openGutenberg(page);
    await inspectLoadedImage(page, elementSelector('png'), 'image/webp', responses);
    await inspectLoadedImage(page, elementSelector('jpeg'), 'image/webp', responses);
    assert.equal(await page.locator('source[type="image/avif"]').count(), 0);
  });
  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    await openElementor(page, responses);
    await inspectLoadedImage(page, '[data-id="jioimg1"] img', 'image/webp', responses, false, 'png', 'elementor-png');
    await inspectLoadedImage(page, '[data-id="jioibox1"] img', 'image/webp', responses, false, 'jpeg', 'elementor-jpeg');
    assert.equal(await page.locator('source[type="image/avif"]').count(), 0);
  });
  await control({ operation: 'settings', enabled: 1, preference: 'auto' });

  // A stale current-file identity is rejected and the original PNG is loaded.
  state = await control({ operation: 'stale', asset: 'png' });
  assert.ok(state.derivative_identities.every((identity) => identity === 'stale-browser-file-identity'));
  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    await openGutenberg(page);
    await inspectLoadedImage(page, elementSelector('png'), 'image/png', responses, true, 'png');
  });
  state = await control({ operation: 'reoptimize', asset: 'png' });
  assert.ok(state.derivative_identities.length > 0 && state.derivative_identities.every((identity) => identity.length > 0), 'Reoptimization restores a current identity after stale fallback.');
  assert.equal(state.original_sha256, fixture.original_hashes.png, 'PNG source bytes survive stale-row reoptimization.');
  assert.deepEqual(state.attachment_metadata, fixture.original_metadata.png, 'PNG attachment metadata survives stale-row reoptimization.');

  // Request a narrow viewport and ensure Chromium selects an appropriate responsive derivative.
  await withPage({ width: 360, height: 800 }, async (page, responses) => {
    await openGutenberg(page);
    const result = await inspectLoadedImage(page, elementSelector('png'), 'image/webp', responses);
    assert.ok(result.srcset, 'WordPress responsive fallback srcset is preserved.');
    assert.ok(result.sizes, 'WordPress sizes is preserved.');
    const selected = result.responsiveWebp.split(',').map((candidate) => candidate.trim()).find((candidate) => {
      const [url] = candidate.split(/\s+/, 1);
      return new URL(url, fixture.gutenberg_url).href === result.currentSrc;
    });
    assert.ok(selected, 'The browser-selected resource belongs to the WebP responsive source set.');
    assert.ok(result.responsiveSizes, 'Responsive format sources preserve sizes.');
    const width = Number(selected.match(/\s(\d+)w$/)?.[1] || 0);
    assert.ok(width > 0 && width < 1200, `Narrow viewport should select a smaller candidate; selected ${width}w.`);
    console.log(`Responsive Chromium candidate at 360px viewport: ${width}w.`);
  });

  // Simulate a genuine legacy registry record, verify fallback, then regenerate through the queue worker.
  state = await control({ operation: 'legacy', asset: 'jpeg' });
  assert.ok(state.derivative_identities.every((identity) => identity === ''), 'Legacy test rows have no current file identity.');
  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    await openElementor(page, responses);
    await inspectLoadedImage(page, '[data-id="jioibox1"] img', 'image/jpeg', responses, true, 'jpeg');
    assert.equal(await page.locator('[data-id="jioibox1"] picture').count(), 0);
  });
  state = await control({ operation: 'reoptimize', asset: 'jpeg' });
  assert.ok(state.derivative_identities.length > 0 && state.derivative_identities.every((identity) => identity.length > 0), 'Reoptimization writes current derivative identity metadata.');
  assert.equal(state.original_sha256, fixture.original_hashes.jpeg, 'JPEG source bytes survive legacy-row reoptimization.');
  assert.deepEqual(state.attachment_metadata, fixture.original_metadata.jpeg, 'JPEG attachment metadata survives legacy-row reoptimization.');
  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    await openElementor(page, responses);
    await inspectLoadedImage(page, '[data-id="jioibox1"] img', 'image/avif', responses, false, 'jpeg', 'elementor-jpeg');
  });

  // Remove only synthetic JPEG derivative files and rows; the original remains available.
  await control({ operation: 'missing', asset: 'jpeg' });
  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    await openElementor(page, responses);
    await inspectLoadedImage(page, '[data-id="jioibox1"] img', 'image/jpeg', responses, true, 'jpeg');
  });

  // Rollback is an uncached HTTP request with the real setting OFF.
  state = await control({ operation: 'settings', enabled: 0, preference: 'auto' });
  assert.equal(state.frontend_delivery, false);
  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    await openGutenberg(page);
    await inspectLoadedImage(page, elementSelector('png'), 'image/png', responses, true, 'png');
    assert.equal(await page.locator('picture').count(), 0, 'Disabling delivery restores original-only uncached HTML.');
  });

  for (const asset of ['png', 'jpeg']) {
    state = await control({ operation: 'settings', enabled: 0, preference: 'auto', asset });
    assert.equal(state.original_sha256, fixture.original_hashes[asset], `Original ${asset} bytes remained unchanged.`);
    assert.deepEqual(state.attachment_metadata, fixture.original_metadata[asset], `WordPress ${asset} attachment metadata remained unchanged.`);
  }

  // Elementor Element Caching is an explicitly unsupported operating mode for
  // JIO widget delivery. Verify cold rendering fails closed and the warmed OFF
  // document cache stays original across OFF->ON->OFF without pretending the hook ran.
  state = await control({ operation: 'elementor_cache', enabled: 1 });
  assert.equal(state.elementor_cache_enabled, true);
  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    const elementor = await openElementor(page, responses);
    assert.equal(elementor.elementCache, 'enabled');
    assert.equal(elementor.documentCache, 'warm');
    assert.ok(elementor.trace.some((entry) => entry.name === 'image'));
    assert.ok(elementor.trace.every((entry) => !entry.picture_after_adapter), 'With Element Caching active, fresh Elementor widgets fail closed to the original markup.');
    await inspectLoadedImage(page, '[data-id="jioimg1"] img', 'image/png', responses, true, 'png', 'elementor-png');
  });
  state = await control({ operation: 'settings', enabled: 1, preference: 'auto' });
  assert.equal(state.elementor_cache_warning, true, 'The administrator compatibility warning is retained while cached Elementor delivery is enabled.');
  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    const elementor = await openElementor(page, responses);
    assert.equal(elementor.elementCache, 'enabled');
    assert.equal(elementor.documentCache, 'warm');
    assert.deepEqual(elementor.trace, [], 'Warm Elementor output bypasses widget rendering; the original OFF cache remains safe.');
    await inspectLoadedImage(page, '[data-id="jioimg1"] img', 'image/png', responses, true, 'png', 'elementor-png');
  });
  state = await control({ operation: 'settings', enabled: 0, preference: 'auto' });
  assert.equal(state.elementor_cache_warning, true, 'The cache warning persists after Delivery is turned OFF until Elementor cache is disabled.');
  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    const elementor = await openElementor(page, responses);
    assert.equal(elementor.elementCache, 'enabled');
    assert.equal(elementor.documentCache, 'warm');
    assert.deepEqual(elementor.trace, [], 'Warm OFF cache remains original and does not claim delivery-hook execution.');
    await inspectLoadedImage(page, '[data-id="jioimg1"] img', 'image/png', responses, true, 'png', 'elementor-png');
  });

  // Elementor's supported Element Cache setting change clears its own document
  // cache. With JIO ON and a cold cache, the adapter must still fail closed.
  await control({ operation: 'elementor_cache', enabled: 0 });
  await control({ operation: 'elementor_cache', enabled: 1 });
  await control({ operation: 'settings', enabled: 1, preference: 'auto' });
  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    const elementor = await openElementor(page, responses);
    assert.equal(elementor.elementCache, 'enabled');
    assert.equal(elementor.documentCache, 'warm');
    assert.ok(elementor.trace.some((entry) => entry.name === 'image'));
    assert.ok(elementor.trace.every((entry) => !entry.picture_after_adapter), 'A cold render with Element Caching ON fails closed despite Delivery ON.');
    await inspectLoadedImage(page, '[data-id="jioimg1"] img', 'image/png', responses, true, 'png', 'elementor-png');
  });
  await control({ operation: 'settings', enabled: 0, preference: 'auto' });
  await withPage({ width: 1280, height: 900 }, async (page, responses) => {
    const elementor = await openElementor(page, responses);
    assert.equal(elementor.elementCache, 'enabled');
    assert.deepEqual(elementor.trace, [], 'The cold fail-closed original is reused from Elementor cache.');
    await inspectLoadedImage(page, '[data-id="jioimg1"] img', 'image/png', responses, true, 'png', 'elementor-png');
  });
  await control({ operation: 'elementor_cache', enabled: 0 });
  console.log('PASS: Gutenberg and Elementor AVIF/WebP delivery with Element Caching OFF; Element Caching ON was verified to fail closed to original images.');
} finally {
  await browser.close();
}
