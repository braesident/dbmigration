<?php

declare(strict_types=1);

namespace Braesident\DbMigration;

use InvalidArgumentException;
use stdClass;

final class SelectBuilder
{
  private array $context;
  private string $dialect;

  private bool $distinct = false;
  private array $select = [];
  private ?array $from = null;
  private array $joins = [];
  private array $where = [];
  private array $groupBy = [];
  private array $having = [];
  private array $orderBy = [];
  private ?int $limit = null;
  private ?int $offset = null;
  private array $unions = [];

  public function __construct(string|array $dialect = 'mysql')
  {
    $this->context = self::normalizeDialectContext($dialect);
    $this->dialect = $this->context['dialect'];
  }

  public static function build(mixed $selectOrQuery, string|array $dialect): string
  {
    if ($selectOrQuery instanceof self) {
      return $selectOrQuery->statement();
    }

    if (self::looksLikeQuery($selectOrQuery)) {
      return self::fromQuery($selectOrQuery, $dialect)->statement();
    }

    return self::buildSelectSql($selectOrQuery, $dialect);
  }

  public static function expr(mixed $expr, string|array $dialect): string
  {
    $builder = new self($dialect);

    return $builder->renderExpr($expr);
  }

  public static function condition(mixed $conditions, string|array $dialect, string $glue = 'AND'): string
  {
    $builder = new self($dialect);

    return $builder->renderConditionGroup($conditions, $glue);
  }

  public static function fromQuery(mixed $query, string|array $dialect): self
  {
    if ($query instanceof self) {
      return $query;
    }

    $builder = new self($dialect);
    $builder->applyQuery($query);

    return $builder;
  }

  public static function fn(string $name, mixed ...$args): array
  {
    return [
      'fn'   => $name,
      'args' => $args
    ];
  }

  public static function calc(mixed $left, string $op, mixed $right): array
  {
    return [
      'op'    => $op,
      'left'  => $left,
      'right' => $right
    ];
  }

  public static function col(string $name, ?string $as = null): array
  {
    $col = ['col' => $name];
    if (\is_string($as) && '' !== $as) {
      $col['as'] = $as;
    }

    return $col;
  }

  public static function val(mixed $value): array
  {
    return ['value' => $value];
  }

  public static function when(mixed $condition, mixed $then): array
  {
    return [
      'cond' => $condition,
      'then' => $then
    ];
  }

  public static function case(array $when, mixed $else = null): array
  {
    $case = ['when' => $when];
    if (func_num_args() > 1) {
      $case['else'] = $else;
    }

    return ['case' => $case];
  }

  public function distinct(bool $distinct = true): self
  {
    $this->distinct = $distinct;

    return $this;
  }

  public function select(mixed $expr, ?string $as = null): self
  {
    if (\is_string($as) && '' !== $as) {
      $this->select[] = ['expr' => $expr, 'as' => $as];

      return $this;
    }

    $this->select[] = $expr;

    return $this;
  }

  public function from(mixed $table, ?string $as = null): self
  {
    $this->from = $this->normalizeFrom($table, $as);

    return $this;
  }

  public function join(mixed $table, ?string $as = null, mixed $on = null): self
  {
    return $this->addJoin('INNER', $table, $as, $on);
  }

  public function leftJoin(mixed $table, ?string $as = null, mixed $on = null): self
  {
    return $this->addJoin('LEFT', $table, $as, $on);
  }

  public function rightJoin(mixed $table, ?string $as = null, mixed $on = null): self
  {
    return $this->addJoin('RIGHT', $table, $as, $on);
  }

  private function addJoin(string $type, mixed $table, ?string $as, mixed $on): self
  {
    if ($table instanceof self) {
      $join = ['query' => $table];
    } elseif (\is_object($table)) {
      $table = ($table instanceof stdClass) ? (array) $table : get_object_vars($table);
      $join  = $table;
    } elseif (\is_array($table)) {
      $join = $table;
    } elseif (\is_string($table)) {
      $join = ['table' => $table];
    } else {
      throw new InvalidArgumentException('join benötigt table oder query.');
    }

    if (null !== $as && ! isset($join['as']) && ! isset($join['alias'])) {
      $join['as'] = $as;
    }
    if (null !== $on && ! isset($join['on'])) {
      $join['on'] = $on;
    }

    $this->joins[] = $this->normalizeJoin($join, $type);

    return $this;
  }

  public function where(mixed $condition): self
  {
    if (null !== $condition) {
      $this->where[] = $condition;
    }

    return $this;
  }

  public function having(mixed $condition): self
  {
    if (null !== $condition) {
      $this->having[] = $condition;
    }

    return $this;
  }

  public function groupBy(mixed $columns): self
  {
    foreach ($this->normalizeList($columns) as $column) {
      $this->groupBy[] = $column;
    }

    return $this;
  }

  public function orderBy(mixed $expr, ?string $direction = null): self
  {
    $this->orderBy[] = [
      'expr' => $expr,
      'dir'  => $direction
    ];

    return $this;
  }

  public function limit(?int $limit, ?int $offset = null): self
  {
    $this->limit  = $limit;
    $this->offset = $offset;

    return $this;
  }

  public function union(mixed $query, bool $all = false): self
  {
    $this->unions[] = [
      'query' => $query,
      'all'   => $all
    ];

    return $this;
  }

