<?php

declare(strict_types=1);

namespace Braesident\DbMigration;

use InvalidArgumentException;
use stdClass;

final class SqlBuilder
{
  /**
   * Builds SQL from a builder definition and dialect.
   */
  public static function build(string|array|object $definition, string|array $dialect): string|array
  {
    $context    = self::normalizeDialectContext($dialect);
    $dialect    = $context['dialect'];
    $normalized = self::normalizeDefinition($definition);
    $type       = mb_strtolower((string) ($normalized['type'] ?? ''));
    if ('' === $type && self::hasDialectSql($normalized)) {
      $type = 'raw';
    }

    return match ($type) {
      'create_table' => self::buildCreateTable($normalized, $dialect),
      'drop_table'   => self::buildDropTable($normalized, $dialect),
      'create_view'  => self::buildCreateView($normalized, $dialect, $context),
      'drop_view'    => self::buildDropView($normalized, $dialect),
      'create_trigger' => self::buildCreateTrigger($normalized, $dialect, $context),
      'alter_trigger'  => self::buildAlterTrigger($normalized, $dialect, $context),
      'drop_trigger'   => self::buildDropTrigger($normalized, $dialect),
      'alter_table'  => self::buildAlterTable($normalized, $dialect, $context),
      'insert'       => self::buildInsert($normalized, $dialect, $context),
      'update'       => self::buildUpdate($normalized, $dialect, $context),
      'delete'       => self::buildDelete($normalized, $dialect, $context),
      'raw'          => self::buildRaw($normalized, $dialect),
      default        => throw new InvalidArgumentException('SQL-Builder kennt den Typ nicht: '.$type)
    };
  }

  private static function normalizeDefinition(string|array|object $definition): array
  {
    if (\is_string($definition)) {
      $decoded = json_decode($definition, true);
      if ( ! \is_array($decoded)) {
        throw new InvalidArgumentException('SQL-Definition ist kein gültiges JSON.');
      }

      return $decoded;
    }

    if (\is_object($definition)) {
      $definition = self::objectToArray($definition);
    }

    if ( ! \is_array($definition)) {
      throw new InvalidArgumentException('SQL-Definition muss string, array oder object sein.');
    }

    return $definition;
  }

  private static function hasDialectSql(array $definition): bool
  {
    $keys = array_map(static fn ($key) => mb_strtolower((string) $key), array_keys($definition));

    return (bool) array_intersect($keys, ['mysql', 'mariadb', 'sqlsrv', 'sqlserver', 'mssql', 'sql', 'default']);
  }

  private static function buildRaw(array $definition, string $dialect): string|array
  {
    $normalized = [];
    foreach ($definition as $key => $value) {
      $normalized[mb_strtolower((string) $key)] = $value;
    }

    $dialectMap = [
      'mysql'  => ['mysql', 'mariadb'],
      'sqlsrv' => ['sqlsrv', 'sqlserver', 'mssql']
    ];

    if (isset($dialectMap[$dialect])) {
      foreach ($dialectMap[$dialect] as $key) {
        if (isset($normalized[$key])) {
          return $normalized[$key];
        }
      }
    }

    if (isset($normalized['sql'])) {
      return $normalized['sql'];
    }

    if (isset($normalized['default'])) {
      return $normalized['default'];
    }

    throw new InvalidArgumentException('Kein SQL für Dialekt "'.$dialect.'" gefunden.');
  }

  private static function objectToArray(object $value): array
  {
    if ($value instanceof stdClass) {
      return (array) $value;
    }

    $result = [];
    foreach (get_object_vars($value) as $key => $val) {
      $result[$key] = \is_object($val) ? self::objectToArray($val) : (\is_array($val) ? self::arrayToArray($val) : $val);
    }

    return $result;
  }

  private static function arrayToArray(array $value): array
  {
    $result = [];
    foreach ($value as $key => $val) {
      if (\is_object($val)) {
        $result[$key] = self::objectToArray($val);
      } elseif (\is_array($val)) {
        $result[$key] = self::arrayToArray($val);
      } else {
        $result[$key] = $val;
      }
    }

    return $result;
  }

  private static function buildCreateTable(array $definition, string $dialect): string|array
  {
    $table = (string) ($definition['table'] ?? '');
    if ('' === $table) {
      throw new InvalidArgumentException('create_table benötigt "table".');
    }

    $columns     = $definition['columns'] ?? [];
    $primaryKey  = $definition['primaryKey'] ?? [];
    $unique      = $definition['unique'] ?? [];
    $indexes     = $definition['indexes'] ?? [];
    $foreignKeys = $definition['foreignKeys'] ?? [];
    $checks      = $definition['checks'] ?? [];
    $ifNotExists = (bool) ($definition['ifNotExists'] ?? true);
    $schema      = (string) ($definition['schema'] ?? 'dbo');

    $lines       = [];
    $extraChecks = [];
    $columnComments = [];

    foreach ($columns as $column) {
      if ( ! \is_array($column)) {
        throw new InvalidArgumentException('Spalten müssen Arrays sein.');
      }
      if ('sqlsrv' === $dialect && isset($column['comment'])) {
        $comment = $column['comment'];
        if (\is_string($comment) && '' !== trim($comment)) {
          $columnName = (string) ($column['name'] ?? '');
          if ('' !== $columnName) {
            $columnComments[$columnName] = $comment;
          }
        }
      }
      $lines[] = self::buildColumn($column, $dialect, $table, $extraChecks);
    }

    if ( ! empty($primaryKey)) {
      $lines[] = 'PRIMARY KEY ('.self::quoteColumnList($primaryKey, $dialect).')';
    }

    foreach ($unique as $uniqueDef) {
      $lines[] = self::buildUnique($uniqueDef, $dialect);
    }

    foreach ($indexes as $indexDef) {
      $lines[] = self::buildIndex($indexDef, $dialect);
    }

    foreach ($foreignKeys as $fkDef) {
      $lines[] = self::buildForeignKey($fkDef, $dialect, $schema);
    }

    foreach ($checks as $checkDef) {
      $lines[] = self::buildCheck($checkDef, $dialect);
    }

    foreach ($extraChecks as $checkSql) {
      $lines[] = $checkSql;
    }

    if ('sqlsrv' === $dialect) {
      $tableRef = '['.$schema.'].['.$table.']';
      if ($ifNotExists) {
        $sql   = [];
        $sql[] = "IF OBJECT_ID(N'".$tableRef."', N'U') IS NULL";
        $sql[] = 'BEGIN';
        $sql[] = '  CREATE TABLE '.$tableRef.' (';
        $sql[] = self::indentLines($lines, 4);
        $sql[] = '  );';
        $sql[] = 'END';

        $createSql = implode("\n", $sql);
        if ([] === $columnComments) {
          return $createSql;
        }

        $commentSql = [];
        foreach ($columnComments as $columnName => $comment) {
          $commentSql[] = self::buildSqlSrvColumnComment($schema, $table, $columnName, $comment);
        }

        return array_merge([$createSql], $commentSql);
      }

      $sql   = [];
      $sql[] = 'CREATE TABLE '.$tableRef.' (';
      $sql[] = self::indentLines($lines, 2);
      $sql[] = ');';

      $createSql = implode("\n", $sql);
      if ([] === $columnComments) {
        return $createSql;
      }

      $commentSql = [];
      foreach ($columnComments as $columnName => $comment) {
        $commentSql[] = self::buildSqlSrvColumnComment($schema, $table, $columnName, $comment);
      }

      return array_merge([$createSql], $commentSql);
    }

    $sql = 'CREATE TABLE';
    if ($ifNotExists) {
      $sql .= ' IF NOT EXISTS';
    }
    $sql .= ' `'.$table.'` ('.\PHP_EOL;
    $sql .= self::indentLines($lines, 2);
    $sql .= \PHP_EOL.') ENGINE = InnoDB';

    return $sql;
  }

  private static function buildDropTable(array $definition, string $dialect): string
  {
    $table = (string) ($definition['table'] ?? '');
    if ('' === $table) {
      throw new InvalidArgumentException('drop_table benötigt "table".');
    }

    $schema   = (string) ($definition['schema'] ?? 'dbo');
    $ifExists = \array_key_exists('ifExists', $definition) ? (bool) $definition['ifExists'] : true;

    if ('sqlsrv' === $dialect) {
      $tableRef = '['.$schema.'].['.$table.']';
      if ($ifExists) {
        return "IF OBJECT_ID(N'".$tableRef."', N'U') IS NOT NULL DROP TABLE ".$tableRef;
      }

      return 'DROP TABLE '.$tableRef;
    }

    $sql = 'DROP TABLE';
    if ($ifExists) {
      $sql .= ' IF EXISTS';
    }
    $sql .= ' `'.$table.'`';

    return $sql;
  }

  private static function buildCreateView(array $definition, string $dialect, array $context): string|array
  {
    $view = (string) ($definition['view'] ?? $definition['name'] ?? '');
    if ('' === $view) {
      throw new InvalidArgumentException('create_view benötigt "view".');
    }

    $schema  = (string) ($definition['schema'] ?? 'dbo');
    $replace = \array_key_exists('replace', $definition) ? (bool) $definition['replace'] : true;
    $select  = $definition['select'] ?? $definition['query'] ?? null;
    if (null === $select) {
      throw new InvalidArgumentException('create_view benötigt "select" oder "query".');
    }
    $selectSql = SelectBuilder::build($select, $context);

    if ('sqlsrv' === $dialect) {
      $viewRef = '['.$schema.'].['.$view.']';
      if ($replace) {
        $sql   = [];
        $sql[] = "IF OBJECT_ID(N'".$viewRef."', N'V') IS NULL EXEC('CREATE VIEW ".$viewRef." AS SELECT 1 AS dummy')";
        $sql[] = 'ALTER VIEW '.$viewRef.' AS '.$selectSql;

        return $sql;
      }

      return 'CREATE VIEW '.$viewRef.' AS '.$selectSql;
    }

    $sql = 'CREATE ';
    if ($replace) {
      $sql .= 'OR REPLACE ';
    }
    $sql .= 'VIEW `'.$view.'` AS '.$selectSql;

    return $sql;
  }

  private static function buildDropView(array $definition, string $dialect): string
  {
    $view = (string) ($definition['view'] ?? $definition['name'] ?? '');
    if ('' === $view) {
      throw new InvalidArgumentException('drop_view benötigt "view".');
    }

    $schema   = (string) ($definition['schema'] ?? 'dbo');
    $ifExists = \array_key_exists('ifExists', $definition) ? (bool) $definition['ifExists'] : true;

    if ('sqlsrv' === $dialect) {
      $viewRef = '['.$schema.'].['.$view.']';
      if ($ifExists) {
        return "IF OBJECT_ID(N'".$viewRef."', N'V') IS NOT NULL DROP VIEW ".$viewRef;
      }

      return 'DROP VIEW '.$viewRef;
    }

    $sql = 'DROP VIEW';
    if ($ifExists) {
      $sql .= ' IF EXISTS';
    }
    $sql .= ' `'.$view.'`';

    return $sql;
  }

