<?php

declare(strict_types=1);

namespace Braesident\DbMigration;

use InvalidArgumentException;

/**
 * Builds WHERE conditions and parameter bindings for SQL ASTs.
 */
final class WhereBuilder
{
  private array $conditions = [];

  private array $params = [];

  private int $counter = 0;

  private string $prefix;

  /**
   * Creates a builder with a parameter prefix.
   *
   * @param string $prefix prefix for auto-generated parameter names (without colon)
   */
  public function __construct(string $prefix = 'p')
  {
    $prefix       = trim($prefix);
    $this->prefix = '' !== $prefix ? $prefix : 'p';
  }

  /**
   * Adds a raw condition to the AND list.
   *
   * @return $this
   */
  public function and(mixed $condition): self
  {
    if (null === $condition) {
      return $this;
    }

    if (\is_string($condition) && '' === trim($condition)) {
      return $this;
    }

    $this->conditions[] = $condition;

    return $this;
  }

  /**
   * Adds an AND condition with a literal value.
   *
   * @return $this
   */
  public function andValue(string $column, string $operator, mixed $value): self
  {
    $this->conditions[] = [$column, $operator, ['value' => $value]];

    return $this;
  }

  /**
   * Adds an OR condition with a literal value.
   *
   * @return $this
   */
  public function orValue(string $column, string $operator, mixed $value): self
  {
    $this->appendOrCondition([$column, $operator, ['value' => $value]]);

    return $this;
  }

  /**
   * Adds column = value as AND.
   *
   * @return $this
   */
  public function andEquals(string $column, mixed $value): self
  {
    return $this->andValue($column, '=', $value);
  }

  /**
   * Adds column <> value as AND.
   *
   * @return $this
   */
  public function andNotEquals(string $column, mixed $value): self
  {
    return $this->andValue($column, '<>', $value);
  }

  /**
   * Adds column = value as OR.
   *
   * @return $this
   */
  public function orEquals(string $column, mixed $value): self
  {
    return $this->orValue($column, '=', $value);
  }

  /**
   * Adds column <> value as OR.
   *
   * @return $this
   */
  public function orNotEquals(string $column, mixed $value): self
  {
    return $this->orValue($column, '<>', $value);
  }

  /**
   * Adds column < value as AND.
   *
   * @return $this
   */
  public function andLessThan(string $column, mixed $value): self
  {
    return $this->andValue($column, '<', $value);
  }

  /**
   * Adds column <= value as AND.
   *
   * @return $this
   */
  public function andLessThanOrEqual(string $column, mixed $value): self
  {
    return $this->andValue($column, '<=', $value);
  }

  /**
   * Adds column > value as AND.
   *
   * @return $this
   */
  public function andGreaterThan(string $column, mixed $value): self
  {
    return $this->andValue($column, '>', $value);
  }

  /**
   * Adds column >= value as AND.
   *
   * @return $this
   */
  public function andGreaterThanOrEqual(string $column, mixed $value): self
  {
    return $this->andValue($column, '>=', $value);
  }

  /**
   * Adds column < value as OR.
   *
   * @return $this
   */
  public function orLessThan(string $column, mixed $value): self
  {
    return $this->orValue($column, '<', $value);
  }

  /**
   * Adds column <= value as OR.
   *
   * @return $this
   */
  public function orLessThanOrEqual(string $column, mixed $value): self
  {
    return $this->orValue($column, '<=', $value);
  }

  /**
   * Adds column > value as OR.
   *
   * @return $this
   */
  public function orGreaterThan(string $column, mixed $value): self
  {
    return $this->orValue($column, '>', $value);
  }

  /**
   * Adds column >= value as OR.
   *
   * @return $this
   */
  public function orGreaterThanOrEqual(string $column, mixed $value): self
  {
    return $this->orValue($column, '>=', $value);
  }

  /**
   * Short alias for andEquals().
   *
   * @return $this
   */
  public function andEq(string $column, mixed $value): self
  {
    return $this->andEquals($column, $value);
  }

