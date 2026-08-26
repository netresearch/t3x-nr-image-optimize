.PHONY: help cgl cgl-fix phpstan rector fractor lint test test-unit test-functional test-acceptance test-fuzz test-e2e benchmark mutation mutation-full ci all

RUNTESTS = Build/Scripts/runTests.sh

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

cgl: ## Check code style (dry-run)
	$(RUNTESTS) -s cgl -n

cgl-fix: ## Fix code style
	$(RUNTESTS) -s cgl:fix

phpstan: ## Run PHPStan static analysis
	$(RUNTESTS) -s phpstan

rector: ## Run Rector dry-run
	$(RUNTESTS) -s rector -n

fractor: ## Run Fractor dry-run
	$(RUNTESTS) -s fractor -n

lint: ## Run PHP linter
	$(RUNTESTS) -s lint

test: test-unit test-functional test-acceptance ## Run all tests

test-unit: ## Run unit tests
	$(RUNTESTS) -s unit

test-functional: ## Run functional tests
	$(RUNTESTS) -s functional

test-acceptance: ## Run acceptance tests
	$(RUNTESTS) -s acceptance

test-fuzz: ## Run fuzz tests
	$(RUNTESTS) -s fuzz

test-e2e: ## Run the Playwright e2e/benchmark suite (provisions TYPO3 in Docker; E2E_TYPO3_VERSION=13|14)
	$(RUNTESTS) -s e2e

benchmark: test-e2e ## Run the benchmark and refresh the charts in Documentation/Images/Benchmark
	cp .Build/benchmark/results.json .Build/benchmark/*.svg Documentation/Images/Benchmark/

mutation: ## Run mutation tests (unit tests only)
	$(RUNTESTS) -s mutation

mutation-full: ## Run mutation tests (unit + functional + acceptance)
	$(RUNTESTS) -s mutation-full

ci: ## Run full CI suite (lint, cgl, phpstan, rector, fractor, unit)
	$(RUNTESTS) -s ci

all: ## Run all tests and quality checks
	$(RUNTESTS) -s all

.DEFAULT_GOAL := help
