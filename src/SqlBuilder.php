<?php

declare(strict_types=1);

namespace Braesident\DbMigration;

use InvalidArgumentException;
use stdClass;

final class SqlBuilder
{
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
      'alter_table'  => self::buildAlterTable($normalized, $dialect, $context),
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

  private static function buildCreateTable(array $definition, string $dialect): string
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

    foreach ($columns as $column) {
      if ( ! \is_array($column)) {
        throw new InvalidArgumentException('Spalten müssen Arrays sein.');
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

        return implode("\n", $sql);
      }

      $sql   = [];
      $sql[] = 'CREATE TABLE '.$tableRef.' (';
      $sql[] = self::indentLines($lines, 2);
      $sql[] = ');';

      return implode("\n", $sql);
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
            throw new InvalidArgumentException('drop_index benötigt "name".');
          }
          if ('sqlsrv' === $dialect) {
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
            throw new InvalidArgumentException('drop_unique benötigt "name".');
          }
          if ('sqlsrv' === $dialect) {
            $statements[] = 'ALTER TABLE '.$tableRef.' DROP CONSTRAINT ['.$name.']';
          } else {
            $statements[] = 'ALTER TABLE '.$tableRef.' DROP INDEX `'.$name.'`';
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
            throw new InvalidArgumentException('drop_foreign_key benötigt "name".');
          }
          if ('sqlsrv' === $dialect) {
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
    if ('blob' === $type) {
      return 'mysql' === $dialect ? 'MEDIUMBLOB' : 'VARBINARY(MAX)';
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
    $onDelete   = $fkDef['onDelete'] ?? null;
    $onUpdate   = $fkDef['onUpdate'] ?? null;

    $sql = 'FOREIGN KEY ('.self::quoteColumnList($columns, $dialect).') ';
    if ($name) {
      $sql = ('sqlsrv' === $dialect ? 'CONSTRAINT ['.$name.'] ' : 'CONSTRAINT `'.$name.'` ').$sql;
    }
    $refTableSql = 'sqlsrv' === $dialect
      ? '['.$schema.'].['.$refTable.']'
      : '`'.$refTable.'`';
    $sql .= 'REFERENCES '.$refTableSql.' ('.self::quoteColumnList($refColumns, $dialect).')';
    if ($onDelete) {
      $sql .= ' ON DELETE '.mb_strtoupper((string) $onDelete);
    }
    if ($onUpdate) {
      $sql .= ' ON UPDATE '.mb_strtoupper((string) $onUpdate);
    }

    return $sql;
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
