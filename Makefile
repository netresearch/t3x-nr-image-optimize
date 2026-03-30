.PHONY: help cgl cgl-fix phpstan rector fractor lint test test-unit test-functional test-acceptance test-fuzz mutation mutation-full ci all

RUNTESTS = Build/Scripts/runTests.sh

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

cgl: ## Check code style (dry-run)
	$(RUNTESTS) cgl

cgl-fix: ## Fix code style
	$(RUNTESTS) cgl:fix

phpstan: ## Run PHPStan static analysis
	$(RUNTESTS) phpstan

rector: ## Run Rector dry-run
	$(RUNTESTS) rector

fractor: ## Run Fractor dry-run
	$(RUNTESTS) fractor

lint: ## Run PHP linter
	$(RUNTESTS) lint

test: test-unit test-functional test-acceptance ## Run all tests

test-unit: ## Run unit tests
	$(RUNTESTS) unit

test-functional: ## Run functional tests
	$(RUNTESTS) functional

test-acceptance: ## Run acceptance tests
	$(RUNTESTS) acceptance

test-fuzz: ## Run fuzz tests
	$(RUNTESTS) fuzz

mutation: ## Run mutation tests (unit tests only)
	$(RUNTESTS) mutation

mutation-full: ## Run mutation tests (unit + functional + acceptance)
	$(RUNTESTS) mutation-full

ci: ## Run full CI suite (lint, cgl, phpstan, rector, fractor, unit)
	$(RUNTESTS) ci

all: ## Run all tests and quality checks
	$(RUNTESTS) all

.DEFAULT_GOAL := help
