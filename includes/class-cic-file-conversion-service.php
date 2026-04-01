<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CICFileConversionService {
    private const ALLOWED_MIMES = array('image/jpeg', 'image/png', 'image/webp', 'image/avif');

    /**
     * @var CICCapabilitiesDetector
     */
    private $capabilities;

    /**
     * @var CICDebugLogger
     */
    private $logger;

    /**
     * @var CICOptimizerInterface[]
     */
    private $optimizers;

    /**
     * @var string|null
     */
    private static $uploadsBaseDirCache = null;

    public function __construct(CICCapabilitiesDetector $capabilities, CICDebugLogger $logger, $optimizers = array()) {
        $this->capabilities = $capabilities;
        $this->logger = $logger;

        if (!empty($optimizers)) {
            $this->optimizers = $optimizers;
        } else {
            $this->optimizers = array(
                new CICBinaryOptimizerProvider($this->capabilities, $this->logger),
                new CICImagickOptimizerProvider($this->capabilities),
                new CICGdOptimizerProvider($this->capabilities),
            );
        }
    }

    /**
     * @param string $filePath
     * @param array<string,mixed> $options
     * @param string $failureReason
     * @param string $engineUsed
     *
     * @return bool
     */
    public function convertOriginalFile($filePath, $options, &$failureReason, &$engineUsed) {
        $result = $this->optimizeFile($filePath, $options);
        $failureReason = isset($result['reason']) ? (string) $result['reason'] : '';
        $engineUsed = isset($result['engine']) ? (string) $result['engine'] : '';

        return !empty($result['success']);
    }

    /**
     * @param int $attachmentId
     * @param string $originalFilePath
     * @param array<string,mixed>|null $attachmentMetadata
     * @param array<string,mixed> $options
     * @param string $failureReason
     * @param array<int,string> $enginesUsed
     *
     * @return bool
     */
    public function convertThumbnails($attachmentId, $originalFilePath, $attachmentMetadata, $options, &$failureReason, &$enginesUsed) {
        $thumbnailFiles = $this->getThumbnailFilePaths((int) $attachmentId, $originalFilePath, $attachmentMetadata);

        if (empty($thumbnailFiles)) {
            return true;
        }

        $allSuccessful = true;
        foreach ($thumbnailFiles as $thumbnailFilePath) {
            $result = $this->optimizeFile($thumbnailFilePath, $options);
            if (!empty($result['engine'])) {
                $enginesUsed[] = (string) $result['engine'];
            }

            if (empty($result['success'])) {
                $allSuccessful = false;
                if ('' === $failureReason && !empty($result['reason'])) {
                    $failureReason = (string) $result['reason'];
                }
            }

            $this->generateAlternativeFormats($thumbnailFilePath, $options);
        }

        return $allSuccessful;
    }

    /**
     * @param string $filePath
     * @param array<string,mixed> $options
     *
     * @return void
     */
    public function generateAlternativeFormats($filePath, $options) {
        $filePath = (string) $filePath;
        if (!file_exists($filePath) || !$this->isPathInUploads($filePath)) {
            return;
        }

        $sourceMime = $this->detectRealMimeType($filePath);
        if (!in_array($sourceMime, self::ALLOWED_MIMES, true)) {
            return;
        }

        if (!empty($options['convert_to_webp'])) {
            $this->generateAlternativeFile($filePath, 'image/webp', $options);
        }

        if (!empty($options['try_avif'])) {
            $this->generateAlternativeFile($filePath, 'image/avif', $options);
        }
    }

    /**
     * @param string $filePath
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    public function optimizeFile($filePath, $options) {
        $filePath = (string) $filePath;

        if ('' === $filePath || !file_exists($filePath)) {
            return array('success' => false, 'reason' => 'missing_file', 'engine' => '');
        }

        if (!$this->isPathInUploads($filePath)) {
            return array('success' => false, 'reason' => 'invalid_file_path', 'engine' => '');
        }

        $sourceMime = $this->detectRealMimeType($filePath);
        if ('image/svg+xml' === $sourceMime) {
            return array('success' => false, 'reason' => 'unsupported_svg', 'engine' => '');
        }

        if (!in_array($sourceMime, self::ALLOWED_MIMES, true)) {
            return array('success' => false, 'reason' => 'unsupported_mime:' . $sourceMime, 'engine' => '');
        }

        $sourceSize = (int) @filesize($filePath);
        if ($sourceSize <= 0) {
            return array('success' => false, 'reason' => 'empty_file', 'engine' => '');
        }

        $targetMime = $sourceMime;
        $tempPath = $this->createTempPath($filePath, $targetMime);
        $backupPath = $filePath . '.cic-bak';

        $safeBackup = @copy($filePath, $backupPath);
        if (!$safeBackup) {
            return array('success' => false, 'reason' => 'backup_failed', 'engine' => '');
        }

        $result = $this->runOptimizerChain($filePath, $tempPath, $sourceMime, $targetMime, $options);
        if (empty($result['success']) || !file_exists($tempPath)) {
            @unlink($backupPath);
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }

            return array(
                'success' => false,
                'reason' => isset($result['reason']) ? (string) $result['reason'] : 'optimize_failed',
                'engine' => isset($result['engine']) ? (string) $result['engine'] : '',
            );
        }

        $optimizedSize = (int) @filesize($tempPath);
        if ($optimizedSize <= 0 || $optimizedSize >= $sourceSize) {
            @unlink($tempPath);
            @unlink($backupPath);

            return array(
                'success' => true,
                'reason' => 'kept_original_better_or_equal',
                'engine' => isset($result['engine']) ? (string) $result['engine'] : '',
            );
        }

        $replaced = @copy($tempPath, $filePath);
        @unlink($tempPath);

        if (!$replaced) {
            @copy($backupPath, $filePath);
            @unlink($backupPath);

            return array(
                'success' => false,
                'reason' => 'replace_failed',
                'engine' => isset($result['engine']) ? (string) $result['engine'] : '',
            );
        }

        if (empty($options['preserve_original'])) {
            @unlink($backupPath);
        }

        return array(
            'success' => true,
            'reason' => 'optimized',
            'engine' => isset($result['engine']) ? (string) $result['engine'] : '',
            'before' => $sourceSize,
            'after' => $optimizedSize,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function getCapabilities() {
        return $this->capabilities->getCapabilities();
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
        $capabilities = $this->getCapabilities();

        return !empty($capabilities['wp']['supports_webp'])
            || !empty($capabilities['imagick']['supports_webp'])
            || !empty($capabilities['gd']['supports_webp'])
            || !empty($capabilities['binaries']['cwebp']);
    }

    /**
     * @param int $attachmentId
     * @param string $originalFilePath
     * @param array<string,mixed>|null $attachmentMetadata
     *
     * @return array<int,string>
     */
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

    /**
     * @param string $sourcePath
     * @param string $targetMime
     * @param array<string,mixed> $options
     *
     * @return void
     */
    private function generateAlternativeFile($sourcePath, $targetMime, $options) {
        $ext = 'image/webp' === $targetMime ? 'webp' : 'avif';
        $destPath = preg_replace('/\.[^.]+$/', '.' . $ext, (string) $sourcePath);
        if (!is_string($destPath) || '' === $destPath || !$this->isPathInUploads($destPath, true)) {
            return;
        }

        $sourceMime = $this->detectRealMimeType($sourcePath);
        $result = $this->runOptimizerChain($sourcePath, $destPath, $sourceMime, $targetMime, $options);
        if (empty($result['success']) || !file_exists($destPath)) {
            if (file_exists($destPath)) {
                @unlink($destPath);
            }
            return;
        }

        $sourceSize = (int) @filesize($sourcePath);
        $generatedSize = (int) @filesize($destPath);

        if ($generatedSize <= 0 || $generatedSize >= $sourceSize) {
            @unlink($destPath);
            return;
        }

        $this->logger->log('generated_alternative_format', array(
            'source' => $sourcePath,
            'dest' => $destPath,
            'engine' => isset($result['engine']) ? (string) $result['engine'] : '',
            'target_mime' => $targetMime,
        ));
    }

    /**
     * @param string $sourcePath
     * @param string $destPath
     * @param string $sourceMime
     * @param string $targetMime
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    private function runOptimizerChain($sourcePath, $destPath, $sourceMime, $targetMime, $options) {
        $fallbackReason = 'no_optimizer_succeeded';

        foreach ($this->optimizers as $optimizer) {
            if (!$optimizer instanceof CICOptimizerInterface) {
                continue;
            }

            if (!$optimizer->supports($sourceMime)) {
                continue;
            }

            $providerOptions = array_merge(
                $options,
                array(
                    'source_mime' => $sourceMime,
                    'target_mime' => $targetMime,
                )
            );

            $result = $optimizer->optimize($sourcePath, $destPath, $providerOptions);
            $success = !empty($result['success']) && file_exists($destPath);
            if ($success) {
                return array(
                    'success' => true,
                    'engine' => $optimizer->getName() . (empty($result['engine']) ? '' : ':' . (string) $result['engine']),
                    'reason' => 'ok',
                );
            }

            $fallbackReason = isset($result['reason']) ? (string) $result['reason'] : 'provider_failed';

            $this->logger->log('optimizer_fallback', array(
                'provider' => $optimizer->getName(),
                'source' => $sourcePath,
                'dest' => $destPath,
                'source_mime' => $sourceMime,
                'target_mime' => $targetMime,
                'reason' => $fallbackReason,
            ));
        }

        return array(
            'success' => false,
            'engine' => '',
            'reason' => $fallbackReason,
        );
    }

    /**
     * @param string $path
     *
     * @return string
     */
    private function detectRealMimeType($path) {
        $mime = '';

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if (false !== $finfo) {
                $detected = @finfo_file($finfo, $path);
                if (is_string($detected)) {
                    $mime = strtolower($detected);
                }
                @finfo_close($finfo);
            }
        }

        if ('' === $mime && function_exists('getimagesize')) {
            $imageInfo = @getimagesize($path);
            if (is_array($imageInfo) && !empty($imageInfo['mime'])) {
                $mime = strtolower((string) $imageInfo['mime']);
            }
        }

        if ('image/jpg' === $mime) {
            $mime = 'image/jpeg';
        }

        return $mime;
    }

    /**
     * @param string $sourcePath
     * @param string $targetMime
     *
     * @return string
     */
    private function createTempPath($sourcePath, $targetMime) {
        $suffix = 'tmp';
        if ('image/jpeg' === $targetMime) {
            $suffix = 'jpg';
        } elseif ('image/png' === $targetMime) {
            $suffix = 'png';
        } elseif ('image/webp' === $targetMime) {
            $suffix = 'webp';
        } elseif ('image/avif' === $targetMime) {
            $suffix = 'avif';
        }

        return $sourcePath . '.cic-tmp.' . $suffix;
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
}