  private static function buildCreateTrigger(array $definition, string $dialect, array $context): string|array
  {
    return self::buildTriggerStatement($definition, $dialect, $context, 'CREATE');
  }

  private static function buildAlterTrigger(array $definition, string $dialect, array $context): string|array
  {
    if ('mysql' === $dialect) {
      $name   = self::normalizeTriggerName($definition);
      $schema = (string) ($definition['schema'] ?? 'dbo');

      $dropSql   = self::buildDropTrigger(['name' => $name, 'schema' => $schema, 'ifExists' => true], $dialect);
      $createSql = self::buildTriggerStatement($definition, $dialect, $context, 'CREATE');

      return [$dropSql, $createSql];
    }

    return self::buildTriggerStatement($definition, $dialect, $context, 'ALTER');
  }

  private static function buildDropTrigger(array $definition, string $dialect): string
  {
    $name = (string) ($definition['name'] ?? $definition['trigger'] ?? $definition['triggerName'] ?? '');
    if ('' === $name) {
      throw new InvalidArgumentException('drop_trigger benötigt "name".');
    }

    $schema   = (string) ($definition['schema'] ?? 'dbo');
    $ifExists = \array_key_exists('ifExists', $definition) ? (bool) $definition['ifExists'] : true;

    if ('sqlsrv' === $dialect) {
      $triggerRef = '['.$schema.'].['.$name.']';
      if ($ifExists) {
        return "IF OBJECT_ID(N'".$triggerRef."', N'TR') IS NOT NULL DROP TRIGGER ".$triggerRef;
      }

      return 'DROP TRIGGER '.$triggerRef;
    }

    $sql = 'DROP TRIGGER';
    if ($ifExists) {
      $sql .= ' IF EXISTS';
    }
    $sql .= ' `'.$name.'`';

    return $sql;
  }

  private static function buildTriggerStatement(array $definition, string $dialect, array $context, string $action): string
  {
    $name   = self::normalizeTriggerName($definition);
    $table  = (string) ($definition['table'] ?? '');
    if ('' === $table) {
      throw new InvalidArgumentException('trigger benötigt "table".');
    }

    $schema = (string) ($definition['schema'] ?? 'dbo');
    $timing = self::normalizeTriggerTiming($definition['timing'] ?? $definition['time'] ?? null, $dialect);
    $events = self::normalizeTriggerEvents($definition['events'] ?? $definition['event'] ?? null, $dialect);

    $body = $definition['body'] ?? $definition['statement'] ?? $definition['sql'] ?? null;
    if (null === $body) {
      throw new InvalidArgumentException('trigger benötigt "body" oder "statement".');
    }
    [$bodySql, $autoWrap] = self::normalizeTriggerBody($body, $dialect, $context);
    $wrap = $definition['wrap'] ?? $definition['wrapBody'] ?? null;
    $wrap = \is_bool($wrap) ? $wrap : $autoWrap;
    if ($wrap) {
      $bodySql = "BEGIN\n".$bodySql."\nEND";
    }

    if ('sqlsrv' === $dialect) {
      $triggerRef = '['.$schema.'].['.$name.']';
      $tableRef   = '['.$schema.'].['.$table.']';
      $eventSql   = implode(', ', $events);

      return $action.' TRIGGER '.$triggerRef.' ON '.$tableRef.' '.$timing.' '.$eventSql.' AS '.$bodySql;
    }

    $event = $events[0];
    $forEachRow = \array_key_exists('forEachRow', $definition) ? (bool) $definition['forEachRow'] : true;
    if ( ! $forEachRow) {
      throw new InvalidArgumentException('mysql trigger benötigt FOR EACH ROW.');
    }
    $sql = $action.' TRIGGER `'.$name.'` '.$timing.' '.$event.' ON `'.$table.'`';
    $sql .= ' FOR EACH ROW';
    $sql .= ' '.$bodySql;

    return $sql;
  }

  private static function normalizeTriggerName(array $definition): string
  {
    $name = (string) ($definition['name'] ?? $definition['trigger'] ?? $definition['triggerName'] ?? '');
    if ('' === $name) {
      throw new InvalidArgumentException('trigger benötigt "name".');
    }

    return $name;
  }

  private static function normalizeTriggerTiming(mixed $timing, string $dialect): string
  {
    if (null === $timing) {
      $timing = 'after';
    }

    $timing = self::resolveDialectSqlString($timing, $dialect, 'trigger timing');

    $normalized = mb_strtolower(trim($timing));
    if ('' === $normalized) {
      throw new InvalidArgumentException('trigger timing fehlt.');
    }

    $normalized = str_replace(['_', '-'], ' ', $normalized);
    $normalized = preg_replace('/\\s+/', ' ', $normalized);

    if ('sqlsrv' === $dialect) {
      if (\in_array($normalized, ['after', 'for'], true)) {
        return 'AFTER';
      }
      if ('instead of' === $normalized) {
        return 'INSTEAD OF';
      }
      throw new InvalidArgumentException('sqlsrv trigger timing muss AFTER oder INSTEAD OF sein.');
    }

    if ('before' === $normalized) {
      return 'BEFORE';
    }
    if ('after' === $normalized) {
      return 'AFTER';
    }

    throw new InvalidArgumentException('mysql trigger timing muss BEFORE oder AFTER sein.');
  }

  private static function normalizeTriggerEvents(mixed $events, string $dialect): array
  {
    if (null === $events) {
      throw new InvalidArgumentException('trigger benötigt "event" oder "events".');
    }
    if (\is_object($events)) {
      $events = self::objectToArray($events);
    }

    $parts = [];
    if (\is_string($events)) {
      $normalized = trim($events);
      if ('' === $normalized) {
        throw new InvalidArgumentException('trigger events dürfen nicht leer sein.');
      }
      $parts = preg_split('/\\s*,\\s*|\\s+/', $normalized);
    } elseif (\is_array($events)) {
      $parts = self::isList($events) ? $events : array_values($events);
    } else {
      throw new InvalidArgumentException('trigger events müssen string oder array sein.');
    }

    $out = [];
    foreach ($parts as $event) {
      if ( ! \is_string($event)) {
        throw new InvalidArgumentException('trigger event muss string sein.');
      }
      $event = mb_strtoupper(trim($event));
      if ('' === $event) {
        continue;
      }
      if ( ! \in_array($event, ['INSERT', 'UPDATE', 'DELETE'], true)) {
        throw new InvalidArgumentException('Unbekanntes trigger event: '.$event);
      }
      $out[] = $event;
    }

    if ([] === $out) {
      throw new InvalidArgumentException('trigger events dürfen nicht leer sein.');
    }
    if ('mysql' === $dialect && \count($out) !== 1) {
      throw new InvalidArgumentException('mysql trigger unterstützt nur ein Event.');
    }

    return $out;
  }

  private static function normalizeTriggerBody(mixed $body, string $dialect, array $context): array
  {
    $body = self::resolveDialectTriggerBody($body, $dialect);

    if (\is_array($body) && self::isList($body)) {
      $lines = [];
      foreach ($body as $item) {
        $lines = array_merge($lines, self::renderTriggerBodyLines($item, $dialect, $context));
      }
      if ([] === $lines) {
        throw new InvalidArgumentException('trigger body darf nicht leer sein.');
      }

      return [implode("\n", $lines), true];
    }

    $lines = self::renderTriggerBodyLines($body, $dialect, $context);
    if ([] === $lines) {
      throw new InvalidArgumentException('trigger body darf nicht leer sein.');
    }

    return [implode("\n", $lines), false];
  }

  private static function renderTriggerBodyLines(mixed $item, string $dialect, array $context): array
  {
    if ($item instanceof stdClass) {
      $item = (array) $item;
    }

    if (\is_string($item)) {
      $line = rtrim($item);

      return '' === $line ? [] : [$line];
    }

    if (\is_array($item)) {
      if (self::isList($item)) {
        $lines = [];
        foreach ($item as $part) {
          $lines = array_merge($lines, self::renderTriggerBodyLines($part, $dialect, $context));
        }

        return $lines;
      }

      if (self::looksLikeDefinition($item)) {
        $definition = self::extractDefinition($item);
        $sql        = self::build($definition, $context);

        return self::normalizeTriggerBodySql($sql);
      }

      $resolved = self::resolveDialectTriggerBody($item, $dialect);
      if ($resolved !== $item) {
        return self::renderTriggerBodyLines($resolved, $dialect, $context);
      }
    }

    throw new InvalidArgumentException('trigger body item muss SQL oder Definition sein.');
  }

  private static function normalizeTriggerBodySql(string|array $sql): array
  {
    if (\is_string($sql)) {
      $sql = trim($sql);

      return '' === $sql ? [] : [$sql];
    }
    if ( ! \is_array($sql) || ! self::isList($sql)) {
      throw new InvalidArgumentException('trigger body SQL muss string oder array sein.');
    }
    $lines = [];
    foreach ($sql as $line) {
      if ( ! \is_string($line)) {
        throw new InvalidArgumentException('trigger body SQL array benötigt strings.');
      }
      $line = rtrim($line);
      if ('' !== $line) {
        $lines[] = $line;
      }
    }

    return $lines;
  }

  private static function resolveDialectTriggerBody(mixed $value, string $dialect): mixed
  {
    if ($value instanceof stdClass) {
      $value = (array) $value;
    }

    if (\is_string($value) || null === $value) {
      return $value;
    }

    if (\is_array($value)) {
      if (self::isList($value)) {
        return $value;
      }
      if (self::looksLikeDefinition($value)) {
        return $value;
      }

      $normalized = [];
      foreach ($value as $key => $val) {
        $normalized[mb_strtolower((string) $key)] = $val;
      }

      $dialectMap = [
        'mysql'  => ['mysql', 'mariadb'],
        'sqlsrv' => ['sqlsrv', 'sqlserver', 'mssql']
      ];

      if (isset($dialectMap[$dialect])) {
        foreach ($dialectMap[$dialect] as $key) {
          if (array_key_exists($key, $normalized)) {
            return $normalized[$key];
          }
        }
      }

      if (array_key_exists('sql', $normalized)) {
        return $normalized['sql'];
      }
      if (array_key_exists('default', $normalized)) {
        return $normalized['default'];
      }
    }

    return $value;
  }

  private static function looksLikeDefinition(array $value): bool
  {
    $keys = array_map(static fn ($key) => mb_strtolower((string) $key), array_keys($value));

    return \in_array('type', $keys, true) || \in_array('definition', $keys, true) || \in_array('builder', $keys, true);
  }

