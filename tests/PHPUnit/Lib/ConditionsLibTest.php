<?php
declare(strict_types=1);

namespace App\Tests\Lib;

use PHPUnit\Framework\TestCase;

final class ConditionsLibTest extends TestCase
{
    public function testEvaluateConditionDelegatesToEvaluator(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'eq', 'value' => 'active']);
        self::assertTrue(evaluate_condition($condition, ['status' => 'active']));
    }

    public function testEvaluateConditionEmptyReturnsTrue(): void
    {
        self::assertTrue(evaluate_condition('', []));
        self::assertTrue(evaluate_condition(null, []));
    }

    public function testEvaluateConditionFalse(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'eq', 'value' => 'active']);
        self::assertFalse(evaluate_condition($condition, ['status' => 'inactive']));
    }

    public function testEvaluateFieldConditionDelegates(): void
    {
        $field = ['condition' => json_encode(['field' => 'type', 'op' => 'eq', 'value' => 'A'])];
        self::assertTrue(evaluate_field_condition($field, ['type' => 'A']));
        self::assertFalse(evaluate_field_condition($field, ['type' => 'B']));
    }

    public function testEvaluateFieldConditionEmptyCondition(): void
    {
        $field = ['condition' => ''];
        self::assertTrue(evaluate_field_condition($field, []));
    }

    public function testEvaluateFieldConditionNoConditionKey(): void
    {
        $field = [];
        self::assertTrue(evaluate_field_condition($field, []));
    }
}
