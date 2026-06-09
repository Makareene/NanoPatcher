# NanoPatcher

NanoPatcher is a tiny XML-driven SQL and PHP patch runner.

It executes SQL scripts and PHP patches in a deterministic order and tracks execution history using XML files.

The project is intentionally simple:

- No migration tables
- No framework dependencies
- No ORM
- No command generation
- No rollback system
- No hidden magic
- Just XML, SQL, PHP and execution tracking

## Requirements

- PHP 8.0+
- PDO
- Database driver (PostgreSQL, MySQL, etc.)

## Project Structure

```text
NanoPatcher/
├── .gitignore
├── db.php
├── index.php
├── NanoPatcher.php
├── README.md
├── changeset/
│   ├── example_changeset.xml
│   ├── project_changeset1.xml
│   ├── project_changeset2.xml
│   └── project_changeset10.xml
├── executed/
│   └── example_changeset.xml
├── sql/
│   ├── create_table.sql
│   └── update_data.sql
└── php/
    ├── migrate_data.php
    └── rebuild_cache.php
```

## Quick Start

Create `db.php`:

```php
<?php

return [
  'dsn'      => 'pgsql:host=localhost;port=5432;dbname=my_database',
  'username' => 'postgres',
  'password' => 'password',
  'options'  => [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  ],
];
```

Create a changeset:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<changeset>
  <file type="sql" url="create_table.sql"/>
  <file type="php" url="migrate_data.php"/>
</changeset>
```

Run NanoPatcher:

```bash
php index.php
```

## Changesets

NanoPatcher automatically scans the `changeset` directory.

Only files matching the pattern below are executed:

```text
*_changeset{N}.xml
```

Examples:

```text
project_changeset1.xml
project_changeset2.xml
project_changeset10.xml
```

Files are executed in numeric order:

```text
1
2
10
```

not:

```text
1
10
2
```

## Example File

The file:

```text
example_changeset.xml
```

is intentionally ignored.

It exists only as a template and documentation example.

Because it has no numeric suffix, NanoPatcher never executes it.

## Changeset Format

Example:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<changeset>
  <file type="sql" url="create_table.sql"/>
  <file type="php" url="migrate_data.php"/>
</changeset>
```

Supported types:

| Type | Directory |
|--------|--------|
| sql | sql/ |
| php | php/ |

## SQL Patches

Example:

```sql
CREATE TABLE example (
  id BIGINT PRIMARY KEY
);
```

SQL files are executed through:

```php
$pdo->exec($sql);
```

## PHP Patches

Example:

```php
<?php

return function(PDO $pdo): void {
  $pdo->exec("
    INSERT INTO example (
      id
    )
    VALUES (
      1
    )
  ");
};
```

PHP patches must return a callable.

NanoPatcher automatically executes the callable and passes the active PDO connection.

## Execution Tracking

For every changeset NanoPatcher creates a corresponding file inside the `executed` directory.

Example:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<changeset>
  <file
    type="sql"
    url="create_table.sql"
    at="2026-06-09 16:37:22"
  />
  <file
    type="php"
    url="migrate_data.php"
    at="2026-06-09 16:37:25"
  />
</changeset>
```

A file is considered executed when a matching record exists:

- type
- url

If found, the file is skipped.

## Execution Flow

```text
Scan changeset directory
    ↓
Sort changesets by number
    ↓
Load changeset XML
    ↓
Check executed XML
    ↓
Execute SQL/PHP file
    ↓
Save execution timestamp
    ↓
Continue
```

## Success Example

```php
Array
(
    [code] => SUCCESS
    [description] => NanoPatcher completed successfully.
)
```

## Skip Example

```php
Array
(
    [code] => SKIPPED
    [type] => sql
    [url] => create_table.sql
    [at] => 2026-06-09 16:37:22
    [description] => Already executed.
)
```

## Error Example

```php
Array
(
    [code] => EXECUTION_ERROR
    [type] => sql
    [url] => broken.sql
    [description] => SQLSTATE[42601]...
)
```

When an error occurs:

- execution stops immediately
- the failed file is not marked as executed
- remaining changesets are not processed

## Git Ignore

Recommended `.gitignore`:

```gitignore
db.php

executed/*
!executed/example_changeset.xml
```

This keeps:

- database credentials out of Git
- execution history local to each project

while preserving the example file.

## Philosophy

NanoPatcher is not intended to compete with Liquibase, Flyway or Doctrine Migrations.

Its goal is different:

- Small
- Simple
- Transparent
- Easy to understand
- Easy to modify

If you can read XML, SQL and PHP, you can understand the entire project in a few minutes.

## License

MIT License