<?php

/**
 * NanoPatcher
 *
 * Tiny XML-driven SQL/PHP patch runner.
 *
 * PHP version: 8.0+
 */

class NanoPatcher {

  protected string $base_dir;
  protected string $schema_file;
  protected string $changeset_dir;
  protected string $executed_dir;
  protected string $sql_dir;
  protected string $php_dir;
  protected ?PDO $pdo = null;

  public function __construct(?string $base_dir = null) {
    $base_dir = $base_dir ?: __DIR__;
    $this->base_dir      = rtrim(realpath($base_dir) ?: $base_dir, DIRECTORY_SEPARATOR);
    $this->schema_file   = $this->base_dir . DIRECTORY_SEPARATOR . 'NanoPatcher.xsd';
    $this->changeset_dir = $this->base_dir . DIRECTORY_SEPARATOR . 'changeset';
    $this->executed_dir  = $this->base_dir . DIRECTORY_SEPARATOR . 'executed';
    $this->sql_dir       = $this->base_dir . DIRECTORY_SEPARATOR . 'sql';
    $this->php_dir       = $this->base_dir . DIRECTORY_SEPARATOR . 'php';
  }

  public function run(bool $show_skipped = false): array {
    if (!is_file($this->schema_file)) {
      return [ 'code' => 'SCHEMA_NOT_FOUND'
              ,'description' => "Schema file was not found: {$this->schema_file}"
              ,'changesets' => []
             ];
    }

    $res = [ 'code' => 'SUCCESS'
            ,'description' => 'NanoPatcher completed successfully.'
            ,'changesets' => []
           ];

    $pdo = $this->createPdo();

    if (!$pdo['success']) return $pdo;

    $this->pdo = $pdo['pdo'];

    $files = $this->getChangesetFiles();

    if (!$files) {
      return [ 'code' => 'NOT_FOUND'
              ,'description' => 'No numbered changesets found.'
              ,'changesets' => []
            ];
    }

    foreach ($files as $file) {
      $changeset = $this->runChangeset($file, $show_skipped);
      $res['changesets'][] = $changeset;

      if ($changeset['code'] !== 'SUCCESS') {
        $res['code'] = 'ERROR';
        $res['description'] = 'NanoPatcher stopped with an error.';
        break;
      }
    }

    return $res;
  }

  protected function createPdo(): array {
    $config_file = $this->base_dir . DIRECTORY_SEPARATOR . 'db.php';

    if (!file_exists($config_file)) {
      return [ 'success' => false
              ,'code' => 'DB_CONFIG_NOT_FOUND'
              ,'description' => 'db.php was not found.'
             ];
    }

    $config = include $config_file;

    if (!is_array($config)) {
      return [ 'success' => false
              ,'code' => 'DB_CONFIG_INVALID'
              ,'description' => 'db.php must return an array.'
             ];
    }

    try {
      return [ 'success' => true
              ,'pdo' => new PDO( $config['dsn'] ?? ''
                                ,$config['username'] ?? null
                                ,$config['password'] ?? null
                                ,$config['options'] ?? []
                               )
             ];
    } catch (Throwable $e) {
      return [ 'success' => false
              ,'code' => 'DB_CONNECTION_ERROR'
              ,'description' => $e->getMessage()
             ];
    }
  }

  protected function getChangesetFiles(): array {
    $files = glob($this->changeset_dir . DIRECTORY_SEPARATOR . '*_changeset*.xml') ?: [];

    $files = array_filter($files, function (string $file): bool {
      return (bool)preg_match('/_changeset(\d+)\.xml$/i', basename($file));
    });

    usort($files, function (string $a, string $b): int {
      preg_match('/_changeset(\d+)\.xml$/i', basename($a), $ma);
      preg_match('/_changeset(\d+)\.xml$/i', basename($b), $mb);

      return (int)$ma[1] <=> (int)$mb[1];
    });

    return array_values($files);
  }

