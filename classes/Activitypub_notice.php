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
 * @author    Daniel Supernault <danielsupernault@gmail.com>
 * @author    Diogo Cordeiro <diogo@fc.up.pt>
 * @copyright 2018 Free Software Foundation http://fsf.org
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      https://www.gnu.org/software/social/
 */
if (!defined('GNUSOCIAL')) {
    exit(1);
}

/**
 * ActivityPub notice representation
 *
 * @category  Plugin
 * @package   GNUsocial
 * @author    Diogo Cordeiro <diogo@fc.up.pt>
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://www.gnu.org/software/social/
 */
class Activitypub_notice extends Managed_DataObject
{
    /**
     * Generates a pretty notice from a Notice object
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Notice $notice
     * @return pretty array to be used in a response
     */
    public static function notice_to_array($notice)
    {
        $profile = $notice->getProfile();
        $attachments = [];
        foreach ($notice->attachments() as $attachment) {
            $attachments[] = Activitypub_attachment::attachment_to_array($attachment);
        }

        $tags = [];
        foreach ($notice->getTags() as $tag) {
            if ($tag != "") {       // Hacky workaround to avoid stupid outputs
                $tags[] = Activitypub_tag::tag_to_array($tag);
            }
        }

        $cc = [common_local_url('apActorFollowers', ['id' => $profile->getID()])];
        foreach ($notice->getAttentionProfiles() as $to_profile) {
            $cc[]  = $href = $to_profile->getUri();
            $tags[] = Activitypub_mention_tag::mention_tag_to_array_from_values($href, $to_profile->getNickname().'@'.parse_url($href, PHP_URL_HOST));
        }

        // In a world without walls and fences, we should make everything Public!
        $to[]= 'https://www.w3.org/ns/activitystreams#Public';

        $item = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'           => common_local_url('apNotice', ['id' => $notice->getID()]),
            'type'         => 'Note',
            'published'    => str_replace(' ', 'T', $notice->getCreated()).'Z',
            'url'          => $notice->getUrl(),
            'attributedTo' => ActivityPubPlugin::actor_uri($profile),
            'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc'           => $cc,
            'atomUri'      => $notice->getUrl(),
            'conversation' => $notice->getConversationUrl(),
            'content'      => $notice->getRendered(),
            'isLocal'      => $notice->isLocal(),
            'attachment'   => $attachments,
            'tag'          => $tags
        ];

        // Is this a reply?
        if (!empty($notice->reply_to)) {
            $item['inReplyTo'] = Notice::getById($notice->reply_to)->getUrl();
            $item['inReplyToAtomUri'] = Notice::getById($notice->reply_to)->getUrl();
        }

        // Do we have a location for this notice?
        try {
            $location = Notice_location::locFromStored($notice);
            $item['latitude']  = $location->lat;
            $item['longitude'] = $location->lon;
        } catch (Exception $e) {
            // Apparently no.
        }

