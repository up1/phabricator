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

class PhabricatorUserLog extends PhabricatorUserDAO {

  const ACTION_LOGIN          = 'login';
  const ACTION_LOGOUT         = 'logout';
  const ACTION_LOGIN_FAILURE  = 'login-fail';
  const ACTION_RESET_PASSWORD = 'reset-pass';

  const ACTION_CREATE         = 'create';

  const ACTION_ADMIN          = 'admin';
  const ACTION_DISABLE        = 'disable';

  protected $actorPHID;
  protected $userPHID;
  protected $action;
  protected $oldValue;
  protected $newValue;
  protected $details = array();
  protected $remoteAddr;
  protected $session;

  public static function newLog(
    PhabricatorUser $actor = null,
    PhabricatorUser $user = null,
    $action) {

    $log = new PhabricatorUserLog();

    if ($actor) {
      $log->setActorPHID($actor->getPHID());
    }

    if ($user) {
      $log->setUserPHID($user->getPHID());
    }

    if ($action) {
      $log->setAction($action);
    }

    return $log;
  }

  public function save() {
    if (!$this->remoteAddr) {
      $this->remoteAddr = idx($_SERVER, 'REMOTE_ADDR');
    }
    if (!$this->session) {
      $this->setSession(idx($_COOKIE, 'phsid'));
    }
    $this->details['host'] = php_uname('n');
    $this->details['user_agent'] = idx($_SERVER, 'HTTP_USER_AGENT');

    return parent::save();
  }

  public function setSession($session) {
    // Store the hash of the session, not the actual session key, so that
    // seeing the logs doesn't compromise all the sessions which appear in
    // them. This just prevents casual leaks, like in a screenshot.
    if (strlen($session)) {
      $this->session = sha1($session);
    }
    return $this;
  }

  public function getConfiguration() {
    return array(
      self::CONFIG_SERIALIZATION => array(
        'oldValue' => self::SERIALIZATION_JSON,
        'newValue' => self::SERIALIZATION_JSON,
        'details'  => self::SERIALIZATION_JSON,
      ),
    ) + parent::getConfiguration();
  }

}
