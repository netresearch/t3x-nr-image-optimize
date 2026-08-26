import * as fs from 'node:fs';
import * as path from 'node:path';

/**
 * Renders the benchmark results as self-contained SVG bar charts for the
 * documentation. No dependencies: the charts are a few hundred bytes of
 * markup each, and the documentation build must not need a browser.
 *
 * Design: horizontal grouped bars, one row per scenario, core in blue and
 * the extension in orange (a colour-vision-safe pair), values as direct
 * labels in neutral ink, an explicit light surface so the figure reads the
 * same in the documentation's light and dark themes.
 */

interface Series {
  key: 'core' | 'ext';
  label: string;
  colour: string;
}

interface ChartRow {
  label: string;
  values: Record<Series['key'], number | null>;
}

export interface ChartSpec {
  title: string;
  subtitle: string;
  /** Second subtitle line: what was measured, how often, on what. */
  runs: string;
  unit: 'ms' | 'count' | 'bytes';
  rows: ChartRow[];
}

interface Metrics {
  ttfbMs: number;
  loadMs: number;
  serverCpuMs: number | null;
  variantsCreated: number;
  phpRequests: number;
  imageBytes: number;
}

export interface BenchmarkResults {
  meta: { iterations: number; images: number; date: string; environment: { typo3: string; php: string } };
  scenarios: { id: string; label: string; core: Metrics; ext: Metrics }[];
}

const SERIES: Series[] = [
  { key: 'core', label: 'TYPO3 core f:image', colour: '#2a78d6' },
  { key: 'ext', label: 'nr_image_optimize nrio:sourceSet', colour: '#eb6834' },
];

const INK = '#0b0b0b';
const INK_SECONDARY = '#52514e';
const GRID = '#e5e4e0';
const SURFACE = '#fcfcfb';

const WIDTH = 820;
const LABEL_WIDTH = 250;
const MARGIN = { top: 100, right: 96, bottom: 44, left: 16 };
const ROW_HEIGHT = 44;
const BAR = 12;
const GAP = 2;

export function formatValue(value: number, unit: ChartSpec['unit']): string {
  switch (unit) {
    case 'ms':
      if (value < 1000) {
        return `${Math.round(value)} ms`;
      }
      return `${(value / 1000).toFixed(value >= 10000 ? 1 : 2)} s`;
    case 'bytes':
      return value >= 1048576 ? `${(value / 1048576).toFixed(1)} MB` : `${Math.round(value / 1024)} kB`;
    default:
      return `${Math.round(value)}`;
  }
}

function escape(text: string): string {
  return text.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
}

/** Round the axis maximum up to 1, 2, 2.5 or 5 times a power of ten. */
function niceMax(value: number): number {
  if (value <= 0) {
    return 1;
  }
  const power = 10 ** Math.floor(Math.log10(value));
  for (const factor of [1, 2, 2.5, 5, 10]) {
    if (factor * power >= value) {
      return factor * power;
    }
  }
  return 10 * power;
}

