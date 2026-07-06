<?php
declare(strict_types=1);

namespace App\Contract;

interface HtmlInterface
{
    public function escape(?string $value): string;
    public function h(?string $value): string;
    public function getFileIcon(string $mimeType): string;
    public function formatFileSize(int $bytes): string;
    public function tJargon(string $text): string;
}
