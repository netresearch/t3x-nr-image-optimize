# E2E performance benchmark

Measures what the documentation claims in *Introduction > Performance model*:
that `nrio:sourceSet` moves image processing out of the page render, and what
that is worth to a visitor (time to first byte, load time) and to the server
(CPU time, PHP requests, files written) — against TYPO3 core's `f:image` on
otherwise identical pages.

## Run it

```bash
make test-e2e                       # provisions TYPO3 14 + the extension in Docker, runs the suite
make benchmark                      # same, then copies results + charts into Documentation/Images/Benchmark/
E2E_TYPO3_VERSION=13 make test-e2e  # against TYPO3 13
BENCHMARK_ITERATIONS=5 make test-e2e
```

Only Docker (or Podman) is needed. `Build/Scripts/runTests.sh -s e2e` builds
MariaDB, a composer-installed TYPO3 with this working tree as the extension,
PHP-FPM and Apache, then drives Chromium from the Playwright image against it.
The hooks in `Build/Scripts/runTests.conf` (`e2e_provision_*`) seed the
benchmark pages, and `Fixtures/seed.php` generates 24 synthetic 3000×2000
JPEGs so that no large binaries live in the repository.

Results: `.Build/benchmark/results.json` (medians plus every iteration).

## What is measured

Five pages render the same 24 photos as 800×600 crops:

| Page                | Template                            |
|---------------------|-------------------------------------|
| `/bench/core-eager` | `<f:image width="800c" height="600c">` |
| `/bench/ext-eager`  | `<nrio:sourceSet width="800" height="600">` |
| `/bench/core-lazy`  | as above, `loading="lazy"`           |
| `/bench/ext-lazy`   | as above, `lazyload="1"`             |
| `/bench/ext-eager-jpeg` | hand-written `/processed/` URLs with `skipWebP=1&skipAvif=1` — one JPEG per image, like core |

Seven scenarios, each visited with both pipelines in a fresh browser context:

1. **Page render only** — caches and variants cold, image requests aborted by the browser. Isolates the HTML response.
2. **Everything cold** — first visitor after a deployment.
3. **Everything cold, JPEG only** — like 2, but the extension skips WebP/AVIF, so both pipelines write one JPEG per image (the like-for-like CPU comparison).
4. **Page cache cold, variants exist** — editor change or deployment, variants still on disk.
5. **Everything warm** — steady state.
6. **Page cache warm, variants purged** — variant files deleted while the page cache still serves the old HTML.
7. **Everything cold, lazy loading** — like 2 with native lazy loading and a 1280×720 viewport.

Client side (Navigation/Resource Timing in Chromium): TTFB, DOMContentLoaded,
load, LCP, image requests and bytes, broken (4xx/5xx) images. Server side
(`public/_bench/control.php`, seeded into the instance, never shipped): CPU
time of the PHP-FPM container from cgroup `cpu.stat` (includes ImageMagick
child processes), variant files written, and — from the `X-Powered-By`
header — how many responses went through PHP at all.

## What is asserted

The suite fails when the architectural claims stop holding:

- rendering a page writes **0** variants with `nrio:sourceSet`, ≥ 24 with `f:image`;
- render-only and cold-cache TTFB are lower with the extension;
- with variants purged under a warm page cache, the extension serves 0 broken images, core serves 404s;
- with lazy loading, the extension processes fewer images than core.

Absolute numbers are reported, never asserted: they depend on the machine.
