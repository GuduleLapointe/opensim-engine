<?php

declare(strict_types=1);

namespace OpenSim\Moderation;

/**
 * What it takes to ban users: the users found, the statements that delete their data and ban them. Nothing is run: the
 * statements are given to be reviewed and run by the administrator of the databases.
 */
final class BanPlan
{
    /** @var list<BannedUser> */
    public array $users = [];

    /** @var list<string> the lines of the file that deletes what the users own (statements and their comments) */
    public array $cleanup = [];

    /** @var list<string> the lines of the file that adds the users to the ban list of the estates */
    public array $ban = [];

    /** @var list<string> the rows of hg_traveling_data of the users, for information */
    public array $traveling = [];

    /** @var list<string> what could not be done */
    public array $problems = [];

    /** The number of statements that delete. */
    public function deletes(): int
    {
        return self::statements($this->cleanup);
    }

    /** The number of statements that ban. */
    public function bans(): int
    {
        return self::statements($this->ban);
    }

    /**
     * The files of the plan, by suffix.
     *
     * @param string $date the date written in their first line
     * @return array<string,string>
     */
    public function files(string $date): array
    {
        $lines = static fn(array $lines): string => $lines === [] ? '' : implode("\n", $lines) . "\n";

        return [
            'users' => $lines(array_map(
                static fn(BannedUser $u): string => implode("\t", [$u->uuid, $u->first, $u->last, $u->grid]),
                $this->users,
            )),
            'cleanup' => $lines(["-- Delete accounts and data $date", ...$this->cleanup]),
            'ban' => $lines(["-- Ban users $date", ...$this->ban]),
            'hg_traveling_data' => $lines(["-- Hypergrid traveling data of the users $date", ...$this->traveling]),
        ];
    }

    /** @param list<string> $lines */
    private static function statements(array $lines): int
    {
        return count(array_filter($lines, static fn(string $line): bool => $line !== '' && !str_starts_with($line, '--')));
    }
}