  private static function extractDefinition(array $value): array
  {
    if (array_key_exists('definition', $value)) {
      return self::normalizeDefinition($value['definition']);
    }

    return $value;
  }

  private static function resolveDialectSqlStringOrList(mixed $value, string $dialect): string|array
  {
    if (\is_object($value)) {
      $value = self::objectToArray($value);
    }
    if (\is_string($value)) {
      return $value;
    }
    if (\is_array($value)) {
      if (self::isList($value)) {
        return $value;
      }

      $normalized = [];
      foreach ($value as $key => $val) {
        $normalized[mb_strtolower((string) $key)] = $val;
      }

      $dialectMap = [
        'mysql'  => ['mysql', 'mariadb'],
        'sqlsrv' => ['sqlsrv', 'sqlserver', 'mssql']
      ];

      if (isset($dialectMap[$dialect])) {
        foreach ($dialectMap[$dialect] as $key) {
          if (array_key_exists($key, $normalized)) {
            return $normalized[$key];
          }
        }
      }

      if (array_key_exists('sql', $normalized)) {
        return $normalized['sql'];
      }
      if (array_key_exists('default', $normalized)) {
        return $normalized['default'];
      }
    }

    throw new InvalidArgumentException('Kein SQL für Trigger-Body gefunden.');
  }

  private static function resolveDialectSqlString(mixed $value, string $dialect, string $label = 'SQL'): string
  {
    $resolved = self::resolveDialectSqlStringOrList($value, $dialect);
    if ( \is_array($resolved)) {
      throw new InvalidArgumentException($label.' muss string sein.');
    }

    return $resolved;
  }

