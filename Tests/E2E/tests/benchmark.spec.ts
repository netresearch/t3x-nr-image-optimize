import { test, expect } from '@playwright/test';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { aggregate, reset, stat, visit, type VisitMetrics } from '../lib/bench';
import { writeCharts } from '../lib/charts';

/**
 * Render-vs-process benchmark: TYPO3 core f:image against nrio:sourceSet.
 *
 * Both pipelines render the same 24 photos (3000x2000 JPEG) as 800x600
 * crops on otherwise identical pages. Each scenario is a state a real site
 * is in at some point; each is visited with both pipelines, several times,
 * and the median is kept. The assertions at the end are the durable claims
 * the documentation makes — they must hold on every run, on any machine.
 *
 * Results land in .Build/benchmark/results.json (BENCHMARK_RESULTS to
 * override); `make benchmark` renders the charts from that file.
 */

const IMAGES = 24;
const ITERATIONS = Number.parseInt(process.env.BENCHMARK_ITERATIONS ?? '3', 10);
const RESULTS = process.env.BENCHMARK_RESULTS ?? path.resolve(__dirname, '../../../.Build/benchmark/results.json');

type Pipeline = 'core' | 'ext';

interface Scenario {
  id: string;
  label: string;
  description: string;
  reset: { caches?: boolean; variants?: boolean };
  page: 'eager' | 'lazy' | 'eagerSingleFormat';
  blockImages?: boolean;
}

// Core has one format only, so its "single format" page is the eager page.
const PAGES: Record<Pipeline, Record<Scenario['page'], string>> = {
  core: { eager: '/bench/core-eager', lazy: '/bench/core-lazy', eagerSingleFormat: '/bench/core-eager' },
  ext: { eager: '/bench/ext-eager', lazy: '/bench/ext-lazy', eagerSingleFormat: '/bench/ext-eager-jpeg' },
};

// Order matters: each scenario leaves the state the next one starts from.
const SCENARIOS: Scenario[] = [
  {
    id: 'render-only',
    label: 'Page render only',
    description: 'Caches and variants cold; the browser aborts every image request, so only the HTML response is measured.',
    reset: { caches: true, variants: true },
    page: 'eager',
    blockImages: true,
  },
  {
    id: 'cold',
    label: 'Everything cold',
    description: 'Page cache empty, no variant exists yet: the first visitor after a deployment.',
    reset: { caches: true, variants: true },
    page: 'eager',
  },
  {
    id: 'cold-single-format',
    label: 'Everything cold, JPEG only',
    description: 'As "everything cold", but the extension URLs carry skipWebP=1&skipAvif=1, so both pipelines write one JPEG per image.',
    reset: { caches: true, variants: true },
    page: 'eagerSingleFormat',
  },
  {
    id: 'page-cache-cold',
    label: 'Page cache cold, variants exist',
    description: 'Page cache flushed (editor change, deployment), variant files still on disk.',
    reset: { caches: true },
    page: 'eager',
  },
  {
    id: 'warm',
    label: 'Everything warm',
    description: 'Steady state: page cache hit, every variant on disk.',
    reset: {},
    page: 'eager',
  },
  {
    id: 'variants-purged',
    label: 'Page cache warm, variants purged',
    description: 'Variant files deleted (cleanup, new server, storage migration) while the page cache still serves the old HTML.',
    reset: { variants: true },
    page: 'eager',
  },
  {
    id: 'lazy-cold',
    label: 'Everything cold, lazy loading',
    description: 'As "everything cold", with loading="lazy" on every image and a 1280x720 viewport.',
    reset: { caches: true, variants: true },
    page: 'lazy',
  },
];

