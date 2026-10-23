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
