<?php
/**
 * GNU social - a federating social network
 *
 * ActivityPubPlugin implementation for GNU Social
 *
 * LICENCE: This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @category  Plugin
 * @package   GNUsocial
 * @author    Diogo Cordeiro <diogo@fc.up.pt>
 * @author    Daniel Supernault <danielsupernault@gmail.com>
 * @copyright 2018 Free Software Foundation http://fsf.org
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      https://www.gnu.org/software/social/
 */
if (!defined('GNUSOCIAL')) {
    exit(1);
}

// Ensure proper timezone
date_default_timezone_set('UTC');

// Import required files by the plugin
require __DIR__.'/vendor/autoload.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . "utils" . DIRECTORY_SEPARATOR . "discoveryhints.php";
require_once __DIR__ . DIRECTORY_SEPARATOR . "utils" . DIRECTORY_SEPARATOR . "explorer.php";
require_once __DIR__ . DIRECTORY_SEPARATOR . "utils" . DIRECTORY_SEPARATOR . "postman.php";

// So that this isn't hardcoded everywhere
define('ACTIVITYPUB_BASE_INSTANCE_URI', common_root_url()."index.php/user/");

/**
 * @category  Plugin
 * @package   GNUsocial
 * @author    Diogo Cordeiro <diogo@fc.up.pt>
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://www.gnu.org/software/social/
 */
class ActivityPubPlugin extends Plugin
{
    /**
     * Returns a Actor's URI from its local $profile
     * Works both for local and remote users.
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Profile $profile Actor's local profile
     * @return string Actor's URI
     */
    public static function actor_uri($profile)
    {
        if ($profile->isLocal()) {
            return ACTIVITYPUB_BASE_INSTANCE_URI.$profile->getID();
        } else {
            return $profile->getUri();
        }
    }

    /**
     * Returns a Actor's URL from its local $profile
     * Works both for local and remote users.
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Profile $profile Actor's local profile
     * @return string Actor's URL
     */
    public static function actor_url($profile)
    {
        return ActivityPubPlugin::actor_uri($profile)."/";
    }

    /**
     * Returns a notice from its URL since GNU Social doesn't provide
     * this functionality
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param string $url Notice's URL
     * @return Notice The Notice object
     * @throws Exception This function or provides a Notice or fails with exception
     */
    public static function get_local_notice_from_url($url)
    {
        try {
            return Notice::getByUri($url);
        } catch (Exception $e) {
            try {
                $candidate = Notice::getByID(intval(substr($url, strlen(common_local_url('shownotice', ['notice' => ''])))));
                if ($candidate->getUrl() == $url) { // Sanity check
                    return $candidate;
                } else {
                    throw new Exception("Notice not found.");
                }
            } catch (Exception $e) {
                throw new Exception("Notice not found.");
            }
        }
    }

    /**
     * Route/Reroute urls
     *
     * @param URLMapper $m
     * @return void
     */
    public function onRouterInitialized(URLMapper $m)
    {
        ActivityPubURLMapperOverwrite::variable(
            $m,
            'user/:id',
            ['id'     => '[0-9]+'],
            'apActorProfile'
        );

        // Special route for webfinger purposes
        ActivityPubURLMapperOverwrite::variable(
            $m,
            ':nickname',
            ['nickname' => Nickname::DISPLAY_FMT],
            'apActorProfile'
        );

        ActivityPubURLMapperOverwrite::variable(
            $m,
            'notice/:id',
            ['id'     => '[0-9]+'],
            'apNotice'
        );

        $m->connect(
            'user/:id/liked.json',
                    ['action'    => 'apActorLiked'],
                    ['id' => '[0-9]+']
                );

        $m->connect(
            'user/:id/followers.json',
                    ['action'    => 'apActorFollowers'],
                    ['id' => '[0-9]+']
                );

        $m->connect(
            'user/:id/following.json',
                    ['action'    => 'apActorFollowing'],
                    ['id' => '[0-9]+']
                );

        $m->connect(
            'user/:id/inbox.json',
                    ['action' => 'apActorInbox'],
                    ['id' => '[0-9]+']
                );

        $m->connect(
            'inbox.json',
                    ['action' => 'apSharedInbox']
                );
    }

