<?php

declare(strict_types=1);

namespace test\php;

use Braesident\DbMigration\iMigration;
use Braesident\DbMigration\Migration;

final class Migration20251229194000 extends Migration implements iMigration
{
  public function up(): void
  {
    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'        => 'create_table',
        'table'       => 'test_comment',
        'schema'      => 'dbo',
        'ifNotExists' => true,
        'columns'     => [
          [
            'name'          => 'kTest_comment',
            'type'          => 'int',
            'autoIncrement' => true,
            'unsigned'      => true,
            'nullable'      => false
          ],
          [
            'name'     => 'bType',
            'type'     => 'tinyint',
            'length'   => 1,
            'nullable' => false,
            'comment'  => '0=history/1=note'
          ],
          [
            'name'     => 'cText',
            'type'     => 'string',
            'length'   => 255,
            'nullable' => true
          ]
        ],
        'primaryKey' => ['kTest_comment']
      ]
    ]);
  }

  public function down(): void
  {
    $this->execute([
      'builder'    => 'sql',
      'definition' => [
        'type'     => 'drop_table',
        'table'    => 'test_comment',
        'schema'   => 'dbo',
        'ifExists' => true
      ]
    ]);
  }
}