  public function statement(): string
  {
    $select = $this->select;
    if ([] === $select) {
      $select = ['*'];
    }

    $sql = 'SELECT ';
    if ($this->distinct) {
      $sql .= 'DISTINCT ';
    }
    $sql .= $this->renderSelectList($select);

    if (null !== $this->from) {
      $sql .= ' FROM '.$this->renderFrom($this->from);
    }

    foreach ($this->joins as $join) {
      $sql .= ' '.$this->renderJoin($join);
    }

    if ([] !== $this->where) {
      $sql .= ' WHERE '.$this->renderConditionGroup($this->where, 'AND');
    }

    if ([] !== $this->groupBy) {
      $sql .= ' GROUP BY '.$this->renderGroupBy($this->groupBy);
    }

    if ([] !== $this->having) {
      $sql .= ' HAVING '.$this->renderConditionGroup($this->having, 'AND');
    }

    if ([] !== $this->orderBy) {
      $sql .= ' ORDER BY '.$this->renderOrderBy($this->orderBy);
    }

    if (null !== $this->limit || null !== $this->offset) {
      $sql .= ' '.$this->renderLimitOffset($this->limit, $this->offset);
    }

    if ([] === $this->unions) {
      return $sql;
    }

    $statement = '('.$sql.')';
    foreach ($this->unions as $union) {
      $unionSql = $this->renderUnionQuery($union['query']);
      $statement .= ' UNION'.($union['all'] ? ' ALL' : '').' ('.$unionSql.')';
    }

    return $statement;
  }

  private static function buildSelectSql(mixed $select, string|array $dialect): string
  {
    $dialectName = self::normalizeDialect($dialect);

    if (\is_string($select)) {
      return self::normalizeSqlBody($select, 'select');
    }

    if (\is_object($select)) {
      $select = ($select instanceof stdClass) ? (array) $select : get_object_vars($select);
    }

    if (\is_array($select)) {
      if (self::isList($select)) {
        return self::normalizeSqlBody($select, 'select');
      }

      $normalized = [];
      foreach ($select as $key => $value) {
        $normalized[mb_strtolower((string) $key)] = $value;
      }

      $dialectMap = [
        'mysql'  => ['mysql', 'mariadb'],
        'sqlsrv' => ['sqlsrv', 'sqlserver', 'mssql']
      ];

      if (isset($dialectMap[$dialectName])) {
        foreach ($dialectMap[$dialectName] as $key) {
          if (\array_key_exists($key, $normalized)) {
            return self::normalizeSqlBody($normalized[$key], 'select');
          }
        }
      }

      if (\array_key_exists('sql', $normalized)) {
        return self::normalizeSqlBody($normalized['sql'], 'select');
      }

      if (\array_key_exists('default', $normalized)) {
        return self::normalizeSqlBody($normalized['default'], 'select');
      }

      throw new InvalidArgumentException('Kein SELECT für Dialekt "'.$dialectName.'" gefunden.');
    }

    throw new InvalidArgumentException('select muss string, array oder object sein.');
  }

  private function applyQuery(mixed $query): void
  {
    if (\is_object($query)) {
      $query = ($query instanceof stdClass) ? (array) $query : get_object_vars($query);
    }
    if ( ! \is_array($query)) {
      throw new InvalidArgumentException('query muss array oder object sein.');
    }

    $normalized = [];
    foreach ($query as $key => $value) {
      $normalized[self::normalizeKey($key)] = $value;
    }

    if (isset($normalized['distinct'])) {
      $this->distinct((bool) $normalized['distinct']);
    }

    if (isset($normalized['select'])) {
      foreach ($this->normalizeSelectList($normalized['select']) as $item) {
        $this->select[] = $item;
      }
    }

    if (isset($normalized['from'])) {
      $this->from = $this->normalizeFrom($normalized['from'], null);
    }

    foreach (['join' => 'INNER', 'leftjoin' => 'LEFT', 'rightjoin' => 'RIGHT'] as $key => $type) {
      if (isset($normalized[$key])) {
        foreach ($this->normalizeJoinList($normalized[$key], $type) as $join) {
          $this->joins[] = $join;
        }
      }
    }

    if (isset($normalized['where'])) {
      foreach ($this->normalizeConditionList($normalized['where']) as $condition) {
        $this->where[] = $condition;
      }
    }

    if (isset($normalized['groupby'])) {
      $this->groupBy($normalized['groupby']);
    }

    if (isset($normalized['having'])) {
      foreach ($this->normalizeConditionList($normalized['having']) as $condition) {
        $this->having[] = $condition;
      }
    }

    if (isset($normalized['orderby'])) {
      foreach ($this->normalizeOrderList($normalized['orderby']) as $order) {
        $this->orderBy[] = $order;
      }
    }

    if (array_key_exists('limit', $normalized)) {
      $limit = $normalized['limit'];
      $this->limit = \is_numeric($limit) ? (int) $limit : null;
    }

    if (array_key_exists('offset', $normalized)) {
      $offset = $normalized['offset'];
      $this->offset = \is_numeric($offset) ? (int) $offset : null;
    }

    if (isset($normalized['union'])) {
      foreach ($this->normalizeUnionList($normalized['union'], false) as $union) {
        $this->unions[] = $union;
      }
    }

    if (isset($normalized['unionall'])) {
      foreach ($this->normalizeUnionList($normalized['unionall'], true) as $union) {
        $this->unions[] = $union;
      }
    }
  }

