<?php

declare(strict_types=1);

namespace test\php;

use Braesident\DbMigration\iMigration;
use Braesident\DbMigration\Migration;
use Braesident\DbMigration\SelectBuilder;

final class Migration20251229193600 extends Migration implements iMigration
{
  public function up(): void
  {
    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'    => 'create_view',
        'view'    => 'view_test_migration_summary',
        'schema'  => 'dbo',
        'replace' => true,
        'query'   => [
          'select' => [
            ['col' => 'tm.kTest_migration', 'as' => 'kTest_migration'],
            ['expr' => SelectBuilder::fn('coalesce', 'tm.cTitle', SelectBuilder::val('')), 'as' => 'cTitle'],
            ['expr' => SelectBuilder::fn('length', SelectBuilder::fn('coalesce', 'tm.cTitle', SelectBuilder::val(''))), 'as' => 'nTitle_len'],
            ['col' => 'tm.bActive', 'as' => 'bActive'],
            ['col' => 'tm.dCreated', 'as' => 'dCreated'],
            ['expr' => SelectBuilder::fn('date_format', 'tm.dCreated', '%Y-%m-%d'), 'as' => 'dCreated_iso'],
            ['expr' => SelectBuilder::fn('ifnull', 'tmc.cName', SelectBuilder::val('')), 'as' => 'cCategory']
          ],
          'from' => [
            'table' => 'test_migration_log',
            'as'    => 'tm'
          ],
          'left_join' => [
            [
              'table' => 'test_migration_category',
              'as'    => 'tmc',
              'on'    => ['tmc.kTest_migration_category', '=', 'tm.kTest_migration_category']
            ]
          ]
        ]
      ]
    ]);

    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'    => 'create_view',
        'view'    => 'view_test_migration_active',
        'schema'  => 'dbo',
        'replace' => true,
        'query'   => [
          'select' => [
            ['col' => 'tm.kTest_migration', 'as' => 'kTest_migration'],
            ['expr' => SelectBuilder::fn('coalesce', 'tm.cTitle', SelectBuilder::val('')), 'as' => 'cTitle'],
            ['expr' => SelectBuilder::fn('date_format', 'tm.dCreated', '%Y-%m-%d'), 'as' => 'dCreated_iso']
          ],
          'from' => [
            'table' => 'test_migration_log',
            'as'    => 'tm'
          ],
          'where' => ['tm.bActive', '=', 1]
        ]
      ]
    ]);
  }

  public function down(): void
  {
    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'     => 'drop_view',
        'view'     => 'view_test_migration_active',
        'schema'   => 'dbo',
        'ifExists' => true
      ]
    ]);

    $this->execute((object) [
      'builder'    => 'sql',
      'definition' => [
        'type'     => 'drop_view',
        'view'     => 'view_test_migration_summary',
        'schema'   => 'dbo',
        'ifExists' => true
      ]
    ]);
  }
}
