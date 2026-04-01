<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CICFileConversionService {
    private const MIME_WEBP = 'image/webp';
    /**
     * @var string|null
     */
    private static $uploadsBaseDirCache = null;

    public function convertOriginalFile($filePath, $compressionType, $quality, &$failureReason) {
        $isAlreadyWebp = 'webp' === strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($isAlreadyWebp) {
            return true;
        }

        return $this->saveAsWebp($filePath, $compressionType, $quality, $failureReason);
    }

    public function convertThumbnails($attachmentId, $originalFilePath, $attachmentMetadata, $compressionType, $quality, &$failureReason) {
        $thumbnailFiles = $this->getThumbnailFilePaths($attachmentId, $originalFilePath, $attachmentMetadata);

        if (empty($thumbnailFiles)) {
            return true;
        }

        $allSuccessful = true;
        foreach ($thumbnailFiles as $thumbnailFilePath) {
            $isThumbWebp = 'webp' === strtolower(pathinfo($thumbnailFilePath, PATHINFO_EXTENSION));
            if ($isThumbWebp) {
                continue;
            }

            $thumbnailFailureReason = '';
            if (!$this->saveAsWebp($thumbnailFilePath, $compressionType, $quality, $thumbnailFailureReason)) {
                $allSuccessful = false;
                if ('' === $failureReason && '' !== $thumbnailFailureReason) {
                    $failureReason = $thumbnailFailureReason;
                }
            }
        }

        return $allSuccessful;
    }

    public function collectLegacyPaths($originalFilePath, $attachmentMetadata) {
        $paths = array();
        if ('' !== $originalFilePath) {
            $paths[] = $originalFilePath;
        }

        if (is_array($attachmentMetadata) && !empty($attachmentMetadata['sizes']) && is_array($attachmentMetadata['sizes'])) {
            $baseDir = trailingslashit(pathinfo($originalFilePath, PATHINFO_DIRNAME));
            foreach ($attachmentMetadata['sizes'] as $sizeData) {
                if (!is_array($sizeData) || empty($sizeData['file'])) {
                    continue;
                }

                $thumbFileName = wp_basename((string) $sizeData['file']);
                if ('' === $thumbFileName) {
                    continue;
                }

                $thumbPath = $baseDir . $thumbFileName;
                if (file_exists($thumbPath)) {
                    $paths[] = $thumbPath;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    public function deleteLegacyFiles($paths) {
        foreach ($paths as $path) {
            if (!is_string($path) || '' === $path) {
                continue;
            }

            $isWebp = 'webp' === strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($isWebp || !file_exists($path) || !$this->isPathInUploads($path)) {
                continue;
            }

            wp_delete_file($path);
        }
    }

    public function replacePathExtensionToWebp($path) {
        $webpPath = preg_replace('/\.[^.]+$/', '.webp', (string) $path);

        return is_string($webpPath) ? $webpPath : '';
    }

    public function isPathInUploads($path, $allowNotExistingFile = false) {
        $baseDir = $this->getResolvedUploadsBaseDir();
        if ('' === $baseDir) {
            return false;
        }

        $resolvedPath = $this->resolvePathForUploadsValidation($path, $allowNotExistingFile);
        if ('' === $resolvedPath) {
            return false;
        }

        return 0 === strpos($resolvedPath, $baseDir);
    }

    public function isWebpSupported() {
        static $supported = null;

        if (null === $supported) {
            $supported = wp_image_editor_supports(array('mime_type' => self::MIME_WEBP));
        }

        return (bool) $supported;
    }

    private function getThumbnailFilePaths($attachmentId, $originalFilePath, $attachmentMetadata) {
        if (!is_string($originalFilePath) || '' === $originalFilePath) {
            return array();
        }

        $metadata = is_array($attachmentMetadata) ? $attachmentMetadata : wp_get_attachment_metadata($attachmentId);
        if (!is_array($metadata) || empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
            return array();
        }

        $baseDir = trailingslashit(pathinfo($originalFilePath, PATHINFO_DIRNAME));
        $paths = array();

        foreach ($metadata['sizes'] as $sizeData) {
            if (!is_array($sizeData) || empty($sizeData['file'])) {
                continue;
            }

            $thumbFileName = wp_basename((string) $sizeData['file']);
            if ('' === $thumbFileName) {
                continue;
            }

            $thumbPath = $baseDir . $thumbFileName;
            if (!file_exists($thumbPath)) {
                continue;
            }

            $paths[] = $thumbPath;
        }

        return array_values(array_unique($paths));
    }

    private function saveAsWebp($filePath, $compressionType, $quality, &$failureReason = '') {
        $isSuccessful = false;
        $effectiveQuality = $this->getEffectiveWebpQuality($quality, $compressionType);
        $webpPath = $this->replacePathExtensionToWebp($filePath);

        if ($this->hasReusableWebpFile($filePath, $webpPath)) {
            return true;
        }

        if ($this->validateWebpPaths($filePath, $webpPath, $failureReason)) {
            $editorResult = $this->saveAsWebpWithEditor($filePath, $webpPath, $effectiveQuality, $failureReason);
            if (true === $editorResult) {
                $isSuccessful = true;
            } elseif (null !== $editorResult) {
                $isSuccessful = $this->saveAsWebpWithGd($filePath, $webpPath, $effectiveQuality, $failureReason);
            }
        }

        return $isSuccessful;
    }

    private function validateWebpPaths($filePath, $webpPath, &$failureReason) {
        $isValid = '' !== $webpPath
            && $this->isPathInUploads($filePath)
            && $this->isPathInUploads($webpPath, true);

        if (!$isValid) {
            $failureReason = 'invalid_target_path';
        }

        return $isValid;
    }

    private function saveAsWebpWithEditor($filePath, $webpPath, $quality, &$failureReason) {
        $result = false;
        $qualityIsFatal = false;

        if (!$this->isWebpSupported()) {
            $failureReason = 'webp_not_supported';
        } else {
            $editor = wp_get_image_editor($filePath);
            if (is_wp_error($editor)) {
                $failureReason = 'editor_init_error:' . $editor->get_error_code();
            } else {
                $setQualityResult = $editor->set_quality($quality);
                if (is_wp_error($setQualityResult)) {
                    $failureReason = 'quality_error:' . $setQualityResult->get_error_code();
                    $qualityIsFatal = true;
                } else {
                    $saved = $editor->save($webpPath, self::MIME_WEBP);
                    if (is_wp_error($saved)) {
                        $failureReason = 'save_error:' . $saved->get_error_code();
                    } else {
                        $result = true;
                    }
                }
            }
        }

        if ($qualityIsFatal) {
            return null;
        }

        return $result;
    }

    private function getEffectiveWebpQuality($quality, $compressionType) {
        if ('lossless' === $compressionType) {
            return 100;
        }

        return (int) $quality;
    }

    private function saveAsWebpWithGd($filePath, $webpPath, $quality, &$failureReason) {
        $isSuccessful = false;

        if (function_exists('imagewebp')) {
            $sourceImage = $this->createImageResourceFromFile($filePath);

            if (!is_resource($sourceImage) && !is_object($sourceImage)) {
                $failureReason = 'gd_open_error';
            } else {
                imagepalettetotruecolor($sourceImage);
                imagealphablending($sourceImage, false);
                imagesavealpha($sourceImage, true);

                $isSuccessful = imagewebp($sourceImage, $webpPath, (int) $quality);
                imagedestroy($sourceImage);

                if (!$isSuccessful) {
                    $failureReason = 'gd_save_error';
                }
            }
        }

        return $isSuccessful;
    }

    private function createImageResourceFromFile($filePath) {
        $imageInfo = function_exists('getimagesize') ? @getimagesize($filePath) : null;
        if (!is_array($imageInfo) || empty($imageInfo['mime'])) {
            return null;
        }

        $mime = (string) $imageInfo['mime'];
        $creatorFunction = $this->getImageCreatorFunctionByMime($mime);

        if ('' === $creatorFunction || !function_exists($creatorFunction)) {
            return null;
        }

        return @$creatorFunction($filePath);
    }

    private function getImageCreatorFunctionByMime($mime) {
        $creators = array(
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/gif' => 'imagecreatefromgif',
            'image/webp' => 'imagecreatefromwebp',
        );

        return isset($creators[$mime]) ? $creators[$mime] : '';
    }

    private function getResolvedUploadsBaseDir() {
        if (null !== self::$uploadsBaseDirCache) {
            return self::$uploadsBaseDirCache;
        }

        $uploads = wp_upload_dir();
        $uploadsBaseDir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        if ('' === $uploadsBaseDir) {
            self::$uploadsBaseDirCache = '';
            return self::$uploadsBaseDirCache;
        }

        $resolvedBaseDir = realpath($uploadsBaseDir);
        if (false === $resolvedBaseDir) {
            self::$uploadsBaseDirCache = '';
            return self::$uploadsBaseDirCache;
        }

        self::$uploadsBaseDirCache = wp_normalize_path(trailingslashit($resolvedBaseDir));

        return self::$uploadsBaseDirCache;
    }

    private function resolvePathForUploadsValidation($path, $allowNotExistingFile) {
        $resolvedNormalizedPath = '';

        if (!is_string($path) || '' === $path) {
            return $resolvedNormalizedPath;
        }

        $resolvedPath = realpath($path);

        if (false === $resolvedPath) {
            if ($allowNotExistingFile) {
                $resolvedParentDir = realpath((string) dirname($path));
                if (false !== $resolvedParentDir) {
                    $resolvedNormalizedPath = wp_normalize_path(trailingslashit($resolvedParentDir) . wp_basename($path));
                }
            }
        } else {
            $resolvedNormalizedPath = wp_normalize_path($resolvedPath);
        }

        return $resolvedNormalizedPath;
    }

    private function hasReusableWebpFile($sourcePath, $webpPath) {
        if ('' === $webpPath || !file_exists($webpPath)) {
            return false;
        }

        if (filesize($webpPath) <= 0) {
            return false;
        }

        $sourceModifiedAt = @filemtime($sourcePath);
        $webpModifiedAt = @filemtime($webpPath);

        if (false === $sourceModifiedAt || false === $webpModifiedAt) {
            return false;
        }

        return $webpModifiedAt >= $sourceModifiedAt;
    }
}