test('render-vs-process benchmark: core f:image vs nrio:sourceSet', async ({ browser, request, baseURL }) => {
  expect(baseURL, 'BASE_URL / TYPO3_BASE_URL must point at the provisioned instance').toBeTruthy();
  const base = (baseURL as string).replace(/\/$/, '');

  const environment = (await stat(request, base)).environment;
  console.log(`environment: ${JSON.stringify(environment)}`);

  const runs: Record<string, Record<Pipeline, VisitMetrics[]>> = {};
  for (const scenario of SCENARIOS) {
    runs[scenario.id] = { core: [], ext: [] };
  }

  for (let iteration = 1; iteration <= ITERATIONS; iteration += 1) {
    for (const pipeline of ['core', 'ext'] as Pipeline[]) {
      for (const scenario of SCENARIOS) {
        await reset(request, base, scenario.reset);
        const metrics = await visit(browser, request, base, PAGES[pipeline][scenario.page], {
          blockImages: scenario.blockImages,
        });
        runs[scenario.id][pipeline].push(metrics);
        console.log(
          `[${iteration}/${ITERATIONS}] ${scenario.id.padEnd(16)} ${pipeline.padEnd(4)}` +
            ` ttfb=${metrics.ttfbMs}ms load=${metrics.loadMs}ms cpu=${metrics.serverCpuMs}ms` +
            ` php=${metrics.phpRequests} img=${metrics.imageRequests} broken=${metrics.brokenImages}` +
            ` variants=+${metrics.variantsCreated}`,
        );
      }
    }
  }

  const scenarios = SCENARIOS.map((scenario) => ({
    id: scenario.id,
    label: scenario.label,
    description: scenario.description,
    core: aggregate(runs[scenario.id].core),
    ext: aggregate(runs[scenario.id].ext),
    iterations: { core: runs[scenario.id].core, ext: runs[scenario.id].ext },
  }));

  const results = {
    meta: {
      date: new Date().toISOString(),
      images: IMAGES,
      sourceImage: '3000x2000 JPEG',
      variant: '800x600 crop',
      iterations: ITERATIONS,
      aggregate: 'median',
      viewport: '1280x720',
      environment,
    },
    scenarios,
  };

  fs.mkdirSync(path.dirname(RESULTS), { recursive: true });
  fs.writeFileSync(RESULTS, JSON.stringify(results, null, 2) + '\n');
  console.log(`results written to ${RESULTS}`);
  for (const chart of writeCharts(results, path.dirname(RESULTS))) {
    console.log(`chart written to ${chart}`);
  }

  const byId = Object.fromEntries(scenarios.map((scenario) => [scenario.id, scenario]));

  // The architectural claim: rendering a page writes no variant with this
  // extension, while core processes every referenced image during render.
  expect(byId['render-only'].ext.variantsCreated, 'nrio:sourceSet must not process any image while rendering').toBe(0);
  expect(byId['render-only'].core.variantsCreated, 'core f:image processes every image while rendering').toBeGreaterThanOrEqual(IMAGES);
  expect(byId['render-only'].ext.ttfbMs, 'render-only TTFB must be lower with the extension').toBeLessThan(byId['render-only'].core.ttfbMs);

  // The first visitor after a deployment sees the HTML sooner.
  expect(byId['cold'].ext.ttfbMs, 'cold-cache TTFB must be lower with the extension').toBeLessThan(byId['cold'].core.ttfbMs);

  // Purging variants under a warm page cache breaks core's images (the HTML
  // points at files nothing regenerates); the middleware regenerates on demand.
  expect(byId['variants-purged'].ext.brokenImages, 'extension must regenerate purged variants on demand').toBe(0);
  expect(byId['variants-purged'].core.brokenImages, 'core serves 404s for purged variants under a warm page cache').toBeGreaterThan(0);

  // Lazy loading: core still processes all 24 during render; the extension
  // only processes what the browser actually requested.
  expect(byId['lazy-cold'].core.variantsCreated).toBeGreaterThanOrEqual(IMAGES);
  expect(byId['lazy-cold'].ext.variantsCreated, 'lazy loading must leave unrequested images unprocessed').toBeLessThan(
    byId['lazy-cold'].core.variantsCreated,
  );
});
