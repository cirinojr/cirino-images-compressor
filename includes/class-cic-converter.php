<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CICConverter {
    private const STATUS_CACHE_KEY = 'cic_status_counts';
    private const STATUS_CACHE_TTL = 10;

    public const META_CONVERTED = '_cic_optimized';
    public const META_CONVERTED_AT = '_cic_optimized_at';
    public const META_FAILED = '_cic_optimization_failed';
    public const META_LAST_ENGINE = '_cic_optimization_engine';

    public const OPTION_RUNNING = 'cic_is_running';
    public const OPTION_BATCH_SIZE = 'cic_batch_size';
    public const OPTION_BATCH_LOCK = 'cic_batch_lock';
    public const OPTION_PERFORMANCE_STATS = 'cic_performance_stats';
    public const OPTION_MONTH_PREFIX = 'cic_month_stats_';

    public const OPTION_OPTIMIZATION_LEVEL = 'cic_optimization_level';
    public const OPTION_STRIP_METADATA = 'cic_strip_metadata';
    public const OPTION_CONVERT_TO_WEBP = 'cic_convert_to_webp';
    public const OPTION_TRY_AVIF = 'cic_try_avif';
    public const OPTION_PRESERVE_ORIGINAL = 'cic_preserve_original';
    public const OPTION_FORCE_WEBP_OUTPUT = 'cic_force_webp_output';
    public const OPTION_DEBUG_MODE = 'cic_debug_mode';

    public const OPTION_JPEG_QUALITY = 'cic_jpeg_quality';
    public const OPTION_WEBP_QUALITY = 'cic_webp_quality';
    public const OPTION_AVIF_QUALITY = 'cic_avif_quality';
    public const OPTION_PNGQUANT_MIN_QUALITY = 'cic_pngquant_min_quality';
    public const OPTION_PNGQUANT_MAX_QUALITY = 'cic_pngquant_max_quality';

    public const LEVEL_LOSSLESS = 'lossless';
    public const LEVEL_BALANCED = 'balanced';
    public const LEVEL_AGGRESSIVE = 'aggressive';
    public const LEVEL_ULTRA = 'ultra';

    public const DEFAULT_BATCH_SIZE = 20;
    public const DEFAULT_OPTIMIZATION_LEVEL = self::LEVEL_BALANCED;
    public const DEFAULT_STRIP_METADATA = 1;
    public const DEFAULT_CONVERT_TO_WEBP = 1;
    public const DEFAULT_TRY_AVIF = 0;
    public const DEFAULT_PRESERVE_ORIGINAL = 1;
    public const DEFAULT_FORCE_WEBP_OUTPUT = 0;
    public const DEFAULT_DEBUG_MODE = 0;

    public const DEFAULT_JPEG_QUALITY = 78;
    public const DEFAULT_WEBP_QUALITY = 80;
    public const DEFAULT_AVIF_QUALITY = 50;
    public const DEFAULT_PNGQUANT_MIN_QUALITY = 65;
    public const DEFAULT_PNGQUANT_MAX_QUALITY = 85;

    private const MAX_BATCH_SIZE = 200;
    private const BATCH_LOCK_TTL = 300;

    /**
     * @var CICFileConversionService
     */
    private $fileConversionService;

    /**
     * @var CICImageStatsService
     */
    private $imageStatsService;

    /**
     * @var CICDebugLogger
     */
    private $logger;

    public function __construct() {
        $capabilities = new CICCapabilitiesDetector();
        $this->logger = new CICDebugLogger(self::OPTION_DEBUG_MODE);
        $this->fileConversionService = new CICFileConversionService($capabilities, $this->logger);
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

    /**
     * @return array<string,mixed>
     */
    public function getSettings() {
        return array(
            'batch_size' => $this->getBatchSize(),
            'optimization_level' => $this->getOptimizationLevel(),
            'strip_metadata' => $this->isEnabledOption(self::OPTION_STRIP_METADATA, self::DEFAULT_STRIP_METADATA),
            'convert_to_webp' => $this->isEnabledOption(self::OPTION_CONVERT_TO_WEBP, self::DEFAULT_CONVERT_TO_WEBP),
            'try_avif' => $this->isEnabledOption(self::OPTION_TRY_AVIF, self::DEFAULT_TRY_AVIF),
            'preserve_original' => $this->isEnabledOption(self::OPTION_PRESERVE_ORIGINAL, self::DEFAULT_PRESERVE_ORIGINAL),
            'force_webp_output' => $this->isEnabledOption(self::OPTION_FORCE_WEBP_OUTPUT, self::DEFAULT_FORCE_WEBP_OUTPUT),
            'debug_mode' => $this->isEnabledOption(self::OPTION_DEBUG_MODE, self::DEFAULT_DEBUG_MODE),
            'jpeg_quality' => $this->getJpegQuality(),
            'webp_quality' => $this->getWebpQuality(),
            'avif_quality' => $this->getAvifQuality(),
            'pngquant_min_quality' => $this->getPngquantMinQuality(),
            'pngquant_max_quality' => $this->getPngquantMaxQuality(),
        );
    }

    public static function sanitizeToggle($value) {
        return !empty($value) ? 1 : 0;
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

    public static function sanitizeOptimizationLevel($value) {
        $level = strtolower(trim((string) $value));
        $allowed = array(self::LEVEL_LOSSLESS, self::LEVEL_BALANCED, self::LEVEL_AGGRESSIVE, self::LEVEL_ULTRA);

        return in_array($level, $allowed, true) ? $level : self::DEFAULT_OPTIMIZATION_LEVEL;
    }

    public static function sanitizeQuality($value, $default = 80, $min = 1, $max = 100) {
        $quality = (int) $value;
        if ($quality < $min || $quality > $max) {
            return (int) $default;
        }

        return $quality;
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
            $options = $this->buildOptimizationOptions();

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
                if ($this->processAttachment((int) $attachmentId, null, $options, false)) {
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

    /**
     * @param int $attachmentId
     * @param array<string,mixed>|null $attachmentMetadata
     * @param array<string,mixed>|null $optimizationOptions
     * @param bool $clearStatusCacheOnSuccess
     *
     * @return bool
     */
    public function processAttachment($attachmentId, $attachmentMetadata = null, $optimizationOptions = null, $clearStatusCacheOnSuccess = true) {
        $filePath = '';
        $validationError = $this->validateAttachmentForConversion((int) $attachmentId, $filePath);
        if ('' !== $validationError) {
            update_post_meta($attachmentId, self::META_FAILED, $validationError);
            return false;
        }

        $metadata = is_array($attachmentMetadata) ? $attachmentMetadata : wp_get_attachment_metadata($attachmentId);
        $options = is_array($optimizationOptions) ? $optimizationOptions : $this->buildOptimizationOptions();

        $failureReason = '';
        $originalEngine = '';
        $thumbEngines = array();

        $originalSuccess = $this->fileConversionService->convertOriginalFile($filePath, $options, $failureReason, $originalEngine);
        $thumbnailSuccess = $this->fileConversionService->convertThumbnails($attachmentId, $filePath, $metadata, $options, $failureReason, $thumbEngines);
        $this->fileConversionService->generateAlternativeFormats($filePath, $options);

        if ($originalSuccess && $thumbnailSuccess) {
            update_post_meta($attachmentId, self::META_CONVERTED, '1');
            update_post_meta($attachmentId, self::META_CONVERTED_AT, current_time('mysql'));

            $engines = array_values(array_unique(array_filter(array_merge(array($originalEngine), $thumbEngines))));
            if (!empty($engines)) {
                update_post_meta($attachmentId, self::META_LAST_ENGINE, implode(', ', $engines));
            }

            delete_post_meta($attachmentId, self::META_FAILED);

            $this->logger->log('attachment_optimized', array(
                'attachment_id' => (int) $attachmentId,
                'file' => $filePath,
                'engines' => $engines,
                'options' => $options,
            ));

            if ($clearStatusCacheOnSuccess) {
                $this->clearStatusCache();
            }

            return true;
        }

        if ('' === $failureReason) {
            $failureReason = 'optimize_failed';
        }

        update_post_meta($attachmentId, self::META_FAILED, $failureReason);

        $this->logger->log('attachment_optimization_failed', array(
            'attachment_id' => (int) $attachmentId,
            'file' => $filePath,
            'failure' => $failureReason,
        ));

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
        $status['capabilities'] = $this->fileConversionService->getCapabilities();

        return $status;
    }

    public function applyRecommendedBatchSize() {
        $recommended = $this->getRecommendedBatchSizeFromStats();
        update_option(self::OPTION_BATCH_SIZE, $recommended);

        return $recommended;
    }

    public function isForceWebpOutputEnabled() {
        return $this->isEnabledOption(self::OPTION_FORCE_WEBP_OUTPUT, self::DEFAULT_FORCE_WEBP_OUTPUT);
    }

    public function getEditorQualityForMime($mimeType) {
        $mime = strtolower((string) $mimeType);

        if ('image/jpeg' === $mime || 'image/jpg' === $mime) {
            return $this->getJpegQuality();
        }

        if ('image/webp' === $mime) {
            return $this->getWebpQuality();
        }

        if ('image/avif' === $mime) {
            return $this->getAvifQuality();
        }

        return $this->getJpegQuality();
    }

    private function validateAttachmentForConversion($attachmentId, &$filePath) {
        if (!wp_attachment_is_image($attachmentId)) {
            return 'not_image';
        }

        $filePath = (string) get_attached_file($attachmentId);
        if ('' === $filePath || !file_exists($filePath)) {
            return 'missing_file';
        }

        if (!$this->fileConversionService->isPathInUploads($filePath)) {
            return 'invalid_file_path';
        }

        $mime = (string) get_post_mime_type($attachmentId);
        if (0 !== strpos($mime, 'image/') || 'image/svg+xml' === strtolower($mime)) {
            return 'invalid_mime';
        }

        return '';
    }

    private function isEnabledOption($key, $default = 0) {
        return 1 === self::sanitizeToggle(get_option((string) $key, (int) $default));
    }

    private function getBatchSize() {
        return self::sanitizeBatchSize(get_option(self::OPTION_BATCH_SIZE, self::DEFAULT_BATCH_SIZE));
    }

    private function getOptimizationLevel() {
        return self::sanitizeOptimizationLevel(get_option(self::OPTION_OPTIMIZATION_LEVEL, self::DEFAULT_OPTIMIZATION_LEVEL));
    }

    private function getJpegQuality() {
        return self::sanitizeQuality(get_option(self::OPTION_JPEG_QUALITY, self::DEFAULT_JPEG_QUALITY), self::DEFAULT_JPEG_QUALITY);
    }

    private function getWebpQuality() {
        return self::sanitizeQuality(get_option(self::OPTION_WEBP_QUALITY, self::DEFAULT_WEBP_QUALITY), self::DEFAULT_WEBP_QUALITY);
    }

    private function getAvifQuality() {
        return self::sanitizeQuality(get_option(self::OPTION_AVIF_QUALITY, self::DEFAULT_AVIF_QUALITY), self::DEFAULT_AVIF_QUALITY);
    }

    private function getPngquantMinQuality() {
        return self::sanitizeQuality(
            get_option(self::OPTION_PNGQUANT_MIN_QUALITY, self::DEFAULT_PNGQUANT_MIN_QUALITY),
            self::DEFAULT_PNGQUANT_MIN_QUALITY
        );
    }

    private function getPngquantMaxQuality() {
        return self::sanitizeQuality(
            get_option(self::OPTION_PNGQUANT_MAX_QUALITY, self::DEFAULT_PNGQUANT_MAX_QUALITY),
            self::DEFAULT_PNGQUANT_MAX_QUALITY
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildOptimizationOptions() {
        $level = $this->getOptimizationLevel();
        $preset = $this->getLevelPreset($level);

        $pngMin = $this->getPngquantMinQuality();
        $pngMax = $this->getPngquantMaxQuality();
        if ($pngMin > $pngMax) {
            $tmp = $pngMin;
            $pngMin = $pngMax;
            $pngMax = $tmp;
        }

        return array(
            'level' => $level,
            'strip_metadata' => $this->isEnabledOption(self::OPTION_STRIP_METADATA, self::DEFAULT_STRIP_METADATA),
            'preserve_original' => $this->isEnabledOption(self::OPTION_PRESERVE_ORIGINAL, self::DEFAULT_PRESERVE_ORIGINAL),
            'convert_to_webp' => $this->isEnabledOption(self::OPTION_CONVERT_TO_WEBP, self::DEFAULT_CONVERT_TO_WEBP),
            'try_avif' => $this->isEnabledOption(self::OPTION_TRY_AVIF, self::DEFAULT_TRY_AVIF),
            'jpeg_quality' => self::sanitizeQuality(get_option(self::OPTION_JPEG_QUALITY, $preset['jpeg_quality']), $preset['jpeg_quality']),
            'webp_quality' => self::sanitizeQuality(get_option(self::OPTION_WEBP_QUALITY, $preset['webp_quality']), $preset['webp_quality']),
            'avif_quality' => self::sanitizeQuality(get_option(self::OPTION_AVIF_QUALITY, $preset['avif_quality']), $preset['avif_quality']),
            'pngquant_min_quality' => $pngMin,
            'pngquant_max_quality' => $pngMax,
            'jpeg_progressive' => true,
            'compression_effort' => $preset['compression_effort'],
        );
    }

    /**
     * @param string $level
     *
     * @return array<string,int>
     */
    private function getLevelPreset($level) {
        if (self::LEVEL_LOSSLESS === $level) {
            return array(
                'jpeg_quality' => 92,
                'webp_quality' => 92,
                'avif_quality' => 60,
                'compression_effort' => 4,
            );
        }

        if (self::LEVEL_AGGRESSIVE === $level) {
            return array(
                'jpeg_quality' => 68,
                'webp_quality' => 72,
                'avif_quality' => 45,
                'compression_effort' => 6,
            );
        }

        if (self::LEVEL_ULTRA === $level) {
            return array(
                'jpeg_quality' => 62,
                'webp_quality' => 66,
                'avif_quality' => 38,
                'compression_effort' => 6,
            );
        }

        return array(
            'jpeg_quality' => self::DEFAULT_JPEG_QUALITY,
            'webp_quality' => self::DEFAULT_WEBP_QUALITY,
            'avif_quality' => self::DEFAULT_AVIF_QUALITY,
            'compression_effort' => 5,
        );
    }

    private function clearStatusCache() {
        delete_transient(self::STATUS_CACHE_KEY);
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

        $stats['runs'] = (int) $stats['runs'] + (int) 1;
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

    /**
     * @return array<string,mixed>
     */
    private function getPerformanceStatus() {
        $stats = get_option(self::OPTION_PERFORMANCE_STATS, array());

        $processedTotal = isset($stats['processed_total']) ? (int) $stats['processed_total'] : 0;
        $durationTotal = isset($stats['duration_ms_total']) ? (int) $stats['duration_ms_total'] : 0;
        $averageMsPerImage = ($processedTotal > 0) ? round($durationTotal / $processedTotal, 2) : 0.0;

        return array(
            'runs' => isset($stats['runs']) ? (int) $stats['runs'] : 0,
            'last_duration_ms' => isset($stats['last_duration_ms']) ? (int) $stats['last_duration_ms'] : 0,
            'last_processed' => isset($stats['last_processed']) ? (int) $stats['last_processed'] : 0,
            'last_batch_size' => isset($stats['last_batch_size']) ? (int) $stats['last_batch_size'] : $this->getBatchSize(),
            'average_ms_per_image' => $averageMsPerImage,
            'recommended_batch_size' => $this->getRecommendedBatchSize($averageMsPerImage),
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
