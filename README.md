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

## Beispiel-Migrationen (Tests)

Die Testmigrationen zeigen unterschiedliche Actions und Notationen (Objekt/String).

- `test/php_migrations/`
  - `Migration20251229193000.php`: create_table (test_migration)
  - `Migration20251229193100.php`: create_table + add_unique
  - `Migration20251229193200.php`: add_column + add_index + add_foreign_key
  - `Migration20251229193300.php`: modify_column + rename_column + add_check
  - `Migration20251229193400.php`: create_table + add_primary_key
  - `Migration20251229193500.php`: rename_table

- `test/json_migrations/`
  - `Migration20251229193000.json`: create_table + drop_table (down)
  - `Migration20251229193100.json`: create_table + add_unique
  - `Migration20251229193200.json`: add_column + add_index + add_foreign_key
  - `Migration20251229193300.json`: modify_column + rename_column + add_check
  - `Migration20251229193400.json`: create_table + add_primary_key
  - `Migration20251229193500.json`: rename_table
