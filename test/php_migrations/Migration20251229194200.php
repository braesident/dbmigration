<?php

declare(strict_types=1);

namespace test\php;

use Braesident\DbMigration\iMigration;
use Braesident\DbMigration\Migration;

final class Migration20251229194200 extends Migration implements iMigration
{
  public function up(): void
  {
    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'        => 'create_table',
        'table'       => 'test_trigger_source',
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
        'table'       => 'test_trigger_log',
        'schema'      => 'dbo',
        'ifNotExists' => true,
        'columns'     => [
          [
            'name'          => 'kLog',
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
            'name'     => 'cAction',
            'type'     => 'string',
            'length'   => 16,
            'nullable' => false
          ],
          [
            'name'     => 'tCreated',
            'type'     => 'timestamp',
            'nullable' => false,
            'default'  => 'CURRENT_TIMESTAMP'
          ]
        ],
        'primaryKey' => ['kLog']
      ]
    ]);

    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'   => 'create_trigger',
        'name'   => 'trg_test_trigger_source_delete',
        'table'  => 'test_trigger_source',
        'schema' => 'dbo',
        'timing' => 'after',
        'event'  => 'delete',
        'body'   => [
          'mysql' => [
            'type'    => 'insert',
            'table'   => 'test_trigger_log',
            'schema'  => 'dbo',
            'columns' => ['kSource', 'cAction'],
            'values'  => [
              [
                ['raw' => 'OLD.kSource'],
                'deleted'
              ]
            ]
          ],
          'sqlsrv' => [
            'type'    => 'insert',
            'table'   => 'test_trigger_log',
            'schema'  => 'dbo',
            'columns' => ['kSource', 'cAction'],
            'query'   => [
              'select' => [
                'kSource',
                ['value' => 'deleted']
              ],
              'from' => 'deleted'
            ]
          ]
        ]
      ]
    ]);

    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'    => 'insert',
        'table'   => 'test_trigger_source',
        'schema'  => 'dbo',
        'columns' => ['cLabel'],
        'values'  => [
          ['S1'],
          ['S2']
        ]
      ]
    ]);

    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'   => 'delete',
        'table'  => 'test_trigger_source',
        'schema' => 'dbo',
        'where'  => ['cLabel', '=', ['value' => 'S1']]
      ]
    ]);
  }

  public function down(): void
  {
    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'     => 'drop_trigger',
        'name'     => 'trg_test_trigger_source_delete',
        'schema'   => 'dbo',
        'ifExists' => true
      ]
    ]);

    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'     => 'drop_table',
        'table'    => 'test_trigger_log',
        'schema'   => 'dbo',
        'ifExists' => true
      ]
    ]);

    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'     => 'drop_table',
        'table'    => 'test_trigger_source',
        'schema'   => 'dbo',
        'ifExists' => true
      ]
    ]);
  }
}
