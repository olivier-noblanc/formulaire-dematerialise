<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Attachment\AttachmentService;
use App\Core\Database;

final class AttachmentServiceTest extends TestCase
{
    private AttachmentService $attachmentService;

    protected function setUp(): void
    {
        $app = \App\Core\App::getInstance();
        $repo = $app->get(\App\Repository\AttachmentRepository::class);
        $this->attachmentService = new AttachmentService($repo);
    }

    public function testGetAllowedMimeTypesReturnsArray(): void
    {
        $mimeTypes = $this->attachmentService->getAllowedMimeTypes();
        $this->assertIsArray($mimeTypes);
        $this->assertNotEmpty($mimeTypes);
        $this->assertContains('application/pdf', $mimeTypes);
        $this->assertContains('image/jpeg', $mimeTypes);
    }

    public function testGetAllowedExtensionsReturnsArray(): void
    {
        $extensions = $this->attachmentService->getAllowedExtensions();
        $this->assertIsArray($extensions);
        $this->assertNotEmpty($extensions);
        $this->assertContains('pdf', $extensions);
        $this->assertContains('jpg', $extensions);
    }

    public function testGetMaxFileSizeReturnsTenMegaBytes(): void
    {
        $maxSize = $this->attachmentService->getMaxFileSize();
        $this->assertSame(10 * 1024 * 1024, $maxSize);
    }

    public function testHandleFileUploadReturnsErrorOnUploadError(): void
    {
        $file = [
            'error' => UPLOAD_ERR_NO_FILE,
            'tmp_name' => '',
            'name' => '',
            'size' => 0,
            'type' => '',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'test-submission-id', 'field_name');
        $this->assertFalse($result['success']);
        $this->assertNull($result['attachment_id']);
    }

    public function testHandleFileUploadReturnsErrorOnOversize(): void
    {
        $file = [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => '',
            'name' => 'large-file.pdf',
            'size' => 20 * 1024 * 1024, // 20 Mo > 10 Mo max
            'type' => 'application/pdf',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'test-submission-id', 'field_name');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('10 Mo', $result['message']);
    }

    public function testHandleFileUploadReturnsErrorOnDisallowedExtension(): void
    {
        $file = [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => '',
            'name' => 'malware.exe',
            'size' => 1024,
            'type' => 'application/octet-stream',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'test-submission-id', 'field_name');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('non autorisé', $result['message']);
    }

    public function testGetAttachmentsReturnsEmptyArrayForNonexistentId(): void
    {
        $attachments = $this->attachmentService->getAttachments('nonexistent-id');
        $this->assertIsArray($attachments);
        $this->assertEmpty($attachments);
    }

    public function testGetAttachmentByIdReturnsNullForNonexistentId(): void
    {
        $attachment = $this->attachmentService->getAttachmentById('nonexistent-id');
        $this->assertNull($attachment);
    }

    // ── handleFileUpload() error paths ──────────────────────────

    public function testHandleFileUploadUploadErrIniSize(): void
    {
        $file = [
            'error' => UPLOAD_ERR_INI_SIZE,
            'tmp_name' => '',
            'name' => 'test.pdf',
            'size' => 0,
            'type' => 'application/pdf',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('taille maximale', $result['message']);
    }

    public function testHandleFileUploadUploadErrPartial(): void
    {
        $file = [
            'error' => UPLOAD_ERR_PARTIAL,
            'tmp_name' => '',
            'name' => 'test.pdf',
            'size' => 100,
            'type' => 'application/pdf',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('partiellement', $result['message']);
    }

    public function testHandleFileUploadUploadErrNoTmpDir(): void
    {
        $file = [
            'error' => UPLOAD_ERR_NO_TMP_DIR,
            'tmp_name' => '',
            'name' => 'test.pdf',
            'size' => 0,
            'type' => 'application/pdf',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Dossier temporaire', $result['message']);
    }

    public function testHandleFileUploadUploadErrCantWrite(): void
    {
        $file = [
            'error' => UPLOAD_ERR_CANT_WRITE,
            'tmp_name' => '',
            'name' => 'test.pdf',
            'size' => 0,
            'type' => 'application/pdf',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('écriture', $result['message']);
    }

    public function testHandleFileUploadDangerousDoubleExtension(): void
    {
        $file = [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => '',
            'name' => 'file.php.jpg',
            'size' => 100,
            'type' => 'image/jpeg',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('doubles extensions', $result['message']);
    }

    public function testHandleFileUploadDangerousPhpExtension(): void
    {
        $file = [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => '',
            'name' => 'malware.php',
            'size' => 100,
            'type' => 'application/octet-stream',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
        $this->assertFalse($result['success']);
    }

    // ── getAllowedMimeTypes() additional checks ──────────────────

    public function testGetAllowedMimeTypesContainsImageTypes(): void
    {
        $types = $this->attachmentService->getAllowedMimeTypes();
        $this->assertContains('image/jpeg', $types);
        $this->assertContains('image/png', $types);
        $this->assertContains('image/gif', $types);
    }

    public function testGetAllowedMimeTypesContainsDocumentTypes(): void
    {
        $types = $this->attachmentService->getAllowedMimeTypes();
        $this->assertContains('application/pdf', $types);
        $this->assertContains('text/plain', $types);
        $this->assertContains('text/csv', $types);
    }

    // ── getAllowedExtensions() additional checks ─────────────────

    public function testGetAllowedExtensionsContainsImageExts(): void
    {
        $exts = $this->attachmentService->getAllowedExtensions();
        $this->assertContains('jpg', $exts);
        $this->assertContains('jpeg', $exts);
        $this->assertContains('png', $exts);
        $this->assertContains('gif', $exts);
    }

    public function testGetAllowedExtensionsContainsDocumentExts(): void
    {
        $exts = $this->attachmentService->getAllowedExtensions();
        $this->assertContains('pdf', $exts);
        $this->assertContains('txt', $exts);
        $this->assertContains('doc', $exts);
        $this->assertContains('docx', $exts);
    }

    // ── Container integration ───────────────────────────────────

    public function testServiceRegisteredInContainer(): void
    {
        $app = \App\Core\App::getInstance();
        $this->assertTrue($app->has(AttachmentService::class));
    }

    public function testContainerReturnsSameInstance(): void
    {
        $app = \App\Core\App::getInstance();
        $s1 = $app->get(AttachmentService::class);
        $s2 = $app->get(AttachmentService::class);
        $this->assertSame($s1, $s2);
    }
}
