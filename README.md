# DbMigration - Actions Overview

Dieses Composer-Package unterstuetzt Migrationen als PHP-Klassen und JSON-Definitionen.
Die JSON-Variante nutzt einen Builder (SqlBuilder) und kann Dialekt-SQL für mysql/sqlsrv erzeugen.

## JSON-Format (Kurzübersicht)

- Datei: `MigrationYYYYMMDDHHMMSS.json`
- Felder: `up` und `down` (jeweils Liste von Steps)
- Step:
  - Builder-Step: `{ "builder": "sql", "definition": { ... } }`
  - Dialekt-SQL: `{ "mysql": "...", "sqlsrv": "...", "sql": "...", "default": "..." }`

## Builder-Definitionen

- `create_table`: Tabelle erzeugen (Spalten, PK, Unique, Indexe, FKs, Checks)
- `drop_table`: Tabelle löschen
- `alter_table`: Tabelle verändern (Actions, siehe unten)
- `create_view`: View erzeugen/ersetzen
- `drop_view`: View löschen
- `raw`: Dialekt-SQL direkt

## alter_table Actions

- `add_column`: Spalte hinzufügen (optional: `after`, `first` fuer mysql)
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
- Views: `create_view` nutzt in mysql `CREATE OR REPLACE VIEW`. In sqlsrv wird bei `replace=true` zuerst eine Dummy-View erzeugt und dann `ALTER VIEW` genutzt.
- Views: `select` kann ein String, ein String-Array oder ein Dialekt-Map sein (z.B. `{ "mysql": [...], "sqlsrv": [...] }`).
- Views: Alternativ kann `query` verwendet werden (JSON-Query-Builder). Dann wird der SELECT aus der Struktur gebaut.

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

Zulaessige Varianten:
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

### expr (Ausdruecke)

Zulaessige Formen (als `expr` oder direkt in select/where etc.):
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
      { "cond": ["o.eStatus", "=", "begun"], "then": { "value": "started" } }
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
"where": ["o.eStatus", "=", "begun"]
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

- `test/json_migrations/`
  - `Migration20251229193000.json`: create_table + drop_table (down)
  - `Migration20251229193100.json`: create_table + add_unique
  - `Migration20251229193200.json`: add_column + add_index + add_foreign_key
  - `Migration20251229193300.json`: modify_column + rename_column + add_check
  - `Migration20251229193400.json`: create_table + add_primary_key
  - `Migration20251229193500.json`: rename_table
  - `Migration20251229193600.json`: create_view (summary/active)
