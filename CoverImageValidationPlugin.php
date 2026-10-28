<?php

namespace APP\plugins\generic\coverImageValidation;

use APP\core\Application;
use PKP\file\TemporaryFileManager;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

require_once __DIR__ . '/CoverImageValidator.php';
require_once __DIR__ . '/CoverProposalService.php';

class CoverImageValidationPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        // Mandatory protection: register regardless of saved enabled settings.
        if ($success) {
            Hook::add('Publication::validate', [$this, 'validateCoverImage']);
            Hook::add('LoadHandler', [$this, 'loadReviewHandler']);
            Hook::add('TemplateManager::display', [$this, 'addReviewLink']);
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

    /** Load the private review page without modifying OJS core routing files. */
    public function loadReviewHandler($hookName, $args)
    {
        $page = & $args[0];
        $handler = & $args[3];
        if (($page ?? null) !== 'coverImageValidation') {
            return false;
        }

        require_once __DIR__ . '/CoverImageReviewHandler.php';
        $handler = new CoverImageReviewHandler();
        return true;
    }

    /**
     * Add a small action to the existing workflow page. The workflow page is
     * rendered by Vue, so an inline backend script is less invasive than
     * replacing the core workflow template.
     */
    public function addReviewLink($hookName, $args)
    {
        $templateManager = $args[0] ?? null;
        $template = $args[1] ?? null;
        if (!$templateManager || $template !== 'workflow/workflow.tpl') {
            return false;
        }

        $request = Application::get()->getRequest();
        $submission = $templateManager->getTemplateVars('submission');
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$submission || !CoverProposalService::canReview($user, $context)) {
            return false;
        }

        $service = new CoverProposalService();
        if (empty($service->getCandidates($submission))) {
            return false;
        }

        $url = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            'coverImageValidation',
            'index',
            null,
            ['submissionId' => $submission->getId(), 'publicationId' => $submission->getLatestPublication()->getId()]
        );
        $label = json_encode(__('plugins.generic.coverImageValidation.reviewCoverImages'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $description = json_encode(__('plugins.generic.coverImageValidation.reviewCoverImagesDescription'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $safeUrl = json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $templateManager->addJavaScript(
            'coverImageValidationReviewLink',
            "(function () {\n"
            . "  function addCoverReviewLink() {\n"
            . "    var target = document.querySelector('#submissionWorkflow');\n"
            . "    if (!target || document.querySelector('.coverImageValidationReview')) return;\n"
            . "    var panel = document.createElement('div');\n"
            . "    panel.className = 'coverImageValidationReview pkp_notification';\n"
            . "    panel.style.marginBottom = '2rem';\n"
            . "    var heading = document.createElement('strong'); heading.textContent = " . $label . ";\n"
            . "    var description = document.createElement('p'); description.textContent = " . $description . ";\n"
            . "    var link = document.createElement('a'); link.className = 'pkp_button'; link.href = " . $safeUrl . "; link.textContent = " . $label . ";\n"
            . "    panel.append(heading, description, link);\n"
            . "    target.insertBefore(panel, target.firstChild);\n"
            . "  }\n"
            . "  document.addEventListener('DOMContentLoaded', addCoverReviewLink);\n"
            . "  window.setTimeout(addCoverReviewLink, 500);\n"
            . "  window.setTimeout(addCoverReviewLink, 1500);\n"
            . "})();",
            [
                'priority' => \APP\template\TemplateManager::STYLE_SEQUENCE_LATE,
                'contexts' => ['backend'],
                'inline' => true,
            ]
        );
        return false;
    }
}
