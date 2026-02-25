=== Cirino Images Compressor ===
Contributors: cirino
Tags: image, images, compressor, optimization
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Initial foundation for the Cirino Images Compressor plugin.

== Description ==
Converts media library images to WebP in bulk using WordPress cron.

Features:
- Processes only image attachments (ignores videos and non-image files).
- Converts original image files and generated thumbnails to WebP.
- Sets WebP as the default format for converted attachments.
- Runs conversion in scheduled batches via WP-Cron.
- While Tools > Images Compressor is open, status checks also advance one batch.
- Converts new uploaded images automatically right after upload.
- Includes settings for keeping/removing original files, WebP quality, and compression type.
- Includes an admin page powered by Vanilla JavaScript.
- Displays progress for current month and total library images.

Requirements:
- The active WordPress image editor (GD or Imagick) must support `image/webp`.
- Compression type `lossless` is applied as maximum quality (`100`).

== Installation ==
1. Upload the `cirino-images-compressor` folder to `/wp-content/plugins/`.
2. Activate the plugin in the WordPress Plugins menu.
3. Go to Tools > Images Compressor and click Start conversion.

== Changelog ==
= 0.1.0 =
* Added bulk WebP conversion engine.
* Added cron-based batch processing.
* Added admin dashboard with progress metrics.