    /**
     * Plugin version information
     *
     * @param array $versions
     * @return boolean hook true
     */
    public function onPluginVersion(array &$versions)
    {
        $versions[] = [ 'name' => 'ActivityPub',
                                'version' => GNUSOCIAL_VERSION,
                                'author' => 'Diogo Cordeiro, Daniel Supernault',
                                'homepage' => 'https://www.gnu.org/software/social/',
                                'rawdescription' => 'Adds ActivityPub Support'];

        return true;
    }

    /**
     * Dummy string on AccountProfileBlock stating that ActivityPub is active
     * this is more of a placeholder for eventual useful stuff ._.
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @return boolean hook return value
     */
    public function onEndShowAccountProfileBlock(HTMLOutputter $out, Profile $profile)
    {
        if ($profile->isLocal()) {
            return true;
        }
        try {
            $aprofile = Activitypub_profile::getKV('profile_id', $profile->id);
        } catch (NoResultException $e) {
            // Not a remote ActivityPub_profile! Maybe some other network
            // that has imported a non-local user (e.g.: OStatus)?
            return true;
        }

        $out->elementStart('dl', 'entity_tags activitypub_profile');
        $out->element('dt', null, _m('ActivityPub'));
        $out->element('dd', null, _m('Active'));
        $out->elementEnd('dl');
    }

    /**
     * Make sure necessary tables are filled out.
     *
     * @return boolean hook true
     */
    public function onCheckSchema()
    {
        $schema = Schema::get();
        $schema->ensureTable('Activitypub_profile', Activitypub_profile::schemaDef());
        $schema->ensureTable('Activitypub_rsa', Activitypub_rsa::schemaDef());
        $schema->ensureTable('Activitypub_pending_follow_requests', Activitypub_pending_follow_requests::schemaDef());
        return true;
    }

    /********************************************************
     *                   WebFinger Events                   *
     ********************************************************/

    /**
     * Get remote user's ActivityPub_profile via a identifier
     *
     * @author GNU Social
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param string $arg A remote user identifier
     * @return Activitypub_profile|null Valid profile in success | null otherwise
     */
    public static function pull_remote_profile($arg)
    {
        if (preg_match('!^((?:\w+\.)*\w+@(?:\w+\.)*\w+(?:\w+\-\w+)*\.\w+)$!', $arg)) {
            // webfinger lookup
            try {
                return Activitypub_profile::ensure_web_finger($arg);
            } catch (Exception $e) {
                common_log(LOG_ERR, 'Webfinger lookup failed for ' .
                                                $arg . ': ' . $e->getMessage());
            }
        }

        // Look for profile URLs, with or without scheme:
        $urls = array();
        if (preg_match('!^https?://((?:\w+\.)*\w+(?:\w+\-\w+)*\.\w+(?:/\w+)+)$!', $arg)) {
            $urls[] = $arg;
        }
        if (preg_match('!^((?:\w+\.)*\w+(?:\w+\-\w+)*\.\w+(?:/\w+)+)$!', $arg)) {
            $schemes = array('http', 'https');
            foreach ($schemes as $scheme) {
                $urls[] = "$scheme://$arg";
            }
        }

        foreach ($urls as $url) {
            try {
                return Activitypub_profile::fromUri($url);
            } catch (Exception $e) {
                common_log(LOG_ERR, 'Profile lookup failed for ' .
                                                $arg . ': ' . $e->getMessage());
            }
        }
        return null;
    }