  private static function buildAlterTable(array $definition, string $dialect, array $context): string|array
  {
    $table = (string) ($definition['table'] ?? '');
    if ('' === $table) {
      throw new InvalidArgumentException('alter_table benötigt "table".');
    }

    $schema  = (string) ($definition['schema'] ?? 'dbo');
    $actions = $definition['actions'] ?? $definition['action'] ?? null;
    if (null === $actions) {
      throw new InvalidArgumentException('alter_table benötigt "actions".');
    }
    if ( ! \is_array($actions)) {
      throw new InvalidArgumentException('alter_table "actions" muss array sein.');
    }
    if ( ! self::isList($actions)) {
      $actions = [$actions];
    }

    $tableRef   = 'sqlsrv' === $dialect ? '['.$schema.'].['.$table.']' : '`'.$table.'`';
    $statements = [];

    foreach ($actions as $action) {
      if ( ! \is_array($action)) {
        throw new InvalidArgumentException('alter_table Action muss array sein.');
      }
      $actionType = mb_strtolower((string) ($action['action'] ?? $action['type'] ?? ''));
      if ('' === $actionType) {
        throw new InvalidArgumentException('alter_table Action benötigt "action".');
      }

      switch ($actionType) {
        case 'add_column':
          $column = $action['column'] ?? null;
          if (null === $column) {
            $column = $action;
            unset($column['action'], $column['type'], $column['after'], $column['first']);
          }
          if ( ! \is_array($column)) {
            throw new InvalidArgumentException('add_column benötigt "column".');
          }

          $extraChecks = [];
          $columnSql   = self::buildColumn($column, $dialect, $table, $extraChecks);
          $sql         = 'ALTER TABLE '.$tableRef.' ADD '.$columnSql;

          if ('mysql' === $dialect) {
            $after = $action['after'] ?? null;
            $first = (bool) ($action['first'] ?? false);
            if ($first) {
              $sql .= ' FIRST';
            } elseif (\is_string($after) && '' !== $after) {
              $sql .= ' AFTER `'.$after.'`';
            }
          }
          $statements[] = $sql;

          foreach ($extraChecks as $checkSql) {
            $statements[] = 'ALTER TABLE '.$tableRef.' ADD '.$checkSql;
          }
          break;

        case 'modify_column':
          $column = $action['column'] ?? null;
          if (null === $column) {
            $column = $action;
            unset($column['action'], $column['type'], $column['after'], $column['first']);
          }
          if ( ! \is_array($column)) {
            throw new InvalidArgumentException('modify_column benötigt "column".');
          }

          $extraChecks = [];
          if ('sqlsrv' === $dialect) {
            if (\array_key_exists('default', $column) || \array_key_exists('onUpdate', $column) || ! empty($column['autoIncrement']) || ! empty($column['identity'])) {
              throw new InvalidArgumentException('sqlsrv modify_column unterstützt kein default/onUpdate/identity.');
            }
            $name = (string) ($column['name'] ?? '');
            if ('' === $name) {
              throw new InvalidArgumentException('Spalte benötigt "name".');
            }
            $type      = $column['type'] ?? 'string';
            $length    = $column['length'] ?? null;
            $precision = $column['precision'] ?? null;
            $scale     = $column['scale'] ?? null;
            $unsigned  = (bool) ($column['unsigned'] ?? false);
            $enum      = $column['enum'] ?? null;
            $raw       = $column[$dialect] ?? $column['raw'] ?? null;
            $nullable  = \array_key_exists('nullable', $column) ? (bool) $column['nullable'] : true;

            $sqlType   = $raw ? (string) $raw : self::mapType($type, $dialect, $length, $precision, $scale, $unsigned, $enum, $table, $name, $extraChecks);
            $columnSql = self::quoteColumn($name, $dialect).' '.$sqlType.' '.($nullable ? 'NULL' : 'NOT NULL');
            $statements[] = 'ALTER TABLE '.$tableRef.' ALTER COLUMN '.$columnSql;

            foreach ($extraChecks as $checkSql) {
              $statements[] = 'ALTER TABLE '.$tableRef.' ADD '.$checkSql;
            }
          } else {
            $columnSql = self::buildColumn($column, $dialect, $table, $extraChecks);
            $sql       = 'ALTER TABLE '.$tableRef.' MODIFY COLUMN '.$columnSql;

            $after = $action['after'] ?? null;
            $first = (bool) ($action['first'] ?? false);
            if ($first) {
              $sql .= ' FIRST';
            } elseif (\is_string($after) && '' !== $after) {
              $sql .= ' AFTER `'.$after.'`';
            }
            $statements[] = $sql;
          }
          break;

        case 'drop_column':
          $name = (string) ($action['name'] ?? '');
          if ('' === $name) {
            throw new InvalidArgumentException('drop_column benötigt "name".');
          }
          $statements[] = 'ALTER TABLE '.$tableRef.' DROP COLUMN '.self::quoteColumn($name, $dialect);
          break;

        case 'rename_column':
          $from = (string) ($action['from'] ?? $action['old'] ?? '');
          $to   = (string) ($action['to'] ?? $action['new'] ?? '');
          if ('' === $from || '' === $to) {
            throw new InvalidArgumentException('rename_column benötigt "from" und "to".');
          }
          if ('sqlsrv' === $dialect) {
            $statements[] = "EXEC sp_rename '".$schema.'.'.$table.'.'.$from."', '".$to."', 'COLUMN'";
          } else {
            $column = $action['column'] ?? null;
            if (null === $column) {
              $column = $action;
              unset($column['action'], $column['type'], $column['from'], $column['to'], $column['old'], $column['new']);
            }
            if ( ! \is_array($column)) {
              throw new InvalidArgumentException('rename_column benötigt Spaltendefinition für mysql.');
            }
            if ( ! isset($column['name']) || '' === (string) $column['name']) {
              $column['name'] = $to;
            }

            $extraChecks = [];
            $columnSql   = self::buildColumn($column, $dialect, $table, $extraChecks);
            $statements[] = 'ALTER TABLE '.$tableRef.' CHANGE COLUMN '.self::quoteColumn($from, $dialect).' '.$columnSql;

            foreach ($extraChecks as $checkSql) {
              $statements[] = 'ALTER TABLE '.$tableRef.' ADD '.$checkSql;
            }
          }
          break;

        case 'rename_table':
          $newName = (string) ($action['to'] ?? $action['new'] ?? $action['name'] ?? '');
          $oldName = (string) ($action['from'] ?? $table);
          if ('' === $newName || '' === $oldName) {
            throw new InvalidArgumentException('rename_table benötigt "to".');
          }
          if ('sqlsrv' === $dialect) {
            $statements[] = "EXEC sp_rename '".$schema.'.'.$oldName."', '".$newName."'";
          } else {
            $statements[] = 'RENAME TABLE `'.$oldName.'` TO `'.$newName.'`';
          }
          $table    = $newName;
          $tableRef = 'sqlsrv' === $dialect ? '['.$schema.'].['.$table.']' : '`'.$table.'`';
          break;

        case 'add_index':
          $indexDef = $action['index'] ?? null;
          if (null === $indexDef) {
            $indexDef = $action;
            unset($indexDef['action'], $indexDef['type']);
          }
          if ( ! \is_array($indexDef)) {
            throw new InvalidArgumentException('add_index benötigt Definition.');
          }
          if ('sqlsrv' === $dialect) {
            $columns = $indexDef['columns'] ?? $indexDef;
            if ( ! \is_array($columns) || [] === $columns) {
              throw new InvalidArgumentException('add_index benötigt "columns".');
            }
            $colsSql   = self::quoteColumnList($columns, $dialect);
            $indexName = $indexDef['name'] ?? ('idx_'.md5($colsSql));
            $statements[] = 'CREATE INDEX ['.$indexName.'] ON '.$tableRef.' ('.$colsSql.')';
          } else {
            $statements[] = 'ALTER TABLE '.$tableRef.' ADD '.self::buildIndex($indexDef, $dialect);
          }
          break;

        case 'drop_index':
          $name = (string) ($action['name'] ?? '');
          if ('' === $name) {
            $columns = $action['columns'] ?? $action['column'] ?? null;
            if (null !== $columns && ! \is_array($columns)) {
              $columns = [$columns];
            }
            $columns = \is_array($columns)
              ? array_values(array_filter($columns, static fn ($col) => \is_string($col) && '' !== trim($col)))
              : [];

            if ('sqlsrv' === $dialect && [] !== $columns) {
              $schemaName = str_replace("'", "''", $schema);
              $tableName  = str_replace("'", "''", $table);
              $colList    = implode("','", array_map(static fn ($col) => str_replace("'", "''", $col), $columns));
              $colCount   = \count($columns);

              $statements[] = "DECLARE @sql nvarchar(max) = N'';\n"
                ."SELECT @sql = @sql + N'DROP INDEX [' + i.name + N'] ON ".$tableRef.";' \n"
                ."FROM sys.indexes i\n"
                ."JOIN sys.tables t ON i.object_id = t.object_id\n"
                ."JOIN sys.schemas s ON t.schema_id = s.schema_id\n"
                ."JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id\n"
                ."JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id\n"
                ."WHERE t.name = '".$tableName."' AND s.name = '".$schemaName."' AND i.is_primary_key = 0 AND i.is_unique_constraint = 0\n"
                ."GROUP BY i.name, i.object_id\n"
                ."HAVING COUNT(*) = ".$colCount." AND SUM(CASE WHEN c.name IN ('".$colList."') THEN 1 ELSE 0 END) = ".$colCount.";\n"
                ."IF (@sql <> N'') EXEC sp_executesql @sql;";
            } else {
              throw new InvalidArgumentException('drop_index benötigt "name" oder "columns".');
            }
          } elseif ('sqlsrv' === $dialect) {
            $statements[] = 'DROP INDEX ['.$name.'] ON '.$tableRef;
          } else {
            $statements[] = 'ALTER TABLE '.$tableRef.' DROP INDEX `'.$name.'`';
          }
          break;

        case 'add_unique':
          $uniqueDef = $action['unique'] ?? null;
          if (null === $uniqueDef) {
            $uniqueDef = $action;
            unset($uniqueDef['action'], $uniqueDef['type']);
          }
          if ( ! \is_array($uniqueDef)) {
            throw new InvalidArgumentException('add_unique benötigt Definition.');
          }
          $statements[] = 'ALTER TABLE '.$tableRef.' ADD '.self::buildUnique($uniqueDef, $dialect);
          break;

        case 'drop_unique':
          $name = (string) ($action['name'] ?? '');
          if ('' === $name) {
            $columns = $action['columns'] ?? $action['column'] ?? null;
            if (null !== $columns && ! \is_array($columns)) {
              $columns = [$columns];
            }
            $columns = \is_array($columns)
              ? array_values(array_filter($columns, static fn ($col) => \is_string($col) && '' !== trim($col)))
              : [];

            if ('sqlsrv' === $dialect && [] !== $columns) {
              $schemaName = str_replace("'", "''", $schema);
              $tableName  = str_replace("'", "''", $table);
              $colList    = implode("','", array_map(static fn ($col) => str_replace("'", "''", $col), $columns));
              $colCount   = \count($columns);

              $statements[] = "DECLARE @sql nvarchar(max) = N'';\n"
                ."SELECT @sql = @sql + N'ALTER TABLE ".$tableRef." DROP CONSTRAINT [' + kc.name + N'];' \n"
                ."FROM sys.key_constraints kc\n"
                ."JOIN sys.tables t ON kc.parent_object_id = t.object_id\n"
                ."JOIN sys.schemas s ON t.schema_id = s.schema_id\n"
                ."JOIN sys.index_columns ic ON kc.parent_object_id = ic.object_id AND kc.unique_index_id = ic.index_id\n"
                ."JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id\n"
                ."WHERE t.name = '".$tableName."' AND s.name = '".$schemaName."' AND kc.[type] = 'UQ'\n"
                ."GROUP BY kc.name, kc.parent_object_id\n"
                ."HAVING COUNT(*) = ".$colCount." AND SUM(CASE WHEN c.name IN ('".$colList."') THEN 1 ELSE 0 END) = ".$colCount.";\n"
                ."SELECT @sql = @sql + N'DROP INDEX [' + i.name + N'] ON ".$tableRef.";' \n"
                ."FROM sys.indexes i\n"
                ."JOIN sys.tables t ON i.object_id = t.object_id\n"
                ."JOIN sys.schemas s ON t.schema_id = s.schema_id\n"
                ."JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id\n"
                ."JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id\n"
                ."WHERE t.name = '".$tableName."' AND s.name = '".$schemaName."' AND i.is_unique = 1 AND i.is_primary_key = 0 AND i.is_unique_constraint = 0\n"
                ."GROUP BY i.name, i.object_id\n"
                ."HAVING COUNT(*) = ".$colCount." AND SUM(CASE WHEN c.name IN ('".$colList."') THEN 1 ELSE 0 END) = ".$colCount.";\n"
                ."IF (@sql <> N'') EXEC sp_executesql @sql;";
            } else {
              throw new InvalidArgumentException('drop_unique benötigt "name" oder "columns".');
            }
          } elseif ('sqlsrv' === $dialect) {
            $statements[] = 'ALTER TABLE '.$tableRef.' DROP CONSTRAINT ['.$name.']';
          } else {
            $statements[] = 'ALTER TABLE '.$tableRef.' DROP INDEX `'.$name.'`';
          }
          break;

        case 'drop_default':
          $name = (string) ($action['name'] ?? '');
          if ('' === $name) {
            $columns = $action['columns'] ?? $action['column'] ?? null;
            if (null !== $columns && ! \is_array($columns)) {
              $columns = [$columns];
            }
            $columns = \is_array($columns)
              ? array_values(array_filter($columns, static fn ($col) => \is_string($col) && '' !== trim($col)))
              : [];

            if ('sqlsrv' === $dialect && [] !== $columns) {
              $schemaName = str_replace("'", "''", $schema);
              $tableName  = str_replace("'", "''", $table);
              $colList    = implode("','", array_map(static fn ($col) => str_replace("'", "''", $col), $columns));

              $statements[] = "DECLARE @sql nvarchar(max) = N'';\n"
                ."SELECT @sql = @sql + N'ALTER TABLE ".$tableRef." DROP CONSTRAINT [' + dc.name + N'];' \n"
                ."FROM sys.default_constraints dc\n"
                ."JOIN sys.tables t ON dc.parent_object_id = t.object_id\n"
                ."JOIN sys.schemas s ON t.schema_id = s.schema_id\n"
                ."JOIN sys.columns c ON dc.parent_object_id = c.object_id AND dc.parent_column_id = c.column_id\n"
                ."WHERE t.name = '".$tableName."' AND s.name = '".$schemaName."' AND c.name IN ('".$colList."');\n"
                ."IF (@sql <> N'') EXEC sp_executesql @sql;";
            } elseif ([] !== $columns) {
              foreach ($columns as $column) {
                $statements[] = 'ALTER TABLE '.$tableRef.' ALTER COLUMN `'.$column.'` DROP DEFAULT';
              }
            } else {
              throw new InvalidArgumentException('drop_default benötigt "name" oder "columns".');
            }
          } elseif ('sqlsrv' === $dialect) {
            $statements[] = 'ALTER TABLE '.$tableRef.' DROP CONSTRAINT ['.$name.']';
          } else {
            $statements[] = 'ALTER TABLE '.$tableRef.' ALTER COLUMN `'.$name.'` DROP DEFAULT';
          }
          break;

        case 'add_check':
          $checkDef = $action['check'] ?? null;
          if (null === $checkDef) {
            $checkDef = $action;
            unset($checkDef['action'], $checkDef['type']);
          }
          if ( ! \is_array($checkDef)) {
            throw new InvalidArgumentException('add_check benötigt Definition.');
          }
          $statements[] = 'ALTER TABLE '.$tableRef.' ADD '.self::buildCheck($checkDef, $dialect);
          break;

        case 'drop_check':
          $name = (string) ($action['name'] ?? '');
          if ('' === $name) {
            throw new InvalidArgumentException('drop_check benötigt "name".');
          }
          if ('mysql' === $dialect && ! self::isMySqlCheckSupported($context)) {
            break;
          }
          if ('sqlsrv' === $dialect) {
            $statements[] = 'ALTER TABLE '.$tableRef.' DROP CONSTRAINT ['.$name.']';
          } else {
            $statements[] = 'ALTER TABLE '.$tableRef.' DROP CHECK `'.$name.'`';
          }
          break;

        case 'add_primary_key':
          $columns = $action['columns'] ?? null;
          if ( ! \is_array($columns) || [] === $columns) {
            throw new InvalidArgumentException('add_primary_key benötigt "columns".');
          }
          $colsSql = self::quoteColumnList($columns, $dialect);
          $name    = $action['name'] ?? null;
          if ($name) {
            $constraint = 'sqlsrv' === $dialect ? '['.$name.']' : '`'.$name.'`';
            $statements[] = 'ALTER TABLE '.$tableRef.' ADD CONSTRAINT '.$constraint.' PRIMARY KEY ('.$colsSql.')';
          } else {
            $statements[] = 'ALTER TABLE '.$tableRef.' ADD PRIMARY KEY ('.$colsSql.')';
          }
          break;

        case 'drop_primary_key':
          $name = $action['name'] ?? null;
          if ('sqlsrv' === $dialect) {
            if ( ! \is_string($name) || '' === $name) {
              throw new InvalidArgumentException('drop_primary_key benötigt "name" für sqlsrv.');
            }
            $statements[] = 'ALTER TABLE '.$tableRef.' DROP CONSTRAINT ['.$name.']';
          } else {
            $statements[] = 'ALTER TABLE '.$tableRef.' DROP PRIMARY KEY';
          }
          break;

        case 'add_foreign_key':
          $fkDef = $action['foreignKey'] ?? null;
          if (null === $fkDef) {
            $fkDef = $action;
            unset($fkDef['action'], $fkDef['type']);
          }
          if ( ! \is_array($fkDef)) {
            throw new InvalidArgumentException('add_foreign_key benötigt Definition.');
          }
          $statements[] = 'ALTER TABLE '.$tableRef.' ADD '.self::buildForeignKey($fkDef, $dialect, $schema);
          break;

        case 'drop_foreign_key':
          $name = (string) ($action['name'] ?? '');
          if ('' === $name) {
            $columns = $action['columns'] ?? $action['column'] ?? null;
            if (null !== $columns && ! \is_array($columns)) {
              $columns = [$columns];
            }
            $columns = \is_array($columns)
              ? array_values(array_filter($columns, static fn ($col) => \is_string($col) && '' !== trim($col)))
              : [];

            $refColumns = $action['refColumns'] ?? $action['ref_columns'] ?? null;
            if (null !== $refColumns && ! \is_array($refColumns)) {
              $refColumns = [$refColumns];
            }
            $refColumns = \is_array($refColumns)
              ? array_values(array_filter($refColumns, static fn ($col) => \is_string($col) && '' !== trim($col)))
              : [];

            $refTable = $action['refTable'] ?? $action['ref_table'] ?? null;
            $refTable = \is_string($refTable) ? trim($refTable) : null;
            if ('' === $refTable) {
              $refTable = null;
            }
            $refSchema = $action['refSchema'] ?? $action['ref_schema'] ?? null;
            $refSchema = \is_string($refSchema) ? trim($refSchema) : null;
            if ('' === $refSchema) {
              $refSchema = null;
            }

            if ('sqlsrv' === $dialect && ([] !== $columns || [] !== $refColumns || null !== $refTable)) {
              if ([] !== $columns && [] !== $refColumns && \count($columns) !== \count($refColumns)) {
                throw new InvalidArgumentException('drop_foreign_key erwartet gleich viele columns und refColumns.');
              }

              $schemaName = str_replace("'", "''", $schema);
              $tableName  = str_replace("'", "''", $table);
              $conditions = [
                "s.name = '".$schemaName."'",
                "t.name = '".$tableName."'"
              ];

              if (null !== $refTable) {
                $refSchemaName = str_replace("'", "''", $refSchema ?? $schema);
                $refTableName  = str_replace("'", "''", $refTable);
                $conditions[] = "rs.name = '".$refSchemaName."' AND rt.name = '".$refTableName."'";
              }

              $havingParts = [];
              if ([] !== $columns) {
                $colList  = implode("','", array_map(static fn ($col) => str_replace("'", "''", $col), $columns));
                $colCount = \count($columns);
                $havingParts[] = "COUNT(*) = ".$colCount." AND SUM(CASE WHEN c.name IN ('".$colList."') THEN 1 ELSE 0 END) = ".$colCount;
              }
              if ([] !== $refColumns) {
                $refColList  = implode("','", array_map(static fn ($col) => str_replace("'", "''", $col), $refColumns));
                $refColCount = \count($refColumns);
                $havingParts[] = "COUNT(*) = ".$refColCount." AND SUM(CASE WHEN rc.name IN ('".$refColList."') THEN 1 ELSE 0 END) = ".$refColCount;
              }

              $whereSql  = implode(' AND ', $conditions);
              $havingSql = [] !== $havingParts ? 'HAVING '.implode(' AND ', $havingParts) : '';

              $statements[] = "DECLARE @sql nvarchar(max) = N'';\n"
                ."SELECT @sql = @sql + N'ALTER TABLE ".$tableRef." DROP CONSTRAINT [' + fk.name + N'];' \n"
                ."FROM sys.foreign_keys fk\n"
                ."JOIN sys.tables t ON fk.parent_object_id = t.object_id\n"
                ."JOIN sys.schemas s ON t.schema_id = s.schema_id\n"
                ."JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id\n"
                ."JOIN sys.columns c ON fkc.parent_object_id = c.object_id AND fkc.parent_column_id = c.column_id\n"
                ."JOIN sys.tables rt ON fk.referenced_object_id = rt.object_id\n"
                ."JOIN sys.schemas rs ON rt.schema_id = rs.schema_id\n"
                ."JOIN sys.columns rc ON fkc.referenced_object_id = rc.object_id AND fkc.referenced_column_id = rc.column_id\n"
                ."WHERE ".$whereSql."\n"
                ."GROUP BY fk.name, fk.parent_object_id, fk.referenced_object_id\n"
                .('' !== $havingSql ? $havingSql."\n" : '')
                ."IF (@sql <> N'') EXEC sp_executesql @sql;";
            } elseif ('mysql' === $dialect && ([] !== $columns || [] !== $refColumns || null !== $refTable)) {
              if ([] !== $columns && [] !== $refColumns && \count($columns) !== \count($refColumns)) {
                throw new InvalidArgumentException('drop_foreign_key erwartet gleich viele columns und refColumns.');
              }

              $schemaName = $schema && 'dbo' !== $schema ? str_replace("'", "''", $schema) : null;
              $tableName  = str_replace("'", "''", $table);
              $conditions = [
                ($schemaName ? "TABLE_SCHEMA = '".$schemaName."'" : 'TABLE_SCHEMA = DATABASE()'),
                "TABLE_NAME = '".$tableName."'",
                "REFERENCED_TABLE_NAME IS NOT NULL"
              ];

              if (null !== $refTable) {
                $refSchemaName = $refSchema && 'dbo' !== $refSchema ? str_replace("'", "''", $refSchema) : null;
                $refTableName  = str_replace("'", "''", $refTable);
                $conditions[] = "REFERENCED_TABLE_NAME = '".$refTableName."'";
                $conditions[] = $refSchemaName ? "REFERENCED_TABLE_SCHEMA = '".$refSchemaName."'" : 'REFERENCED_TABLE_SCHEMA = DATABASE()';
              }

              $havingParts = [];
              if ([] !== $columns) {
                $colList  = implode("','", array_map(static fn ($col) => str_replace("'", "''", $col), $columns));
                $colCount = \count($columns);
                $havingParts[] = "COUNT(*) = ".$colCount." AND SUM(CASE WHEN COLUMN_NAME IN ('".$colList."') THEN 1 ELSE 0 END) = ".$colCount;
              }
              if ([] !== $refColumns) {
                $refColList  = implode("','", array_map(static fn ($col) => str_replace("'", "''", $col), $refColumns));
                $refColCount = \count($refColumns);
                $havingParts[] = "COUNT(*) = ".$refColCount." AND SUM(CASE WHEN REFERENCED_COLUMN_NAME IN ('".$refColList."') THEN 1 ELSE 0 END) = ".$refColCount;
              }

              $whereSql  = implode(' AND ', $conditions);
              $havingSql = [] !== $havingParts ? 'HAVING '.implode(' AND ', $havingParts) : '';

              $statements[] = "SET @sql := (\n"
                ."SELECT GROUP_CONCAT(CONCAT('ALTER TABLE `".$tableName."` DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR '; ')\n"
                ."FROM (\n"
                ."  SELECT CONSTRAINT_NAME\n"
                ."  FROM information_schema.KEY_COLUMN_USAGE\n"
                ."  WHERE ".$whereSql."\n"
                ."  GROUP BY CONSTRAINT_NAME\n"
                .('' !== $havingSql ? '  '.$havingSql."\n" : '')
                .") AS _fk\n"
                .")";
              $statements[] = "SET @sql := IFNULL(@sql, '')";
              $statements[] = "SET @sql := IF(@sql = '', 'SELECT 1', @sql)";
              $statements[] = "PREPARE stmt FROM @sql";
              $statements[] = "EXECUTE stmt";
              $statements[] = "DEALLOCATE PREPARE stmt";
            } else {
              throw new InvalidArgumentException('drop_foreign_key benötigt "name" oder filter (columns/refTable).');
            }
          } elseif ('sqlsrv' === $dialect) {
            $statements[] = 'ALTER TABLE '.$tableRef.' DROP CONSTRAINT ['.$name.']';
          } else {
            $statements[] = 'ALTER TABLE '.$tableRef.' DROP FOREIGN KEY `'.$name.'`';
          }
          break;

        default:
          throw new InvalidArgumentException('alter_table Action unbekannt: '.$actionType);
      }
    }

    if (1 === \count($statements)) {
      return $statements[0];
    }

    return $statements;
  }

