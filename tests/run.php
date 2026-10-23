<?php
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    define('PKP_STRICT_MODE', true);
    function __($key) { return $key; }
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
    class GenericPlugin {
        public bool $enabled = true;
        public function register($category, $path, $context = null) { return true; }
        public function getEnabled($context = null) { return $this->enabled; }
    }
}
namespace APP\core {
    class Application {
        public static $user;
        public static function get() { return new self(); }
        public function getRequest() { return $this; }
        public function getUser() { return self::$user; }
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
namespace {
    $source = getenv('OJS_SOURCE');
    if (!$source) { throw new \RuntimeException('Set OJS_SOURCE to the OJS 3.4.0-9 source directory.'); }
    require $source . '/lib/pkp/classes/plugins/Hook.php';
    require __DIR__ . '/../CoverImageValidationPlugin.php';

    use APP\core\Application;
    use APP\plugins\generic\coverImageValidation\CoverImageValidator;
    use APP\plugins\generic\coverImageValidation\CoverImageValidationPlugin;
    use PKP\plugins\Hook;
    use PKP\file\TemporaryFileManager;

    class TestFile {
        public function __construct(public string $path, public string $mime, public int $owner = 7) {}
        public function getFilePath() { return $this->path; }
        public function getFileType() { return $this->mime; }
    }
    class TestPublication {
        public function __construct(public array $covers = []) {}
        public function getData($key, $locale) { return $this->covers[$locale] ?? null; }
    }
    function check($condition, $name) {
        if (!$condition) { throw new \RuntimeException('FAIL: ' . $name); }
        echo 'PASS: ' . $name . "\n";
    }

    $tmp = sys_get_temp_dir() . '/cover-test-' . bin2hex(random_bytes(5));
    mkdir($tmp, 0700);
    try {
        $v = new CoverImageValidator();
        file_put_contents($tmp . '/html', '<!doctype html><html><body>Harmless test</body></html>');
        file_put_contents($tmp . '/pdf', "%PDF-1.4\nHarmless test\n%%EOF");
        file_put_contents($tmp . '/svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        if (getenv('TEST_MISSING_EXTENSIONS')) {
            check($v->validate($tmp . '/html', 'text/html') === 'plugins.generic.coverImageValidation.unavailable', 'missing GD/Fileinfo fails closed');
            exit;
        }
        $image = imagecreatetruecolor(8, 8);
        foreach (['png' => 'image/png', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'] as $format => $mime) {
            $function = 'image' . $format;
            $function($image, $tmp . '/' . $format);
            check($v->validate($tmp . '/' . $format, $mime) === null, 'accept valid ' . $format);
        }
        imagedestroy($image);
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
        imagepng($largeImage, $tmp . '/pixels'); imagedestroy($largeImage);
        check($v->validate($tmp . '/pixels', 'image/png') === 'plugins.generic.coverImageValidation.limits', 'enforce pixel limit');
        // Preserve a recognizable PNG header but remove its image data.
        file_put_contents($tmp . '/broken', substr(file_get_contents($tmp . '/png'), 0, 33));
        check($v->validate($tmp . '/broken', 'image/png') !== null, 'reject undecodable PNG');

        Application::$user = new class { public function getId() { return 7; } };
        TemporaryFileManager::$files = [
            1 => new TestFile($tmp . '/html', 'text/html'),
            2 => new TestFile($tmp . '/png', 'image/png'),
            3 => new TestFile($tmp . '/png', 'image/png', 99),
        ];
        $plugin = new CoverImageValidationPlugin();
        $plugin->register('generic', 'plugins/generic/coverImageValidation', 11);
        function validateProps($props, $publication = null, $existingErrors = []) {
            $errors = $existingErrors;
            Hook::call('Publication::validate', [&$errors, $publication, $props, ['en', 'fr_CA'], 'en']);
            return $errors;
        }
        check(isset(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 1]]])['coverImage']['en']), 'real OJS hook propagates HTML rejection');
        check(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 2]]]) === [], 'hook accepts valid owned image');
        check(validateProps(['title' => ['en' => 'A title']]) === [], 'ordinary metadata unaffected');
        check(validateProps(['coverImage' => ['en' => null]]) === [], 'localized cover removal permitted');
        check(validateProps(['coverImage' => null]) === [], 'cover removal permitted');
        check(isset(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 3]]])['coverImage']['en']), 'reject another users file');
        check(isset(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 999]]])['coverImage']['en']), 'reject unknown temporary ID');
        check(isset(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 'bad']]])['coverImage']['en']), 'reject malformed temporary ID');
        check(isset(validateProps(['coverImage' => ['fr_CA' => ['temporaryFileId' => 1]]])['coverImage']['fr_CA']), 'validate every submitted locale');
        check(isset(validateProps(['coverImage' => ['en' => ['uploadName' => 'evil.html']]])['coverImage']['en']), 'reject direct filename on new publication');
        $saved = new TestPublication(['en' => ['uploadName' => 'original.png', 'altText' => 'Old']]);
        check(validateProps(['coverImage' => ['en' => ['uploadName' => 'original.png', 'altText' => 'New']]], $saved) === [], 'permit existing cover alt-text edit');
        check(isset(validateProps(['coverImage' => ['en' => ['uploadName' => 'other.png']]], $saved)['coverImage']['en']), 'reject replacement filename without upload');
        $existing = ['title' => ['en' => ['Required']], 'coverImage' => ['en' => ['Earlier error']]];
        $result = validateProps(['coverImage' => ['en' => ['temporaryFileId' => 1]]], null, $existing);
        check($result['title'] === $existing['title'] && count($result['coverImage']['en']) === 2, 'preserve prior validation errors');
        Hook::clear('Publication::validate');
        $disabled = new CoverImageValidationPlugin(); $disabled->enabled = false;
        $disabled->register('generic', 'plugins/generic/coverImageValidation', 11);
        check(validateProps(['coverImage' => ['en' => ['temporaryFileId' => 1]]]) === [], 'disabled plugin does not register validation');
    } finally {
        foreach (glob($tmp . '/*') as $file) { unlink($file); }
        rmdir($tmp);
    }
}
