<?php

namespace AmeliaBooking\Domain\Services\Api;

use DateTime;

/**
 * Class ApiService
 *
 * @package AmeliaBooking\Domain\Services\Api
 */
class ApiService extends BasicApiService
{
    public const HASH_ALGORITHM = 'sha256';

    /**
     * @param $apiKey
     * @param $hashedKeys
     *
     * @return bool
     */
    public function checkApiKeys($apiKey, $hashedKeys)
    {
        foreach ($hashedKeys as $key) {
            if (
                hash(self::HASH_ALGORITHM, $apiKey) === $key['key'] &&
                (empty($key['expiration']) || DateTime::createFromFormat('U', $key['expiration']) > new DateTime())
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param $apiKey
     *
     * @return string
     */
    public function createHash($apiKey)
    {
        return hash(self::HASH_ALGORITHM, $apiKey);
    }
}
