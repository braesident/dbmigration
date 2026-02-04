# DbMigration - Actions Overview

Dieses Composer-Package unterstützt Migrationen als PHP-Klassen und JSON-Definitionen.
Die JSON-Variante nutzt einen Builder (SqlBuilder) und kann Dialekt-SQL für mysql/sqlsrv erzeugen.

## JSON-Format (Kurzübersicht)

- Datei: `MigrationYYYYMMDDHHMMSS.json`
- Felder: `up` und `down` (jeweils Liste von Steps)
- Step:
  - Builder-Step: `{ "builder": "sql", "definition": { ... } }`
  - Dialekt-SQL: `{ "mysql": "...", "sqlsrv": "...", "sql": "...", "default": "..." }`
  - Optional: `only`/`exclude` (oder `dialects`/`include`) begrenzen Steps auf Dialekte, z.B. `{ "only": ["sqlsrv"] }`

## PHP-Notation (Migration vs. Quellcode)

- Migrationen: `$this->execute([...])` akzeptiert Arrays oder `(object)[...]` (JSON-ähnlich).
- Quellcode: mit `SqlRenderer` nutzt du dieselbe Definition ohne Wrapper.

Beispiel Migration:
```php
$this->execute([
  'builder' => 'sql',
  'definition' => [
    'type' => 'insert',
    'table' => 'config',
    'values' => [['cIdentifier' => 'orderid_pad_sign', 'cDefault' => '0']]
  ]
]);
```

Beispiel Quellcode:
```php
$sql = $renderer->render([
  'type' => 'insert',
  'table' => 'config',
  'values' => [['cIdentifier' => 'orderid_pad_sign', 'cDefault' => '0']]
]);
```

## Builder-Definitionen

- `create_table`: Tabelle erzeugen (Spalten, PK, Unique, Indexe, FKs, Checks; optional `comment` pro Spalte)
- `drop_table`: Tabelle löschen
- `alter_table`: Tabelle verändern (Actions, siehe unten)
- `create_view`: View erzeugen/ersetzen
- `drop_view`: View löschen
- `create_trigger`: Trigger erstellen
- `alter_trigger`: Trigger ändern (mysql: DROP + CREATE)
- `drop_trigger`: Trigger entfernen
- `insert`: INSERT (VALUES oder INSERT...SELECT)
- `update`: UPDATE (SET + WHERE)
- `delete`: DELETE (optional JOINs, WHERE, LIMIT)
- `raw`: Dialekt-SQL direkt

## alter_table Actions

- `add_column`: Spalte hinzufügen (optional: `after`, `first` für mysql)
- `modify_column`: Spalte ändern (sqlsrv ohne default/onUpdate/identity)
- `drop_column`: Spalte entfernen
- `rename_column`: Spalte umbenennen
- `rename_table`: Tabelle umbenennen
- `add_index` / `drop_index`: Index hinzufügen/entfernen
- `add_unique` / `drop_unique`: Unique-Constraint hinzufügen/entfernen
- `add_check` / `drop_check`: Check-Constraint hinzufügen/entfernen
- `add_primary_key` / `drop_primary_key`: Primary Key hinzufügen/entfernen
- `add_foreign_key` / `drop_foreign_key`: Foreign Key hinzufügen/entfernen

Hinweise:
- mysql: `rename_table` nutzt `RENAME TABLE`, sqlsrv nutzt `sp_rename`.
- sqlsrv: `drop_primary_key` benötigt den Constraint-Namen.
- sqlsrv: `drop_unique`/`drop_index` können alternativ `columns` nutzen, wenn der Name unbekannt ist (es wird anhand der Spaltenkombination gesucht).
- Views: `create_view` nutzt in mysql `CREATE OR REPLACE VIEW`. In sqlsrv wird bei `replace=true` zuerst eine Dummy-View erzeugt und dann `ALTER VIEW` genutzt.
- Views: `select` kann ein String, ein String-Array oder ein Dialekt-Map sein (z.B. `{ "mysql": [...], "sqlsrv": [...] }`).
- Views: Alternativ kann `query` verwendet werden (JSON-Query-Builder). Dann wird der SELECT aus der Struktur gebaut.
- Trigger: `body` kann ein String, String-Array, Dialekt-Map oder Builder-Definition(en) sein (z.B. `insert`/`update`/`delete`).
- Trigger: `timing` kann dialektspezifisch sein (z.B. `{ "mysql": "before", "sqlsrv": "instead of" }`).

