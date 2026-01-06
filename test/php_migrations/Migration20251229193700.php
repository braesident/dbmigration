<?php

declare(strict_types=1);

namespace test\php;

use Braesident\DbMigration\iMigration;
use Braesident\DbMigration\Migration;

final class Migration20251229193700 extends Migration implements iMigration
{
  public function up(): void
  {
    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'        => 'create_table',
        'table'       => 'test_insert_source',
        'schema'      => 'dbo',
        'ifNotExists' => true,
        'columns'     => [
          [
            'name'          => 'kSource',
            'type'          => 'int',
            'autoIncrement' => true,
            'nullable'      => false,
            'unsigned'      => true
          ],
          [
            'name'     => 'cLabel',
            'type'     => 'string',
            'length'   => 64,
            'nullable' => false
          ],
          [
            'name'     => 'bActive',
            'type'     => 'boolean',
            'nullable' => false,
            'default'  => 1
          ]
        ],
        'primaryKey' => ['kSource']
      ]
    ]);

    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'        => 'create_table',
        'table'       => 'test_insert_target',
        'schema'      => 'dbo',
        'ifNotExists' => true,
        'columns'     => [
          [
            'name'          => 'kTarget',
            'type'          => 'int',
            'autoIncrement' => true,
            'nullable'      => false,
            'unsigned'      => true
          ],
          [
            'name'     => 'cLabel',
            'type'     => 'string',
            'length'   => 64,
            'nullable' => false
          ],
          [
            'name'     => 'bActive',
            'type'     => 'boolean',
            'nullable' => false,
            'default'  => 1
          ]
        ],
        'primaryKey' => ['kTarget']
      ]
    ]);

    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'    => 'insert',
        'table'   => 'test_insert_source',
        'schema'  => 'dbo',
        'columns' => [
          'cLabel',
          'bActive'
        ],
        'values' => [
          ['A', 1],
          ['B', 0],
          ['C', 1]
        ]
      ]
    ]);

    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'    => 'insert',
        'table'   => 'test_insert_target',
        'schema'  => 'dbo',
        'columns' => [
          'cLabel',
          'bActive'
        ],
        'query' => [
          'select' => [
            's.cLabel',
            's.bActive'
          ],
          'from' => [
            'table' => 'test_insert_source',
            'as'    => 's'
          ],
          'where'    => ['s.bActive', '=', 1],
          'order_by' => 's.cLabel ASC'
        ]
      ]
    ]);
  }

  public function down(): void
  {
    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'     => 'drop_table',
        'table'    => 'test_insert_target',
        'schema'   => 'dbo',
        'ifExists' => true
      ]
    ]);

    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'     => 'drop_table',
        'table'    => 'test_insert_source',
        'schema'   => 'dbo',
        'ifExists' => true
      ]
    ]);
  }
}