  private function normalizeSelectList(mixed $select): array
  {
    if (\is_string($select) || \is_object($select) || ! \is_array($select) || ! self::isList($select)) {
      return [$select];
    }

    return $select;
  }

  private function normalizeFrom(mixed $from, ?string $as): array
  {
    if ($from instanceof self) {
      return [
        'type'  => 'query',
        'value' => $from,
        'as'    => $as
      ];
    }

    if (\is_object($from)) {
      $from = ($from instanceof stdClass) ? (array) $from : get_object_vars($from);
    }

    if (\is_string($from)) {
      return [
        'type'  => 'table',
        'value' => $from,
        'as'    => $as
      ];
    }

    if (\is_array($from)) {
      $alias = $from['as'] ?? $from['alias'] ?? $as;
      if (isset($from['query'])) {
        return [
          'type'  => 'query',
          'value' => $from['query'],
          'as'    => $alias
        ];
      }
      $table = $from['table'] ?? $from['name'] ?? null;
      if (\is_string($table) && '' !== $table) {
        return [
          'type'  => 'table',
          'value' => $table,
          'as'    => $alias
        ];
      }
    }

    throw new InvalidArgumentException('from benötigt table oder query.');
  }

  private function normalizeJoinList(mixed $joins, string $type): array
  {
    $list = $this->normalizeList($joins);
    $result = [];
    foreach ($list as $join) {
      $result[] = $this->normalizeJoin($join, $type);
    }

    return $result;
  }

  private function normalizeJoin(mixed $join, string $type): array
  {
    if (\is_object($join)) {
      $join = ($join instanceof stdClass) ? (array) $join : get_object_vars($join);
    }

    if (\is_string($join)) {
      return [
        'type'  => $type,
        'table' => $join,
        'as'    => null,
        'on'    => null,
        'using' => null
      ];
    }

    if (\is_array($join)) {
      $table = $join['table'] ?? $join['name'] ?? null;
      $query = $join['query'] ?? null;
      $alias = $join['as'] ?? $join['alias'] ?? null;
      $on    = $join['on'] ?? null;
      $using = $join['using'] ?? null;

      if (null === $table && null === $query) {
        throw new InvalidArgumentException('join benötigt table oder query.');
      }

      return [
        'type'  => $type,
        'table' => $table,
        'query' => $query,
        'as'    => $alias,
        'on'    => $on,
        'using' => $using
      ];
    }

    throw new InvalidArgumentException('join muss array oder string sein.');
  }

  private function normalizeOrderList(mixed $orderBy): array
  {
    $list = $this->normalizeList($orderBy);
    $result = [];
    foreach ($list as $order) {
      if (\is_string($order)) {
        $result[] = ['expr' => $order, 'dir' => null];
        continue;
      }
      if (\is_object($order)) {
        $order = ($order instanceof stdClass) ? (array) $order : get_object_vars($order);
      }
      if (\is_array($order)) {
        if (self::isList($order) && \count($order) >= 1) {
          $result[] = ['expr' => $order[0], 'dir' => $order[1] ?? null];
          continue;
        }
        $result[] = [
          'expr' => $order['expr'] ?? $order['col'] ?? $order['column'] ?? $order,
          'dir'  => $order['dir'] ?? $order['direction'] ?? null
        ];
        continue;
      }
    }

    return $result;
  }

  private function normalizeUnionList(mixed $unions, bool $all): array
  {
    $list = $this->normalizeList($unions);
    $result = [];
    foreach ($list as $union) {
      if (\is_object($union)) {
        $union = ($union instanceof stdClass) ? (array) $union : get_object_vars($union);
      }
      if (\is_array($union)) {
        $result[] = [
          'query' => $union['query'] ?? $union,
          'all'   => \array_key_exists('all', $union) ? (bool) $union['all'] : $all
        ];
      } else {
        $result[] = [
          'query' => $union,
          'all'   => $all
        ];
      }
    }

    return $result;
  }

  private function normalizeConditionList(mixed $conditions): array
  {
    if (\is_array($conditions) && self::isList($conditions)) {
      if ($this->isConditionTriple($conditions)) {
        return [$conditions];
      }

      return $conditions;
    }

    return [$conditions];
  }

  private function normalizeList(mixed $value): array
  {
    if (\is_array($value) && self::isList($value)) {
      return $value;
    }

    return [$value];
  }

  private function renderSelectList(array $select): string
  {
    $parts = [];
    foreach ($select as $item) {
      $parts[] = $this->renderSelectItem($item);
    }

    return implode(', ', $parts);
  }

  private function renderSelectItem(mixed $item): string
  {
    if (\is_string($item)) {
      return $item;
    }

    if (\is_object($item)) {
      $item = ($item instanceof stdClass) ? (array) $item : get_object_vars($item);
    }

    if (\is_array($item)) {
      if (self::isList($item) && \count($item) >= 1) {
        $expr  = $this->renderExpr($item[0]);
        $alias = $item[1] ?? null;
        if (\is_string($alias) && '' !== $alias) {
          return $expr.' AS '.$this->quoteIdentifier($alias);
        }

        return $expr;
      }

      $alias = $item['as'] ?? $item['alias'] ?? null;
      $expr  = $this->renderExpr($item);
      if (\is_string($alias) && '' !== $alias) {
        return $expr.' AS '.$this->quoteIdentifier($alias);
      }

      return $expr;
    }

    return $this->renderExpr($item);
  }

