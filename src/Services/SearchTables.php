<?php

declare(strict_types=1);

namespace OpenSim\Services;

use OpenSim\Data\OSPDO;
use OpenSim\Models\Region;

/**
 * The tables of the search (the ones OpenSimSearch reads), in the search database of a grid: made when they are missing, brought up
 * to date when a column was added since. The search never makes the table `regions` of Robust: when the database holds it, the
 * regions of the search are kept in `regionsregister` (see Region::searchTable()).
 *
 * The tables are made in the dialect of MySQL and MariaDB, the databases a grid runs on.
 */
final class SearchTables
{
    /** The table of the events. */
    public const EVENTS = 'events';

    /** @return list<string> the tables the search needs, the one of the regions being the one this database uses */
    public static function names(OSPDO $pdo): array
    {
        return [
            'oshelpers_cache',
            Region::searchTable($pdo),
            'parcels',
            'parcelsales',
            'allparcels',
            'objects',
            'popularplaces',
            self::EVENTS,
            'classifieds',
            'hostsregister',
        ];
    }

    /** Whether every table is there. */
    public static function exist(OSPDO $pdo): bool
    {
        return $pdo->tables_exist(self::names($pdo));
    }

    /**
     * Make what is missing, and add the columns that came later.
     *
     * @return bool whether the tables are there after
     */
    public static function ensure(OSPDO $pdo): bool
    {
        if (!$pdo->connected) {
            return false;
        }
        if (!self::exist($pdo)) {
            foreach (self::statements(Region::searchTable($pdo)) as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (\Throwable $error) {
                    error_log('Could not make a table of the search: ' . $error->getMessage());

                    return false;
                }
            }
        }
        self::update($pdo);

        return self::exist($pdo);
    }

    /** The columns the first versions did not have: the gatekeeper of the grid a row comes from, the picture of a parcel. */
    private static function update(OSPDO $pdo): void
    {
        $added = [
            'gatekeeperURL' => [
                'allparcels',
                'classifieds',
                self::EVENTS,
                'hostsregister',
                'objects',
                'parcels',
                'parcelsales',
                'popularplaces',
                Region::searchTable($pdo),
            ],
            'imageUUID' => ['parcels'],
        ];
        foreach ($added as $column => $tables) {
            foreach ($tables as $table) {
                try {
                    if (!$pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetchAll()) {
                        $pdo->exec(
                            "ALTER TABLE `$table` ADD `$column` " .
                                ($column === 'imageUUID' ? 'char(36)' : 'varchar(255)'),
                        );
                    }
                } catch (\Throwable) {
                    // A table that is not there, or a database that does not tell its columns: nothing to bring up to date
                }
            }
        }
    }

