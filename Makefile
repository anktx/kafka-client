.PHONY: test test-coverage infection infection-coverage clean help

# Docker configuration
DOCKER_IMAGE = local-php-cli:8.4-dev
DOCKER_RUN = docker run --rm -v "$(PWD):/app" -w /app $(DOCKER_IMAGE)

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

test: ## Run PHPUnit tests
	@echo '$(BLUE)Running PHPUnit tests...$(NC)'
	$(DOCKER_RUN) vendor/bin/phpunit --testsuite="Unit tests" --colors=never

test-integration: ## Run integration tests (requires Kafka)
	@echo '$(BLUE)Running integration tests...$(NC)'
	$(DOCKER_RUN) vendor/bin/phpunit --testsuite="Integration tests" --colors=never

test-all: ## Run all tests (unit + integration)
	@echo '$(BLUE)Running all tests...$(NC)'
	$(DOCKER_RUN) vendor/bin/phpunit tests --colors=never

test-file: ## Run a single test file (usage: make test-file FILE=tests/Path/ToTest.php)
	@echo '$(BLUE)Running test file: $(FILE)$(NC)'
	$(DOCKER_RUN) vendor/bin/phpunit $(FILE) --colors=never

test-coverage: ## Run PHPUnit tests with coverage (XML for Infection)
	@echo '$(BLUE)Running PHPUnit tests with coverage (XML)...$(NC)'
	$(DOCKER_RUN) vendor/bin/phpunit --testsuite="Unit tests" --coverage-xml=.infection/coverage-xml --log-junit=.infection/junit.xml

test-coverage-text: ## Run PHPUnit tests with coverage (text report)
	@echo '$(BLUE)Running PHPUnit tests with coverage (text)...$(NC)'
	$(DOCKER_RUN) vendor/bin/phpunit --testsuite="Unit tests" --coverage-text --colors=never

infection: ## Run Infection mutation testing
	@echo '$(BLUE)Running Infection mutation testing...$(NC)'
	@echo '$(YELLOW)Note: Run "make infection-show" to see mutation details$(NC)'
	$(DOCKER_RUN) vendor/bin/infection --coverage=.infection/coverage-xml

infection-show: ## Run Infection with mutation details
	@echo '$(BLUE)Running Infection with mutation details...$(NC)'
	$(DOCKER_RUN) vendor/bin/infection --coverage=.infection/coverage-xml --show-mutations

infection-test: ## Run Infection for a single test (usage: make infection-test TEST=tests/Path/ToTest.php)
	@echo '$(BLUE)Running Infection for test: $(TEST)$(NC)'
	$(DOCKER_RUN) vendor/bin/infection --coverage=.infection/coverage-xml --filter='$(TEST)' --show-mutations

infection-test-src: ## Run Infection for a specific source file (usage: make infection-test-src SRC=src/Path/ToFile.php)
	@echo '$(BLUE)Running Infection for source: $(SRC)$(NC)'
	$(DOCKER_RUN) vendor/bin/infection --coverage=.infection/coverage-xml --filter='$(SRC)' --show-mutations

infection-test-src-skip: ## Run Infection for source skipping initial tests (usage: make infection-test-src-skip SRC=src/Path/ToFile.php)
	@echo '$(BLUE)Running Infection for source: $(SRC) (skip initial tests)$(NC)'
	$(DOCKER_RUN) vendor/bin/infection --coverage=.infection/coverage-xml --filter='$(SRC)' --show-mutations --skip-initial-tests

infection-relaxed: ## Run Infection with relaxed MSI threshold (usage: make infection-relaxed SRC=src/Path/ToFile.php MSI=60)
	@echo '$(BLUE)Running Infection with MSI=$(MSI): $(SRC)$(NC)'
	$(DOCKER_RUN) vendor/bin/infection --coverage=.infection/coverage-xml --filter='$(SRC)' --show-mutations --skip-initial-tests --min-msi=$(MSI) --min-covered-msi=$(MSI)

cs-fix: ## Run PHP CS Fixer
	@echo '$(BLUE)Running PHP CS Fixer...$(NC)'
	$(DOCKER_RUN) vendor/bin/php-cs-fixer fix -v --diff --show-progress=dots

cs-dry: ## Run PHP CS Fixer in dry-run mode
	@echo '$(BLUE)Running PHP CS Fixer (dry-run)...$(NC)'
	$(DOCKER_RUN) vendor/bin/php-cs-fixer fix --dry-run -v --diff --show-progress=dots

analyse: ## Run PHPStan static analysis
	@echo '$(BLUE)Running PHPStan analysis...$(NC)'
	$(DOCKER_RUN) vendor/bin/phpstan analyse --memory-limit=256M -v --level 6 --no-progress ./src

clean: ## Clean up generated files
	@echo '$(BLUE)Cleaning up...$(NC)'
	rm -rf .infection
	rm -rf .phpunit.cache
	@echo '$(GREEN)Cleaned up!$(NC)'

ci: test-coverage infection ## Run full CI pipeline (tests + mutation testing)
	@echo '$(GREEN)CI pipeline completed successfully!$(NC)'
