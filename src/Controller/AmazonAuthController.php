<?php

namespace Drupal\social_auth_amazon\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\social_api\Plugin\NetworkManager;
use Drupal\social_auth\SocialAuthDataHandler;
use Drupal\social_auth\SocialAuthUserManager;
use Drupal\social_auth_amazon\AmazonAuthManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Returns responses for Social Auth Amazon module routes.
 */
class AmazonAuthController extends ControllerBase {

  /**
   * The network plugin manager.
   *
   * @var \Drupal\social_api\Plugin\NetworkManager
   */
  private $networkManager;

  /**
   * The user manager.
   *
   * @var \Drupal\social_auth\SocialAuthUserManager
   */
  private $userManager;

  /**
   * The amazon authentication manager.
   *
   * @var \Drupal\social_auth_amazon\AmazonAuthManager
   */
  private $amazonManager;

  /**
   * Used to access GET parameters.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $request;

  /**
   * The Social Auth Data Handler.
   *
   * @var \Drupal\social_auth\SocialAuthDataHandler
   */
  private $dataHandler;

  /**
   * AmazonAuthController constructor.
   *
   * @param \Drupal\social_api\Plugin\NetworkManager $network_manager
   *   Used to get an instance of social_auth_amazon network plugin.
   * @param \Drupal\social_auth\SocialAuthUserManager $user_manager
   *   Manages user login/registration.
   * @param \Drupal\social_auth_amazon\AmazonAuthManager $amazon_manager
   *   Used to manage authentication methods.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request
   *   Used to access GET parameters.
   * @param \Drupal\social_auth\SocialAuthDataHandler $data_handler
   *   SocialAuthDataHandler object.
   */
  public function __construct(NetworkManager $network_manager,
                              SocialAuthUserManager $user_manager,
                              AmazonAuthManager $amazon_manager,
                              RequestStack $request,
                              SocialAuthDataHandler $data_handler) {

    $this->networkManager = $network_manager;
    $this->userManager = $user_manager;
    $this->amazonManager = $amazon_manager;
    $this->request = $request;
    $this->dataHandler = $data_handler;

    // Sets the plugin id.
    $this->userManager->setPluginId('social_auth_amazon');

    // Sets the session keys to nullify if user could not logged in.
    $this->userManager->setSessionKeysToNullify(['access_token', 'oauth2state']);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.network.manager'),
      $container->get('social_auth.user_manager'),
      $container->get('social_auth_amazon.manager'),
      $container->get('request_stack'),
      $container->get('social_auth.data_handler')
    );
  }

  /**
   * Response for path 'user/login/amazon'.
   *
   * Redirects the user to Amazon for authentication.
   */
  public function redirectToAmazon() {
    /* @var \Luchianenco\OAuth2\Client\Provider\Amazon false $amazon */
    $amazon = $this->networkManager->createInstance('social_auth_amazon')->getSdk();

    // If amazon client could not be obtained.
    if (!$amazon) {
      drupal_set_message($this->t('Social Auth Amazon not configured properly. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    // Destination parameter specified in url.
    $destination = $this->request->getCurrentRequest()->get('destination');
    // If destination parameter is set, save it.
    if ($destination) {
      $this->userManager->setDestination($destination);
    }

    // Amazon service was returned, inject it to $amazonManager.
    $this->amazonManager->setClient($amazon);

    // Generates the URL where the user will be redirected for Amazon login.
    // If the user did not have email permission granted on previous attempt,
    // we use the re-request URL requesting only the email address.
    $amazon_login_url = $this->amazonManager->getAmazonLoginUrl();

    $state = $this->amazonManager->getState();

    $this->dataHandler->set('oauth2state', $state);

    return new TrustedRedirectResponse($amazon_login_url);
  }

  /**
   * Response for path 'user/login/amazon/callback'.
   *
   * Amazon returns the user here after user has authenticated in Amazon.
   */
  public function callback() {
    // Checks if user cancel login via Amazon.
    $error = $this->request->getCurrentRequest()->get('error');
    if ($error == 'access_denied') {
      drupal_set_message($this->t('You could not be authenticated.'), 'error');
      return $this->redirect('user.login');
    }

    /* @var \Luchianenco\OAuth2\Client\Provider\Amazon|false $amazon */
    $amazon = $this->networkManager->createInstance('social_auth_amazon')->getSdk();

    // If Amazon client could not be obtained.
    if (!$amazon) {
      drupal_set_message($this->t('Social Auth Amazon not configured properly. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    $state = $this->dataHandler->get('oauth2state');

    // Retrieves $_GET['state'].
    $retrievedState = $this->request->getCurrentRequest()->query->get('state');
    if (empty($retrievedState) || ($retrievedState !== $state)) {
      $this->userManager->nullifySessionKeys();
      drupal_set_message($this->t('Amazon login failed. Unvalid OAuth2 state.'), 'error');
      return $this->redirect('user.login');
    }

    // Saves access token to session.
    $this->dataHandler->set('access_token', $this->amazonManager->getAccessToken());

    $this->amazonManager->setClient($amazon)->authenticate();

    // Gets user's info from Amazon API.
    if (!$amazon_profile = $this->amazonManager->getUserInfo()) {
      drupal_set_message($this->t('Amazon login failed, could not load Amazon profile. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    // Store the data mapped with data points define is
    // social_auth_amazon settings.
    $data = [];

    if (!$this->userManager->checkIfUserExists($amazon_profile->getId())) {
      $api_calls = explode(PHP_EOL, $this->amazonManager->getApiCalls());

      // Iterate through api calls define in settings and try to retrieve them.
      foreach ($api_calls as $api_call) {

        $call = $this->amazonManager->getExtraDetails($api_call);
        array_push($data, $call);
      }
    }
    // If user information could be retrieved.
    return $this->userManager->authenticateUser($amazon_profile->getName(), $amazon_profile->getEmail(), $amazon_profile->getId(), $this->amazonManager->getAccessToken(), json_encode($data));
  }

}
