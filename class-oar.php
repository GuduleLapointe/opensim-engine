<?php
/**
 * OpenSim OAR
 *
 * Works with OpenSimulator archives (OAR): a gzipped tar with a control file (archive.xml), the
 * objects (objects/*.xml), the parcels (landdata/*.xml), the terrain, the region settings and the
 * assets (assets/<uuid>_<type>.<ext>). Reads them (entries, info, check, unpack) and makes them
 * from a folder laid out like their content (pack), the way the files are kept in a project.
 *
 * Archives are made here, not with the tar of the system: the tar of macOS makes archives that
 * OpenSimulator does not read (extended attributes), this one writes plain ustar entries only.
 */

if (!defined('ABSPATH') && !defined('OPENSIM_ENGINE')) {
    exit();
}

class OpenSim_Oar
{
    /** The control file, the first entry of an archive */
    const CONTROL = 'archive.xml';

    /** The folders of an archive, and the entries it holds in the root */
    const FOLDERS = ['assets', 'landdata', 'objects', 'settings', 'terrains'];

    /**
     * Make an archive from a folder laid out as its content.
     *
     * @param string $dir The folder: archive.xml, assets/, landdata/, objects/, settings/, terrains/.
     * @param string $oar The archive to write (replaced).
     * @throws RuntimeException When the folder has no control file or the archive cannot be written.
     */
    public static function pack(string $dir, string $oar): void
    {
        $dir = rtrim($dir, '/');
        if (!is_file("$dir/" . self::CONTROL)) {
            throw new RuntimeException(sprintf('%s has no %s', $dir, self::CONTROL));
        }

        $files = [self::CONTROL];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        $others = [];
        foreach ($iterator as $file) {
            $name = substr($file->getPathname(), strlen($dir) + 1);
            // What editors and systems leave beside the files is not part of an archive
            if ($name !== self::CONTROL && !preg_match('#(^|/)(\.[^/]*|Thumbs\.db)$#', $name)) {
                $others[] = $name;
            }
        }
        sort($others);
        $files = array_merge($files, $others);

        $base = tempnam(sys_get_temp_dir(), 'oar');
        unlink($base);
        $tar = $base . '.tar';
        try {
            $archive = new PharData($tar);
            foreach ($files as $name) {
                $archive->addFile("$dir/$name", $name);
            }
            $archive->compress(Phar::GZ);
            unset($archive);
            if (!@rename("$tar.gz", $oar) && !(copy("$tar.gz", $oar) && unlink("$tar.gz"))) {
                throw new RuntimeException(sprintf('Cannot write %s', $oar));
            }
        } finally {
            foreach ([$tar, "$tar.gz"] as $leftover) {
                if (is_file($leftover)) {
                    unlink($leftover);
                }
            }
        }
    }

    /**
     * The names of the entries of an archive.
     *
     * @return list<string>
     * @throws RuntimeException When the file is not an archive.
     */
    public static function entries(string $oar): array
    {
        $names = [];
        foreach (self::read($oar) as $name => $_) {
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Extract an archive into a folder, created when it is missing. The entries that would go out of
     * the folder are refused.
     *
     * @throws RuntimeException When the file is not an archive or has an unsafe entry.
     */
    public static function unpack(string $oar, string $dir): void
    {
        foreach (self::read($oar) as $name => $content) {
            if (self::unsafe($name)) {
                throw new RuntimeException(sprintf('Unsafe entry in %s: %s', $oar, $name));
            }
            $path = rtrim($dir, '/') . "/$name";
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o755, true);
            }
            file_put_contents($path, $content);
        }
    }

    /**
     * What the control file and the entries tell of an archive.
     *
     * @return array{version:string,size:array{0:int,1:int}|null,assets_included:bool|null,objects:int,parcels:int,assets:int,terrains:int,settings:int}
     * @throws RuntimeException When the file is not an archive or has no readable control file.
     */
    public static function info(string $oar): array
    {
        $info = [
            'version' => '',
            'size' => null,
            'assets_included' => null,
            'objects' => 0,
            'parcels' => 0,
            'assets' => 0,
            'terrains' => 0,
            'settings' => 0,
        ];
        $control = null;
        foreach (self::read($oar) as $name => $content) {
            if ($name === self::CONTROL) {
                $control = $content;
            } elseif (preg_match('#^objects/.+\.xml$#', $name)) {
                $info['objects']++;
            } elseif (preg_match('#^landdata/.+\.xml$#', $name)) {
                $info['parcels']++;
            } elseif (preg_match('#^assets/.+#', $name)) {
                $info['assets']++;
            } elseif (preg_match('#^terrains/.+#', $name)) {
                $info['terrains']++;
            } elseif (preg_match('#^settings/.+\.xml$#', $name)) {
                $info['settings']++;
            }
        }
        $xml = $control === null ? null : self::xml($control);
        if ($xml === null) {
            throw new RuntimeException(sprintf('%s has no readable %s', $oar, self::CONTROL));
        }

        $info['version'] = (string) $xml['major_version'] . '.' . (string) $xml['minor_version'];
        if (isset($xml->assets_included)) {
            $info['assets_included'] = strtolower(trim((string) $xml->assets_included)) === 'true';
        }
        $size = trim((string) ($xml->region_info->size_in_meters ?? ''));
        if (preg_match('/^(\d+)\s*,\s*(\d+)/', $size, $m)) {
            $info['size'] = [(int) $m[1], (int) $m[2]];
        }

        return $info;
    }

