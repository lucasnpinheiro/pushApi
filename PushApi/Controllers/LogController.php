<?php

namespace PushApi\Controllers;

use \PushApi\PushApiException;
use \PushApi\Models\Log;
use \PushApi\Models\User;
use \PushApi\Models\Theme;
use \PushApi\Models\Channel;
use \PushApi\Models\Preference;
use \PushApi\Models\Subscription;
use \PushApi\Controllers\Controller;
use \PushApi\Controllers\QueueController;
use \Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @author Eloi Ballarà Madrid <eloi@tviso.com>
 *
 * Contains the general actions in order to send the messages (retriving actor data and
 * transforming it in order to be correctly queued)
 */
class LogController extends Controller
{
    private $androidUsers = array();
    private $iosUsers = array();
    private $message = '';

    /**
     * Given the different parameters, it is ordered to check the range of the message and
     * if the users wants to recive that message (included it's preferences email/smartphone).
     * Once the information is obtained it is stored a log of the call and it is queued in
     * order to be sent when server can do it.
     * If user hasn't set preferences from that theme, default send is to all ranges. There is
     * only one possibility that the user doesn't recive notifications, he has to set the preference
     * of that theme. Otherwise, he can recive emails (if smartphones aren't set).
     */
	public function sendMessage()
	{
        $this->message = $this->slim->request->post('message');
        $this->theme = $this->slim->request->post('theme');
        $userId = (int) $this->slim->request->post('user_id');
        $channel = $this->slim->request->post('channel');
        
        /**
         * The most important first values to check are message and theme because if theme it's
         * multicast we don't need to check the other parameters
         */
        if (!isset($this->message) && !isset($this->theme)) {
            throw new PushApiException(PushApiException::NO_DATA, "Expected case param");
        }

        // Search if preference exist and if true, it gets all the users that have set preferences.
        $theme = Theme::with('preferences.user')->where('name', $this->theme)->first();
        if (!$theme) {
            throw new PushApiException(PushApiException::NOT_FOUND, "Theme doesn't exist");
        }

        $log = new Log;

        switch ($theme->range) {
            // If theme has this range, checks if the user has set its preferences and prepares the message.
            case Theme::UNICAST:

                if (!isset($userId)) {
                    throw new PushApiException(PushApiException::NO_DATA, "Expected user_id param");
                }

                $user = false;
                // Searching user into theme preferences (if the user exist we don't need to do sql search)
                foreach ($theme->preferences->toArray() as $key => $preferenceUser) {
                    if ($preferenceUser['user']['id'] == $userId) {
                        $user = $preferenceUser['user'];
                        $preference = decbin($preferenceUser['option']);
                    }
                }

                if (!$user) {
                    try {
                        $user = User::findOrFail($userId);
                    } catch (ModelNotFoundException $e) {
                        throw new PushApiException(PushApiException::NOT_FOUND);
                    }
                    $preference = decbin(Preference::ALL_RANGES);
                }

                $this->preQueuingDecider(
                        $preference,
                        $user['email'],
                        $user['android_id'],
                        $user['ios_id'],
                        false
                    );

                // Registering message
                $log->theme_id = $theme->id;
                $log->user_id = $userId;
                $log->message = $this->message;
                $log->save();
                break;

            // If theme has this range, checks all users subscribed and its preferences. Prepare the log and
            // the messages to be queued
            case Theme::MULTICAST:

                if (!isset($channel)) {
                    throw new PushApiException(PushApiException::NO_DATA, "Expected channel param");
                }

                try {
                    $channel = Channel::with(array('subscriptions.user.preferences' => function($query) use ($theme) {
                        return $query->where('theme_id', $theme->id);
                    }))->where('name', $channel)->first();
                } catch (ModelNotFoundException $e) {
                    throw new PushApiException(PushApiException::NOT_FOUND);
                }

                // Checking user preferences and add the notification to the right queue
                foreach ($channel->subscriptions->toArray() as $key => $subscription) {
                    // User hasn't set preferences for that theme, by default recive all devices
                    if (empty($subscription['user']['preferences'][0])) {
                        $preference = decbin(Preference::ALL_RANGES);
                    } else {
                        $preference = decbin($subscription['user']['preferences'][0]['option']);
                    }

                    $this->preQueuingDecider(
                            $preference,
                            $subscription['user']['email'],
                            $subscription['user']['android_id'],
                            $subscription['user']['ios_id'],
                            true
                        );
                }

                $this->storeToQueues();

                // Registering message
                $log->theme_id = $theme->id;
                $log->channel_id = $channel->id;
                $log->message = $this->message;
                $log->save();

                break;

            // If theme has this range, checks the preferences for the target theme and send to
            // all users who haven't set option none.
            case Theme::BROADCAST:

                // Checking user preferences and add the notification to the right queue
                foreach ($theme->preferences->toArray() as $key => $userPreference) {
                    $preference = decbin($userPreference['option']);
                    $this->preQueuingDecider(
                        $preference,
                        $userPreference['user']['email'],
                        $userPreference['user']['android_id'],
                        $userPreference['user']['ios_id'],
                        true
                    );
                }

                $this->storeToQueues();

                // Registering message
                $log->theme_id = $theme->id;
                $log->message = $this->message;
                $log->save();

                break;
            
            default:
                throw new PushApiException(PushApiException::INVALID_ACTION);
                break;
        }
        $this->send(true);
    }

