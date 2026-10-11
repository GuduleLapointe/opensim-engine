<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

use OpenSim\Models\Simulator;

/**
 * The real users in the regions of a simulator, to know whether anybody is to be warned before it stops.
 *
 * Two ways to ask: the HTTP port of the simulator, which tells how many agents each region has (a console busy with a long
 * command, an archive being loaded, does not stop it), and its console, which tells the users from the NPCs and the child
 * agents. Zero from the HTTP port is zero; some agents are asked to the console, and when it does not answer they count.
 */
final class UserCounter
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(private readonly Environment $environment)
    {
    }

    /** The real users in all the regions, null when nothing answers. */
    public function count(Simulator $simulator): ?int
    {
        $http = $this->http($simulator);
        if ($http === 0) {
            return 0;
        }
        $console = $this->console($simulator);

        return $console ?? $http;
    }

    /**
     * Whether there is nobody to wait for: no user, or a simulator that does not answer at all (one that does not answer serves
     * nobody, and waiting for people it cannot tell about would only keep the operator waiting).
     *
     * @return array{nobody:bool,unknown:bool}
     */
    public function nobodyToWaitFor(Simulator $simulator): array
    {
        $count = $this->count($simulator);

        return $count === null ? ['nobody' => true, 'unknown' => true] : ['nobody' => $count === 0, 'unknown' => false];
    }

    /**
     * The agents the regions of a simulator have, as it says on its HTTP port, one request per region (its UUID is in its
     * file). NPCs are counted. Null when a region does not answer.
     */
    private function http(Simulator $simulator): ?int
    {
        $port = $simulator->launchSetting('http_listener_port');
        $directory = $simulator->regionsDirectory();
        if ($port === '' || $directory === '') {
            return null;
        }
        $total = 0;
        $regions = 0;
        foreach (glob("$directory/*.ini") ?: [] as $file) {
            preg_match_all('/^\s*RegionUUID\s*=\s*"?([0-9a-fA-F-]{36})/m', (string) file_get_contents($file), $uuids);
            foreach ($uuids[1] as $uuid) {
                $body = ($this->environment->http ?? self::fetch(...))("http://127.0.0.1:$port/monitorstats/$uuid");
                if ($body === null || !preg_match('#<AgentCountMonitor>(\d+)</AgentCountMonitor>#', $body, $m)) {
                    return null;
                }
                $total += (int) $m[1];
                $regions++;
            }
        }

        return $regions > 0 ? $total : null;
    }

    /** The real users, as the console says them with `show users`; null when it cannot tell. */
    private function console(Simulator $simulator): ?int
    {
        $console = $simulator->console();
        if ($console instanceof \OpenSim\Console\RestConsole) {
            $out = $console->lines(['change region root', 'show users']);
        } elseif ($console->available()) {
            // The console is in one region (the last one that came up): this one makes it see all of them
            $console->send('change region root');
            $out = $console->ask('show users');
        } else {
            return null;
        }

        return $out === null ? null : self::realUsers($out);
    }

    /**
     * The real users in what `show users` printed for all the regions: NPCs and child agents are not counted. The rows are
     * `Firstname Lastname Agent-ID Type Home`, the type being Root, Child, NPC Root or NPC Child; a screen cuts the lines at 80
     * columns, so a type that was cut off counts. Null when the text is not an answer.
     *
     * @param list<string> $lines
     */
    public static function realUsers(array $lines): ?int
    {
        $text = implode("\n", $lines);
        // Each region says how many agents it has, even none: no such line, no answer
        if (!preg_match('/^Root agents in region /m', $text)) {
            return null;
        }
        $roots = 0;
        if (preg_match_all('/^Root agents in region .*\(root (\d*),/m', $text, $m)) {
            $roots = array_sum(array_map('intval', $m[1]));
        }
        if ($roots === 0) {
            return 0;
        }
        // A row: two names, then the id (the log lines the console shows between them are not rows)
        $row = '/^[^\s\[]+\s+\S+\s+' . self::UUID . '/m';
        $rows = preg_match_all($row, $text);
        // Root agents the rows do not show (a list that is not the one expected): no answer
        if ($rows === 0) {
            return null;
        }

        return $rows - (int) preg_match_all('/^[^\s\[]+\s+\S+\s+' . self::UUID . '\s+(NPC|Child)/m', $text);
    }

    private static function fetch(string $url): ?string
    {
        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $body = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        return is_string($body) && $code > 0 ? $body : null;
    }
}
