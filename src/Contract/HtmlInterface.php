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
    public function displayUser(string $email, ?string $current_user = null, bool $force_email = false): string;
    public function displayUserShort(string $email): string;
    public function renderPagination(int $page, int $total_pages, string $base_url): string;
    public function buildUrl(string $url): string;
    public function renderDonutChart(int $total, int $valide, int $en_cours, int $refuse): string;
}
