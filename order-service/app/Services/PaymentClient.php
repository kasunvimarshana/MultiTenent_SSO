<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

/**
 * HTTP client for the Payment Service (Node.js / Express).
 */
class PaymentClient
{
    private Client $http;

    public function __construct()
    {
        $baseUrl    = rtrim(env('PAYMENT_SERVICE_URL', 'http://payment-service:3000'), '/');
        $this->http = new Client([
            'base_uri' => $baseUrl,
            'timeout'  => 15,
            'headers'  => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
                'X-Service'    => 'order-service',
            ],
        ]);
    }

    /**
     * POST /api/payments  – charge the customer.
     *
     * @param  array  $payload  { saga_id, order_id, order_number, amount, currency, customer_email }
     * @return array            { payment_id, amount, status, created_at }
     */
    public function charge(array $payload): array
    {
        try {
            $response = $this->http->post('/api/payments', ['json' => $payload]);
            $data     = json_decode($response->getBody(), true);

            if (empty($data['payment_id'])) {
                throw new RuntimeException('Payment processing failed: no payment ID returned.');
            }

            return $data;
        } catch (RequestException $e) {
            $body    = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
            $decoded = json_decode($body, true);
            $message = $decoded['error'] ?? $e->getMessage();
            throw new RuntimeException('Payment processing failed: ' . $message, 402);
        }
    }

    /**
     * POST /api/payments/{paymentId}/refund  – refund a payment (compensating action).
     *
     * @param  string  $paymentId
     * @param  array   $payload   { reason, order_id }
     * @return array              { refund_id, payment_id, amount, status }
     */
    public function refund(string $paymentId, array $payload): array
    {
        try {
            $response = $this->http->post("/api/payments/{$paymentId}/refund", ['json' => $payload]);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            logger()->error('Payment refund failed', [
                'payment_id' => $paymentId,
                'error'      => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * GET /api/payments  – list payments (for cross-service queries).
     */
    public function listPayments(array $filters = []): array
    {
        try {
            $response = $this->http->get('/api/payments', ['query' => $filters]);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new RuntimeException('Payment Service unavailable: ' . $e->getMessage(), 503);
        }
    }
}
