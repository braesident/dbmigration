<?php

declare(strict_types=1);

namespace test\php;

use Braesident\DbMigration\iMigration;
use Braesident\DbMigration\Migration;
use Braesident\DbMigration\SelectBuilder;

final class Migration20251229193800 extends Migration implements iMigration
{
  public function up(): void
  {
    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'        => 'create_table',
        'table'       => 'test_update_source',
        'schema'      => 'dbo',
        'ifNotExists' => true,
        'columns'     => [
          [
            'name'          => 'kSource',
            'type'          => 'int',
            'autoIncrement' => true,
            'unsigned'      => true,
            'nullable'      => false
          ],
          [
            'name'     => 'cLabel',
            'type'     => 'string',
            'length'   => 64,
            'nullable' => false
          ]
        ],
        'primaryKey' => ['kSource']
      ]
    ]);

    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'        => 'create_table',
        'table'       => 'test_update_target',
        'schema'      => 'dbo',
        'ifNotExists' => true,
        'columns'     => [
          [
            'name'          => 'kTarget',
            'type'          => 'int',
            'autoIncrement' => true,
            'unsigned'      => true,
            'nullable'      => false
          ],
          [
            'name'     => 'cLabel',
            'type'     => 'string',
            'length'   => 64,
            'nullable' => false
          ],
          [
            'name'     => 'cLast_label',
            'type'     => 'string',
            'length'   => 64,
            'nullable' => true
          ],
          [
            'name'     => 'nCounter',
            'type'     => 'int',
            'nullable' => false,
            'default'  => 0
          ]
        ],
        'primaryKey' => ['kTarget']
      ]
    ]);

    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'    => 'insert',
        'table'   => 'test_update_source',
        'schema'  => 'dbo',
        'columns' => ['cLabel'],
        'values'  => [
          ['first'],
          ['second']
        ]
      ]
    ]);

    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'    => 'insert',
        'table'   => 'test_update_target',
        'schema'  => 'dbo',
        'columns' => ['cLabel', 'cLast_label', 'nCounter'],
        'values'  => [
          ['A', null, 0],
          ['B', null, 0]
        ]
      ]
    ]);

    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'   => 'update',
        'table'  => 'test_update_target',
        'schema' => 'dbo',
        'set'    => [
          ['nCounter', SelectBuilder::calc('nCounter', '+', 1)]
        ],
        'where' => ['kTarget', '=', 1],
        'limit' => 1
      ]
    ]);

    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'   => 'update',
        'table'  => 'test_update_target',
        'schema' => 'dbo',
        'set'    => [
          [
            'col'  => 'cLast_label',
            'expr' => [
              'query' => [
                'select'   => 'cLabel',
                'from'     => 'test_update_source',
                'order_by' => 'kSource DESC',
                'limit'    => 1
              ]
            ]
          ],
          ['cLabel', 'A-updated']
        ],
        'where' => ['kTarget', '=', 1],
        'limit' => 1
      ]
    ]);
  }

  public function down(): void
  {
    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'     => 'drop_table',
        'table'    => 'test_update_target',
        'schema'   => 'dbo',
        'ifExists' => true
      ]
    ]);

    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'     => 'drop_table',
        'table'    => 'test_update_source',
        'schema'   => 'dbo',
        'ifExists' => true
      ]
    ]);
  }
}

