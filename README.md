# Cover Image Validation for OJS 3.4.0-9

Custom **site-wide** generic plugin, version 1.4.0.0. No OJS core changes.
Authors upload proposed artwork as an ordinary private submission file. An
editor opens the submission workflow's **Review cover artwork** action, previews
the file, and clicks **Approve as cover**. Only then does OJS move a revalidated
copy into public cover storage and update the publication metadata.

## Coverage and limits

- Permit actual JPEG, PNG, and GIF images whose stored MIME, detected MIME,
  image header, and GD decoding agree.
- Reject HTML, PDF, SVG, unsupported formats, invalid images, and spoofed extensions.
- Maximum file size: 5 MiB (5,242,880 bytes).
- Maximum pixel count: 4,000,000 (for example, 2000 x 2000).
- Respect temporary-file ownership and reject replacement filenames submitted
  without an owned, validated temporary upload.
- Reject direct author/API cover attachments; the normal submission-file path
  remains private until an editor approves it.
- Require a journal manager or editor/section-editor role in the current
  journal for the review page, preview, approval, and rejection actions.
- Permit unchanged existing covers, alt-text edits, cover removal, and metadata
  updates without a cover upload.
- Missing PHP GD/Fileinfo rejects new covers instead of accepting unchecked files.

Limits are constants in `CoverImageValidator.php`. There is no settings form.
Protection is mandatory for **every existing and future journal**. OJS enable/
disable controls are unavailable, and direct disable calls cannot turn it off.
Saved site/journal enabled settings do not govern validation. Non-lazy loading
in version.xml ensures OJS loads the plugin even when a saved setting is false.
A server administrator can still remove/replace the code or uninstall it; this
is not protection against administrators who control the deployment.

Rejected proposals remain in the submission's private file history but are
removed from this plugin's review queue. Authors can upload a new revision or a
new submission file. Replacing an already approved cover requires a fresh
approval; the existing public cover is not changed while a proposal is pending.

This does not scan or clean existing files, validate manuscripts/galleys or
profile images, or prevent uploads into temporary storage. It is not a malware
scanner or image sanitizer; accepted images are not re-encoded. It targets the
publication validation path and this plugin's review path. Imports or
repository calls that bypass validation and other upload paths need separate
review. Other plugins must not erase its validation errors.

## Install or upgrade on restricted QA

1. Back up the current plugin and database. Keep existing traffic restrictions.
   This release targets OJS 3.4.0-9; later releases require compatibility testing.
2. Extract the archive from the OJS root:

   ```bash
   tar -xzf /path/to/coverImageValidation-1.4.0.tar.gz -C plugins/generic
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
   No custom database migration is required; review status uses OJS's existing
   submission-file settings table. OJS records version 1.4.0.0, with site-wide,
   non-lazy loading. Existing enabled settings are harmless.
5. In the QA database, verify:

   ```sql
   SELECT product, major, minor, revision, build, `current`, sitewide, lazy_load
   FROM versions
   WHERE product = 'coverImageValidation' AND `current` = 1;
   ```

   Expect current version **1.4.0.0**, `sitewide=1`, and `lazy_load=0`.
   Registration is essential: replacing PHP files alone leaves the old loading
   metadata in the database. There is no enable checkbox to select.
6. Verify GD and Fileinfo are enabled in PHP-FPM, not only the CLI. If you
   previously applied a core validation patch, restore the verified original
   core file on QA before testing, preserving unrelated customizations. The
   plugin itself requires no core edits or Composer installation.

## QA acceptance tests

Use an ordinary Author account and a disposable unfinished submission without
manually granting extra permissions. The previous reproduction used QA journal
`tj` (ID 11), draft 836 / publication 1255; use a fresh draft if those were deleted.

1. As an Author, upload a genuine PNG as a normal submission file. Verify it
   remains private and cannot be attached directly as a cover; the publication
   request must return **400 with the editorial-review error**.
2. As a journal Manager or Editor/Section Editor, open that submission's
   workflow page and click **Review cover artwork**. Preview the private image,
   then click **Approve as cover** and verify that it becomes the publication
   cover.
3. Reject another image and verify it disappears from the review queue without
   creating a public file. Verify the previous approved cover remains unchanged
   while a replacement is pending or rejected.
4. Verify an editor whose role exists only in another journal cannot open,
   preview, approve, or reject this journal's proposals.
5. Test genuine PNG, JPEG, and GIF files within limits. HTML, PDF, SVG, and
   HTML renamed `.png` with `image/png` must never appear as proposals or become
   covers. Example PNG
   construction for the browser-console test:

   ```javascript
   const canvas = document.createElement('canvas');
   canvas.width = canvas.height = 32;
   canvas.getContext('2d').fillRect(0, 0, 32, 32);
   const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
   const file = new File([blob], 'qa-valid-cover.png', {type: 'image/png'});
   ```

6. Test cover removal, alt text, ordinary metadata, manuscript PDFs, and any HTML
   galley workflow in use. Test a second supported locale and image size limits.
7. **Repeat the direct-API rejection and review approval in a second QA journal
   that has never enabled the plugin individually.** This verifies global coverage.
8. Verify that Installed Plugins offers no disable control, including for a
   site administrator. Confirm HTML remains rejected in a second journal.

After QA passes, repeat the install/upgrade and database verification on
production. This change does not establish that the incident is fully resolved.

## Rollback

Rollback requires server/deployment access: keep traffic restrictions in place,
restore a reviewed previous plugin release, and register that release through
OJS's version process. Restoring PHP files alone does not revert the database
loading metadata. Removing/uninstalling this plugin removes cover validation.

## Development validation

Written for OJS 3.4.0-9. The repository-local harness exercises the plugin with
small stand-ins for OJS's Hook, plugin, account, storage, and publication
interfaces. These are not live OJS/database/browser integration tests. See
`DEVELOPMENT.md` and `TEST-RESULTS.txt`; live QA acceptance remains required.
