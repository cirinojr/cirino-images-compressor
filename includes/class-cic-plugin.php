<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CICPlugin {
    public const CRON_HOOK = 'cic_process_batch_event';
    public const CRON_RECURRENCE = 'cic_every_five_minutes';
    private const MIME_WEBP = 'image/webp';

    /**
     * Plugin singleton instance.
     *
    * @var CICPlugin|null
     */
    private static $instance = null;

    /**
     * @var CICConverter
     */
    private $converter;

    /**
     * Private constructor to enforce singleton usage.
     */
    private function __construct() {
        $this->converter = new CICConverter();
    }

    /**
     * Get plugin singleton instance.
     *
     * @return CICPlugin
     */
    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register plugin runtime hooks.
     *
     * @return void
     */
    public function run() {
        add_action('init', array($this, 'loadTextdomain'));
        add_filter('cron_schedules', array($this, 'registerCronSchedules'));
        add_action(self::CRON_HOOK, array($this, 'processCronBatch'));
        add_filter('wp_editor_set_quality', array($this, 'filterEditorQuality'), 10, 2);
        add_filter('image_editor_output_format', array($this, 'filterEditorOutputFormat'));
        add_filter('wp_image_editors', array($this, 'prioritizeImageEditors'));
        add_filter('wp_generate_attachment_metadata', array($this, 'autoConvertUploadedImage'), 20, 2);

        if (is_admin()) {
            add_action('admin_init', array($this, 'ensureCronScheduled'));
            $adminPage = new CICAdminPage($this->converter);
            $adminPage->registerHooks();
        }
    }

    /**
     * Load plugin translation files.
     *
     * @return void
     */
    public function loadTextdomain() {
        load_plugin_textdomain(
            'cirino-images-compressor',
            false,
            dirname(plugin_basename(CIC_PLUGIN_FILE)) . '/languages'
        );
    }

    public function registerCronSchedules($schedules) {
        $schedules[self::CRON_RECURRENCE] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every 5 minutes', 'cirino-images-compressor'),
        );

        return $schedules;
    }

    public function ensureCronScheduled() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_RECURRENCE, self::CRON_HOOK);
        }
    }

    public function processCronBatch() {
        $this->converter->processBatch();
    }

    public function autoConvertUploadedImage($metadata, $attachmentId) {
        if (!wp_attachment_is_image($attachmentId)) {
            return $metadata;
        }

        $this->converter->processAttachment((int) $attachmentId, $metadata);

        return $metadata;
    }

    public function filterEditorQuality($quality, $mimeType) {
        return $this->converter->getEditorQualityForMime((string) $mimeType);
    }

    public function filterEditorOutputFormat($formats) {
        if (!$this->converter->isForceWebpOutputEnabled()) {
            return $formats;
        }

        if (!wp_image_editor_supports(array('mime_type' => self::MIME_WEBP))) {
            return $formats;
        }

        if (!is_array($formats)) {
            $formats = array();
        }

        $formats['image/jpeg'] = self::MIME_WEBP;
        $formats['image/png'] = self::MIME_WEBP;

        return $formats;
    }

    public function prioritizeImageEditors($editors) {
        if (!is_array($editors)) {
            $editors = array();
        }

        $priority = array('WP_Image_Editor_Imagick', 'WP_Image_Editor_GD');
        foreach (array_reverse($priority) as $className) {
            $currentIndex = array_search($className, $editors, true);
            if (false !== $currentIndex) {
                unset($editors[$currentIndex]);
            }

            array_unshift($editors, $className);
        }

        return array_values(array_unique($editors));
    }
}
