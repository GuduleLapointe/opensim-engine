<?php

namespace OpenSim\Provisioning;

use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Models\Avatar;
use OpenSim\Models\Grid;

/**
 * Makes the avatars of a list in a grid (see Avatar::create()), or tells what it would make: the names that
 * exist are skipped, a failure is told for the avatar and nothing of it is visible.
 */
final class AccountImporter
{
    public const CREATED = 'created';
    public const WOULD_CREATE = 'would create';
    public const EXISTS = 'exists';
    public const FAILED = 'failed';
    public const NOT_TRIED = 'not tried';

    /**
     * @param \Closure(Grid):?list<string> $names the names ("First Last") of the avatars of the grid, null when they cannot be read
     * @param \Closure(Grid):?string $home the id of the default region of the grid, null when it has none
     * @param \Closure(Grid,array<string,mixed>):void $create makes an avatar (the values of Avatar::create()), throws when it cannot
     */
    public function __construct(private \Closure $names, private \Closure $home, private \Closure $create) {}

    /** The importer of a real grid: its avatars are read and made by the model. */
    public static function forModels(): self
    {
        $config = static fn(Grid $grid): GridConfig => GridConfig::fromFolder($grid->directory());

        return new self(
            static function (Grid $grid) use ($config): ?array {
                try {
                    return array_map(
                        static fn(Avatar $avatar): string => $avatar->name(),
                        Avatar::all($config($grid))->items(),
                    );
                } catch (ChannelUnavailable) {
                    return null;
                }
            },
            static function (Grid $grid) use ($config): ?string {
                $pdo = Connections::pdo($config($grid), 'GridService');
                $id = $pdo?->get_var('SELECT uuid FROM regions WHERE (flags & 1) = 1 ORDER BY regionName LIMIT 1');

                return is_string($id) && preg_match('/^[0-9a-fA-F-]{36}$/', $id) === 1 ? $id : null;
            },
            static function (Grid $grid, array $values) use ($config): void {
                Avatar::create($config($grid), $values);
            },
        );
    }

    /**
     * @param list<array{first:string,last:string,email:string,password:string,line:int}> $accounts
     * @param bool $apply     write the accounts, else only tell what would be written
     * @param bool $keepGoing go on after an account that failed
     * @return array{error:?string,results:list<array{first:string,last:string,email:string,status:string,detail:string,password:string,generated:bool}>}
     */
    public function run(Grid $grid, array $accounts, bool $apply, bool $keepGoing = false): array
    {
        $names = ($this->names)($grid);
        if ($names === null) {
            return ['error' => 'the database of the grid cannot be read', 'results' => []];
        }
        $exists = array_fill_keys(array_map('strtolower', array_map('trim', $names)), true);
        // The home of the accounts: the default region, when the grid has one
        $homeId = ($this->home)($grid);

        $results = [];
        $stopped = false;
        foreach ($accounts as $account) {
            $row = [
                'first' => $account['first'],
                'last' => $account['last'],
                'email' => $account['email'],
                'status' => '',
                'detail' => '',
                'password' => $account['password'],
                'generated' => false,
            ];
            if ($stopped) {
                $row['status'] = self::NOT_TRIED;
                $results[] = $row;
                continue;
            }
            if (isset($exists[strtolower("{$account['first']} {$account['last']}")])) {
                $row['status'] = self::EXISTS;
                $results[] = $row;
                continue;
            }
            $hash =
                ($account['password_hash'] ?? '') !== ''
                    ? ['hash' => $account['password_hash'], 'salt' => $account['password_salt'] ?? '']
                    : null;
            if ($row['password'] === '' && $hash === null) {
                $row['password'] = self::password();
                $row['generated'] = true;
            }
            if (!$apply) {
                $row['status'] = self::WOULD_CREATE;
                $row['detail'] = $homeId === null ? 'no default region yet: no home' : '';
                $results[] = $row;
                continue;
            }

            try {
                ($this->create)(
                    $grid,
                    array_filter(
                        [
                            'firstName' => $row['first'],
                            'lastName' => $row['last'],
                            'email' => $row['email'],
                            'password' => $hash === null ? $row['password'] : null,
                            'passwordHash' => $hash,
                            'home' => $homeId,
                        ],
                        static fn($value): bool => $value !== null,
                    ),
                );
                $row['status'] = self::CREATED;
                $exists[strtolower("{$account['first']} {$account['last']}")] = true;
            } catch (\InvalidArgumentException | ChannelUnavailable $e) {
                $row['status'] = self::FAILED;
                $row['detail'] = $e->getMessage();
                $stopped = !$keepGoing;
            }
            $results[] = $row;
        }

        return ['error' => null, 'results' => $results];
    }

    /** A password for an account that was given none: letters and digits. */
    public static function password(int $length = 12): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    /**
     * The result as a CSV for whoever sent the list: the passwords that were generated are the only ones in it.
     *
     * @param list<array{first:string,last:string,email:string,status:string,detail:string,password:string,generated:bool}> $results
     */
    public static function resultCsv(array $results): string
    {
        $out = fopen('php://memory', 'w+');
        fputcsv($out, ['first', 'last', 'email', 'status', 'password', 'detail'], ',', '"', '');
        foreach ($results as $row) {
            fputcsv(
                $out,
                [
                    $row['first'],
                    $row['last'],
                    $row['email'],
                    $row['status'],
                    $row['generated'] ? $row['password'] : '',
                    $row['detail'],
                ],
                ',',
                '"',
                '',
            );
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
