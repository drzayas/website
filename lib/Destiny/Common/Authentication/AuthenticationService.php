<?php
namespace Destiny\Common\Authentication;

use Destiny\Chat\ChatEmotes;
use Destiny\Common\Application;
use Destiny\Common\Crypto;
use Destiny\Common\Exception;
use Destiny\Common\Log;
use Destiny\Common\Utils\Date;
use Destiny\Common\Session;
use Destiny\Common\Service;
use Destiny\Common\SessionCredentials;
use Destiny\Common\User\UserRole;
use Destiny\Common\User\UserFeature;
use Destiny\Common\User\UserService;
use Destiny\Commerce\SubscriptionsService;
use Destiny\Chat\ChatIntegrationService;
use Doctrine\DBAL\DBALException;

/**
 * @method static AuthenticationService instance()
 */
class AuthenticationService extends Service {

    /**
     * @param string $username
     * @throws Exception
     */
    public function validateUsername($username) {
        if (empty ($username))
            throw new Exception ('Username required');

        if (preg_match('/^[A-Za-z0-9_]{3,20}$/', $username) == 0)
            throw new Exception ('Username may only contain A-z 0-9 or underscores and must be over 3 characters and under 20 characters in length.');

        // nick-to-emote similarity heuristics, not perfect sadly ;(
        $normalizeduname = strtolower($username);
        $front = substr($normalizeduname, 0, 2);
        foreach (ChatEmotes::get('destiny') as $emote) {
            $normalizedemote = strtolower($emote);
            if (strpos($normalizeduname, $normalizedemote) === 0)
                throw new Exception ('Username too similar to an emote, try changing the first characters');

            if ($emote == 'LUL')
                continue;

            $shortuname = substr($normalizeduname, 0, strlen($emote));
            $emotefront = substr($normalizedemote, 0, 2);
            if ($front == $emotefront and levenshtein($normalizedemote, $shortuname) <= 2)
                throw new Exception ('Username too similar to an emote, try changing the first characters');
        }

        if (preg_match_all('/[0-9]{3}/', $username, $m) > 0)
            throw new Exception ('Too many numbers in a row in username');

        if (preg_match_all('/[\_]{2}/', $username, $m) > 0 || preg_match_all("/[_]+/", $username, $m) > 2)
            throw new Exception ('Too many underscores in username');

        if (preg_match_all("/[0-9]/", $username, $m) > round(strlen($username) / 2))
            throw new Exception ('Number ratio is too high in username');
    }

