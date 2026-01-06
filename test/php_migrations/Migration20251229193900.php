<?php

declare(strict_types=1);

namespace test\php;

use Braesident\DbMigration\iMigration;
use Braesident\DbMigration\Migration;

final class Migration20251229193900 extends Migration implements iMigration
{
  public function up(): void
  {
    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'        => 'create_table',
        'table'       => 'test_update_join_source',
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
            'length'   => 32,
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
        'table'       => 'test_update_join_target',
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
            'name'     => 'kSource',
            'type'     => 'int',
            'nullable' => false
          ],
          [
            'name'     => 'cLabel',
            'type'     => 'string',
            'length'   => 32,
            'nullable' => false
          ],
          [
            'name'     => 'cSource_label',
            'type'     => 'string',
            'length'   => 32,
            'nullable' => true
          ]
        ],
        'primaryKey' => ['kTarget']
      ]
    ]);

    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'    => 'insert',
        'table'   => 'test_update_join_source',
        'schema'  => 'dbo',
        'columns' => ['cLabel'],
        'values'  => [
          ['S1'],
          ['S2']
        ]
      ]
    ]);

    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'    => 'insert',
        'table'   => 'test_update_join_target',
        'schema'  => 'dbo',
        'columns' => [
          'kSource',
          'cLabel',
          'cSource_label'
        ],
        'values' => [
          [1, 'T1', null],
          [2, 'T2', null]
        ]
      ]
    ]);

    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'   => 'update',
        'table'  => 'test_update_join_target',
        'schema' => 'dbo',
        'as'     => 't',
        'join'   => [
          [
            'table' => 'test_update_join_source',
            'as'    => 's',
            'on'    => ['t.kSource', '=', 's.kSource']
          ]
        ],
        'set' => [
          ['cSource_label', ['col' => 's.cLabel']]
        ],
        'where' => ['t.cLabel', '=', ['value' => 'T1']],
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
        'table'    => 'test_update_join_target',
        'schema'   => 'dbo',
        'ifExists' => true
      ]
    ]);

    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'     => 'drop_table',
        'table'    => 'test_update_join_source',
        'schema'   => 'dbo',
        'ifExists' => true
      ]
    ]);
  }
}

