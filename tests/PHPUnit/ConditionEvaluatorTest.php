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
        self::assertTrue($this->evaluator->evaluate('', []));
        self::assertTrue($this->evaluator->evaluate(null, []));
    }

    public function testInvalidJsonReturnsTrue(): void
    {
        self::assertTrue($this->evaluator->evaluate('not json', []));
        self::assertTrue($this->evaluator->evaluate('{}', []));
    }

    public function testEqOperatorMatch(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'eq', 'value' => 'active']);
        assert($condition !== false);
        self::assertTrue($this->evaluator->evaluate($condition, ['status' => 'active']));
    }

    public function testEqOperatorNoMatch(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'eq', 'value' => 'active']);
        assert($condition !== false);
        self::assertFalse($this->evaluator->evaluate($condition, ['status' => 'inactive']));
    }

    public function testNeqOperatorMatch(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'neq', 'value' => 'active']);
        assert($condition !== false);
        self::assertTrue($this->evaluator->evaluate($condition, ['status' => 'inactive']));
    }

    public function testNeqOperatorNoMatch(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'neq', 'value' => 'active']);
        assert($condition !== false);
        self::assertFalse($this->evaluator->evaluate($condition, ['status' => 'active']));
    }

    public function testInOperatorArray(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'in', 'value' => ['active', 'pending']]);
        assert($condition !== false);
        self::assertTrue($this->evaluator->evaluate($condition, ['status' => 'active']));
        self::assertTrue($this->evaluator->evaluate($condition, ['status' => 'pending']));
        self::assertFalse($this->evaluator->evaluate($condition, ['status' => 'closed']));
    }

    public function testInOperatorCommaString(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'in', 'value' => 'active, pending']);
        assert($condition !== false);
        self::assertTrue($this->evaluator->evaluate($condition, ['status' => 'active']));
        self::assertTrue($this->evaluator->evaluate($condition, ['status' => 'pending']));
        self::assertFalse($this->evaluator->evaluate($condition, ['status' => 'closed']));
    }

    public function testNotEmptyOperator(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'not_empty']);
        assert($condition !== false);
        self::assertTrue($this->evaluator->evaluate($condition, ['name' => 'John']));
        self::assertFalse($this->evaluator->evaluate($condition, ['name' => '']));
        self::assertFalse($this->evaluator->evaluate($condition, []));
    }

    public function testEmptyOperator(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'empty']);
        assert($condition !== false);
        self::assertTrue($this->evaluator->evaluate($condition, ['name' => '']));
        self::assertTrue($this->evaluator->evaluate($condition, []));
        self::assertFalse($this->evaluator->evaluate($condition, ['name' => 'John']));
    }

    public function testDefaultOperatorIsEq(): void
    {
        $condition = json_encode(['field' => 'status', 'value' => 'active']);
        assert($condition !== false);
        self::assertTrue($this->evaluator->evaluate($condition, ['status' => 'active']));
    }

    public function testUnknownOperatorReturnsFalse(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'unknown', 'value' => 'active']);
        assert($condition !== false);
        self::assertFalse($this->evaluator->evaluate($condition, ['status' => 'active']));
    }

    public function testArrayValueFlattened(): void
    {
        $condition = json_encode(['field' => 'tags', 'op' => 'eq', 'value' => 'a, b']);
        assert($condition !== false);
        self::assertTrue($this->evaluator->evaluate($condition, ['tags' => ['a', 'b']]));
    }

    public function testMissingFieldDefaultsToEmpty(): void
    {
        $condition = json_encode(['field' => 'missing', 'op' => 'eq', 'value' => '']);
        assert($condition !== false);
        self::assertTrue($this->evaluator->evaluate($condition, []));
    }

    public function testEqWithIntegerValue(): void
    {
        $condition = json_encode(['field' => 'count', 'op' => 'eq', 'value' => 5]);
        assert($condition !== false);
        self::assertTrue($this->evaluator->evaluate($condition, ['count' => '5']));
    }
}
