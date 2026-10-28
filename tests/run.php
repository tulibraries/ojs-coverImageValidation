<?php
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    define('PKP_STRICT_MODE', true);
    function __($key) { return $key; }
    function strtolower_codesafe($value) { return strtolower($value); }
}
namespace PKP\core {
    class Registry {
        private static array $values = [];
        public static function &get($key, $create = false, $default = null) {
            if (!array_key_exists($key, self::$values)) { self::$values[$key] = $default; }
            return self::$values[$key];
        }
    }
}
namespace PKP\plugins {
    class Hook {
        private static array $hooks = [];

        public static function add($hookName, $callback) {
            self::$hooks[$hookName][] = $callback;
        }

        public static function call($hookName, $args = []) {
            foreach (self::$hooks[$hookName] ?? [] as $callback) {
                $callback($hookName, $args);
            }
            return false;
        }

        public static function clear($hookName = null) {
            if ($hookName === null) {
                self::$hooks = [];
                return;
            }
            unset(self::$hooks[$hookName]);
        }
    }

    class Plugin {
        public static array $settings = [];
        private string $path = '';
        public function register($category, $path, $context = null) { $this->path = $path; return true; }
        public function getPluginPath() { return $this->path; }
        public function addLocaleData() {}
        public function isSitePlugin() { return false; }
        public function getSetting($context, $name) { return self::$settings[$context][$name] ?? null; }
        public function updateSetting($context, $pluginName, $value, $type) { self::$settings[$context][$pluginName] = $value; }
        public function setEnabled($enabled) { $this->updateSetting(0, 'enabled', (bool) $enabled, 'bool'); }
    }
    class GenericPlugin extends Plugin {
    }
}
namespace PKP\security {
    class Role {
        public const ROLE_ID_MANAGER = 16;
        public const ROLE_ID_SUB_EDITOR = 17;
    }
}
namespace APP\core {
    class Application {
        public static $user;
        public static int $contextId = 11;
        public static function get() { return new self(); }
        public function getRequest() { return $this; }
        public function getUser() { return self::$user; }
        public function getContext() { return new class { public function getId() { return Application::$contextId; } }; }
    }
}
namespace PKP\file {
    class TemporaryFileManager {
        public static array $files = [];
        public function getFile($id, $owner) {
            $file = self::$files[$id] ?? null;
            return $file && $file->owner === $owner ? $file : null;
        }
    }
}
namespace PKP\submissionFile {
    class SubmissionFile {}
}
namespace APP\facades {
    class Repo {
        public static $submissionFileRepository;
        public static function submissionFile() {
            return self::$submissionFileRepository;
        }
    }
}
namespace {
    require __DIR__ . '/../CoverImageValidationPlugin.php';

    use APP\core\Application;
    use APP\plugins\generic\coverImageValidation\CoverProposalService;
    use APP\plugins\generic\coverImageValidation\CoverImageValidator;
    use APP\plugins\generic\coverImageValidation\CoverImageValidationPlugin;
    use PKP\plugins\Hook;
    use PKP\plugins\Plugin;
    use PKP\file\TemporaryFileManager;
    use APP\facades\Repo;

    class TestFile {
        public function __construct(public string $path, public string $mime, public int $owner = 7) {}
        public function getFilePath() { return $this->path; }
        public function getFileType() { return $this->mime; }
    }
    class TestUser {
        public function __construct(
            private int $id = 7,
            private array $rolesByContext = []
        ) {}
        public function getId() { return $this->id; }
        public function hasRole(array $roleIds, $contextId) {
            return (bool) array_intersect($roleIds, $this->rolesByContext[$contextId] ?? []);
        }
    }
    class TestPublication {
        public function __construct(public array $covers = []) {}
        public function getData($key, $locale) { return $this->covers[$locale] ?? null; }
    }
    class TestSubmissionFile extends \PKP\submissionFile\SubmissionFile {}
    class TestSubmissionFileRepository {
        public ?object $deleted = null;
        public function delete($file): void { $this->deleted = $file; }
    }
    function check($condition, $name) {
        if (!$condition) { throw new \RuntimeException('FAIL: ' . $name); }
        echo 'PASS: ' . $name . "\n";
    }

