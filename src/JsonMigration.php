<?php

declare(strict_types=1);

namespace Braesident\DbMigration;

use DateTimeImmutable;
use PDO;

final class JsonMigration extends Migration implements iMigration
{
  private int $id;

  private string $name;

  public function __construct(PDO $pdo, int $id, ?DateTimeImmutable $executed = null, ?string $migrationNamespace = null)
  {
    parent::__construct($pdo, $executed);
    $this->id   = $id;
    $namespace  = trim($migrationNamespace ?? 'Braesident\\migrations', '\\');
    $this->name = $namespace.'\\Migration'.str_pad((string) $id, 14, '0', \STR_PAD_LEFT);
  }

  public function up(): void
  {
  }

  public function down(): void
  {
  }

  public function getId()
  {
    return $this->id;
  }

  public function getName(): string
  {
    return $this->name;
  }
}
