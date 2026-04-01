<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CICImagickOptimizerProvider implements CICOptimizerInterface {
    /**
     * @var CICCapabilitiesDetector
     */
    private $capabilities;

    public function __construct(CICCapabilitiesDetector $capabilities) {
        $this->capabilities = $capabilities;
    }

    public function getName() {
        return 'imagick';
    }

    public function supports($mimeType) {
        $caps = $this->capabilities->getCapabilities();
        if (empty($caps['imagick']['available'])) {
            return false;
        }

        $mime = strtolower((string) $mimeType);

        return in_array($mime, array('image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/avif'), true);
    }

    public function optimize($sourcePath, $destPath, $options) {
        if (!$this->supports(isset($options['source_mime']) ? (string) $options['source_mime'] : '')) {
            return array(
                'success' => false,
                'engine' => $this->getName(),
                'reason' => 'imagick_unavailable',
            );
        }

        $sourceMime = isset($options['source_mime']) ? strtolower((string) $options['source_mime']) : '';
        $targetMime = isset($options['target_mime']) ? strtolower((string) $options['target_mime']) : $sourceMime;
        $removeMetadata = !empty($options['strip_metadata']);

        try {
            $image = new Imagick();
            $image->readImage($sourcePath);

            if ($removeMetadata) {
                $image->stripImage();
            }

            if (in_array($targetMime, array('image/jpeg', 'image/jpg'), true)) {
                $quality = isset($options['jpeg_quality']) ? (int) $options['jpeg_quality'] : 80;
                $image->setImageFormat('jpeg');
                $image->setImageCompressionQuality($quality);
                if (!empty($options['jpeg_progressive']) && defined('Imagick::INTERLACE_PLANE')) {
                    $image->setInterlaceScheme(Imagick::INTERLACE_PLANE);
                }
            } elseif ('image/png' === $targetMime) {
                $level = isset($options['level']) ? (string) $options['level'] : 'balanced';
                $colors = $this->getPngColorsByLevel($level);
                if ($colors > 0) {
                    $image->quantizeImage($colors, Imagick::COLORSPACE_RGB, 0, false, false);
                }
                $image->setImageFormat('png');
                $image->setOption('png:compression-level', '9');
                $image->setOption('png:compression-filter', '5');
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
            } elseif ('image/webp' === $targetMime) {
                $quality = isset($options['webp_quality']) ? (int) $options['webp_quality'] : 80;
                $image->setImageFormat('webp');
                $image->setImageCompressionQuality($quality);
            } elseif ('image/avif' === $targetMime) {
                $quality = isset($options['avif_quality']) ? (int) $options['avif_quality'] : 50;
                $image->setImageFormat('avif');
                $image->setImageCompressionQuality($quality);
            } else {
                $image->clear();
                $image->destroy();

                return array(
                    'success' => false,
                    'engine' => $this->getName(),
                    'reason' => 'unsupported_target',
                );
            }

            $wrote = $image->writeImage($destPath);
            $image->clear();
            $image->destroy();

            return array(
                'success' => (bool) $wrote,
                'engine' => $this->getName(),
                'reason' => $wrote ? '' : 'imagick_write_failed',
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'engine' => $this->getName(),
                'reason' => 'imagick_exception:' . $e->getMessage(),
            );
        }
    }

    /**
     * @param string $level
     *
     * @return int
     */
    private function getPngColorsByLevel($level) {
        if ('lossless' === $level) {
            return 0;
        }

        if ('balanced' === $level) {
            return 256;
        }

        if ('aggressive' === $level) {
            return 192;
        }

        return 128;
    }
}