## Datentypen (Hinweise)

- `tinyblob` wird in sqlsrv zu `VARBINARY(255)`.
- `blob`/`mediumblob`/`longblob` werden in sqlsrv zu `VARBINARY(MAX)` (max. 2 GB; MySQL `LONGBLOB` kann bis 4 GB).

## insert

Unterstützt:
- `INSERT ... VALUES` (eine oder mehrere Zeilen)
- `INSERT ... SELECT` (über `select` als SQL oder `query` als AST)

Hinweis (dialekt-neutral):
- Für AUTO_INCREMENT/IDENTITY-Spalten besser die Spalte weglassen oder `DEFAULT` verwenden (SQL Server akzeptiert `NULL` bei IDENTITY nicht).

VALUES (mehrere Zeilen):
```json
{
  "builder": "sql",
  "definition": {
    "type": "insert",
    "table": "config",
    "columns": ["cIdentifier", "cDefault", "cValue"],
    "values": [
      ["orderid_pad_sign", "0", null],
      ["orderid_pad_count", "0", null],
      ["customerid_pad_sign", "0", null],
      ["customerid_pad_count", "0", null]
    ]
  }
}
```

PHP (Migration):
```php
$this->execute([
  'builder' => 'sql',
  'definition' => [
    'type' => 'insert',
    'table' => 'config',
    'columns' => ['cIdentifier', 'cDefault', 'cValue'],
    'values' => [
      ['orderid_pad_sign', '0', null],
      ['orderid_pad_count', '0', null],
      ['customerid_pad_sign', '0', null],
      ['customerid_pad_count', '0', null]
    ]
  ]
]);
```

INSERT...SELECT (inkl. Subquery):
```json
{
  "builder": "sql",
  "definition": {
    "type": "insert",
    "table": "order_cases",
    "columns": ["cPrefix", "nId", "cPostfix"],
    "query": {
      "select": [
        { "value": "" },
        { "query": { "select": "kOrder", "from": "order", "order_by": "kOrder DESC", "limit": 1 } },
        { "value": "" }
      ],
      "where": [
        { "query": { "select": { "raw": "COUNT(*)" }, "from": "order" } },
        ">",
        0
      ]
    }
  }
}
```

PHP (Migration):
```php
$this->execute([
  'builder' => 'sql',
  'definition' => [
    'type' => 'insert',
    'table' => 'order_cases',
    'columns' => ['cPrefix', 'nId', 'cPostfix'],
    'query' => [
      'select' => [
        ['value' => ''],
        ['query' => ['select' => 'kOrder', 'from' => 'order', 'order_by' => 'kOrder DESC', 'limit' => 1]],
        ['value' => '']
      ],
      'where' => [
        ['query' => ['select' => ['raw' => 'COUNT(*)'], 'from' => 'order']],
        '>',
        0
      ]
    ]
  ]
]);
```

## update

Unterstützt:
- `UPDATE ... SET ... WHERE ...` (optional: `limit` / sqlsrv: `TOP`)
- Join-Updates über `join`/`left_join`/`right_join` (MySQL: `UPDATE ... JOIN ...`, SQL Server: `UPDATE ... FROM ... JOIN ...`)

Hinweis:
- Strings in `set` sind immer Literale. Für Spalten/Expressions nutze z.B. `{ "col": "o.kOrder" }`, `{ "raw": "o.kOrder" }` oder Expr-Objekte (`op`, `fn`, `case`, ...).

SET als Objekt (Spalte => Wert):
```json
{
  "builder": "sql",
  "definition": {
    "type": "update",
    "table": "config",
    "set": {
      "cValue": "1"
    },
    "where": {
      "cIdentifier": "orderid_pad_sign"
    }
  }
}
```

