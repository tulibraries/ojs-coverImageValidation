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

The regression suite includes direct-cover editorial-role enforcement, rejected
HTML/PDF replacements, mixed-language covers (including reversed locale order),
and requests without an authenticated file owner. The valid-image tests ensure
normal submission artwork can still be validated. The approval page uses OJS's
database, private file service, and publication repository, so its final
approve/reject behavior requires the browser-based QA test in `README.md` (or
an OJS integration-test environment); the repository harness does not connect
to an OJS database.
