<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

/**
 * HTTP client for calling the Inventory Service.
 *
 * In a production system this could also publish to a message queue
 * (e.g. RabbitMQ / Kafka) for async communication.
 */
class InventoryClient
{
    private Client $http;
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('INVENTORY_SERVICE_URL', 'http://inventory-service:8000'), '/');
        $this->http    = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => 10,
            'headers'  => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
                'X-Service'    => 'order-service',
            ],
        ]);
    }

    /**
     * GET /api/products  – list products with optional filters.
     */
    public function listProducts(array $filters = []): array
    {
        try {
            $response = $this->http->get('/api/products', ['query' => $filters]);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new RuntimeException('Inventory Service unavailable: ' . $e->getMessage(), 503);
        }
    }

    /**
     * GET /api/products/{id}  – fetch a single product.
     */
    public function getProduct(int $productId): array
    {
        try {
            $response = $this->http->get("/api/products/{$productId}");
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new RuntimeException("Product {$productId} not found in Inventory Service.", 404);
        }
    }

    /**
     * POST /api/reservations  – reserve stock for all order items.
     *
     * @param  array  $payload  { order_id, saga_id, items: [{product_id, quantity}] }
     * @return array            { reservation_ids: [...] }
     */
    public function reserve(array $payload): array
    {
        try {
            $response = $this->http->post('/api/reservations', ['json' => $payload]);
            $data = json_decode($response->getBody(), true);

            if (empty($data['reservation_ids'])) {
                throw new RuntimeException('Inventory reservation failed: no reservation IDs returned.');
            }

            return $data;
        } catch (RequestException $e) {
            $body    = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
            $decoded = json_decode($body, true);
            $message = $decoded['error'] ?? $e->getMessage();
            throw new RuntimeException('Inventory reservation failed: ' . $message, 422);
        }
    }

    /**
     * DELETE /api/reservations/release  – release (rollback) reserved stock.
     *
     * @param  array  $payload  { order_id, saga_id, reservation_ids: [...] }
     */
    public function releaseReservations(array $payload): void
    {
        try {
            $this->http->delete('/api/reservations/release', ['json' => $payload]);
        } catch (RequestException $e) {
            // Log but do not re-throw; compensation must be best-effort
            logger()->error('Failed to release inventory reservations', [
                'payload' => $payload,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