    /**
    * Webfinger matches: @user@example.com or even @user--one.george_orwell@1984.biz
    *
    * @author GNU Social
    * @param   string  $text       The text from which to extract webfinger IDs
    * @param   string  $preMention Character(s) that signals a mention ('@', '!'...)
    * @return  array   The matching IDs (without $preMention) and each respective position in the given string.
    */
    public static function extractWebfingerIds($text, $preMention='@')
    {
        $wmatches = [];
        $result = preg_match_all(
                    '/(?<!\S)'.preg_quote($preMention, '/').'('.Nickname::WEBFINGER_FMT.')/',
                    $text,
                    $wmatches,
                    PREG_OFFSET_CAPTURE
                );
        if ($result === false) {
            common_log(LOG_ERR, __METHOD__ . ': Error parsing webfinger IDs from text (preg_last_error=='.preg_last_error().').');
            return [];
        } elseif ($n_matches = count($wmatches)) {
            common_debug(sprintf('Found %d matches for WebFinger IDs: %s', $n_matches, _ve($wmatches)));
        }
        return $wmatches[1];
    }

    /**
     * Profile URL matches: @example.com/mublog/user
     *
     * @author GNU Social
     * @param   string  $text       The text from which to extract URL mentions
     * @param   string  $preMention Character(s) that signals a mention ('@', '!'...)
     * @return  array   The matching URLs (without @ or acct:) and each respective position in the given string.
     */
    public static function extractUrlMentions($text, $preMention='@')
    {
        $wmatches = array();
        // In the regexp below we need to match / _before_ URL_REGEX_VALID_PATH_CHARS because it otherwise gets merged
        // with the TLD before (but / is in URL_REGEX_VALID_PATH_CHARS anyway, it's just its positioning that is important)
        $result = preg_match_all(
                    '/(?:^|\s+)'.preg_quote($preMention, '/').'('.URL_REGEX_DOMAIN_NAME.'(?:\/['.URL_REGEX_VALID_PATH_CHARS.']*)*)/',
                                $text,
                                $wmatches,
                                PREG_OFFSET_CAPTURE
                );
        if ($result === false) {
            common_log(LOG_ERR, __METHOD__ . ': Error parsing profile URL mentions from text (preg_last_error=='.preg_last_error().').');
        } elseif (count($wmatches)) {
            common_debug(sprintf('Found %d matches for profile URL mentions: %s', count($wmatches), _ve($wmatches)));
        }
        return $wmatches[1];
    }

    /**
     * Add activity+json mimetype on WebFinger
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param XML_XRD $xrd
     * @param Managed_DataObject $object
     */
    public function onEndWebFingerProfileLinks(XML_XRD &$xrd, Managed_DataObject $object)
    {
        if ($object->isPerson()) {
            $link = new XML_XRD_Element_Link(
                'self',
                     ActivityPubPlugin::actor_uri($object->getProfile()),
                    'application/activity+json'
            );
            $xrd->links[] = clone ($link);
        }
    }