    /**
     * What is wrong with an archive: no control file, XML that does not parse, entries out of their
     * folder, assets the archive says it has and does not. Empty when it is fine.
     *
     * @return list<string>
     */
    public static function check(string $oar): array
    {
        $problems = [];
        try {
            $entries = iterator_to_array(self::read($oar));
        } catch (RuntimeException $e) {
            return [$e->getMessage()];
        }

        if (!isset($entries[self::CONTROL])) {
            $problems[] = sprintf('no %s', self::CONTROL);
        } elseif (self::xml($entries[self::CONTROL]) === null) {
            $problems[] = sprintf('%s does not parse', self::CONTROL);
        }

        $assets = 0;
        foreach ($entries as $name => $content) {
            if (self::unsafe($name)) {
                $problems[] = sprintf('unsafe entry: %s', $name);
            } elseif ($name !== self::CONTROL && !in_array(explode('/', $name)[0], self::FOLDERS, true)) {
                $problems[] = sprintf('unknown entry: %s', $name);
            } elseif (preg_match('#^(objects|landdata|settings)/.+\.xml$#', $name) && self::xml($content) === null) {
                $problems[] = sprintf('%s does not parse', $name);
            } elseif (str_starts_with($name, 'assets/')) {
                $assets++;
            }
        }

        $control = isset($entries[self::CONTROL]) ? self::xml($entries[self::CONTROL]) : null;
        if (
            $control !== null &&
            isset($control->assets_included) &&
            strtolower(trim((string) $control->assets_included)) === 'true' &&
            $assets === 0
        ) {
            $problems[] = 'the archive says assets are included, and has none';
        }

        return $problems;
    }

    /**
     * The entries of an archive, name => content, files only.
     *
     * @return Generator<string,string>
     * @throws RuntimeException When the file is not a gzipped tar.
     */
    private static function read(string $oar): Generator
    {
        $data = is_file($oar) ? file_get_contents($oar) : false;
        if ($data === false || strncmp($data, "\x1f\x8b", 2) !== 0) {
            throw new RuntimeException(sprintf('%s is not an OAR (a gzipped tar)', $oar));
        }
        $tar = @gzdecode($data);
        if ($tar === false) {
            throw new RuntimeException(sprintf('%s cannot be uncompressed', $oar));
        }

        $offset = 0;
        $length = strlen($tar);
        $longName = null;
        while ($offset + 512 <= $length) {
            $header = substr($tar, $offset, 512);
            if (trim($header, "\0") === '') {
                break;
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            if ($prefix !== '' && strncmp(substr($header, 257, 5), 'ustar', 5) === 0) {
                $name = "$prefix/$name";
            }
            $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
            $type = $header[156];
            $content = substr($tar, $offset + 512, $size);
            $offset += 512 + (int) (ceil($size / 512) * 512);

            if ($type === 'L') {
                // GNU long name: the name of the next entry
                $longName = rtrim($content, "\0");
                continue;
            }
            if ($longName !== null) {
                $name = $longName;
                $longName = null;
            }
            // Only files; the folders, the links and the extended headers of other tars are no content
            if (($type === '0' || $type === "\0") && !str_ends_with($name, '/')) {
                yield preg_replace('#^\./#', '', $name) => $content;
            }
        }
    }

    /** Whether an entry name goes out of the folder it is extracted into */
    private static function unsafe(string $name): bool
    {
        return $name === '' || $name[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $name) === 1;
    }

    /** The XML of a file of an archive: the declared encoding is not trusted (the archives say utf-16 and are utf-8) */
    private static function xml(string $content): ?SimpleXMLElement
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = preg_replace('/^\s*<\?xml[^>]*\?>/', '', (string) $content);
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) $content);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml === false ? null : $xml;
    }
}
