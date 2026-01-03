<?php

declare(strict_types=1);

namespace test\mix;

use Braesident\DbMigration\iMigration;
use Braesident\DbMigration\Migration;

/**
 * Test-Migration für PHP/JSON-Abgleich.
 */
final class Migration20251229193000 extends Migration implements iMigration
{
  public function up(): void
  {
    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'        => 'create_table',
        'table'       => 'test_migration',
        'schema'      => 'dbo',
        'ifNotExists' => true,
        'columns'     => [
          [
            'name'          => 'kTest_migration',
            'type'          => 'bigint',
            'nullable'      => false,
            'autoIncrement' => true
          ],
          [
            'name'     => 'cName',
            'type'     => 'string',
            'length'   => 255,
            'nullable' => false
          ],
          [
            'name'     => 'bActive',
            'type'     => 'boolean',
            'nullable' => false,
            'default'  => 1
          ],
          [
            'name'     => 'dCreated',
            'type'     => 'datetime',
            'nullable' => false,
            'default'  => 'CURRENT_TIMESTAMP'
          ]
        ],
        'primaryKey' => ['kTest_migration']
      ]
    ]);
  }

  public function down(): void
  {
    $this->execute([
      'mysql'  => 'DROP TABLE IF EXISTS `test_migration`',
      'sqlsrv' => "IF OBJECT_ID(N'[dbo].[test_migration]', N'U') IS NOT NULL DROP TABLE [dbo].[test_migration]"
    ]);
  }
}
