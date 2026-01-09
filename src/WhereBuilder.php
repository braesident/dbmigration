<?php

declare(strict_types=1);

namespace Braesident\DbMigration;

use InvalidArgumentException;

final class WhereBuilder
{
  private array $conditions = [];
  private array $params = [];
  private int $counter = 0;
  private string $prefix;

  public function __construct(string $prefix = 'p')
  {
    $prefix = trim($prefix);
    $this->prefix = '' !== $prefix ? $prefix : 'p';
  }

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

  public function andValue(string $column, string $operator, mixed $value): self
  {
    $this->conditions[] = [$column, $operator, ['value' => $value]];

    return $this;
  }

  public function orValue(string $column, string $operator, mixed $value): self
  {
    $this->appendOrCondition([$column, $operator, ['value' => $value]]);

    return $this;
  }

  public function andEquals(string $column, mixed $value): self
  {
    return $this->andValue($column, '=', $value);
  }

  public function andNotEquals(string $column, mixed $value): self
  {
    return $this->andValue($column, '<>', $value);
  }

  public function orEquals(string $column, mixed $value): self
  {
    return $this->orValue($column, '=', $value);
  }

  public function orNotEquals(string $column, mixed $value): self
  {
    return $this->orValue($column, '<>', $value);
  }

  public function andParam(string $column, string $operator, mixed $value, ?string $name = null): self
  {
    $placeholder = $this->registerParam($name, $value);
    $this->conditions[] = [$column, $operator, ['raw' => $placeholder]];

    return $this;
  }

  public function orParam(string $column, string $operator, mixed $value, ?string $name = null): self
  {
    $placeholder = $this->registerParam($name, $value);
    $this->appendOrCondition([$column, $operator, ['raw' => $placeholder]]);

    return $this;
  }

  public function andEqualsParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->andParam($column, '=', $value, $name);
  }

  public function andNotEqualsParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->andParam($column, '<>', $value, $name);
  }

  public function orEqualsParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->orParam($column, '=', $value, $name);
  }

  public function orNotEqualsParam(string $column, mixed $value, ?string $name = null): self
  {
    return $this->orParam($column, '<>', $value, $name);
  }

  public function andIn(string $column, array $values, ?string $prefix = null): self
  {
    $filtered = array_values(array_filter(
      $values,
      static fn ($value) => null !== $value && (! \is_string($value) || '' !== $value)
    ));

    if ([] === $filtered) {
      return $this;
    }

    $items = [];
    foreach ($filtered as $idx => $value) {
      $name = ($prefix ?? $this->prefix).'_'.$idx;
      $placeholder = $this->registerParam($name, $value);
      $items[] = ['raw' => $placeholder];
    }

    $this->conditions[] = [$column, 'in', $items];

    return $this;
  }

  public function orIn(string $column, array $values, ?string $prefix = null): self
  {
    $filtered = array_values(array_filter(
      $values,
      static fn ($value) => null !== $value && (! \is_string($value) || '' !== $value)
    ));

    if ([] === $filtered) {
      return $this;
    }

    $items = [];
    foreach ($filtered as $idx => $value) {
      $name = ($prefix ?? $this->prefix).'_'.$idx;
      $placeholder = $this->registerParam($name, $value);
      $items[] = ['raw' => $placeholder];
    }

    $this->appendOrCondition([$column, 'in', $items]);

    return $this;
  }

  public function andGroup(callable|array $group): self
  {
    $conditions = $this->resolveGroupConditions($group);
    if (null === $conditions) {
      return $this;
    }

    $this->conditions[] = ['and' => $conditions];

    return $this;
  }

  public function orGroup(callable|array $group): self
  {
    $conditions = $this->resolveGroupConditions($group);
    if (null === $conditions) {
      return $this;
    }

    $this->conditions[] = ['or' => $conditions];

    return $this;
  }

  public function conditions(): array
  {
    return $this->conditions;
  }

  public function params(): array
  {
    return $this->params;
  }

  public function hasConditions(): bool
  {
    return [] !== $this->conditions;
  }

  private function registerParam(?string $name, mixed $value): string
  {
    $base = $this->normalizeParamName($name) ?? $this->prefix;
    $param = ':'.$base;

    if (array_key_exists($param, $this->params)) {
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
    $last = $this->conditions[$lastIndex];

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

      $items[] = $condition;
      $last['or'] = $items;
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
    $child = new self($this->prefix);
    $child->params = &$this->params;
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
      $suffix++;
    } while (array_key_exists($param, $this->params));

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
