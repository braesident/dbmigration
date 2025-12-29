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
   * MigrationHelper constructor.
   */
  public function __construct(private string $path, private PDO $db, private string $migrationNamespace = 'Braesident\\migrations')
  {
    $this->migrationNamespace = trim($this->migrationNamespace, '\\');
  }

  public function getIdFromFileName(string $fileName): ?int
  {
    $matches = [];

    return (preg_match(self::MIGRATION_FILE_NAME_PATTERN, basename($fileName), $matches))
        ? (int) $matches[1]
        : null;
  }

  public function isValidMigrationFileName(string $fileName): int|bool
  {
    return (bool) preg_match(self::MIGRATION_FILE_NAME_PATTERN, $fileName, $matches);
  }

  public function isValidMigrationJsonFileName(string $fileName): bool
  {
    return (bool) preg_match(self::MIGRATION_JSON_FILE_NAME_PATTERN, $fileName, $matches);
  }

  public function getIdFromJsonFileName(string $fileName): ?int
  {
    $matches = [];

    return (preg_match(self::MIGRATION_JSON_FILE_NAME_PATTERN, basename($fileName), $matches))
        ? (int) $matches[1]
        : null;
  }

  public function getJsonPathForId(int $id): ?string
  {
    $fileName = 'Migration'.str_pad((string) $id, 14, '0', \STR_PAD_LEFT).'.json';
    $path     = rtrim($this->path, '\\/').\DIRECTORY_SEPARATOR.$fileName;

    return is_file($path) ? $path : null;
  }

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

  public function mapFileNameToClassName(DirectoryIterator $file): string
  {
    return \sprintf(
      '%s\%s',
      $this->migrationNamespace,
      str_replace('.'.$file->getExtension(), '', $file->getFilename())
    );
  }

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

  public static function mapClassNameToId(string $className): ?int
  {
    $matches = [];

    return preg_match(self::MIGRATION_CLASS_NAME_PATTERN, $className, $matches)
        ? (int) $matches[1]
        : null;
  }
}
