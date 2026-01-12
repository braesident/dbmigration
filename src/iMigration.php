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

  /**
   * Runs the migration up direction.
   */
  public function up(): void;

  /**
   * Runs the migration down direction.
   */
  public function down(): void;

  /**
   * Returns the optional migration description.
   */
  public function getDescription(): string;

  /**
   * Returns the migration id.
   */
  public function getId();

  /**
   * Returns the fully-qualified migration class name.
   */
  public function getName(): string;

  /**
   * Executes SQL or a builder definition.
   */
  public function execute(string|array|object $query): void;
}
