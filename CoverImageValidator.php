<?php

namespace APP\plugins\generic\coverImageValidation;

/** Validates uploaded contents, never the browser-supplied filename alone. */
class CoverImageValidator
{
    public const MAX_BYTES = 5242880;
    public const MAX_PIXELS = 4000000;

    /** Returns a translation key on rejection, null on success. */
    public function validate(string $path, string $storedMime): ?string
    {
        if (!extension_loaded('gd') || !class_exists(\finfo::class)) {
            return 'plugins.generic.coverImageValidation.unavailable';
        }
        if (!is_file($path) || !is_readable($path)) {
            return 'plugins.generic.coverImageValidation.invalid';
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            return 'plugins.generic.coverImageValidation.limits';
        }
        $info = @getimagesize($path);
        $mimes = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_GIF => 'image/gif'];
        if (!$info || !isset($mimes[$info[2]])) {
            return 'plugins.generic.coverImageValidation.invalid';
        }
        // PublicFileManager derives the public extension from stored MIME.
        // Require that MIME to agree with the detected image contents too.
        $expected = $mimes[$info[2]];
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($storedMime !== $expected || $detected !== $expected) {
            return 'plugins.generic.coverImageValidation.invalid';
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_PIXELS / $info[1]) {
            return 'plugins.generic.coverImageValidation.limits';
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return 'plugins.generic.coverImageValidation.invalid';
        }
        try {
            $image = @imagecreatefromstring($contents);
        } catch (\Throwable $error) {
            return 'plugins.generic.coverImageValidation.invalid';
        }
        if ($image === false) {
            return 'plugins.generic.coverImageValidation.invalid';
        }
        imagedestroy($image);
        return null;
    }
}
