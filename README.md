# NanoPatcher

**Version 1.1.0**

NanoPatcher is a tiny XML-driven SQL and PHP patch runner.

It executes SQL scripts and PHP patches in a deterministic order and tracks execution history using XML files.

The project is intentionally simple:

- No migration tables
- No framework dependencies
- No ORM
- No command generation
- No rollback system
- No hidden magic
- Human-readable execution history
- Just XML, SQL, PHP and execution tracking

## SQL Patches

Example:

```sql
CREATE TABLE example (
  id BIGINT PRIMARY KEY
);



COMMENT ON TABLE example IS 'Demo table';
```

NanoPatcher executes SQL files through PDO.

A single SQL file may contain multiple SQL statements.

Statements are separated by **two empty lines**.

Internally NanoPatcher splits SQL files using:

```php
preg_split('/\R\s*\R\s*\R/', trim($sql));
```

and executes every resulting statement separately.

This allows migration files to contain multiple CREATE, ALTER, INSERT, COMMENT, FUNCTION, PROCEDURE and TRIGGER statements while keeping files readable.

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

Execution history is stored in plain XML and can be inspected manually without querying the database.

Path separators are normalized automatically, allowing changesets created on Windows and Linux to work identically.

## Showing Skipped Files

By default NanoPatcher hides already executed files from the output.

```php
$patcher->run();
```

To include skipped files:

```php
$patcher->run(true);
```

Example:

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

## Git Ignore

Recommended `.gitignore`:

```gitignore
db.php

executed/*
!executed/example_changeset.xml
```

This keeps:

- database credentials out of Git
- execution history local to each environment

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
