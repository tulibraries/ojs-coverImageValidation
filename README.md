# Cover Image Validation for OJS 3.4.0-9

Custom **site-wide** generic plugin, version 1.1.0.0. No OJS core changes.
Uses `Publication::validate` to check new publication cover images, including
unfinished submissions and direct API updates, before public storage.

## Coverage and limits

- Permit actual JPEG, PNG, and GIF images whose stored MIME, detected MIME,
  image header, and GD decoding agree.
- Reject HTML, PDF, SVG, unsupported formats, invalid images, and spoofed extensions.
- Maximum file size: 5 MiB (5,242,880 bytes).
- Maximum pixel count: 4,000,000 (for example, 2000 x 2000).
- Respect temporary-file ownership and reject replacement filenames submitted
  without an owned, validated temporary upload.
- Permit unchanged existing covers, alt-text edits, cover removal, and metadata
  updates without a cover upload.
- Missing PHP GD/Fileinfo rejects new covers instead of accepting unchecked files.

Limits are constants in `CoverImageValidator.php`. There is no settings form.
The site-level enabled setting (context 0) applies to **every existing and future
journal**. Old per-journal enabled settings are ignored by the plugin's runtime
check. Site administrators control the global switch; this is not an always-on
plugin that bypasses OJS's enable/disable mechanism.

This does not scan or clean existing files, validate manuscripts/galleys or
profile images, or prevent uploads into temporary storage. It is not a malware
scanner or image sanitizer; accepted images are not re-encoded. It targets the
reproduced publication validation path. Imports or repository calls that bypass
validation and other upload paths need separate review. Other plugins must not
erase its validation errors.

## Install or upgrade on restricted QA

1. Back up the current plugin and database. Keep existing traffic restrictions.
   This release targets OJS 3.4.0-9; later releases require compatibility testing.
2. Extract the archive from the OJS root:

   ```bash
   tar -xzf /path/to/coverImageValidation-1.1.0.tar.gz -C plugins/generic
   ```

   The folder must remain `plugins/generic/coverImageValidation`, even if your
   GitHub repository is named `ojs-coverImageValidation`. Preserve the normal
   deployment owner, read permissions, and SELinux labeling for plugin files.
3. Check syntax using the application's PHP version:

   ```bash
   php -l plugins/generic/coverImageValidation/CoverImageValidationPlugin.php
   php -l plugins/generic/coverImageValidation/CoverImageValidator.php
   ```

4. **Register the new version through OJS.** Merely replacing the files does not
   update the old version's `sitewide` database flag. Run from the OJS root:

   ```bash
   sudo -u apache php lib/pkp/tools/installPluginVersion.php \
     plugins/generic/coverImageValidation/version.xml
   ```

   Use your deployment's PHP executable and application user if different.
   Run once for this new version; if it reports an error, stop and investigate.
   No custom database migration is required. OJS records version 1.1.0.0 and
   installs the default site-level `enabled=1` when no site setting exists.
   A previously explicit site-level disable is preserved.
5. In the QA database, verify:

   ```sql
   SELECT product, major, minor, revision, build, `current`, sitewide
   FROM versions
   WHERE product = 'coverImageValidation' AND `current` = 1;

   SELECT plugin_name, context_id, setting_value AS enabled
   FROM plugin_settings
   WHERE plugin_name = 'coverimagevalidationplugin'
     AND setting_name = 'enabled' AND context_id = 0;
   ```

   Expect current version **1.1.0.0**, `sitewide=1`, and a context-0 enabled value
   of `1`. A setting for journal 11 alone is no longer enough. If context 0 is
   explicitly disabled, log in as a site administrator and enable the site-wide
   plugin from Installed Plugins. Do not assume installation succeeded based
   only on the presence of its files.
6. Verify GD and Fileinfo are enabled in PHP-FPM, not only the CLI. If you
   previously applied a core validation patch, restore the verified original
   core file on QA before testing, preserving unrelated customizations. The
   plugin itself requires no core edits or Composer installation.

## QA acceptance tests

Use an ordinary Author account and a disposable unfinished submission without
manually granting extra permissions. The previous reproduction used QA journal
`tj` (ID 11), draft 836 / publication 1255; use a fresh draft if those were deleted.

1. Repeat the harmless HTML upload-and-attach test: temporary POST may return
   200, but publication PUT must return **400 with the cover-image error**.
2. Repeat with HTML renamed `.png` and claiming `image/png`: reject the cover.
3. Verify no new public HTML is created and that rejection preserves the current
   cover metadata. Remove/quarantine old reproduction fixtures first so an old
   file is not mistaken for a new successful upload.
4. Test genuine PNG, JPEG, and GIF covers within limits: accept. Example PNG
   construction for the browser-console test:

   ```javascript
   const canvas = document.createElement('canvas');
   canvas.width = canvas.height = 32;
   canvas.getContext('2d').fillRect(0, 0, 32, 32);
   const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
   const file = new File([blob], 'qa-valid-cover.png', {type: 'image/png'});
   ```

5. Test cover removal, alt text, ordinary metadata, manuscript PDFs, and any HTML
   galley workflow in use. Test a second supported locale and image size limits.
6. **Repeat the HTML rejection and valid-image acceptance in a second QA journal
   that has never enabled the plugin individually.** This verifies global coverage.
7. In restricted QA only, disable the global plugin to confirm the harmless
   draft reproduction succeeds, then re-enable and confirm rejection. Clean up
   the harmless public file produced by that comparison. Leave global protection
   enabled before returning to normal operations.

After QA passes, repeat the install/upgrade and database verification on
production. This change does not establish that the incident is fully resolved.

## Rollback

A site administrator can disable the site-wide plugin. That removes validation
for **all journals**, so keep containment restrictions in place. To revert to
1.0's journal-level behavior, restore the old code and use OJS's version update
process under a reviewed rollback plan; restoring PHP files alone does not
revert the database's site-wide metadata or site settings.

## Development validation

Written against OJS 3.4.0-9 and its pinned pkp-lib commit
`f968cdafe19b256df0d649ebcc43399bfb695fee`. The isolated harness exercises the
real Hook dispatcher, LazyLoadPlugin, and GenericPlugin classes, with stand-ins
for base plugin settings, accounts, publications, and temporary files. These
are not live OJS/database/browser integration tests. See `DEVELOPMENT.md` and
`TEST-RESULTS.txt`; live QA acceptance remains required.