PHP (Migration):
```php
$this->execute([
  'builder' => 'sql',
  'definition' => [
    'type' => 'update',
    'table' => 'config',
    'set' => [
      'cValue' => '1'
    ],
    'where' => [
      'cIdentifier' => 'orderid_pad_sign'
    ]
  ]
]);
```

SET mit Ausdruck + Subquery:
```json
{
  "builder": "sql",
  "definition": {
    "type": "update",
    "table": "order_cases",
    "set": {
      "nId": {
        "query": {
          "select": "kOrder",
          "from": "order",
          "order_by": "kOrder DESC",
          "limit": 1
        }
      }
    },
    "where": [
      { "query": { "select": { "raw": "COUNT(*)" }, "from": "order" } },
      ">",
      0
    ]
  }
}
```

PHP (Migration):
```php
$this->execute([
  'builder' => 'sql',
  'definition' => [
    'type' => 'update',
    'table' => 'order_cases',
    'set' => [
      'nId' => [
        'query' => [
          'select' => 'kOrder',
          'from' => 'order',
          'order_by' => 'kOrder DESC',
          'limit' => 1
        ]
      ]
    ],
    'where' => [
      ['query' => ['select' => ['raw' => 'COUNT(*)'], 'from' => 'order']],
      '>',
      0
    ]
  ]
]);
```

Join-Update:
```json
{
  "builder": "sql",
  "definition": {
    "type": "update",
    "table": "order_cases",
    "as": "oc",
    "join": [
      {
        "table": "order",
        "as": "o",
        "on": ["o.kOrder", "=", "oc.nId"]
      }
    ],
    "set": {
      "cPrefix": "",
      "cPostfix": "",
      "nId": { "col": "o.kOrder" }
    },
    "where": ["o.eStatus", "<>", { "value": "deleted" }],
    "limit": 10
  }
}
```

PHP (Migration):
```php
$this->execute([
  'builder' => 'sql',
  'definition' => [
    'type' => 'update',
    'table' => 'order_cases',
    'as' => 'oc',
    'join' => [
      [
        'table' => 'order',
        'as' => 'o',
        'on' => ['o.kOrder', '=', 'oc.nId']
      ]
    ],
    'set' => [
      'cPrefix' => '',
      'cPostfix' => '',
      'nId' => ['col' => 'o.kOrder']
    ],
    'where' => ['o.eStatus', '<>', ['value' => 'deleted']],
    'limit' => 10
  ]
]);
```

## delete

Unterstützt:
- `DELETE ... WHERE ...` (optional: `limit` / sqlsrv: `TOP`)
- Join-Deletes über `join`/`left_join`/`right_join` (MySQL: `DELETE alias FROM ... JOIN ...`, SQL Server: `DELETE alias FROM ... JOIN ...`)

Einfaches DELETE:
```json
{
  "builder": "sql",
  "definition": {
    "type": "delete",
    "table": "test_delete_parent",
    "where": ["cLabel", "=", { "value": "P2" }],
    "limit": 1
  }
}
```

PHP (Migration):
```php
$this->execute([
  'builder' => 'sql',
  'definition' => [
    'type' => 'delete',
    'table' => 'test_delete_parent',
    'where' => ['cLabel', '=', ['value' => 'P2']],
    'limit' => 1
  ]
]);
```

Join-Delete:
```json
{
  "builder": "sql",
  "definition": {
    "type": "delete",
    "table": "test_delete_child",
    "as": "c",
    "join": [
      {
        "table": "test_delete_parent",
        "as": "p",
        "on": ["c.kParent", "=", "p.kParent"]
      }
    ],
    "where": ["p.cLabel", "=", { "value": "P1" }]
  }
}
```

PHP (Migration):
```php
$this->execute([
  'builder' => 'sql',
  'definition' => [
    'type' => 'delete',
    'table' => 'test_delete_child',
    'as' => 'c',
    'join' => [
      [
        'table' => 'test_delete_parent',
        'as' => 'p',
        'on' => ['c.kParent', '=', 'p.kParent']
      ]
    ],
    'where' => ['p.cLabel', '=', ['value' => 'P1']]
  ]
]);
```

## trigger

