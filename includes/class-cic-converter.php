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
    public const OPTION_FORCE_WEBP_OUTPUT = 'cic_force_webp_output';
    public const OPTION_WEBP_QUALITY = 'cic_webp_quality';
    public const OPTION_WEBP_COMPRESSION_TYPE = 'cic_webp_compression_type';
    public const OPTION_BATCH_LOCK = 'cic_batch_lock';
    public const OPTION_PERFORMANCE_STATS = 'cic_performance_stats';
    public const OPTION_MONTH_PREFIX = 'cic_month_stats_';
    public const DEFAULT_BATCH_SIZE = 20;
    private const MAX_BATCH_SIZE = 200;
    public const DEFAULT_KEEP_ORIGINAL = 1;
    public const DEFAULT_FORCE_WEBP_OUTPUT = 1;
    public const DEFAULT_WEBP_QUALITY = 82;
    public const DEFAULT_WEBP_COMPRESSION_TYPE = self::COMPRESSION_LOSSY;
    private const BATCH_LOCK_TTL = 300;

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
        $this->releaseBatchLock();
        $this->clearStatusCache();
    }

    public function isRunning() {
        return (bool) get_option(self::OPTION_RUNNING, 0);
    }

    public function getSettings() {
        return array(
            'batch_size' => $this->getBatchSize(),
            'keep_original' => $this->isKeepOriginalEnabled(),
            'force_webp_output' => $this->isForceWebpOutputEnabled(),
            'webp_quality' => $this->getWebpQuality(),
            'webp_compression_type' => $this->getWebpCompressionType(),
        );
    }

    public static function sanitizeKeepOriginal($value) {
        return !empty($value) ? 1 : 0;
    }

    public static function sanitizeForceWebpOutput($value) {
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
        $startedAt = microtime(true);

        if (!$this->isRunning()) {
            return array(
                'processed' => 0,
                'converted' => 0,
                'failed' => 0,
                'remaining' => $this->imageStatsService->countPendingImages(self::META_CONVERTED),
            );
        }

        if (!$this->acquireBatchLock()) {
            return array(
                'processed' => 0,
                'converted' => 0,
                'failed' => 0,
                'remaining' => $this->imageStatsService->countPendingImages(self::META_CONVERTED),
            );
        }

        try {
            $batchSize = self::sanitizeBatchSize(get_option(self::OPTION_BATCH_SIZE, self::DEFAULT_BATCH_SIZE));
            $conversionContext = $this->buildConversionContext();

            $query = new WP_Query(
                array(
                    'post_type' => 'attachment',
                    'post_status' => 'inherit',
                    'post_mime_type' => 'image',
                    'posts_per_page' => $batchSize,
                    'fields' => 'ids',
                    'orderby' => 'ID',
                    'order' => 'ASC',
                    'no_found_rows' => true,
                    'cache_results' => false,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                    'lazy_load_term_meta' => false,
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
                if ($this->processAttachment((int) $attachmentId, null, $conversionContext, false)) {
                    $converted++;
                } else {
                    $failed++;
                }
            }

            if ($processed > 0) {
                $this->imageStatsService->updateMonthStats(self::OPTION_MONTH_PREFIX, $processed, $converted, $failed);
                $durationMs = $this->calculateDurationMs($startedAt);
                $this->updatePerformanceStats($processed, $converted, $failed, $durationMs, $batchSize);
            }

            $remaining = $this->imageStatsService->countPendingImages(self::META_CONVERTED);
            if (0 === $remaining) {
                update_option(self::OPTION_RUNNING, 0);
            }

            $this->clearStatusCache();

            return array(
                'processed' => $processed,
                'converted' => $converted,
                'failed' => $failed,
                'remaining' => $remaining,
            );
        } finally {
            $this->releaseBatchLock();
        }
    }

    public function processAttachment($attachmentId, $attachmentMetadata = null, $conversionContext = null, $clearStatusCacheOnSuccess = true) {
        $filePath = '';
        $validationError = $this->validateAttachmentForConversion($attachmentId, $filePath);
        if ('' !== $validationError) {
            update_post_meta($attachmentId, self::META_FAILED, $validationError);
            return false;
        }

        $metadata = is_array($attachmentMetadata) ? $attachmentMetadata : wp_get_attachment_metadata($attachmentId);
        $context = is_array($conversionContext) ? $conversionContext : $this->buildConversionContext();
        $legacyPaths = array();
        $shouldKeepOriginal = !empty($context['keep_original']);

        if (!$shouldKeepOriginal) {
            $legacyPaths = $this->fileConversionService->collectLegacyPaths($filePath, $metadata);
        }

        $failureReason = '';
        $compressionType = isset($context['compression_type']) ? (string) $context['compression_type'] : $this->getWebpCompressionType();
        $quality = isset($context['quality']) ? (int) $context['quality'] : $this->getWebpQuality();

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
            if ($clearStatusCacheOnSuccess) {
                $this->clearStatusCache();
            }
            return true;
        }

        if ('' === $failureReason) {
            $failureReason = 'save_error';
        }

        update_post_meta($attachmentId, self::META_FAILED, $failureReason);

        return false;
    }

    public function getStatus() {
        $status = $this->imageStatsService->buildStatus(
            $this->isRunning(),
            self::META_CONVERTED,
            self::OPTION_MONTH_PREFIX,
            self::STATUS_CACHE_KEY,
            self::STATUS_CACHE_TTL
        );

        $status['performance'] = $this->getPerformanceStatus();

        return $status;
    }

    public function applyRecommendedBatchSize() {
        $recommended = $this->getRecommendedBatchSizeFromStats();
        update_option(self::OPTION_BATCH_SIZE, $recommended);

        return $recommended;
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

    public function isForceWebpOutputEnabled() {
        $value = get_option(self::OPTION_FORCE_WEBP_OUTPUT, self::DEFAULT_FORCE_WEBP_OUTPUT);

        return 1 === self::sanitizeForceWebpOutput($value);
    }

    public static function sanitizeBatchSize($value) {
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

    private function getBatchSize() {
        return self::sanitizeBatchSize(get_option(self::OPTION_BATCH_SIZE, self::DEFAULT_BATCH_SIZE));
    }

    private function clearStatusCache() {
        delete_transient(self::STATUS_CACHE_KEY);
    }

    private function buildConversionContext() {
        return array(
            'keep_original' => $this->isKeepOriginalEnabled(),
            'compression_type' => $this->getWebpCompressionType(),
            'quality' => $this->getWebpQuality(),
        );
    }

    private function acquireBatchLock() {
        $now = time();
        $lockTimestamp = (int) get_option(self::OPTION_BATCH_LOCK, 0);

        if ($lockTimestamp > 0 && ($now - $lockTimestamp) < self::BATCH_LOCK_TTL) {
            return false;
        }

        if ($lockTimestamp > 0) {
            delete_option(self::OPTION_BATCH_LOCK);
        }

        return add_option(self::OPTION_BATCH_LOCK, $now, '', 'no');
    }

    private function releaseBatchLock() {
        delete_option(self::OPTION_BATCH_LOCK);
    }

    private function calculateDurationMs($startedAt) {
        return (int) max(0, round((microtime(true) - (float) $startedAt) * 1000));
    }

    private function updatePerformanceStats($processed, $converted, $failed, $durationMs, $batchSize) {
        $stats = get_option(
            self::OPTION_PERFORMANCE_STATS,
            array(
                'runs' => 0,
                'processed_total' => 0,
                'converted_total' => 0,
                'failed_total' => 0,
                'duration_ms_total' => 0,
                'last_duration_ms' => 0,
                'last_processed' => 0,
                'last_batch_size' => 0,
                'last_run_at' => '',
            )
        );

        $stats['runs'] = (int) $stats['runs'] + 1;
        $stats['processed_total'] = (int) $stats['processed_total'] + (int) $processed;
        $stats['converted_total'] = (int) $stats['converted_total'] + (int) $converted;
        $stats['failed_total'] = (int) $stats['failed_total'] + (int) $failed;
        $stats['duration_ms_total'] = (int) $stats['duration_ms_total'] + (int) $durationMs;
        $stats['last_duration_ms'] = (int) $durationMs;
        $stats['last_processed'] = (int) $processed;
        $stats['last_batch_size'] = (int) $batchSize;
        $stats['last_run_at'] = current_time('mysql');

        update_option(self::OPTION_PERFORMANCE_STATS, $stats);
    }

    private function getPerformanceStatus() {
        $stats = get_option(self::OPTION_PERFORMANCE_STATS, array());

        $runs = isset($stats['runs']) ? (int) $stats['runs'] : 0;
        $processedTotal = isset($stats['processed_total']) ? (int) $stats['processed_total'] : 0;
        $durationTotal = isset($stats['duration_ms_total']) ? (int) $stats['duration_ms_total'] : 0;
        $averageMsPerImage = ($processedTotal > 0) ? round($durationTotal / $processedTotal, 2) : 0.0;
        $recommendedBatchSize = $this->getRecommendedBatchSize($averageMsPerImage);

        return array(
            'runs' => $runs,
            'last_duration_ms' => isset($stats['last_duration_ms']) ? (int) $stats['last_duration_ms'] : 0,
            'last_processed' => isset($stats['last_processed']) ? (int) $stats['last_processed'] : 0,
            'last_batch_size' => isset($stats['last_batch_size']) ? (int) $stats['last_batch_size'] : $this->getBatchSize(),
            'average_ms_per_image' => $averageMsPerImage,
            'recommended_batch_size' => $recommendedBatchSize,
            'last_run_at' => isset($stats['last_run_at']) ? (string) $stats['last_run_at'] : '',
        );
    }

    private function getRecommendedBatchSize($averageMsPerImage) {
        $targetBatchDurationMs = 12000;

        if ($averageMsPerImage <= 0) {
            return $this->getBatchSize();
        }

        $recommended = (int) floor($targetBatchDurationMs / (float) $averageMsPerImage);

        return self::sanitizeBatchSize($recommended);
    }

    private function getRecommendedBatchSizeFromStats() {
        $stats = get_option(self::OPTION_PERFORMANCE_STATS, array());
        $processedTotal = isset($stats['processed_total']) ? (int) $stats['processed_total'] : 0;
        $durationTotal = isset($stats['duration_ms_total']) ? (int) $stats['duration_ms_total'] : 0;
        $averageMsPerImage = ($processedTotal > 0) ? ($durationTotal / $processedTotal) : 0;

        return $this->getRecommendedBatchSize($averageMsPerImage);
    }
}
