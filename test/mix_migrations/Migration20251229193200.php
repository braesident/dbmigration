<?php

declare(strict_types=1);

namespace test\mix;

use Braesident\DbMigration\iMigration;
use Braesident\DbMigration\Migration;

final class Migration20251229193200 extends Migration implements iMigration
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
            'action' => 'add_column',
            'column' => [
              'name'     => 'kTest_migration_category',
              'type'     => 'bigint',
              'unsigned' => true,
              'nullable' => true
            ]
          ],
          [
            'action'  => 'add_index',
            'name'    => 'idx_test_migration_category',
            'columns' => ['kTest_migration_category']
          ],
          [
            'action'     => 'add_foreign_key',
            'name'       => 'fk_test_migration_category',
            'columns'    => ['kTest_migration_category'],
            'refTable'   => 'test_migration_category',
            'refColumns' => ['kTest_migration_category'],
            'onDelete'   => 'set null',
            'onUpdate'   => 'no action'
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
        'table'   => 'test_migration',
        'schema'  => 'dbo',
        'actions' => [
          [
            'action' => 'drop_foreign_key',
            'name'   => 'fk_test_migration_category'
          ],
          [
            'action' => 'drop_index',
            'name'   => 'idx_test_migration_category'
          ],
          [
            'action' => 'drop_column',
            'name'   => 'kTest_migration_category'
          ]
        ]
      ]
    ]);
  }
}