    /**
     * Find any explicit remote mentions. Accepted forms:
     *   Webfinger: @user@example.com
     *   Profile link: @example.com/mublog/user
     *
     * @author GNU Social
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Profile $sender
     * @param string $text input markup text
     * @param array &$mention in/out param: set of found mentions
     * @return boolean hook return value
     */
    public function onEndFindMentions(Profile $sender, $text, &$mentions)
    {
        $matches = array();

        foreach (self::extractWebfingerIds($text, '@') as $wmatch) {
            list($target, $pos) = $wmatch;
            $this->log(LOG_INFO, "Checking webfinger person '$target'");
            $profile = null;
            try {
                $aprofile = Activitypub_profile::ensure_web_finger($target);
                $profile = $aprofile->local_profile();
            } catch (Exception $e) {
                $this->log(LOG_ERR, "Webfinger check failed: " . $e->getMessage());
                continue;
            }
            assert($profile instanceof Profile);

            $displayName = !empty($profile->nickname) && mb_strlen($profile->nickname) < mb_strlen($target)
                        ? $profile->getNickname()   // TODO: we could do getBestName() or getFullname() here
                        : $target;
            $url = $profile->getUri();
            if (!common_valid_http_url($url)) {
                $url = $profile->getUrl();
            }
            $matches[$pos] = array('mentioned' => array($profile),
                                               'type' => 'mention',
                                               'text' => $displayName,
                                               'position' => $pos,
                                               'length' => mb_strlen($target),
                                               'url' => $url);
        }

        foreach (self::extractUrlMentions($text) as $wmatch) {
            list($target, $pos) = $wmatch;
            $schemes = array('https', 'http');
            foreach ($schemes as $scheme) {
                $url = "$scheme://$target";
                $this->log(LOG_INFO, "Checking profile address '$url'");
                try {
                    $aprofile = Activitypub_profile::fromUri($url);
                    $profile = $aprofile->local_profile();
                    $displayName = !empty($profile->nickname) && mb_strlen($profile->nickname) < mb_strlen($target) ?
                                        $profile->nickname : $target;
                    $matches[$pos] = array('mentioned' => array($profile),
                                                               'type' => 'mention',
                                                               'text' => $displayName,
                                                               'position' => $pos,
                                                               'length' => mb_strlen($target),
                                                               'url' => $profile->getUrl());
                    break;
                } catch (Exception $e) {
                    $this->log(LOG_ERR, "Profile check failed: " . $e->getMessage());
                }
            }
        }

        foreach ($mentions as $i => $other) {
            // If we share a common prefix with a local user, override it!
            $pos = $other['position'];
            if (isset($matches[$pos])) {
                $mentions[$i] = $matches[$pos];
                unset($matches[$pos]);
            }
        }
        foreach ($matches as $mention) {
            $mentions[] = $mention;
        }

        return true;
    }

    /**
     * Allow remote profile references to be used in commands:
     *   sub update@status.net
     *   whois evan@identi.ca
     *   reply http://identi.ca/evan hey what's up
     *
     * @author GNU Social
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Command $command
     * @param string $arg
     * @param Profile &$profile
     * @return hook return code
     */
    public function onStartCommandGetProfile($command, $arg, &$profile)
    {
        try {
            $aprofile = $this->pull_remote_profile($arg);
            $profile = $aprofile->local_profile();
        } catch (Exception $e) {
            // No remote ActivityPub profile found
            return true;
        }

        return false;
    }

    /********************************************************
     *                   Discovery Events                   *
     ********************************************************/

    /**
     * Profile URI for remote profiles.
     *
     * @author GNU Social
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Profile $profile
     * @param string $uri in/out
     * @return mixed hook return code
     */
    public function onStartGetProfileUri($profile, &$uri)
    {
        $aprofile = Activitypub_profile::getKV('profile_id', $profile->id);
        if ($aprofile instanceof Activitypub_profile) {
            $uri = $aprofile->getUri();
            return false;
        }
        return true;
    }

    /**
     * Profile from URI.
     *
     * @author GNU Social
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param string $uri
     * @param Profile &$profile in/out param: Profile got from URI
     * @return mixed hook return code
     */
    public function onStartGetProfileFromURI($uri, &$profile)
    {
        try {
            $explorer = new Activitypub_explorer();
            $profile = $explorer->lookup($uri)[0];
            return false;
        } catch (Exception $e) {
            return true; // It's not an ActivityPub profile as far as we know, continue event handling
        }
    }

    /********************************************************
     *                    Delivery Events                   *
     ********************************************************/

    /**
     * Having established a remote subscription, send a notification to the
     * remote ActivityPub profile's endpoint.
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Profile $profile  subscriber
     * @param Profile $other    subscribee
     * @return hook return value
     * @throws Exception
     */
    public function onStartSubscribe(Profile $profile, Profile $other)
    {
        if (!$profile->isLocal() && $other->isLocal()) {
            return true;
        }

        try {
            $other = Activitypub_profile::from_profile($other);
        } catch (Exception $e) {
            return true;
        }

        $postman = new Activitypub_postman($profile, array($other));

        $postman->follow();

        return true;
    }

