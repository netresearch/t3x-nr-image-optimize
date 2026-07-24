# ADR-0001: Browser Format Support via Explicit Query Parameters, Not Accept-Header Sniffing

**Status:** Accepted
**Date:** 2026-04-16

---

## Context

PR #28 (`NEXT-99`, upload-time image optimization) introduced a `determineBrowserSupport()` method in
`Classes/Processor.php` that inspected the `Accept` request header to decide whether to serve a WebP/AVIF
variant. Review of that PR found two defects:

- It built a fresh `ServerRequestFactory::fromGlobals()` instead of using the request already available to
  the dispatcher, so it did not actually see the client's real `Accept` header in most call paths.
- It did not strip quality values (`image/webp;q=0.9`), so `Accept` headers with quality-weighted media
  types failed to match.

Fixing those two bugs would have kept the method alive, but `main`'s `Processor` already has a working,
independent mechanism for the same decision: the `skipWebP` / `skipAvif` query parameters, populated by
`SourceSetViewHelper` (`Classes/ViewHelpers/SourceSetViewHelper.php`) and read via
`Processor::parseQueryParams()`. `SourceSetViewHelper` builds `<picture>`/`srcset` markup, so format
selection there is a deliberate authoring-time or client-capability decision expressed explicitly in the
generated URL — not something the server should re-derive by re-parsing `Accept` at request time.

## Decision

Format negotiation (WebP/AVIF vs. original) is decided exclusively through the explicit `skipWebP` /
`skipAvif` query parameters carried in the processed-image URL. `Processor` does not inspect the `Accept`
request header to decide which variant to serve.

`determineBrowserSupport()` was removed from `Classes/Processor.php` rather than fixed.

## Reasons

- **Single source of truth.** Two independent mechanisms for the same decision (query params set by the
  ViewHelper, and header sniffing in the dispatcher) can disagree — e.g. a client explicitly requesting the
  original via a hand-authored URL could still get silently swapped for a WebP variant based on stale/generic
  `Accept` parsing. Keeping one mechanism removes that drift risk.
- **Correctness.** Building a request object from globals inside `Processor` produced wrong results in the
  actual dispatch path, where the real `ServerRequestInterface` was already available but not threaded
  through. There was no way to fix this without plumbing the request further than the architecture already
  does for this concern.
- **Explicitness over inference.** The `SourceSetViewHelper` already knows the calling context (author intent,
  responsive variants being generated) and is the natural place to decide whether WebP/AVIF should be
  offered at all — passing that decision down as an explicit flag is simpler to reason about and test than
  re-inferring it from headers at serve time.

## Trade-offs

- Callers that request a processed image URL directly (bypassing `SourceSetViewHelper`) get no automatic
  `Accept`-header-based fallback — they must set `skipWebP=1` / `skipAvif=1` explicitly if they want to opt
  out of modern formats.

## Consequences for future work

Do not reintroduce `Accept`-header sniffing in `Processor.php` for format selection. If a future requirement
needs server-side content negotiation independent of the query parameters, treat it as a new decision
superseding this one — it must not silently duplicate the existing `skipWebP`/`skipAvif` path.
