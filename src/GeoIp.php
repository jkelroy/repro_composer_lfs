<?php

declare(strict_types=1);

namespace Mvorisek\Utils;

use MaxMind\Db\Reader;
use MaxMind\Db\Reader\Metadata;

class GeoIp
{
    /** @var string */
    protected static $_dbFile = __DIR__ . '/../data/GeoLite2-City.mmdb';
    /** @var Reader|null */
    protected static $_reader;

    /** @var array<string, mixed[]> */
    protected static $_cache = [];
    /** @var int */
    protected static $_cacheSize = 100;

    public static function getDbFilePath(): string
    {
        return static::$_dbFile;
    }

    protected static function initializeReader(): void
    {
        try {
            if (static::$_reader !== null) {
                static::$_reader->close();
            }
        } catch (\Exception $e) {
        }

        try {
            static::$_reader = new Reader(static::$_dbFile);
        } catch (\Exception $e) {
            throw new \Exception('Unable to initialize GeoIP Reader: ' . $e->getMessage());
        }

        $metadata = static::$_reader->metadata();
        if (
            $metadata->ipVersion !== 6
            || !preg_match('~^Geo(?:IP|Lite)2-City$~isu', $metadata->databaseType)
            || $metadata->searchTreeSize < 10 * 1024 * 1024
        ) {
            throw new \Exception('Unsupported GeoIP database');
        }
    }

    protected static function getReader(): Reader
    {
        if (static::$_reader === null) {
            static::initializeReader();
        }

        return static::$_reader;
    }

    public static function getReaderMetadata(): Metadata
    {
        $reader = static::getReader();

        return $reader->metadata();
    }

    /**
     * @return mixed[]
     */
    public static function getRaw(\Mvorisek\Net\IpAddress $ip): array
    {
        if (isset(static::$_cache[$ip->getIp()])) {
            $ipData = static::$_cache[$ip->getIp()];
        } else {
            $reader = static::getReader();
            try {
                [$ipData, $ipPrefix] = $reader->getWithPrefixLen($ip->getIp());
            } catch (\Exception $e) {
                throw new \Exception('Unable to localize IP address: ' . $e->getMessage());
            }

            $ipSubnet = new \Mvorisek\Net\IpAddress($ip->getIp(true), true);
            $ipSubnet->setMask($ipPrefix);
            $ipSubnet->setIp($ipSubnet->getIp(true) & $ipSubnet->getMask(true), true); // @phpstan-ignore-line
            $ipSubnet->setMask($ipPrefix);
            $ipSubnet->getScope();
            $ipData['subnet'] = $ipSubnet;
            static::$_cache[$ip->getIp()] = $ipData;

            if (count(static::$_cache) > static::$_cacheSize) {
                static::$_cache = array_slice(static::$_cache, (int) (static::$_cacheSize * 0.5) + 1, null, true);
            }
        }

        if ($ipData === null || !isset($ipData['country']) || empty($ipData['country']['iso_code']) || !isset($ipData['location']) || !isset($ipData['location']['longitude']) || !isset($ipData['location']['latitude'])) {
            throw new \Exception('Unable to localize IP address: Not found');
        }

        $ipData['subnet'] = clone $ipData['subnet'];

        return $ipData;
    }

    /**
     * @return string ISO 3166-1 country code, always two UC character
     */
    public static function getCountryCode(\Mvorisek\Net\IpAddress $ip)
    {
        return strtoupper(static::getRaw($ip)['country']['iso_code']);
    }

    /**
     * @param string $locale
     * @param bool   $localeFallback
     *
     * @return string|null
     */
    public static function getCountryName(\Mvorisek\Net\IpAddress $ip, $locale = 'en', $localeFallback = true)
    {
        $ipData = static::getRaw($ip);

        return static::getName($ipData['country']['names'], $locale, $localeFallback);
    }

    // @TODO subdivisions ?
    // https://en.wikipedia.org/wiki/Federated_state
    // http://www.unece.org/cefact/codesfortrade/codes_index.html
    // https://www.iso.org/obp/ui/#search
    // http://download.geonames.org/export/dump/
    // https://raw.githubusercontent.com/yosoyadri/GeoNames-XML-Builder/master/continents-countries-statesprovinces.xml

    /**
     * @param string $locale
     * @param bool   $localeFallback
     *
     * @return string|null
     */
    public static function getCityName(\Mvorisek\Net\IpAddress $ip, $locale = 'en', $localeFallback = true)
    {
        $ipData = static::getRaw($ip);

        return isset($ipData['city']) ? static::getName($ipData['city']['names'], $locale, $localeFallback) : null;
    }

    /**
     * @return array<string, float>
     */
    public static function getCoordinates(\Mvorisek\Net\IpAddress $ip)
    {
        $ipData = static::getRaw($ip);

        return ['longitude' => (float) $ipData['location']['longitude'], 'latitude' => (float) $ipData['location']['latitude']];
    }

    /**
     * @param bool $localeFallback
     *
     * @return string|null
     */
    public static function getTimeZone(\Mvorisek\Net\IpAddress $ip, $localeFallback = true)
    {
        $ipData = static::getRaw($ip);
        if (!empty($ipData['location']['time_zone'])) {
            return $ipData['location']['time_zone'];
        }

        // @TODO country / subdivision fallback ?
        throw new \Error('Not implemented');
    }

    /**
     * @param array<string, string> $names
     * @param string                $locale
     * @param bool                  $localeFallback
     *
     * @return string|null
     */
    protected static function getName($names, $locale = 'en', $localeFallback = true)
    {
        $locale = strtolower(trim($locale));
        if (isset($names[$locale])) {
            return $names[$locale];
        } elseif ($localeFallback && isset($names['en'])) {
            return $names['en'];
        }

        return null;
    }
}
