# Development and local checks

PHP 8.1+ with GD and Fileinfo is required. The plugin is intended for OJS
3.4.0-9; PHP version compatibility is not a claim of compatibility with every
OJS version that runs on that PHP release.

The CLI-only development harness is in this plugin's `tests` directory. It
returns HTTP 404 and exits if requested through the web. It uses local test
doubles for the small OJS interfaces exercised by this plugin, so it runs from
this repository without an OJS checkout:

```bash
php tests/run.php
TEST_MISSING_EXTENSIONS=1 php -n tests/run.php
```

The second command verifies refusal of new covers without decoder extensions.
The harness does not modify OJS source or connect to a database. Fixtures are
created in a private temporary directory and removed afterward.

## Automated checks on GitHub

`.github/workflows/tests.yml` runs syntax checks and the test harness on every
push and pull request, and supports manual runs from the Actions tab. The matrix
uses PHP 8.1 and 8.3 with GD and Fileinfo, then repeats the missing-extension
check with `php -n`.

The regression suite includes editorial-role enforcement, rejected HTML/PDF
replacements, mixed-language covers (including reversed locale order), and
requests without an authenticated file owner. The valid-image tests ensure
editorial image uploads remain supported. Local Hook, plugin, account, storage,
and publication doubles cover the interfaces used by this plugin. This is not
a complete OJS API or draft-permission integration test. Keep the browser-based
QA draft test in README.md as the deployment acceptance check.
