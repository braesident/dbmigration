<?php

declare(strict_types=1);

namespace Braesident\DbMigration;

interface iMigration
{
  /**
   * @var string
   */
  public const UP = 'up';

  /**
   * @var string
   */
  public const DOWN = 'down';

  public function up(): void;

  public function down(): void;

  public function getDescription(): string;

  public function getId();

  public function getName(): string;

  public function execute(string|array|object $query): void;
}
