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

use GuzzleHttp\Client;
use HttpSignatures\Context;
use HttpSignatures\GuzzleHttpSignatures;

/**
 * ActivityPub's own Postman
 *
 * Standard workflow expects that we send an Explorer to find out destinataries'
 * inbox address. Then we send our postman to deliver whatever we want to send them.
 *
 * @category  Plugin
 * @package   GNUsocial
 * @author    Diogo Cordeiro <diogo@fc.up.pt>
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://www.gnu.org/software/social/
 */
class Activitypub_postman
{
    private $actor;
    private $actor_uri;
    private $to = [];
    private $client;
    private $headers;

    /**
     * Create a postman to deliver something to someone
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Profile $from Profile of sender
     * @param Array of Activitypub_profile $to destinataries
     */
    public function __construct($from, $to = [])
    {
        $this->actor = $from;
        $this->to = $to;
        $this->actor_uri = ActivityPubPlugin::actor_uri($this->actor);

        $actor_private_key = new Activitypub_rsa();
        $actor_private_key = $actor_private_key->get_private_key($this->actor);

        $context = new Context([
            'keys' => [$this->actor_uri."#public-key" => $actor_private_key],
            'algorithm' => 'rsa-sha256',
            'headers' => ['(request-target)', 'date', 'content-type', 'accept', 'user-agent'],
        ]);

        $this->to = $to;
        $this->headers = [
            'content-type' => 'application/activity+json',
            'accept'       => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
            'user-agent'   => 'GNUSocialBot v0.1 - https://gnu.io/social',
            'date'         => date('D, d M Y h:i:s') . ' GMT'
        ];

        $handlerStack = GuzzleHttpSignatures::defaultHandlerFromContext($context);
        $this->client = new Client(['handler' => $handlerStack]);
    }

    /**
     * Send something to remote instance
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param string $data request body
     * @param string $inbox url of remote inbox
     * @param string $method request method
     * @return Psr\Http\Message\ResponseInterface
     */
    public function send($data, $inbox, $method = 'POST')
    {
        common_debug('ActivityPub Postman: Delivering '.$data.' to '.$inbox);
        $response = $this->client->request($method, $inbox, ['headers' => array_merge($this->headers, ['(request-target)' => strtolower($method).' '.parse_url($inbox, PHP_URL_PATH)]),'body' => $data]);
        common_debug('ActivityPub Postman: Delivery result with status code '.$response->getStatusCode().': '.$response->getBody()->getContents());
        return $response;
    }

    /**
     * Send a follow notification to remote instance
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @throws Exception
     */
    public function follow()
    {
        $data = Activitypub_follow::follow_to_array(ActivityPubPlugin::actor_uri($this->actor), $this->to[0]->getUrl());
        $res = $this->send(json_encode($data, JSON_UNESCAPED_SLASHES), $this->to[0]->get_inbox());
        $res_body = json_decode($res->getBody()->getContents());

        if ($res->getStatusCode() == 200 || $res->getStatusCode() == 202 || $res->getStatusCode() == 409) {
            $pending_list = new Activitypub_pending_follow_requests($this->actor->getID(), $this->to[0]->getID());
            $pending_list->add();
            return true;
        } elseif (isset($res_body[0]->error)) {
            throw new Exception($res_body[0]->error);
        }

        throw new Exception("An unknown error occurred.");
    }

    /**
     * Send a Undo Follow notification to remote instance
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     */
    public function undo_follow()
    {
        $data = Activitypub_undo::undo_to_array(
                    Activitypub_follow::follow_to_array(
                        ActivityPubPlugin::actor_uri($this->actor),
                        $this->to[0]->getUrl()
                    )
                );
        $res = $this->send(json_encode($data, JSON_UNESCAPED_SLASHES), $this->to[0]->get_inbox());
        $res_body = json_decode($res->getBody()->getContents());

        if ($res->getStatusCode() == 200 || $res->getStatusCode() == 202 || $res->getStatusCode() == 409) {
            $pending_list = new Activitypub_pending_follow_requests($this->actor->getID(), $this->to[0]->getID());
            $pending_list->remove();
            return true;
        }
        if (isset($res_body[0]->error)) {
            throw new Exception($res_body[0]->error);
        }
        throw new Exception("An unknown error occurred.");
    }

