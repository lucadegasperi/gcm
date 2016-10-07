<?php

namespace NotificationChannels\Gcm;

use Exception;
use Illuminate\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Notification;
use NotificationChannels\Gcm\Exceptions\SendingFailed;
use ZendService\Google\Gcm\Client;
use ZendService\Google\Gcm\Message as Packet;

class GcmChannel
{
    /** @var Client */
    protected $client;

    /** @var Dispatcher */
    protected $events;

    /**
     * @param Client $client
     * @param Dispatcher $events
     */
    public function __construct(Client $client, Dispatcher $events)
    {
        $this->client = $client;
        $this->events = $events;
    }

    /**
     * Send the notification to Google Cloud Messaging.
     *
     * @param mixed $notifiable
     * @param Notification $notification
     * @return void
     *
     * @throws Exceptions\SendingFailed
     */
    public function send($notifiable, Notification $notification)
    {
        $devices = $notifiable->routeNotificationFor('gcm');
        if (! $devices) {
            return;
        }

        foreach ($devices as $device) {
            $deviceToken = ($device instanceof GcmDeviceInterface) ? $device->getToken() : $device;

            $message = $notification->toGcm($notifiable, $device);
            if (! $message) {
                continue;
            }

            $packet = $this->getPacket($deviceToken, $message);

            try {
                $response = $this->client->send($packet);
            } catch (Exception $exception) {
                throw SendingFailed::create($exception);
            }

            if (! $response->getFailureCount() == 0) {
                $this->handleFailedNotifications($notifiable, $notification, $response);
            }
        }
    }

    /**
     * @param $token
     * @param $message
     *
     * @return \NotificationChannels\Gcm\Packet
     */
    protected function getPacket($token, $message)
    {
        $packet = new Packet();

        $packet->setRegistrationIds([$token]);
        $packet->setCollapseKey(str_slug($message->title));
        $packet->setData([
                'title' => $message->title,
                'message' => $message->message,
            ] + $message->data);

        return $packet;
    }

    /**
     * @param $notifiable
     * @param \Illuminate\Notifications\Notification $notification
     * @param $response
     */
    protected function handleFailedNotifications($notifiable, Notification $notification, $response)
    {
        $results = $response->getResults();

        foreach ($results as $token => $result) {
            if (! isset($result['error'])) {
                continue;
            }

            $this->events->fire(
                new NotificationFailed($notifiable, $notification, $this, [
                    'token' => $token,
                    'error' => $result['error'],
                ])
            );
        }
    }
}
