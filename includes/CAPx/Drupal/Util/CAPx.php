<?php
/**
 * @file
 * @author [author] <[email]>
 */

namespace CAPx\Drupal\Util;
use CAPx\APILib\HTTPClient;
use Guzzle\Http\Exception\ClientErrorResponseException;

class CAPx {

  /**
   * Returns a fully loaded entity from the DB
   * @param  [type] $profileId [description]
   * @return [type]            [description]
   */
  public static function getEntityByProfileId($entityType, $bundleType, $profileId) {
    // @TODO: CACHE THIS!

    $entityId = CAPx::getEntityIdByProfileId($entityType, $bundleType, $profileId);

    if (!$entityId) {
      return FALSE;
    }

    $entity =  entity_load_single($entityType, $entityId);
    return $entity;

  }

  /**
   * [getEntityIdByProfileId description]
   * @param  [type] $entityType [description]
   * @param  [type] $bundleType [description]
   * @param  [type] $profileId  [description]
   * @return [type]             [description]
   */
  public static function getEntityIdByProfileId($entityType, $bundleType, $profileId) {

    $query = db_select("capx_profiles", 'capx')
      ->fields('capx', array('entity_id'))
      ->condition('entity_type', $entityType)
      ->condition('bundle_type', $bundleType)
      ->condition('profile_id', $profileId)
      ->orderBy('id', 'DESC')
      ->execute()
      ->fetchAssoc();

    return isset($query['entity_id']) ? $query['entity_id'] : FALSE;

  }

  /**
   * Returns the profile Id
   * @param  [type] $entity [description]
   * @return [type]         [description]
   */
  public static function getProfileIdByEntity($entity) {
    $id = $entity->getIdentifier();
    $entityType = $entity->type();
    $bundleType = $entity->getBundle();

    $query = db_select("capx_profiles", 'capx')
      ->fields('capx', array('profile_id'))
      ->condition('entity_type', $entityType)
      ->condition('bundle_type', $bundleType)
      ->condition('entity_id', $id)
      ->orderBy('id', 'DESC')
      ->execute()
      ->fetchAssoc();

    return isset($query['profile_id']) ? $query['profile_id'] : FALSE;
  }

  /**
   * [insertNewProfileRecord description]
   * @param  [type] $entity [description]
   * @return [type]         [description]
   */
  public static function insertNewProfileRecord($entity, $profileId, $etag = '') {
    $id = $entity->getIdentifier();
    $entityType = $entity->type();
    $bundleType = $entity->getBundle();

    $record = array(
      'entity_type' => $entityType,
      'entity_id' => $id,
      'importer' => '',
      'profile_id' => $profileId,
      'etag' => $etag,
      'bundle_type' => $bundleType,
      'sync' => 1,
    );

    $yes = drupal_write_record('capx_profiles', $record);

    if (!$yes) {
      watchdog('CAPx', 'Could not insert record for capx_profiles on profile id: ' . $profileId, array(), WATCHDOG_ERROR);
    }
  }

  /**
   * [insertNewProfileRecord description]
   * @param  [type] $entity must be wrapped in entity_metadata_wrapper
   * @return [type]         [description]
   */
  public static function deleteProfileRecord($entity) {

    // BEAN is returning its delta when using this.
    //$id = $entity->getIdentifier();

    $entityType = $entity->type();
    $entityRaw = $entity->raw();
    list($id, $vid, $bundle) = entity_extract_ids($entityType, $entityRaw);

    db_delete('capx_profiles')
      ->condition('entity_type', $entityType)
      ->condition('entity_id', $id)
      ->execute();
  }

  /**
   * Test that both the API and Auth endpoints work.
   * @return [type] [description]
   */
  public static function testConnection() {
    $auth = CAPx::testAuthConnection();
    $api  = CAPx::testApiConnection();

    if ($auth->status && $api->status) {
      return $auth;
    }

    if (!$auth->status) {
      return $auth;
    }

    return $api;
  }

