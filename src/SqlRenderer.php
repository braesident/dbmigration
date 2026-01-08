<?php

declare(strict_types=1);

namespace Braesident\DbMigration;

use InvalidArgumentException;
use PDO;
use stdClass;

final class SqlRenderer
{
  private ?array $dialectContext = null;

  public function __construct(private PDO $pdo)
  {
  }

  public function render(string|array|object $query): string|array|null
  {
    return $this->resolveQuery($query);
  }

  public function renderAll(string|array|object $query): array
  {
    $resolved = $this->render($query);
    if (null === $resolved) {
      return [];
    }

    $out = [];
    $this->collectStatements($resolved, $out);

    return $out;
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
      if (method_exists($query, 'statement')) {
        /** @var callable $method */
        $method = [$query, 'statement'];

        return $method();
      }
      if (method_exists($query, '__toString')) {
        return (string) $query;
      }
      $query = ($query instanceof stdClass) ? (array) $query : get_object_vars($query);
    }

    if ( ! \is_array($query)) {
      throw new InvalidArgumentException('SQL-Definition muss string, array oder object sein.');
    }

    if ($this->isList($query)) {
      return $query;
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
      $definition = $normalized['definition'] ?? $normalized;

      return SqlBuilder::build($definition, $this->getDialectContext());
    }

    if ($this->hasDialectSql($normalized)) {
      return $this->resolveDialectSql($normalized);
    }

    if ($this->looksLikeQueryAst($normalized)) {
      return SelectBuilder::build($normalized, $this->getDialectContext());
    }

    throw new InvalidArgumentException('SQL-Definition hat kein bekanntes Format.');
  }

  private function collectStatements(mixed $value, array &$out): void
  {
    if (null === $value) {
      return;
    }

    if (\is_string($value)) {
      $sql = trim($value);
      if ('' !== $sql) {
        $out[] = $sql;
      }

      return;
    }

    if (\is_array($value)) {
      foreach ($value as $item) {
        $this->collectStatements($item, $out);
      }

      return;
    }

    throw new InvalidArgumentException('SQL-Statement muss string oder array sein.');
  }

  private function hasDialectSql(array $definition): bool
  {
    $keys = array_map(static fn ($key) => mb_strtolower((string) $key), array_keys($definition));

    return (bool) array_intersect($keys, ['mysql', 'mariadb', 'sqlsrv', 'sqlserver', 'mssql', 'sql', 'default']);
  }

  private function resolveDialectSql(array $definition): string|array
  {
    $dialect    = $this->getDialect();
    $dialectMap = [
      'mysql'  => ['mysql', 'mariadb'],
      'sqlsrv' => ['sqlsrv', 'sqlserver', 'mssql']
    ];

    if (isset($dialectMap[$dialect])) {
      foreach ($dialectMap[$dialect] as $key) {
        if (array_key_exists($key, $definition)) {
          return $definition[$key];
        }
      }
    }

    if (array_key_exists('sql', $definition)) {
      return $definition['sql'];
    }

    if (array_key_exists('default', $definition)) {
      return $definition['default'];
    }

    throw new InvalidArgumentException('Kein SQL für Dialekt "'.$dialect.'" gefunden.');
  }

  private function looksLikeQueryAst(mixed $value): bool
  {
    if (\is_object($value)) {
      $value = ($value instanceof stdClass) ? (array) $value : get_object_vars($value);
    }
    if ( ! \is_array($value)) {
      return false;
    }

    $keys = array_map(static fn ($key) => self::normalizeKey($key), array_keys($value));
    foreach (['select', 'from', 'where', 'groupby', 'having', 'orderby', 'join', 'leftjoin', 'rightjoin', 'limit', 'offset', 'distinct', 'union', 'unionall'] as $key) {
      if (\in_array($key, $keys, true)) {
        return true;
      }
    }

    return false;
  }

  private static function normalizeKey(string|int $key): string
  {
    $key = mb_strtolower((string) $key);

    return str_replace(['_', '-'], '', $key);
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
      $items = preg_split('/\s*,\s*|\s+/', $value);
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
