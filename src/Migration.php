<?php

declare(strict_types=1);

namespace Braesident\DbMigration;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final class Migration
{
  private $description;

  public function __construct(private PDO $pdo, private ?DateTimeImmutable $executed = null)
  {
  }

  public function getDescription(): string
  {
    return $this->description ?? '';
  }

  public function getId()
  {
    return MigrationHelper::mapClassNameToId($this->getName());
  }

  public function getName(): string
  {
    return self::class;
  }

  /**
   * Execute a query or dialect-specific query set.
   */
  public function execute(string|array|object $query): void
  {
    $resolved = $this->resolveQuery($query);

    if (null === $resolved) {
      return;
    }

    if (\is_array($resolved)) {
      foreach ($resolved as $statement) {
        $this->execute($statement);
      }

      return;
    }

    $sql = trim($resolved);
    if ('' === $sql) {
      return;
    }

    $this->pdo->query($sql);
  }

  private function resolveQuery(string|array|object $query): string|array|null
  {
    if (\is_string($query)) {
      return $query;
    }

    if (\is_object($query)) {
      if (method_exists($query, 'toSql')) {
        /** @var callable $method */
        $method = [$query, 'toSql'];

        return $method($this->getDialect());
      }
      if (method_exists($query, '__toString')) {
        return (string) $query;
      }
      $query = get_object_vars($query);
    }

    $normalized = [];
    foreach ($query as $key => $value) {
      $normalized[mb_strtolower((string) $key)] = $value;
    }

    if (isset($normalized['builder']) || isset($normalized['definition']) || isset($normalized['type'])) {
      $builder = $normalized['builder'] ?? 'sql';
      if (\is_string($builder)) {
        $builder = mb_strtolower($builder);
        if ( ! \in_array($builder, ['sql', 'sqlbuilder'], true)) {
          throw new InvalidArgumentException('Unbekannter SQL-Builder: '.$builder);
        }
      }
      $definition = $normalized['definition'] ?? $normalized;

      return SqlBuilder::build($definition, $this->getDialect());
    }

    if ($this->isList($query)) {
      return $query;
    }

    $dialect    = $this->getDialect();
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

  private function getDialect(): string
  {
    if ($this->pdo instanceof PDO) {
      $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
      if (\in_array($driver, ['sqlsrv', 'dblib', 'mssql'], true)) {
        return 'sqlsrv';
      }
    }

    return 'mysql';
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
}
