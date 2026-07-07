<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Workflow\ConditionEvaluator;

final class ConditionEvaluatorTest extends TestCase
{
    private ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new ConditionEvaluator();
    }

    public function testEmptyConditionReturnsTrue(): void
    {
        $this->assertTrue($this->evaluator->evaluate('', []));
        $this->assertTrue($this->evaluator->evaluate(null, []));
    }

    public function testInvalidJsonReturnsTrue(): void
    {
        $this->assertTrue($this->evaluator->evaluate('not json', []));
        $this->assertTrue($this->evaluator->evaluate('{}', []));
    }

    public function testEqOperatorMatch(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'eq', 'value' => 'active']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['status' => 'active']));
    }

    public function testEqOperatorNoMatch(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'eq', 'value' => 'active']);
        $this->assertFalse($this->evaluator->evaluate($condition, ['status' => 'inactive']));
    }

    public function testNeqOperatorMatch(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'neq', 'value' => 'active']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['status' => 'inactive']));
    }

    public function testNeqOperatorNoMatch(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'neq', 'value' => 'active']);
        $this->assertFalse($this->evaluator->evaluate($condition, ['status' => 'active']));
    }

    public function testInOperatorArray(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'in', 'value' => ['active', 'pending']]);
        $this->assertTrue($this->evaluator->evaluate($condition, ['status' => 'active']));
        $this->assertTrue($this->evaluator->evaluate($condition, ['status' => 'pending']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['status' => 'closed']));
    }

    public function testInOperatorCommaString(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'in', 'value' => 'active, pending']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['status' => 'active']));
        $this->assertTrue($this->evaluator->evaluate($condition, ['status' => 'pending']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['status' => 'closed']));
    }

    public function testNotEmptyOperator(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'not_empty']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['name' => 'John']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['name' => '']));
        $this->assertFalse($this->evaluator->evaluate($condition, []));
    }

    public function testEmptyOperator(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'empty']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['name' => '']));
        $this->assertTrue($this->evaluator->evaluate($condition, []));
        $this->assertFalse($this->evaluator->evaluate($condition, ['name' => 'John']));
    }

    public function testDefaultOperatorIsEq(): void
    {
        $condition = json_encode(['field' => 'status', 'value' => 'active']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['status' => 'active']));
    }

    public function testUnknownOperatorReturnsTrue(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'unknown', 'value' => 'active']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['status' => 'active']));
    }

    public function testArrayValueFlattened(): void
    {
        $condition = json_encode(['field' => 'tags', 'op' => 'eq', 'value' => 'a, b']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['tags' => ['a', 'b']]));
    }

    public function testMissingFieldDefaultsToEmpty(): void
    {
        $condition = json_encode(['field' => 'missing', 'op' => 'eq', 'value' => '']);
        $this->assertTrue($this->evaluator->evaluate($condition, []));
    }

    public function testEqWithIntegerValue(): void
    {
        $condition = json_encode(['field' => 'count', 'op' => 'eq', 'value' => 5]);
        $this->assertTrue($this->evaluator->evaluate($condition, ['count' => '5']));
    }
}