  private static function buildInsert(array $definition, string $dialect, array $context): string
  {
    $table = (string) ($definition['table'] ?? '');
    if ('' === $table) {
      throw new InvalidArgumentException('insert benötigt "table".');
    }

    $schema  = (string) ($definition['schema'] ?? 'dbo');
    $columns = $definition['columns'] ?? $definition['column'] ?? null;
    $values  = $definition['values'] ?? $definition['rows'] ?? null;
    $select  = $definition['select'] ?? $definition['query'] ?? null;

    if (null !== $values && null !== $select) {
      throw new InvalidArgumentException('insert darf nicht gleichzeitig "values" und "select/query" enthalten.');
    }
    if (null === $values && null === $select) {
      throw new InvalidArgumentException('insert benötigt "values" oder "select/query".');
    }

    if (null !== $columns && ! \is_array($columns)) {
      throw new InvalidArgumentException('insert "columns" muss array sein.');
    }
    if (null !== $columns && ! self::isList($columns)) {
      throw new InvalidArgumentException('insert "columns" muss Liste sein.');
    }

    $tableRef = 'sqlsrv' === $dialect ? '['.$schema.'].['.$table.']' : '`'.$table.'`';

    if (null !== $values) {
      if ( ! \is_array($values)) {
        throw new InvalidArgumentException('insert "values" muss array sein.');
      }

      if ( ! self::isList($values)) {
        $values = [$values];
      } elseif ([] !== $values && ! \is_array($values[0]) && ! \is_object($values[0])) {
        $values = [$values];
      }

      if ([] === $values) {
        throw new InvalidArgumentException('insert "values" darf nicht leer sein.');
      }

      [$columnsSql, $rows] = self::normalizeInsertRows($columns, $values, $context);

      $sql = 'INSERT INTO '.$tableRef;
      if ('' !== $columnsSql) {
        $sql .= ' ('.$columnsSql.')';
      }

      $rowSql = [];
      foreach ($rows as $row) {
        $valuesSql = [];
        foreach ($row as $value) {
          $valuesSql[] = self::formatDmlValue($value, $dialect, $context);
        }
        $rowSql[] = '('.implode(', ', $valuesSql).')';
      }

      if (1 === \count($rowSql)) {
        return $sql.' VALUES '.$rowSql[0];
      }

      return $sql." VALUES\n  ".implode(",\n  ", $rowSql);
    }

    $sql = 'INSERT INTO '.$tableRef;
    if (null !== $columns && [] !== $columns) {
      $sql .= ' ('.self::quoteColumnList($columns, $dialect).')';
    }

    $selectSql = SelectBuilder::build($select, $context);

    return $sql.' '.$selectSql;
  }

  private static function normalizeInsertRows(?array $columns, array $values, array $context): array
  {
    $dialect = (string) ($context['dialect'] ?? 'mysql');
    $columnList = $columns ?? [];
    $rows = [];

    foreach ($values as $idx => $row) {
      if (\is_object($row)) {
        $row = self::objectToArray($row);
      }
      if ( ! \is_array($row)) {
        throw new InvalidArgumentException('insert values['.$idx.'] muss array/object sein.');
      }

      if (self::isList($row)) {
        if ([] === $columnList) {
          throw new InvalidArgumentException('insert values als Liste benötigen "columns".');
        }
        if (\count($row) !== \count($columnList)) {
          throw new InvalidArgumentException('insert values['.$idx.'] hat '.\count($row).' Werte, erwartet werden '.\count($columnList).'.');
        }
        $rows[] = $row;
        continue;
      }

      if ([] === $columnList) {
        $columnList = array_keys($row);
      }

      foreach ($columnList as $colName) {
        if ( ! \array_key_exists((string) $colName, $row)) {
          throw new InvalidArgumentException('insert values['.$idx.'] fehlt Spalte "'.$colName.'".');
        }
      }
      if (\count($row) !== \count($columnList)) {
        $extra = array_diff(array_keys($row), $columnList);
        if ([] !== $extra) {
          throw new InvalidArgumentException('insert values['.$idx.'] enthält unbekannte Spalten: '.implode(', ', $extra));
        }
      }

      $ordered = [];
      foreach ($columnList as $colName) {
        $ordered[] = $row[(string) $colName];
      }
      $rows[] = $ordered;
    }

    $columnsSql = [] !== $columnList ? self::quoteColumnList(array_map('strval', $columnList), $dialect) : '';

    return [$columnsSql, $rows];
  }

