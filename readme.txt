=== Cirino Images Compressor ===
Contributors: cirinojr
Tags: webp, image optimization, media library, performance, compression
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert and optimize Media Library images to WebP with safe batch processing, progress metrics, and practical admin controls.

== Description ==
Cirino Images Compressor helps you convert existing and newly uploaded media images to WebP in a controlled and safe way.

Built for WordPress sites that need faster image delivery without losing operational control.

Features:
- Converts only image attachments (ignores non-image attachments).
- Converts originals and generated thumbnails to WebP.
- Optionally keeps or removes legacy original files after successful conversion.
- Optional forced default output format to WebP for generated WordPress image sizes (when supported).
- Converts newly uploaded images automatically.
- Batch conversion over WP-Cron with admin fallback execution while the tools screen is open.
- Conversion lock to reduce concurrent processing race conditions.
- Live dashboard with monthly and global progress.
- Batch benchmark metrics (time per batch, average ms/image, recommended batch size).
- One-click "Apply recommended batch" action.
- Secure admin actions with nonce and capability checks.

Requirements:
- The active WordPress image editor (GD or Imagick) must support image/webp.
- Lossless compression mode uses quality 100.

== Frequently Asked Questions ==
= Will this convert all media files? =
No. The plugin processes only image attachments.

= Is it safe to remove originals? =
Yes, if you have backups and your theme/plugins do not rely on legacy file extensions directly.

= What batch size should I use? =
Start with 20 and use the benchmark recommendation in Tools > Images Compressor.

= Does it work without real server cron? =
Yes. It uses WP-Cron and also processes fallback batches while the plugin admin page is open.

== Installation ==
1. Upload the cirino-images-compressor folder to /wp-content/plugins/.
2. Activate the plugin from the WordPress Plugins screen.
3. Go to Tools > Images Compressor.
4. Configure batch size, quality, compression type, and retention options.
5. Click Start conversion and monitor progress.

== Screenshots ==
1. Main Tools screen with actions and conversion status.
2. Settings section with batch size, quality, and conversion controls.
3. Live benchmark and recommended batch size panel.

== Upgrade Notice ==
= 0.1.1 =
Adds benchmark-driven batch tuning, one-click recommended batch apply, improved admin feedback, and hardening updates.

== Changelog ==
= 0.1.1 =
* Added benchmark metrics for batch duration and average ms/image.
* Added recommended batch size calculation and one-click apply action.
* Added safer admin UX to reduce duplicate action requests.
* Added toast feedback with success/error states.
* Improved admin JavaScript internationalization support.
* Improved processing lock behavior and minor internal cleanups.

= 0.1.0 =
* Added bulk WebP conversion engine.
* Added cron-based batch processing.
* Added admin dashboard with progress metrics.
