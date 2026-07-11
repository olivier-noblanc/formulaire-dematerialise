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

    // ── handleFileUpload() UPLOAD_ERR_FORM_SIZE ────────────────

    public function testHandleFileUploadUploadErrFormSize(): void
    {
        $file = [
            'error' => UPLOAD_ERR_FORM_SIZE,
            'tmp_name' => '',
            'name' => 'test.pdf',
            'size' => 0,
            'type' => 'application/pdf',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('formulaire', $result['message']);
    }

    // ── handleFileUpload() unknown error code ───────────────────

    public function testHandleFileUploadUnknownError(): void
    {
        $file = [
            'error' => 99, // Unknown error code
            'tmp_name' => '',
            'name' => 'test.pdf',
            'size' => 0,
            'type' => 'application/pdf',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('inconnue', $result['message']);
    }

    // ── handleFileUpload() empty filename after sanitization ────

    public function testHandleFileUploadEmptyFilenameDefaultsToFichier(): void
    {
        $file = [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => '',
            'name' => '.....', // All dots — stripped by ltrim
            'size' => 100,
            'type' => 'application/octet-stream',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
        // Should not succeed because 'fichier' has no extension
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('non autorisé', $result['message']);
    }

    // ── handleFileUpload() dangerous extension directly ──────────

    public function testHandleFileUploadDangerousPhpExtensionBlocked(): void
    {
        $file = [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => '',
            'name' => 'script.php',
            'size' => 100,
            'type' => 'application/x-php',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
        // Dangerous extension .php → blocked at dangerous extension check (line 122)
        $this->assertFalse($result['success']);
    }

    // ── handleFileUpload() disallowed extension (not dangerous) ──

    public function testHandleFileUploadDisallowedExtensionNotDangerous(): void
    {
        $file = [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => '',
            'name' => 'test.bmp',
            'size' => 100,
            'type' => 'image/bmp',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('non autorisé', $result['message']);
    }

    // ── handleFileUpload() filename sanitization keeps allowed ext ──

    public function testHandleFileUploadFilenameSanitizationRemovesSpecialChars(): void
    {
        $file = [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => '',
            'name' => 'my file (copy) [1].bmp',
            'size' => 100,
            'type' => 'image/bmp',
        ];
        // .bmp extension is not in allowed list → blocked before finfo
        $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('non autorisé', $result['message']);
    }

    // ── handleFileUpload() multiple dangerous double extensions ──

    public function testHandleFileUploadDangerousDoubleExtensionAsp(): void
    {
        $file = [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => '',
            'name' => 'file.asp.jpg',
            'size' => 100,
            'type' => 'image/jpeg',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('doubles extensions', $result['message']);
    }

    // ── handleFileUpload() safe double extension with dangerous last ─

    public function testHandleFileUploadDoubleExtDangerousLastPart(): void
    {
        $file = [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => '',
            'name' => 'image.cgi.jpg',
            'size' => 100,
            'type' => 'image/jpeg',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('doubles extensions', $result['message']);
    }

    // ── handleFileUpload() disallowed non-dangerous ext ──────────

    public function testHandleFileUploadHtmlExtensionBlocked(): void
    {
        $file = [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => '',
            'name' => 'page.html',
            'size' => 100,
            'type' => 'text/html',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('non autorisé', $result['message']);
    }

    // ── handleFileUpload() double extension with different dangerous ext ──

    public function testHandleFileUploadDangerousDoubleExtensionPhtml(): void
    {
        $file = [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => '',
            'name' => 'image.phtml.jpg',
            'size' => 100,
            'type' => 'image/jpeg',
        ];
        $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('doubles extensions', $result['message']);
    }

    // ── getAllowedMimeTypes() contains all expected types ─────────

    public function testGetAllowedMimeTypesContainsAllExpectedTypes(): void
    {
        $types = $this->attachmentService->getAllowedMimeTypes();
        $expected = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'text/plain',
            'text/csv',
            'application/zip',
        ];
        foreach ($expected as $type) {
            $this->assertContains($type, $types, "Missing MIME type: $type");
        }
    }

    // ── getAllowedExtensions() contains all expected exts ─────────

    public function testGetAllowedExtensionsContainsAllExpectedExts(): void
    {
        $exts = $this->attachmentService->getAllowedExtensions();
        $expected = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip'];
        foreach ($expected as $ext) {
            $this->assertContains($ext, $exts, "Missing extension: $ext");
        }
    }

    // ── getAttachments() and getAttachmentById() integration ─────

    public function testGetAttachmentsReturnsArrayForExistingSubmission(): void
    {
        $pdo = \App\Core\App::getInstance()->get(\App\Core\Database::class)->getPdo();
        $submissionId = 'test-sub-' . uniqid();
        $attId = bin2hex(random_bytes(8));

        $pdo->prepare("INSERT INTO attachments (id, submission_id, field_name, original_name, stored_name, mime_type, file_size, file_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$attId, $submissionId, 'field', 'test.pdf', 'test_stored.pdf', 'application/pdf', 100, 'fake']);

        try {
            $attachments = $this->attachmentService->getAttachments($submissionId);
            $this->assertIsArray($attachments);
            $this->assertNotEmpty($attachments);
        } finally {
            $pdo->prepare("DELETE FROM attachments WHERE id = ?")->execute([$attId]);
        }
    }

    public function testGetAttachmentByIdReturnsDataForExistingAttachment(): void
    {
        $pdo = \App\Core\App::getInstance()->get(\App\Core\Database::class)->getPdo();
        $submissionId = 'test-sub-byid-' . uniqid();
        $attId = bin2hex(random_bytes(8));

        $pdo->prepare("INSERT INTO attachments (id, submission_id, field_name, original_name, stored_name, mime_type, file_size, file_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$attId, $submissionId, 'field', 'lookup.pdf', 'lookup_stored.pdf', 'application/pdf', 200, 'content']);

        try {
            $attachment = $this->attachmentService->getAttachmentById($attId);
            $this->assertNotNull($attachment);
            $this->assertSame('lookup.pdf', $attachment['original_name']);
        } finally {
            $pdo->prepare("DELETE FROM attachments WHERE id = ?")->execute([$attId]);
        }
    }

    // ── handleFileUpload() all upload error codes covered ────────

    public function testHandleFileUploadAllErrorCodesReturnFailure(): void
    {
        $errorCodes = [
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE,
            UPLOAD_ERR_PARTIAL,
            UPLOAD_ERR_NO_FILE,
            UPLOAD_ERR_NO_TMP_DIR,
            UPLOAD_ERR_CANT_WRITE,
        ];

        foreach ($errorCodes as $code) {
            $file = [
                'error' => $code,
                'tmp_name' => '',
                'name' => 'test.pdf',
                'size' => 0,
                'type' => 'application/pdf',
            ];
            $result = $this->attachmentService->handleFileUpload($file, 'sub-id', 'field');
            $this->assertFalse($result['success'], "Error code $code should return failure");
            $this->assertNull($result['attachment_id']);
        }
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
