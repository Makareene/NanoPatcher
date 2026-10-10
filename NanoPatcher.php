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
  protected string $changeset_dir;
  protected string $executed_dir;
  protected string $sql_dir;
  protected string $php_dir;
  protected ?PDO $pdo = null;

  public function __construct(?string $base_dir = null) {
    $this->base_dir      = rtrim($base_dir ?: __DIR__, DIRECTORY_SEPARATOR);
    $this->changeset_dir = $this->base_dir . DIRECTORY_SEPARATOR . 'changeset';
    $this->executed_dir  = $this->base_dir . DIRECTORY_SEPARATOR . 'executed';
    $this->sql_dir       = $this->base_dir . DIRECTORY_SEPARATOR . 'sql';
    $this->php_dir       = $this->base_dir . DIRECTORY_SEPARATOR . 'php';
  }

  public function run(bool $show_skipped = false): array {
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

    $executed_xml = $this->getExecutedXml($executed_file);

    if (!$executed_xml['success']) {
      return [ 'code' => $executed_xml['code']
              ,'changeset' => $changeset_name
              ,'description' => $executed_xml['description']
              ,'files' => []
             ];
    }

    $xml = @simplexml_load_file($changeset_file);

    if ($xml === false) {
      return [ 'code' => 'CHANGESET_XML_INVALID'
              ,'changeset' => $changeset_name
              ,'description' => "Invalid XML: {$changeset_name}"
              ,'files' => []
             ];
    }

    foreach ($xml->file as $file) {
      $type = strtolower((string)$file['type']);
      $url = str_replace( ['/', '\\']
                         ,DIRECTORY_SEPARATOR
                         ,(string)$file['url']
                        );

      if (!$type || !$url) {
        $res['code'] = 'FILE_ITEM_INVALID';
        $res['description'] = "Invalid file item in {$changeset_name}";
        return $res;
      }

      $executed_at = $this->getFileExecutedAt($executed_xml['xml'], $type, $url);

      if ($executed_at !== null) {
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

      $executed['at'] = $this->saveExecutedFile( $executed_file
                                                ,$executed_xml['xml']
                                                ,$type
                                                ,$url
                                               );

      $res['files'][] = $executed;
    }

    return $res;
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
      
      if ($xml->asXML($executed_file) === false) {
        return [ 'success' => false
                ,'code' => 'EXECUTED_XML_CREATE_ERROR'
                ,'description' => 'Cannot create executed XML file: ' . basename($executed_file)
               ];
      }
    }

    $xml = @simplexml_load_file($executed_file);

    if ($xml === false) {
      return [ 'success' => false
              ,'code' => 'EXECUTED_XML_INVALID'
              ,'description' => 'Executed XML file is invalid: ' . basename($executed_file)
             ];
    }

    return [ 'success' => true
            ,'xml' => $xml
           ];
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
    $path = match ($type) {
      'sql' => $this->sql_dir . DIRECTORY_SEPARATOR . $url
     ,'php' => $this->php_dir . DIRECTORY_SEPARATOR . $url
     ,default => null
    };

    if (!$path) {
      return [ 'code' => 'UNKNOWN_FILE_TYPE'
              ,'type' => $type
              ,'url' => $url
              ,'description' => "Unknown file type: {$type}"
             ];
    }

    if (!file_exists($path)) {
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

    $file = $xml->addChild('file');
    $file->addAttribute('type', $type);
    $file->addAttribute('url', $url);
    $file->addAttribute('at', $at);

    if ($xml->asXML($executed_file) === false)
      throw new Exception('Unable to save executed XML file: ' . basename($executed_file));

    return $at;
  }

}

?>
