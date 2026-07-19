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
        $this->assertTrue($this->evaluator->evaluate('', []));
        $this->assertTrue($this->evaluator->evaluate(null, []));
    }

    public function testConditionEvaluatorHandlesInvalidJson(): void
    {
        $this->assertTrue($this->evaluator->evaluate('not-json', []));
    }

    public function testConditionEvaluatorHandlesEqOperator(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'eq', 'value' => 'active']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['status' => 'active']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['status' => 'inactive']));
    }

    public function testConditionEvaluatorHandlesNeqOperator(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'neq', 'value' => 'active']);
        $this->assertFalse($this->evaluator->evaluate($condition, ['status' => 'active']));
        $this->assertTrue($this->evaluator->evaluate($condition, ['status' => 'inactive']));
    }

    public function testConditionEvaluatorHandlesInOperator(): void
    {
        $condition = json_encode(['field' => 'role', 'op' => 'in', 'value' => ['admin', 'editor']]);
        $this->assertTrue($this->evaluator->evaluate($condition, ['role' => 'admin']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['role' => 'viewer']));
    }

    public function testConditionEvaluatorHandlesInOperatorWithString(): void
    {
        $condition = json_encode(['field' => 'role', 'op' => 'in', 'value' => 'admin,editor']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['role' => 'admin']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['role' => 'viewer']));
    }

    public function testConditionEvaluatorHandlesNotEmptyOperator(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'not_empty']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['name' => 'John']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['name' => '']));
        $this->assertFalse($this->evaluator->evaluate($condition, []));
    }

    public function testConditionEvaluatorHandlesEmptyOperator(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'empty']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['name' => '']));
        $this->assertTrue($this->evaluator->evaluate($condition, []));
        $this->assertFalse($this->evaluator->evaluate($condition, ['name' => 'John']));
    }

    public function testConditionEvaluatorHandlesUnknownOperator(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'unknown']);
        $this->assertFalse($this->evaluator->evaluate($condition, ['name' => 'John']));
    }

    public function testConditionEvaluatorHandlesArrayValueInData(): void
    {
        $condition = json_encode(['field' => 'tags', 'op' => 'not_empty']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['tags' => ['admin', 'user']]));
    }

    public function testConditionEvaluatorHandlesMissingFieldInData(): void
    {
        $condition = json_encode(['field' => 'missing', 'op' => 'eq', 'value' => '']);
        $this->assertTrue($this->evaluator->evaluate($condition, []));
    }

    public function testConditionEvaluatorDefaultsToEqOperator(): void
    {
        $condition = json_encode(['field' => 'name', 'value' => 'test']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['name' => 'test']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['name' => 'other']));
    }

    public function testConditionEvaluatorHandlesContainsOperator(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'contains', 'value' => 'John']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['name' => 'John Doe']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['name' => 'Jane Doe']));
    }

    public function testConditionEvaluatorHandlesEqualsAlias(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'equals', 'value' => 'active']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['status' => 'active']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['status' => 'inactive']));
    }

    public function testConditionEvaluatorHandlesNotEqualsAlias(): void
    {
        $condition = json_encode(['field' => 'status', 'op' => 'not_equals', 'value' => 'active']);
        $this->assertFalse($this->evaluator->evaluate($condition, ['status' => 'active']));
        $this->assertTrue($this->evaluator->evaluate($condition, ['status' => 'inactive']));
    }

    public function testConditionEvaluatorHandlesZeroCondition(): void
    {
        $this->assertTrue($this->evaluator->evaluate('0', ['any' => 'data']));
    }

    public function testConditionEvaluatorHandlesNullCondition(): void
    {
        $this->assertTrue($this->evaluator->evaluate(null, ['any' => 'data']));
    }

    public function testConditionEvaluatorHandlesMalformedJson(): void
    {
        $this->assertTrue($this->evaluator->evaluate('{invalid json', []));
    }

    public function testConditionEvaluatorHandlesEmptyFieldName(): void
    {
        $condition = json_encode(['field' => '', 'op' => 'eq', 'value' => 'test']);
        $this->assertTrue($this->evaluator->evaluate($condition, []));
    }

    public function testConditionEvaluatorHandlesMissingFieldKey(): void
    {
        $condition = json_encode(['op' => 'eq', 'value' => 'test']);
        $this->assertTrue($this->evaluator->evaluate($condition, []));
    }

    public function testConditionEvaluatorContainsWithEmptyValue(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'contains', 'value' => '']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['name' => 'John']));
    }

    public function testConditionEvaluatorInOperatorWithArrayValue(): void
    {
        $condition = json_encode(['field' => 'role', 'op' => 'in', 'value' => ['admin', 'editor', 'viewer']]);
        $this->assertTrue($this->evaluator->evaluate($condition, ['role' => 'admin']));
        $this->assertTrue($this->evaluator->evaluate($condition, ['role' => 'viewer']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['role' => 'guest']));
    }

    public function testConditionEvaluatorInOperatorWithCommaString(): void
    {
        $condition = json_encode(['field' => 'type', 'op' => 'in', 'value' => 'A, B, C']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['type' => 'A']));
        $this->assertTrue($this->evaluator->evaluate($condition, ['type' => 'B']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['type' => 'D']));
    }

    public function testConditionEvaluatorEmptyOperatorDefaultsToTrue(): void
    {
        $condition = json_encode(['field' => 'name', 'value' => 'test']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['name' => 'test']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['name' => 'other']));
    }

    public function testConditionEvaluatorArrayDataConvertedToString(): void
    {
        $condition = json_encode(['field' => 'tags', 'op' => 'eq', 'value' => 'admin, user']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['tags' => ['admin', 'user']]));
    }

    public function testConditionEvaluatorNumericValueComparison(): void
    {
        $condition = json_encode(['field' => 'count', 'op' => 'eq', 'value' => '5']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['count' => 5]));
        $this->assertFalse($this->evaluator->evaluate($condition, ['count' => 3]));
    }

    public function testConditionEvaluatorNotEmptyWithZeroValue(): void
    {
        $condition = json_encode(['field' => 'count', 'op' => 'not_empty']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['count' => 0]));
        $this->assertFalse($this->evaluator->evaluate($condition, ['count' => '']));
    }

    public function testConditionEvaluatorEmptyWithNullValue(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'empty']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['name' => null]));
    }

    public function testConditionEvaluatorHandlesDeeplyNestedJson(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'eq', 'value' => 'test']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['name' => 'test']));
    }

    public function testConditionEvaluatorBooleanValueInData(): void
    {
        $condition = json_encode(['field' => 'active', 'op' => 'eq', 'value' => '1']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['active' => true]));
    }

    public function testConditionEvaluatorNumericValueInData(): void
    {
        $condition = json_encode(['field' => 'count', 'op' => 'eq', 'value' => '5']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['count' => 5]));
    }

    public function testConditionEvaluatorEmptyArrayInData(): void
    {
        $condition = json_encode(['field' => 'items', 'op' => 'not_empty']);
        $this->assertFalse($this->evaluator->evaluate($condition, ['items' => []]));
    }

    public function testConditionEvaluatorNotEmptyWithNonEmptyArray(): void
    {
        $condition = json_encode(['field' => 'items', 'op' => 'not_empty']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['items' => ['a', 'b']]));
    }

    public function testConditionEvaluatorEmptyWithNonEmptyArray(): void
    {
        $condition = json_encode(['field' => 'items', 'op' => 'empty']);
        $this->assertFalse($this->evaluator->evaluate($condition, ['items' => ['a']]));
    }

    public function testConditionEvaluatorContainsWithNumericValue(): void
    {
        $condition = json_encode(['field' => 'code', 'op' => 'contains', 'value' => '123']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['code' => 'abc123def']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['code' => 'abcdef']));
    }

    public function testConditionEvaluatorInOperatorWithSingleValue(): void
    {
        $condition = json_encode(['field' => 'role', 'op' => 'in', 'value' => ['admin']]);
        $this->assertTrue($this->evaluator->evaluate($condition, ['role' => 'admin']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['role' => 'user']));
    }

    public function testConditionEvaluatorNeqWithEmptyString(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'neq', 'value' => '']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['name' => 'John']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['name' => '']));
    }

    public function testConditionEvaluatorEqWithEmptyString(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'eq', 'value' => '']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['name' => '']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['name' => 'John']));
    }

    public function testConditionEvaluatorContainsWithEmptyActual(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'contains', 'value' => 'John']);
        $this->assertFalse($this->evaluator->evaluate($condition, ['name' => '']));
    }

    public function testConditionEvaluatorInWithEmptyArray(): void
    {
        $condition = json_encode(['field' => 'role', 'op' => 'in', 'value' => []]);
        $this->assertFalse($this->evaluator->evaluate($condition, ['role' => 'admin']));
    }

    public function testConditionEvaluatorMultipleConditionsLastWins(): void
    {
        $condition = json_encode(['field' => 'name', 'op' => 'eq', 'value' => 'John']);
        $this->assertTrue($this->evaluator->evaluate($condition, ['name' => 'John']));
    }
}
