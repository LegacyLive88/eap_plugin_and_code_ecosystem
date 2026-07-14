<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Infrastructure\Services\Recaptcha;

use AmeliaBooking\Application\Commands\CommandResult;

/**
 * Class RecaptchaService
 */
class RecaptchaService extends AbstractRecaptchaService
{
    /**
     * @param string $value
     *
     * @return boolean
     */
    public function verify($value)
    {
        $googleRecaptchaSettings = $this->settingsService->getSetting(
            'general',
            'googleRecaptcha'
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://www.google.com/recaptcha/api/siteverify');
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);

        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            [
                'secret'   => $googleRecaptchaSettings['secret'],
                'response' => $value
            ]
        );

        $response = json_decode(curl_exec($ch));

        return $response->success;
    }

    /**
     * Verify recaptcha with provided secret and token
     *
     * @param string $secret
     * @param string $token
     *
     * @return array Array with 'success' (bool), 'message' (string), and optional 'error_codes' (array)
     */
    public function verifyWithSecret($secret, $token)
    {
        if (empty($secret)) {
            return [
                'success' => false,
                'message' => 'Missing secret'
            ];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://www.google.com/recaptcha/api/siteverify');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'secret'   => $secret,
            'response' => $token ?: ''
        ]);

        $response = json_decode(curl_exec($ch));
        curl_close($ch);

        if (!$response) {
            return [
                'success' => false,
                'message' => 'Failed to connect to Google Recaptcha service.'
            ];
        }

        $errorCodes = isset($response->{'error-codes'}) ? $response->{'error-codes'} : [];

        if ($response->success) {
            return [
                'success' => true,
                'message' => 'Validation successful'
            ];
        }

        return [
            'success'     => false,
            'message'     => 'Recaptcha verification failed',
            'error_codes' => $errorCodes
        ];
    }

    /**
     * @param string $value
     * @param string $cabinetType
     *
     * @return boolean
     */
    public function process($value, $cabinetType)
    {
        $googleRecaptchaSettings = $this->settingsService->getSetting(
            'general',
            'googleRecaptcha'
        );

        $userRecaptchaSettings = $this->settingsService->getSetting(
            'roles',
            $cabinetType . 'Cabinet'
        );

        if (
            $this->settingsService->isFeatureEnabled('recaptcha') &&
            $googleRecaptchaSettings['siteKey'] &&
            $googleRecaptchaSettings['secret'] &&
            !empty($userRecaptchaSettings['googleRecaptcha']) &&
            (!$value || !$this->verify($value))
        ) {
            return false;
        }

        return true;
    }
}