  private static function formatDmlValue(mixed $value, string $dialect, array $context): string
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
    if (\is_string($value)) {
      return "'".str_replace("'", "''", $value)."'";
    }

    if ($value instanceof stdClass) {
      $value = (array) $value;
    }

    if (\is_array($value)) {
      foreach (['value', 'val', 'literal', 'lit'] as $key) {
        if (\array_key_exists($key, $value)) {
          return self::formatDmlValue($value[$key], $dialect, $context);
        }
      }

      if (isset($value['default']) && true === $value['default']) {
        return 'DEFAULT';
      }

      if (isset($value['raw'])) {
        $raw = trim((string) $value['raw']);
        if ('' === $raw) {
          throw new InvalidArgumentException('raw darf nicht leer sein.');
        }

        return $raw;
      }

      if (isset($value['query'])) {
        $sql = SelectBuilder::build($value['query'], $context);

        return '('.$sql.')';
      }

      $looksLikeQuery = false;
      foreach (array_keys($value) as $k) {
        $k = mb_strtolower((string) $k);
        $k = str_replace(['_', '-'], '', $k);
        if (\in_array($k, ['select', 'from', 'where', 'groupby', 'orderby', 'join', 'leftjoin', 'rightjoin', 'limit', 'offset', 'distinct', 'union', 'unionall'], true)) {
          $looksLikeQuery = true;
          break;
        }
      }

      if ($looksLikeQuery) {
        $sql = SelectBuilder::build($value, $context);

        return '('.$sql.')';
      }

      $sql = SelectBuilder::expr($value, $context);

      return $sql;
    }

