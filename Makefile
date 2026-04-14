.PHONY: run qa test test-unit test-integration lint analyse rector md

# Usage:
#  - Run default QA: make qa
#  - Run any composer script: make run SCRIPT=analyse
#  - Keep vendor between runs: composer install once, or use your CI cache

SCRIPT ?= qa

# Delegate to the monorepo test runner so installation/cleanup is consistent
# Usage: make run SCRIPT=qa    (keeps old behaviour)
# The monorepo script signature: scripts/run-zolta-cqrs-package-tests.sh <pkg-dir> <script> [keep]
run:
	@echo "Checking for monorepo runner..."
	@if [ -x ../../scripts/run-zolta-cqrs-package-tests.sh ]; then \
		echo "Delegating to monorepo scripts/run-zolta-cqrs-package-tests.sh"; \
		../../scripts/run-zolta-cqrs-package-tests.sh . $(SCRIPT) $(KEEP); \
	else \
		echo "Monorepo runner not found — installing locally and running $(SCRIPT)"; \
		composer install --no-interaction --prefer-dist --ansi; \
		composer run-script --no-interaction $(SCRIPT); \
	fi

qa:
	$(MAKE) run SCRIPT=qa

test:
	$(MAKE) run SCRIPT=test

test-unit:
	$(MAKE) run SCRIPT=test:unit

test-integration:
	$(MAKE) run SCRIPT=test:integration

lint:
	$(MAKE) run SCRIPT=lint

analyse:
	$(MAKE) run SCRIPT=analyse

md:
	$(MAKE) run SCRIPT=md

rector:
	$(MAKE) run SCRIPT=rector
