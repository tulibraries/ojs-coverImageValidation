<?php

namespace APP\plugins\generic\coverImageValidation;

use APP\core\Application;
use PKP\file\TemporaryFileManager;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

require_once __DIR__ . '/CoverImageValidator.php';

class CoverImageValidationPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        // Mandatory protection: register regardless of saved enabled settings.
        if ($success) {
            Hook::add('Publication::validate', [$this, 'validateCoverImage']);
        }
        return $success;
    }

    public function isSitePlugin()
    {
        return true;
    }

    /** Mandatory protection has no journal or site-level off switch. */
    public function getEnabled($contextId = null)
    {
        return true;
    }

    public function getCanEnable()
    {
        return false;
    }

    public function getCanDisable()
    {
        return false;
    }

    public function setEnabled($enabled)
    {
        // Also resist direct calls that bypass the plugin-grid capability check.
        parent::setEnabled(true);
    }

    public function getInstallSitePluginSettingsFile()
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    public function getDisplayName()
    {
        return __('plugins.generic.coverImageValidation.name');
    }

    public function getDescription()
    {
        return __('plugins.generic.coverImageValidation.description');
    }

    /** OJS 3.4 Hook::call supplies one array, whose errors entry is a reference. */
    public function validateCoverImage($hookName, $args)
    {
        $errors =& $args[0];
        $publication = $args[1];
        $props = $args[2];

        if (!array_key_exists('coverImage', $props) || $props['coverImage'] === null) {
            return false;
        }
        if (!is_array($props['coverImage'])) {
            $errors['coverImage'][] = __('plugins.generic.coverImageValidation.invalid');
            return false;
        }

        $request = Application::get()->getRequest();
        $user = $request->getUser();
        $context = $request->getContext();
        $canUploadCover = $user && $context && $user->hasRole(
            [
                Role::ROLE_ID_MANAGER,
                Role::ROLE_ID_SUB_EDITOR,
            ],
            $context->getId()
        );
        $manager = new TemporaryFileManager();
        $validator = new CoverImageValidator();

        foreach ($props['coverImage'] as $locale => $cover) {
            if ($cover === null) {
                continue; // Permit deletion without a new upload.
            }
            $failure = null;
            if (!is_array($cover)) {
                $failure = 'plugins.generic.coverImageValidation.invalid';
            } elseif (array_key_exists('temporaryFileId', $cover)) {
                if (!$canUploadCover) {
                    $failure = 'plugins.generic.coverImageValidation.editorRequired';
                } else {
                    $id = filter_var(
                        $cover['temporaryFileId'],
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1]]
                    );

                    // Never retrieve another user's temporary file.
                    $file = $id && $user
                        ? $manager->getFile($id, $user->getId())
                        : null;

                    $failure = $file
                        ? $validator->validate($file->getFilePath(), (string) $file->getFileType())
                        : 'plugins.generic.coverImageValidation.invalid';
                }
            } else {
                // Permit an unchanged existing cover (including alt-text edits),
                // but never let a client choose a different server-side filename.
                $saved = $publication ? $publication->getData('coverImage', $locale) : null;
                if (!empty($cover) && (
                    !is_array($saved)
                    || empty($saved['uploadName'])
                    || ($cover['uploadName'] ?? null) !== $saved['uploadName']
                )) {
                    $failure = 'plugins.generic.coverImageValidation.newUploadRequired';
                }
            }

            if ($failure) {
                // Preserve existing core and plugin errors.
                if (!isset($errors['coverImage'])) {
                    $errors['coverImage'] = [];
                }
                if (!isset($errors['coverImage'][$locale])) {
                    $errors['coverImage'][$locale] = [];
                }
                if (is_array($errors['coverImage'][$locale])) {
                    $errors['coverImage'][$locale][] = __($failure);
                }
            }
        }
        return false; // Continue the hook chain.
    }
}
