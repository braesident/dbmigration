<?php

declare(strict_types=1);

namespace Braesident\DbMigration;

use DateTimeImmutable;
use DirectoryIterator;
use Exception;
use InvalidArgumentException;
use PDO;
use PDOException;
use ReflectionClass;
use stdClass;

final class MigrationManager
{
  private string $path;

  private string $migrationNamespace;

  private string $migrationInterface;

  private string $jsonMigrationClass;

  private ?array $executedMigrations = null;

  /**
   * @var IMigration[]
   */
  private array $migrations = [];

  private MigrationHelper $helper;

  public function __construct(
    private PDO $pdo,
    ?string $path = null,
    ?string $migrationNamespace = null,
    ?string $migrationInterface = null,
    ?string $jsonMigrationClass = null,
    private ?string $schema = null
  ) {
    $this->path               = $this->normalizePath($path ?? \dirname(__DIR__, 1).'/migrations/');
    $this->migrationNamespace = trim($migrationNamespace ?? 'Braesident\\migrations', '\\');
    $this->migrationInterface = $migrationInterface ?? 'Braesident\DbMigration\iMigration';
    $this->jsonMigrationClass = $jsonMigrationClass ?? 'Braesident\DbMigration\JsonMigration';
    $this->helper             = new MigrationHelper($this->path, $pdo, $this->migrationNamespace);
  }

  public function getCurrentId(): int
  {
    $this->ensureDbMigrationTable();

    if ( ! $this->isDbMigrationsInstalled()) {
      return 0;
    }

    $table = $this->getDbMigrationTable();

    $stmt = $this->pdo->prepare(
      'SELECT kMigration
      FROM '.$table.'
      ORDER BY kMigration DESC'
    );

    $stmt->execute();

    return (int) $stmt->fetchColumn();
  }

