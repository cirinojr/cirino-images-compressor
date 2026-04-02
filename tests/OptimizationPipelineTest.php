<?php

use PHPUnit\Framework\TestCase;

final class CICFakeOptimizer implements CICOptimizerInterface {
    /**
     * @var string
     */
    private $name;

    /**
     * @var callable
     */
    private $callback;

    /**
     * @var int
     */
    public $calls = 0;

    public function __construct($name, $callback) {
        $this->name = (string) $name;
        $this->callback = $callback;
    }

    public function supports($mimeType) {
        return true;
    }

    public function optimize($sourcePath, $destPath, $options) {
        $this->calls++;
        $callback = $this->callback;

        return $callback($sourcePath, $destPath, $options);
    }

    public function getName() {
        return $this->name;
    }
}

final class OptimizationPipelineTest extends TestCase {
    /**
     * @var string
     */
    private $uploadsDir;

    protected function setUp(): void {
        $this->uploadsDir = $GLOBALS['cic_test_uploads_basedir'];
        if (!is_dir($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0777, true);
        }
    }

    public function testJpegOptimizationReducesSizeAndReplacesFile(): void {
        $source = $this->uploadsDir . DIRECTORY_SEPARATOR . 'sample.jpg';
        file_put_contents($source, base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxAQEBUQEA8VFRUVFRUQFRUVFRUVFRUVFhUVFRUYHSggGBolGxUVITEhJSkrLi4uFx8zODMsNygtLisBCgoKDg0OGhAQGi0fHR0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAAEAAQMBIgACEQEDEQH/xAAWAAEBAQAAAAAAAAAAAAAAAAAAAQL/xAAXAQEBAQEAAAAAAAAAAAAAAAAAAQIR/9oADAMBAAIQAxAAAAH2QAAAAAAAAAAAAAAAP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAQUCcf/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQMBAT8Bj//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQIBAT8Bj//Z'));

        $optimizer = new CICFakeOptimizer('jpeg-test', function ($sourcePath, $destPath, $options) {
            file_put_contents($destPath, str_repeat('a', 64));
            return array('success' => true, 'engine' => 'fake-jpeg');
        });

        $service = new CICFileConversionService(new CICCapabilitiesDetector(), new CICDebugLogger(), array($optimizer));
        $result = $service->optimizeFile($source, array('preserve_original' => 0));

        $this->assertTrue($result['success']);
        $this->assertSame('optimized', $result['reason']);
        $this->assertSame(1, $optimizer->calls);
        $this->assertFileDoesNotExist($source . '.cic-bak');
    }

    public function testTransparentPngUsesFallbackWithoutBreakingFile(): void {
        $source = $this->uploadsDir . DIRECTORY_SEPARATOR . 'transparent.png';
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIW2P4z8DwHwAFgwJ/lv9L8QAAAABJRU5ErkJggg=='));

        $first = new CICFakeOptimizer('first-fail', function ($sourcePath, $destPath, $options) {
            return array('success' => false, 'engine' => 'first-fail', 'reason' => 'forced_failure');
        });

        $second = new CICFakeOptimizer('second-ok', function ($sourcePath, $destPath, $options) {
            copy($sourcePath, $destPath);
            return array('success' => true, 'engine' => 'second-ok');
        });

        $service = new CICFileConversionService(new CICCapabilitiesDetector(), new CICDebugLogger(), array($first, $second));
        $result = $service->optimizeFile($source, array('preserve_original' => 1));

        $imageInfo = getimagesize($source);
        $this->assertTrue($result['success']);
        $this->assertSame(1, $first->calls);
        $this->assertSame(1, $second->calls);
        $this->assertIsArray($imageInfo);
        $this->assertSame('image/png', $imageInfo['mime']);
    }

    public function testWebpGenerationForSubsize(): void {
        $source = $this->uploadsDir . DIRECTORY_SEPARATOR . 'to-webp.png';
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAGUlEQVQImWNgoBpgYGBoYGBg+M+ABBgAAN2kA0QvC4GAAAAAElFTkSuQmCC'));

        $optimizer = new CICFakeOptimizer('webp-provider', function ($sourcePath, $destPath, $options) {
            if ('image/webp' === $options['target_mime']) {
                file_put_contents($destPath, str_repeat('w', 10));
                return array('success' => true, 'engine' => 'fake-webp');
            }

            copy($sourcePath, $destPath);
            return array('success' => true, 'engine' => 'copy');
        });

        $service = new CICFileConversionService(new CICCapabilitiesDetector(), new CICDebugLogger(), array($optimizer));
        $service->generateAlternativeFormats($source, array('convert_to_webp' => 1, 'try_avif' => 0));

        $this->assertFileExists($this->uploadsDir . DIRECTORY_SEPARATOR . 'to-webp.webp');
    }

    public function testFailedAlternativeGenerationPreservesExistingDestination(): void {
        $source = $this->uploadsDir . DIRECTORY_SEPARATOR . 'existing-webp-source.png';
        $dest = $this->uploadsDir . DIRECTORY_SEPARATOR . 'existing-webp-source.webp';

        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAGUlEQVQImWNgoBpgYGBoYGBg+M+ABBgAAN2kA0QvC4GAAAAAElFTkSuQmCC'));
        file_put_contents($dest, 'existing_webp_payload');

        $optimizer = new CICFakeOptimizer('webp-fail', function ($sourcePath, $destPath, $options) {
            if ('image/webp' === $options['target_mime']) {
                return array('success' => false, 'engine' => 'fail-webp', 'reason' => 'forced_failure');
            }

            copy($sourcePath, $destPath);
            return array('success' => true, 'engine' => 'copy');
        });

        $service = new CICFileConversionService(new CICCapabilitiesDetector(), new CICDebugLogger(), array($optimizer));
        $service->generateAlternativeFormats($source, array('convert_to_webp' => 1, 'try_avif' => 0));

        $this->assertFileExists($dest);
        $this->assertSame('existing_webp_payload', file_get_contents($dest));
    }

    public function testLargerAlternativeGenerationDoesNotReplaceExistingDestination(): void {
        $source = $this->uploadsDir . DIRECTORY_SEPARATOR . 'larger-webp-source.png';
        $dest = $this->uploadsDir . DIRECTORY_SEPARATOR . 'larger-webp-source.webp';

        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAGUlEQVQImWNgoBpgYGBoYGBg+M+ABBgAAN2kA0QvC4GAAAAAElFTkSuQmCC'));
        file_put_contents($dest, 'existing_small_webp');

        $optimizer = new CICFakeOptimizer('webp-larger', function ($sourcePath, $destPath, $options) {
            if ('image/webp' === $options['target_mime']) {
                file_put_contents($destPath, str_repeat('w', 240));
                return array('success' => true, 'engine' => 'larger-webp');
            }

            copy($sourcePath, $destPath);
            return array('success' => true, 'engine' => 'copy');
        });

        $service = new CICFileConversionService(new CICCapabilitiesDetector(), new CICDebugLogger(), array($optimizer));
        $service->generateAlternativeFormats($source, array('convert_to_webp' => 1, 'try_avif' => 0));

        $this->assertFileExists($dest);
        $this->assertSame('existing_small_webp', file_get_contents($dest));
    }

    public function testOptimizeFileRejectsPathOutsideUploads(): void {
        $outsidePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cic-outside-test.jpg';
        file_put_contents($outsidePath, str_repeat('x', 64));

        $service = new CICFileConversionService(new CICCapabilitiesDetector(), new CICDebugLogger(), array());
        $result = $service->optimizeFile($outsidePath, array('preserve_original' => 0));

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_file_path', $result['reason']);

        @unlink($outsidePath);
    }

    public function testSupportedMimeTypesIncludeOnlyProcessableImageFormats(): void {
        $service = new CICFileConversionService(new CICCapabilitiesDetector(), new CICDebugLogger(), array());
        $supported = $service->getSupportedMimeTypes();

        $this->assertSame(array('image/jpeg', 'image/png', 'image/webp', 'image/avif'), $supported);
    }

    public function testUnsupportedImageMimeIsRejectedBySupportGuard(): void {
        $service = new CICFileConversionService(new CICCapabilitiesDetector(), new CICDebugLogger(), array());

        $this->assertTrue($service->isMimeTypeSupportedForOptimization('image/jpeg'));
        $this->assertFalse($service->isMimeTypeSupportedForOptimization('image/gif'));
        $this->assertFalse($service->isMimeTypeSupportedForOptimization('image/svg+xml'));
    }
}
