<?php

declare(strict_types=1);

namespace OpenSim\Services;

use OpenSim\Api\Response;
use OpenSim\Data\OSPDO;
use OpenSim\Text\Uuid;

/**
 * The instant messages sent to avatars who are not there, kept until they come back (the service the module V1 of the simulators
 * calls: `OfflineMessageModule = OfflineMessageModule` and `OfflineMessageURL`). The simulators post the messages in XML to
 * `<url>/SaveMessage/` and ask them back at `<url>/RetrieveMessages/`; the format is the one of the module.
 *
 * The messages are kept in the table of the offline messages of Robust (`im_offline`), the one the module V2 reads too. An avatar
 * who asked it in the preferences of their viewer (Send IM to email) also gets the message by email, once the answer is sent.
 */
final class OfflineMessages
{
    /** The table of Robust's service of offline messages. */
    public const TABLE = 'im_offline';

    /** @var list<\Closure():void> what is done once the answer is sent */
    private array $after = [];

    /**
     * @param OSPDO $messages the database of the offline messages
     * @param ?OSPDO $profiles the database of the profiles, who knows the mail preferences (`usersettings`)
     * @param string $sender the address the mails come from
     */
    public function __construct(
        private readonly OSPDO $messages,
        private readonly ?OSPDO $profiles = null,
        private readonly string $gridName = '',
        private readonly string $sender = '',
        private readonly ?MailerInterface $mailer = null,
        private readonly string $table = self::TABLE,
    ) {}

    /**
     * The answer to a call of the module.
     *
     * @param string $method SaveMessage or RetrieveMessages
     */
    public function call(string $method, string $body): Response
    {
        if (trim($body) === '') {
            return XmlRpc::failure(new Failure('Bad request: no request', 400));
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            return XmlRpc::failure(new Failure('Bad request: not XML', 400));
        }

        return match ($method) {
            'SaveMessage' => $this->save($xml),
            'RetrieveMessages' => $this->retrieve($xml),
            default => XmlRpc::failure(
                new Failure(
                    "method $method not implemented, please configure OfflineMessageModule = OfflineMessageModule in OpenSim.ini",
                    404,
                ),
            ),
        };
    }

    /** What waits for the answer to be sent: the mails. */
    public function finish(): void
    {
        while (($todo = array_shift($this->after)) !== null) {
            $todo();
        }
    }

    /** Keep a message for its recipient, and mail it when they asked. */
    private function save(\SimpleXMLElement $xml): Response
    {
        $saved = $this->messages->prepareAndExecute(
            "INSERT INTO {$this->table} (PrincipalID, FromID, Message) VALUES (:PrincipalID, :FromID, :Message)",
            [
                'PrincipalID' => (string) $xml->toAgentID,
                'FromID' => (string) $xml->fromAgentID,
                'Message' => preg_replace('/>\n/', '>', (string) $xml->asXML()),
            ],
        );
        $saved && ($this->after[] = fn() => $this->mail($xml));

        return Response::binary(XmlRpc::plain((bool) $saved), XmlRpc::CONTENT_TYPE);
    }

    /** Give the messages kept for an avatar, and forget them. */
    private function retrieve(\SimpleXMLElement $xml): Response
    {
        $guid = (string) $xml->Guid;
        $head =
            '<?xml version="1.0" encoding="utf-8"?><ArrayOfGridInstantMessage xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">';
        if (!Uuid::isValid($guid)) {
            error_log("$guid is not a valid UUID");

            return Response::binary('', XmlRpc::CONTENT_TYPE);
        }
        $pending = $this->messages->prepareAndExecute(
            "SELECT ID, Message FROM {$this->table} WHERE PrincipalID = :PrincipalID",
            [
                'PrincipalID' => $guid,
            ],
        );
        if (!$pending) {
            error_log('error retrieving pending messages');

            return Response::binary(XmlRpc::plain(false), XmlRpc::CONTENT_TYPE);
        }
        $delivered = [];
        $list = '';
        foreach ($pending->fetchAll(\PDO::FETCH_NUM) as [$id, $message]) {
            // What was kept is the whole document: its declaration is not part of the list
            $end = strpos((string) $message, '?>');
            $list .= $end === false ? $message : substr((string) $message, $end + 2);
            $delivered[] = $id;
        }
        if ($delivered !== []) {
            $this->messages->prepareAndExecute(
                "DELETE FROM {$this->table} WHERE ID IN (" . implode(',', array_fill(0, count($delivered), '?')) . ')',
                $delivered,
            );
        }

        return Response::binary($head . $list . '</ArrayOfGridInstantMessage>', XmlRpc::CONTENT_TYPE);
    }

