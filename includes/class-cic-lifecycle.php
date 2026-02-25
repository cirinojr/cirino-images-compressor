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
        add_option(CICConverter::OPTION_KEEP_ORIGINAL, CICConverter::DEFAULT_KEEP_ORIGINAL);
        add_option(CICConverter::OPTION_WEBP_QUALITY, CICConverter::DEFAULT_WEBP_QUALITY);
        add_option(CICConverter::OPTION_WEBP_COMPRESSION_TYPE, CICConverter::DEFAULT_WEBP_COMPRESSION_TYPE);

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
            CICConverter::OPTION_KEEP_ORIGINAL,
            CICConverter::OPTION_WEBP_QUALITY,
            CICConverter::OPTION_WEBP_COMPRESSION_TYPE,
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
    }

    public static function registerCronSchedules($schedules) {
        $schedules[CICPlugin::CRON_RECURRENCE] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every 5 minutes', 'cirino-images-compressor'),
        );

        return $schedules;
    }
}
