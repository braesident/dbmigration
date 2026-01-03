<?php

declare(strict_types=1);

namespace test\php;

use Braesident\DbMigration\iMigration;
use Braesident\DbMigration\Migration;

final class Migration20251229193300 extends Migration implements iMigration
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
            'action' => 'modify_column',
            'column' => [
              'name'     => 'cName',
              'type'     => 'string',
              'length'   => 200,
              'nullable' => false
            ]
          ],
          [
            'action' => 'rename_column',
            'from'   => 'cName',
            'to'     => 'cTitle',
            'column' => [
              'name'     => 'cTitle',
              'type'     => 'string',
              'length'   => 200,
              'nullable' => false
            ]
          ]
        ]
      ]
    ]);

    $this->execute('ALTER TABLE test_migration ADD CONSTRAINT chk_test_migration_active CHECK (bActive IN (0,1))');
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
            'action' => 'drop_check',
            'name'   => 'chk_test_migration_active'
          ],
          [
            'action' => 'modify_column',
            'column' => [
              'name'     => 'cTitle',
              'type'     => 'string',
              'length'   => 255,
              'nullable' => false
            ]
          ],
          [
            'action' => 'rename_column',
            'from'   => 'cTitle',
            'to'     => 'cName',
            'column' => [
              'name'     => 'cName',
              'type'     => 'string',
              'length'   => 255,
              'nullable' => false
            ]
          ]
        ]
      ]
    ]);
  }
}
