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
        $db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $this->attachmentService = new AttachmentService($db);
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
}