    /**
     * Send a Accept Follow notification to remote instance
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     */
    public function accept_follow()
    {
        $data = Activitypub_accept::accept_to_array(
                    Activitypub_follow::follow_to_array(
                       $this->to[0]->getUrl(),
                       ActivityPubPlugin::actor_uri($this->actor)

                    )
               );
        $res = $this->send(json_encode($data, JSON_UNESCAPED_SLASHES), $this->to[0]->get_inbox());
        $res_body = json_decode($res->getBody()->getContents());

        if ($res->getStatusCode() == 200 || $res->getStatusCode() == 202 || $res->getStatusCode() == 409) {
            $pending_list = new Activitypub_pending_follow_requests($this->actor->getID(), $this->to[0]->getID());
            $pending_list->remove();
            return true;
        }
        if (isset($res_body[0]->error)) {
            throw new Exception($res_body[0]->error);
        }
        throw new Exception("An unknown error occurred.");
    }

    /**
     * Send a Like notification to remote instances holding the notice
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Notice $notice
     */
    public function like($notice)
    {
        $data = Activitypub_like::like_to_array(
                    ActivityPubPlugin::actor_uri($this->actor),
                    $notice->getUrl()
                );
        $data = json_encode($data, JSON_UNESCAPED_SLASHES);

        foreach ($this->to_inbox() as $inbox) {
            $this->send($data, $inbox);
        }
    }

    /**
     * Send a Undo Like notification to remote instances holding the notice
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Notice $notice
     */
    public function undo_like($notice)
    {
        $data = Activitypub_undo::undo_to_array(
                         Activitypub_like::like_to_array(
                             ActivityPubPlugin::actor_uri($this->actor),
                            $notice->getUrl()
                         )
                );
        $data = json_encode($data, JSON_UNESCAPED_SLASHES);

        foreach ($this->to_inbox() as $inbox) {
            $this->send($data, $inbox);
        }
    }

    /**
     * Send a Create notification to remote instances
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Notice $notice
     */
    public function create($notice)
    {
        $data = Activitypub_create::create_to_array(
                    $this->actor_uri,
                    Activitypub_notice::notice_to_array($notice)
                );
        if (isset($notice->reply_to)) {
            $data["object"]["reply_to"] = $notice->getParent()->getUrl();
        }
        $data = json_encode($data, JSON_UNESCAPED_SLASHES);

        foreach ($this->to_inbox() as $inbox) {
            $this->send($data, $inbox);
        }
    }

    /**
     * Send a Announce notification to remote instances
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Notice $notice
     */
    public function announce($notice)
    {
        $data = Activitypub_announce::announce_to_array(
                         ActivityPubPlugin::actor_uri($this->actor),
                         $notice->getUri()
                        );
        $data = json_encode($data, JSON_UNESCAPED_SLASHES);

        foreach ($this->to_inbox() as $inbox) {
            $this->send($data, $inbox);
        }
    }

    /**
     * Send a Delete notification to remote instances holding the notice
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Notice $notice
     */
    public function delete($notice)
    {
        $data = Activitypub_delete::delete_to_array(
                    ActivityPubPlugin::actor_uri($notice->getProfile()),
                    $notice->getUrl()
                );
        $errors = [];
        $data = json_encode($data, JSON_UNESCAPED_SLASHES);
        foreach ($this->to_inbox() as $inbox) {
            $res = $this->send($data, $inbox);
            if (!$res->getStatusCode() == 200) {
                $res_body = json_decode($res->getBody()->getContents(), true);
                if (isset($res_body[0]['error'])) {
                    $errors[] = ($res_body[0]['error']);
                    continue;
                }
                $errors[] = ("An unknown error occurred.");
            }
        }
        if (!empty($errors)) {
            throw new Exception(json_encode($errors));
        }
    }

    /**
     * Clean list of inboxes to deliver messages
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @return array To Inbox URLs
     */
    private function to_inbox()
    {
        $to_inboxes = [];
        foreach ($this->to as $to_profile) {
            $i = $to_profile->get_inbox();
            // Prevent delivering to self
            if ($i == [common_local_url('apInbox')]) {
                continue;
            }
            $to_inboxes[] = $i;
        }

        return array_unique($to_inboxes);
    }
}