  protected function runChangeset(string $changeset_file, bool $show_skipped = false): array {
    $changeset_name = basename($changeset_file);
    $executed_file  = $this->executed_dir . DIRECTORY_SEPARATOR . $changeset_name;

    $res = [ 'code' => 'SUCCESS'
            ,'changeset' => $changeset_name
            ,'files' => []
           ];

    $changeset_xml = $this->loadXml($changeset_file);

    if (!$changeset_xml['success']) {
      return [ 'code' => 'CHANGESET_XML_INVALID'
              ,'changeset' => $changeset_name
              ,'description' => $changeset_xml['description']
              ,'files' => []
             ];
    }

    $xml = $changeset_xml['xml'];
    $executed_xml = $this->getExecutedXml($executed_file);

    if (!$executed_xml['success']) {
      return [ 'code' => $executed_xml['code']
              ,'changeset' => $changeset_name
              ,'description' => $executed_xml['description']
              ,'files' => []
             ];
    }

    foreach ($xml->file as $file) {
      $type = (string)$file['type'];
      $url = (string)$file['url'];
      $force = in_array(trim((string)$file['force']), ['true', '1'], true);

      if ($force && (!is_writable($changeset_file) || !is_writable(dirname($changeset_file)))) {
        $res['code'] = 'CHANGESET_XML_SAVE_ERROR';
        $res['description'] = "Cannot remove force from {$changeset_name}: changeset is not writable.";
        return $res;
      }

      $executed_at = $this->getFileExecutedAt($executed_xml['xml'], $type, $url);

      if (!$force && $executed_at !== null) {
        if ($show_skipped)
          $res['files'][] = [ 'code'        => 'SKIPPED'
                             ,'type'        => $type
                             ,'url'         => $url
                             ,'at'          => $executed_at
                             ,'description' => 'Already executed.'
                            ];

        continue;
      }

      $executed = $this->executeFile($type, $url);

      if ($executed['code'] !== 'SUCCESS') {
        $res['files'][] = $executed;
        $res['code'] = 'ERROR';
        $res['description'] = 'Changeset stopped with an error.';
        return $res;
      }

      try {
        $executed['at'] = $this->saveExecutedFile( $executed_file
                                                  ,$executed_xml['xml']
                                                  ,$type
                                                  ,$url
                                                 );
      } catch (Throwable $e) {
        $executed['code'] = 'EXECUTED_XML_SAVE_ERROR';
        $executed['description'] = $e->getMessage();
        $res['files'][] = $executed;
        $res['code'] = 'ERROR';
        $res['description'] = 'Changeset stopped with an error.';
        return $res;
      }

      if ($force) {
        $this->removeForce($file);

        try {
          $this->saveXml($changeset_file, $xml);
        } catch (Throwable $e) {
          $executed['code'] = 'CHANGESET_XML_SAVE_ERROR';
          $executed['description'] = $e->getMessage();
          $res['files'][] = $executed;
          $res['code'] = 'ERROR';
          $res['description'] = 'Changeset stopped with an error.';
          return $res;
        }
      }

      $res['files'][] = $executed;
    }

    return $res;
  }

  protected function setForce(SimpleXMLElement $file): void {
    if (in_array(trim((string)$file['force']), ['true', '1'], true))
      return;

    $file['force'] = 'true';
  }

  protected function removeForce(SimpleXMLElement $file): void {
    if (!isset($file['force']))
      return;

    unset($file['force']);
  }

  protected function validateFileUrl(string $url): void {
    if ( trim($url) === ''
         || str_starts_with($url, '/')
         || str_contains($url, '\\')
         || str_contains($url, ':')
       )
      throw new InvalidArgumentException("File url must be a non-empty relative path using '/' only: {$url}");

    $has_name = false;

    foreach (explode('/', $url) as $part) {
      if ($part === '' || $part === '.' || ($part === '..' && $has_name))
        throw new InvalidArgumentException("Invalid file url: {$url}. Do not use './', repeated or trailing '/', or '..' after directory/file names.");

      if ($part !== '..')
        $has_name = true;
    }

    if (!$has_name)
      throw new InvalidArgumentException("File url must end in a file name: {$url}");
  }

