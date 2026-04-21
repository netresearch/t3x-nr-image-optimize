# 1.1.1

## BUGFIX

- Serve image variants when `public/processed` and/or `public/uploads` are
  symlinked to an external mount (e.g. AWS EFS on ECS via the container's
  post-deployment script). The symlink fix released in 1.1.0 only covered
  `fileadmin` (resolved via FAL storage lookup); variants under the other
  two directories still returned HTTP 400 for every uncached request
  because the parent-walk in path validation resolved them to targets
  outside the allowed-roots set. `getAllowedRoots()` now also resolves
  symlinked `public/processed` and `public/uploads` — restricted to this
  hardcoded TYPO3 namespace set to prevent an arbitrary admin-created
  symlink such as `public/etc -> /etc` from silently widening the
  allow-list. Target must be a directory (defense in depth for
  `public/uploads -> /etc/passwd` style misconfigurations).
  See [#70](https://github.com/netresearch/t3x-nr-image-optimize/issues/70),
  [#77](https://github.com/netresearch/t3x-nr-image-optimize/pull/77).

## Contributors

- Sebastian Mendel

# 1.1.0

## MISC

- 7d13283 fix: backport comprehensive quality review (PR #52) to TYPO3 12 (#54)
- 016e7f2 NEXT-95: fix version constants in SystemRequirementsService for TYPO3 12
- a30015a NEXT-95: fix code quality issues and CS violations
- 58192b7 NEXT-95: fix review comments for TYPO3 12 backport
- 50d1ebc fix: comprehensive quality review with 29 agent passes (#52)

## Contributors

- Axel Seemann
- Sebastian Mendel
- axel.seemann@netresearch.de

# 1.0.3

## BUGFIX

- 152c6ce OPSCHEM-347: [BUGFIX] Fix TypeError in Processor::getValueFromMode() for non-matching URLs

## Contributors

- Rico Sonntag

# 1.0.2

## MISC

- 6b72dc3 OPSCHEM-347: [Fix] Fix nullable dirname access in SourceSetViewHelper

## Contributors

- axel.seemann@netresearch.de

# 1.0.1

## TASK

- 31872bd [TASK] Add ext_emconf file

## Contributors

- Gitsko

# 1.0.0

## TASK

- dadff15 [TASK] Add github workflows, fix php linter errors
- 5bd1c28 [TASK] Add pipeline checks fot github actions

## MISC

- bd3dbfa [Fix] Fix tailor pipeline to user version w/o v in version
- 31d1121 [Fix] Fix pipline check for php versions

## Contributors

- Gitsko

# 0.1.5

## MISC

- 6a2678e Fix "strtolower(): Argument #1 ($string) must be of type string, null given"
- 41a6905 Allow possible file extensions include numbers
- ba1e265 Fix "Trying to access array offset on value of type bool"
- ca6a25f chore(deps): update dependency ssch/typo3-rector to v3
- 1373828 fix(deps): update dependency intervention/image to 3.7.2 || 3.11.1
- a025c2d Add renovate.json
- a60b13b Add missing extension icon
- 38723a4 CHEM-422: Correct examples for cropVariants in basic installations
- dc20ea3 CHEM-288: Change lazyload behaviour. Iptimize readme. Add additional attribute attribute.
- 9b61f07 Update README.rst. Fix Codeblock in example.
- 92acca4 OPSFX-259: Disable on the fly image optimization via optipng etc. due to massive performance issues.
- d73b4a8 Update readme regaridng usage of the viewhelper
- aadeb33 FX-864: Describe the render modes in readme.
- ddc0dab FX-864: Implement Middleware ans Processing Logic
- bbdeab9 FX-864: Fix extension key in composer.json
- 322ef79 Fix codestyle.
- 476536d FX-864: Fix code sytle.
- b64fa1e FX-864: Fix ci pipeline
- 10d78e8 FX-864: Build base for developing the extension.
- e80213c FX-864: Add Processor class.
- b78d36a FX-864: Add basic extenison files.
- 28b0863 Fix package name.
- 2988b23 Initial Commit

## Contributors

- Axel Seemann
- Renovate Bot
- Renovate Bot
- Rico Sonntag
- Sebastian Koschel

