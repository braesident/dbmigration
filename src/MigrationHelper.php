<?php

declare(strict_types=1);

namespace Braesident\DbMigration;

use DirectoryIterator;
use InvalidArgumentException;
use PDO;

final class MigrationHelper
{
  /**
   * @var string
   */
  public const MIGRATION_CLASS_NAME_PATTERN = '/Migration(\d{14})$/';

  /**
   * @var string
   */
  private const MIGRATION_FILE_NAME_PATTERN = '/^Migration(\d{14}).php$/';

  /**
   * @var string
   */
  private const MIGRATION_JSON_FILE_NAME_PATTERN = '/^Migration(\d{14}).json$/';

  /**
   * Prepares migration helpers for a filesystem path.
   */
  public function __construct(private string $path, private PDO $db, private string $migrationNamespace = 'Braesident\\migrations')
  {
    $this->migrationNamespace = trim($this->migrationNamespace, '\\');
  }

  /**
   * Extracts the migration id from a PHP filename.
   */
  public function getIdFromFileName(string $fileName): ?int
  {
    $matches = [];

    return (preg_match(self::MIGRATION_FILE_NAME_PATTERN, basename($fileName), $matches))
        ? (int) $matches[1]
        : null;
  }

  /**
   * Checks whether a PHP migration filename is valid.
   */
  public function isValidMigrationFileName(string $fileName): int|bool
  {
    return (bool) preg_match(self::MIGRATION_FILE_NAME_PATTERN, $fileName, $matches);
  }

  /**
   * Checks whether a JSON migration filename is valid.
   */
  public function isValidMigrationJsonFileName(string $fileName): bool
  {
    return (bool) preg_match(self::MIGRATION_JSON_FILE_NAME_PATTERN, $fileName, $matches);
  }

  /**
   * Extracts the migration id from a JSON filename.
   */
  public function getIdFromJsonFileName(string $fileName): ?int
  {
    $matches = [];

    return (preg_match(self::MIGRATION_JSON_FILE_NAME_PATTERN, basename($fileName), $matches))
        ? (int) $matches[1]
        : null;
  }

  /**
   * Returns the JSON migration path for an id, if it exists.
   */
  public function getJsonPathForId(int $id): ?string
  {
    $fileName = 'Migration'.str_pad((string) $id, 14, '0', \STR_PAD_LEFT).'.json';
    $path     = rtrim($this->path, '\\/').\DIRECTORY_SEPARATOR.$fileName;

    return is_file($path) ? $path : null;
  }

  /**
   * Reads and decodes a JSON migration by id.
   */
  public function readJsonMigration(int $id): ?array
  {
    $path = $this->getJsonPathForId($id);
    if (null === $path) {
      return null;
    }

    $content = file_get_contents($path);
    if (false === $content) {
      throw new InvalidArgumentException('Migration-JSON konnte nicht gelesen werden: '.$path);
    }

    $decoded = json_decode($content, true);
    if (\JSON_ERROR_NONE !== json_last_error() || ! \is_array($decoded)) {
      throw new InvalidArgumentException('Migration-JSON ist ungültig: '.$path);
    }

    return $decoded;
  }

  /**
   * Maps a migration filename to the fully-qualified class name.
   */
  public function mapFileNameToClassName(DirectoryIterator $file): string
  {
    return \sprintf(
      '%s\%s',
      $this->migrationNamespace,
      str_replace('.'.$file->getExtension(), '', $file->getFilename())
    );
  }

  /**
   * Returns the default dbmigration table definition.
   */
  public function getDbMigrationDefinition(): array
  {
    return [
      'type'        => 'create_table',
      'table'       => 'dbmigration',
      'schema'      => 'dbo',
      'ifNotExists' => true,
      'columns'     => [
        [
          'name'     => 'kMigration',
          'type'     => 'bigint',
          'nullable' => false
        ],
        [
          'name'     => 'dExecuted',
          'type'     => 'datetime',
          'nullable' => false
        ]
      ],
      'primaryKey' => ['kMigration']
    ];
  }

  /**
   * Extracts the migration id from a class name.
   */
  public static function mapClassNameToId(string $className): ?int
  {
    $matches = [];

    return preg_match(self::MIGRATION_CLASS_NAME_PATTERN, $className, $matches)
        ? (int) $matches[1]
        : null;
  }
}