  protected function loadXml(string $xml_file): array {
    $previous_errors = libxml_use_internal_errors(true);
    libxml_clear_errors();

    try {
      $document = new DOMDocument();

      if (!$document->load($xml_file, LIBXML_NONET))
        throw new Exception('Unable to read XML.');

      if (!@$document->schemaValidate($this->schema_file))
        throw new Exception('XML does not match NanoPatcher.xsd.');

      $xml = simplexml_import_dom($document);

      if ($xml === false)
        throw new Exception('Unable to load validated XML.');

      // XSD checks duplicate keys; PHP only rejects alternate path spellings.
      foreach ($xml->file as $file)
        $this->validateFileUrl((string)$file['url']);

      return [ 'success' => true
              ,'xml' => $xml
             ];
    } catch (Throwable $e) {
      $errors = [];

      foreach (libxml_get_errors() as $error)
        $errors[] = 'line ' . $error->line . ': ' . trim($error->message);

      return [ 'success' => false
              ,'description' => basename($xml_file) . ': ' . $e->getMessage()
                                . ($errors ? ' ' . implode(' ', $errors) : '')
             ];
    } finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous_errors);
    }
  }

  protected function getExecutedXml(string $executed_file): array {
    if (!file_exists($this->executed_dir)) {
      if (!mkdir($this->executed_dir, 0777, true)) {
        return [ 'success' => false
                ,'code' => 'EXECUTED_DIR_CREATE_ERROR'
                ,'description' => 'Cannot create executed directory.'
               ];
      }
    }

    if (!file_exists($executed_file)) {
      $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><changeset/>');

      try {
        $this->saveXml($executed_file, $xml);
      } catch (Throwable $e) {
        return [ 'success' => false
                ,'code' => 'EXECUTED_XML_CREATE_ERROR'
                ,'description' => $e->getMessage()
               ];
      }
    }

    $loaded = $this->loadXml($executed_file);

    if (!$loaded['success']) {
      return [ 'success' => false
              ,'code' => 'EXECUTED_XML_INVALID'
              ,'description' => $loaded['description']
             ];
    }

    foreach ($loaded['xml']->file as $file) {
      if (!isset($file['at'])) {
        return [ 'success' => false
                ,'code' => 'EXECUTED_XML_INVALID'
                ,'description' => 'Executed file has no at attribute: ' . (string)$file['url']
               ];
      }
    }

    return $loaded;
  }

  protected function getFileExecutedAt(SimpleXMLElement $xml, string $type, string $url): ?string {
    foreach ($xml->file as $file) {
      if ((string)$file['type'] === $type && (string)$file['url'] === $url) {
        return (string)$file['at'];
      }
    }

    return null;
  }

  protected function executeFile(string $type, string $url): array {
    $relative_path = str_replace('/', DIRECTORY_SEPARATOR, $url);

    $path = match ($type) {
      'sql' => $this->sql_dir . DIRECTORY_SEPARATOR . $relative_path
     ,'php' => $this->php_dir . DIRECTORY_SEPARATOR . $relative_path
     ,default => null
    };

    if (!$path) {
      return [ 'code' => 'UNKNOWN_FILE_TYPE'
              ,'type' => $type
              ,'url' => $url
              ,'description' => "Unknown file type: {$type}"
             ];
    }

    if (!is_file($path)) {
      return [ 'code' => 'FILE_NOT_FOUND'
              ,'type' => $type
              ,'url' => $url
              ,'description' => "File was not found: {$path}"
             ];
    }

    try {
      if ($type === 'sql') {
        $sql = file_get_contents($path);

        if ($sql === false) {
          return [ 'code' => 'SQL_READ_ERROR'
                  ,'type' => $type
                  ,'url' => $url
                  ,'description' => "Cannot read SQL file: {$path}"
                 ];
        }

        $queries = preg_split('/\R\s*\R\s*\R/', trim($sql));

        foreach ($queries as $query) {
          $query = trim($query);

          if ($query === '')
            continue;

          $this->pdo->exec($query);
        }
      }

      if ($type === 'php') {
        $callback = require $path;

        if (!is_callable($callback)) {
          return [ 'code' => 'PHP_NOT_CALLABLE'
                  ,'type' => $type
                  ,'url' => $url
                  ,'description' => "PHP patch must return callable: {$path}"
                 ];
        }

        $callback($this->pdo);
      }

      return [ 'code' => 'SUCCESS'
              ,'type' => $type
              ,'url' => $url
              ,'description' => 'Executed successfully.'
             ];
    } catch (Throwable $e) {
      return [ 'code' => 'EXECUTION_ERROR'
              ,'type' => $type
              ,'url' => $url
              ,'description' => $e->getMessage()
             ];
    }
  }

  protected function saveExecutedFile(string $executed_file, SimpleXMLElement $xml, string $type, string $url): string {
    $at = date('Y-m-d H:i:s');
    $item = null;

    foreach ($xml->file as $file) {
      if ((string)$file['type'] === $type && (string)$file['url'] === $url) {
        $item = $file;
        break;
      }
    }

    $item ??= $xml->addChild('file');
    $item['type'] = $type;
    $item['url'] = $url;
    $item['at'] = $at;

    $this->saveXml($executed_file, $xml);

    return $at;
  }

  protected function saveXml(string $xml_file, SimpleXMLElement $xml): void {
    $namespace = 'http://www.w3.org/2001/XMLSchema-instance';
    $attributes = $xml->attributes($namespace);

    if (isset($attributes['noNamespaceSchemaLocation']))
      $attributes['noNamespaceSchemaLocation'] = '../NanoPatcher.xsd';
    else
      $xml->addAttribute('xsi:noNamespaceSchemaLocation', '../NanoPatcher.xsd', $namespace);

    $contents = $xml->asXML();

    if ($contents === false)
      throw new Exception('Unable to serialize XML file: ' . basename($xml_file));

    $temporary_file = @tempnam(dirname($xml_file), '.nanopatcher-');

    if ($temporary_file === false)
      throw new Exception('Unable to create temporary XML file: ' . basename($xml_file));

    try {
      if (@file_put_contents($temporary_file, $contents, LOCK_EX) !== strlen($contents))
        throw new Exception('Unable to write XML file: ' . basename($xml_file));

      $mode = file_exists($xml_file) ? fileperms($xml_file) & 0777 : 0666 & ~umask();

      if (!@chmod($temporary_file, $mode) || !@rename($temporary_file, $xml_file))
        throw new Exception('Unable to save XML file: ' . basename($xml_file));
    } finally {
      if (file_exists($temporary_file))
        unlink($temporary_file);
    }
  }

}

?>
