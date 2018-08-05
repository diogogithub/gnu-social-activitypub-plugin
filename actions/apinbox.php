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

/**
 * Inbox Request Handler
 *
 * @category  Plugin
 * @package   GNUsocial
 * @author    Diogo Cordeiro <diogo@fc.up.pt>
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://www.gnu.org/software/social/
 */
class apInboxAction extends ManagedAction
{
    protected $needLogin = false;
    protected $canPost   = true;

    /**
     * Handle the Inbox request
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @return void
     */
    protected function handle()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ActivityPubReturn::error('Only POST requests allowed.');
        }

        common_debug('ActivityPub Inbox: Received a POST request.');
        $data = file_get_contents('php://input');
        common_debug('ActivityPub Inbox: Request contents: '.$data);
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['actor'])) {
            ActivityPubReturn::error('Actor not found in the request.');
        }

        $actor = ActivityPub_explorer::get_profile_from_url($data['actor']);
        $actor_public_key = new Activitypub_rsa();
        $actor_public_key = $actor_public_key->ensure_public_key($actor);

        common_debug('ActivityPub Inbox: HTTP Signature: Validation will now start!');

        $headers = $this->get_all_headers();
        common_debug('ActivityPub Inbox: Request Headers: '.print_r($headers, true));

        // TODO: Validate HTTP Signature

        common_debug('ActivityPub Inbox: HTTP Signature: Authorized request. Will now start the inbox handler.');

        try {
            new Activitypub_inbox_handler($data, $actor);
            ActivityPubReturn::answer();
        } catch (Exception $e) {
            ActivityPubReturn::error($e->getMessage());
        }
    }

    /**
     * Get all HTTP header key/values as an associative array for the current request.
     *
     * @author PHP Manual Contributed Notes <joyview@gmail.com>
     * @return string[string] The HTTP header key/value pairs.
     */
    private function get_all_headers()
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[strtolower(str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5))))))] = $value;
            }
        }
        return $headers;
    }
}