  /**
   * [testConnection description]
   * @return [type] [description]
   */
  public static function testAuthConnection($username = null, $password = null, $authpoint = null) {

    $return = (object) array(
      'status' => 0,
      'message' => t('connection failed'),
      'code' => 0,
    );

    $username = is_null($username)    ? decrypt(variable_get('stanford_capx_username', '')) : $username;
    $password = is_null($password)    ? decrypt(variable_get('stanford_capx_password', '')) : $password;
    $authpoint = is_null($authpoint)  ? variable_get('stanford_capx_api_auth_uri', '')      : $authpoint;

    $client = new HTTPClient();
    $client->setEndpoint($authpoint);

    try {
      $auth = $client->api('auth')->authenticate($username, $password);
    }
    catch(\Exception $e) {
      $return->message = t($e->getMessage());
      return $return;
    }

    $token = $auth->getAuthApiToken();
    $response = $auth->getLastResponse();
    $reasonPhrase = $response->getReasonPhrase();
    $code = $response->getStatusCode();

    $return->code = $code;
    $return->message = t($reasonPhrase);

    if (!empty($token)) {
      $return->status = 1;
      $return->token = $token;
    }

    return $return;
  }


  /**
   * Tests a token against the API for validity
   * @param  [type] $token [description]
   * @return object        $object->value
   *                       $obj
   */
  public static function testApiConnection($token = null, $endpoint = null) {

    $token    = is_null($token) ? variable_get('stanford_capx_token','') : $token;
    $endpoint = is_null($endpoint) ? variable_get('stanford_capx_api_base_url', '') : $endpoint;

    $return = (object) array(
      'status' => 0,
      'message' => t('API connection failed'),
      'code' => "ERROR: ",
    );

    $client = new HTTPClient();
    $client->setEndpoint($endpoint);
    $client->setApiToken($token);

    try {
      $results = $client->api('search')->keyword('test');
    }
    catch(\Exception $e) {
      $return->message = $e->getMessage();
      return $return;
    }

    if (is_array($results)) {
      $return->status = 1;
      $return->message = t("connection successfull");
      $return->code = 200;
    }

    return $return;
  }

  /**
   * [renewConnectionToken description]
   * @return [type] [description]
   */
  public static function renewConnectionToken() {

    $username   = decrypt(variable_get('stanford_capx_username', ''));
    $password   = decrypt(variable_get('stanford_capx_password', ''));
    $authpoint  = variable_get('stanford_capx_api_auth_uri', '');

    $client = new HTTPClient();
    $client->setEndpoint($authpoint);
    $response = $client->api('auth')->authenticate($username, $password);

    if ($response) {
      $token = $response->getAuthApiToken();
      variable_set('stanford_capx_token', $token);
      return TRUE;
    }

    throw new Exception("Could not authenticate with server.");
  }

  /**
   * Returns an authenticated HTTP Client for use.
   * @return HTTPClient an authenticated HTTP client ready to use.
   */
  public static function getAuthenticatedHTTPClient() {
    $username   = decrypt(variable_get('stanford_capx_username', ''));
    $password   = decrypt(variable_get('stanford_capx_password', ''));
    $token      = variable_get('stanford_capx_token', '');
    $endpoint   = variable_get('stanford_capx_api_base_url', '');
    $authpoint  = variable_get('stanford_capx_api_auth_uri', '');

    $connection = CAPx::testConnectionToken($token);

    if (!$connection->value) {
      $client = new HTTPClient();
      $client->setEndpoint($authpoint);
      $response = $client->api('auth')->authenticate($username, $password);
      if ($response) {
        $token = $response->getAuthApiToken();
        variable_set('stanford_capx_token', $token);
      }
      else {
        throw new \Exception("Could not authenticate with API server.");
      }
    }

    $client->setApiToken($token);
    $client->setEndpoint($endpoint);
    return $client;
  }

}
