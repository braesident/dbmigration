<?php

declare(strict_types=1);

namespace test\php;

use Braesident\DbMigration\iMigration;
use Braesident\DbMigration\Migration;

final class Migration20251229193500 extends Migration implements iMigration
{
  public function up(): void
  {
    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'    => 'alter_table',
        'table'   => 'test_migration',
        'schema'  => 'dbo',
        'actions' => [
          [
            'action' => 'rename_table',
            'from'   => 'test_migration',
            'to'     => 'test_migration_log'
          ]
        ]
      ]
    ]);
  }

  public function down(): void
  {
    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'    => 'alter_table',
        'table'   => 'test_migration_log',
        'schema'  => 'dbo',
        'actions' => [
          [
            'action' => 'rename_table',
            'from'   => 'test_migration_log',
            'to'     => 'test_migration'
          ]
        ]
      ]
    ]);
  }
}
