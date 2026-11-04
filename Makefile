SHELL := /bin/sh

PHP ?= php
TAR ?= tar
PLUGIN_NAME := coverImageValidation
VERSION := $(shell sed -n 's:.*<release>\([^<]*\)</release>.*:\1:p' version.xml | head -n 1)
DIST_DIR ?= dist
PACKAGE := $(DIST_DIR)/$(PLUGIN_NAME)-$(VERSION).tar.gz

PHP_FILES := $(shell find . -type f -name '*.php' \
	-not -path './.git/*' \
	-not -path './$(DIST_DIR)/*' \
	-print)

.PHONY: all build check lint test test-missing package clean help

all: check

build: package

check: lint test test-missing

lint:
	@set -e; \
	for file in $(PHP_FILES); do \
		$(PHP) -l "$$file" >/dev/null; \
	done
	@echo "PHP syntax checks passed."

test:
	$(PHP) tests/run.php

test-missing:
	TEST_MISSING_EXTENSIONS=1 $(PHP) -n tests/run.php

package: check
	@mkdir -p "$(DIST_DIR)"
	@rm -rf "$(DIST_DIR)/$(PLUGIN_NAME)"
	@mkdir -p "$(DIST_DIR)/$(PLUGIN_NAME)"
	@$(TAR) -cf - \
		--exclude='./.git' \
		--exclude='./.github' \
		--exclude='./tests' \
		--exclude='./$(DIST_DIR)' \
		. | $(TAR) -xf - -C "$(DIST_DIR)/$(PLUGIN_NAME)"
	@$(TAR) -czf "$(PACKAGE)" -C "$(DIST_DIR)" "$(PLUGIN_NAME)"
	@rm -rf "$(DIST_DIR)/$(PLUGIN_NAME)"
	@echo "Created $(PACKAGE)"

clean:
	rm -rf "$(DIST_DIR)"

help:
	@echo "make check         Run syntax and regression checks"
	@echo "make lint          Check PHP syntax"
	@echo "make test          Run the PHP regression harness"
	@echo "make test-missing  Verify fail-closed behavior without PHP extensions"
	@echo "make package       Build an OJS-ready release archive"
	@echo "make clean         Remove generated release artifacts"
