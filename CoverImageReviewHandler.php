<?php

namespace APP\plugins\generic\coverImageValidation;

use APP\core\Application;
use APP\core\Request;
use APP\core\Services;
use APP\facades\Repo;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use APP\handler\Handler;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\internal\SubmissionRequiredPolicy;
use PKP\security\authorization\internal\UserAccessibleWorkflowStageRequiredPolicy;
use PKP\security\Role;

class CoverImageReviewHandler extends Handler
{
    public $_isBackendPage = true;

    public function __construct()
    {
        parent::__construct();
        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
            ['index', 'approve', 'reject', 'preview']
        );
    }

    public function authorize($request, &$args, $roleAssignments)
    {
        // Reuse OJS's submission and editorial-workflow authorization before
        // the plugin exposes a private file or changes publication metadata.
        $this->addPolicy(new SubmissionRequiredPolicy($request, $args, 'submissionId'));
        $this->addPolicy(new UserAccessibleWorkflowStageRequiredPolicy(
            $request,
            PKPApplication::WORKFLOW_TYPE_EDITORIAL
        ));

        return parent::authorize($request, $args, $roleAssignments);
    }

    public function index($args, Request $request)
    {
        $submission = $this->getSubmission($request);
        $publication = $this->getPublication($submission, $request);
        if (!$publication) {
            $request->getDispatcher()->handle404();
            return;
        }

        $service = new CoverProposalService();
        $context = $request->getContext();
        $locales = $context ? $context->getSupportedSubmissionLocales() : [];
        $reviewUrl = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            'coverImageValidation',
            'index',
            null,
            ['submissionId' => $submission->getId(), 'publicationId' => $publication->getId()]
        );

        $candidates = [];
        foreach ($service->getCandidates($submission) as $candidate) {
            $file = $candidate['file'];
            $previewUrl = $request->getDispatcher()->url(
                $request,
                Application::ROUTE_PAGE,
                $context->getPath(),
                'coverImageValidation',
                'preview',
                null,
                ['submissionId' => $submission->getId(), 'fileId' => $file->getId()]
            );
            $approveUrl = $request->getDispatcher()->url(
                $request,
                Application::ROUTE_PAGE,
                $context->getPath(),
                'coverImageValidation',
                'approve',
                null,
                ['submissionId' => $submission->getId(), 'publicationId' => $publication->getId()]
            );
            $rejectUrl = $request->getDispatcher()->url(
                $request,
                Application::ROUTE_PAGE,
                $context->getPath(),
                'coverImageValidation',
                'reject',
                null,
                ['submissionId' => $submission->getId(), 'publicationId' => $publication->getId()]
            );
            $candidates[] = [
                'id' => $file->getId(),
                'name' => $candidate['name'],
                'mime' => $candidate['mime'],
                'previewUrl' => $previewUrl,
                'approveUrl' => $approveUrl,
                'rejectUrl' => $rejectUrl,
                'altText' => (string) ($publication->getData('coverImage', $context->getPrimaryLocale())['altText'] ?? ''),
            ];
        }

        $plugin = PluginRegistry::getPlugin('generic', 'coverImageValidation');
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'coverReviewCandidates' => $candidates,
            'coverReviewLocales' => $locales,
            'coverReviewSubmission' => $submission,
            'coverReviewPublication' => $publication,
            'coverReviewPrimaryLocale' => $context->getPrimaryLocale(),
            'coverReviewError' => $request->getUserVar('error'),
            'coverReviewUrl' => $reviewUrl,
            'pageTitle' => __('plugins.generic.coverImageValidation.reviewCoverImages'),
            'pageWidth' => TemplateManager::PAGE_WIDTH_WIDE,
        ]);
        $templateMgr->display($plugin->getTemplateResource('review.tpl'));
    }

    public function preview($args, Request $request): void
    {
        $submission = $this->getSubmission($request);
        $file = (new CoverProposalService())->getFile(
            $submission->getId(),
            (int) $request->getUserVar('fileId')
        );
        if (!$file || !(new CoverProposalService())->isCandidate($file)) {
            $request->getDispatcher()->handle404();
            return;
        }

        $filename = Services::get('file')->formatFilename(
            $file->getData('path'),
            (string) ($file->getLocalizedData('name') ?: basename((string) $file->getData('path')))
        );
        Services::get('file')->download($file->getData('fileId'), $filename, true);
    }

    public function approve($args, Request $request): void
    {
        if (!$request->checkCSRF()) {
            http_response_code(403);
            return;
        }

        $submission = $this->getSubmission($request);
        $file = (new CoverProposalService())->getFile(
            $submission->getId(),
            (int) $request->getUserVar('fileId')
        );
        $publication = $this->getPublication($submission, $request);
        if (!$file || !$publication) {
            $request->getDispatcher()->handle404();
            return;
        }

        $context = $request->getContext();
        $locale = (string) $request->getUserVar('locale');
        $locales = $context->getSupportedSubmissionLocales();
        if (!array_key_exists($locale, $locales)) {
            $locale = $context->getPrimaryLocale();
        }
        $altText = trim((string) $request->getUserVar('altText'));

        try {
            (new CoverProposalService())->approve(
                $file,
                $publication,
                $locale,
                $altText,
                (int) $request->getUser()->getId()
            );
        } catch (\Throwable $error) {
            error_log('Cover image approval failed: ' . $error->getMessage());
            $request->redirect(
                $context->getPath(),
                'coverImageValidation',
                'index',
                null,
                [
                    'submissionId' => $submission->getId(),
                    'publicationId' => $publication->getId(),
                    'error' => 'plugins.generic.coverImageValidation.approvalFailed',
                ]
            );
            return;
        }

        $request->redirect(
            $context->getPath(),
            'coverImageValidation',
            'index',
            null,
            ['submissionId' => $submission->getId(), 'publicationId' => $publication->getId()]
        );
    }

    public function reject($args, Request $request): void
    {
        if (!$request->checkCSRF()) {
            http_response_code(403);
            return;
        }

        $submission = $this->getSubmission($request);
        $service = new CoverProposalService();
        $file = $service->getFile(
            $submission->getId(),
            (int) $request->getUserVar('fileId')
        );
        if (!$file || !$service->isCandidate($file)) {
            $request->getDispatcher()->handle404();
            return;
        }
        $service->reject($file);

        $context = $request->getContext();
        $request->redirect(
            $context->getPath(),
            'coverImageValidation',
            'index',
            null,
            ['submissionId' => $submission->getId(), 'publicationId' => $request->getUserVar('publicationId')]
        );
    }

    private function getSubmission(Request $request)
    {
        $submission = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);
        if (!$submission) {
            $request->getDispatcher()->handle404();
        }
        return $submission;
    }

    private function getPublication($submission, Request $request)
    {
        $publicationId = filter_var($request->getUserVar('publicationId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $publicationId
            ? Repo::publication()->get($publicationId, $submission->getId())
            : $submission->getLatestPublication();
    }
}
