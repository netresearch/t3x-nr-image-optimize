# 2.2.1

## MISC

- e529f72 NEXT-98: Adjust author in ext_emconf

## Contributors

- Sebastian Altenburg

# 2.2.0

## MISC

- 6535aad BUGFIX: Always render alt attribute
- 1448d40 TEST: consolidate responsive aspect coverage
- e61944b TEST: streamline redundant coverage
- 3c29401 Include test directories in dev tools
- 293381e Update AGENTS.md
- 549e3cc test: expand processor and viewhelper coverage
- 70b6edb test: expand coverage for processor and view helper
- 65d5a6c Update AGENTS.md
- 456ce95 Add missing unit tests for processor locking and view helper attributes
- 39aa08f Update AGENTS.md
- 6692726 Add package header to ProcessorTest
- 57ce677 Add processor URL parsing unit tests
- a0f54d7 Update AGENTS.md
- 27889d5 Add more tests
- 99f4486 Add AGENTS.md
- e65fbcf Add separate phpunit coverage command
- b38c883 Update dev dependencies
- da1fb19 Fix order in README
- d71a270 Update php-cs-fixer configuration
- ea0c2bc Potential fix for code scanning alert no. 3: Workflow does not contain permissions

## Contributors

- Gitsko
- Rico Sonntag
- Rico Sonntag

# 2.1.0

## MISC

- d48674d NEXT-93: Optimize default sizes attribute
- 4756ea1 Update README.rst
- d80031c Update README.rst
- c82e876 NEXT-93: Fix conding style
- c1ff9ef NEXT-93: Fix conding style
- 790b084 NEXT-93: Update readme
- fb9fa29 Update Classes/ViewHelpers/SourceSetViewHelper.php
- 0a384f8 NEXT-93: Correct image variants
- 230746c NEXT-93: Set nerw breakpoints
- 6ebc1ff NEXT-93: Optimize code
- 9a87ac3 NEXT-93: Fix tests
- 4059be7 NEXT-93 Replace empty() with count() comparison
- 783391b NEXT-93 Fix PHPStan errors in SourceSetViewHelper
- 1522eb7 NEXT-93 Optimize ViewHelper code and add test coverage
- 81099fb NEXT-93 Add responsive width-based srcset with sizes attribute

## Contributors

- Gitsko
- Sebastian Koschel

# 2.0.1

## BUGFIX

- 8ba0230 [BUGFIX] Remove declare statement to publish via github action in TER

## Contributors

- Gitsko

# 2.0.0

## FEATURE

- b47bc27 [FEATURE] Add TYPO3 13 compatibility with PHP 8.2-8.4 support

## MISC

- 779034e Delete Build/phpstan-docker.neon
- 5f0c53b Update README
- 8ef206e Add license file
- 776c4b3 Drop duplicate README
- e76d74d Add suggest package "christophlehmann/imageoptimizer" to composer.json
- a0899bf Remove obsolete check for system binaries, as no longer required
- 48cb3c4 Add small script to check required system binaries
- 172b239 Add missing phpdoc
- 2efa74f Update github CI workflow
- 8d5580a Fix phpstan issues
- 4b68a0d Update ext_emconf.php
- 8a72c2a Update composer.json
- 911eac0 Fix unittests
- 16019c1 Update dev tool configuration, add missing Unittests.xml
- 3b7bb23 Update baseline and other changes
- e0e3671 Update Build/.php-cs-fixer.dist.php
- b77308a Update Classes/ViewHelpers/SourceSetViewHelper.php
- 84f1a11 Update Classes/ViewHelpers/SourceSetViewHelper.php
- 4ba3b22 Delete tst files
- cab4026 TYPO3-13: Add type hints to arrow functions as suggested by Rector
- 334ea47 TYPO3-13: Fix remaining code style issues
- a7da671 TYPO3-13: Fix PHPStan strict rules violations and update baseline
- 3b62852 TYPO3-13: Fix PHPStan v2 configuration
- b019146 TYPO3-13: Update PHPStan to v2 and replace deprecated extension
- c602bc6 Update composer.json
- 3a914a2 Update ext_emconf.php
- 6a1d4c2 chore(deps): update actions/checkout action to v5
- 47747b3 fix(deps): update dependency intervention/image to 3.7.2 || 3.11.1 || 3.11.4
- b44ad84 chore(deps): update actions/cache action to v4
- 9898e9c chore(deps): update dependency typo3/testing-framework to v9
- b90f656 chore(deps): update phpstan packages to v2
- 1cfa6c7 [FIX] Remove Changelog section from README
- 64044e7 [FIX] Remove PHP Compatibility Check instructions

## Contributors

- Gitsko
- Gitsko
- Rico Sonntag
- renovate[bot]

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