  private function renderFrom(array $from): string
  {
    $alias = $from['as'] ?? null;

    if ('query' === ($from['type'] ?? '')) {
      $sql = $this->renderSubquery($from['value'] ?? null);
      if (\is_string($alias) && '' !== $alias) {
        return $sql.' AS '.$this->quoteIdentifier($alias);
      }

      return $sql;
    }

    $table = (string) ($from['value'] ?? '');
    if ('' === $table) {
      throw new InvalidArgumentException('from benötigt table.');
    }

    $tableRef = $this->quoteIdentifierPath($table);

    if (\is_string($alias) && '' !== $alias) {
      return $tableRef.' AS '.$this->quoteIdentifier($alias);
    }

    return $tableRef;
  }

  private function renderJoin(array $join): string
  {
    $type = $join['type'] ?? 'INNER';
    $table = $join['table'] ?? null;
    $query = $join['query'] ?? null;
    $alias = $join['as'] ?? null;

    if (null !== $query) {
      $source = $this->renderSubquery($query);
    } elseif (\is_string($table) && '' !== $table) {
      $source = $this->quoteIdentifierPath($table);
    } else {
      throw new InvalidArgumentException('join benötigt table oder query.');
    }

    if (\is_string($alias) && '' !== $alias) {
      $source .= ' AS '.$this->quoteIdentifier($alias);
    }

    $sql = $type.' JOIN '.$source;
    if (isset($join['on']) && null !== $join['on']) {
      $sql .= ' ON '.$this->renderConditionGroup($join['on'], 'AND');
    } elseif (isset($join['using']) && null !== $join['using']) {
      $cols = $this->normalizeList($join['using']);
      $sql .= ' USING ('.implode(', ', array_map([$this, 'quoteIdentifier'], $cols)).')';
    }

    return $sql;
  }

  private function renderGroupBy(array $columns): string
  {
    $parts = [];
    foreach ($columns as $column) {
      $parts[] = \is_string($column) ? $column : $this->renderExpr($column);
    }

    return implode(', ', $parts);
  }

  private function renderOrderBy(array $orderBy): string
  {
    $parts = [];
    foreach ($orderBy as $order) {
      $expr = $order['expr'] ?? $order;
      $dir  = $order['dir'] ?? null;
      $sql  = \is_string($expr) ? $expr : $this->renderExpr($expr);
      if (\is_string($dir) && '' !== $dir) {
        $sql .= ' '.mb_strtoupper($dir);
      }
      $parts[] = $sql;
    }

    return implode(', ', $parts);
  }

  private function renderLimitOffset(?int $limit, ?int $offset): string
  {
    if ('sqlsrv' === $this->dialect) {
      $off = max(0, (int) ($offset ?? 0));
      $lim = null !== $limit ? max(0, (int) $limit) : null;
      $sql = 'OFFSET '.$off.' ROWS';
      if (null !== $lim) {
        $sql .= ' FETCH NEXT '.$lim.' ROWS ONLY';
      }
      if ([] === $this->orderBy) {
        return 'ORDER BY (SELECT 1) '.$sql;
      }

      return $sql;
    }

    if (null === $limit && null !== $offset) {
      $limit = 18446744073709551615;
    }

    if (null === $offset || 0 === $offset) {
      return 'LIMIT '.(int) $limit;
    }

    return 'LIMIT '.(int) $offset.', '.(int) $limit;
  }

  private function renderUnionQuery(mixed $query): string
  {
    if ($query instanceof self) {
      return $query->statement();
    }

    if (\is_string($query)) {
      return $query;
    }

    return self::fromQuery($query, $this->context)->statement();
  }

  private function renderConditionGroup(mixed $conditions, string $glue): string
  {
    if (\is_array($conditions) && self::isList($conditions)) {
      if ($this->isConditionTriple($conditions)) {
        return $this->renderCondition($conditions);
      }

      $parts = [];
      foreach ($conditions as $condition) {
        $parts[] = $this->renderCondition($condition);
      }
      if (\count($parts) > 1) {
        return '('.implode(' '.mb_strtoupper($glue).' ', $parts).')';
      }
      if (1 === \count($parts)) {
        return $parts[0];
      }

      return '1=1';
    }

    return $this->renderCondition($conditions);
  }

