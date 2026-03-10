<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * HTTP client for the Notification Service (Python / Flask).
 */
class NotificationClient
{
    private Client $http;

    public function __construct()
    {
        $baseUrl    = rtrim(env('NOTIFICATION_SERVICE_URL', 'http://notification-service:5000'), '/');
        $this->http = new Client([
            'base_uri' => $baseUrl,
            'timeout'  => 10,
            'headers'  => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
                'X-Service'    => 'order-service',
            ],
        ]);
    }

    /**
     * POST /api/notifications  – send a notification.
     *
     * @param  array  $payload  { type, recipient, customer_name, order_number, … }
     */
    public function send(array $payload): void
    {
        try {
            $this->http->post('/api/notifications', ['json' => $payload]);
        } catch (RequestException $e) {
            // Notification is non-critical; log and continue
            logger()->warning('Notification send failed', [
                'payload' => $payload,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
