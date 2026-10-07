<?php

namespace Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A PSR-18 client for the Anthropic SDK that records requests and replays queued
 * responses, so tests exercise the real SDK without the network.
 */
class FakeAnthropicTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param  list<ResponseInterface>  $responses
     */
    public function __construct(private array $responses = []) {}

    /**
     * A successful Messages API response whose text block is the given JSON.
     *
     * @param  array<mixed>  $structured
     */
    public static function message(array $structured, string $stopReason = 'end_turn', int $inputTokens = 1200, int $outputTokens = 300): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-haiku-4-5',
            'content' => [['type' => 'text', 'text' => json_encode($structured)]],
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ], JSON_THROW_ON_ERROR));
    }

    public static function error(int $status, string $type): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode([
            'type' => 'error',
            'error' => ['type' => $type, 'message' => 'Simulated error'],
        ], JSON_THROW_ON_ERROR));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? throw new RuntimeException('No fake response queued.');
    }

    /**
     * @return array<string, mixed>
     */
    public function body(int $index = 0): array
    {
        return json_decode((string) $this->requests[$index]->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