CREATE TRIGGER mit dialektspezifischem `body` (MySQL nutzt `OLD`, SQL Server `deleted`):
```json
{
  "builder": "sql",
  "definition": {
    "type": "create_trigger",
    "name": "trg_test_trigger_source_delete",
    "table": "test_trigger_source",
    "schema": "dbo",
    "timing": "after",
    "event": "delete",
    "body": {
      "mysql": {
        "type": "insert",
        "table": "test_trigger_log",
        "columns": ["kSource", "cAction"],
        "values": [[{ "raw": "OLD.kSource" }, "deleted"]]
      },
      "sqlsrv": {
        "type": "insert",
        "table": "test_trigger_log",
        "columns": ["kSource", "cAction"],
        "query": {
          "select": ["kSource", { "value": "deleted" }],
          "from": "deleted"
        }
      }
    }
  }
}
```

PHP (Migration):
```php
$this->execute([
  'builder' => 'sql',
  'definition' => [
    'type' => 'create_trigger',
    'name' => 'trg_test_trigger_source_delete',
    'table' => 'test_trigger_source',
    'schema' => 'dbo',
    'timing' => 'after',
    'event' => 'delete',
    'body' => [
      'mysql' => [
        'type' => 'insert',
        'table' => 'test_trigger_log',
        'columns' => ['kSource', 'cAction'],
        'values' => [[['raw' => 'OLD.kSource'], 'deleted']]
      ],
      'sqlsrv' => [
        'type' => 'insert',
        'table' => 'test_trigger_log',
        'columns' => ['kSource', 'cAction'],
        'query' => [
          'select' => ['kSource', ['value' => 'deleted']],
          'from' => 'deleted'
        ]
      ]
    ]
  ]
]);
```

## SqlRenderer

Für normale Projekt‑Queries (außerhalb von Migrationen). Rendert SQL aus Builder‑Definitionen, Dialekt‑Maps oder SELECT‑ASTs.

Minimal:
```php
use Braesident\DbMigration\SqlRenderer;

$renderer = new SqlRenderer($pdo);
$sql = $renderer->render([
  'select' => ['*'],
  'from' => 'extensions',
  'where' => ['eStatus', '=', ['raw' => ':status']]
]);
```

Mit Schema (SQL Server):
```php
$sql = $renderer->render([
  'select' => ['*'],
  'from' => ['schema' => 'dbo', 'table' => 'view_account']
]);
```

Mehrere Statements (z. B. Trigger/Kommentare):
```php
$statements = $renderer->renderAll($definition);
foreach ($statements as $sql) {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
}
```

Dialekt‑Map (raw SQL):
```php
$sql = $renderer->render([
  'mysql' => 'SELECT NOW()',
  'sqlsrv' => 'SELECT SYSDATETIME()'
]);
```

Schema‑Hinweis (SQL Server):
- Builder‑Definitionen (z. B. `create_table`, `create_view`, `insert`, `update`, `delete`) unterstützen `schema` direkt am Root.
- SELECT‑ASTs lesen `schema` nicht am Root. Nutze stattdessen:
  - `from: ['table' => 'view_account', 'schema' => 'dbo']`
  - oder `from: 'dbo.view_account'`

WhereBuilder (kompakte WHERE + Params):
```php
use Braesident\DbMigration\WhereBuilder;

$where = new WhereBuilder();
$where->andNotEquals('o.eStatus', 'deleted');
$where->andParam('o.kAccount', '=', (int) $accountId, 'kaccount');
$where->andIn('o.eStatus', $statusFilter, 'status');
$where->orIn('o.eStatus', $fallbackFilter, 'status_fallback');

$objTotals['where'] = $where->conditions();
$params = $where->params();
```

Weitere Helper (Vergleiche + Kurzformen):
```php
$where->andLt('o.nTotal', 10);
$where->orGte('o.nTotal', 100);
$where->andLtParam('o.nTotal', 10, 'min_total');
$where->andGteParam('o.nTotal', 100, 'max_total');
```

Gruppierung:
```php
$where = new WhereBuilder();
$where->andEquals('a', 1)
      ->andEquals('b', 2)
      ->orEquals('c', 3)
      ->orGroup([
        ['d', '=', 4],
        ['e', '=', 5]
      ]);
```