    /** Send the message by email to the avatar who asked it. */
    private function mail(\SimpleXMLElement $xml): void
    {
        $settings = $this->profiles?->prepareAndExecute(
            'SELECT imviaemail, email FROM usersettings WHERE useruuid = :useruuid',
            [
                'useruuid' => (string) $xml->toAgentID,
            ],
        );
        $row = $settings ? $settings->fetch(\PDO::FETCH_NUM) : false;
        [$wanted, $email] = $row === false ? [null, null] : [$row[0], $row[1]];
        if (empty($email) || $wanted == 'false' || $this->mailer === null) {
            return;
        }
        $mail = $this->compose($xml);
        $from = $mail['from'] . ($this->sender === '' ? '' : " <{$this->sender}>");
        $this->mailer->send((string) $email, $mail['subject'], $mail['body'], $from) ||
            error_log("error sending the IM notification to $email");
    }

    /**
     * The mail of a message: who it is from, the subject, the body.
     *
     * @return array{from:string,subject:string,body:string}
     */
    public function compose(\SimpleXMLElement $xml): array
    {
        $from = (string) $xml->fromAgentName;
        // The grid itself writes as the grid
        $from === 'Server' && ($from = $this->gridName);
        // A message may start with its subject: "Subject|Text"
        $parts = explode('|', (string) $xml->message, 2);
        $heading = count($parts) > 1 ? $parts[0] : '';
        $text = $parts[1] ?? $parts[0];
        $html = static fn(string $plain): string => str_replace("\n", "\n<br>", htmlspecialchars($plain));
        $titled = ($heading === '' ? '' : '<h4>' . htmlspecialchars($heading) . '</h4>') . $html($text);
        $in = $this->gridName === '' ? '' : " in {$this->gridName}";
        $intro = '';
        $outro = '';
        $body = $titled;
        // The kinds of messages of http://wiki.secondlife.com/wiki/ImprovedInstantMessage
        switch ((string) $xml->dialog) {
            case '32':
                $subject = "Group notice: $heading";
                $intro = "$from sent a group notice$in:";
                break;
            case '3':
                $subject = "Group invitation from $from";
                $intro = $text;
                $body = 'Log in-world to accept or decline';
                break;
            case '4':
                $subject = "Inventory offer from $from";
                $intro = "$from returned you " . $text;
                $body = 'Log in-world to accept or decline';
                break;
            case '38':
                $subject = "Friendship offer from $from";
                $intro = $text;
                $body = 'Log in-world to accept or decline.';
                break;
            default:
                $subject = "Message from $from";
                $outro = "Sent by $from $in";
        }

        return [
            'from' => $from,
            'subject' => htmlspecialchars($subject),
            'body' =>
                '<html><body>' .
                ($intro === '' ? '' : '<p>' . htmlspecialchars($intro) . '</p>') .
                $body .
                "\r\n\r\n" .
                ($outro === '' ? '' : '<blockquote>' . htmlspecialchars($outro) . '</blockquote>') .
                '<hr>' .
                "<p style='font-size:small'><b>" .
                htmlspecialchars($this->gridName) .
                '</b> Instant Messages mail forwarding by opensim-helpers.' .
                '<br>Please log in-world to answer to this message. Emails to the sender address will not be processed.' .
                '<br>To disable mail notifications, uncheck option "Send IM to mail" in your viewer preferences (tab "Chat" or "Communications").' .
                '</p></body></html>',
        ];
    }
}
