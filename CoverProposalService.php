<?php

namespace APP\plugins\generic\coverImageValidation;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\config\Config;
use PKP\core\Core as PKPCore;
use PKP\db\DAORegistry;
use PKP\file\TemporaryFileManager;
use PKP\security\Role;
use PKP\submissionFile\SubmissionFile;

/**
 * Finds private submission images and publishes them only after editorial approval.
 */
class CoverProposalService
{
    public const STATUS_SETTING = 'coverImageValidationStatus';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_APPROVED = 'approved';

    private CoverImageValidator $validator;

    public function __construct(?CoverImageValidator $validator = null)
    {
        $this->validator = $validator ?? new CoverImageValidator();
    }

    public static function canReview($user, $context): bool
    {
        return $user && $context && $user->hasRole(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
            $context->getId()
        );
    }

    /**
     * Return valid, private submission-stage images awaiting a decision.
     *
     * Ordinary submission files remain ordinary submission files. The plugin
     * only treats supported images as candidates; no file is moved or exposed
     * publicly by this method.
     *
     * @return array<int,array{file: SubmissionFile, path: string, name: string, mime: string}>
     */
    public function getCandidates($submission): array
    {
        $candidates = [];
        $files = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_SUBMISSION])
            ->getMany();

        foreach ($files as $file) {
            if (in_array($this->getStatus($file), [self::STATUS_REJECTED, self::STATUS_APPROVED], true)) {
                continue;
            }

            $path = $this->getFilePath($file);
            if ($this->validator->validate($path, (string) $file->getData('mimetype')) !== null) {
                continue;
            }

            $name = (string) ($file->getLocalizedData('name') ?: basename((string) $file->getData('path')));
            $candidates[] = [
                'file' => $file,
                'path' => $path,
                'name' => $name,
                'mime' => (string) $file->getData('mimetype'),
            ];
        }

        return $candidates;
    }

    public function getFile($submissionId, $fileId): ?SubmissionFile
    {
        $file = Repo::submissionFile()->get((int) $fileId, (int) $submissionId);
        if (!$file || $file->getData('fileStage') !== SubmissionFile::SUBMISSION_FILE_SUBMISSION) {
            return null;
        }
        return $file;
    }

    public function getFilePath(SubmissionFile $file): string
    {
        $path = (string) $file->getData('path');
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }
        return rtrim((string) Config::getVar('files', 'files_dir'), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    public function isCandidate(SubmissionFile $file): bool
    {
        if ($file->getData('fileStage') !== SubmissionFile::SUBMISSION_FILE_SUBMISSION) {
            return false;
        }
        if (in_array($this->getStatus($file), [self::STATUS_REJECTED, self::STATUS_APPROVED], true)) {
            return false;
        }
        return $this->validator->validate(
            $this->getFilePath($file),
            (string) $file->getData('mimetype')
        ) === null;
    }

    public function reject(SubmissionFile $file): void
    {
        // Delete through OJS so the submission-file record and its private
        // stored file are cleaned up together. OJS preserves the physical
        // file when it is still shared by another submission-file revision.
        Repo::submissionFile()->delete($file);
    }

    /**
     * Publish a proposal through OJS's publication repository.
     *
     * @return mixed The refreshed publication.
     * @throws \RuntimeException when the source cannot be staged or validated.
     */
    public function approve(SubmissionFile $file, $publication, string $locale, string $altText, int $userId)
    {
        if (!$this->isCandidate($file)) {
            throw new \RuntimeException('The selected submission file is no longer a valid cover proposal.');
        }

        $temporaryFileId = $this->stageTemporaryFile($file, $userId);
        $params = [
            'coverImage' => [
                $locale => [
                    'temporaryFileId' => $temporaryFileId,
                    'altText' => $altText,
                ],
            ],
        ];

        try {
            $updatedPublication = Repo::publication()->edit($publication, $params);
        } catch (\Throwable $error) {
            // The temporary file is still private if OJS did not move it.
            $temporaryFileManager = new TemporaryFileManager();
            if ($temporaryFileManager->getFile($temporaryFileId, $userId)) {
                $temporaryFileManager->deleteById($temporaryFileId, $userId);
            }
            throw $error;
        }

        $this->setStatus($file, self::STATUS_APPROVED);
        return $updatedPublication;
    }

    private function getStatus(SubmissionFile $file): ?string
    {
        $value = DB::table('submission_file_settings')
            ->where('submission_file_id', $file->getId())
            ->where('setting_name', self::STATUS_SETTING)
            ->value('setting_value');

        if (!$value) {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded) || (int) ($decoded['fileId'] ?? 0) !== (int) $file->getData('fileId')) {
            return null;
        }
        return $decoded['status'] ?? null;
    }

    private function setStatus(SubmissionFile $file, string $status): void
    {
        DB::table('submission_file_settings')->updateOrInsert(
            [
                'submission_file_id' => $file->getId(),
                'locale' => '',
                'setting_name' => self::STATUS_SETTING,
            ],
            [
                'setting_value' => json_encode([
                    'status' => $status,
                    'fileId' => (int) $file->getData('fileId'),
                ]),
            ]
        );
    }

    private function stageTemporaryFile(SubmissionFile $file, int $userId): int
    {
        $sourcePath = $this->getFilePath($file);
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \RuntimeException('The private submission file is unavailable.');
        }

        $temporaryFileManager = new TemporaryFileManager();
        $temporaryPath = tempnam($temporaryFileManager->getBasePath(), 'cover-review-');
        if ($temporaryPath === false) {
            throw new \RuntimeException('The private submission file could not be staged.');
        }
        if (!copy($sourcePath, $temporaryPath)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('The private submission file could not be staged.');
        }

        $temporaryFile = DAORegistry::getDAO('TemporaryFileDAO')->newDataObject();
        $temporaryFile->setUserId($userId);
        $temporaryFile->setServerFileName(basename($temporaryPath));
        $temporaryFile->setFileType((string) $file->getData('mimetype'));
        $temporaryFile->setFileSize((int) filesize($temporaryPath));
        $temporaryFile->setOriginalFileName((string) ($file->getLocalizedData('name') ?: basename($sourcePath)));
        $temporaryFile->setDateUploaded(PKPCore::getCurrentDate());

        try {
            return DAORegistry::getDAO('TemporaryFileDAO')->insertObject($temporaryFile);
        } catch (\Throwable $error) {
            @unlink($temporaryPath);
            throw $error;
        }
    }
}
