<?php

declare(strict_types=1);

namespace Braesident\DbMigration;

use DateTimeImmutable;
use PDO;

final class JsonMigration extends Migration implements iMigration
{
  private int $id;

  private string $name;

  /**
   * Creates a JSON-backed migration instance.
   */
  public function __construct(PDO $pdo, int $id, ?DateTimeImmutable $executed = null, ?string $migrationNamespace = null)
  {
    parent::__construct($pdo, $executed);
    $this->id   = $id;
    $namespace  = trim($migrationNamespace ?? 'Braesident\\migrations', '\\');
    $this->name = $namespace.'\\Migration'.str_pad((string) $id, 14, '0', \STR_PAD_LEFT);
  }

  /**
   * No-op placeholder for JSON migrations (statements run via MigrationManager).
   */
  public function up(): void
  {
  }

  /**
   * No-op placeholder for JSON migrations (statements run via MigrationManager).
   */
  public function down(): void
  {
  }

  /**
   * Returns the numeric migration id.
   */
  public function getId()
  {
    return $this->id;
  }

  /**
   * Returns the fully-qualified migration class name.
   */
  public function getName(): string
  {
    return $this->name;
  }
}
