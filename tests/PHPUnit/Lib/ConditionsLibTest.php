<?php
declare(strict_types=1);

namespace App\Tests\Lib;

use PHPUnit\Framework\TestCase;

final class ConditionsLibTest extends TestCase
{
    public function testEvaluateConditionDelegatesToEvaluator(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'eq', 'value' => 'active']);
        $this->assertTrue(evaluate_condition($condition, ['status' => 'active']));
    }

    public function testEvaluateConditionEmptyReturnsTrue(): void
    {
        $this->assertTrue(evaluate_condition('', []));
        $this->assertTrue(evaluate_condition(null, []));
    }

    public function testEvaluateConditionFalse(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'eq', 'value' => 'active']);
        $this->assertFalse(evaluate_condition($condition, ['status' => 'inactive']));
    }

    public function testEvaluateFieldConditionDelegates(): void
    {
        $field = ['condition' => json_encode(['field' => 'type', 'op' => 'eq', 'value' => 'A'])];
        $this->assertTrue(evaluate_field_condition($field, ['type' => 'A']));
        $this->assertFalse(evaluate_field_condition($field, ['type' => 'B']));
    }

    public function testEvaluateFieldConditionEmptyCondition(): void
    {
        $field = ['condition' => ''];
        $this->assertTrue(evaluate_field_condition($field, []));
    }

    public function testEvaluateFieldConditionNoConditionKey(): void
    {
        $field = [];
        $this->assertTrue(evaluate_field_condition($field, []));
    }
}
