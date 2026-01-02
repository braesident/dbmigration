<?php

declare(strict_types=1);

// Terminal: php -f migrate.php type=mysql format=php

use Braesident\DbMigration\MigrationManager;
use Braesident\JPdo\JPdo;

require \dirname(__DIR__, 1).'/vendor/autoload.php';

if ($argc > 1) {
  // $argc[0] == file path
  $_GET = $_GET ?? [];
  foreach ($argv as $v) {
    $vs = explode('=', $v, 2);
    if (\count($vs) > 1) {
      $_GET[$vs[0]] = $vs[1];
    } else {
      $_GET[] = $vs[0];
    }
  }
}

$type = $_GET['type'] ?? '';
if ( ! isset($type) || ! \in_array($type, ['mysql', 'sqlsrv'], true)) {
  http_response_code(400);
  exit('set param `type` (mysql|sqlsrv)');
}

if ( ! isset($_GET['format'])) {
  http_response_code(400);
  exit('set param `format` (php|json|mix)');
}

$format = $_GET['format'];
$target = $_GET['target'] ?? $_GET['t'] ?? null;

try {
  switch ($type) {
    case 'mysql':

      $db = JPdo::mysql('localhost', 'migration_test', 'user', 'pw123');
      if ('php' === $format) {
        $mm = new MigrationManager($db, __DIR__.'/php_migrations', 'test\\php');
      } elseif ('json' === $format) {
        $mm = new MigrationManager($db, __DIR__.'/json_migrations', 'test\\json');
      } elseif ('mix' === $format) {
        $mm = new MigrationManager($db, __DIR__.'/json_migrations', 'test\\json');
      }

      break;

    case 'sqlsrv':

      if ( ! isset($_GET['p'])) {
        http_response_code(400);
        exit('set (p)assword param for sqlsrv');
      }

      $db = JPdo::sqlsrv('ip,port\\instance', 'migration_test', 'user', $_GET['p']);
      $mm = new MigrationManager($db);

      break;
  }

  $mm->migrate($target);
} catch (Throwable $th) {
  echo $th->getMessage();
}
