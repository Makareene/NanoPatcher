<?php

require dirname(__DIR__) . '/NanoPatcher.php';

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
  if (!(error_reporting() & $severity))
    return true;

  throw new ErrorException($message, 0, $severity, $file, $line);
});

$test_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nanopatcher-tests-' . bin2hex(random_bytes(8));
$fixture_no = 0;
$checks = 0;
mkdir($test_root);

function check(bool $condition, string $message): void {
  global $checks;
  $checks++;

  if (!$condition)
    throw new RuntimeException($message);
}

function fixture(): array {
  global $test_root, $fixture_no;
  $directory = $test_root . DIRECTORY_SEPARATOR . ++$fixture_no;
  $base = $directory . '/application/database';

  foreach (['changeset', 'executed', 'sql', 'php'] as $name)
    mkdir($base . '/' . $name, 0777, true);

  copy(dirname(__DIR__) . '/NanoPatcher.xsd', $base . '/NanoPatcher.xsd');

  $dsn = 'sqlite:' . $base . '/test.sqlite';
  $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $pdo->exec('CREATE TABLE events (event_id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
  file_put_contents($base . '/db.php', '<?php return ' . var_export(['dsn' => $dsn], true) . ';');

  return [$base, $pdo, $directory];
}

function writeFile(string $path, string $contents): void {
  if (!is_dir(dirname($path)))
    mkdir(dirname($path), 0777, true);

  file_put_contents($path, $contents);
}

function writeXml(string $path, array $items): void {
  $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><changeset/>');

  foreach ($items as $attributes) {
    $file = $xml->addChild('file');

    foreach ($attributes as $name => $value)
      $file->addAttribute($name, $value);
  }

  $xml->asXML($path);
}

function events(PDO $pdo): array {
  return $pdo->query('SELECT value FROM events ORDER BY event_id')->fetchAll(PDO::FETCH_COLUMN);
}

function history(string $base): SimpleXMLElement {
  return simplexml_load_file($base . '/executed/test_changeset1.xml');
}

function source(string $base): SimpleXMLElement {
  return simplexml_load_file($base . '/changeset/test_changeset1.xml');
}

function removeDirectory(string $directory): void {
  foreach (new DirectoryIterator($directory) as $entry) {
    if ($entry->isDot())
      continue;

    if ($entry->isDir())
      removeDirectory($entry->getPathname());
    else
      unlink($entry->getPathname());
  }

  rmdir($directory);
}

class SaveFailurePatcher extends NanoPatcher {

  private string $failure_directory;

  public function __construct(string $base, string $failure_directory) {
    parent::__construct($base);
    $this->failure_directory = $failure_directory;
  }

  protected function saveXml(string $xml_file, SimpleXMLElement $xml): void {
    if (basename(dirname($xml_file)) === $this->failure_directory)
      throw new RuntimeException('Intentional XML save failure.');

    parent::saveXml($xml_file, $xml);
  }

}

try {
  // Ordinary execution reads project-local XSD and leaves stored urls unchanged.
  [$base, $pdo] = fixture();
  writeFile($base . '/sql/table/example.sql', "INSERT INTO events(value) VALUES ('sql');");
  writeXml($base . '/changeset/test_changeset1.xml', [['type' => 'sql', 'url' => 'table/example.sql']]);
  $patcher = new NanoPatcher($base);
  check($patcher->run()['code'] === 'SUCCESS', 'First execution failed.');
  check(events($pdo) === ['sql'], 'First execution did not run once.');

  writeXml($base . '/executed/test_changeset1.xml', [
    ['type' => 'sql', 'url' => 'table/example.sql', 'at' => '2000-01-01 00:00:00']
  ]);
  writeXml($base . '/changeset/test_changeset1.xml', [['type' => 'sql', 'url' => 'table/example.sql']]);
  $old_history = file_get_contents($base . '/executed/test_changeset1.xml');
  check($patcher->run(true)['changesets'][0]['files'][0]['code'] === 'SKIPPED', 'Legacy history was not recognized.');
  check(file_get_contents($base . '/executed/test_changeset1.xml') === $old_history, 'Skipped history was unnecessarily rewritten.');

  // Both true spellings are one-shot, update the timestamp, and never add duplicates.
  foreach (['true', '1'] as $force) {
    writeXml($base . '/changeset/test_changeset1.xml', [['type' => 'sql', 'url' => 'table/example.sql', 'force' => $force]]);
    check($patcher->run()['code'] === 'SUCCESS', 'Forced execution failed.');
    $xml = history($base);
    check(count($xml->file) === 1, 'Forced execution duplicated history.');
    check((string)$xml->file[0]['at'] !== '2000-01-01 00:00:00', 'Forced execution did not update at.');
    check((string)$xml->file[0]['url'] === 'table/example.sql', 'Updated history changed the url.');
    check(!isset(source($base)->file[0]['force']), 'Successful force was not removed.');
    check($patcher->run(true)['changesets'][0]['files'][0]['code'] === 'SKIPPED', 'Force was not one-shot.');
  }

  check(events($pdo) === ['sql', 'sql', 'sql'], 'Forced files did not execute exactly once each.');

  foreach (['false', '0'] as $force) {
    writeXml($base . '/changeset/test_changeset1.xml', [['type' => 'sql', 'url' => 'table/example.sql', 'force' => $force]]);
    check($patcher->run(true)['changesets'][0]['files'][0]['code'] === 'SKIPPED', 'A false force value caused re-execution.');
    check(events($pdo) === ['sql', 'sql', 'sql'], 'A false force value changed the database.');
  }

  // SQL and PHP may have the same url: their composite keys differ.
  [$base, $pdo] = fixture();
  writeFile($base . '/sql/shared.patch', "INSERT INTO events(value) VALUES ('sql');");
  writeFile($base . '/php/shared.patch', '<?php return function (PDO $pdo): void { $pdo->exec("INSERT INTO events(value) VALUES (\'php\')"); };');
  writeXml($base . '/changeset/test_changeset1.xml', [
    ['type' => 'sql', 'url' => 'shared.patch']
   ,['type' => 'php', 'url' => 'shared.patch']
  ]);
  check((new NanoPatcher($base))->run()['code'] === 'SUCCESS', 'Different types with the same url were rejected.');
  check(events($pdo) === ['sql', 'php'], 'SQL/PHP execution order changed.');
  check(count(history($base)->file) === 2, 'Composite-key history was not saved.');

  // The XSD key rejects duplicates directly, without any PHP path checks.
  [$base, $pdo] = fixture();
  writeFile($base . '/sql/first.sql', "INSERT INTO events(value) VALUES ('first');");
  writeFile($base . '/sql/table/example.sql', "INSERT INTO events(value) VALUES ('duplicate');");
  writeXml($base . '/changeset/test_changeset1.xml', [
    ['type' => 'sql', 'url' => 'first.sql']
   ,['type' => 'sql', 'url' => 'table/example.sql']
   ,['type' => 'sql', 'url' => 'table/example.sql']
  ]);
  $document = new DOMDocument();
  $document->load($base . '/changeset/test_changeset1.xml');
  $previous_errors = libxml_use_internal_errors(true);
  check(!$document->schemaValidate($base . '/NanoPatcher.xsd'), 'XSD alone accepted duplicate composite keys.');
  libxml_clear_errors();
  libxml_use_internal_errors($previous_errors);
  $result = (new NanoPatcher($base))->run();
  check($result['changesets'][0]['code'] === 'CHANGESET_XML_INVALID', 'Duplicate changeset key was accepted.');
  check(str_contains($result['changesets'][0]['description'], 'fileKey'), 'Duplicate error did not come from the XSD key.');
  check(events($pdo) === [], 'Duplicate validation happened after execution.');

  $invalid_items = [
    ['url' => 'example.sql']
   ,['type' => 'sql']
   ,['type' => 'python', 'url' => 'example.sql']
   ,['type' => 'SQL', 'url' => 'example.sql']
   ,['type' => 'sql', 'url' => 'example.sql', 'force' => 'yes']
   ,['type' => 'sql', 'url' => 'example.sql', 'force' => '2']
   ,['type' => 'sql', 'url' => 'example.sql', 'force' => 'True']
   ,['type' => 'sql', 'url' => 'example.sql', 'force' => '']
   ,['type' => 'sql', 'url' => '']
   ,['type' => 'sql', 'url' => '.']
   ,['type' => 'sql', 'url' => 'table/..']
   ,['type' => 'sql', 'url' => './table/example.sql']
   ,['type' => 'sql', 'url' => 'table/./example.sql']
   ,['type' => 'sql', 'url' => 'table//example.sql']
   ,['type' => 'sql', 'url' => 'table/example.sql/']
   ,['type' => 'sql', 'url' => 'table/sub/../example.sql']
   ,['type' => 'sql', 'url' => '../table/../../example.sql']
   ,['type' => 'sql', 'url' => '../']
   ,['type' => 'sql', 'url' => '..']
   ,['type' => 'sql', 'url' => 'table\\example.sql']
   ,['type' => 'sql', 'url' => '/absolute/example.sql']
   ,['type' => 'sql', 'url' => 'C:\\absolute\\example.sql']
   ,['type' => 'sql', 'url' => 'C:/absolute/example.sql']
   ,['type' => 'sql', 'url' => 'C:example.sql']
   ,['type' => 'sql', 'url' => '//server/share/example.sql']
   ,['type' => 'sql', 'url' => 'file:///example.sql']
   ,['type' => 'sql', 'url' => 'example.sql', 'unknown' => 'value']
  ];

  foreach ($invalid_items as $item) {
    [$base, $pdo] = fixture();
    writeFile($base . '/sql/first.sql', "INSERT INTO events(value) VALUES ('first');");
    writeXml($base . '/changeset/test_changeset1.xml', [['type' => 'sql', 'url' => 'first.sql'], $item]);
    $result = (new NanoPatcher($base))->run();
    check($result['changesets'][0]['code'] === 'CHANGESET_XML_INVALID', 'An invalid file item was accepted.');
    check(events($pdo) === [], 'An invalid changeset partially executed.');
    check((string)source($base)->file[1]['url'] === ($item['url'] ?? ''), 'Invalid input was silently normalized.');
  }

  foreach (['<changeset><file', '<wrong/>', '<changeset><other/></changeset>'] as $contents) {
    [$base, $pdo] = fixture();
    writeFile($base . '/changeset/test_changeset1.xml', $contents);
    check((new NanoPatcher($base))->run()['changesets'][0]['code'] === 'CHANGESET_XML_INVALID', 'Invalid XML structure was accepted.');
    check(events($pdo) === [], 'Invalid XML changed the database.');
  }

  // Existing history uses the same schema and strict path rules.
  foreach (['example.sql', './example.sql', 'table\\example.sql'] as $duplicate) {
    [$base, $pdo] = fixture();
    writeFile($base . '/sql/example.sql', "INSERT INTO events(value) VALUES ('sql');");
    writeXml($base . '/changeset/test_changeset1.xml', [['type' => 'sql', 'url' => 'example.sql', 'force' => 'true']]);
    writeXml($base . '/executed/test_changeset1.xml', [
      ['type' => 'sql', 'url' => 'example.sql', 'at' => '2000-01-01 00:00:00']
     ,['type' => 'sql', 'url' => $duplicate, 'at' => '2000-01-02 00:00:00']
    ]);
    check((new NanoPatcher($base))->run()['changesets'][0]['code'] === 'EXECUTED_XML_INVALID', 'Duplicate or invalid history was accepted.');
    check(events($pdo) === [], 'Invalid history allowed forced execution.');
    check(isset(source($base)->file[0]['force']), 'Invalid history consumed force.');
  }

  foreach ([null, '', 'yesterday', '2000-01-01 00:00:00 extra'] as $at) {
    [$base, $pdo] = fixture();
    $item = ['type' => 'sql', 'url' => 'example.sql'];

    if ($at !== null)
      $item['at'] = $at;

    writeXml($base . '/changeset/test_changeset1.xml', [['type' => 'sql', 'url' => 'example.sql']]);
    writeXml($base . '/executed/test_changeset1.xml', [$item]);
    check((new NanoPatcher($base))->run()['changesets'][0]['code'] === 'EXECUTED_XML_INVALID', 'Invalid or missing history timestamp was accepted.');
  }

  // External paths work for both patch types and keep portable history keys.
  [$base, $pdo, $directory] = fixture();
  writeFile($directory . '/NanoText/database/mariadb/external.sql', "INSERT INTO events(value) VALUES ('external-sql');");
  writeFile($directory . '/shared/external.php', '<?php return function (PDO $pdo): void { $pdo->exec("INSERT INTO events(value) VALUES (\'external-php\')"); };');
  writeXml($base . '/changeset/test_changeset1.xml', [
    ['type' => 'sql', 'url' => '../../../NanoText/database/mariadb/external.sql', 'force' => '1']
   ,['type' => 'php', 'url' => '../../../shared/external.php', 'force' => 'true']
  ]);
  $patcher = new NanoPatcher($base);
  check($patcher->run()['code'] === 'SUCCESS', 'External SQL/PHP files could not execute.');
  check(events($pdo) === ['external-sql', 'external-php'], 'External files executed incorrectly.');
  check((string)history($base)->file[0]['url'] === '../../../NanoText/database/mariadb/external.sql', 'External history became absolute or lost its parent segments.');
  check($patcher->run(true)['changesets'][0]['files'][0]['code'] === 'SKIPPED', 'External files were not tracked.');

  $previous_directory = getcwd();
  chdir($test_root);
  check($patcher->run(true)['changesets'][0]['files'][1]['code'] === 'SKIPPED', 'Changing the working directory broke relative paths.');
  chdir($previous_directory);

  chdir(dirname($base));
  $relative_base_patcher = new NanoPatcher('./database');
  chdir($test_root);
  check($relative_base_patcher->run(true)['changesets'][0]['files'][0]['code'] === 'SKIPPED', 'A relative base directory followed a changed working directory.');
  chdir($previous_directory);

  // Stop at the first failure, preserving successful progress and pending force.
  [$base, $pdo] = fixture();
  writeFile($base . '/sql/first.sql', "INSERT INTO events(value) VALUES ('first');");
  writeFile($base . '/sql/broken.sql', 'INVALID SQL;');
  writeFile($base . '/sql/last.sql', "INSERT INTO events(value) VALUES ('last');");
  writeXml($base . '/changeset/test_changeset1.xml', [
    ['type' => 'sql', 'url' => 'first.sql', 'force' => 'true']
   ,['type' => 'sql', 'url' => 'broken.sql', 'force' => 'true']
   ,['type' => 'sql', 'url' => 'last.sql', 'force' => 'true']
  ]);
  writeXml($base . '/changeset/test_changeset2.xml', [['type' => 'sql', 'url' => 'last.sql']]);
  $result = (new NanoPatcher($base))->run();
  check($result['code'] === 'ERROR' && count($result['changesets']) === 1, 'Execution continued into another changeset after failure.');
  check(events($pdo) === ['first'], 'Execution continued after the failing file.');
  check(count(history($base)->file) === 1, 'A failed or unexecuted file was recorded.');
  check(!isset(source($base)->file[0]['force']), 'Successful progress did not consume force.');
  check(isset(source($base)->file[1]['force'], source($base)->file[2]['force']), 'Failure consumed pending force attributes.');

  foreach (['missing.sql', 'directory'] as $url) {
    [$base, $pdo] = fixture();
    mkdir($base . '/sql/directory');
    writeXml($base . '/changeset/test_changeset1.xml', [['type' => 'sql', 'url' => $url, 'force' => 'true']]);
    $result = (new NanoPatcher($base))->run();
    check($result['changesets'][0]['files'][0]['code'] === 'FILE_NOT_FOUND', 'Missing/non-file path was executed.');
    check(isset(source($base)->file[0]['force']), 'Missing file consumed force.');
  }

  foreach (['<?php return null;', '<?php return function (PDO $pdo): void { throw new RuntimeException("failure"); };'] as $contents) {
    [$base, $pdo] = fixture();
    writeFile($base . '/php/broken.php', $contents);
    writeXml($base . '/changeset/test_changeset1.xml', [['type' => 'php', 'url' => 'broken.php', 'force' => 'true']]);
    check((new NanoPatcher($base))->run()['code'] === 'ERROR', 'A broken PHP patch was accepted.');
    check(isset(source($base)->file[0]['force']), 'A broken PHP patch consumed force.');
    check(count(history($base)->file) === 0, 'A broken PHP patch was recorded.');
  }

  // Saving failures return errors without losing the on-disk force request.
  foreach (['executed', 'changeset'] as $failure_directory) {
    [$base, $pdo] = fixture();
    writeFile($base . '/sql/example.sql', "INSERT INTO events(value) VALUES ('sql');");
    writeXml($base . '/changeset/test_changeset1.xml', [['type' => 'sql', 'url' => 'example.sql', 'force' => 'true']]);
    writeXml($base . '/executed/test_changeset1.xml', [['type' => 'sql', 'url' => 'example.sql', 'at' => '2000-01-01 00:00:00']]);
    $before_source = file_get_contents($base . '/changeset/test_changeset1.xml');
    $before_history = file_get_contents($base . '/executed/test_changeset1.xml');
    $result = (new SaveFailurePatcher($base, $failure_directory))->run();
    $expected_code = $failure_directory === 'executed' ? 'EXECUTED_XML_SAVE_ERROR' : 'CHANGESET_XML_SAVE_ERROR';
    check($result['changesets'][0]['files'][0]['code'] === $expected_code, 'XML saving failure was not reported.');
    check(file_get_contents($base . '/changeset/test_changeset1.xml') === $before_source, 'Saving failure changed the source request.');
    check(events($pdo) === ['sql'], 'Saving failure incorrectly changed execution semantics.');
    check(count(history($base)->file) === 1, 'Saving failure duplicated history.');

    if ($failure_directory === 'executed')
      check(file_get_contents($base . '/executed/test_changeset1.xml') === $before_history, 'Failed history saving overwrote history.');
    else
      check((string)history($base)->file[0]['at'] !== '2000-01-01 00:00:00', 'Successful history saving was lost.');
  }

  // Both XML directories reference the preinstalled project-local schema.
  [$base, $pdo, $directory] = fixture();
  $schema = $base . '/NanoPatcher.xsd';
  writeFile($base . '/sql/example.sql', "INSERT INTO events(value) VALUES ('sql');");
  writeXml($base . '/changeset/test_changeset1.xml', [['type' => 'sql', 'url' => 'example.sql', 'force' => 'true']]);
  check((new NanoPatcher($base))->run()['code'] === 'SUCCESS', 'A project-local schema file failed.');

  foreach (['changeset', 'executed'] as $name) {
    $xml_file = $base . '/' . $name . '/test_changeset1.xml';
    $xml = simplexml_load_file($xml_file);
    $hint = (string)$xml->attributes('http://www.w3.org/2001/XMLSchema-instance')['noNamespaceSchemaLocation'];
    check($hint === '../NanoPatcher.xsd', 'Schema location was not the fixed project-relative path.');
    check(realpath(dirname($xml_file) . '/' . $hint) === realpath($schema), 'Schema hint points to the wrong file.');
    $document = new DOMDocument();
    $document->load($xml_file);
    check($document->schemaValidate($schema), 'Written XML does not match the schema.');
  }

  [$base, $pdo] = fixture();
  writeFile($base . '/sql/example.sql', "INSERT INTO events(value) VALUES ('sql');");
  writeXml($base . '/changeset/test_changeset1.xml', [['type' => 'sql', 'url' => 'example.sql']]);
  unlink($base . '/NanoPatcher.xsd');
  unlink($base . '/db.php');
  check((new NanoPatcher($base))->run()['code'] === 'SCHEMA_NOT_FOUND', 'A missing schema did not fail before connecting to the database.');
  check(events($pdo) === [], 'A missing schema changed the database.');
  check(!file_exists($base . '/NanoPatcher.xsd'), 'The runner automatically created or copied a missing schema.');
  check(!file_exists($base . '/executed/test_changeset1.xml'), 'A missing schema created execution history.');

  file_put_contents($base . '/db.php', '<?php return ' . var_export(['dsn' => 'sqlite:' . $base . '/test.sqlite'], true) . ';');
  writeFile($base . '/NanoPatcher.xsd', '<not-a-schema/>');
  check((new NanoPatcher($base))->run()['changesets'][0]['code'] === 'CHANGESET_XML_INVALID', 'An invalid schema allowed unvalidated execution.');
  check(events($pdo) === [], 'An invalid schema changed the database.');

  // Keep the existing SQL block splitting and ordinary multi-changeset history.
  [$base, $pdo] = fixture();
  writeFile($base . '/sql/example.sql', "INSERT INTO events(value) VALUES ('block1');\n\n\nINSERT INTO events(value) VALUES ('block2');");
  writeXml($base . '/changeset/test_changeset1.xml', [['type' => 'sql', 'url' => 'example.sql']]);
  writeXml($base . '/changeset/test_changeset2.xml', [['type' => 'sql', 'url' => 'example.sql']]);
  $patcher = new NanoPatcher($base);
  check($patcher->run()['code'] === 'SUCCESS', 'Multiple changesets failed.');
  check(events($pdo) === ['block1', 'block2', 'block1', 'block2'], 'SQL blocks or independent changeset history changed.');
  check($patcher->run()['changesets'][0]['files'] === [], 'Skipped files are no longer hidden by default.');

  echo "PASS: {$checks} checks.\n";
} finally {
  removeDirectory($test_root);
}
