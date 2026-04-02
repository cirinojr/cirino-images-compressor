<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CICLifecycle {
    /**
     * Option key used to store plugin version.
     */
    private const OPTION_VERSION = 'cic_plugin_version';

    /**
     * Handle plugin activation tasks.
     *
     * @return void
     */
    public static function activate() {
        add_filter('cron_schedules', array('CICLifecycle', 'registerCronSchedules'));
        update_option(self::OPTION_VERSION, CIC_VERSION);
        add_option(CICConverter::OPTION_OPTIMIZATION_LEVEL, CICConverter::DEFAULT_OPTIMIZATION_LEVEL);
        add_option(CICConverter::OPTION_STRIP_METADATA, CICConverter::DEFAULT_STRIP_METADATA);
        add_option(CICConverter::OPTION_CONVERT_TO_WEBP, CICConverter::DEFAULT_CONVERT_TO_WEBP);
        add_option(CICConverter::OPTION_TRY_AVIF, CICConverter::DEFAULT_TRY_AVIF);
        add_option(CICConverter::OPTION_PRESERVE_ORIGINAL, CICConverter::DEFAULT_PRESERVE_ORIGINAL);
        add_option(CICConverter::OPTION_FORCE_WEBP_OUTPUT, CICConverter::DEFAULT_FORCE_WEBP_OUTPUT);
        add_option(CICConverter::OPTION_BATCH_SIZE, CICConverter::DEFAULT_BATCH_SIZE);
        add_option(CICConverter::OPTION_JPEG_QUALITY, CICConverter::DEFAULT_JPEG_QUALITY);
        add_option(CICConverter::OPTION_WEBP_QUALITY, CICConverter::DEFAULT_WEBP_QUALITY);
        add_option(CICConverter::OPTION_AVIF_QUALITY, CICConverter::DEFAULT_AVIF_QUALITY);
        add_option(CICConverter::OPTION_PNGQUANT_MIN_QUALITY, CICConverter::DEFAULT_PNGQUANT_MIN_QUALITY);
        add_option(CICConverter::OPTION_PNGQUANT_MAX_QUALITY, CICConverter::DEFAULT_PNGQUANT_MAX_QUALITY);
        add_option(CICConverter::OPTION_DEBUG_MODE, CICConverter::DEFAULT_DEBUG_MODE);
        add_option(CICConverter::OPTION_PERFORMANCE_STATS, array());

        if (!wp_next_scheduled(CICPlugin::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, CICPlugin::CRON_RECURRENCE, CICPlugin::CRON_HOOK);
        }
    }

    /**
     * Handle plugin deactivation tasks.
     *
     * @return void
     */
    public static function deactivate() {
        wp_clear_scheduled_hook(CICPlugin::CRON_HOOK);
        delete_option(CICConverter::OPTION_RUNNING);
        delete_option(CICConverter::OPTION_BATCH_LOCK);
        delete_option(CICConverter::OPTION_PERFORMANCE_STATS);
    }

    /**
     * Handle plugin uninstall tasks.
     *
     * @return void
     */
    public static function uninstall() {
        global $wpdb;

        $optionNames = array(
            self::OPTION_VERSION,
            CICConverter::OPTION_RUNNING,
            CICConverter::OPTION_BATCH_SIZE,
            CICConverter::OPTION_OPTIMIZATION_LEVEL,
            CICConverter::OPTION_STRIP_METADATA,
            CICConverter::OPTION_CONVERT_TO_WEBP,
            CICConverter::OPTION_TRY_AVIF,
            CICConverter::OPTION_PRESERVE_ORIGINAL,
            CICConverter::OPTION_FORCE_WEBP_OUTPUT,
            CICConverter::OPTION_JPEG_QUALITY,
            CICConverter::OPTION_WEBP_QUALITY,
            CICConverter::OPTION_AVIF_QUALITY,
            CICConverter::OPTION_PNGQUANT_MIN_QUALITY,
            CICConverter::OPTION_PNGQUANT_MAX_QUALITY,
            CICConverter::OPTION_DEBUG_MODE,
            CICConverter::OPTION_BATCH_LOCK,
            CICConverter::OPTION_PERFORMANCE_STATS,
        );

        foreach ($optionNames as $optionName) {
            delete_option($optionName);
        }

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like(CICConverter::OPTION_MONTH_PREFIX) . '%'
            )
        );

        delete_metadata('post', 0, CICConverter::META_CONVERTED, '', true);
        delete_metadata('post', 0, CICConverter::META_CONVERTED_AT, '', true);
        delete_metadata('post', 0, CICConverter::META_FAILED, '', true);
        delete_metadata('post', 0, CICConverter::META_LAST_ENGINE, '', true);
        delete_metadata('post', 0, CICConverter::META_ATTEMPTS, '', true);
    }

    public static function registerCronSchedules($schedules) {
        $schedules[CICPlugin::CRON_RECURRENCE] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every 5 minutes', 'cirino-images-compressor'),
        );

        return $schedules;
    }
}
