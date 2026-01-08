<?php

declare(strict_types=1);

namespace test\php;

use Braesident\DbMigration\iMigration;
use Braesident\DbMigration\Migration;

final class Migration20251229194100 extends Migration implements iMigration
{
  public function up(): void
  {
    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'        => 'create_table',
        'table'       => 'test_delete_parent',
        'schema'      => 'dbo',
        'ifNotExists' => true,
        'columns'     => [
          [
            'name'          => 'kParent',
            'type'          => 'int',
            'autoIncrement' => true,
            'unsigned'      => true,
            'nullable'      => false
          ],
          [
            'name'     => 'cLabel',
            'type'     => 'string',
            'length'   => 32,
            'nullable' => false
          ]
        ],
        'primaryKey' => ['kParent']
      ]
    ]);

    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'        => 'create_table',
        'table'       => 'test_delete_child',
        'schema'      => 'dbo',
        'ifNotExists' => true,
        'columns'     => [
          [
            'name'          => 'kChild',
            'type'          => 'int',
            'autoIncrement' => true,
            'unsigned'      => true,
            'nullable'      => false
          ],
          [
            'name'     => 'kParent',
            'type'     => 'int',
            'nullable' => false
          ],
          [
            'name'     => 'cLabel',
            'type'     => 'string',
            'length'   => 32,
            'nullable' => true
          ]
        ],
        'primaryKey' => ['kChild']
      ]
    ]);

    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'    => 'insert',
        'table'   => 'test_delete_parent',
        'schema'  => 'dbo',
        'columns' => ['cLabel'],
        'values'  => [
          ['P1'],
          ['P2']
        ]
      ]
    ]);

    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'    => 'insert',
        'table'   => 'test_delete_child',
        'schema'  => 'dbo',
        'columns' => [
          'kParent',
          'cLabel'
        ],
        'values' => [
          [1, 'C1'],
          [1, 'C2'],
          [2, 'C3']
        ]
      ]
    ]);

    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'   => 'delete',
        'table'  => 'test_delete_child',
        'schema' => 'dbo',
        'as'     => 'c',
        'join'   => [
          [
            'table' => 'test_delete_parent',
            'as'    => 'p',
            'on'    => ['c.kParent', '=', 'p.kParent']
          ]
        ],
        'where' => ['p.cLabel', '=', ['value' => 'P1']]
      ]
    ]);

    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'   => 'delete',
        'table'  => 'test_delete_parent',
        'schema' => 'dbo',
        'where'  => ['cLabel', '=', ['value' => 'P2']],
        'limit'  => 1
      ]
    ]);
  }

  public function down(): void
  {
    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'     => 'drop_table',
        'table'    => 'test_delete_child',
        'schema'   => 'dbo',
        'ifExists' => true
      ]
    ]);

    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'     => 'drop_table',
        'table'    => 'test_delete_parent',
        'schema'   => 'dbo',
        'ifExists' => true
      ]
    ]);
  }
}
