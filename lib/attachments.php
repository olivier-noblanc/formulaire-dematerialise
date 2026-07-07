<?php
declare(strict_types=1);

/**
 * File attachments management — wrappers delegating to AttachmentService.
 *
 * @package lib
 */

function get_allowed_mime_types(): array {
    return \App\Core\App::attachment()->getAllowedMimeTypes();
}

function get_allowed_extensions(): array {
    return \App\Core\App::attachment()->getAllowedExtensions();
}

function get_max_file_size(): int {
    return \App\Core\App::attachment()->getMaxFileSize();
}

function handle_file_upload(array $file, string $submission_id, string $field_name): array {
    return \App\Core\App::attachment()->handleFileUpload($file, $submission_id, $field_name);
}

function get_attachments(string $submission_id): array {
    return \App\Core\App::attachment()->getAttachments($submission_id);
}

function get_attachment_by_id(string $attachment_id): ?array {
    return \App\Core\App::attachment()->getAttachmentById($attachment_id);
}
