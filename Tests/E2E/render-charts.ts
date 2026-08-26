/*
 * Re-render the documentation charts from an existing results file without
 * running the benchmark again:
 *
 *   node Tests/E2E/render-charts.ts [.Build/benchmark/results.json] [outDir]
 *
 * Needs Node >= 22.6 (type stripping); the Playwright image and any current
 * Node do. `make benchmark` runs the same code at the end of the suite.
 */
import * as fs from 'node:fs';
import * as path from 'node:path';
import { writeCharts, type BenchmarkResults } from './lib/charts.ts';

// Paths are relative to the working directory (run from the repository root)
// and must stay inside it: this script reads one file and writes SVGs.
function insideWorkingDirectory(candidate: string): string {
  const resolved = path.resolve(candidate);
  if (resolved !== process.cwd() && !resolved.startsWith(process.cwd() + path.sep)) {
    throw new Error(`refusing to use ${candidate}: outside the working directory ${process.cwd()}`);
  }
  return resolved;
}

const resultsFile = insideWorkingDirectory(process.argv[2] ?? '.Build/benchmark/results.json');
const outDir = insideWorkingDirectory(process.argv[3] ?? path.dirname(resultsFile));

const results = JSON.parse(fs.readFileSync(resultsFile, 'utf8')) as BenchmarkResults;
for (const file of writeCharts(results, outDir)) {
  console.log(`wrote ${file}`);
}