  public function getExecutedMigrations(): array
  {
    $this->ensureDbMigrationTable();

    if ( ! $this->isDbMigrationsInstalled()) {
      return $this->executedMigrations = [];
    }

    if (null === $this->executedMigrations) {
      $this->executedMigrations = [];
      $table                    = $this->getDbMigrationTable();
      $stmt                     = $this->pdo->prepare('SELECT *
        FROM '.$table.'
        ORDER BY kMigration ASC');
      $stmt->execute();
      $set = $stmt->fetchAll(PDO::FETCH_OBJ);
      foreach ($set as $k => $m) {
        // jdump([
        //   'key'      => $k,
        //   'DTI'      => new DateTimeImmutable($m->dExecuted),
        //   'executed' => $m->dExecuted
        // ]);
        $this->executedMigrations[$m->kMigration] = new DateTimeImmutable($m->dExecuted);
      }
    }

    return array_keys($this->executedMigrations);
  }

  public function getMigrations(): array
  {
    if (\count($this->migrations) > 0) {
      return $this->migrations;
    }

    $migrations = [];
    $executed   = $this->getExecutedMigrations();

    foreach (new DirectoryIterator($this->path) as $fileinfo) {
      if ($fileinfo->isDot() || 'php' !== $fileinfo->getExtension()) {
        continue;
      }

      $baseName = $fileinfo->getBasename();

      if ($this->helper->isValidMigrationFileName($baseName)) {
        $filePath = $fileinfo->getPathname();
        $id       = $this->helper->getIdFromFileName($baseName);
        if (null === $id) {
          continue;
        }
        if (isset($migrations[$id])) {
          throw new InvalidArgumentException(\sprintf('Duplicate migration id "%s" in "%s"', $id, $filePath));
        }
        $class = $this->helper->mapFileNameToClassName($fileinfo);
        $date  = $executed[(int) $id] ?? null;
        require_once $filePath;

        if ( ! class_exists($class)) {
          throw new InvalidArgumentException(\sprintf('Could not find class "%s" in file "%s"', $class, $filePath));
        }
        $migration = new $class($this->pdo, $date);
        /** @var IMigration $migration */
        if ( ! is_subclass_of($migration, $this->migrationInterface)) {
          throw new InvalidArgumentException(\sprintf('The class "%s" in file "%s" must implement "%s"', $class, $filePath, $this->migrationInterface));
        }

        $migrations[$id] = $migration;
      }
    }

    foreach (new DirectoryIterator($this->path) as $fileinfo) {
      if ($fileinfo->isDot() || 'json' !== $fileinfo->getExtension()) {
        continue;
      }

      $baseName = $fileinfo->getBasename();

      if ($this->helper->isValidMigrationJsonFileName($baseName)) {
        $id = $this->helper->getIdFromJsonFileName($baseName);
        if (null === $id) {
          continue;
        }
        if (isset($migrations[$id])) {
          continue;
        }
        $date           = $executed[(int) $id] ?? null;
        $migrationClass = $this->jsonMigrationClass;
        $reflection     = new ReflectionClass($migrationClass);
        $args           = [$this->pdo, (int) $id, $date];
        $constructor    = $reflection->getConstructor();
        if ($constructor && $constructor->getNumberOfParameters() >= 4) {
          $args[] = $this->migrationNamespace;
        }
        $migrations[$id] = $reflection->newInstanceArgs($args);
      }
    }
    ksort($migrations);
    $this->setMigrations($migrations);

    return $this->migrations;
  }

  public function isDbMigrationsInstalled(): bool
  {
    if ($this->isSqlServer()) {
      $stmt = $this->pdo->prepare("SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = 'dbmigration'");
      $stmt->execute([':schema' => $this->getSqlServerSchema()]);

      return (bool) $stmt->fetchColumn();
    }

    $stmt = $this->pdo->prepare("SELECT EXISTS (
      SELECT *
      FROM information_schema.tables
      WHERE table_schema = :schema
        AND table_name = 'dbmigration'
    );");
    $stmt->execute([':schema' => $this->getMySqlSchema()]);

    return (bool) $stmt->fetchColumn();
  }

  public function migrate($identifier = null): array
  {
    $this->ensureDbMigrationTable();

    $migrations         = $this->getMigrations();
    $executedMigrations = $this->getExecutedMigrations();
    $currentId          = $this->getCurrentId();
    $executed           = [];

    if (empty($executedMigrations) && empty($migrations)) {
      return [];
    }

    if (null === $identifier) {
      $identifier = max(array_merge($executedMigrations, array_keys($migrations)));
    }

    $identifier = $this->resolveMigrationIdentifier($identifier, $migrations, $executedMigrations, $currentId);

    $direction = $identifier > $currentId ? IMigration::UP : IMigration::DOWN;

    try {
      if (IMigration::DOWN === $direction) {
        krsort($migrations);
        foreach ($migrations as $migration) {
          // $migration->setDeleteData();
          $id = $migration->getId();
          if ($id <= $identifier) {
            break;
          }
          if (\in_array($id, $executedMigrations, true)) {
            $executed[] = $migration;
            $this->executeMigration($migration, IMigration::DOWN);
          }
        }
      }
      ksort($migrations);
      foreach ($migrations as $migration) {
        $id = $migration->getId();
        if ($id > $identifier) {
          break;
        }
        if ( ! \in_array($id, $executedMigrations, true)) {
          $executed[] = $migration;
          $this->executeMigration($migration);
        }
      }
    } catch (PDOException $e) {
      [$code, , $message] = $e->errorInfo;
      // TODO generate logger
      // $this->log($migration, $direction, $code, $message);

      throw $e;
    } catch (Exception $e) {
      // $this->log($migration, $direction, 'JTL01', $e->getMessage());

      throw $e;
    }

    return $executed;
  }

  private function resolveMigrationIdentifier(mixed $identifier, array $migrations, array $executedMigrations, int $currentId): int
  {
    if (null === $identifier) {
      return $currentId;
    }

    if (\is_string($identifier)) {
      $trimmed = trim($identifier);
      if (preg_match('/^([+-])(\d+)$/', $trimmed, $matches)) {
        return $this->resolveRelativeMigrationId($matches[1], (int) $matches[2], $migrations, $executedMigrations, $currentId);
      }
    }

    if (\is_numeric($identifier)) {
      return (int) $identifier;
    }

    throw new InvalidArgumentException('Ungültiger Migration-Identifikator.');
  }

  private function resolveRelativeMigrationId(string $sign, int $offset, array $migrations, array $executedMigrations, int $currentId): int
  {
    if ($offset <= 0) {
      return $currentId;
    }

    if ('+' === $sign) {
      $ids = array_map('intval', array_keys($migrations));
      sort($ids, \SORT_NUMERIC);
      $future = array_values(array_filter($ids, static fn ($id) => $id > $currentId));
      if ([] === $future) {
        return $currentId;
      }
      $index = $offset - 1;
      if ($index >= \count($future)) {
        return $future[\count($future) - 1];
      }

      return $future[$index];
    }

    $ids = array_map('intval', $executedMigrations);
    sort($ids, \SORT_NUMERIC);
    $past = array_values(array_filter($ids, static fn ($id) => $id < $currentId));
    if ([] === $past) {
      return 0;
    }
    $index = \count($past) - $offset;
    if ($index < 0) {
      return 0;
    }

    return $past[$index];
  }

  public function setMigrations(array $migrations): self
  {
    $this->migrations = $migrations;

    return $this;
  }

  private function executeMigration(IMigration $migration, string $direction = IMigration::UP): void
  {
    // reset cached executed migrations
    $this->executedMigrations = null;
    $start                    = new DateTimeImmutable('now');

    try {
      $this->pdo->beginTransaction();
      if ( ! $this->executeJsonMigration($migration, $direction)) {
        $migration->{$direction}();
      }
      if ($this->pdo->inTransaction()) {
        // Transaction may be committed by DDL in migration
        $this->pdo->commit();
      }
      $this->migrated($migration, $direction, $start);
    } catch (Exception $e) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollback();
      }

      throw new Exception($migration->getName().' '.$migration->getDescription().' | '.$e->getMessage(), (int) $e->getCode());
    }
  }

  private function migrated(IMigration $migration, string $direction, DateTimeImmutable $executed): self
  {
    if (0 === strcasecmp($direction, IMigration::UP)) {
      $data             = new stdClass();
      $data->kMigration = $migration->getId();
      $data->dExecuted  = $executed->format('Y-m-d H:i:s');

      $table = $this->getDbMigrationTable();
      $stmt  = $this->pdo->prepare('INSERT INTO '.$table.' (kMigration, dExecuted) VALUES (:kMigration, :dExecuted)');
      $stmt->execute([
        ':kMigration' => $migration->getId(),
        ':dExecuted'  => $executed->format('Y-m-d H:i:s')
      ]);

      return $this;
    }

    $table = $this->getDbMigrationTable();
    $stmt  = $this->pdo->prepare('DELETE FROM '.$table.' WHERE kMigration = :kMigration');
    $stmt->execute([
      ':kMigration' => $migration->getId(),
    ]);

    return $this;
  }

  private function getSqlServerSchema(): string
  {
    return 'dbo';
  }

  private function getMySqlSchema(): string
  {
    if (\is_string($this->schema) && '' !== $this->schema) {
      return $this->schema;
    }
    if ($this->pdo instanceof PDO) {
      $stmt   = $this->pdo->query('SELECT DATABASE()');
      $schema = $stmt ? $stmt->fetchColumn() : null;
      if (\is_string($schema) && '' !== $schema) {
        return $schema;
      }
    }

    throw new InvalidArgumentException('MySQL schema ist nicht gesetzt.');
  }

  private function getDbMigrationTable(): string
  {
    if ($this->isSqlServer()) {
      return '[dbo].[dbmigration]';
    }

    return 'dbmigration';
  }

  private function normalizePath(string $path): string
  {
    return rtrim($path, '\\/').\DIRECTORY_SEPARATOR;
  }

  private function ensureDbMigrationTable(): void
  {
    if ($this->isDbMigrationsInstalled()) {
      return;
    }

    $definition = $this->helper->getDbMigrationDefinition();
    $sql        = SqlBuilder::build($definition, $this->getDialect());
    $this->executeSql($sql);
  }

  private function executeSql(string|array $sql): void
  {
    if (\is_array($sql)) {
      foreach ($sql as $statement) {
        $this->executeSql($statement);
      }

      return;
    }

    $sql = trim($sql);
    if ('' === $sql) {
      return;
    }

    $this->pdo->query($sql);
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

  private function executeJsonMigration(IMigration $migration, string $direction): bool
  {
    $payload = $this->helper->readJsonMigration((int) $migration->getId());
    if (null === $payload) {
      if ($migration instanceof JsonMigration) {
        throw new InvalidArgumentException('JSON-Migration fehlt für ID '.$migration->getId().'.');
      }

      return false;
    }

    $key        = 0 === strcasecmp($direction, IMigration::DOWN) ? 'down' : 'up';
    $statements = $payload[$key] ?? [];
    $statements = $this->normalizeJsonStatements($statements);

    if ( ! method_exists($migration, 'execute')) {
      throw new InvalidArgumentException('JSON-Migration erfordert eine Migration-Basisklasse mit execute().');
    }

    foreach ($statements as $statement) {
      if (null === $statement || '' === $statement) {
        continue;
      }
      $migration->execute($statement);
    }

    return true;
  }

  private function normalizeJsonStatements(mixed $statements): array
  {
    if (null === $statements) {
      return [];
    }

    if ( ! \is_array($statements)) {
      return [$statements];
    }

    if ( ! $this->isList($statements)) {
      return [$statements];
    }

    return $statements;
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

  private function isSqlServer(): bool
  {
    if ($this->pdo instanceof PDO) {
      $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
      if (\in_array($driver, ['sqlsrv', 'dblib', 'mssql'], true)) {
        return true;
      }
    }

    return false;
  }
}