    /**
     * @param string $email
     * @param array $user
     * @param null|boolean $skipusercheck
     * @throws DBALException
     * @throws Exception
     */
    public function validateEmail($email, array $user = null, $skipusercheck = null) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            throw new Exception ('A valid email is required');
        if (!$skipusercheck and !empty ($user)) {
            if (UserService::instance()->getIsEmailTaken($email, $user ['userId']))
                throw new Exception ('The email you asked for is already being used');
        } elseif (!$skipusercheck) {
            if (UserService::instance()->getIsEmailTaken($email))
                throw new Exception ('The email you asked for is already being used');
        }
        $emailDomain = strtolower(substr($email, strpos($email, '@') + 1));
        $blacklist = array_merge([], include _BASEDIR . '/config/domains.blacklist.php');
        if (in_array($emailDomain, $blacklist))
            throw new Exception ('email is blacklisted');
    }

    /**
     * Starts up the session, looks for remember me if there was no session
     * Also updates the session if the user is flagged for it.
     *
     * @throws \Exception
     */
    public function startSession() {
        $chatIntegrationService = ChatIntegrationService::instance();
        // If the session has a cookie, start it
        if (Session::hasSessionCookie() && Session::start() && Session::hasRole(UserRole::USER)) {
            $sessionId = Session::getSessionId();
            if (!empty($sessionId)) {
                $chatIntegrationService->renewChatSessionExpiration(Session::getSessionId());
            }
        }

        // Check the Remember me cookie if the session is invalid
        if (!Session::hasRole(UserRole::USER)) {
            $user = $this->getRememberMe();
            if (!empty($user)) {
                Session::start();
                Session::updateCredentials($this->buildUserCredentials($user, 'rememberme'));
                $this->setRememberMe($user);

                // flagUserForUpdate updates the credentials AGAIN, but since its low impact
                // Instead of doing the logic in two places
                $this->flagUserForUpdate($user['userId']);
            }
        }

        // Update the user if they have been flagged for an update
        if (Session::hasRole(UserRole::USER)) {
            $userId = Session::getCredentials()->getUserId();
            if (!empty($userId) && $this->isUserFlaggedForUpdate($userId)) {
                $user = UserService::instance()->getUserById($userId);
                if (!empty ($user)) {
                    $this->clearUserUpdateFlag($userId);
                    Session::updateCredentials($this->buildUserCredentials($user, 'session'));
                    // the refreshChatSession differs from this call, because only here we have access to the session id.
                    $chatIntegrationService->setChatSession(Session::getCredentials(), Session::getSessionId());
                }
            }
        }
    }

    /**
     * @param array $user
     * @param string $authProvider
     * @return SessionCredentials
     * @throws DBALException
     */
    public function buildUserCredentials(array $user, $authProvider) {
        $userService = UserService::instance();
        $credentials = new SessionCredentials ($user);
        $credentials->setAuthProvider($authProvider);
        $credentials->addRoles(UserRole::USER);
        $credentials->addFeatures($userService->getFeaturesByUserId($user ['userId']));
        $credentials->addRoles($userService->getRolesByUserId($user ['userId']));

        $sub = SubscriptionsService::instance()->getUserActiveSubscription($user ['userId']);
        if (!empty ($sub)) {
            $credentials->setSubscription([
                'start' => Date::getDateTime($sub['createdDate'])->format(Date::FORMAT),
                'end' => Date::getDateTime($sub['endDate'])->format(Date::FORMAT)
            ]);
        }
        if (!empty ($sub) or $user ['istwitchsubscriber']) {
            $credentials->addRoles(UserRole::SUBSCRIBER);
            $credentials->addFeatures(UserFeature::SUBSCRIBER);
        }
        if ($user['istwitchsubscriber'])
            $credentials->addFeatures(UserFeature::SUBSCRIBERT0);
        if (!empty($sub)) {
            if ($sub['subscriptionTier'] == 1)
                $credentials->addFeatures(UserFeature::SUBSCRIBERT1);
            else if ($sub['subscriptionTier'] == 2)
                $credentials->addFeatures(UserFeature::SUBSCRIBERT2);
            else if ($sub['subscriptionTier'] == 3)
                $credentials->addFeatures(UserFeature::SUBSCRIBERT3);
            else if ($sub['subscriptionTier'] == 4)
                $credentials->addFeatures(UserFeature::SUBSCRIBERT4);
        }
        return $credentials;
    }

    /**
     * @param AuthenticationCredentials $authCreds
     * @return array
     * @throws DBALException
     * @throws Exception
     */
    public function handleAuthCredentials(AuthenticationCredentials $authCreds) {
        $userService = UserService::instance();
        $user = $userService->getUserByAuthId($authCreds->getAuthId(), $authCreds->getAuthProvider());

        if (empty ($user)) {
            throw new Exception ('Invalid auth user');
        }

        // The user has register before...
        // Update the auth profile for this provider
        $authProfile = $userService->getUserAuthProfile($user ['userId'], $authCreds->getAuthProvider());
        if (!empty ($authProfile)) {
            $userService->updateUserAuthProfile($user ['userId'], $authCreds->getAuthProvider(), [
                'authCode' => $authCreds->getAuthCode(),
                'authDetail' => $authCreds->getAuthDetail()
            ]);
        }

        // Renew the session upon successful login, makes it slightly harder to hijack
        $session = Session::instance();
        $session->renew(true);

        $credentials = $this->buildUserCredentials($user, $authCreds->getAuthProvider());
        Session::updateCredentials($credentials);
        ChatIntegrationService::instance()->setChatSession($credentials, Session::getSessionId());
        return $user;
    }

    /**
     * Handles the authentication and then merging of accounts
     * Merging of an account is basically connecting multiple authenticators to one user
     *
     * @param AuthenticationCredentials $authCreds
     * @throws DBALException
     * @throws Exception
     */
    public function handleAuthAndMerge(AuthenticationCredentials $authCreds) {
        $userService = UserService::instance ();
        $user = $userService->getUserByAuthId ( $authCreds->getAuthId (), $authCreds->getAuthProvider () );
        $sessAuth = Session::getCredentials ()->getData ();
        // We need to merge the accounts if one exists
        if (! empty ( $user )) {
            // If the profile userId is the same as the current one, the profiles are connceted, they shouldn't be here
            if ($user ['userId'] == $sessAuth ['userId']) {
                throw new Exception ( 'These account are already connected' );
            }
            // If the profile user is older than the current user, prompt the user to rather login using the other profile
            if (intval ( $user ['userId'] ) < $sessAuth ['userId']) {
                throw new Exception ( sprintf ( 'Your user profile for the %s account is older. Please login and use that account to merge.', $authCreds->getAuthProvider () ) );
            }
            // So we have a profile for a different user to the one logged in, we delete that user, and add a profile for the current user
            $userService->removeAuthProfile ( $user ['userId'], $authCreds->getAuthProvider () );
            // Set the user profile to Merged
            $userService->updateUser ( $user ['userId'], ['userStatus' => 'Merged']);
        }
        $userService->addUserAuthProfile([
            'userId' => $sessAuth ['userId'],
            'authProvider' => $authCreds->getAuthProvider(),
            'authId' => $authCreds->getAuthId(),
            'authCode' => $authCreds->getAuthCode(),
            'authDetail' => $authCreds->getAuthDetail(),
            'refreshToken' => $authCreds->getRefreshToken()
        ]);
    }

    /**
     * Generates a rememberme cookie
     * Note the rememberme cookie has a long expiry unlike the session cookie
     *
     * @param array $user
     * @throws \Exception
     */
    public function setRememberMe(array $user) {
        $cookie = Session::instance()->getRememberMeCookie();
        $rawData = $cookie->getValue();
        if (! empty ( $rawData ))
            $cookie->clearCookie();
        $expires = Date::getDateTime (time() + mt_rand(0,2419200)); // 0-28 days
        $expires->add(new \DateInterval('P1M'));
        $data = Crypto::encrypt(serialize([
            'userId' => $user['userId'],
            'expires' => $expires->getTimestamp()
        ]));
        $cookie->setValue ( $data, Date::getDateTime ('NOW + 2 MONTHS')->getTimestamp() );
    }

    /**
     * Returns the user record associated with a remember me cookie
     * @return array
     *
     * @throws \Exception
     */
    protected function getRememberMe() {
        $cookie = Session::instance()->getRememberMeCookie();
        $rawData = $cookie->getValue();
        $user = null;
        if ( empty ( $rawData ))
            goto end;

        if(strlen($rawData) < 64)
            goto cleanup;

        $data = unserialize(Crypto::decrypt($rawData));
        if (! $data)
            goto cleanup;

        if (!isset($data['expires']) or !isset($data['userId']))
            goto cleanup;

        $expires = Date::getDateTime($data['expires']);
        if ($expires <= Date::getDateTime())
            goto cleanup;

        $user = UserService::instance()->getUserById(intval($data['userId']));
        goto end;

        cleanup:
        $cookie->clearCookie();
        end:
        return $user;
    }

    /**
     * Flag a user session for update
     * So that on their next request, the session data is updated.
     * Also does a chat session refresh
     *
     * @param array|number $user
     * @throws DBALException
     */
    public function flagUserForUpdate($user) {
        if (!is_array($user))
            $user = UserService::instance()->getUserById($user);
        if (!empty($user)) {
            $cache = Application::instance()->getCache();
            $cache->save('refreshusersession-' . $user['userId'], time(), intval(ini_get('session.gc_maxlifetime')));
            ChatIntegrationService::instance()->refreshChatUserSession($this->buildUserCredentials($user, 'session'));
        }
    }

    /**
     * @param $userId
     */
    protected function clearUserUpdateFlag($userId) {
        $cache = Application::instance()->getCache();
        $cache->delete('refreshusersession-' . $userId);
    }

    /**
     * @param int $userId
     * @return bool
     */
    protected function isUserFlaggedForUpdate($userId) {
        $cache = Application::instance()->getCache();
        $lastUpdated = $cache->fetch('refreshusersession-' . $userId);
        return !empty ($lastUpdated);
    }

    /**
     * @param AuthenticationCredentials $authCreds
     * @return string
     * @throws DBALException
     * @throws Exception
     */
    public function get(AuthenticationCredentials $authCreds) {
        $authService = AuthenticationService::instance();
        $userService = UserService::instance();

        // Make sure the creds are valid
        if (!$authCreds->isValid()) {
            Log::error('Error validating auth credentials {creds}', ['creds' => var_export($authCreds, true)]);
            throw new Exception ('Invalid auth credentials');
        }

        $email = $authCreds->getEmail();
        if (!empty($email))
            $authService->validateEmail($authCreds->getEmail(), null, true);

        // Account merge
        if (Session::getAndRemove('accountMerge') === '1') {
            // Must be logged in to do a merge
            if (!Session::hasRole(UserRole::USER)) {
                throw new Exception ('Authentication required for account merge');
            }
            $authService->handleAuthAndMerge($authCreds);
            return 'redirect: /profile/authentication';
        }

        // Follow url
        $follow = Session::getAndRemove('follow');
        // Remember me checkbox on login form
        $rememberme = Session::getAndRemove('rememberme');

        // If the user profile doesn't exist, go to the register page
        if (!$userService->getUserAuthProviderExists($authCreds->getAuthId(), $authCreds->getAuthProvider())) {
            Session::set('authSession', $authCreds);
            $url = '/register?code=' . urlencode($authCreds->getAuthCode());
            if (!empty($follow)) {
                $url .= '&follow=' . urlencode($follow);
            }
            return 'redirect: ' . $url;
        }

        // User exists, handle the auth
        $user = $authService->handleAuthCredentials($authCreds);
        try {
            if ($rememberme == true) {
                $authService->setRememberMe($user);
            }
        } catch (\Exception $e) {
            $n = new Exception('Failed to create remember me cookie.', $e);
            Log::error($n);
        }
        if (!empty ($follow) && substr($follow, 0, 1) == '/') {
            return 'redirect: ' . $follow;
        }
        return 'redirect: /profile';
    }

}