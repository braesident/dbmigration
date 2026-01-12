<?php

declare(strict_types=1);

namespace Braesident\DbMigration;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class Migration
{
  private $description;
  private ?array $dialectContext = null;

  /**
   * Creates a migration base instance.
   */
  public function __construct(private PDO $pdo, private ?DateTimeImmutable $executed = null)
  {
  }

  /**
   * Returns the optional migration description.
   */
  public function getDescription(): string
  {
    return $this->description ?? '';
  }

  /**
   * Returns the migration id derived from the class name.
   */
  public function getId()
  {
    return MigrationHelper::mapClassNameToId($this->getName());
  }

  /**
   * Returns the fully-qualified migration class name.
   */
  public function getName(): string
  {
    return static::class;
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

    $only   = $normalized['only'] ?? $normalized['dialects'] ?? $normalized['include'] ?? null;
    $except = $normalized['except'] ?? $normalized['exclude'] ?? null;
    if (null !== $only || null !== $except) {
      $dialect = $this->getDialect();
      if (null !== $only && ! $this->matchesDialectList($only, $dialect)) {
        return null;
      }
      if (null !== $except && $this->matchesDialectList($except, $dialect)) {
        return null;
      }
      unset($normalized['only'], $normalized['dialects'], $normalized['include'], $normalized['except'], $normalized['exclude']);
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

      return SqlBuilder::build($definition, $this->getDialectContext());
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

  private function getDialectContext(): array
  {
    if (null !== $this->dialectContext) {
      return $this->dialectContext;
    }

    $dialect = $this->getDialect();
    $context = ['dialect' => $dialect];

    if ('mysql' === $dialect) {
      $context = array_merge($context, $this->getMySqlVersionInfo());
    }

    return $this->dialectContext = $context;
  }

  private function matchesDialectList(mixed $value, string $dialect): bool
  {
    $list = $this->normalizeDialectList($value);
    if ([] === $list) {
      return false;
    }
    if (\in_array('all', $list, true)) {
      return true;
    }
    if ('sqlsrv' === $dialect) {
      return \in_array('sqlsrv', $list, true);
    }
    if ('mysql' === $dialect) {
      if (\in_array('mysql', $list, true)) {
        return true;
      }
      if (\in_array('mariadb', $list, true)) {
        $context = $this->getDialectContext();

        return 'mariadb' === ($context['serverProduct'] ?? '');
      }
    }

    return false;
  }

  private function normalizeDialectList(mixed $value): array
  {
    if (\is_object($value)) {
      $value = get_object_vars($value);
    }

    if (\is_string($value)) {
      $value = trim($value);
      if ('' === $value) {
        throw new InvalidArgumentException('dialect filter darf nicht leer sein.');
      }
      $items = preg_split('/\\s*,\\s*|\\s+/', $value);
    } elseif (\is_array($value)) {
      $items = $this->isList($value) ? $value : array_values($value);
    } else {
      throw new InvalidArgumentException('dialect filter muss string oder array sein.');
    }

    $out = [];
    foreach ($items as $item) {
      if ( ! \is_string($item)) {
        throw new InvalidArgumentException('dialect filter benötigt strings.');
      }
      $token = mb_strtolower(trim($item));
      if ('' === $token) {
        continue;
      }
      if ('*' === $token || 'all' === $token) {
        $out[] = 'all';
        continue;
      }
      if (\in_array($token, ['sqlsrv', 'sqlserver', 'mssql'], true)) {
        $out[] = 'sqlsrv';
        continue;
      }
      if (\in_array($token, ['maria', 'mariadb'], true)) {
        $out[] = 'mariadb';
        continue;
      }
      if ('mysql' === $token) {
        $out[] = 'mysql';
        continue;
      }

      throw new InvalidArgumentException('Unbekannter Dialekt: '.$item);
    }

    return array_values(array_unique($out));
  }

  private function getMySqlVersionInfo(): array
  {
    $version = '';
    if ($this->pdo instanceof PDO) {
      $attr = $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
      if (\is_string($attr)) {
        $version = $attr;
      }
    }
    if ('' === $version) {
      return [];
    }

    $isMaria  = false !== stripos($version, 'mariadb');
    $clean    = $isMaria ? preg_replace('/-.*$/', '', $version) : preg_replace('/[^0-9.].*$/', '', $version);
    $parts    = explode('.', (string) $clean);
    $major    = (int) ($parts[0] ?? 0);
    $minor    = (int) ($parts[1] ?? 0);
    $patch    = (int) ($parts[2] ?? 0);
    $versionId = ($major * 10000) + ($minor * 100) + $patch;

    return [
      'serverProduct'  => $isMaria ? 'mariadb' : 'mysql',
      'serverVersion'  => $version,
      'serverVersionId' => $versionId
    ];
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
