<?php

namespace Drupal\social_auth_amazon;

use Drupal\social_auth\AuthManager\OAuth2Manager;
use Drupal\Core\Config\ConfigFactory;

/**
 * Contains all the logic for Amazon login integration.
 */
class AmazonAuthManager extends OAuth2Manager {

  /**
   * The Amazon client object.
   *
   * @var \Luchianenco\OAuth2\Client\Provider\Amazon
   */
  protected $client;

  /**
   * The Amazon user.
   *
   * @var \Luchianenco\OAuth2\Client\Provider\AmazonResourceOwner
   */
  protected $user;

  /**
   * The Social Auth Amazon settings.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $settings;

  /**
   * The data point to be collected.
   *
   * @var string
   */
  protected $scopes;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactory $configFactory
   *   Used for accessing configuration object factory.
   */
  public function __construct(ConfigFactory $configFactory) {
    $this->settings = $configFactory->getEditable('social_auth_amazon.settings');
  }

  /**
   * Authenticates the users by using the access token.
   */
  public function authenticate() {
    $this->setAccessToken($this->client->getAccessToken('authorization_code',
      ['code' => $_GET['code']]));
  }

  /**
   * Gets the data by using the access token returned.
   *
   * @return \Luchianenco\OAuth2\Client\Provider\AmazonResourceOwner
   *   User info returned by the Amazon.
   */
  public function getUserInfo() {
    $this->user = $this->client->getResourceOwner($this->getAccessToken());
    return $this->user;
  }

  /**
   * Gets the data by using the access token returned.
   *
   * @param string $url
   *   The API call url.
   *
   * @return string
   *   Data returned by API call.
   */
  public function getExtraDetails($url) {
    if ($url) {
      $httpRequest = $this->client->getAuthenticatedRequest('GET', $url, $this->getAccessToken(), []);
      $data = $this->client->getResponse($httpRequest);
      return json_decode($data->getBody(), TRUE);
    }

    return FALSE;
  }

  /**
   * Returns the Amazon login URL where user will be redirected.
   *
   * @return string
   *   Absolute Amazon login URL where user will be redirected.
   */
  public function getAmazonLoginUrl() {
    $scopes = $this->getScopes();

    $login_url = $this->client->getAuthorizationUrl([
      'scope' => $scopes,
    ]);

    // Generate and return the URL where we should redirect the user.
    return $login_url;
  }

  /**
   * Returns the Amazon login URL where user will be redirected.
   *
   * @return string
   *   Absolute Amazon login URL where user will be redirected
   */
  public function getState() {
    $state = $this->client->getState();

    // Generate and return the URL where we should redirect the user.
    return $state;
  }

  /**
   * Gets the data Point defined the settings form page.
   *
   * @return string
   *   Comma-separated scopes.
   */
  public function getScopes() {
    if (!$this->scopes) {
      $this->scopes = $this->settings->get('scopes');
    }
    return $this->scopes;
  }

  /**
   * Gets the API calls to collect data.
   *
   * @return string
   *   Comma-separated API calls.
   */
  public function getApiCalls() {
    return $this->settings->get('api_calls');
  }

}
