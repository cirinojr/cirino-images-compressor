# Cirino Images Compressor

WordPress plugin for practical image optimization in the Media Library, with a safe fallback chain and clear operational controls.

![Plugin banner](docs/images/banner.png)

## Overview

Cirino Images Compressor helps editors reduce image size without changing their normal WordPress workflow.

The plugin focuses on:

- Real file size reduction with configurable quality levels
- Safe processing (backup-first behavior and keep-original-if-larger checks)
- Broad compatibility through fallback engines when preferred tools are not available

## Features

### Optimization levels

- `lossless`
- `balanced`
- `aggressive`
- `ultra`

### Format-aware pipeline

- **JPEG/JPG**: re-encode with configurable quality, optional progressive output, optional metadata stripping
- **PNG**: optimization flow that prioritizes `pngquant` + `oxipng` when available, with transparency preservation
- **WebP**: optional generation for originals and WordPress sub-sizes
- **AVIF**: optional generation only when server support is available

### Fallback chain

1. Local binaries (`pngquant`, `oxipng`, `cwebp`, `avifenc`)
2. Imagick
3. GD / default WordPress encoder

### Safety and reliability

- Real MIME-type validation
- SVG skip protection
- Backup before overwrite
- Keep-original-if-larger rule
- Temporary-file strategy for WebP/AVIF generation before final replace
- Retry ceiling for repeatedly failing attachments
- Throttled admin fallback processing while status polling is active

### WordPress integration

- Works with Media Library originals and generated sizes
- Supports bulk processing with status reporting in `Tools > Images Compressor`
- Uses native hooks:
  - `wp_editor_set_quality`
  - `image_editor_output_format`
  - `wp_image_editors`
  - `wp_generate_attachment_metadata`

## Installation

1. Copy the plugin folder to `wp-content/plugins/cirino-images-compressor`.
2. Activate the plugin in the WordPress admin.
3. Open `Tools > Images Compressor`.
4. Choose optimization settings and start bulk optimization.

## Usage

1. Set batch size and optimization level.
2. Configure JPEG/WebP/AVIF quality values as needed.
3. Optionally enable WebP and AVIF generation.
4. Start optimization and monitor progress in the Batch & Status tab.

## Screenshots

### Settings

![Settings screen](docs/images/screenshot-settings.png)

### Batch and status

![Batch and status screen](docs/images/screenshot-batch.png)

## Project structure

- `includes/class-cic-capabilities-detector.php`: environment capability detection
- `includes/class-cic-optimizer-interface.php`: optimizer provider contract
- `includes/class-cic-binary-optimizer-provider.php`: local binary optimizer provider
- `includes/class-cic-imagick-optimizer-provider.php`: Imagick optimizer provider
- `includes/class-cic-gd-optimizer-provider.php`: GD optimizer provider
- `includes/class-cic-file-conversion-service.php`: conversion and fallback orchestration
- `includes/class-cic-converter.php`: batch workflow and attachment processing
- `includes/class-cic-admin-page.php`: admin UI and controls

## Local development

Run tests locally:

```bash
composer install
composer test
```

## Repository assets

This README references the following image files:

- `docs/images/banner.png`
- `docs/images/screenshot-settings.png`
- `docs/images/screenshot-batch.png`

## License

GPLv2 or later.