    /**
     * Checks the preferences that user has set foreach device and adds into the right
     * queue, if @param multiple is set, then it will store the smartphone receivers into
     * queues in order to send only one request to the server with all the receivers.
     * @param  [string] $preference User preference
     * @param  [string] $email      User email
     * @param  [string] $android_id User android id
     * @param  [string] $ios_id     User ios id
     * @param  [boolean] $multiple  If there will be more calls with the same class instance
     */
    private function preQueuingDecider($preference, $email, $android_id, $ios_id, $multiple = false)
    {
        // Checking if user wants to recive via email
        if ((Preference::EMAIL & $preference) == Preference::EMAIL) {
            $this->addToDeviceQueue($email, QueueController::EMAIL);
        }

        if (!$multiple) {
            // Checking if user wants to recive via smartphone
            if ((Preference::SMARTPHONE & $preference) == Preference::SMARTPHONE) {
                if ($android_id != 0) {
                    // Android receivers requires to be stored into an array structure
                    $this->addToDeviceQueue(array($android_id), QueueController::ANDROID);
                }
                if ($ios_id != 0) {
                    $this->addToDeviceQueue($ios_id, QueueController::IOS);
                }
            }
        } else {
            // Checking if user wants to recive via smartphone
            if ((Preference::SMARTPHONE & $preference) == Preference::SMARTPHONE) {
                if ($android_id != 0) {
                    array_push($this->androidUsers, $android_id);
                }
                if ($ios_id != 0) {
                    array_push($this->iosUsers, $ios_id);
                }

                // Android GMC lets send notifications to 1000 devices with one JSON message,
                // if there are more >1000 we need to refill the list
                if (sizeof($this->androidUsers) == 1000) {
                    $this->addToDeviceQueue($this->androidUsers, QueueController::ANDROID);
                    $this->androidUsers = array();
                }
            }
        }
    }

    /**
     * Stores into the right queue the smartphones arrays if those has been set
     */
    private function storeToQueues()
    {
        if (!empty($this->androidUsers)) {
            $this->addToDeviceQueue($this->androidUsers, QueueController::ANDROID);
        }
        if (!empty($this->iosUsers)) {
            $this->addToDeviceQueue($this->iosUsers, QueueController::IOS);
        }
    }

    /**
     * Generates an array of data prepared to be stored in the $device queue
     * @param [string] $receiver   The receiver of the target user
     * @param [string] $device  Destination where the message must be stored
     */
    private function addToDeviceQueue($receiver, $device)
    {
        if (!isset($device)) {
            throw new PushApiException(PushApiException::INVALID_ACTION);
        }

        $data = array(
            "to" => $receiver,
            "subject" => $this->theme,
            "message" => $this->message
        );
        (new QueueController())->addToQueue($data, $device);
    }
}