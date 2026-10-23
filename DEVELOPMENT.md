# Development and local checks

PHP 8.1+ with GD and Fileinfo is required. The plugin is intended for OJS
3.4.0-9; PHP version compatibility is not a claim of compatibility with every
OJS version that runs on that PHP release.

The CLI-only development harness is in this plugin's `tests` directory. It
returns HTTP 404 and exits if requested through the web. Run from this plugin's
directory. It requires a trusted
checkout of OJS 3.4.0-9 with its lib/pkp submodule initialized:

```bash
OJS_SOURCE=/path/to/ojs-3.4.0-9 php tests/run.php
TEST_MISSING_EXTENSIONS=1 OJS_SOURCE=/path/to/ojs-3.4.0-9 php -n tests/run.php
```

The second command verifies refusal of new covers without decoder extensions.
The harness does not modify OJS source or connect to a database. Fixtures are
created in a private temporary directory and removed afterward.

## Automated checks on GitHub

`.github/workflows/tests.yml` runs syntax checks and the test harness on every
push and pull request, and supports manual runs from the Actions tab. The matrix
uses PHP 8.1 and 8.3 with GD and Fileinfo, then repeats the missing-extension
check with `php -n`. Each run checks out only the pkp-lib source pinned by OJS
3.4.0-9, so the Hook dispatcher cannot silently change with an upstream branch.

The regression suite includes rejected HTML replacements, mixed-language covers
(including reversed locale order), and requests without an authenticated file
owner. The valid-image tests ensure image uploads remain supported. The site-wide tests use the real OJS LazyLoadPlugin and GenericPlugin classes
to verify mandatory protection across current and future journals, even with
disabled or absent settings, and refusal of direct disable calls. Descriptor
checks verify site-wide non-lazy loading. Base plugin
settings, account, storage, and publication objects are stand-ins; this is not a complete OJS API
or draft-permission integration test. Keep the browser-based QA draft test in
README.md as the deployment acceptance check.

To obtain just the dependency used in CI, from the plugin checkout:

```bash
git clone --no-checkout https://github.com/pkp/pkp-lib.git .test-deps/ojs/lib/pkp
git -C .test-deps/ojs/lib/pkp checkout f968cdafe19b256df0d649ebcc43399bfb695fee
OJS_SOURCE="$PWD/.test-deps/ojs" php tests/run.php
TEST_MISSING_EXTENSIONS=1 OJS_SOURCE="$PWD/.test-deps/ojs" php -n tests/run.php
```