  private function renderCondition(mixed $condition): string
  {
    if (\is_object($condition)) {
      $condition = ($condition instanceof stdClass) ? (array) $condition : get_object_vars($condition);
    }

    if (\is_array($condition) && self::isList($condition) && $this->isConditionTriple($condition)) {
      return $this->renderOp($condition[0], (string) $condition[1], $condition[2]);
    }

    if (\is_array($condition) && ! self::isList($condition)) {
      $normalized = [];
      foreach ($condition as $key => $value) {
        $normalized[mb_strtolower((string) $key)] = $value;
      }

      if (isset($normalized['and'])) {
        return $this->renderConditionGroup($normalized['and'], 'AND');
      }
      if (isset($normalized['or'])) {
        return $this->renderConditionGroup($normalized['or'], 'OR');
      }
      if (isset($normalized['not'])) {
        return 'NOT ('.$this->renderCondition($normalized['not']).')';
      }
      if (isset($normalized['exists'])) {
        return 'EXISTS '.$this->renderSubquery($normalized['exists']);
      }
      if (isset($normalized['not_exists'])) {
        return 'NOT EXISTS '.$this->renderSubquery($normalized['not_exists']);
      }
      if (isset($normalized['is_null'])) {
        return $this->renderExpr($normalized['is_null']).' IS NULL';
      }
      if (isset($normalized['is_not_null'])) {
        return $this->renderExpr($normalized['is_not_null']).' IS NOT NULL';
      }
      if (isset($normalized['between'])) {
        $between = $normalized['between'];
        $expr    = $between['expr'] ?? $between['col'] ?? null;
        $min     = $between['min'] ?? null;
        $max     = $between['max'] ?? null;
        if (null === $expr) {
          throw new InvalidArgumentException('between benötigt expr.');
        }
        $sql = $this->renderExpr($expr).' BETWEEN '.$this->renderExpr($min).' AND '.$this->renderExpr($max);
        if ( ! empty($between['not'])) {
          return 'NOT ('.$sql.')';
        }

        return $sql;
      }
    }

    return $this->renderExpr($condition);
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

  private function renderExpr(mixed $expr): string
  {
    if (null === $expr) {
      return 'NULL';
    }

    if (\is_bool($expr)) {
      return $expr ? '1' : '0';
    }

    if (\is_int($expr) || \is_float($expr)) {
      return (string) $expr;
    }

    if (\is_string($expr)) {
      return $expr;
    }

    if ($expr instanceof self) {
      return $this->renderSubquery($expr);
    }

    if (\is_object($expr)) {
      $expr = ($expr instanceof stdClass) ? (array) $expr : get_object_vars($expr);
    }

    if (\is_array($expr)) {
      if (self::isList($expr)) {
        $parts = array_map([$this, 'renderExpr'], $expr);

        return implode(', ', $parts);
      }

      $expr = $this->stripKeys($expr, ['as', 'alias']);

      foreach (['value', 'val', 'literal', 'lit'] as $key) {
        if (\array_key_exists($key, $expr)) {
          return $this->renderLiteral($expr[$key]);
        }
      }

      if (isset($expr['raw'])) {
        return trim((string) $expr['raw']);
      }

      if (isset($expr['col'])) {
        return \is_string($expr['col']) ? $expr['col'] : $this->renderExpr($expr['col']);
      }

      if (isset($expr['expr'])) {
        return $this->renderExpr($expr['expr']);
      }

      if (isset($expr['calc'])) {
        $calc = $expr['calc'];
        if (\is_object($calc)) {
          $calc = ($calc instanceof stdClass) ? (array) $calc : get_object_vars($calc);
        }
        if (\is_array($calc)) {
          $left  = $calc['left'] ?? $calc['col'] ?? null;
          $op    = $calc['op'] ?? null;
          $right = $calc['right'] ?? null;
          if (null === $right) {
            foreach ($calc as $key => $value) {
              if ( ! \in_array($key, ['left', 'col', 'op'], true)) {
                $right = [$key => $value];
                break;
              }
            }
          }
          if (null !== $left && null !== $op && null !== $right) {
            return $this->renderOp($left, $op, $right);
          }
        }
      }

      if (isset($expr['op'])) {
        $left  = $expr['left'] ?? null;
        $right = $expr['right'] ?? null;
        $op    = $expr['op'];

        if (null !== $right) {
          return $this->renderOp($left, $op, $right);
        }

        return $this->renderUnaryOp($op, $left);
      }

      if (isset($expr['case'])) {
        return $this->renderCase($expr['case']);
      }

      if (isset($expr['query'])) {
        return $this->renderSubquery($expr['query']);
      }

      if (isset($expr['fn']) || isset($expr['function'])) {
        $name = (string) ($expr['fn'] ?? $expr['function']);
        $args = $expr['args'] ?? [];

        return $this->renderFunction($name, $args);
      }

      if (1 === \count($expr)) {
        $key = (string) array_key_first($expr);
        $val = $expr[$key];

        return $this->renderFunction($key, $val);
      }
    }

    throw new InvalidArgumentException('Ungültiger Ausdruck.');
  }

  private function renderOp(mixed $left, string $op, mixed $right): string
  {
    $operator = mb_strtoupper(trim($op));
    $leftSql  = $this->renderExpr($left);

    if (\in_array($operator, ['IN', 'NOT IN'], true)) {
      $rightSql = $this->renderInList($right);

      return $leftSql.' '.$operator.' '.$rightSql;
    }

    if (\in_array($operator, ['AND', 'OR'], true)) {
      return '('.$this->renderConditionGroup([$left, $right], $operator).')';
    }

    $rightSql = $this->renderExpr($right);

    return $leftSql.' '.$operator.' '.$rightSql;
  }

  private function renderUnaryOp(string $op, mixed $expr): string
  {
    $operator = mb_strtoupper(trim($op));
    if ('' === $operator) {
      throw new InvalidArgumentException('Operator fehlt.');
    }

    return $operator.' '.$this->renderExpr($expr);
  }

  private function renderInList(mixed $value): string
  {
    if ($value instanceof self) {
      return $this->renderSubquery($value);
    }

    if (\is_object($value)) {
      $value = ($value instanceof stdClass) ? (array) $value : get_object_vars($value);
    }

    if (\is_array($value)) {
      if (self::isList($value)) {
        $items = [];
        foreach ($value as $item) {
          if (\is_string($item) || \is_int($item) || \is_float($item) || \is_bool($item) || null === $item) {
            $items[] = $this->renderLiteral($item);
          } else {
            $items[] = $this->renderExpr($item);
          }
        }

        return '('.implode(', ', $items).')';
      }

      if (isset($value['query'])) {
        return $this->renderSubquery($value['query']);
      }
    }

    if (\is_string($value)) {
      return $value;
    }

    return '('.$this->renderExpr($value).')';
  }

  private function renderCase(mixed $case): string
  {
    if (\is_object($case)) {
      $case = ($case instanceof stdClass) ? (array) $case : get_object_vars($case);
    }
    if ( ! \is_array($case)) {
      throw new InvalidArgumentException('CASE muss array sein.');
    }

    $whens = $case['when'] ?? $case['whens'] ?? null;
    if (null === $whens) {
      throw new InvalidArgumentException('CASE benötigt "when".');
    }

    $parts = ['CASE'];
    foreach ($this->normalizeList($whens) as $when) {
      if (\is_object($when)) {
        $when = ($when instanceof stdClass) ? (array) $when : get_object_vars($when);
      }
      if ( ! \is_array($when)) {
        throw new InvalidArgumentException('CASE when ungültig.');
      }
      $cond = $when['cond'] ?? $when['when'] ?? null;
      $then = $when['then'] ?? null;
      if (null === $cond) {
        throw new InvalidArgumentException('CASE when benötigt "cond".');
      }
      $parts[] = 'WHEN '.$this->renderCondition($cond).' THEN '.$this->renderExpr($then);
    }

    if (\array_key_exists('else', $case)) {
      $parts[] = 'ELSE '.$this->renderExpr($case['else']);
    }

    $parts[] = 'END';

    return implode(' ', $parts);
  }

  private function renderFunction(string $name, mixed $args): string
  {
    $fn = mb_strtolower($name);
    if ('' === $fn) {
      throw new InvalidArgumentException('Funktionsname fehlt.');
    }

    $normalizedArgs = $this->normalizeFunctionArgs($fn, $args);

    if (\in_array($fn, ['ifnull', 'isnull', 'is_null'], true)) {
      $fnName = 'sqlsrv' === $this->dialect ? 'ISNULL' : 'IFNULL';

      return $fnName.'('.implode(', ', $normalizedArgs).')';
    }

    if ('coalesce' === $fn) {
      return 'COALESCE('.implode(', ', $normalizedArgs).')';
    }

    if (\in_array($fn, ['now', 'current_timestamp', 'currenttimestamp'], true)) {
      return 'sqlsrv' === $this->dialect ? 'SYSDATETIME()' : 'CURRENT_TIMESTAMP';
    }

    if (\in_array($fn, ['curdate', 'current_date', 'currentdate'], true)) {
      return 'sqlsrv' === $this->dialect ? 'CONVERT(date, GETDATE())' : 'CURRENT_DATE';
    }

    if (\in_array($fn, ['curtime', 'current_time', 'currenttime'], true)) {
      return 'sqlsrv' === $this->dialect ? 'CONVERT(time, GETDATE())' : 'CURRENT_TIME';
    }

    if (\in_array($fn, ['length', 'len', 'char_length', 'character_length'], true)) {
      $fnName = 'sqlsrv' === $this->dialect ? 'LEN' : 'CHAR_LENGTH';

      return $fnName.'('.implode(', ', $normalizedArgs).')';
    }

    if ('date_format' === $fn || 'dateformat' === $fn) {
      if (\count($normalizedArgs) < 2) {
        throw new InvalidArgumentException('date_format benötigt 2 Argumente.');
      }

      if ('sqlsrv' === $this->dialect) {
        $format = $this->extractLiteral($args, 1);
        $format = $this->convertDateFormatToSqlSrv($format);
        $formatSql = $this->renderLiteral($format);

        return 'FORMAT('.$normalizedArgs[0].', '.$formatSql.')';
      }

      return 'DATE_FORMAT('.$normalizedArgs[0].', '.$normalizedArgs[1].')';
    }

    if (\in_array($fn, ['greatest', 'least'], true)) {
      if (\count($normalizedArgs) < 2) {
        throw new InvalidArgumentException($fn.' benötigt mindestens 2 Argumente.');
      }

      if ('sqlsrv' === $this->dialect) {
        $operator = 'greatest' === $fn ? '>=' : '<=';

        return $this->renderGreatestLeast($normalizedArgs, $operator);
      }

      return mb_strtoupper($fn).'('.implode(', ', $normalizedArgs).')';
    }

    if (\in_array($fn, ['date_add', 'dateadd'], true)) {
      return $this->renderDateAddSub($args, false);
    }

    if (\in_array($fn, ['date_sub', 'datesub'], true)) {
      return $this->renderDateAddSub($args, true);
    }

    $fnName = mb_strtoupper($fn);

    return $fnName.'('.implode(', ', $normalizedArgs).')';
  }

  private function renderGreatestLeast(array $normalizedArgs, string $operator): string
  {
    $expr = array_shift($normalizedArgs);
    foreach ($normalizedArgs as $next) {
      $expr = 'CASE WHEN '.$expr.' '.$operator.' '.$next.' THEN '.$expr.' ELSE '.$next.' END';
    }

    return $expr;
  }

  private function normalizeFunctionArgs(string $fn, mixed $args): array
  {
    if (\is_object($args)) {
      $args = ($args instanceof stdClass) ? (array) $args : get_object_vars($args);
    }

    if ( ! \is_array($args) || ! self::isList($args)) {
      if (\is_array($args)) {
        if (isset($args['args'])) {
          $args = $args['args'];
        } elseif (\in_array($fn, ['ifnull', 'isnull', 'is_null'], true)) {
          $cond = $args['cond'] ?? $args['expr'] ?? null;
          $then = $args['then'] ?? $args['else'] ?? null;
          if (null !== $cond) {
            $args = [$cond, $then];
          } else {
            $args = [$args];
          }
        } else {
          $args = [$args];
        }
      } else {
        $args = [$args];
      }
    }

    $out = [];
    foreach ($args as $index => $arg) {
      if ('date_format' === $fn || 'dateformat' === $fn) {
        if (1 === $index && \is_string($arg)) {
          $out[] = $this->renderLiteral($arg);
          continue;
        }
      }
      $out[] = $this->renderExpr($arg);
    }

    return $out;
  }

  private function extractLiteral(mixed $args, int $index): string
  {
    if (\is_object($args)) {
      $args = ($args instanceof stdClass) ? (array) $args : get_object_vars($args);
    }
    if (\is_array($args) && self::isList($args) && \array_key_exists($index, $args)) {
      $val = $args[$index];
      if (\is_string($val)) {
        return $val;
      }
      if (\is_array($val) && (\array_key_exists('value', $val) || \array_key_exists('literal', $val) || \array_key_exists('val', $val) || \array_key_exists('lit', $val))) {
        foreach (['value', 'literal', 'val', 'lit'] as $key) {
          if (\array_key_exists($key, $val)) {
            return (string) $val[$key];
          }
        }
      }
    }

    return '';
  }

  private function renderDateAddSub(mixed $args, bool $subtract): string
  {
    [$exprSql, $amountSql, $unitSql] = $this->normalizeDateIntervalArgs($args);

    if ('sqlsrv' === $this->dialect) {
      if ($subtract) {
        $amountSql = '0 - ('.$amountSql.')';
      }

      return 'DATEADD('.$unitSql.', '.$amountSql.', '.$exprSql.')';
    }

    $fnName = $subtract ? 'DATE_SUB' : 'DATE_ADD';

    return $fnName.'('.$exprSql.', INTERVAL '.$amountSql.' '.$unitSql.')';
  }

  private function normalizeDateIntervalArgs(mixed $args): array
  {
    if (\is_object($args)) {
      $args = ($args instanceof stdClass) ? (array) $args : get_object_vars($args);
    }

    if (\is_array($args) && self::isList($args) && \count($args) >= 3) {
      $expr   = $args[0];
      $amount = $args[1];
      $unit   = $args[2];

      return [$this->renderExpr($expr), $this->renderExpr($amount), $this->normalizeIntervalUnit($unit)];
    }

    if (\is_array($args)) {
      $expr   = $args['expr'] ?? $args['date'] ?? $args['col'] ?? null;
      $amount = $args['amount'] ?? $args['value'] ?? $args['interval'] ?? null;
      $unit   = $args['unit'] ?? null;
      if (null !== $expr && null !== $amount && null !== $unit) {
        return [$this->renderExpr($expr), $this->renderExpr($amount), $this->normalizeIntervalUnit($unit)];
      }
    }

    throw new InvalidArgumentException('date_add/date_sub benötigt (expr, amount, unit).');
  }

  private function normalizeIntervalUnit(mixed $unit): string
  {
    if (\is_object($unit)) {
      $unit = ($unit instanceof stdClass) ? (array) $unit : get_object_vars($unit);
    }

    if (\is_array($unit)) {
      if (isset($unit['value'])) {
        $unit = $unit['value'];
      } elseif (isset($unit['literal'])) {
        $unit = $unit['literal'];
      } elseif (isset($unit['raw'])) {
        $unit = $unit['raw'];
      }
    }

    if ( ! \is_string($unit) || '' === trim($unit)) {
      throw new InvalidArgumentException('Interval-Unit fehlt.');
    }

    $unit = mb_strtolower(trim($unit));
    $map = [
      'year' => 'YEAR',
      'years' => 'YEAR',
      'yy' => 'YEAR',
      'yyyy' => 'YEAR',
      'quarter' => 'QUARTER',
      'quarters' => 'QUARTER',
      'qq' => 'QUARTER',
      'month' => 'MONTH',
      'months' => 'MONTH',
      'mm' => 'MONTH',
      'week' => 'WEEK',
      'weeks' => 'WEEK',
      'wk' => 'WEEK',
      'day' => 'DAY',
      'days' => 'DAY',
      'dd' => 'DAY',
      'hour' => 'HOUR',
      'hours' => 'HOUR',
      'hh' => 'HOUR',
      'minute' => 'MINUTE',
      'minutes' => 'MINUTE',
      'mi' => 'MINUTE',
      'min' => 'MINUTE',
      'second' => 'SECOND',
      'seconds' => 'SECOND',
      'ss' => 'SECOND'
    ];

    return $map[$unit] ?? mb_strtoupper($unit);
  }

  private function renderSubquery(mixed $query): string
  {
    if ($query instanceof self) {
      return '('.$query->statement().')';
    }

    return '('.self::fromQuery($query, $this->context)->statement().')';
  }

  private function renderLiteral(mixed $value): string
  {
    if (null === $value) {
      return 'NULL';
    }
    if (\is_bool($value)) {
      return $value ? '1' : '0';
    }
    if (\is_int($value) || \is_float($value)) {
      return (string) $value;
    }

    $escaped = str_replace("'", "''", (string) $value);

    return "'".$escaped."'";
  }

  private function quoteIdentifierPath(string $name): string
  {
    $name = trim($name);
    if ('' === $name) {
      return $name;
    }

    if ($this->isRawIdentifier($name)) {
      return $name;
    }

    $parts = explode('.', $name);
    $quoted = [];
    foreach ($parts as $part) {
      if ('' === $part) {
        return $name;
      }
      if ('*' === $part) {
        $quoted[] = $part;
        continue;
      }
      $quoted[] = $this->quoteIdentifier($part);
    }

    return implode('.', $quoted);
  }

  private function isRawIdentifier(string $name): bool
  {
    if (preg_match('/\\s|[()]/', $name)) {
      return true;
    }

    return false !== strpbrk($name, '`[]\"');
  }

  private function quoteIdentifier(string $name): string
  {
    if ('' === $name) {
      return $name;
    }

    if ('sqlsrv' === $this->dialect) {
      return '['.$name.']';
    }

    return '`'.$name.'`';
  }

  private function convertDateFormatToSqlSrv(string $format): string
  {
    if ('' === $format) {
      return $format;
    }

    $map = [
      '%Y' => 'yyyy',
      '%y' => 'yy',
      '%m' => 'MM',
      '%c' => 'M',
      '%d' => 'dd',
      '%e' => 'd',
      '%H' => 'HH',
      '%h' => 'hh',
      '%I' => 'hh',
      '%i' => 'mm',
      '%s' => 'ss',
      '%S' => 'ss',
      '%T' => 'HH:mm:ss',
      '%b' => 'MMM',
      '%M' => 'MMMM',
      '%p' => 'tt',
      '%%' => '%'
    ];

    $out = '';
    $len = strlen($format);
    for ($i = 0; $i < $len; $i++) {
      $ch = $format[$i];
      if ('%' !== $ch) {
        $out .= $ch;
        continue;
      }
      $token = $i + 1 < $len ? $format[$i].$format[$i + 1] : $format[$i];
      if (isset($map[$token])) {
        $out .= $map[$token];
        $i++;
        continue;
      }
      $out .= $ch;
    }

    return $out;
  }

  private static function looksLikeQuery(mixed $value): bool
  {
    if (\is_object($value)) {
      $value = ($value instanceof stdClass) ? (array) $value : get_object_vars($value);
    }
    if ( ! \is_array($value)) {
      return false;
    }

    $keys = array_map(static fn ($key) => self::normalizeKey($key), array_keys($value));
    foreach (['select', 'from', 'where', 'groupby', 'orderby', 'join', 'leftjoin', 'rightjoin', 'limit', 'offset', 'distinct', 'union', 'unionall'] as $key) {
      if (\in_array($key, $keys, true)) {
        return true;
      }
    }

    return false;
  }

  private static function normalizeDialectContext(string|array $dialect): array
  {
    if (\is_array($dialect)) {
      $context      = $dialect;
      $dialectValue = $context['dialect'] ?? $context['driver'] ?? $context['name'] ?? null;
      if ( ! \is_string($dialectValue) || '' === $dialectValue) {
        throw new InvalidArgumentException('SQL-Dialekt fehlt.');
      }
      $context['dialect'] = mb_strtolower($dialectValue);

      return $context;
    }

    if ( ! \is_string($dialect) || '' === $dialect) {
      throw new InvalidArgumentException('SQL-Dialekt fehlt.');
    }

    return ['dialect' => mb_strtolower($dialect)];
  }

  private static function normalizeDialect(string|array $dialect): string
  {
    $context = self::normalizeDialectContext($dialect);

    return $context['dialect'];
  }

  private static function normalizeKey(string|int $key): string
  {
    $key = mb_strtolower((string) $key);

    return str_replace(['_', '-'], '', $key);
  }

  private static function stripKeys(array $value, array $keys): array
  {
    foreach ($keys as $key) {
      if (\array_key_exists($key, $value)) {
        unset($value[$key]);
      }
    }

    return $value;
  }

  private static function normalizeSqlBody(mixed $value, string $fieldName): string
  {
    if (\is_string($value)) {
      $sql = trim($value);
      if ('' === $sql) {
        throw new InvalidArgumentException($fieldName.' darf nicht leer sein.');
      }

      return $sql;
    }

    if (\is_array($value)) {
      if ( ! self::isList($value)) {
        throw new InvalidArgumentException($fieldName.' muss string oder Liste sein.');
      }
      $lines = [];
      foreach ($value as $line) {
        $line = trim((string) $line);
        if ('' !== $line) {
          $lines[] = $line;
        }
      }
      if ([] === $lines) {
        throw new InvalidArgumentException($fieldName.' darf nicht leer sein.');
      }

      return implode("\n", $lines);
    }

    throw new InvalidArgumentException($fieldName.' muss string oder Liste sein.');
  }

  private static function isList(array $value): bool
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
}
