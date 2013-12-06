<?php

/**
 * @file
 * This module is called from custom cron.
 * Does the legwork of converting documents.
 *
 * Because of performance issues this is done outside of Drupals bootstrap.
 *
 * Arguments:
 *   1: Path to files which should be converted. This should either be an
 *      relative path or an absolute.
 *
 *   2: Path to your Drupal instance. When providing a valid Drupal path, it
 *      tries to update the corrosponding file entity in Drupal with the new
 *      .pdf URI.
 */

if (php_sapi_name() !== 'cli') {
  // This script is ONLY allowed from commandline.
  exit();
}

if (!shell_exec('which soffice')) {
  print ('soffice was not found. You need to install a pdf conversion tool like LibreOffice.');
  exit();
}

if (!extension_loaded('imagick')) {
  print ('imagick was not found. Cannot convert .tiff files');
}

if (!shell_exec('which mapitool') || !shell_exec('which munpack')) {
  print ('you need mapitool and munpack to unpack and convert .msg files.');
}

if (!isset($_SERVER['argv'][1])) {
  print ('Usage: php os2web_pdf_converter.php "/path/to/files" "/path/to/drupal"');
  exit();
}
elseif (!is_dir($_SERVER['argv'][1])) {
  print ('The path is not a directory!');
  exit();
}
else {
  $directory_root = $_SERVER['argv'][1];

  // Setup Drupal but only if provided.
  if (isset($_SERVER['argv'][2])) {
    if (!file_exists($_SERVER['argv'][2] . '/includes/bootstrap.inc')) {
      print ('No Drupal instance was found at ' . $_SERVER['argv'][2]);
      exit();
    }
    define('DRUPAL_ROOT', $_SERVER['argv'][2]);
    require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
    drupal_bootstrap(DRUPAL_BOOTSTRAP_DATABASE);
  }
}


require 'lib/PDFConverter.php';


$allowed_extensions = PDFConverter::getAllowedExtenstions();

// Loop trough all files in the directory, only files of specific type allowed
// by the PDFConverter.
foreach (getFilesList($directory_root, '/.*\.(' . implode('|', $allowed_extensions) . ')$/i') as $file) {
  // Replaces the extension with ".pdf".
  $pdf_file = preg_replace('/\.(' . implode('|', $allowed_extensions) . ')$/i', '.pdf', $file);
  if (!file_exists($pdf_file)) {
    try {
      $file = new PDFConverter($file);
      if ($file->convert()) {
        if (defined('DRUPAL_ROOT')) {
          updateDrupalFile($file);
        }
        print ($file->file . ' was converted to pdf.');
      }

    }
    catch(Exception $e) {
      error_log($e->getMessage());
    }
  }
}

/**
 * Get a list of all matched files in folder. Recursivly.
 *
 * @param string $folder
 *   the folder
 * @param string $pattern
 *   regex pattern to search for
 *
 * @return array
 *   array of file paths
 */
function getFilesList($folder, $pattern) {
  $dir = new RecursiveDirectoryIterator($folder);
  $ite = new RecursiveIteratorIterator($dir);
  $files = new RegexIterator($ite, $pattern, RegexIterator::GET_MATCH);
  $file_list = array();
  foreach ($files as $file) {
    $file_list[] = $file[0];
  }
  return $file_list;
}

/**
 * Updates the file entry in file_managed in Drupal to the new uri.
 *
 * @param string $file
 *   The file path.
 */
function updateDrupalFile($file) {
  // TODO: Could the path be converted easier?
  // - What if the files folder are somewhere else?
  // - Only possible with public files.
  $file_parts = explode('sites/default/files/', $file->file);
  if (isset($file_parts[1])) {
    $file_uri = 'public://' . $file_parts[1];
  }

  $d_file = db_query('SELECT f.fid
                      FROM {file_managed} f
                      WHERE f.uri = :uri', array(':uri' => $file_uri));

  if ($fid = $d_file->fetchField()) {
    db_update('file_managed')
      ->fields(array(
        'uri' => preg_replace('/\.(' . implode('|', PDFConverter::getAllowedExtenstions()) . ')$/i', '.pdf', $file_uri),
        'filename' => basename($file->pdf),
        'timestamp' => time(),
        'filesize' => filesize($file->pdf),
      ))
      ->condition('fid', $fid)
      ->execute();
  }
}
