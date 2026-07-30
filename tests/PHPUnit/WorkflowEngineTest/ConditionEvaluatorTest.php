<?php
declare(strict_types=1);

namespace App\Tests\WorkflowEngineTest;

use App\Workflow\ConditionEvaluator;

/**
 * Tests for the ConditionEvaluator component.
 */
final class ConditionEvaluatorTest extends Base
{
    private ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ConditionEvaluator();
    }

    public function testConditionEvaluatorHandlesEmptyCondition(): void
    {
        self::assertTrue($this->evaluator->evaluate('', []));
        self::assertTrue($this->evaluator->evaluate(null, []));
    }

    public function testConditionEvaluatorHandlesInvalidJson(): void
    {
        self::assertTrue($this->evaluator->evaluate('not-json', []));
    }

    public function testConditionEvaluatorHandlesEqOperator(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'eq', 'value' => 'active']);
        self::assertTrue($this->evaluator->evaluate($condition, ['status' => 'active']));
        self::assertFalse($this->evaluator->evaluate($condition, ['status' => 'inactive']));
    }

    public function testConditionEvaluatorHandlesNeqOperator(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'neq', 'value' => 'active']);
        self::assertFalse($this->evaluator->evaluate($condition, ['status' => 'active']));
        self::assertTrue($this->evaluator->evaluate($condition, ['status' => 'inactive']));
    }

    public function testConditionEvaluatorHandlesInOperator(): void
    {
        $condition = json_encode(['field' => 'role', 'op' => 'in', 'value' => ['admin', 'editor']]);
        self::assertTrue($this->evaluator->evaluate($condition, ['role' => 'admin']));
        self::assertFalse($this->evaluator->evaluate($condition, ['role' => 'viewer']));
    }

    public function testConditionEvaluatorHandlesInOperatorWithString(): void
    {
        $condition = json_encode(['field' => 'role', 'op' => 'in', 'value' => 'admin,editor']);
        self::assertTrue($this->evaluator->evaluate($condition, ['role' => 'admin']));
        self::assertFalse($this->evaluator->evaluate($condition, ['role' => 'viewer']));
    }

    public function testConditionEvaluatorHandlesNotEmptyOperator(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'not_empty']);
        self::assertTrue($this->evaluator->evaluate($condition, ['name' => 'John']));
        self::assertFalse($this->evaluator->evaluate($condition, ['name' => '']));
        self::assertFalse($this->evaluator->evaluate($condition, []));
    }

    public function testConditionEvaluatorHandlesEmptyOperator(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'empty']);
        self::assertTrue($this->evaluator->evaluate($condition, ['name' => '']));
        self::assertTrue($this->evaluator->evaluate($condition, []));
        self::assertFalse($this->evaluator->evaluate($condition, ['name' => 'John']));
    }

    public function testConditionEvaluatorHandlesUnknownOperator(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'unknown']);
        self::assertFalse($this->evaluator->evaluate($condition, ['name' => 'John']));
    }

    public function testConditionEvaluatorHandlesArrayValueInData(): void
    {
        $condition = json_encode(['field' => 'tags', 'op' => 'not_empty']);
        self::assertTrue($this->evaluator->evaluate($condition, ['tags' => ['admin', 'user']]));
    }

    public function testConditionEvaluatorHandlesMissingFieldInData(): void
    {
        $condition = json_encode(['field' => 'missing', 'op' => 'eq', 'value' => '']);
        self::assertTrue($this->evaluator->evaluate($condition, []));
    }

    public function testConditionEvaluatorDefaultsToEqOperator(): void
    {
        $condition = json_encode(['field' => 'name', 'value' => 'test']);
        self::assertTrue($this->evaluator->evaluate($condition, ['name' => 'test']));
        self::assertFalse($this->evaluator->evaluate($condition, ['name' => 'other']));
    }

    public function testConditionEvaluatorHandlesContainsOperator(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'contains', 'value' => 'John']);
        self::assertTrue($this->evaluator->evaluate($condition, ['name' => 'John Doe']));
        self::assertFalse($this->evaluator->evaluate($condition, ['name' => 'Jane Doe']));
    }

    public function testConditionEvaluatorHandlesEqualsAlias(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'equals', 'value' => 'active']);
        self::assertTrue($this->evaluator->evaluate($condition, ['status' => 'active']));
        self::assertFalse($this->evaluator->evaluate($condition, ['status' => 'inactive']));
    }

    public function testConditionEvaluatorHandlesNotEqualsAlias(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'not_equals', 'value' => 'active']);
        self::assertFalse($this->evaluator->evaluate($condition, ['status' => 'active']));
        self::assertTrue($this->evaluator->evaluate($condition, ['status' => 'inactive']));
    }

    public function testConditionEvaluatorHandlesZeroCondition(): void
    {
        self::assertTrue($this->evaluator->evaluate('0', ['any' => 'data']));
    }

    public function testConditionEvaluatorHandlesNullCondition(): void
    {
        self::assertTrue($this->evaluator->evaluate(null, ['any' => 'data']));
    }

    public function testConditionEvaluatorHandlesMalformedJson(): void
    {
        self::assertTrue($this->evaluator->evaluate('{invalid json', []));
    }

    public function testConditionEvaluatorHandlesEmptyFieldName(): void
    {
        $condition = json_encode(['field' => '', 'op' => 'eq', 'value' => 'test']);
        self::assertTrue($this->evaluator->evaluate($condition, []));
    }

    public function testConditionEvaluatorHandlesMissingFieldKey(): void
    {
        $condition = json_encode(['op' => 'eq', 'value' => 'test']);
        self::assertTrue($this->evaluator->evaluate($condition, []));
    }

    public function testConditionEvaluatorContainsWithEmptyValue(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'contains', 'value' => '']);
        self::assertTrue($this->evaluator->evaluate($condition, ['name' => 'John']));
    }

    public function testConditionEvaluatorInOperatorWithArrayValue(): void
    {
        $condition = json_encode(['field' => 'role', 'op' => 'in', 'value' => ['admin', 'editor', 'viewer']]);
        self::assertTrue($this->evaluator->evaluate($condition, ['role' => 'admin']));
        self::assertTrue($this->evaluator->evaluate($condition, ['role' => 'viewer']));
        self::assertFalse($this->evaluator->evaluate($condition, ['role' => 'guest']));
    }

    public function testConditionEvaluatorInOperatorWithCommaString(): void
    {
        $condition = json_encode(['field' => 'type', 'op' => 'in', 'value' => 'A, B, C']);
        self::assertTrue($this->evaluator->evaluate($condition, ['type' => 'A']));
        self::assertTrue($this->evaluator->evaluate($condition, ['type' => 'B']));
        self::assertFalse($this->evaluator->evaluate($condition, ['type' => 'D']));
    }

    public function testConditionEvaluatorEmptyOperatorDefaultsToTrue(): void
    {
        $condition = json_encode(['field' => 'name', 'value' => 'test']);
        self::assertTrue($this->evaluator->evaluate($condition, ['name' => 'test']));
        self::assertFalse($this->evaluator->evaluate($condition, ['name' => 'other']));
    }

    public function testConditionEvaluatorArrayDataConvertedToString(): void
    {
        $condition = json_encode(['field' => 'tags', 'op' => 'eq', 'value' => 'admin, user']);
        self::assertTrue($this->evaluator->evaluate($condition, ['tags' => ['admin', 'user']]));
    }

    public function testConditionEvaluatorNumericValueComparison(): void
    {
        $condition = json_encode(['field' => 'count', 'op' => 'eq', 'value' => '5']);
        self::assertTrue($this->evaluator->evaluate($condition, ['count' => 5]));
        self::assertFalse($this->evaluator->evaluate($condition, ['count' => 3]));
    }

    public function testConditionEvaluatorNotEmptyWithZeroValue(): void
    {
        $condition = json_encode(['field' => 'count', 'op' => 'not_empty']);
        self::assertTrue($this->evaluator->evaluate($condition, ['count' => 0]));
        self::assertFalse($this->evaluator->evaluate($condition, ['count' => '']));
    }

    public function testConditionEvaluatorEmptyWithNullValue(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'empty']);
        self::assertTrue($this->evaluator->evaluate($condition, ['name' => null]));
    }

    public function testConditionEvaluatorHandlesDeeplyNestedJson(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'eq', 'value' => 'test']);
        self::assertTrue($this->evaluator->evaluate($condition, ['name' => 'test']));
    }

    public function testConditionEvaluatorBooleanValueInData(): void
    {
        $condition = json_encode(['field' => 'active', 'op' => 'eq', 'value' => '1']);
        self::assertTrue($this->evaluator->evaluate($condition, ['active' => true]));
    }

    public function testConditionEvaluatorNumericValueInData(): void
    {
        $condition = json_encode(['field' => 'count', 'op' => 'eq', 'value' => '5']);
        self::assertTrue($this->evaluator->evaluate($condition, ['count' => 5]));
    }

    public function testConditionEvaluatorEmptyArrayInData(): void
    {
        $condition = json_encode(['field' => 'items', 'op' => 'not_empty']);
        self::assertFalse($this->evaluator->evaluate($condition, ['items' => []]));
    }

    public function testConditionEvaluatorNotEmptyWithNonEmptyArray(): void
    {
        $condition = json_encode(['field' => 'items', 'op' => 'not_empty']);
        self::assertTrue($this->evaluator->evaluate($condition, ['items' => ['a', 'b']]));
    }

    public function testConditionEvaluatorEmptyWithNonEmptyArray(): void
    {
        $condition = json_encode(['field' => 'items', 'op' => 'empty']);
        self::assertFalse($this->evaluator->evaluate($condition, ['items' => ['a']]));
    }

    public function testConditionEvaluatorContainsWithNumericValue(): void
    {
        $condition = json_encode(['field' => 'code', 'op' => 'contains', 'value' => '123']);
        self::assertTrue($this->evaluator->evaluate($condition, ['code' => 'abc123def']));
        self::assertFalse($this->evaluator->evaluate($condition, ['code' => 'abcdef']));
    }

    public function testConditionEvaluatorInOperatorWithSingleValue(): void
    {
        $condition = json_encode(['field' => 'role', 'op' => 'in', 'value' => ['admin']]);
        self::assertTrue($this->evaluator->evaluate($condition, ['role' => 'admin']));
        self::assertFalse($this->evaluator->evaluate($condition, ['role' => 'user']));
    }

    public function testConditionEvaluatorNeqWithEmptyString(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'neq', 'value' => '']);
        self::assertTrue($this->evaluator->evaluate($condition, ['name' => 'John']));
        self::assertFalse($this->evaluator->evaluate($condition, ['name' => '']));
    }

    public function testConditionEvaluatorEqWithEmptyString(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'eq', 'value' => '']);
        self::assertTrue($this->evaluator->evaluate($condition, ['name' => '']));
        self::assertFalse($this->evaluator->evaluate($condition, ['name' => 'John']));
    }

    public function testConditionEvaluatorContainsWithEmptyActual(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'contains', 'value' => 'John']);
        self::assertFalse($this->evaluator->evaluate($condition, ['name' => '']));
    }

    public function testConditionEvaluatorInWithEmptyArray(): void
    {
        $condition = json_encode(['field' => 'role', 'op' => 'in', 'value' => []]);
        self::assertFalse($this->evaluator->evaluate($condition, ['role' => 'admin']));
    }

    public function testConditionEvaluatorMultipleConditionsLastWins(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'eq', 'value' => 'John']);
        self::assertTrue($this->evaluator->evaluate($condition, ['name' => 'John']));
    }
}
