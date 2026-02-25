<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CICImageStatsService {
    /**
     * @var CICFileConversionService
     */
    private $fileConversionService;

    public function __construct(CICFileConversionService $fileConversionService) {
        $this->fileConversionService = $fileConversionService;
    }

    public function countPendingImages($convertedMetaKey) {
        $query = new WP_Query(
            $this->buildImageCountQueryArgs(
                array(
                    'meta_query' => array(
                        'relation' => 'OR',
                        array(
                            'key' => $convertedMetaKey,
                            'compare' => 'NOT EXISTS',
                        ),
                        array(
                            'key' => $convertedMetaKey,
                            'value' => '1',
                            'compare' => '!=',
                        ),
                    ),
                )
            )
        );

        return (int) $query->found_posts;
    }

    public function updateMonthStats($monthOptionPrefix, $processed, $converted, $failed) {
        $optionKey = $this->getMonthOptionKey($monthOptionPrefix);
        $current = get_option(
            $optionKey,
            array(
                'processed' => 0,
                'converted' => 0,
                'failed' => 0,
                'updated_at' => '',
            )
        );

        $current['processed'] = (int) $current['processed'] + (int) $processed;
        $current['converted'] = (int) $current['converted'] + (int) $converted;
        $current['failed'] = (int) $current['failed'] + (int) $failed;
        $current['updated_at'] = current_time('mysql');

        update_option($optionKey, $current);
    }

    public function buildStatus($isRunning, $convertedMetaKey, $monthOptionPrefix, $statusCacheKey, $statusCacheTtl) {
        $counts = get_transient($statusCacheKey);
        if (!is_array($counts)) {
            $counts = array(
                'month_total' => $this->countImagesByMonth(false, $convertedMetaKey),
                'month_converted' => $this->countImagesByMonth(true, $convertedMetaKey),
                'total_images' => $this->countAllImages(),
                'total_converted' => $this->countConvertedImages($convertedMetaKey),
                'pending' => $this->countPendingImages($convertedMetaKey),
            );

            set_transient($statusCacheKey, $counts, $statusCacheTtl);
        }

        return array(
            'running' => $isRunning,
            'webp_supported' => $this->fileConversionService->isWebpSupported(),
            'month' => array(
                'total' => $counts['month_total'],
                'converted' => $counts['month_converted'],
                'percentage' => $this->calculatePercentage($counts['month_converted'], $counts['month_total']),
            ),
            'total' => array(
                'total' => $counts['total_images'],
                'converted' => $counts['total_converted'],
                'percentage' => $this->calculatePercentage($counts['total_converted'], $counts['total_images']),
            ),
            'pending' => $counts['pending'],
            'last_month_batch' => get_option($this->getMonthOptionKey($monthOptionPrefix), array()),
        );
    }

    private function getMonthOptionKey($monthOptionPrefix) {
        return (string) $monthOptionPrefix . wp_date('Y_m');
    }

    private function countAllImages() {
        $query = new WP_Query($this->buildImageCountQueryArgs());

        return (int) $query->found_posts;
    }

    private function countConvertedImages($convertedMetaKey) {
        $query = new WP_Query(
            $this->buildImageCountQueryArgs(
                array(
                    'meta_query' => array(
                        array(
                            'key' => $convertedMetaKey,
                            'value' => '1',
                            'compare' => '=',
                        ),
                    ),
                )
            )
        );

        return (int) $query->found_posts;
    }

    private function countImagesByMonth($onlyConverted, $convertedMetaKey) {
        $year = (int) wp_date('Y');
        $month = (int) wp_date('m');

        $queryArgs = $this->buildImageCountQueryArgs(
            array(
                'date_query' => array(
                    array(
                        'year' => $year,
                        'month' => $month,
                    ),
                ),
            )
        );

        if ($onlyConverted) {
            $queryArgs['meta_query'] = array(
                array(
                    'key' => $convertedMetaKey,
                    'value' => '1',
                    'compare' => '=',
                ),
            );
        }

        $query = new WP_Query($queryArgs);

        return (int) $query->found_posts;
    }

    private function calculatePercentage($value, $total) {
        if ($total <= 0) {
            return 0;
        }

        return (float) number_format(((int) $value / (int) $total) * 100, 2, '.', '');
    }

    private function buildImageCountQueryArgs($args = array()) {
        return array_merge(
            array(
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'no_found_rows' => false,
                'cache_results' => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'lazy_load_term_meta' => false,
            ),
            $args
        );
    }
}