    $tempRoot = sys_get_temp_dir();
    if (!is_dir($tempRoot) || !is_writable($tempRoot)) {
        $tempRoot = __DIR__;
    }
    $tmp = $tempRoot . '/cover-test-' . bin2hex(random_bytes(5));
    mkdir($tmp, 0700);
    try {
        $v = new CoverImageValidator();
        file_put_contents($tmp . '/html', '<!doctype html><html><body>Harmless test</body></html>');
        file_put_contents($tmp . '/pdf', "%PDF-1.4\nHarmless test\n%%EOF");
        file_put_contents($tmp . '/svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        if (getenv('TEST_MISSING_EXTENSIONS')) {
            $missingExtensionsValidator = new CoverImageValidator(false);
            check($missingExtensionsValidator->validate($tmp . '/html', 'text/html') === 'plugins.generic.coverImageValidation.unavailable', 'missing GD/Fileinfo fails closed');
            return;
        }
        $image = imagecreatetruecolor(8, 8);
        foreach (['png' => 'image/png', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'] as $format => $mime) {
            $function = 'image' . $format;
            $function($image, $tmp . '/' . $format);
            check($v->validate($tmp . '/' . $format, $mime) === null, 'accept valid ' . $format);
        }
        unset($image);
        check($v->validate($tmp . '/html', 'text/html') !== null, 'reject HTML');
        check($v->validate($tmp . '/html', 'image/png') !== null, 'reject HTML claiming image MIME');
        copy($tmp . '/html', $tmp . '/fake.png');
        check($v->validate($tmp . '/fake.png', 'image/png') !== null, 'reject HTML renamed .png');
        check($v->validate($tmp . '/pdf', 'application/pdf') !== null, 'reject PDF');
        check($v->validate($tmp . '/svg', 'image/svg+xml') !== null, 'reject SVG');
        check($v->validate($tmp . '/png', 'text/html') !== null, 'reject valid PNG with stored HTML MIME');
        file_put_contents($tmp . '/empty', '');
        check($v->validate($tmp . '/empty', 'image/png') !== null, 'reject empty file');
        check($v->validate($tmp . '/missing', 'image/png') !== null, 'reject missing file');
        $handle = fopen($tmp . '/large', 'wb'); ftruncate($handle, 5242881); fclose($handle);
        check($v->validate($tmp . '/large', 'image/png') === 'plugins.generic.coverImageValidation.limits', 'enforce byte limit');
        $largeImage = imagecreatetruecolor(2001, 2000);
        imagepng($largeImage, $tmp . '/pixels'); unset($largeImage);
        check($v->validate($tmp . '/pixels', 'image/png') === 'plugins.generic.coverImageValidation.limits', 'enforce pixel limit');
        // Preserve a recognizable PNG header but remove its image data.
        file_put_contents($tmp . '/broken', substr(file_get_contents($tmp . '/png'), 0, 33));
        check($v->validate($tmp . '/broken', 'image/png') !== null, 'reject undecodable PNG');

        Application::$user = new TestUser();
        TemporaryFileManager::$files = [
            1 => new TestFile($tmp . '/html', 'text/html'),
            2 => new TestFile($tmp . '/png', 'image/png'),
            3 => new TestFile($tmp . '/png', 'image/png', 99),
            4 => new TestFile($tmp . '/pdf', 'application/pdf'),
        ];
        $plugin = new CoverImageValidationPlugin();
        Plugin::$settings = [0 => ['enabled' => true], 11 => ['enabled' => false]];
        $plugin->register('generic', 'plugins/generic/coverImageValidation', 11);
        check($plugin->isSitePlugin(), 'plugin is site-wide');
        check($plugin->getEnabled(), 'mandatory validation reports enabled despite disabled journal setting');
        check($plugin->getInstallSitePluginSettingsFile() === 'plugins/generic/coverImageValidation/settings.xml', 'installer receives site defaults file');
        $submissionFileRepository = new TestSubmissionFileRepository();
        Repo::$submissionFileRepository = $submissionFileRepository;
        $rejectedFile = new TestSubmissionFile();
        (new CoverProposalService())->reject($rejectedFile);
        check($submissionFileRepository->deleted === $rejectedFile, 'reject deletes through the OJS submission-file repository');
        $reviewContext = new class { public function getId() { return 11; } };
        check(CoverProposalService::canReview(new TestUser(7, [11 => [\PKP\security\Role::ROLE_ID_SUB_EDITOR]]), $reviewContext), 'current-journal editor can review proposals');
        check(!CoverProposalService::canReview(new TestUser(7, [12 => [\PKP\security\Role::ROLE_ID_SUB_EDITOR]]), $reviewContext), 'editor from another journal cannot review proposals');
        function validateProps($props, $publication = null, $existingErrors = []) {
            $errors = $existingErrors;
            Hook::call('Publication::validate', [&$errors, $publication, $props, ['en', 'fr_CA'], 'en']);
            return $errors;
        }
        check(isset(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 1]]])['coverImage']['en']), 'local hook dispatcher propagates HTML rejection');
        $authorCoverErrors = validateProps(['coverImage' => ['en' => ['temporaryFileId' => 2]]]);
        check(
            $authorCoverErrors['coverImage']['en'][0] === 'plugins.generic.coverImageValidation.editorRequired',
            'author cannot upload a genuine cover image'
        );
        Application::$user = new TestUser(7, [11 => [\PKP\security\Role::ROLE_ID_SUB_EDITOR]]);
        check(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 2]]]) === [], 'editor in the current journal can upload a genuine cover image');
        Application::$user = new TestUser(7, [11 => [\PKP\security\Role::ROLE_ID_MANAGER]]);
        check(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 2]]]) === [], 'manager in the current journal can upload a genuine cover image');
        Application::$user = new TestUser(7, [11 => [\PKP\security\Role::ROLE_ID_SUB_EDITOR]]);
        check(isset(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 1]]])['coverImage']['en']), 'editor cannot upload HTML as a cover image');
        check(isset(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 4]]])['coverImage']['en']), 'editor cannot upload PDF as a cover image');
        check(isset(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 999]]])['coverImage']['en']), 'editor cannot attach an unknown temporary file');
        check(validateProps(['title' => ['en' => 'A title']]) === [], 'ordinary metadata unaffected');
        check(validateProps(['submissionFileId' => 2]) === [], 'ordinary manuscript uploads unaffected');
        check(validateProps(['coverImage' => ['en' => null]]) === [], 'localized cover removal permitted');
        check(validateProps(['coverImage' => null]) === [], 'cover removal permitted');
        check(isset(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 3]]])['coverImage']['en']), 'reject another users file');
        check(isset(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 999]]])['coverImage']['en']), 'reject unknown temporary ID');
        check(isset(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 'bad']]])['coverImage']['en']), 'reject malformed temporary ID');
        check(isset(validateProps(['coverImage' => ['fr_CA' => ['temporaryFileId' => 1]]])['coverImage']['fr_CA']), 'validate every submitted locale');
        check(isset(validateProps(['coverImage' => ['en' => ['uploadName' => 'evil.html']]])['coverImage']['en']), 'reject direct filename on new publication');
        $saved = new TestPublication(['en' => ['uploadName' => 'original.png', 'altText' => 'Old']]);
        $originalCover = $saved->covers;
        $rejectedReplacement = validateProps(['coverImage' => ['en' => ['temporaryFileId' => 1]]], $saved);
        check(isset($rejectedReplacement['coverImage']['en']) && $saved->covers === $originalCover, 'reject HTML replacement without changing the existing cover');
        $mixedLocales = validateProps(['coverImage' => [
            'en' => ['temporaryFileId' => 2],
            'fr_CA' => ['temporaryFileId' => 1],
        ]]);
        check(!isset($mixedLocales['coverImage']['en']) && isset($mixedLocales['coverImage']['fr_CA']), 'valid first locale does not bypass HTML validation in another locale');
        $mixedLocalesReversed = validateProps(['coverImage' => [
            'fr_CA' => ['temporaryFileId' => 1],
            'en' => ['temporaryFileId' => 2],
        ]]);
        check($mixedLocalesReversed === $mixedLocales, 'locale order does not change the rejection');
        $author = new TestUser();
        Application::$user = null;
        check(isset(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 2]]])['coverImage']['en']), 'reject new upload without an authenticated owner');
        Application::$user = $author;
        check(validateProps(['coverImage' => ['en' => ['uploadName' => 'original.png', 'altText' => 'New']]], $saved) === [], 'permit existing cover alt-text edit');
        check(isset(validateProps(['coverImage' => ['en' => ['uploadName' => 'other.png']]], $saved)['coverImage']['en']), 'reject replacement filename without upload');
        $existing = ['title' => ['en' => ['Required']], 'coverImage' => ['en' => ['Earlier error']]];
        $result = validateProps(['coverImage' => ['en' => ['temporaryFileId' => 1]]], null, $existing);
        check($result['title'] === $existing['title'] && count($result['coverImage']['en']) === 2, 'preserve prior validation errors');
        Hook::clear('Publication::validate');
        Application::$contextId = 16;
        Application::$user = new TestUser(7, [16 => [\PKP\security\Role::ROLE_ID_SUB_EDITOR]]);
        Hook::clear('Publication::validate');
        $otherJournal = new CoverImageValidationPlugin();
        $otherJournal->register('generic', 'plugins/generic/coverImageValidation', 16);
        check(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 2]]]) === [], 'editor in the current journal can upload in another journal');
        Application::$user = new TestUser(7, [11 => [\PKP\security\Role::ROLE_ID_SUB_EDITOR]]);
        $wrongJournalErrors = validateProps(['coverImage' => ['en' => ['temporaryFileId' => 2]]]);
        check(
            $wrongJournalErrors['coverImage']['en'][0] === 'plugins.generic.coverImageValidation.editorRequired',
            'editor only in another journal cannot upload a cover'
        );
        Application::$contextId = 999;
        Application::$user = new TestUser(7, [16 => [\PKP\security\Role::ROLE_ID_SUB_EDITOR]]);
        Hook::clear('Publication::validate');
        $newJournal = new CoverImageValidationPlugin();
        $newJournal->register('generic', 'plugins/generic/coverImageValidation', 999);
        check(isset(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 1]]])['coverImage']['en']), 'site-enabled validation protects a newly created journal');
        Application::$contextId = 11;
        Application::$user = new TestUser(7, [11 => [\PKP\security\Role::ROLE_ID_SUB_EDITOR]]);
        Hook::clear('Publication::validate');
        $disabled = new CoverImageValidationPlugin();
        $disabled->setEnabled(false);
        check(Plugin::$settings[0]['enabled'] === true && Plugin::$settings[11]['enabled'] === false, 'direct disable call keeps site enabled');
        check(!$disabled->getCanDisable(), 'OJS disable endpoint capability is denied');
        check(!$disabled->getCanEnable(), 'OJS enable toggle is unavailable');
        Plugin::$settings[0]['enabled'] = false;
        check($disabled->getEnabled(11) && $disabled->getEnabled(0), 'saved site disable does not change runtime enabled status');
        Plugin::$settings[11]['enabled'] = true;
        $disabled->register('generic', 'plugins/generic/coverImageValidation', 11);
        check(isset(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 1]]])['coverImage']['en']), 'saved site disable cannot bypass validation');
        Hook::clear('Publication::validate');
        unset(Plugin::$settings[0]);
        $notInstalled = new CoverImageValidationPlugin();
        $notInstalled->register('generic', 'plugins/generic/coverImageValidation', 11);
        check(isset(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 1]]])['coverImage']['en']), 'missing site setting cannot bypass validation');
        $descriptor = file_get_contents(__DIR__ . '/../version.xml');
        check(str_contains($descriptor, '<lazy-load>0</lazy-load>') && str_contains($descriptor, '<sitewide>1</sitewide>'), 'descriptor loads site-wide regardless of saved enabled settings');
    } finally {
        foreach (glob($tmp . '/*') as $file) { unlink($file); }
        rmdir($tmp);
    }
}
