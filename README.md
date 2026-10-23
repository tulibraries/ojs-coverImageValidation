# Cover Image Validation for OJS 3.4.0-9

Custom journal-level generic plugin. No OJS core changes. Registering the
`Publication::validate` hook checks new publication cover images, including
unfinished submissions and direct API updates, before public storage.

## Policy

- Permit actual JPEG, PNG, and GIF images whose stored MIME, detected MIME,
  image header, and GD decoding agree.
- Reject HTML, PDF, SVG, other formats, invalid images, and spoofed extensions.
- Maximum file size: 5 MiB (5,242,880 bytes).
- Maximum pixel count: 4,000,000 (for example, 2000 x 2000).
- Respect temporary-file ownership. Reject replacement filenames submitted
  without an owned, validated temporary upload.
- Permit unchanged existing covers, alt-text edits, cover removal, and metadata
  updates without a cover upload.
- Missing PHP GD/Fileinfo rejects new covers rather than accepting unchecked files.

Limits are constants in `CoverImageValidator.php`. There is no settings form.
The plugin is enabled per journal. It does not scan existing files, validate
ordinary manuscripts/galleys, control profile images, or block temporary uploads.
It is not a malware scanner or an image sanitizer; accepted images are not
re-encoded. This protection targets the reproduced publication API path. Other
imports, direct repository calls that bypass validation, and unrelated upload
paths need separate review. Other plugins must not erase these validation errors.

## Install on restricted QA

1. Confirm QA uses OJS 3.4.0-9. Review the source and keep the existing backups
   and traffic restrictions. Later OJS versions require their own compatibility test.
2. Extract the supplied archive from the OJS root:

   ```bash
   tar -xzf /path/to/coverImageValidation-1.0.0.tar.gz -C plugins/generic
   ```

   The resulting path must be
   `plugins/generic/coverImageValidation/CoverImageValidationPlugin.php`.
   Do not extract a second nesting layer. No Composer install or database migration
   is required. Keep code owned by the normal deployment owner and readable by
   PHP-FPM; do not grant broad write access.
3. Check syntax using the PHP version used by the application:

   ```bash
   php -l plugins/generic/coverImageValidation/CoverImageValidationPlugin.php
   php -l plugins/generic/coverImageValidation/CoverImageValidator.php
   ```

4. Check GD and Fileinfo are enabled in PHP-FPM (not merely CLI). A CLI check is
   useful but not conclusive if the configurations differ:

   ```bash
   php -r 'echo "GD: ", extension_loaded("gd") ? "yes" : "no", PHP_EOL; echo "Fileinfo: ", extension_loaded("fileinfo") ? "yes" : "no", PHP_EOL;'
   ```

5. In the test journal, open Settings > Website > Plugins > Installed Plugins.
   Under Generic Plugins, enable **Cover Image Validation**. No settings link is
   expected. If absent, check PHP-FPM errors and that the folder name/case matches.
   For a manual deployment the OJS plugin loader discovers the plugin on disk.
6. If you previously applied the core validation patch discussed in chat, restore
   the verified original core file on QA before testing this plugin. Otherwise
   a core rejection could be mistaken for plugin protection. Do not overwrite
   unrelated customizations. Existing unrelated profile-image changes do not
   need to be removed for this test.

## Required QA acceptance tests

Use a disposable unfinished submission and an ordinary Author account, without
manually granting extra permissions. The previous reproduction used QA journal
`tj`, ID 11, and draft submission 836 / publication 1255. Use a fresh draft if
those records were deleted. Do not run the reproduction on production.

1. Repeat the harmless HTML upload-and-attach test. Temporary POST can return
   200; the publication PUT must return 400 with this plugin's cover-image error.
2. Record the metadata before the request. Verify failed requests do not change
   the saved cover and do not create a new public HTML file. Old files from the
   prior reproduction may remain; remove or quarantine those known fixtures
   before measuring new file creation.
3. Repeat with a PDF and HTML renamed with a `.png` extension: reject the cover.
4. Upload real PNG, JPEG, and GIF covers within the limits: accept. For the same
   browser-console test replace its file construction with:

   ```javascript
   const canvas = document.createElement('canvas');
   canvas.width = canvas.height = 32;
   canvas.getContext('2d').fillRect(0, 0, 32, 32);
   const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
   const file = new File([blob], 'qa-valid-cover.png', {type: 'image/png'});
   ```

   Keep the original `FormData` POST and publication PUT logic. Use small, known
   local image fixtures for JPEG/GIF comparisons.
5. Test alt-text edits, removing the cover, and normal metadata edits without a
   new cover. Test a normal manuscript PDF and any HTML galley workflow you use.
6. Confirm limits reject an oversized image. Test another supported locale if
   the journal is multilingual.
7. In restricted QA only, disable the plugin and confirm the harmless draft
   reproduction succeeds; re-enable and confirm it fails. That verifies the
   plugin is the active protection. Remove the known harmless public HTML after
   the comparison and leave the plugin enabled.

Enable and verify in every affected production journal only after QA passes.
Repeat a controlled harmless negative/positive test on a disposable record after
deployment. Keep upstream OJS/PHP updates and the existing incident response
separate: this plugin does not establish that an installation is clean.

## Rollback

Disable **Cover Image Validation** in the affected journal's Installed Plugins.
That removes this validation on subsequent requests; it also removes this
protection, so retain containment restrictions. No data migration to undo.
Delete the plugin directory only after disabling it everywhere.

## Validation performed during development

Written against the OJS 3.4.0-9 source and its pinned pkp-lib commit
`f968cdafe19b256df0d649ebcc43399bfb695fee`. The included development harness uses
that release's real Hook dispatcher, with stand-ins for accounts, plugin enable
settings, publications, and temporary-file storage. It exercises file validation,
ownership, hook error propagation, existing errors, locales, and filename changes.
It is not a live OJS/database or browser integration test. QA acceptance testing
above remains required. See `DEVELOPMENT.md` for running the harness.