export function renderBarChart(spec: ChartSpec): string {
  const plotWidth = WIDTH - MARGIN.left - LABEL_WIDTH - MARGIN.right;
  const height = MARGIN.top + spec.rows.length * ROW_HEIGHT + MARGIN.bottom;
  const max = niceMax(Math.max(...spec.rows.flatMap((row) => SERIES.map((s) => row.values[s.key] ?? 0))));
  const x0 = MARGIN.left + LABEL_WIDTH;
  const scale = (value: number) => (value / max) * plotWidth;

  const parts: string[] = [
    `<svg xmlns="http://www.w3.org/2000/svg" width="${WIDTH}" height="${height}" viewBox="0 0 ${WIDTH} ${height}" role="img" aria-labelledby="title desc" font-family="-apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" font-size="13">`,
    `<title id="title">${escape(spec.title)}</title>`,
    `<desc id="desc">${escape(spec.subtitle)}</desc>`,
    `<rect width="${WIDTH}" height="${height}" rx="8" fill="${SURFACE}"/>`,
    `<text x="${MARGIN.left}" y="24" font-size="16" font-weight="600" fill="${INK}">${escape(spec.title)}</text>`,
    `<text x="${MARGIN.left}" y="44" fill="${INK_SECONDARY}">${escape(spec.subtitle)}</text>`,
    `<text x="${MARGIN.left}" y="62" fill="${INK_SECONDARY}" font-size="11">${escape(spec.runs)}</text>`,
  ];

  // Legend on its own row below the subtitles.
  let legendX = MARGIN.left;
  for (const series of SERIES) {
    parts.push(
      `<rect x="${legendX}" y="74" width="12" height="12" rx="3" fill="${series.colour}"/>`,
      `<text x="${legendX + 17}" y="84" fill="${INK_SECONDARY}">${escape(series.label)}</text>`,
    );
    legendX += series.label.length * 6.6 + 34;
  }

  // Gridlines and axis ticks.
  const ticks = 4;
  for (let i = 0; i <= ticks; i += 1) {
    const value = (max / ticks) * i;
    const x = x0 + scale(value);
    parts.push(
      `<line x1="${x}" y1="${MARGIN.top - 8}" x2="${x}" y2="${height - MARGIN.bottom + 4}" stroke="${GRID}" stroke-width="1"/>`,
      `<text x="${x}" y="${height - MARGIN.bottom + 20}" text-anchor="middle" fill="${INK_SECONDARY}" font-size="11">${formatValue(value, spec.unit)}</text>`,
    );
  }

  spec.rows.forEach((row, index) => {
    const rowTop = MARGIN.top + index * ROW_HEIGHT;
    parts.push(`<text x="${x0 - 12}" y="${rowTop + ROW_HEIGHT / 2 + 4}" text-anchor="end" fill="${INK}">${escape(row.label)}</text>`);

    SERIES.forEach((series, seriesIndex) => {
      const value = row.values[series.key];
      const y = rowTop + (ROW_HEIGHT - (BAR * 2 + GAP)) / 2 + seriesIndex * (BAR + GAP);
      if (value === null) {
        parts.push(`<text x="${x0 + 6}" y="${y + BAR - 2}" fill="${INK_SECONDARY}" font-size="11">n/a</text>`);
        return;
      }
      const width = value === 0 ? 0 : Math.max(scale(value), 4);
      // Square at the baseline, rounded at the data end. A zero draws no bar:
      // a stub would claim a value that is not there.
      if (width > 0) {
        parts.push(
          `<path d="M${x0},${y} h${width - 4} a4,4 0 0 1 4,4 v${BAR - 8} a4,4 0 0 1 -4,4 h-${width - 4} z" fill="${series.colour}"/>`,
        );
      }
      parts.push(
        `<text x="${x0 + width + 6}" y="${y + BAR - 2}" fill="${INK_SECONDARY}" font-size="11">${formatValue(value, spec.unit)}</text>`,
      );
    });
  });

  parts.push('</svg>');
  return parts.join('\n') + '\n';
}

function rows(results: BenchmarkResults, pick: (metrics: Metrics) => number | null): ChartRow[] {
  return results.scenarios.map((scenario) => ({
    label: scenario.label,
    values: { core: pick(scenario.core), ext: pick(scenario.ext) },
  }));
}

export function chartSpecs(results: BenchmarkResults): Record<string, ChartSpec> {
  const runs = `Median of ${results.meta.iterations} visits · ${results.meta.images} images per page (3000×2000 JPEG → 800×600) · TYPO3 ${results.meta.environment.typo3} · PHP ${results.meta.environment.php} · Apache + PHP-FPM in Docker`;
  return {
    ttfb: {
      title: 'Time to first byte of the HTML document',
      subtitle: 'Client view: how long the visitor waits before the page starts to arrive',
      runs,
      unit: 'ms',
      rows: rows(results, (m) => m.ttfbMs),
    },
    load: {
      title: 'Page fully loaded (window load event)',
      subtitle: 'Client view: HTML plus every eager image delivered',
      runs,
      unit: 'ms',
      rows: rows(results, (m) => m.loadMs),
    },
    'server-cpu': {
      title: 'Server CPU time per visit',
      subtitle: 'Server view: PHP-FPM container including ImageMagick child processes',
      runs,
      unit: 'ms',
      rows: rows(results, (m) => m.serverCpuMs),
    },
    'variants-written': {
      title: 'Variant files written per visit',
      subtitle: 'Server view: core writes one file per image, the extension three (JPEG, WebP, AVIF)',
      runs,
      unit: 'count',
      rows: rows(results, (m) => m.variantsCreated),
    },
    'php-requests': {
      title: 'Requests handled by PHP per visit',
      subtitle: 'Server view: responses that went through TYPO3 instead of being served as static files',
      runs,
      unit: 'count',
      rows: rows(results, (m) => m.phpRequests),
    },
  };
}

export function writeCharts(results: BenchmarkResults, outDir: string): string[] {
  fs.mkdirSync(outDir, { recursive: true });
  const written: string[] = [];
  for (const [name, spec] of Object.entries(chartSpecs(results))) {
    const file = path.join(outDir, `${name}.svg`);
    fs.writeFileSync(file, renderBarChart(spec));
    written.push(file);
  }
  return written;
}
