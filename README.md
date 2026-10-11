# NanoPatcher

**Version 1.1.1**

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

## Changesets and XML Validation

Numbered changesets use filenames such as `application_changeset1.xml` and `application_changeset2.xml`. Files inside each changeset run in their listed order.

The repository includes `NanoPatcher.xsd` in its root, next to `NanoPatcher.php`. Each changeset selects its schema through the root element's `xsi:noNamespaceSchemaLocation` attribute.

Relative schema paths such as `schemas/NanoPatcher.xsd`, `./schemas/NanoPatcher.xsd`, and `../NanoPatcher.xsd` resolve from the directory containing the particular source changeset, not from the migration base directory or the process working directory. Absolute filesystem paths are used directly, including POSIX paths, Windows drive paths, and UNC paths. The schema must already exist; NanoPatcher does not create or copy it.

Both XML example files, `changeset/example_changeset.xml` and `executed/example_changeset.xml`, reference the root schema using `xsi:noNamespaceSchemaLocation="../NanoPatcher.xsd"`. Use the same declaration in your changesets and execution history:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<changeset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="../NanoPatcher.xsd">
  <file type="sql" url="table/example.sql"/>
  <file type="php" url="migrate_data.php"/>
</changeset>
```

Both `xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"` and a non-empty `xsi:noNamespaceSchemaLocation` are required on the changeset and execution history roots. NanoPatcher resolves the schema from the source changeset and explicitly validates both documents against it before running any file from that changeset. Missing declarations, an incorrect namespace, a missing schema file, or failed validation produce `CHANGESET_XML_INVALID` for the source changeset or `EXECUTED_XML_INVALID` for existing history.

The constructor configures migration directories. The protected `$schema_file` property is assigned when loading a source changeset, using that changeset's schema location. Different changesets can reference different schemas. There is no default or fallback schema location.

The schema defines a composite key on `type` and `url`. Duplicate keys are rejected within each XML document. The same `url` with different types is allowed because SQL and PHP files have different base directories. Execution history remains separate for each changeset.

XSD alone checks duplicate `type` + `url` keys. PHP separately checks the permitted spelling of paths; it does not normalize paths or search for duplicate XML entries.

## File Paths

The following restrictions apply to script `url` attributes. Schema locations use the separate rules described above, including support for `./` and absolute paths.

The `url` attribute contains a relative filesystem path:

- `type="sql"` resolves from the configured `sql` directory.
- `type="php"` resolves from the configured `php` directory.

Leading `../` segments may point outside the project. For example, if the application and NanoText directories are siblings and SQL patches are in `application/database/sql`:

```xml
<file type="sql" url="../../../NanoText/database/mariadb/f_word_sort_key.sql"/>
```

Use one spelling for each relative path:

- Use `/` as the only separator in XML. Backslashes are rejected.
- Do not use `./`, including inside a path, repeated `//`, or a trailing `/`.
- Use `../` only at the beginning of a path. `table/sub/../example.sql` is rejected; use `table/example.sql`.
- Absolute paths, drive prefixes, URI schemes, and colons are rejected.

These restrictions let the XSD composite key compare paths directly without PHP normalization. `table/example.sql` is valid; `./table/example.sql` is an error.

The same rules apply to existing execution history. Before upgrading, manually change legacy backslashes to `/` and remove alternate path spellings in both changesets and history, keeping one entry per composite key.

History stores the accepted relative `url` unchanged. Only the filesystem path used to execute a file replaces `/` with `DIRECTORY_SEPARATOR`. Paths are relative to the configured SQL/PHP directories, not the process working directory. Symbolic links and filesystem-specific aliases are not resolved for history identity.

## Execution Tracking

For every changeset NanoPatcher creates a corresponding file inside the `executed` directory.

When creating history, NanoPatcher copies the source root's `xmlns:xsi` and `xsi:noNamespaceSchemaLocation` declarations with their values unchanged. In particular, it does not replace or recalculate the schema location. Existing history must also contain both declarations. Saving XML preserves these values.

Example:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<changeset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="../NanoPatcher.xsd">
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

Execution history must include an `at` timestamp for every file, using `YYYY-MM-DD HH:MM:SS`.

## Forced Execution

Set the optional `force` attribute on an individual file to execute it once again:

```xml
<file type="sql" url="procedure/m_word_change.sql" force="true"/>
```

`force="true"` and `force="1"` bypass the already-executed check. Missing `force`, `force="false"`, and `force="0"` use normal history tracking. Other values are rejected by XML validation.

After successful execution, NanoPatcher updates the existing history entry's `at` timestamp, or creates an entry if none exists. It then removes `force` from the source changeset and saves that changeset immediately. Subsequent runs skip the file normally.

If execution or history saving fails, `force` remains in the source changeset. If saving the changed changeset fails, the runner reports an error; the successful execution is already recorded in history, and the on-disk `force` attribute remains until it can be removed. Changeset files and their containing directory must be writable when forced execution is requested.

XML updates use a temporary file in the destination directory followed by rename, preserving existing file permissions and schema declarations.

Forced execution keeps the same script semantics as normal execution. It does not add database transactions or undo SQL/PHP changes.

### Managing Force in Subclasses

Two protected methods operate on an existing XML `<file>` element:

- `setForce(SimpleXMLElement $file): void` sets `force="true"`. If the value is already `true` or `1`, it returns without changing the element.
- `removeForce(SimpleXMLElement $file): void` removes the `force` attribute. If the attribute is already absent, it returns without changing the element.

Both methods change the XML object in memory. Save the changeset separately with `saveXml()` when persistence is needed. For example, inside a subclass:

```php
$this->setForce($xml->file[0]);
$this->saveXml($changeset_file, $xml);
```

To remove the attribute:

```php
$this->removeForce($xml->file[0]);
$this->saveXml($changeset_file, $xml);
```

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

## Requirements

Runtime requirements: PHP 8.0+, PDO with the application's database driver, DOM, SimpleXML, and libxml.