Builder‑Definition:
```php
$sql = $renderer->render([
  'type' => 'update',
  'table' => 'extensions',
  'set' => ['eStatus' => ['raw' => ':status']],
  'where' => ['kExtension', '=', ['raw' => ':id']]
]);
```

## SelectBuilder (Kurzform)

JSON (query-basierter View):
```json
{
  "builder": "sql",
  "definition": {
    "type": "create_view",
    "view": "view_material_product",
    "replace": true,
    "query": {
      "select": [
        "mp.*",
        { "expr": { "op": "+", "left": "mp.nStock", "right": { "fn": "ifnull", "args": [ { "fn": "sum", "args": ["su.nQuantity"] }, 0 ] } }, "as": "nTotal_stock" },
        { "col": "mc.cName", "as": "cOrigin" }
      ],
      "from": { "table": "material_product", "as": "mp" },
      "left_join": [
        { "table": "storage_unit", "as": "su", "on": ["mp.kMaterial_product", "=", "su.kProduct"] },
        { "table": "material_category", "as": "mc", "on": ["mc.kMaterial_category", "=", "mp.kCategory"] }
      ],
      "group_by": ["mp.kMaterial_product"]
    }
  }
}
```

PHP (Migration, Builder-Definition):
```php
$this->execute([
  'builder' => 'sql',
  'definition' => [
    'type' => 'create_view',
    'view' => 'view_material_product',
    'replace' => true,
    'query' => [
      'select' => [
        'mp.*',
        ['expr' => ['op' => '+', 'left' => 'mp.nStock', 'right' => ['fn' => 'ifnull', 'args' => [['fn' => 'sum', 'args' => ['su.nQuantity']], 0]]], 'as' => 'nTotal_stock'],
        ['col' => 'mc.cName', 'as' => 'cOrigin']
      ],
      'from' => ['table' => 'material_product', 'as' => 'mp'],
      'left_join' => [
        ['table' => 'storage_unit', 'as' => 'su', 'on' => ['mp.kMaterial_product', '=', 'su.kProduct']],
        ['table' => 'material_category', 'as' => 'mc', 'on' => ['mc.kMaterial_category', '=', 'mp.kCategory']]
      ],
      'group_by' => ['mp.kMaterial_product']
    ]
  ]
]);
```

PHP (fluent API):
```php
$sb = new SelectBuilder('mysql');
$sb->select('mp.*')
   ->select(SelectBuilder::calc('mp.nStock', '+', SelectBuilder::fn('ifnull', SelectBuilder::fn('sum', 'su.nQuantity'), 0)), 'nTotal_stock')
   ->select('mc.cName', 'cOrigin')
   ->from('material_product', 'mp')
   ->leftJoin('storage_unit', 'su', ['mp.kMaterial_product', '=', 'su.kProduct'])
   ->leftJoin('material_category', 'mc', ['mc.kMaterial_category', '=', 'mp.kCategory'])
   ->groupBy('mp.kMaterial_product');
$sql = $sb->statement();
```

## SelectBuilder - Query-Strukturen

### select

Zulässige Varianten:
```json
"select": "mp.*"
```
```json
"select": ["mp.*", "mc.cName AS cOrigin"]
```
```json
"select": ["mc.cName", "cOrigin"]
```
```json
"select": { "col": "mc.cName", "as": "cOrigin" }
```
```json
"select": { "expr": { "fn": "ifnull", "args": ["mc.cName", { "value": "" }] }, "as": "cOrigin" }
```
```json
"select": [
  "mp.*",
  ["mc.cName", "cOrigin"],
  { "expr": { "fn": "coalesce", "args": ["mc.cName", { "value": "" }] }, "as": "cOrigin" }
]
```

### expr (Ausdrücke)

Zulässige Formen (als `expr` oder direkt in select/where etc.):
```json
"expr": "mp.nStock"
```
```json
"expr": 123
```
```json
"expr": { "value": "text" }
```
```json
"expr": { "raw": "COUNT(*)" }
```
```json
"expr": { "col": "mc.cName" }
```
```json
"expr": { "fn": "sum", "args": ["su.nQuantity"] }
```
```json
"expr": { "op": "+", "left": "mp.nStock", "right": 1 }
```
```json
"expr": { "calc": { "col": "mp.nStock", "op": "+", "is_null": { "cond": { "sum": "su.nQuantity" }, "then": 0 } } }
```
```json
"expr": {
  "case": {
    "when": [
      { "cond": ["o.eStatus", "=", { "value": "begun" }], "then": { "value": "started" } }
    ],
    "else": { "value": "" }
  }
}
```

