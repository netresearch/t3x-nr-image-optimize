import type { APIRequestContext, Browser } from '@playwright/test';

/**
 * Measurement helpers for the performance benchmark.
 *
 * Every visit runs in a fresh browser context (empty HTTP cache) and reads
 * two things: what the browser saw (navigation and resource timing, status
 * codes, which responses carried PHP's X-Powered-By header) and what the
 * server did (CPU time of the PHP-FPM container and the number of variant
 * files written, both via the control endpoint seeded into the instance).
 */

export interface VariantStat {
  files: number;
  bytes: number;
}

export interface ServerStat {
  cpuUsec: number | null;
  variants: { core: VariantStat; ext: VariantStat };
  environment: {
    typo3: string;
    php: string;
    server: string;
    imagick: string;
    processor: string;
  };
}

export interface VisitMetrics {
  /** Server response time of the HTML document (responseStart - requestStart). */
  ttfbMs: number;
  domContentLoadedMs: number;
  /** Until the window load event, i.e. every eager image decoded. */
  loadMs: number;
  /** Largest Contentful Paint, when the browser reported one. */
  lcpMs: number | null;
  /** Wall clock from navigation start until the network went idle. */
  wallMs: number;
  imageRequests: number;
  imageBytes: number;
  /** Image responses with a 4xx/5xx status. */
  brokenImages: number;
  /** Responses that went through PHP-FPM (document, control calls excluded). */
  phpRequests: number;
  /** CPU time the PHP-FPM container consumed during the visit. */
  serverCpuMs: number | null;
  variantsCreated: number;
  variantBytes: number;
}

export interface VisitOptions {
  /** Abort every image request, so only the page render itself is measured. */
  blockImages?: boolean;
}

const IMAGE_URL = /\.(?:jpe?g|png|gif|webp|avif|svg)(?:\?.*)?$/i;

export async function stat(request: APIRequestContext, baseURL: string): Promise<ServerStat> {
  const response = await request.get(`${baseURL}/_bench/control.php?action=stat`);
  if (!response.ok()) {
    throw new Error(`control endpoint answered ${response.status()}: ${await response.text()}`);
  }
  return (await response.json()) as ServerStat;
}

export async function reset(
  request: APIRequestContext,
  baseURL: string,
  what: { caches?: boolean; variants?: boolean },
): Promise<void> {
  if (!what.caches && !what.variants) {
    return;
  }
  const query = `action=reset&caches=${what.caches ? 1 : 0}&variants=${what.variants ? 1 : 0}`;
  const response = await request.get(`${baseURL}/_bench/control.php?${query}`);
  if (!response.ok()) {
    throw new Error(`reset failed with ${response.status()}: ${await response.text()}`);
  }
}

export async function visit(
  browser: Browser,
  request: APIRequestContext,
  baseURL: string,
  path: string,
  options: VisitOptions = {},
): Promise<VisitMetrics> {
  const context = await browser.newContext();
  const page = await context.newPage();

  if (options.blockImages) {
    await context.route(IMAGE_URL, (route) => route.abort());
  }

  await page.addInitScript(() => {
    (window as unknown as { __lcp: number | null }).__lcp = null;
    new PerformanceObserver((list) => {
      const last = list.getEntries().at(-1);
      if (last) {
        (window as unknown as { __lcp: number | null }).__lcp = last.startTime;
      }
    }).observe({ type: 'largest-contentful-paint', buffered: true });
  });

  let imageRequests = 0;
  let brokenImages = 0;
  let phpRequests = 0;

  page.on('response', (response) => {
    const url = response.url();
    if (url.includes('/_bench/')) {
      return;
    }
    if (response.request().resourceType() === 'image' || IMAGE_URL.test(url)) {
      imageRequests += 1;
      if (response.status() >= 400) {
        brokenImages += 1;
      }
    }
    if (response.headers()['x-powered-by'] !== undefined) {
      phpRequests += 1;
    }
  });

  const before = await stat(request, baseURL);
  const started = Date.now();

  await page.goto(`${baseURL}${path}`, { waitUntil: 'load' });
  await page.waitForLoadState('networkidle');

  const wallMs = Date.now() - started;

  const timing = await page.evaluate(() => {
    const navigation = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming;
    const images = performance
      .getEntriesByType('resource')
      .filter((entry) => (entry as PerformanceResourceTiming).initiatorType === 'img');
    return {
      ttfbMs: navigation.responseStart - navigation.requestStart,
      domContentLoadedMs: navigation.domContentLoadedEventEnd - navigation.startTime,
      loadMs: navigation.loadEventEnd - navigation.startTime,
      lcpMs: (window as unknown as { __lcp: number | null }).__lcp,
      imageBytes: images.reduce((sum, entry) => sum + (entry as PerformanceResourceTiming).transferSize, 0),
    };
  });

  const after = await stat(request, baseURL);
  await context.close();

  const variantFiles = (s: ServerStat) => s.variants.core.files + s.variants.ext.files;
  const variantBytes = (s: ServerStat) => s.variants.core.bytes + s.variants.ext.bytes;

  return {
    ttfbMs: round(timing.ttfbMs),
    domContentLoadedMs: round(timing.domContentLoadedMs),
    loadMs: round(timing.loadMs),
    lcpMs: timing.lcpMs === null ? null : round(timing.lcpMs),
    wallMs,
    imageRequests,
    imageBytes: timing.imageBytes,
    brokenImages,
    phpRequests,
    serverCpuMs:
      before.cpuUsec === null || after.cpuUsec === null ? null : round((after.cpuUsec - before.cpuUsec) / 1000),
    variantsCreated: variantFiles(after) - variantFiles(before),
    variantBytes: variantBytes(after) - variantBytes(before),
  };
}

export function median(values: number[]): number {
  const sorted = [...values].sort((a, b) => a - b);
  const middle = Math.floor(sorted.length / 2);
  return sorted.length % 2 === 0 ? round((sorted[middle - 1] + sorted[middle]) / 2) : sorted[middle];
}

/** Median of every numeric metric across iterations; null when no iteration had a value. */
export function aggregate(runs: VisitMetrics[]): VisitMetrics {
  if (runs.length === 0) {
    throw new Error('aggregate() received no runs — check BENCHMARK_ITERATIONS');
  }
  const keys = Object.keys(runs[0]) as (keyof VisitMetrics)[];
  const result = {} as Record<keyof VisitMetrics, number | null>;
  for (const key of keys) {
    const values = runs.map((run) => run[key]).filter((value): value is number => value !== null);
    result[key] = values.length === 0 ? null : median(values);
  }
  return result as unknown as VisitMetrics;
}

function round(value: number): number {
  return Math.round(value * 10) / 10;
}
