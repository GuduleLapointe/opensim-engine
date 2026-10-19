<?php

declare(strict_types=1);

namespace OpenSim\Api;

/**
 * The answer of the API in the terms of no framework: a status, headers, a body. An adapter (PSR-7, a script, the command line)
 * gives it to whoever asked.
 */
final class Response
{
    public const JSON_API = 'application/vnd.api+json';

    private ?string $raw = null;

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $document the body, a JSON:API document or a problem
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly array $document,
    ) {}

    public static function document(array $document, int $status = 200): self
    {
        return new self($status, ['Content-Type' => self::JSON_API], $document);
    }

    public static function problem(Problem $problem): self
    {
        return new self($problem->status, ['Content-Type' => Problem::CONTENT_TYPE], $problem->toArray());
    }

    /**
     * An answer that is a file, a picture, a text: the bytes and their content type.
     *
     * @param array<string,string> $headers more headers than the type and the length
     */
    public static function binary(string $data, string $mime, int $status = 200, array $headers = []): self
    {
        $response = new self(
            $status,
            ['Content-Type' => $mime, 'Content-Length' => (string) strlen($data)] + $headers,
            [],
        );
        $response->raw = $data;

        return $response;
    }

    public function body(): string
    {
        return $this->raw ??
            json_encode($this->document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