    /**
     * Notify remote server on unsubscribe.
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Profile $profile
     * @param Profile $other
     * @return hook return value
     */
    public function onStartUnsubscribe(Profile $profile, Profile $other)
    {
        if (!$profile->isLocal() && $other->isLocal()) {
            return true;
        }

        try {
            $other = Activitypub_profile::from_profile($other);
        } catch (Exception $e) {
            return true;
        }

        $postman = new Activitypub_postman($profile, array($other));

        $postman->undo_follow();

        return true;
    }

    /**
     * Notify remote users when their notices get favorited.
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Profile $profile of local user doing the faving
     * @param Notice $notice Notice being favored
     * @return hook return value
     */
    public function onEndFavorNotice(Profile $profile, Notice $notice)
    {
        // Only distribute local users' favor actions, remote users
        // will have already distributed theirs.
        if (!$profile->isLocal()) {
            return true;
        }

        $other = array();
        try {
            $other[] = Activitypub_profile::from_profile($notice->getProfile());
        } catch (Exception $e) {
            // Local user can be ignored
        }
        foreach ($notice->getAttentionProfiles() as $to_profile) {
            try {
                $other[] = Activitypub_profile::from_profile($to_profile);
            } catch (Exception $e) {
                // Local user can be ignored
            }
        }
        if ($notice->reply_to) {
            try {
                $other[] = Activitypub_profile::from_profile($notice->getParent()->getProfile());
            } catch (Exception $e) {
                // Local user can be ignored
            }
            try {
                $mentions = $notice->getParent()->getAttentionProfiles();
                foreach ($mentions as $to_profile) {
                    try {
                        $other[] = Activitypub_profile::from_profile($to_profile);
                    } catch (Exception $e) {
                        // Local user can be ignored
                    }
                }
            } catch (NoParentNoticeException $e) {
                // This is not a reply to something (has no parent)
            } catch (NoResultException $e) {
                // Parent author's profile not found! Complain louder?
                common_log(LOG_ERR, "Parent notice's author not found: ".$e->getMessage());
            }
        }

        $postman = new Activitypub_postman($profile, $other);

        $postman->like($notice);

        return true;
    }

    /**
     * Notify remote users when their notices get de-favorited.
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Profile $profile of local user doing the de-faving
     * @param Notice  $notice  Notice being favored
     * @return hook return value
     */
    public function onEndDisfavorNotice(Profile $profile, Notice $notice)
    {
        // Only distribute local users' favor actions, remote users
        // will have already distributed theirs.
        if (!$profile->isLocal()) {
            return true;
        }

        $other = array();
        try {
            $other[] = Activitypub_profile::from_profile($notice->getProfile());
        } catch (Exception $e) {
            // Local user can be ignored
        }
        foreach ($notice->getAttentionProfiles() as $to_profile) {
            try {
                $other[] = Activitypub_profile::from_profile($to_profile);
            } catch (Exception $e) {
                // Local user can be ignored
            }
        }
        if ($notice->reply_to) {
            try {
                $other[] = Activitypub_profile::from_profile($notice->getParent()->getProfile());
            } catch (Exception $e) {
                // Local user can be ignored
            }
            try {
                $mentions = $notice->getParent()->getAttentionProfiles();
                foreach ($mentions as $to_profile) {
                    try {
                        $other[] = Activitypub_profile::from_profile($to_profile);
                    } catch (Exception $e) {
                        // Local user can be ignored
                    }
                }
            } catch (NoParentNoticeException $e) {
                // This is not a reply to something (has no parent)
            } catch (NoResultException $e) {
                // Parent author's profile not found! Complain louder?
                common_log(LOG_ERR, "Parent notice's author not found: ".$e->getMessage());
            }
        }

        $postman = new Activitypub_postman($profile, $other);

        $postman->undo_like($notice);

        return true;
    }