    throw new InvalidArgumentException('Ungültiger Wert.');
  }

  private static function buildUpdate(array $definition, string $dialect, array $context): string
  {
    $table = (string) ($definition['table'] ?? '');
    if ('' === $table) {
      throw new InvalidArgumentException('update benötigt "table".');
    }

    $schema = (string) ($definition['schema'] ?? 'dbo');
    $alias  = $definition['as'] ?? $definition['alias'] ?? null;
    if (null !== $alias && ! \is_string($alias)) {
      throw new InvalidArgumentException('update "as/alias" muss string sein.');
    }
    $alias = \is_string($alias) ? trim($alias) : null;
    if ('' === $alias) {
      $alias = null;
    }

    $set = $definition['set'] ?? null;
    if (null === $set) {
      throw new InvalidArgumentException('update benötigt "set".');
    }

    $assignments = self::normalizeUpdateSet($set);
    if ([] === $assignments) {
      throw new InvalidArgumentException('update "set" darf nicht leer sein.');
    }

    $setParts = [];
    foreach ($assignments as $assignment) {
      [$col, $val] = $assignment;
      if ('sqlsrv' === $dialect && false !== strpos($col, '.')) {
        $qualifier = trim((string) explode('.', $col, 2)[0]);
        $target    = $alias ?? $table;
        if ('' !== $qualifier && $qualifier !== $target) {
          throw new InvalidArgumentException('sqlsrv update unterstützt nur Spalten der Zieltabelle ('.$target.').');
        }
      }
      $setParts[]  = self::quoteIdentifierPath($col, $dialect).' = '.self::formatDmlValue($val, $dialect, $context);
    }

    $joins = self::normalizeUpdateJoins($definition, $dialect, $context, $schema);
    $joinSql = '';
    if ([] !== $joins) {
      $parts = [];
      foreach ($joins as $join) {
        $parts[] = self::renderUpdateJoin($join, $dialect, $context, $schema);
      }
      $joinSql = implode(' ', $parts);
    }

    $where = $definition['where'] ?? null;
    if (null !== $where && \is_object($where)) {
      $where = self::objectToArray($where);
    }

    $whereSql = null;
    if (null !== $where) {
      $whereSql = self::buildUpdateWhere($where, $context);
    }

    $limit = $definition['limit'] ?? null;
    $limit = is_numeric($limit) ? (int) $limit : null;
    if (null !== $limit && $limit < 0) {
      $limit = null;
    }

    $tableRef = 'sqlsrv' === $dialect ? '['.$schema.'].['.$table.']' : self::quoteIdentifierPath($table, $dialect);
    $aliasSql = $alias ? self::quoteColumn($alias, $dialect) : null;

    if ('sqlsrv' === $dialect) {
      $sql = 'UPDATE';
      if (null !== $limit) {
        $sql .= ' TOP ('.$limit.')';
      }
      $sql .= ' '.($aliasSql ?? $tableRef).' SET '.implode(', ', $setParts);
      if ($aliasSql || '' !== $joinSql) {
        $sql .= ' FROM '.$tableRef;
        if ($aliasSql) {
          $sql .= ' AS '.$aliasSql;
        }
        if ('' !== $joinSql) {
          $sql .= ' '.$joinSql;
        }
      }
      if (\is_string($whereSql) && '' !== $whereSql) {
        $sql .= ' WHERE '.$whereSql;
      }

      return $sql;
    }

    $sql = 'UPDATE '.$tableRef;
    if ($aliasSql) {
      $sql .= ' AS '.$aliasSql;
    }
    if ('' !== $joinSql) {
      $sql .= ' '.$joinSql;
    }
    $sql .= ' SET '.implode(', ', $setParts);
    if (\is_string($whereSql) && '' !== $whereSql) {
      $sql .= ' WHERE '.$whereSql;
    }
    if (null !== $limit) {
      $sql .= ' LIMIT '.$limit;
    }

    return $sql;
  }

  private static function buildDelete(array $definition, string $dialect, array $context): string
  {
    $table = (string) ($definition['table'] ?? '');
    if ('' === $table) {
      throw new InvalidArgumentException('delete benötigt "table".');
    }

    $schema = (string) ($definition['schema'] ?? 'dbo');
    $alias  = $definition['as'] ?? $definition['alias'] ?? null;
    if (null !== $alias && ! \is_string($alias)) {
      throw new InvalidArgumentException('delete "as/alias" muss string sein.');
    }
    $alias = \is_string($alias) ? trim($alias) : null;
    if ('' === $alias) {
      $alias = null;
    }

    $joins = self::normalizeUpdateJoins($definition, $dialect, $context, $schema);
    $joinSql = '';
    if ([] !== $joins) {
      $parts = [];
      foreach ($joins as $join) {
        $parts[] = self::renderUpdateJoin($join, $dialect, $context, $schema);
      }
      $joinSql = implode(' ', $parts);
    }

    $where = $definition['where'] ?? null;
    if (null !== $where && \is_object($where)) {
      $where = self::objectToArray($where);
    }

    $whereSql = null;
    if (null !== $where) {
      $whereSql = self::buildUpdateWhere($where, $context);
    }

    $limit = $definition['limit'] ?? null;
    $limit = is_numeric($limit) ? (int) $limit : null;
    if (null !== $limit && $limit < 0) {
      $limit = null;
    }

    $tableRef = 'sqlsrv' === $dialect ? '['.$schema.'].['.$table.']' : self::quoteIdentifierPath($table, $dialect);
    $aliasSql = $alias ? self::quoteColumn($alias, $dialect) : null;

    if ('sqlsrv' === $dialect) {
      $sql = 'DELETE';
      if (null !== $limit) {
        $sql .= ' TOP ('.$limit.')';
      }
      $sql .= ' '.($aliasSql ?? $tableRef);
      if ($aliasSql || '' !== $joinSql) {
        $sql .= ' FROM '.$tableRef;
        if ($aliasSql) {
          $sql .= ' AS '.$aliasSql;
        }
        if ('' !== $joinSql) {
          $sql .= ' '.$joinSql;
        }
      }
      if (\is_string($whereSql) && '' !== $whereSql) {
        $sql .= ' WHERE '.$whereSql;
      }

      return $sql;
    }

    if ($aliasSql || '' !== $joinSql) {
      $sql = 'DELETE '.($aliasSql ?? $tableRef).' FROM '.$tableRef;
      if ($aliasSql) {
        $sql .= ' AS '.$aliasSql;
      }
      if ('' !== $joinSql) {
        $sql .= ' '.$joinSql;
      }
    } else {
      $sql = 'DELETE FROM '.$tableRef;
    }
    if (\is_string($whereSql) && '' !== $whereSql) {
      $sql .= ' WHERE '.$whereSql;
    }
    if (null !== $limit) {
      $sql .= ' LIMIT '.$limit;
    }

    return $sql;
  }

  private static function normalizeUpdateSet(mixed $set): array
  {
    if (\is_object($set)) {
      $set = self::objectToArray($set);
    }
    if ( ! \is_array($set)) {
      throw new InvalidArgumentException('update "set" muss array/object sein.');
    }

    $result = [];

    if (self::isList($set)) {
      foreach ($set as $idx => $item) {
        if (\is_object($item)) {
          $item = self::objectToArray($item);
        }

        if (\is_array($item) && self::isList($item)) {
          if (\count($item) < 2) {
            throw new InvalidArgumentException('update set['.$idx.'] benötigt [column, value].');
          }
          $col = (string) $item[0];
          $val = $item[1];
          if ('' === $col) {
            throw new InvalidArgumentException('update set['.$idx.'] Spaltenname fehlt.');
          }
          $result[] = [$col, $val];
          continue;
        }

        if (\is_array($item) && ! self::isList($item)) {
          $col = (string) ($item['column'] ?? $item['col'] ?? $item['name'] ?? '');
          if ('' === $col) {
            throw new InvalidArgumentException('update set['.$idx.'] benötigt "col".');
          }
          if (\array_key_exists('value', $item)) {
            $val = $item['value'];
          } elseif (\array_key_exists('val', $item)) {
            $val = $item['val'];
          } elseif (\array_key_exists('expr', $item)) {
            $val = $item['expr'];
          } elseif (\array_key_exists('raw', $item)) {
            $val = ['raw' => $item['raw']];
          } else {
            throw new InvalidArgumentException('update set['.$idx.'] benötigt "value".');
          }

          $result[] = [$col, $val];
          continue;
        }

        throw new InvalidArgumentException('update set['.$idx.'] hat ein ungültiges Format.');
      }

      return $result;
    }

    foreach ($set as $col => $val) {
      $col = (string) $col;
      if ('' === $col) {
        throw new InvalidArgumentException('update set enthält leeren Spaltennamen.');
      }
      $result[] = [$col, $val];
    }

    return $result;
  }

  private static function buildUpdateWhere(mixed $where, array $context): string
  {
    if (\is_string($where)) {
      return trim($where);
    }

    if (\is_object($where)) {
      $where = self::objectToArray($where);
    }

    if (\is_array($where) && ! self::isList($where)) {
      $normalized = [];
      foreach ($where as $key => $value) {
        $normalized[mb_strtolower((string) $key)] = $value;
      }

      $operatorKeys = ['and', 'or', 'not', 'exists', 'not_exists', 'is_null', 'is_not_null', 'between'];
      foreach ($operatorKeys as $key) {
        if (\array_key_exists($key, $normalized)) {
          return SelectBuilder::condition($where, $context);
        }
      }

      $conditions = [];
      foreach ($where as $col => $val) {
        if (null === $val) {
          $conditions[] = ['is_null' => (string) $col];
          continue;
        }
        if (\is_string($val)) {
          $val = ['value' => $val];
        }
        $conditions[] = [(string) $col, '=', $val];
      }

      return SelectBuilder::condition($conditions, $context);
    }

    return SelectBuilder::condition($where, $context);
  }

  private static function buildSqlSrvColumnComment(string $schema, string $table, string $column, string $comment): string
  {
    $schema = trim($schema);
    if ('' === $schema) {
      $schema = 'dbo';
    }

    $table  = trim($table);
    $column = trim($column);
    if ('' === $table || '' === $column) {
      throw new InvalidArgumentException('sqlsrv column comment benötigt table und column.');
    }

    $tableRef   = '['.$schema.'].['.$table.']';
    $tableRefLit = str_replace("'", "''", $tableRef);
    $schemaLit  = str_replace("'", "''", $schema);
    $tableLit   = str_replace("'", "''", $table);
    $columnLit  = str_replace("'", "''", $column);
    $commentLit = str_replace("'", "''", $comment);

    $sql   = [];
    $sql[] = 'IF EXISTS (';
    $sql[] = '  SELECT 1';
    $sql[] = '  FROM sys.extended_properties ep';
    $sql[] = "  WHERE ep.major_id = OBJECT_ID(N'".$tableRefLit."')";
    $sql[] = "    AND ep.minor_id = COLUMNPROPERTY(OBJECT_ID(N'".$tableRefLit."'), N'".$columnLit."', 'ColumnId')";
    $sql[] = "    AND ep.name = N'MS_Description'";
    $sql[] = ')';
    $sql[] = "  EXEC sys.sp_updateextendedproperty @name=N'MS_Description', @value=N'".$commentLit."',";
    $sql[] = "    @level0type=N'SCHEMA', @level0name=N'".$schemaLit."',";
    $sql[] = "    @level1type=N'TABLE',  @level1name=N'".$tableLit."',";
    $sql[] = "    @level2type=N'COLUMN', @level2name=N'".$columnLit."'";
    $sql[] = 'ELSE';
    $sql[] = "  EXEC sys.sp_addextendedproperty @name=N'MS_Description', @value=N'".$commentLit."',";
    $sql[] = "    @level0type=N'SCHEMA', @level0name=N'".$schemaLit."',";
    $sql[] = "    @level1type=N'TABLE',  @level1name=N'".$tableLit."',";
    $sql[] = "    @level2type=N'COLUMN', @level2name=N'".$columnLit."'";

    return implode("\n", $sql);
  }

  private static function normalizeUpdateJoins(array $definition, string $dialect, array $context, string $defaultSchema): array
  {
    $joins = [];

    foreach (['join', 'inner_join', 'innerJoin'] as $key) {
      if (isset($definition[$key])) {
        $joins = array_merge($joins, self::normalizeUpdateJoinList($definition[$key], 'INNER', $dialect, $context, $defaultSchema));
      }
    }
    foreach (['left_join', 'leftJoin'] as $key) {
      if (isset($definition[$key])) {
        $joins = array_merge($joins, self::normalizeUpdateJoinList($definition[$key], 'LEFT', $dialect, $context, $defaultSchema));
      }
    }
    foreach (['right_join', 'rightJoin'] as $key) {
      if (isset($definition[$key])) {
        $joins = array_merge($joins, self::normalizeUpdateJoinList($definition[$key], 'RIGHT', $dialect, $context, $defaultSchema));
      }
    }

    return $joins;
  }

  private static function normalizeUpdateJoinList(mixed $joins, string $type, string $dialect, array $context, string $defaultSchema): array
  {
    if (\is_object($joins)) {
      $joins = self::objectToArray($joins);
    }

    if (\is_string($joins)) {
      return [self::normalizeUpdateJoin($joins, $type, $dialect, $context, $defaultSchema)];
    }

    if ( ! \is_array($joins)) {
      throw new InvalidArgumentException('update join muss string, object oder array sein.');
    }

    if ( ! self::isList($joins)) {
      return [self::normalizeUpdateJoin($joins, $type, $dialect, $context, $defaultSchema)];
    }

    $result = [];
    foreach ($joins as $idx => $join) {
      $result[] = self::normalizeUpdateJoin($join, $type, $dialect, $context, $defaultSchema, $idx);
    }

    return $result;
  }

  private static function normalizeUpdateJoin(
    mixed $join,
    string $type,
    string $dialect,
    array $context,
    string $defaultSchema,
    ?int $idx = null
  ): array {
    if (\is_object($join)) {
      $join = self::objectToArray($join);
    }

    if (\is_string($join)) {
      return [
        'type'  => $type,
        'table' => $join
      ];
    }

    if ( ! \is_array($join)) {
      throw new InvalidArgumentException('update join'.(null !== $idx ? '['.$idx.']' : '').' hat ein ungültiges Format.');
    }

    $table = $join['table'] ?? $join['name'] ?? null;
    $query = $join['query'] ?? null;

    if (null === $table && null === $query) {
      throw new InvalidArgumentException('update join'.(null !== $idx ? '['.$idx.']' : '').' benötigt "table" oder "query".');
    }

    $alias = $join['as'] ?? $join['alias'] ?? null;
    if (null !== $alias && ! \is_string($alias)) {
      throw new InvalidArgumentException('update join'.(null !== $idx ? '['.$idx.']' : '').' alias muss string sein.');
    }
    $alias = \is_string($alias) ? trim($alias) : null;
    if ('' === $alias) {
      $alias = null;
    }

    $schema = $join['schema'] ?? null;
    if (null !== $schema && ! \is_string($schema)) {
      throw new InvalidArgumentException('update join'.(null !== $idx ? '['.$idx.']' : '').' schema muss string sein.');
    }
    $schema = \is_string($schema) ? trim($schema) : null;
    if ('' === $schema) {
      $schema = null;
    }
    if (null === $schema && 'sqlsrv' === $dialect) {
      $schema = $defaultSchema;
    }

    return [
      'type'   => $type,
      'table'  => \is_string($table) ? (string) $table : null,
      'query'  => $query,
      'as'     => $alias,
      'on'     => $join['on'] ?? null,
      'using'  => $join['using'] ?? null,
      'schema' => $schema
    ];
  }

  private static function renderUpdateJoin(array $join, string $dialect, array $context, string $defaultSchema): string
  {
    $type = mb_strtoupper((string) ($join['type'] ?? 'INNER'));
    $alias = $join['as'] ?? null;
    $on = $join['on'] ?? null;
    $using = $join['using'] ?? null;

    $source = null;
    if (isset($join['query']) && null !== $join['query']) {
      $source = '('.SelectBuilder::build($join['query'], $context).')';
    } elseif (isset($join['table']) && \is_string($join['table']) && '' !== $join['table']) {
      $schema = $join['schema'] ?? null;
      $schema = \is_string($schema) && '' !== $schema ? $schema : ('sqlsrv' === $dialect ? $defaultSchema : null);
      if ('sqlsrv' === $dialect && null !== $schema && ! self::isRawIdentifier($join['table']) && false === str_contains($join['table'], '.')) {
        $source = '['.$schema.'].['.$join['table'].']';
      } else {
        $source = self::quoteIdentifierPath($join['table'], $dialect);
      }
    } else {
      throw new InvalidArgumentException('update join benötigt table oder query.');
    }

    if (\is_string($alias) && '' !== $alias) {
      $source .= ' AS '.self::quoteColumn($alias, $dialect);
    }

    $sql = $type.' JOIN '.$source;
    if (null !== $on) {
      $sql .= ' ON '.SelectBuilder::condition($on, $context);
    } elseif (null !== $using) {
      if ('sqlsrv' === $dialect) {
        throw new InvalidArgumentException('update join USING wird in sqlsrv nicht unterstützt, nutze ON.');
      }
      $cols = \is_array($using) ? $using : [$using];
      $sql .= ' USING ('.self::quoteColumnList($cols, $dialect).')';
    }

    return $sql;
  }

  private static function quoteIdentifierPath(string $name, string $dialect): string
  {
    $name = trim($name);
    if ('' === $name) {
      return $name;
    }

    if (self::isRawIdentifier($name)) {
      return $name;
    }

    $parts  = explode('.', $name);
    $quoted = [];
    foreach ($parts as $part) {
      $part = trim($part);
      if ('' === $part) {
        return $name;
      }
      if ('*' === $part) {
        $quoted[] = $part;
        continue;
      }
      $quoted[] = self::quoteColumn($part, $dialect);
    }

    return implode('.', $quoted);
  }

  private static function isRawIdentifier(string $name): bool
  {
    if (preg_match('/\\s|[()]/', $name)) {
      return true;
    }

    return false !== strpbrk($name, '`[]\"');
  }

  private static function buildColumn(array $column, string $dialect, string $table, array &$extraChecks): string
  {
    $name = (string) ($column['name'] ?? '');
    if ('' === $name) {
      throw new InvalidArgumentException('Spalte benötigt "name".');
    }

    $type          = $column['type'] ?? 'string';
    $length        = $column['length'] ?? null;
    $precision     = $column['precision'] ?? null;
    $scale         = $column['scale'] ?? null;
    $nullable      = \array_key_exists('nullable', $column) ? (bool) $column['nullable'] : true;
    $default       = $column['default'] ?? null;
    $onUpdate      = $column['onUpdate'] ?? null;
    $comment       = $column['comment'] ?? null;
    $autoIncrement = (bool) ($column['autoIncrement'] ?? $column['identity'] ?? false);
    $unsigned      = (bool) ($column['unsigned'] ?? false);
    $enum          = $column['enum'] ?? null;
    $raw           = $column[$dialect] ?? $column['raw'] ?? null;

    $sqlType = $raw ? (string) $raw : self::mapType($type, $dialect, $length, $precision, $scale, $unsigned, $enum, $table, $name, $extraChecks);

    $parts   = [];
    $parts[] = self::quoteColumn($name, $dialect).' '.$sqlType;

    if ($autoIncrement && 'mysql' === $dialect && ! str_contains(mb_strtoupper($sqlType), 'AUTO_INCREMENT')) {
      $parts[] = 'AUTO_INCREMENT';
    }
    if ($autoIncrement && 'sqlsrv' === $dialect && ! str_contains(mb_strtoupper($sqlType), 'IDENTITY')) {
      $parts[] = 'IDENTITY(1,1)';
    }

    if ( ! $nullable) {
      $parts[] = 'NOT NULL';
    } else {
      $parts[] = 'NULL';
    }

    if (\array_key_exists('default', $column)) {
      $parts[] = 'DEFAULT '.self::formatDefault($default, $dialect);
    }
    if ($onUpdate && 'mysql' === $dialect) {
      $parts[] = 'ON UPDATE '.self::formatDefault($onUpdate, $dialect);
    }
    if ('mysql' === $dialect && \is_string($comment) && '' !== trim($comment)) {
      $parts[] = "COMMENT '".str_replace("'", "''", trim($comment))."'";
    }

    return implode(' ', array_filter($parts));
  }

  private static function mapType(
    string $type,
    string $dialect,
    mixed $length,
    mixed $precision,
    mixed $scale,
    bool $unsigned,
    mixed $enum,
    string $table,
    string $column,
    array &$extraChecks
  ): string {
    $type = mb_strtolower((string) $type);

    if (\is_array($enum) && [] !== $enum) {
      $maxLen = max(array_map(static fn ($val) => mb_strlen((string) $val), $enum));
      if ('mysql' === $dialect) {
        $values = array_map(static fn ($val) => "'".str_replace("'", "''", (string) $val)."'", $enum);

        return 'ENUM('.implode(',', $values).')';
      }
      $values        = array_map(static fn ($val) => "'".str_replace("'", "''", (string) $val)."'", $enum);
      $extraChecks[] = 'CONSTRAINT [chk_'.$table.'_'.$column.'] CHECK (['.$column.'] IN ('.implode(', ', $values).'))';

      return 'NVARCHAR('.max(1, $maxLen).')';
    }

    $len  = is_numeric($length) ? (int) $length : null;
    $prec = is_numeric($precision) ? (int) $precision : null;
    $sc   = is_numeric($scale) ? (int) $scale : null;

    if ('int' === $type) {
      return 'INT'.($unsigned && 'mysql' === $dialect ? ' UNSIGNED' : '');
    }
    if ('tinyint' === $type) {
      $len    = $len ?? null;
      $suffix = 'mysql' === $dialect && $len ? '('.$len.')' : '';

      return 'TINYINT'.$suffix.($unsigned && 'mysql' === $dialect ? ' UNSIGNED' : '');
    }
    if ('smallint' === $type) {
      return 'SMALLINT'.($unsigned && 'mysql' === $dialect ? ' UNSIGNED' : '');
    }
    if ('bigint' === $type) {
      return 'BIGINT'.($unsigned && 'mysql' === $dialect ? ' UNSIGNED' : '');
    }
    if ('boolean' === $type) {
      return 'mysql' === $dialect ? 'TINYINT(1)' : 'BIT';
    }
    if ('text' === $type) {
      return 'mysql' === $dialect ? 'TEXT' : 'NVARCHAR(MAX)';
    }
    if ('longtext' === $type) {
      return 'mysql' === $dialect ? 'LONGTEXT' : 'NVARCHAR(MAX)';
    }
    if ('mediumtext' === $type) {
      return 'mysql' === $dialect ? 'MEDIUMTEXT' : 'NVARCHAR(MAX)';
    }
    if ('json' === $type) {
      return 'mysql' === $dialect ? 'JSON' : 'NVARCHAR(MAX)';
    }
    if ('tinyblob' === $type) {
      return 'mysql' === $dialect ? 'TINYBLOB' : 'VARBINARY(255)';
    }
    if ('blob' === $type) {
      return 'mysql' === $dialect ? 'BLOB' : 'VARBINARY(MAX)';
    }
    if ('mediumblob' === $type) {
      return 'mysql' === $dialect ? 'MEDIUMBLOB' : 'VARBINARY(MAX)';
    }
    if ('longblob' === $type) {
      return 'mysql' === $dialect ? 'LONGBLOB' : 'VARBINARY(MAX)';
    }
    if ('datetime' === $type) {
      return 'mysql' === $dialect ? 'DATETIME' : 'DATETIME2';
    }
    if ('timestamp' === $type) {
      return 'mysql' === $dialect ? 'TIMESTAMP' : 'DATETIME2';
    }
    if ('date' === $type) {
      return 'DATE';
    }
    if ('decimal' === $type) {
      $prec = $prec ?? 10;
      $sc   = $sc ?? 2;

      return 'DECIMAL('.$prec.','.$sc.')';
    }

    // string / varchar
    $len = $len ?? 255;

    return ('mysql' === $dialect ? 'VARCHAR' : 'NVARCHAR').'('.$len.')';
  }

  private static function buildUnique(array $uniqueDef, string $dialect): string
  {
    $name    = $uniqueDef['name'] ?? null;
    $columns = $uniqueDef['columns'] ?? $uniqueDef;
    $colsSql = self::quoteColumnList($columns, $dialect);

    if ('sqlsrv' === $dialect) {
      if ($name) {
        return 'CONSTRAINT ['.$name.'] UNIQUE ('.$colsSql.')';
      }

      return 'UNIQUE ('.$colsSql.')';
    }

    if ($name) {
      return 'UNIQUE KEY `'.$name.'` ('.$colsSql.')';
    }

    return 'UNIQUE ('.$colsSql.')';
  }

  private static function buildIndex(array $indexDef, string $dialect): string
  {
    $name    = $indexDef['name'] ?? null;
    $columns = $indexDef['columns'] ?? $indexDef;
    $colsSql = self::quoteColumnList($columns, $dialect);

    if ('sqlsrv' === $dialect) {
      if ( ! $name) {
        $name = 'idx_'.md5($colsSql);
      }

      return 'INDEX ['.$name.'] ('.$colsSql.')';
    }

    if ($name) {
      return 'KEY `'.$name.'` ('.$colsSql.')';
    }

    return 'KEY ('.$colsSql.')';
  }

  private static function buildForeignKey(array $fkDef, string $dialect, string $schema): string
  {
    $name       = $fkDef['name'] ?? null;
    $columns    = $fkDef['columns'] ?? [];
    $refTable   = $fkDef['refTable'] ?? '';
    $refColumns = $fkDef['refColumns'] ?? [];
    $onDelete   = self::normalizeForeignKeyAction($fkDef['onDelete'] ?? null, $dialect);
    $onUpdate   = self::normalizeForeignKeyAction($fkDef['onUpdate'] ?? null, $dialect);

    $sql = 'FOREIGN KEY ('.self::quoteColumnList($columns, $dialect).') ';
    if ($name) {
      $sql = ('sqlsrv' === $dialect ? 'CONSTRAINT ['.$name.'] ' : 'CONSTRAINT `'.$name.'` ').$sql;
    }
    $refTableSql = 'sqlsrv' === $dialect
      ? '['.$schema.'].['.$refTable.']'
      : '`'.$refTable.'`';
    $sql .= 'REFERENCES '.$refTableSql.' ('.self::quoteColumnList($refColumns, $dialect).')';
    if ($onDelete) {
      $sql .= ' ON DELETE '.$onDelete;
    }
    if ($onUpdate) {
      $sql .= ' ON UPDATE '.$onUpdate;
    }

    return $sql;
  }

  private static function normalizeForeignKeyAction(mixed $action, string $dialect): ?string
  {
    if ( ! \is_string($action)) {
      return null;
    }

    $normalized = mb_strtoupper(trim($action));
    if ('' === $normalized) {
      return null;
    }

    $normalized = str_replace(['_', '-'], ' ', $normalized);
    $normalized = preg_replace('/\\s+/', ' ', $normalized);

    if ('sqlsrv' === $dialect) {
      if ('RESTRICT' === $normalized) {
        return 'NO ACTION';
      }
      if ('NOACTION' === $normalized) {
        return 'NO ACTION';
      }
    }

    if ('SETNULL' === $normalized) {
      return 'SET NULL';
    }
    if ('SETDEFAULT' === $normalized) {
      return 'SET DEFAULT';
    }

    return $normalized;
  }

  private static function buildCheck(array $checkDef, string $dialect): string
  {
    $name       = $checkDef['name'] ?? null;
    $expression = $checkDef['expression'] ?? '';
    if ('' === $expression) {
      throw new InvalidArgumentException('CHECK benötigt "expression".');
    }

    if ('sqlsrv' === $dialect) {
      if ($name) {
        return 'CONSTRAINT ['.$name.'] CHECK ('.$expression.')';
      }

      return 'CHECK ('.$expression.')';
    }

    if ($name) {
      return 'CONSTRAINT `'.$name.'` CHECK ('.$expression.')';
    }

    return 'CHECK ('.$expression.')';
  }

  private static function quoteColumn(string $name, string $dialect): string
  {
    return 'sqlsrv' === $dialect ? '['.$name.']' : '`'.$name.'`';
  }

  private static function quoteColumnList(array $columns, string $dialect): string
  {
    $quoted = [];
    foreach ($columns as $column) {
      $quoted[] = self::quoteColumn((string) $column, $dialect);
    }

    return implode(', ', $quoted);
  }

  private static function formatDefault(mixed $value, string $dialect): string
  {
    if (null === $value) {
      return 'NULL';
    }
    if (\is_int($value) || \is_float($value)) {
      return (string) $value;
    }
    $upper = mb_strtoupper((string) $value);
    if (\in_array($upper, ['CURRENT_TIMESTAMP', 'NOW'], true)) {
      return 'sqlsrv' === $dialect ? 'SYSDATETIME()' : 'CURRENT_TIMESTAMP';
    }

    return "'".str_replace("'", "''", (string) $value)."'";
  }

  private static function indentLines(array $lines, int $spaces): string
  {
    $indent = str_repeat(' ', $spaces);
    $out    = [];
    $count  = \count($lines);
    foreach ($lines as $idx => $line) {
      $suffix = $idx < $count - 1 ? ',' : '';
      $out[]  = $indent.$line.$suffix;
    }

    return implode("\n", $out);
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

  private static function normalizeDialectContext(string|array $dialect): array
  {
    if (\is_array($dialect)) {
      $context       = $dialect;
      $dialectValue  = $context['dialect'] ?? $context['driver'] ?? $context['name'] ?? null;
      if ( ! \is_string($dialectValue) || '' === $dialectValue) {
        throw new InvalidArgumentException('SQL-Dialekt fehlt.');
      }
      $context['dialect'] = mb_strtolower($dialectValue);

      return $context;
    }

    return ['dialect' => mb_strtolower((string) $dialect)];
  }

  private static function isMySqlCheckSupported(array $context): bool
  {
    $product = mb_strtolower((string) ($context['serverProduct'] ?? ''));
    if ('mariadb' === $product) {
      return true;
    }

    $versionId = (int) ($context['serverVersionId'] ?? 0);
    if (0 === $versionId) {
      return true;
    }

    return $versionId >= 80016;
  }

}