    /** @return list<string> */
    private static function statements(string $regions): array
    {
        $myisam = 'ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci';
        $innodb = 'ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci';
        $null = '00000000-0000-0000-0000-000000000000';

        return [
            "CREATE TABLE IF NOT EXISTS `allparcels` (
                `regionUUID` char(36) NOT NULL,
                `parcelname` varchar(255) NOT NULL,
                `ownerUUID` char(36) NOT NULL default '$null',
                `groupUUID` char(36) NOT NULL default '$null',
                `landingpoint` varchar(255) NOT NULL,
                `parcelUUID` char(36) NOT NULL default '$null',
                `infoUUID` char(36) NOT NULL default '$null',
                `parcelarea` int(11) NOT NULL,
                `gatekeeperURL` varchar(255),
                PRIMARY KEY (`parcelUUID`),
                KEY `regionUUID` (`regionUUID`)
            ) $myisam",
            "CREATE TABLE IF NOT EXISTS `classifieds` (
                `classifieduuid` char(36) NOT NULL,
                `creatoruuid` char(36) NOT NULL,
                `creationdate` int(20) NOT NULL,
                `expirationdate` int(20) NOT NULL,
                `category` varchar(20) NOT NULL,
                `name` varchar(255) NOT NULL,
                `description` text NOT NULL,
                `parceluuid` char(36) NOT NULL,
                `parentestate` int(11) NOT NULL,
                `snapshotuuid` char(36) NOT NULL,
                `simname` varchar(255) NOT NULL,
                `posglobal` varchar(255) NOT NULL,
                `parcelname` varchar(255) NOT NULL,
                `classifiedflags` int(8) NOT NULL,
                `priceforlisting` int(5) NOT NULL,
                PRIMARY KEY (`classifieduuid`)
            ) $innodb",
            'CREATE TABLE IF NOT EXISTS `' .
            self::EVENTS .
            "` (
                `owneruuid` char(36) NOT NULL,
                `name` varchar(255) NOT NULL,
                `eventid` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `creatoruuid` char(36) NOT NULL,
                `category` int(2) NOT NULL,
                `description` text NOT NULL,
                `dateUTC` int(10) NOT NULL,
                `duration` int(10) NOT NULL,
                `covercharge` tinyint(1) NOT NULL,
                `coveramount` int(10) NOT NULL,
                `simname` varchar(255) NOT NULL,
                `parcelUUID` char(36) NOT NULL,
                `globalPos` varchar(255) NOT NULL,
                `eventflags` int(1) NOT NULL,
                `gatekeeperURL` varchar(255),
                `landingpoint` varchar(35) DEFAULT NULL,
                `parcelName` varchar(255) DEFAULT NULL,
                `mature` enum('true','false') NOT NULL DEFAULT 'false',
                PRIMARY KEY (`eventid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1",
            "CREATE TABLE IF NOT EXISTS `hostsregister` (
                `host` varchar(255) NOT NULL,
                `port` int(5) NOT NULL,
                `register` int(10) NOT NULL,
                `nextcheck` int(10) NOT NULL,
                `checked` tinyint(1) NOT NULL,
                `failcounter` int(10) NOT NULL,
                `gatekeeperURL` varchar(255),
                PRIMARY KEY (`host`,`port`)
            ) $myisam",
            "CREATE TABLE IF NOT EXISTS `objects` (
                `objectuuid` char(36) NOT NULL,
                `parceluuid` char(36) NOT NULL,
                `location` varchar(255) NOT NULL,
                `name` varchar(255) NOT NULL,
                `description` varchar(255) NOT NULL,
                `regionuuid` char(36) NOT NULL default '',
                `gatekeeperURL` varchar(255),
                PRIMARY KEY (`objectuuid`,`parceluuid`)
            ) $myisam",
            "CREATE TABLE IF NOT EXISTS `parcels` (
                `parcelUUID` char(36) NOT NULL,
                `regionUUID` char(36) NOT NULL,
                `parcelname` varchar(255) NOT NULL,
                `landingpoint` varchar(255) NOT NULL,
                `description` varchar(255) NOT NULL,
                `searchcategory` varchar(50) NOT NULL,
                `build` enum('true','false') NOT NULL,
                `script` enum('true','false') NOT NULL,
                `public` enum('true','false') NOT NULL,
                `dwell` float NOT NULL default '0',
                `infouuid` varchar(36) NOT NULL default '',
                `mature` varchar(10) NOT NULL default 'PG',
                `gatekeeperURL` varchar(255),
                `imageUUID` char(36),
                PRIMARY KEY (`regionUUID`,`parcelUUID`),
                KEY `name` (`parcelname`),
                KEY `description` (`description`),
                KEY `searchcategory` (`searchcategory`),
                KEY `dwell` (`dwell`)
            ) $myisam",
            "CREATE TABLE IF NOT EXISTS `parcelsales` (
                `regionUUID` char(36) NOT NULL,
                `parcelname` varchar(255) NOT NULL,
                `parcelUUID` char(36) NOT NULL,
                `area` int(6) NOT NULL,
                `saleprice` int(11) NOT NULL,
                `landingpoint` varchar(255) NOT NULL,
                `infoUUID` char(36) NOT NULL default '$null',
                `dwell` int(11) NOT NULL,
                `parentestate` int(11) NOT NULL default '1',
                `mature` varchar(10) NOT NULL default 'PG',
                `gatekeeperURL` varchar(255),
                PRIMARY KEY (`regionUUID`,`parcelUUID`)
            ) $myisam",
            "CREATE TABLE IF NOT EXISTS `popularplaces` (
                `parcelUUID` char(36) NOT NULL,
                `name` varchar(255) NOT NULL,
                `dwell` float NOT NULL,
                `infoUUID` char(36) NOT NULL,
                `has_picture` tinyint(1) NOT NULL,
                `mature` varchar(10) NOT NULL,
                `gatekeeperURL` varchar(255),
                PRIMARY KEY (`parcelUUID`)
            ) $myisam",
            "CREATE TABLE IF NOT EXISTS `$regions` (
                `regionname` varchar(255) NOT NULL,
                `regionUUID` char(36) NOT NULL,
                `regionhandle` varchar(255) NOT NULL,
                `url` varchar(255) NOT NULL,
                `owner` varchar(255) NOT NULL,
                `owneruuid` char(36) NOT NULL,
                `gatekeeperURL` varchar(255),
                PRIMARY KEY (`regionUUID`)
            ) $myisam",
            'CREATE TABLE IF NOT EXISTS `oshelpers_cache` (
                `cache_key` VARCHAR(255) NOT NULL,
                `cache_value` LONGBLOB,
                `cache_expires` int(10) default 0,
                PRIMARY KEY (`cache_key`)
            ) ENGINE=InnoDB',
        ];
    }
}
