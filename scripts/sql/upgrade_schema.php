#!/usr/bin/env php
<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

$root = dirname(dirname(dirname(__FILE__)));
require_once $root.'/scripts/__init_script__.php';
require_once $root.'/scripts/__init_env__.php';

phutil_require_module('phutil', 'console');
phutil_require_module('phabricator', 'infrastructure/setup/sql');

define('SCHEMA_VERSION_TABLE_NAME', 'schema_version');

$options = getopt('v:u:p:') + array(
  'v' => null,
  'u' => null,
  'p' => null,
);

if ($options['v'] && !is_numeric($options['v'])) {
  usage();
}

echo phutil_console_wrap(
  "Before running this script, you should take down the Phabricator web ".
  "interface and stop any running Phabricator daemons.");

if (!phutil_console_confirm('Are you ready to continue?')) {
  echo "Cancelled.\n";
  exit(1);
}

// Use always the version from the commandline if it is defined
$next_version = isset($options['v']) ? (int)$options['v'] : null;

if ($options['u']) {
  $conn_user = $options['u'];
  $conn_pass = $options['p'];
} else {
  $conn_user = PhabricatorEnv::getEnvConfig('mysql.user');
  $conn_pass = PhabricatorEnv::getEnvConfig('mysql.pass');
}
$conn_host = PhabricatorEnv::getEnvConfig('mysql.host');

$conn = new AphrontMySQLDatabaseConnection(
  array(
    'user'      => $conn_user,
    'pass'      => $conn_pass,
    'host'      => $conn_host,
    'database'  => null,
  ));

try {

  $create_sql = <<<END
  CREATE DATABASE IF NOT EXISTS `phabricator_meta_data`;
END;
  queryfx($conn, $create_sql);

  $create_sql = <<<END
  CREATE TABLE IF NOT EXISTS phabricator_meta_data.`schema_version` (
    `version` INTEGER not null
  );
END;
  queryfx($conn, $create_sql);

  // Get the version only if commandline argument wasn't given
  if ($next_version === null) {
    $version = queryfx_one(
      $conn,
      'SELECT * FROM phabricator_meta_data.%T',
      SCHEMA_VERSION_TABLE_NAME);

    if (!$version) {
      print "*** No version information in the database ***\n";
      print "*** Give the first patch version which to  ***\n";
      print "*** apply as the command line argument     ***\n";
      exit(-1);
    }

    $next_version = $version['version'] + 1;
  }

  $patches = PhabricatorSQLPatchList::getPatchList();

  $patch_applied = false;
  foreach ($patches as $patch) {
    if ($patch['version'] < $next_version) {
      continue;
    }

    $short_name = basename($patch['path']);
    print "Applying patch {$short_name}...\n";

    list($stdout, $stderr) = execx(
      "mysql --user=%s --password=%s --host=%s < %s",
      $conn_user,
      $conn_pass,
      $conn_host,
      $patch['path']);

    if ($stderr) {
      print $stderr;
      exit(-1);
    }

    // Patch was successful, update the db with the latest applied patch version
    // 'DELETE' and 'INSERT' instead of update, because the table might be empty
    queryfx(
      $conn,
      'DELETE FROM phabricator_meta_data.%T',
      SCHEMA_VERSION_TABLE_NAME);
    queryfx(
      $conn,
      'INSERT INTO phabricator_meta_data.%T VALUES (%d)',
      SCHEMA_VERSION_TABLE_NAME,
      $patch['version']);

    $patch_applied = true;
  }

  if (!$patch_applied) {
    print "Your database is already up-to-date.\n";
  }

} catch (AphrontQueryAccessDeniedException $ex) {
  echo
    "ACCESS DENIED\n".
    "The user '{$conn_user}' does not have sufficient MySQL privileges to\n".
    "execute the schema upgrade. Use the -u and -p flags to run as a user\n".
    "with more privileges (e.g., root).".
    "\n\n".
    "EXCEPTION:\n".
    $ex->getMessage().
    "\n\n";
  exit(1);
}

function usage() {
  echo
    "usage: upgrade_schema.php [-v version] [-u user -p pass]".
    "\n\n".
    "Run 'upgrade_schema.php -v 12' to apply all patches starting from ".
    "version 12.\n".
    "Run 'upgrade_schema.php -u root -p hunter2' to override the configured ".
    "default user.\n";
  exit(1);
}

