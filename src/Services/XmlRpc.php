<?php

declare(strict_types=1);

namespace OpenSim\Services;

use OpenSim\Api\Response;
use PhpXmlRpc\Encoder;
use PhpXmlRpc\Request;
use PhpXmlRpc\Response as RpcResponse;

/**
 * What the viewers and the simulators speak to the search, the currency and the land tools: XML-RPC, the methods and the answers
 * as OpenSimulator and the viewers expect them. The requests are read and the answers written here, as strings, for whoever
 * transports them.
 *
 *     $rpc = new XmlRpc(['dir_places_query' => fn(array $request): array => XmlRpc::envelope(true, '', $rows)]);
 *     $response = $rpc->call($body);       // an Api\Response: text/xml
 *
 * A method gets the struct the caller sent (the first parameter) and gives the struct to answer, or the XML of the answer itself
 * (see plain()). It tells a request it cannot answer with a Failure, which gives the status and the message.
 */
final class XmlRpc
{
    public const CONTENT_TYPE = 'text/xml';

    /** @param array<string,\Closure(array<string,mixed>):(array<string,mixed>|string)> $methods by the name of the XML-RPC method */
    public function __construct(private readonly array $methods) {}

    /** The answer to the XML-RPC request in a body. */
    public function call(string $xml): Response
    {
        $call = self::parse($xml);
        if ($call === null) {
            return self::failure(new Failure('Bad request: no XML-RPC request', 400));
        }
        $method = $this->methods[$call['method']] ?? null;
        if ($method === null) {
            return Response::binary((new RpcResponse(0, 1, 'Unknown method'))->serialize(), self::CONTENT_TYPE);
        }
        try {
            return self::answer($method($call['params'][0] ?? []));
        } catch (Failure $failure) {
            return self::failure($failure);
        }
    }

    /**
     * A request: the method and its parameters.
     *
     * @return ?array{method:string,params:list<mixed>} null when the text is not an XML-RPC request
     */
    public static function parse(string $xml): ?array
    {
        if (trim($xml) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $encoder = new Encoder();
            $request = $encoder->decodeXml($xml);
            if (!($request instanceof Request)) {
                return null;
            }
            $params = [];
            for ($i = 0; $i < $request->getNumParams(); $i++) {
                $params[] = $encoder->decode($request->getParam($i));
            }

            return ['method' => $request->method(), 'params' => $params];
        } catch (\Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * The XML of a call of a method, to send to a simulator.
     *
     * @param list<mixed> $params
     */
    public static function request(string $method, array $params): string
    {
        $encoder = new Encoder();

        return (new Request(
            $method,
            array_map(static fn(mixed $param) => $encoder->encode($param), $params),
        ))->serialize();
    }

    /**
     * What a simulator answers to a call: the struct, or the fault (`faultCode`, `faultString`), false when it is not an answer.
     */
    public static function answerOf(string $xml): mixed
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $encoder = new Encoder();
            $response = $encoder->decodeXml($xml);
            if (!($response instanceof RpcResponse)) {
                return false;
            }

            return $response->faultCode() !== 0
                ? ['faultCode' => $response->faultCode(), 'faultString' => $response->faultString()]
                : $encoder->decode($response->value());
        } catch (\Throwable) {
            return false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * A value as the parameters of an answer, the way the extension of PHP wrote it (what the simulators are used to).
     *
     * @param array<mixed>|scalar|null $value
     */
    public static function encode(mixed $value): string
    {
        return '<?xml version="1.0" encoding="utf-8" ?>' .
            "\n<params>\n<param>\n" .
            (new Encoder())->encode($value)->serialize() .
            "</param>\n</params>\n";
    }

    /**
     * The answer the search methods give: success, a message, and the rows when there are some.
     *
     * @param ?array<mixed> $data
     * @return array<string,mixed>
     */
    public static function envelope(bool $success, string $message = '', ?array $data = null): array
    {
        $answer = ['success' => $success, 'errorMessage' => $message];
        // No data to say when it failed and there is none
        if ($success || !empty($data)) {
            $answer['data'] = $data ?? [];
        }

        return $answer;
    }

    /**
     * The shortest answers, the ones the search gives when it has nothing to say: `true`, or an error.
     */
    public static function plain(bool $success, string $message = ''): string
    {
        return $success
            ? '<?xml version="1.0"?>' . "\n<boolean>true</boolean>\n"
            : '<?xml version="1.0"?>' . "\n<error>" . htmlspecialchars($message, ENT_XML1) . "</error>\n";
    }

    /** @param array<string,mixed>|string $struct */
    private static function answer(array|string $struct): Response
    {
        return Response::binary(is_string($struct) ? $struct : self::encode($struct), self::CONTENT_TYPE);
    }

    /** The answer to a request that cannot be answered. */
    public static function failure(Failure $failure): Response
    {
        return Response::binary(
            self::encode(self::envelope(false, $failure->getMessage(), [])),
            self::CONTENT_TYPE,
            $failure->status,
        );
    }
}
