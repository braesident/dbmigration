<?php

declare(strict_types=1);

namespace test\php;

use Braesident\DbMigration\iMigration;
use Braesident\DbMigration\Migration;

final class Migration20251229193400 extends Migration implements iMigration
{
  public function up(): void
  {
    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'        => 'create_table',
        'table'       => 'test_constraint',
        'schema'      => 'dbo',
        'ifNotExists' => true,
        'columns'     => [
          [
            'name'     => 'kConstraint',
            'type'     => 'bigint',
            'nullable' => false
          ],
          [
            'name'     => 'cLabel',
            'type'     => 'string',
            'length'   => 64,
            'nullable' => false
          ]
        ]
      ]
    ]);

    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'    => 'alter_table',
        'table'   => 'test_constraint',
        'schema'  => 'dbo',
        'actions' => [
          [
            'action'  => 'add_primary_key',
            'name'    => 'pk_test_constraint',
            'columns' => ['kConstraint']
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
        'table'   => 'test_constraint',
        'schema'  => 'dbo',
        'actions' => [
          [
            'action' => 'drop_primary_key',
            'name'   => 'pk_test_constraint'
          ]
        ]
      ]
    ]);

    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'     => 'drop_table',
        'table'    => 'test_constraint',
        'schema'   => 'dbo',
        'ifExists' => true
      ]
    ]);
  }
}
