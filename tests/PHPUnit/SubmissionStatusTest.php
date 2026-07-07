<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\SubmissionStatus;

final class SubmissionStatusTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertSame('en_cours', SubmissionStatus::EN_COURS->value);
        $this->assertSame('valide', SubmissionStatus::VALIDE->value);
        $this->assertSame('refuse', SubmissionStatus::REFUSE->value);
        $this->assertSame('annule', SubmissionStatus::ANNULE->value);
    }

    public function testLabels(): void
    {
        $this->assertSame('En cours', SubmissionStatus::EN_COURS->label());
        $this->assertSame('Validé(e)', SubmissionStatus::VALIDE->label());
        $this->assertSame('Refusé(e)', SubmissionStatus::REFUSE->label());
        $this->assertSame('Annulé(e)', SubmissionStatus::ANNULE->label());
    }

    public function testIcons(): void
    {
        $this->assertSame('⏳', SubmissionStatus::EN_COURS->icon());
        $this->assertSame('✓', SubmissionStatus::VALIDE->icon());
        $this->assertSame('❌', SubmissionStatus::REFUSE->icon());
        $this->assertSame('🗑', SubmissionStatus::ANNULE->icon());
    }

    public function testColors(): void
    {
        $this->assertSame('#f59e0b', SubmissionStatus::EN_COURS->color());
        $this->assertSame('#16a34a', SubmissionStatus::VALIDE->color());
        $this->assertSame('#dc2626', SubmissionStatus::REFUSE->color());
        $this->assertSame('#6b7280', SubmissionStatus::ANNULE->color());
    }

    public function testCssClasses(): void
    {
        $this->assertSame('status-en-cours', SubmissionStatus::EN_COURS->cssClass());
        $this->assertSame('status-valide', SubmissionStatus::VALIDE->cssClass());
        $this->assertSame('status-refuse', SubmissionStatus::REFUSE->cssClass());
        $this->assertSame('status-annule', SubmissionStatus::ANNULE->cssClass());
    }

    public function testFromValueValid(): void
    {
        $this->assertSame(SubmissionStatus::EN_COURS, SubmissionStatus::fromValue('en_cours'));
        $this->assertSame(SubmissionStatus::VALIDE, SubmissionStatus::fromValue('valide'));
        $this->assertSame(SubmissionStatus::REFUSE, SubmissionStatus::fromValue('refuse'));
        $this->assertSame(SubmissionStatus::ANNULE, SubmissionStatus::fromValue('annule'));
    }

    public function testFromValueInvalid(): void
    {
        $this->assertNull(SubmissionStatus::fromValue('unknown'));
        $this->assertNull(SubmissionStatus::fromValue(''));
    }

    public function testTryFrom(): void
    {
        $this->assertNotNull(SubmissionStatus::tryFrom('en_cours'));
        $this->assertNull(SubmissionStatus::tryFrom('invalid'));
    }
}