  /**
   * Short alias for andNotEquals().
   *
   * @return $this
   */
  public function andNe(string $column, mixed $value): self
  {
    return $this->andNotEquals($column, $value);
  }

  /**
   * Short alias for orEquals().
   *
   * @return $this
   */
  public function orEq(string $column, mixed $value): self
  {
    return $this->orEquals($column, $value);
  }

  /**
   * Short alias for orNotEquals().
   *
   * @return $this
   */
  public function orNe(string $column, mixed $value): self
  {
    return $this->orNotEquals($column, $value);
  }

  /**
   * Short alias for andLessThan().
   *
   * @return $this
   */
  public function andLt(string $column, mixed $value): self
  {
    return $this->andLessThan($column, $value);
  }

  /**
   * Short alias for andLessThanOrEqual().
   *
   * @return $this
   */
  public function andLte(string $column, mixed $value): self
  {
    return $this->andLessThanOrEqual($column, $value);
  }

  /**
   * Short alias for andGreaterThan().
   *
   * @return $this
   */
  public function andGt(string $column, mixed $value): self
  {
    return $this->andGreaterThan($column, $value);
  }

  /**
   * Short alias for andGreaterThanOrEqual().
   *
   * @return $this
   */
  public function andGte(string $column, mixed $value): self
  {
    return $this->andGreaterThanOrEqual($column, $value);
  }

  /**
   * Short alias for orLessThan().
   *
   * @return $this
   */
  public function orLt(string $column, mixed $value): self
  {
    return $this->orLessThan($column, $value);
  }

  /**
   * Short alias for orLessThanOrEqual().
   *
   * @return $this
   */
  public function orLte(string $column, mixed $value): self
  {
    return $this->orLessThanOrEqual($column, $value);
  }

  /**
   * Short alias for orGreaterThan().
   *
   * @return $this
   */
  public function orGt(string $column, mixed $value): self
  {
    return $this->orGreaterThan($column, $value);
  }

  /**
   * Short alias for orGreaterThanOrEqual().
   *
   * @return $this
   */
  public function orGte(string $column, mixed $value): self
  {
    return $this->orGreaterThanOrEqual($column, $value);
  }

  /**
   * Adds an AND condition with a bound parameter.
   *
   * @return $this
   */
  public function andParam(string $column, string $operator, mixed $value, ?string $name = null): self
  {
    $placeholder        = $this->registerParam($name, $value);
    $this->conditions[] = [$column, $operator, ['raw' => $placeholder]];

    return $this;
  }

  /**
   * Adds an OR condition with a bound parameter.
   *
   * @return $this
   */
  public function orParam(string $column, string $operator, mixed $value, ?string $name = null): self
  {
    $placeholder = $this->registerParam($name, $value);
    $this->appendOrCondition([$column, $operator, ['raw' => $placeholder]]);

    return $this;
  }