### from

```json
"from": "material_product"
```
```json
"from": { "table": "material_product", "as": "mp" }
```
```json
"from": { "query": { "select": "x", "from": "tbl" }, "as": "sub" }
```

### join / left_join / right_join

```json
"left_join": "storage_unit"
```
```json
"left_join": {
  "table": "storage_unit",
  "as": "su",
  "on": ["mp.kMaterial_product", "=", "su.kProduct"]
}
```
```json
"left_join": [
  {
    "table": "storage_unit",
    "as": "su",
    "on": ["mp.kMaterial_product", "=", "su.kProduct"]
  },
  {
    "query": { "select": "kContact", "from": "contact" },
    "as": "c",
    "on": ["c.kContact", "=", "o.kContact"]
  }
]
```

### where / having

```json
"where": ["o.eStatus", "=", { "value": "begun" }]
```
```json
"where": {
  "and": [
    ["o.eStatus", "!=", "deleted"],
    { "or": [
      ["o.kAccount", ">", 0],
      { "is_null": "o.kAccount" }
    ] }
  ]
}
```
```json
"where": { "between": { "expr": "o.dOrder_date", "min": "2024-01-01", "max": "2024-12-31" } }
```
```json
"where": { "exists": { "select": "1", "from": "order_log", "where": ["kOrder", "=", "o.kOrder"] } }
```

### group_by

```json
"group_by": "mp.kMaterial_product"
```
```json
"group_by": ["mp.kMaterial_product", "mc.cName"]
```

### order_by

```json
"order_by": "o.dOrder_date DESC"
```
```json
"order_by": ["o.dOrder_date", "DESC"]
```
```json
"order_by": [
  { "expr": "o.dOrder_date", "dir": "DESC" },
  ["o.cOrder_id", "ASC"]
]
```

## Beispiel-Migrationen (Tests)

Die Testmigrationen zeigen unterschiedliche Actions und Notationen (Objekt/String).

- `test/php_migrations/`
  - `Migration20251229193000.php`: create_table (test_migration)
  - `Migration20251229193100.php`: create_table + add_unique
  - `Migration20251229193200.php`: add_column + add_index + add_foreign_key
  - `Migration20251229193300.php`: modify_column + rename_column + add_check
  - `Migration20251229193400.php`: create_table + add_primary_key
  - `Migration20251229193500.php`: rename_table
  - `Migration20251229193600.php`: create_view (summary/active)
  - `Migration20251229193700.php`: insert (VALUES + INSERT...SELECT)
  - `Migration20251229193800.php`: update (SET + WHERE + Subquery)
  - `Migration20251229193900.php`: update (JOIN-Update)
  - `Migration20251229194000.php`: create_table (column comments)
  - `Migration20251229194100.php`: delete (WHERE + JOIN)
  - `Migration20251229194200.php`: create_trigger (DELETE -> log)

- `test/json_migrations/`
  - `Migration20251229193000.json`: create_table + drop_table (down)
  - `Migration20251229193100.json`: create_table + add_unique
  - `Migration20251229193200.json`: add_column + add_index + add_foreign_key
  - `Migration20251229193300.json`: modify_column + rename_column + add_check
  - `Migration20251229193400.json`: create_table + add_primary_key
  - `Migration20251229193500.json`: rename_table
  - `Migration20251229193600.json`: create_view (summary/active)
  - `Migration20251229193700.json`: insert (VALUES + INSERT...SELECT)
  - `Migration20251229193800.json`: update (SET + WHERE + Subquery)
  - `Migration20251229193900.json`: update (JOIN-Update)
  - `Migration20251229194000.json`: create_table (column comments)
  - `Migration20251229194100.json`: delete (WHERE + JOIN)
  - `Migration20251229194200.json`: create_trigger (DELETE -> log)
