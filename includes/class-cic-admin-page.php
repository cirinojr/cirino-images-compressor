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
        add_action('wp_ajax_cic_apply_recommended_batch', array($this, 'handleApplyRecommendedBatch'));
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

        wp_enqueue_style('cic-admin-style', CIC_PLUGIN_URL . 'assets/css/admin.css', array(), CIC_VERSION);
        wp_enqueue_script('cic-admin-script', CIC_PLUGIN_URL . 'assets/js/admin.js', array(), CIC_VERSION, true);

        wp_localize_script(
            'cic-admin-script',
            'cicAdminConfig',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(self::NONCE_ACTION_AJAX),
                'pollInterval' => 10000,
                'i18n' => array(
                    'running' => __('Running', 'cirino-images-compressor'),
                    'stopped' => __('Stopped', 'cirino-images-compressor'),
                    'available' => __('Available', 'cirino-images-compressor'),
                    'unavailable' => __('Unavailable', 'cirino-images-compressor'),
                    'statusErrorShort' => __('Status error', 'cirino-images-compressor'),
                    'statusErrorToast' => __('Status update failed. Please try again.', 'cirino-images-compressor'),
                    'monthBatchProcessed' => __('processed', 'cirino-images-compressor'),
                    'monthBatchConverted' => __('converted', 'cirino-images-compressor'),
                    'monthBatchFailed' => __('failed', 'cirino-images-compressor'),
                    'updatedAt' => __('updated at', 'cirino-images-compressor'),
                    'benchmarkRuns' => __('runs', 'cirino-images-compressor'),
                    'benchmarkLast' => __('last', 'cirino-images-compressor'),
                    'benchmarkAvg' => __('avg', 'cirino-images-compressor'),
                    'benchmarkRecommended' => __('recommended batch', 'cirino-images-compressor'),
                    'imagesAbbr' => __('imgs', 'cirino-images-compressor'),
                    'msPerImage' => __('ms/img', 'cirino-images-compressor'),
                    'applying' => __('Applying...', 'cirino-images-compressor'),
                    'applied' => __('Applied', 'cirino-images-compressor'),
                    'applyFailed' => __('Apply failed', 'cirino-images-compressor'),
                    'appliedWithValue' => __('Applied: %d', 'cirino-images-compressor'),
                    'startSuccess' => __('Optimization started successfully.', 'cirino-images-compressor'),
                    'startError' => __('Could not start optimization.', 'cirino-images-compressor'),
                    'stopSuccess' => __('Optimization stopped.', 'cirino-images-compressor'),
                    'stopError' => __('Could not stop optimization.', 'cirino-images-compressor'),
                    'applySuccessWithValue' => __('Recommended batch applied: %d.', 'cirino-images-compressor'),
                    'applySuccess' => __('Recommended batch applied.', 'cirino-images-compressor'),
                    'applyError' => __('Could not apply recommended batch.', 'cirino-images-compressor'),
                ),
            )
        );
    }

    public function renderPage() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->converter->getSettings();
        $settingsSaved = isset($_GET['cic_settings']) && '1' === sanitize_text_field(wp_unslash($_GET['cic_settings']));

        ?>
        <div class="wrap cic-wrap">
            <div class="cic-shell">
                <header class="cic-header-card">
                    <div class="cic-header-main">
                        <div class="cic-brand-mark" aria-hidden="true">
                            <img id="cic-brand-logo" src="<?php echo esc_url(CIC_PLUGIN_URL . 'assets/images/cic-logo.png'); ?>" alt="<?php echo esc_attr__('Cirino Images Compressor Logo', 'cirino-images-compressor'); ?>" />
                            <span class="cic-brand-fallback">CIC</span>
                        </div>
                        <div>
                        <h1 class="cic-title"><?php echo esc_html__('Cirino Images Compressor', 'cirino-images-compressor'); ?></h1>
                        <p class="cic-description"><?php echo esc_html__('Aggressive image optimization with safe fallback chain (Binary > Imagick > GD) and optional WebP/AVIF generation.', 'cirino-images-compressor'); ?></p>
                        </div>
                    </div>
                    <div class="cic-head-meta">
                        <span class="cic-pill" id="cic-processing-pill"><?php echo esc_html__('Stopped', 'cirino-images-compressor'); ?></span>
                        <span class="cic-head-sync" id="cic-last-sync">-</span>
                    </div>
                </header>

                <nav class="cic-tabs" role="tablist" aria-label="<?php echo esc_attr__('Sections', 'cirino-images-compressor'); ?>">
                    <button type="button" class="cic-tab is-active" id="cic-tab-config" data-tab-target="config" role="tab" aria-selected="true" aria-controls="cic-panel-config"><?php echo esc_html__('Settings', 'cirino-images-compressor'); ?></button>
                    <button type="button" class="cic-tab" id="cic-tab-batch" data-tab-target="batch" role="tab" aria-selected="false" aria-controls="cic-panel-batch"><?php echo esc_html__('Batch & Status', 'cirino-images-compressor'); ?></button>
                </nav>

                <?php if ($settingsSaved) : ?>
                    <div class="notice notice-success is-dismissible">
                        <p><?php echo esc_html__('Settings saved.', 'cirino-images-compressor'); ?></p>
                    </div>
                <?php endif; ?>

                <div class="cic-toast" id="cic-toast" role="status" aria-live="polite" aria-atomic="true"></div>

                <section class="cic-panel is-active" id="cic-panel-config" data-tab-panel="config" role="tabpanel" aria-labelledby="cic-tab-config">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cic-settings-form cic-card">
                        <div class="cic-card-head">
                            <h2><?php echo esc_html__('Optimization Settings', 'cirino-images-compressor'); ?></h2>
                            <p><?php echo esc_html__('Configure quality, output formats, safety and processing behavior.', 'cirino-images-compressor'); ?></p>
                        </div>
                        <input type="hidden" name="action" value="cic_save_settings" />
                        <?php wp_nonce_field(self::NONCE_ACTION_SETTINGS, self::NONCE_FIELD); ?>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php echo esc_html__('Batch size', 'cirino-images-compressor'); ?></th>
                                <td>
                                    <input type="number" name="cic_batch_size" min="1" max="200" value="<?php echo esc_attr((string) $settings['batch_size']); ?>" class="small-text" />
                                    <p class="description"><?php echo esc_html__('How many images are processed per batch run (1 to 200).', 'cirino-images-compressor'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Optimization level', 'cirino-images-compressor'); ?></th>
                                <td>
                                    <select name="cic_optimization_level">
                                        <option value="lossless" <?php selected($settings['optimization_level'], CICConverter::LEVEL_LOSSLESS); ?>><?php echo esc_html__('Lossless', 'cirino-images-compressor'); ?></option>
                                        <option value="balanced" <?php selected($settings['optimization_level'], CICConverter::LEVEL_BALANCED); ?>><?php echo esc_html__('Balanced', 'cirino-images-compressor'); ?></option>
                                        <option value="aggressive" <?php selected($settings['optimization_level'], CICConverter::LEVEL_AGGRESSIVE); ?>><?php echo esc_html__('Aggressive', 'cirino-images-compressor'); ?></option>
                                        <option value="ultra" <?php selected($settings['optimization_level'], CICConverter::LEVEL_ULTRA); ?>><?php echo esc_html__('Ultra', 'cirino-images-compressor'); ?></option>
                                    </select>
                                    <p class="description"><?php echo esc_html__('Aggressive and Ultra target stronger size reduction while preserving visual quality.', 'cirino-images-compressor'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('JPEG quality', 'cirino-images-compressor'); ?></th>
                                <td><input type="number" name="cic_jpeg_quality" min="1" max="100" value="<?php echo esc_attr((string) $settings['jpeg_quality']); ?>" class="small-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('WebP quality', 'cirino-images-compressor'); ?></th>
                                <td><input type="number" name="cic_webp_quality" min="1" max="100" value="<?php echo esc_attr((string) $settings['webp_quality']); ?>" class="small-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('AVIF quality', 'cirino-images-compressor'); ?></th>
                                <td><input type="number" name="cic_avif_quality" min="1" max="100" value="<?php echo esc_attr((string) $settings['avif_quality']); ?>" class="small-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('PNGQuant min quality', 'cirino-images-compressor'); ?></th>
                                <td><input type="number" name="cic_pngquant_min_quality" min="1" max="100" value="<?php echo esc_attr((string) $settings['pngquant_min_quality']); ?>" class="small-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('PNGQuant max quality', 'cirino-images-compressor'); ?></th>
                                <td><input type="number" name="cic_pngquant_max_quality" min="1" max="100" value="<?php echo esc_attr((string) $settings['pngquant_max_quality']); ?>" class="small-text" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Remove metadata', 'cirino-images-compressor'); ?></th>
                                <td><label><input type="checkbox" name="cic_strip_metadata" value="1" <?php checked(!empty($settings['strip_metadata'])); ?> /> <?php echo esc_html__('Strip EXIF/metadata when possible', 'cirino-images-compressor'); ?></label></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Convert to WebP', 'cirino-images-compressor'); ?></th>
                                <td><label><input type="checkbox" name="cic_convert_to_webp" value="1" <?php checked(!empty($settings['convert_to_webp'])); ?> /> <?php echo esc_html__('Generate WebP alternatives for original and sub-sizes', 'cirino-images-compressor'); ?></label></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Try AVIF', 'cirino-images-compressor'); ?></th>
                                <td><label><input type="checkbox" name="cic_try_avif" value="1" <?php checked(!empty($settings['try_avif'])); ?> /> <?php echo esc_html__('Generate AVIF only when server supports it', 'cirino-images-compressor'); ?></label></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Preserve original backup', 'cirino-images-compressor'); ?></th>
                                <td><label><input type="checkbox" name="cic_preserve_original" value="1" <?php checked(!empty($settings['preserve_original'])); ?> /> <?php echo esc_html__('Keep .cic-bak backup after successful optimization', 'cirino-images-compressor'); ?></label></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Force WebP output in editor hook', 'cirino-images-compressor'); ?></th>
                                <td><label><input type="checkbox" name="cic_force_webp_output" value="1" <?php checked(!empty($settings['force_webp_output'])); ?> /> <?php echo esc_html__('Enable image_editor_output_format mapping to WebP', 'cirino-images-compressor'); ?></label></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Debug logs', 'cirino-images-compressor'); ?></th>
                                <td><label><input type="checkbox" name="cic_debug_mode" value="1" <?php checked(!empty($settings['debug_mode'])); ?> /> <?php echo esc_html__('Write debug info to PHP error_log', 'cirino-images-compressor'); ?></label></td>
                            </tr>
                        </table>

                        <?php submit_button(__('Save settings', 'cirino-images-compressor')); ?>
                    </form>
                </section>

                <section class="cic-panel" id="cic-panel-batch" data-tab-panel="batch" role="tabpanel" aria-labelledby="cic-tab-batch" hidden>
                    <div class="cic-summary-grid">
                        <div class="cic-summary-card">
                            <span><?php echo esc_html__('Process status', 'cirino-images-compressor'); ?></span>
                            <strong id="cic-status-running">-</strong>
                        </div>
                        <div class="cic-summary-card">
                            <span><?php echo esc_html__('Pending images', 'cirino-images-compressor'); ?></span>
                            <strong id="cic-status-pending">-</strong>
                        </div>
                        <div class="cic-summary-card">
                            <span><?php echo esc_html__('Total image attachments', 'cirino-images-compressor'); ?></span>
                            <strong id="cic-status-total-images">-</strong>
                        </div>
                        <div class="cic-summary-card">
                            <span><?php echo esc_html__('Current month progress', 'cirino-images-compressor'); ?></span>
                            <strong id="cic-status-month">-</strong>
                        </div>
                    </div>

                    <div class="cic-card cic-actions-card">
                        <h2><?php echo esc_html__('Bulk Actions', 'cirino-images-compressor'); ?></h2>
                        <p><?php echo esc_html__('Use these controls to run, stop and tune mass optimization without leaving this screen.', 'cirino-images-compressor'); ?></p>
                        <div class="cic-actions">
                            <button type="button" class="cic-btn cic-btn-primary" id="cic-start-btn"><?php echo esc_html__('Start optimization', 'cirino-images-compressor'); ?></button>
                            <button type="button" class="cic-btn cic-btn-secondary" id="cic-stop-btn"><?php echo esc_html__('Stop optimization', 'cirino-images-compressor'); ?></button>
                            <button type="button" class="cic-btn cic-btn-secondary" id="cic-apply-recommended-btn"><?php echo esc_html__('Apply recommended batch', 'cirino-images-compressor'); ?></button>
                        </div>
                    </div>

                    <div class="cic-card">
                        <h2><?php echo esc_html__('Status & Results', 'cirino-images-compressor'); ?></h2>
                        <div class="cic-status-grid">
                            <div class="cic-status-row"><span class="cic-label"><?php echo esc_html__('WebP support', 'cirino-images-compressor'); ?></span><span class="cic-value" id="cic-status-webp-support">-</span></div>
                            <div class="cic-status-row"><span class="cic-label"><?php echo esc_html__('Capabilities', 'cirino-images-compressor'); ?></span><span class="cic-value" id="cic-status-capabilities">-</span></div>
                            <div class="cic-status-row"><span class="cic-label"><?php echo esc_html__('Total progress', 'cirino-images-compressor'); ?></span><span class="cic-value" id="cic-status-total">-</span></div>
                            <div class="cic-status-row"><span class="cic-label"><?php echo esc_html__('Last monthly batch summary', 'cirino-images-compressor'); ?></span><span class="cic-value" id="cic-status-month-batch">-</span></div>
                            <div class="cic-status-row"><span class="cic-label"><?php echo esc_html__('Batch performance benchmark', 'cirino-images-compressor'); ?></span><span class="cic-value" id="cic-status-performance">-</span></div>
                        </div>
                    </div>
                </section>
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

    public function handleApplyRecommendedBatch() {
        $this->assertRequestIsValid();
        $recommended = $this->converter->applyRecommendedBatchSize();
        $status = $this->converter->getStatus();
        $status['batch_size_applied'] = (int) $recommended;
        wp_send_json_success($status);
    }

    public function handleSaveSettings() {
        if ('POST' !== strtoupper((string) $_SERVER['REQUEST_METHOD'])) {
            wp_die(esc_html__('Invalid request method.', 'cirino-images-compressor'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized request.', 'cirino-images-compressor'));
        }

        check_admin_referer(self::NONCE_ACTION_SETTINGS, self::NONCE_FIELD);

        update_option(CICConverter::OPTION_BATCH_SIZE, CICConverter::sanitizeBatchSize(isset($_POST['cic_batch_size']) ? wp_unslash($_POST['cic_batch_size']) : CICConverter::DEFAULT_BATCH_SIZE));
        update_option(CICConverter::OPTION_OPTIMIZATION_LEVEL, CICConverter::sanitizeOptimizationLevel(isset($_POST['cic_optimization_level']) ? wp_unslash($_POST['cic_optimization_level']) : CICConverter::DEFAULT_OPTIMIZATION_LEVEL));

        update_option(CICConverter::OPTION_STRIP_METADATA, CICConverter::sanitizeToggle(isset($_POST['cic_strip_metadata']) ? '1' : '0'));
        update_option(CICConverter::OPTION_CONVERT_TO_WEBP, CICConverter::sanitizeToggle(isset($_POST['cic_convert_to_webp']) ? '1' : '0'));
        update_option(CICConverter::OPTION_TRY_AVIF, CICConverter::sanitizeToggle(isset($_POST['cic_try_avif']) ? '1' : '0'));
        update_option(CICConverter::OPTION_PRESERVE_ORIGINAL, CICConverter::sanitizeToggle(isset($_POST['cic_preserve_original']) ? '1' : '0'));
        update_option(CICConverter::OPTION_FORCE_WEBP_OUTPUT, CICConverter::sanitizeToggle(isset($_POST['cic_force_webp_output']) ? '1' : '0'));
        update_option(CICConverter::OPTION_DEBUG_MODE, CICConverter::sanitizeToggle(isset($_POST['cic_debug_mode']) ? '1' : '0'));

        update_option(CICConverter::OPTION_JPEG_QUALITY, CICConverter::sanitizeQuality(isset($_POST['cic_jpeg_quality']) ? wp_unslash($_POST['cic_jpeg_quality']) : CICConverter::DEFAULT_JPEG_QUALITY, CICConverter::DEFAULT_JPEG_QUALITY));
        update_option(CICConverter::OPTION_WEBP_QUALITY, CICConverter::sanitizeQuality(isset($_POST['cic_webp_quality']) ? wp_unslash($_POST['cic_webp_quality']) : CICConverter::DEFAULT_WEBP_QUALITY, CICConverter::DEFAULT_WEBP_QUALITY));
        update_option(CICConverter::OPTION_AVIF_QUALITY, CICConverter::sanitizeQuality(isset($_POST['cic_avif_quality']) ? wp_unslash($_POST['cic_avif_quality']) : CICConverter::DEFAULT_AVIF_QUALITY, CICConverter::DEFAULT_AVIF_QUALITY));
        update_option(CICConverter::OPTION_PNGQUANT_MIN_QUALITY, CICConverter::sanitizeQuality(isset($_POST['cic_pngquant_min_quality']) ? wp_unslash($_POST['cic_pngquant_min_quality']) : CICConverter::DEFAULT_PNGQUANT_MIN_QUALITY, CICConverter::DEFAULT_PNGQUANT_MIN_QUALITY));
        update_option(CICConverter::OPTION_PNGQUANT_MAX_QUALITY, CICConverter::sanitizeQuality(isset($_POST['cic_pngquant_max_quality']) ? wp_unslash($_POST['cic_pngquant_max_quality']) : CICConverter::DEFAULT_PNGQUANT_MAX_QUALITY, CICConverter::DEFAULT_PNGQUANT_MAX_QUALITY));

        $redirectUrl = add_query_arg(array('page' => self::PAGE_SLUG, 'cic_settings' => '1'), admin_url('tools.php'));
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
