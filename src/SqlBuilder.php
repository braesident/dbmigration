<?php

declare(strict_types=1);

namespace Braesident\DbMigration;

use InvalidArgumentException;
use stdClass;

/**
 * Minimaler SQL-Builder-Stub für Dialekt-spezifisches SQL.
 */
final class SqlBuilder
{
  public static function build(string|array|object $definition, string $dialect): string|array
  {
    $normalized = self::normalizeDefinition($definition);
    $type       = mb_strtolower((string) ($normalized['type'] ?? ''));
    if ('' === $type && self::hasDialectSql($normalized)) {
      $type = 'raw';
    }

    return match ($type) {
      'create_table' => self::buildCreateTable($normalized, $dialect),
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
}