  /**
   * Adds column = :param as AND.
   *
   * @return $this
   */
  public function andEqualsParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->andParam($column, '=', $value, $name);
  }

  /**
   * Adds column <> :param as AND.
   *
   * @return $this
   */
  public function andNotEqualsParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->andParam($column, '<>', $value, $name);
  }

  /**
   * Adds column = :param as OR.
   *
   * @return $this
   */
  public function orEqualsParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->orParam($column, '=', $value, $name);
  }

  /**
   * Adds column <> :param as OR.
   *
   * @return $this
   */
  public function orNotEqualsParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->orParam($column, '<>', $value, $name);
  }

  /**
   * Adds column < :param as AND.
   *
   * @return $this
   */
  public function andLessThanParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->andParam($column, '<', $value, $name);
  }

  /**
   * Adds column <= :param as AND.
   *
   * @return $this
   */
  public function andLessThanOrEqualParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->andParam($column, '<=', $value, $name);
  }

  /**
   * Adds column > :param as AND.
   *
   * @return $this
   */
  public function andGreaterThanParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->andParam($column, '>', $value, $name);
  }

  /**
   * Adds column >= :param as AND.
   *
   * @return $this
   */
  public function andGreaterThanOrEqualParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->andParam($column, '>=', $value, $name);
  }

  /**
   * Adds column < :param as OR.
   *
   * @return $this
   */
  public function orLessThanParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->orParam($column, '<', $value, $name);
  }

  /**
   * Adds column <= :param as OR.
   *
   * @return $this
   */
  public function orLessThanOrEqualParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->orParam($column, '<=', $value, $name);
  }

  /**
   * Adds column > :param as OR.
   *
   * @return $this
   */
  public function orGreaterThanParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->orParam($column, '>', $value, $name);
  }

  /**
   * Adds column >= :param as OR.
   *
   * @return $this
   */
  public function orGreaterThanOrEqualParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->orParam($column, '>=', $value, $name);
  }

  /**
   * Short alias for andEqualsParam().
   *
   * @return $this
   */
  public function andEqParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->andEqualsParam($column, $value, $name);
  }

  /**
   * Short alias for andNotEqualsParam().
   *
   * @return $this
   */
  public function andNeParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->andNotEqualsParam($column, $value, $name);
  }

  /**
   * Short alias for orEqualsParam().
   *
   * @return $this
   */
  public function orEqParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->orEqualsParam($column, $value, $name);
  }

  /**
   * Short alias for orNotEqualsParam().
   *
   * @return $this
   */
  public function orNeParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->orNotEqualsParam($column, $value, $name);
  }

  /**
   * Short alias for andLessThanParam().
   *
   * @return $this
   */
  public function andLtParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->andLessThanParam($column, $value, $name);
  }

  /**
   * Short alias for andLessThanOrEqualParam().
   *
   * @return $this
   */
  public function andLteParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->andLessThanOrEqualParam($column, $value, $name);
  }

  /**
   * Short alias for andGreaterThanParam().
   *
   * @return $this
   */
  public function andGtParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->andGreaterThanParam($column, $value, $name);
  }

  /**
   * Short alias for andGreaterThanOrEqualParam().
   *
   * @return $this
   */
  public function andGteParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->andGreaterThanOrEqualParam($column, $value, $name);
  }

  /**
   * Short alias for orLessThanParam().
   *
   * @return $this
   */
  public function orLtParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->orLessThanParam($column, $value, $name);
  }

  /**
   * Short alias for orLessThanOrEqualParam().
   *
   * @return $this
   */
  public function orLteParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->orLessThanOrEqualParam($column, $value, $name);
  }

  /**
   * Short alias for orGreaterThanParam().
   *
   * @return $this
   */
  public function orGtParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->orGreaterThanParam($column, $value, $name);
  }

  /**
   * Short alias for orGreaterThanOrEqualParam().
   *
   * @return $this
   */
  public function orGteParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->orGreaterThanOrEqualParam($column, $value, $name);
  }

  /**
   * Adds column IN (...) as AND with bound params.
   *
   * @param array<int, mixed> $values
   *
   * @return $this
   */
  public function andIn(string $column, array $values, ?string $prefix = null): self
  {
    $filtered = array_values(array_filter(
      $values,
      static fn ($value) => null !== $value && ( ! \is_string($value) || '' !== $value)
    ));

    if ([] === $filtered) {
      return $this;
    }

    $items = [];
    foreach ($filtered as $idx => $value) {
      $name        = ($prefix ?? $this->prefix).'_'.$idx;
      $placeholder = $this->registerParam($name, $value);
      $items[]     = ['raw' => $placeholder];
    }

    $this->conditions[] = [$column, 'in', $items];

    return $this;
  }

  /**
   * Adds column IN (...) as OR with bound params.
   *
   * @param array<int, mixed> $values
   *
   * @return $this
   */
  public function orIn(string $column, array $values, ?string $prefix = null): self
  {
    $filtered = array_values(array_filter(
      $values,
      static fn ($value) => null !== $value && ( ! \is_string($value) || '' !== $value)
    ));

    if ([] === $filtered) {
      return $this;
    }

    $items = [];
    foreach ($filtered as $idx => $value) {
      $name        = ($prefix ?? $this->prefix).'_'.$idx;
      $placeholder = $this->registerParam($name, $value);
      $items[]     = ['raw' => $placeholder];
    }

    $this->appendOrCondition([$column, 'in', $items]);

    return $this;
  }

  /**
   * Adds a grouped AND condition.
   *
   * @return $this
   */
  public function andGroup(callable|array $group): self
  {
    $conditions = $this->resolveGroupConditions($group);
    if (null === $conditions) {
      return $this;
    }

    $this->conditions[] = ['and' => $conditions];

    return $this;
  }

  /**
   * Adds a grouped OR condition.
   *
   * @return $this
   */
  public function orGroup(callable|array $group): self
  {
    $conditions = $this->resolveGroupConditions($group);
    if (null === $conditions) {
      return $this;
    }

    $this->conditions[] = ['or' => $conditions];

    return $this;
  }

  /**
   * Returns collected conditions.
   *
   * @return array<int, mixed>
   */
  public function conditions(): array
  {
    return $this->conditions;
  }

  /**
   * Returns collected parameters.
   *
   * @return array<string, mixed>
   */
  public function params(): array
  {
    return $this->params;
  }

  /**
   * Returns true if any conditions exist.
   */
  public function hasConditions(): bool
  {
    return [] !== $this->conditions;
  }

  private function registerParam(?string $name, mixed $value): string
  {
    $base  = $this->normalizeParamName($name) ?? $this->prefix;
    $param = ':'.$base;

    if (\array_key_exists($param, $this->params)) {
      $param = $this->nextParam($base);
    }

    $this->params[$param] = $value;

    return $param;
  }

  private function appendOrCondition(mixed $condition): void
  {
    $count = \count($this->conditions);
    if (0 === $count) {
      $this->conditions[] = $condition;

      return;
    }

    $lastIndex = $count - 1;
    $last      = $this->conditions[$lastIndex];

    if (\is_array($last) && \array_key_exists('or', $last)) {
      $orGroup = $last['or'];
      if (\is_object($orGroup)) {
        $orGroup = get_object_vars($orGroup);
      }

      if (\is_array($orGroup) && $this->isList($orGroup) && ! $this->isConditionTriple($orGroup)) {
        $items = $orGroup;
      } else {
        $items = [$orGroup];
      }

      $items[]                      = $condition;
      $last['or']                   = $items;
      $this->conditions[$lastIndex] = $last;

      return;
    }

    $this->conditions[$lastIndex] = ['or' => [$last, $condition]];
  }

  private function resolveGroupConditions(callable|array $group): ?array
  {
    if (\is_callable($group)) {
      $child = $this->spawnChild();
      $group($child);

      return $child->hasConditions() ? $child->conditions() : null;
    }

    if (\is_object($group)) {
      $group = get_object_vars($group);
    }

    if ( ! \is_array($group) || [] === $group) {
      return null;
    }

    if ($this->isConditionTriple($group)) {
      return [$group];
    }

    return $group;
  }

  private function spawnChild(): self
  {
    $child          = new self($this->prefix);
    $child->params  = &$this->params;
    $child->counter = &$this->counter;

    return $child;
  }

  private function isConditionTriple(array $value): bool
  {
    if (3 !== \count($value)) {
      return false;
    }
    if ( ! \array_key_exists(1, $value)) {
      return false;
    }

    return \is_string($value[1]) && '' !== trim($value[1]);
  }

  private function isList(array $value): bool
  {
    if ([] === $value) {
      return true;
    }
    $i = 0;
    foreach ($value as $key => $_) {
      if ($key !== $i) {
        return false;
      }
      ++$i;
    }

    return true;
  }

  private function nextParam(string $base): string
  {
    $suffix = 1;
    do {
      $param = ':'.$base.'_'.$suffix;
      ++$suffix;
    } while (\array_key_exists($param, $this->params));

    return $param;
  }

  private function normalizeParamName(?string $name): ?string
  {
    if (null === $name) {
      return null;
    }

    $name = trim($name);
    if ('' === $name) {
      return null;
    }

    $name = ltrim($name, ':');
    if ('' === $name) {
      throw new InvalidArgumentException('Parametername darf nicht leer sein.');
    }

    return $name;
  }
}
