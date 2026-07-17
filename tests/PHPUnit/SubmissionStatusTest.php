<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Enum\SubmissionStatus;

final class SubmissionStatusTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertSame('en_cours', SubmissionStatus::EnCours->value);
        $this->assertSame('valide', SubmissionStatus::Valide->value);
        $this->assertSame('refuse', SubmissionStatus::Refuse->value);
        $this->assertSame('annule', SubmissionStatus::Annule->value);
    }

    public function testLabels(): void
    {
        $this->assertSame('En cours', SubmissionStatus::EnCours->label());
        $this->assertSame('Validé', SubmissionStatus::Valide->label());
        $this->assertSame('Refusé', SubmissionStatus::Refuse->label());
        $this->assertSame('Annulé', SubmissionStatus::Annule->label());
    }

    public function testIcons(): void
    {
        $this->assertSame('⏳', SubmissionStatus::EnCours->icon());
        $this->assertSame('✓', SubmissionStatus::Valide->icon());
        $this->assertSame('❌', SubmissionStatus::Refuse->icon());
        $this->assertSame('🗑', SubmissionStatus::Annule->icon());
    }

    public function testColors(): void
    {
        $this->assertSame('#f59e0b', SubmissionStatus::EnCours->color());
        $this->assertSame('#16a34a', SubmissionStatus::Valide->color());
        $this->assertSame('#dc2626', SubmissionStatus::Refuse->color());
        $this->assertSame('#6b7280', SubmissionStatus::Annule->color());
    }

    public function testCssClasses(): void
    {
        $this->assertSame('status-en-cours', SubmissionStatus::EnCours->cssClass());
        $this->assertSame('status-valide', SubmissionStatus::Valide->cssClass());
        $this->assertSame('status-refuse', SubmissionStatus::Refuse->cssClass());
        $this->assertSame('status-annule', SubmissionStatus::Annule->cssClass());
    }

    public function testBadgeClasses(): void
    {
        $this->assertSame('badge-warn', SubmissionStatus::EnCours->badgeClass());
        $this->assertSame('badge-ok', SubmissionStatus::Valide->badgeClass());
        $this->assertSame('badge-err', SubmissionStatus::Refuse->badgeClass());
        $this->assertSame('badge-annule', SubmissionStatus::Annule->badgeClass());
    }

    public function testFromValueValid(): void
    {
        $this->assertSame(SubmissionStatus::EnCours, SubmissionStatus::fromValue('en_cours'));
        $this->assertSame(SubmissionStatus::Valide, SubmissionStatus::fromValue('valide'));
        $this->assertSame(SubmissionStatus::Refuse, SubmissionStatus::fromValue('refuse'));
        $this->assertSame(SubmissionStatus::Annule, SubmissionStatus::fromValue('annule'));
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
