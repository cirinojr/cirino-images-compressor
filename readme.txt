=== Cirino Images Compressor ===
Contributors: cirinojr
Tags: webp, image optimization, media library, performance, compression
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress image optimization with a fallback chain (local binaries > Imagick > GD), a transparency-safe PNG pipeline, and optional WebP/AVIF generation.

== Description ==
Cirino Images Compressor helps you optimize existing and newly uploaded media images with stronger compression while keeping Media Library compatibility.

Built for WordPress sites that need faster image delivery without losing operational control.

Features:
- Pipeline by format (JPEG, PNG, WebP, AVIF optional) with fallback chain:
	1) Local binaries (pngquant/oxipng/cwebp/avifenc when available)
	2) Imagick
	3) GD/WordPress standard encoder
- PNG aggressive mode with quantization support and transparency preservation.
- JPEG re-encode with configurable quality, metadata stripping, and progressive output when supported.
- Optional WebP generation for originals and WordPress sub-sizes.
- Optional AVIF generation only when real support exists.
- Real mime-type validation and SVG skip protection.
- Safe overwrite: backup-first strategy and keep-original-if-larger rule.
- Safer alternative generation: writes WebP/AVIF to temp files and only replaces final file on success.
- Retry ceiling for repeatedly failing attachments so batches can complete without infinite loops.
- Records the engine used for each optimized attachment.
- Converts newly uploaded images automatically.
- Batch optimization over WP-Cron with admin fallback execution while the tools screen is open.
- Admin fallback batch execution is throttled to reduce load during frequent status polling.
- Conversion lock to reduce concurrent processing race conditions.
- Live dashboard with monthly and global progress.
- Batch benchmark metrics (time per batch, average ms/image, recommended batch size).
- One-click "Apply recommended batch" action.
- Secure admin actions with nonce and capability checks.
- Debug logs in `error_log` when enabled.

Native WordPress hooks used:
- `wp_editor_set_quality`
- `image_editor_output_format`
- `wp_image_editors`

Requirements:
- For maximum PNG compression, install `pngquant` and `oxipng` binaries on server PATH.
- For binary WebP/AVIF generation, install `cwebp` and/or `avifenc`.
- Plugin still works with Imagick or GD fallback when binaries are unavailable.

== Frequently Asked Questions ==
= Will this convert all media files? =
No. The plugin processes only image attachments.

= Is PNG transparency preserved? =
Yes. PNG optimization keeps alpha/transparency and avoids destructive conversions.

= What if optimized output gets larger? =
The plugin keeps the original file automatically when an optimized result is larger or equal.

= What batch size should I use? =
Start with 20 and use the benchmark recommendation in Tools > Images Compressor.

= Does it work without real server cron? =
Yes. It uses WP-Cron and also processes fallback batches while the plugin admin page is open.

== Installation ==
1. Upload the cirino-images-compressor folder to /wp-content/plugins/.
2. Activate the plugin from the WordPress Plugins screen.
3. Go to Tools > Images Compressor.
4. Configure optimization level (`lossless`, `balanced`, `aggressive`, `ultra`) and per-format quality.
5. Optionally enable WebP/AVIF generation and debug logs.
6. Click Start optimization and monitor progress.

== Screenshots ==
1. Main Tools screen with actions and conversion status.
2. Settings section with batch size, quality, and conversion controls.
3. Live benchmark and recommended batch size panel.

== Upgrade Notice ==
= 0.2.0 =
Introduces aggressive/ultra optimization pipeline, format-aware fallback chain, and WordPress native hook integration.

== Changelog ==
= 0.2.0 =
* Added optimization levels: lossless, balanced, aggressive, ultra.
* Added JPEG/PNG/WebP/AVIF format-aware optimization pipeline.
* Added fallback chain: binaries -> Imagick -> GD.
* Added PNG quantization-first flow (pngquant + oxipng when available).
* Added safe backup + keep-original-if-larger behavior.
* Added real mime validation and SVG skip.
* Added engine-used tracking per attachment.
* Added expanded admin settings (metadata stripping, WebP/AVIF toggles, per-format quality).
* Added debug logging mode.
* Added test suite for JPEG, transparent PNG, and WebP generation flow.

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
