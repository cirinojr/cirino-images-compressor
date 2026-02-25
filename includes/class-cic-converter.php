<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CICConverter {
    private const STATUS_CACHE_KEY = 'cic_status_counts';
    private const STATUS_CACHE_TTL = 10;
    public const COMPRESSION_LOSSY = 'lossy';
    public const COMPRESSION_LOSSLESS = 'lossless';
    public const META_CONVERTED = '_cic_webp_converted';
    public const META_CONVERTED_AT = '_cic_webp_converted_at';
    public const META_FAILED = '_cic_webp_failed';
    public const OPTION_RUNNING = 'cic_is_running';
    public const OPTION_BATCH_SIZE = 'cic_batch_size';
    public const OPTION_KEEP_ORIGINAL = 'cic_keep_original';
    public const OPTION_WEBP_QUALITY = 'cic_webp_quality';
    public const OPTION_WEBP_COMPRESSION_TYPE = 'cic_webp_compression_type';
    public const OPTION_MONTH_PREFIX = 'cic_month_stats_';
    public const DEFAULT_BATCH_SIZE = 20;
    private const MAX_BATCH_SIZE = 200;
    public const DEFAULT_KEEP_ORIGINAL = 1;
    public const DEFAULT_WEBP_QUALITY = 82;
    public const DEFAULT_WEBP_COMPRESSION_TYPE = self::COMPRESSION_LOSSY;

    /**
     * @var CICFileConversionService
     */
    private $fileConversionService;

    /**
     * @var CICAttachmentMetadataService
     */
    private $attachmentMetadataService;

    /**
     * @var CICImageStatsService
     */
    private $imageStatsService;

    public function __construct() {
        $this->fileConversionService = new CICFileConversionService();
        $this->attachmentMetadataService = new CICAttachmentMetadataService($this->fileConversionService);
        $this->imageStatsService = new CICImageStatsService($this->fileConversionService);
    }

    public function start() {
        update_option(self::OPTION_RUNNING, 1);
        $this->clearStatusCache();
    }

    public function stop() {
        update_option(self::OPTION_RUNNING, 0);
        $this->clearStatusCache();
    }

    public function isRunning() {
        return (bool) get_option(self::OPTION_RUNNING, 0);
    }

    public function getSettings() {
        return array(
            'keep_original' => $this->isKeepOriginalEnabled(),
            'webp_quality' => $this->getWebpQuality(),
            'webp_compression_type' => $this->getWebpCompressionType(),
        );
    }

    public static function sanitizeKeepOriginal($value) {
        return !empty($value) ? 1 : 0;
    }

    public static function sanitizeWebpQuality($value) {
        $quality = (int) $value;
        if ($quality < 1) {
            $quality = 1;
        }

        if ($quality > 100) {
            $quality = 100;
        }

        return $quality;
    }

    public static function sanitizeWebpCompressionType($value) {
        $type = strtolower(trim((string) $value));
        if (self::COMPRESSION_LOSSLESS === $type) {
            return self::COMPRESSION_LOSSLESS;
        }

        return self::COMPRESSION_LOSSY;
    }

    public function processBatch() {
        if (!$this->isRunning()) {
            return array(
                'processed' => 0,
                'converted' => 0,
                'failed' => 0,
                'remaining' => $this->imageStatsService->countPendingImages(self::META_CONVERTED),
            );
        }

        $batchSize = self::sanitizeBatchSize(get_option(self::OPTION_BATCH_SIZE, self::DEFAULT_BATCH_SIZE));

        $query = new WP_Query(
            array(
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => $batchSize,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key' => self::META_CONVERTED,
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key' => self::META_CONVERTED,
                        'value' => '1',
                        'compare' => '!=',
                    ),
                ),
            )
        );

        $processed = 0;
        $converted = 0;
        $failed = 0;

        foreach ($query->posts as $attachmentId) {
            $processed++;
            if ($this->processAttachment((int) $attachmentId)) {
                $converted++;
            } else {
                $failed++;
            }
        }

        $this->imageStatsService->updateMonthStats(self::OPTION_MONTH_PREFIX, $processed, $converted, $failed);

        $remaining = $this->imageStatsService->countPendingImages(self::META_CONVERTED);
        if (0 === $remaining) {
            $this->stop();
        }

        $this->clearStatusCache();

        return array(
            'processed' => $processed,
            'converted' => $converted,
            'failed' => $failed,
            'remaining' => $remaining,
        );
    }

    public function processAttachment($attachmentId, $attachmentMetadata = null) {
        $filePath = '';
        $validationError = $this->validateAttachmentForConversion($attachmentId, $filePath);
        if ('' !== $validationError) {
            update_post_meta($attachmentId, self::META_FAILED, $validationError);
            return false;
        }

        $metadata = is_array($attachmentMetadata) ? $attachmentMetadata : wp_get_attachment_metadata($attachmentId);
        $legacyPaths = array();
        $shouldKeepOriginal = $this->isKeepOriginalEnabled();

        if (!$shouldKeepOriginal) {
            $legacyPaths = $this->fileConversionService->collectLegacyPaths($filePath, $metadata);
        }

        $failureReason = '';
        $compressionType = $this->getWebpCompressionType();
        $quality = $this->getWebpQuality();

        $originalSuccess = $this->fileConversionService->convertOriginalFile($filePath, $compressionType, $quality, $failureReason);
        $thumbnailSuccess = $this->fileConversionService->convertThumbnails($attachmentId, $filePath, $metadata, $compressionType, $quality, $failureReason);
        $webpAsDefaultSuccess = false;

        if ($originalSuccess && $thumbnailSuccess) {
            $webpAsDefaultSuccess = $this->attachmentMetadataService->setAttachmentToWebp($attachmentId, $filePath, $metadata, $failureReason);
            if ($webpAsDefaultSuccess && !$shouldKeepOriginal) {
                $this->fileConversionService->deleteLegacyFiles($legacyPaths);
            }
        }

        if ($originalSuccess && $thumbnailSuccess && $webpAsDefaultSuccess) {
            update_post_meta($attachmentId, self::META_CONVERTED, '1');
            update_post_meta($attachmentId, self::META_CONVERTED_AT, current_time('mysql'));
            delete_post_meta($attachmentId, self::META_FAILED);
            $this->clearStatusCache();
            return true;
        }

        if ('' === $failureReason) {
            $failureReason = 'save_error';
        }

        update_post_meta($attachmentId, self::META_FAILED, $failureReason);

        return false;
    }

    public function getStatus() {
        return $this->imageStatsService->buildStatus(
            $this->isRunning(),
            self::META_CONVERTED,
            self::OPTION_MONTH_PREFIX,
            self::STATUS_CACHE_KEY,
            self::STATUS_CACHE_TTL
        );
    }

    private function validateAttachmentForConversion($attachmentId, &$filePath) {
        $error = '';

        if (!wp_attachment_is_image($attachmentId)) {
            $error = 'not_image';
        } else {
            $filePath = (string) get_attached_file($attachmentId);
            if ('' === $filePath || !file_exists($filePath)) {
                $error = 'missing_file';
            } elseif (!$this->fileConversionService->isPathInUploads($filePath)) {
                $error = 'invalid_file_path';
            } else {
                $mime = (string) get_post_mime_type($attachmentId);
                if (0 !== strpos($mime, 'image/')) {
                    $error = 'invalid_mime';
                }
            }
        }

        return $error;
    }

    private function isKeepOriginalEnabled() {
        $value = get_option(self::OPTION_KEEP_ORIGINAL, self::DEFAULT_KEEP_ORIGINAL);

        return 1 === self::sanitizeKeepOriginal($value);
    }

    private static function sanitizeBatchSize($value) {
        $batchSize = (int) $value;

        if ($batchSize < 1) {
            return self::DEFAULT_BATCH_SIZE;
        }

        if ($batchSize > self::MAX_BATCH_SIZE) {
            return self::MAX_BATCH_SIZE;
        }

        return $batchSize;
    }

    private function getWebpQuality() {
        $quality = get_option(self::OPTION_WEBP_QUALITY, self::DEFAULT_WEBP_QUALITY);

        return self::sanitizeWebpQuality($quality);
    }

    private function getWebpCompressionType() {
        $type = get_option(self::OPTION_WEBP_COMPRESSION_TYPE, self::DEFAULT_WEBP_COMPRESSION_TYPE);

        return self::sanitizeWebpCompressionType($type);
    }

    private function clearStatusCache() {
        delete_transient(self::STATUS_CACHE_KEY);
    }
}
