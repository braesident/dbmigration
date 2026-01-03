<?php

declare(strict_types=1);

namespace test\php;

use Braesident\DbMigration\iMigration;
use Braesident\DbMigration\Migration;

final class Migration20251229193100 extends Migration implements iMigration
{
  public function up(): void
  {
    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'        => 'create_table',
        'table'       => 'test_migration_category',
        'schema'      => 'dbo',
        'ifNotExists' => true,
        'columns'     => [
          [
            'name'          => 'kTest_migration_category',
            'type'          => 'bigint',
            'nullable'      => false,
            'autoIncrement' => true,
            'unsigned'      => true
          ],
          [
            'name'     => 'cName',
            'type'     => 'string',
            'length'   => 128,
            'nullable' => false
          ]
        ],
        'primaryKey' => ['kTest_migration_category']
      ]
    ]);

    $this->execute('ALTER TABLE test_migration_category ADD CONSTRAINT uq_test_migration_category_name UNIQUE (cName)');
  }

  public function down(): void
  {
    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'    => 'alter_table',
        'table'   => 'test_migration_category',
        'schema'  => 'dbo',
        'actions' => [
          [
            'action' => 'drop_unique',
            'name'   => 'uq_test_migration_category_name'
          ]
        ]
      ]
    ]);

    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'     => 'drop_table',
        'table'    => 'test_migration_category',
        'schema'   => 'dbo',
        'ifExists' => true
      ]
    ]);
  }
}
