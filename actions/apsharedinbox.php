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
 * Shared Inbox Handler
 *
 * @category  Plugin
 * @package   GNUsocial
 * @author    Diogo Cordeiro <diogo@fc.up.pt>
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://www.gnu.org/software/social/
 */
class apSharedInboxAction extends ManagedAction
{
    protected $needLogin = false;
    protected $canPost   = true;

    /**
     * Handle the Shared Inbox request
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @return void
     */
    protected function handle()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ActivityPubReturn::error('Only POST requests allowed.');
        }

        common_debug('ActivityPub Shared Inbox: Received a POST request.');
        $data = file_get_contents('php://input');
        common_debug('ActivityPub Shared Inbox: Request contents: '.$data);
        $data = json_decode(file_get_contents('php://input'), true);

        // Validate data
        if (!isset($data['type'])) {
            ActivityPubReturn::error('Type was not specified.');
        }
        if (!isset($data['actor'])) {
            ActivityPubReturn::error('Actor was not specified.');
        }
        if (!isset($data['object'])) {
            ActivityPubReturn::error('Object was not specified.');
        }

        // Get valid Actor object
        try {
            $actor_profile = ActivityPub_explorer::get_profile_from_url($data['actor']);
        } catch (Exception $e) {
            ActivityPubReturn::error($e->getMessage(), 404);
        }

        $cc = [];

        // Process request
        define('INBOX_HANDLERS', __DIR__ . DIRECTORY_SEPARATOR . 'inbox' . DIRECTORY_SEPARATOR);
        switch ($data['type']) {
            // Data available:
            // Profile       $actor_profile Actor performing the action
            // string|object $data->object  Object to be handled
            // Array|String  $cc            Destinataries
            // string|object $data->object
            case 'Create':
                $cc  = $data['object']['cc'];
                $res = $data['object'];
                require_once INBOX_HANDLERS . 'Create.php';
                break;
            case 'Follow':
                require_once INBOX_HANDLERS . 'Follow.php';
                break;
            case 'Like':
                require_once INBOX_HANDLERS . 'Like.php';
                break;
            case 'Announce':
                require_once INBOX_HANDLERS . 'Announce.php';
                break;
            case 'Undo':
                require_once INBOX_HANDLERS . 'Undo.php';
                break;
            case 'Delete':
                require_once INBOX_HANDLERS . 'Delete.php';
                break;
            case 'Accept':
                require_once INBOX_HANDLERS . 'Accept.php';
                break;
            case 'Reject':
                require_once INBOX_HANDLERS . 'Reject.php';
                break;
            default:
                ActivityPubReturn::error('Invalid type value.');
        }
    }
}
