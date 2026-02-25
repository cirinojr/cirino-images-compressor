<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CICAdminPage {
    private const PAGE_SLUG = 'cirino-images-compressor';
    private const NONCE_FIELD = 'cic_nonce';
    private const NONCE_ACTION_SETTINGS = 'cic_admin_settings';
    private const NONCE_ACTION_AJAX = 'cic_admin_ajax';

    /**
     * @var CICConverter
     */
    private $converter;

    public function __construct(CICConverter $converter) {
        $this->converter = $converter;
    }

    public function registerHooks() {
        add_action('admin_menu', array($this, 'registerMenu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueAssets'));
        add_action('admin_post_cic_save_settings', array($this, 'handleSaveSettings'));
        add_action('wp_ajax_cic_start_conversion', array($this, 'handleStart'));
        add_action('wp_ajax_cic_stop_conversion', array($this, 'handleStop'));
        add_action('wp_ajax_cic_get_status', array($this, 'handleStatus'));
    }

    public function registerMenu() {
        add_management_page(
            __('Cirino Images Compressor', 'cirino-images-compressor'),
            __('Images Compressor', 'cirino-images-compressor'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'renderPage')
        );
    }

    public function enqueueAssets($hookSuffix) {
        if ('tools_page_' . self::PAGE_SLUG !== $hookSuffix) {
            return;
        }

        wp_enqueue_style(
            'cic-admin-style',
            CIC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CIC_VERSION
        );

        wp_enqueue_script(
            'cic-admin-script',
            CIC_PLUGIN_URL . 'assets/js/admin.js',
            array(),
            CIC_VERSION,
            true
        );

        wp_localize_script(
            'cic-admin-script',
            'cicAdminConfig',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(self::NONCE_ACTION_AJAX),
            )
        );
    }

    public function renderPage() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->converter->getSettings();
        $settingsSaved = false;

        if (isset($_GET['cic_settings'])) {
            $settingsSaved = '1' === sanitize_text_field(wp_unslash($_GET['cic_settings']));
        }

        ?>
        <div class="wrap cic-wrap">
            <h1 class="cic-title"><?php echo esc_html__('Cirino Images Compressor', 'cirino-images-compressor'); ?></h1>
            <p class="cic-description"><?php echo esc_html__('Bulk conversion to WebP runs through WordPress cron and progresses from this screen as well. Only image attachments are processed.', 'cirino-images-compressor'); ?></p>

            <?php if ($settingsSaved) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__('Settings saved.', 'cirino-images-compressor'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cic-settings-form">
                <input type="hidden" name="action" value="cic_save_settings" />
                <?php wp_nonce_field(self::NONCE_ACTION_SETTINGS, self::NONCE_FIELD); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Keep original image', 'cirino-images-compressor'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="cic_keep_original" value="1" <?php checked(!empty($settings['keep_original'])); ?> />
                                <?php echo esc_html__('Keep original files after converting to WebP', 'cirino-images-compressor'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('WebP quality', 'cirino-images-compressor'); ?></th>
                        <td>
                            <input type="number" name="cic_webp_quality" min="1" max="100" value="<?php echo esc_attr((string) $settings['webp_quality']); ?>" class="small-text" />
                            <p class="description"><?php echo esc_html__('Valid range: 1 to 100.', 'cirino-images-compressor'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('WebP compression type', 'cirino-images-compressor'); ?></th>
                        <td>
                            <select name="cic_webp_compression_type">
                                <option value="<?php echo esc_attr(CICConverter::COMPRESSION_LOSSY); ?>" <?php selected($settings['webp_compression_type'], CICConverter::COMPRESSION_LOSSY); ?>><?php echo esc_html__('Lossy', 'cirino-images-compressor'); ?></option>
                                <option value="<?php echo esc_attr(CICConverter::COMPRESSION_LOSSLESS); ?>" <?php selected($settings['webp_compression_type'], CICConverter::COMPRESSION_LOSSLESS); ?>><?php echo esc_html__('Lossless', 'cirino-images-compressor'); ?></option>
                            </select>
                            <p class="description"><?php echo esc_html__('Lossless uses maximum quality (100).', 'cirino-images-compressor'); ?></p>
                        </td>
                    </tr>
                </table>

                <p><?php echo esc_html__('Converted attachments are set to use WebP as the default media file format.', 'cirino-images-compressor'); ?></p>

                <?php submit_button(__('Save settings', 'cirino-images-compressor')); ?>
            </form>

            <div class="cic-actions">
                <button type="button" class="cic-btn cic-btn-primary" id="cic-start-btn"><?php echo esc_html__('Start conversion', 'cirino-images-compressor'); ?></button>
                <button type="button" class="cic-btn cic-btn-secondary" id="cic-stop-btn"><?php echo esc_html__('Stop conversion', 'cirino-images-compressor'); ?></button>
            </div>

            <div class="cic-status-grid">
                <div class="cic-status-row">
                    <span class="cic-label"><?php echo esc_html__('Process status', 'cirino-images-compressor'); ?></span>
                    <span class="cic-value" id="cic-status-running">-</span>
                </div>
                <div class="cic-status-row">
                    <span class="cic-label"><?php echo esc_html__('WebP support', 'cirino-images-compressor'); ?></span>
                    <span class="cic-value" id="cic-status-webp-support">-</span>
                </div>
                <div class="cic-status-row">
                    <span class="cic-label"><?php echo esc_html__('Current month progress', 'cirino-images-compressor'); ?></span>
                    <span class="cic-value" id="cic-status-month">-</span>
                </div>
                <div class="cic-status-row">
                    <span class="cic-label"><?php echo esc_html__('Total progress', 'cirino-images-compressor'); ?></span>
                    <span class="cic-value" id="cic-status-total">-</span>
                </div>
                <div class="cic-status-row">
                    <span class="cic-label"><?php echo esc_html__('Total image attachments', 'cirino-images-compressor'); ?></span>
                    <span class="cic-value" id="cic-status-total-images">-</span>
                </div>
                <div class="cic-status-row">
                    <span class="cic-label"><?php echo esc_html__('Pending images', 'cirino-images-compressor'); ?></span>
                    <span class="cic-value" id="cic-status-pending">-</span>
                </div>
                <div class="cic-status-row">
                    <span class="cic-label"><?php echo esc_html__('Last monthly batch summary', 'cirino-images-compressor'); ?></span>
                    <span class="cic-value" id="cic-status-month-batch">-</span>
                </div>
            </div>
        </div>
        <?php
    }

    public function handleStart() {
        $this->assertRequestIsValid();
        $this->converter->start();
        $this->runBatchFallback();
        wp_send_json_success($this->converter->getStatus());
    }

    public function handleStop() {
        $this->assertRequestIsValid();
        $this->converter->stop();
        wp_send_json_success($this->converter->getStatus());
    }

    public function handleStatus() {
        $this->assertRequestIsValid();
        $this->runBatchFallback();
        wp_send_json_success($this->converter->getStatus());
    }

    public function handleSaveSettings() {
        if ('POST' !== strtoupper((string) $_SERVER['REQUEST_METHOD'])) {
            wp_die(esc_html__('Invalid request method.', 'cirino-images-compressor'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized request.', 'cirino-images-compressor'));
        }

        check_admin_referer(self::NONCE_ACTION_SETTINGS, self::NONCE_FIELD);

        $keepOriginal = isset($_POST['cic_keep_original']) ? '1' : '0';
        $qualityRaw = isset($_POST['cic_webp_quality']) ? wp_unslash($_POST['cic_webp_quality']) : CICConverter::DEFAULT_WEBP_QUALITY;
        $compressionRaw = isset($_POST['cic_webp_compression_type']) ? wp_unslash($_POST['cic_webp_compression_type']) : CICConverter::DEFAULT_WEBP_COMPRESSION_TYPE;

        update_option(CICConverter::OPTION_KEEP_ORIGINAL, CICConverter::sanitizeKeepOriginal($keepOriginal));
        update_option(CICConverter::OPTION_WEBP_QUALITY, CICConverter::sanitizeWebpQuality($qualityRaw));
        update_option(CICConverter::OPTION_WEBP_COMPRESSION_TYPE, CICConverter::sanitizeWebpCompressionType($compressionRaw));

        $redirectUrl = add_query_arg(
            array(
                'page' => self::PAGE_SLUG,
                'cic_settings' => '1',
            ),
            admin_url('tools.php')
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    private function runBatchFallback() {
        if (!$this->converter->isRunning()) {
            return;
        }

        $this->converter->processBatch();
    }

    private function assertRequestIsValid() {
        if (!wp_doing_ajax()) {
            wp_send_json_error(array('message' => __('Invalid request context.', 'cirino-images-compressor')), 400);
        }

        if ('POST' !== strtoupper((string) $_SERVER['REQUEST_METHOD'])) {
            wp_send_json_error(array('message' => __('Invalid request method.', 'cirino-images-compressor')), 405);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized request.', 'cirino-images-compressor')), 403);
        }

        check_ajax_referer(self::NONCE_ACTION_AJAX, 'nonce');
    }
}
