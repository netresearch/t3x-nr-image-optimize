.PHONY: help cgl cgl-fix phpstan rector fractor lint test test-unit test-functional test-acceptance test-fuzz mutation

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

cgl: ## Check code style (dry-run)
	composer ci:test:php:cgl

cgl-fix: ## Fix code style
	composer ci:cgl

phpstan: ## Run PHPStan static analysis
	composer ci:test:php:phpstan

rector: ## Run Rector dry-run
	composer ci:test:php:rector

fractor: ## Run Fractor dry-run
	composer ci:test:php:fractor

lint: ## Run PHP linter
	composer ci:test:php:lint

test: test-unit test-functional test-acceptance ## Run all tests

test-unit: ## Run unit tests
	composer ci:test:php:unit

test-functional: ## Run functional tests
	composer ci:test:php:functional

test-acceptance: ## Run acceptance tests
	composer ci:test:php:acceptance

test-fuzz: ## Run fuzz tests
	composer ci:test:php:fuzz

mutation: ## Run mutation tests with Infection
	composer ci:test:php:mutation

.DEFAULT_GOAL := help