        return $item;
    }

    /**
     * Create a Notice via ActivityPub Note Object.
     * Returns created Notice.
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Array $object
     * @param Profile|null $actor_profile
     * @return Notice
     * @throws Exception
     */
    public static function create_notice($object, $actor_profile = null)
    {
        $id      = $object['id'];         // int32
        $url     = $object['url'];        // string
        $content = $object['content'];    // string

        // possible keys: ['inReplyTo', 'latitude', 'longitude', 'attachment']
        $settings = [];
        if (isset($object['inReplyTo'])) {
            $settings['inReplyTo'] = $object['inReplyTo'];
        }
        if (isset($object['latitude'])) {
            $settings['latitude']  = $object['latitude'];
        }
        if (isset($object['longitude'])) {
            $settings['longitude'] = $object['longitude'];
        }
        if (isset($object['attachment'])) {
            $settings['attachment'] = $object['attachment'];
        }

        // Ensure Actor Profile
        if (is_null($actor_profile)) {
            $actor_profile = ActivityPub_explorer::get_profile_from_url($object['actor']);
        }

        $act = new Activity();
        $act->verb = ActivityVerb::POST;
        $act->time = time();
        $act->actor = $actor_profile->asActivityObject();
        $act->context = new ActivityContext();
        $options = ['source' => 'ActivityPub', 'uri' => $id, 'url' => $url];

        // Do we have an attachment?
        if (isset($settings['attachment'][0])) {
            $attach = $settings['attachment'][0];
            $attach_url = $settings['attachment'][0]['url'];
            // Is it an image?
            if (ActivityPubPlugin::$store_images_from_remote_notes_attachments && substr($attach["mediaType"], 0, 5) == "image") {
                $temp_filename = tempnam(sys_get_temp_dir(), 'apCreateNoteAttach_');
                try {
                    $imgData = HTTPClient::quickGet($attach_url);
                    // Make sure it's at least an image file. ImageFile can do the rest.
                    if (false === getimagesizefromstring($imgData)) {
                        common_debug('ActivityPub Create Notice: Failed because the downloaded image: '.$attach_url. 'is not valid.');
                        throw new UnsupportedMediaException('Downloaded image was not an image.');
                    }
                    file_put_contents($temp_filename, $imgData);
                    common_debug('ActivityPub Create Notice: Stored dowloaded image in: '.$temp_filename);

                    $id = $actor_profile->getID();

                    $imagefile = new ImageFile(null, $temp_filename);
                    $filename = hash(File::FILEHASH_ALG, $imgData).image_type_to_extension($imagefile->type);

                    unset($imgData);    // No need to carry this in memory.
                    rename($temp_filename, File::path($filename));
                    common_debug('ActivityPub Create Notice: Moved image from: '.$temp_filename.' to '.$filename);
                    $mediaFile = new MediaFile($filename, $attach['mediaType']);
                    $act->enclosures[] = $mediaFile->getEnclosure();
                } catch (Exception $e) {
                    common_debug('ActivityPub Create Notice: Something went wrong while processing the image from: '.$attach_url.' details: '.$e->getMessage());
                    unlink($temp_filename);
                }
            }
            $content .= ($content==='' ? '' : ' ') . '<br><a href="'.$attach_url.'">Remote Attachment Source</a>';
        }

        // Is this a reply?
        if (isset($settings['inReplyTo'])) {
            try {
                $inReplyTo = ActivityPubPlugin::grab_notice_from_url($settings['inReplyTo']);
            } catch (Exception $e) {
                throw new Exception('Invalid Object inReplyTo value: '.$e->getMessage());
            }
            $act->context->replyToID  = $inReplyTo->getUri();
            $act->context->replyToUrl = $inReplyTo->getUrl();
        } else {
            $inReplyTo = null;
        }

        // Mentions
        $mentions = [];
        if (isset($object['tag']) && is_array($object['tag'])) {
            foreach ($object['tag'] as $tag) {
                if ($tag['type'] == 'Mention') {
                    $mentions[] = $tag['href'];
                }
            }
        }
        $mentions_profiles = [];
        $discovery = new Activitypub_explorer;
        foreach ($mentions as $mention) {
            try {
                $mentions_profiles[] = $discovery->lookup($mention)[0];
            } catch (Exception $e) {
                // Invalid actor found, just let it go. // TODO: Fallback to OStatus
            }
        }
        unset($discovery);

        foreach ($mentions_profiles as $mp) {
            $act->context->attention[ActivityPubPlugin::actor_uri($mp)] = 'http://activitystrea.ms/schema/1.0/person';
        }

        // Add location if that is set
        if (isset($settings['latitude'], $settings['longitude'])) {
            $act->context->location = Location::fromLatLon($settings['latitude'], $settings['longitude']);
        }

        // Reject notice if it is too long (without the HTML)
        if (Notice::contentTooLong($content)) {
            //throw new Exception('That\'s too long. Maximum notice size is %d character.');
        }

        $actobj = new ActivityObject();
        $actobj->type = ActivityObject::NOTE;
        $actobj->content = strip_tags($content, '<p><b><i><u><a><ul><ol><li>');

        // Finally add the activity object to our activity
        $act->objects[] = $actobj;

        $note = Notice::saveActivity($act, $actor_profile, $options);
        if (ActivityPubPlugin::$store_images_from_remote_notes_attachments && isset($mediaFile)) {
            $mediaFile->attachToNotice($note);
        }
        return $note;
    }

    /**
     * Validates a note.
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param  Array $object
     * @throws Exception
     */
    public static function validate_note($object)
    {
        if (!isset($object['attributedTo'])) {
            common_debug('ActivityPub Notice Validator: Rejected because attributedTo was not specified.');
            throw new Exception('No attributedTo specified.');
        }
        if (!isset($object['id'])) {
            common_debug('ActivityPub Notice Validator: Rejected because Object ID was not specified.');
            throw new Exception('Object ID not specified.');
        } elseif (!filter_var($object['id'], FILTER_VALIDATE_URL)) {
            common_debug('ActivityPub Notice Validator: Rejected because Object ID is invalid.');
            throw new Exception('Invalid Object ID.');
        }
        if (!isset($object['type']) || $object['type'] !== 'Note') {
            common_debug('ActivityPub Notice Validator: Rejected because of Type.');
            throw new Exception('Invalid Object type.');
        }
        if (!isset($object['content'])) {
            common_debug('ActivityPub Notice Validator: Rejected because Content was not specified.');
            throw new Exception('Object content was not specified.');
        }
        if (!isset($object['url'])) {
            throw new Exception('Object URL was not specified.');
        } elseif (!filter_var($object['url'], FILTER_VALIDATE_URL)) {
            common_debug('ActivityPub Notice Validator: Rejected because Object URL is invalid.');
            throw new Exception('Invalid Object URL.');
        }
        if (!isset($object['cc'])) {
            common_debug('ActivityPub Notice Validator: Rejected because Object CC was not specified.');
            throw new Exception('Object CC was not specified.');
        }
        return true;
    }
}