    /**
     * Notify remote users when their notices get deleted
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @return boolean hook flag
     */
    public function onStartDeleteOwnNotice($user, $notice)
    {
        $profile = $user->getProfile();

        // Only distribute local users' delete actions, remote users
        // will have already distributed theirs.
        if (!$profile->isLocal()) {
            return true;
        }

        $other = array();

        foreach ($notice->getAttentionProfiles() as $to_profile) {
            try {
                $other[] = Activitypub_profile::from_profile($to_profile);
            } catch (Exception $e) {
                // Local user can be ignored
            }
        }
        if ($notice->reply_to) {
            try {
                $other[] = Activitypub_profile::from_profile($notice->getParent()->getProfile());
            } catch (Exception $e) {
                // Local user can be ignored
            }
            try {
                $mentions = $notice->getParent()->getAttentionProfiles();
                foreach ($mentions as $to_profile) {
                    try {
                        $other[] = Activitypub_profile::from_profile($to_profile);
                    } catch (Exception $e) {
                        // Local user can be ignored
                    }
                }
            } catch (NoParentNoticeException $e) {
                // This is not a reply to something (has no parent)
            } catch (NoResultException $e) {
                // Parent author's profile not found! Complain louder?
                common_log(LOG_ERR, "Parent notice's author not found: ".$e->getMessage());
            }
        }

        $postman = new Activitypub_postman($profile, $other);
        $postman->delete($notice);
        return true;
    }

    /**
     * Insert notifications for replies, mentions and repeats
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @return boolean hook flag
     */
    public function onStartNoticeDistribute($notice)
    {
        assert($notice->id > 0);        // Ignore if not a valid notice

        $profile = Profile::getKV($notice->profile_id);

        if (!$profile->isLocal()) {
            return true;
        }

        $other = array();
        try {
            $other[] = Activitypub_profile::from_profile($notice->getProfile());
        } catch (Exception $e) {
            // Local user can be ignored
        }
        foreach ($notice->getAttentionProfiles() as $to_profile) {
            try {
                $other[] = Activitypub_profile::from_profile($to_profile);
            } catch (Exception $e) {
                // Local user can be ignored
            }
        }

        // Is Announce
        if ($notice->isRepeat()) {
            $repeated_notice = Notice::getKV('id', $notice->repeat_of);
            if ($repeated_notice instanceof Notice) {
                try {
                    $other[] = Activitypub_profile::from_profile($repeated_notice->getProfile());
                } catch (Exception $e) {
                    // Local user can be ignored
                }

                $postman = new Activitypub_postman($profile, $other);

                // That was it
                $postman->announce($repeated_notice);
                return true;
            }
        }

        // Ignore for activity/non-post-verb notices
        if (method_exists('ActivityUtils', 'compareVerbs')) {
            $is_post_verb = ActivityUtils::compareVerbs(
                            $notice->verb,
                            [ActivityVerb::POST]
                        );
        } else {
            $is_post_verb = ($notice->verb == ActivityVerb::POST ? true : false);
        }
        if ($notice->source == 'activity' || !$is_post_verb) {
            return true;
        }

        // Create
        if ($notice->reply_to) {
            try {
                $other[] = Activitypub_profile::from_profile($notice->getParent()->getProfile());
            } catch (Exception $e) {
                // Local user can be ignored
            }
            try {
                $mentions = $notice->getParent()->getAttentionProfiles();
                foreach ($mentions as $to_profile) {
                    try {
                        $other[] = Activitypub_profile::from_profile($to_profile);
                    } catch (Exception $e) {
                        // Local user can be ignored
                    }
                }
            } catch (NoParentNoticeException $e) {
                // This is not a reply to something (has no parent)
            } catch (NoResultException $e) {
                // Parent author's profile not found! Complain louder?
                common_log(LOG_ERR, "Parent notice's author not found: ".$e->getMessage());
            }
        }
        $postman = new Activitypub_postman($profile, $other);

        // That was it
        $postman->create($notice);
        return true;
    }

