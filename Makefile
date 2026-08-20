.PHONY: help validate test test-integration test-all test-file test-coverage test-coverage-text coverage \
	infection infection-test infection-test-src infection-test-src-skip infection-relaxed \
	cs-fix cs-dry analyse analyse-baseline qa clean ci

# Docker configuration
DOCKER_IMAGE = local-php-cli:8.4-dev
# -u + HOME: файлы-артефакты (.phpunit.cache, .infection, vendor-кэш) остаются
# принадлежащими текущему пользователю, а не root.
DOCKER_RUN = docker run --rm -u "$(shell id -u):$(shell id -g)" -e HOME=/tmp -v "$(CURDIR):/app" -w /app $(DOCKER_IMAGE)

# Адрес брокера для интеграционных тестов (пробрасывается в контейнер)
KAFKA_BROKERS ?= localhost:9092

# Default target
.DEFAULT_GOAL := help

# Colors for output
BLUE := \033[0;34m
GREEN := \033[0;32m
YELLOW := \033[1;33m
NC := \033[0m # No Color

help: ## Show this help message
	@echo '$(BLUE)Available targets:$(NC)'
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-20s$(NC) %s\n", $$1, $$2}'

validate: ## Validate composer.json in strict mode
	$(DOCKER_RUN) composer validate --strict

test: ## Run PHPUnit unit tests
	@echo '$(BLUE)Running PHPUnit unit tests...$(NC)'
	$(DOCKER_RUN) vendor/bin/phpunit --testsuite=Unit --colors=never

test-integration: ## Run integration tests (requires Kafka at KAFKA_BROKERS, default localhost:9092)
	@echo '$(BLUE)Running integration tests...$(NC)'
	$(DOCKER_RUN) -e KAFKA_BROKERS="$(KAFKA_BROKERS)" vendor/bin/phpunit --testsuite=Integration --colors=never

test-all: ## Run all tests (unit + integration)
	@echo '$(BLUE)Running all tests...$(NC)'
	$(DOCKER_RUN) -e KAFKA_BROKERS="$(KAFKA_BROKERS)" vendor/bin/phpunit tests --colors=never

test-file: ## Run a single test file (usage: make test-file FILE=tests/Path/ToTest.php)
	@echo '$(BLUE)Running test file: $(FILE)$(NC)'
	$(DOCKER_RUN) -e KAFKA_BROKERS="$(KAFKA_BROKERS)" vendor/bin/phpunit $(FILE) --colors=never

test-coverage: ## Run PHPUnit tests with coverage (XML for filtered Infection runs)
	@echo '$(BLUE)Running PHPUnit tests with coverage (XML)...$(NC)'
	$(DOCKER_RUN) vendor/bin/phpunit --testsuite=Unit --coverage-xml=.infection/coverage-xml --log-junit=.infection/coverage-xml/junit.xml

test-coverage-text: ## Run PHPUnit tests with coverage (text report)
	@echo '$(BLUE)Running PHPUnit tests with coverage (text)...$(NC)'
	$(DOCKER_RUN) vendor/bin/phpunit --testsuite=Unit --coverage-text --colors=never

coverage: ## Run PHPUnit tests with coverage and enforce the 100% line coverage gate
	@echo '$(BLUE)Running PHPUnit tests with coverage gate...$(NC)'
	$(DOCKER_RUN) vendor/bin/phpunit --testsuite=Unit --coverage-text --coverage-php=.phpunit.cache/coverage.cov --colors=never
	$(DOCKER_RUN) php bin/coverage-check.php

infection: ## Run Infection mutation testing (same as composer infection)
	@echo '$(BLUE)Running Infection mutation testing...$(NC)'
	$(DOCKER_RUN) vendor/bin/infection --show-mutations

infection-test: ## Run Infection for a single test file (usage: make infection-test TEST=tests/Path/ToTest.php; requires make test-coverage)
	@echo '$(BLUE)Running Infection for test: $(TEST)$(NC)'
	$(DOCKER_RUN) vendor/bin/infection --coverage=.infection/coverage-xml --filter='$(TEST)' --show-mutations

infection-test-src: ## Run Infection for a source file (usage: make infection-test-src SRC=src/Path/ToFile.php; requires make test-coverage)
	@echo '$(BLUE)Running Infection for source: $(SRC)$(NC)'
	$(DOCKER_RUN) vendor/bin/infection --coverage=.infection/coverage-xml --filter='$(SRC)' --show-mutations

infection-test-src-skip: ## Run Infection for a source file skipping initial tests (usage: make infection-test-src-skip SRC=src/Path/ToFile.php; requires make test-coverage)
	@echo '$(BLUE)Running Infection for source: $(SRC) (skip initial tests)$(NC)'
	$(DOCKER_RUN) vendor/bin/infection --coverage=.infection/coverage-xml --filter='$(SRC)' --show-mutations --skip-initial-tests

infection-relaxed: ## Run Infection with relaxed MSI threshold (usage: make infection-relaxed SRC=src/Path/ToFile.php MSI=60; requires make test-coverage)
	@echo '$(BLUE)Running Infection with MSI=$(MSI): $(SRC)$(NC)'
	$(DOCKER_RUN) vendor/bin/infection --coverage=.infection/coverage-xml --filter='$(SRC)' --show-mutations --skip-initial-tests --min-msi=$(MSI) --min-covered-msi=$(MSI)

cs-fix: ## Run PHP CS Fixer
	@echo '$(BLUE)Running PHP CS Fixer...$(NC)'
	$(DOCKER_RUN) vendor/bin/php-cs-fixer fix -v --diff --show-progress=dots

cs-dry: ## Run PHP CS Fixer in dry-run mode
	@echo '$(BLUE)Running PHP CS Fixer (dry-run)...$(NC)'
	$(DOCKER_RUN) vendor/bin/php-cs-fixer fix --dry-run -v --diff --show-progress=dots

analyse: ## Run PHPStan static analysis (level 9)
	@echo '$(BLUE)Running PHPStan analysis (level 9)...$(NC)'
	$(DOCKER_RUN) vendor/bin/phpstan analyse --memory-limit=512M --no-progress

analyse-baseline: ## Generate PHPStan baseline
	@echo '$(BLUE)Generating PHPStan baseline...$(NC)'
	$(DOCKER_RUN) vendor/bin/phpstan analyse --memory-limit=512M --generate-baseline

qa: validate cs-dry analyse coverage ## Run full QA pipeline (validate + style check + static analysis + unit tests + coverage gate)
	@echo '$(GREEN)QA pipeline completed successfully!$(NC)'

clean: ## Clean up generated files
	@echo '$(BLUE)Cleaning up...$(NC)'
	rm -rf .infection
	rm -rf .phpunit.cache
	rm -rf .cache
	rm -rf phpstan-baseline.neon
	@echo '$(GREEN)Cleaned up!$(NC)'

ci: validate cs-dry analyse coverage infection ## Run full CI pipeline (validate + style + static analysis + tests + coverage gate + mutation testing)
	@echo '$(GREEN)CI pipeline completed successfully!$(NC)'