    /**
     * Override the "from ActivityPub" bit in notice lists to link to the
     * original post and show the domain it came from.
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Notice in $notice
     * @param string out &$name
     * @param string out &$url
     * @param string out &$title
     * @return mixed hook return code
     */
    public function onStartNoticeSourceLink($notice, &$name, &$url, &$title)
    {
        // If we don't handle this, keep the event handler going
        if (!in_array($notice->source, array('ActivityPub', 'share'))) {
            return true;
        }

        try {
            $url = $notice->getUrl();
            // If getUrl() throws exception, $url is never set

            $bits = parse_url($url);
            $domain = $bits['host'];
            if (substr($domain, 0, 4) == 'www.') {
                $name = substr($domain, 4);
            } else {
                $name = $domain;
            }

            // TRANS: Title. %s is a domain name.
            $title = sprintf(_m('Sent from %s via ActivityPub'), $domain);

            // Abort event handler, we have a name and URL!
            return false;
        } catch (InvalidUrlException $e) {
            // This just means we don't have the notice source data
            return true;
        }
    }
}

/**
 * Plugin return handler
 */
class ActivityPubReturn
{
    /**
     * Return a valid answer
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param array $res
     * @return void
     */
    public static function answer($res, $code = 200)
    {
        http_response_code($code);
        header('Content-Type: application/activity+json');
        echo json_encode($res, JSON_UNESCAPED_SLASHES | (isset($_GET["pretty"]) ? JSON_PRETTY_PRINT : null));
        exit;
    }

    /**
     * Return an error
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param string $m
     * @param int32 $code
     * @return void
     */
    public static function error($m, $code = 500)
    {
        http_response_code($code);
        header('Content-Type: application/activity+json');
        $res[] = Activitypub_error::error_message_to_array($m);
        echo json_encode($res, JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Select content type from HTTP Accept header
     *
     * @author Maciej Łebkowski <m.lebkowski@gmail.com>
     * @param array $mimeTypes Supported Types
     * @return array|null of supported mime types sorted | null if none valid
     */
    public static function getBestSupportedMimeType($mimeTypes = null)
    {
        if (!isset($_SERVER['HTTP_ACCEPT'])) {
            return null;
        }

        // Values will be stored in this array
        $AcceptTypes = [];

        // Accept header is case insensitive, and whitespace isn’t important
        $accept = strtolower(str_replace(' ', '', $_SERVER['HTTP_ACCEPT']));
        // divide it into parts in the place of a ","
        $accept = explode(',', $accept);
        foreach ($accept as $a) {
            // the default quality is 1.
            $q = 1;
            // check if there is a different quality
            if (strpos($a, ';q=')) {
                // divide "mime/type;q=X" into two parts: "mime/type" i "X"
                list($a, $q) = explode(';q=', $a);
            }
            // mime-type $a is accepted with the quality $q
            // WARNING: $q == 0 means, that mime-type isn’t supported!
            $AcceptTypes[$a] = $q;
        }
        arsort($AcceptTypes);

        // if no parameter was passed, just return parsed data
        if (!$mimeTypes) {
            return $AcceptTypes;
        }

        $mimeTypes = array_map('strtolower', (array)$mimeTypes);

        // let’s check our supported types:
        foreach ($AcceptTypes as $mime => $q) {
            if ($q && in_array($mime, $mimeTypes)) {
                return $mime;
            }
        }
        // no mime-type found
        return null;
    }
}

/**
 * Overwrites variables in URL-mapping
 */
class ActivityPubURLMapperOverwrite extends URLMapper
{
    public static function variable($m, $path, $paramPatterns, $newaction)
    {
        $mimes = [
            'application/json',
            'application/activity+json',
            'application/ld+json',
            'application/ld+json; profile="https://www.w3.org/ns/activitystreams"'
        ];

        if (is_null(ActivityPubReturn::getBestSupportedMimeType($mimes))) {
            return true;
        }

        $m->connect($path, array('action' => $newaction), $paramPatterns);
        $regex = self::makeRegex($path, $paramPatterns);
        foreach ($m->variables as $n => $v) {
            if ($v[1] == $regex) {
                $m->variables[$n][0]['action'] = $newaction;
            }
        }
    }
}
