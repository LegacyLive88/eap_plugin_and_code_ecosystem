<?php

namespace AmeliaBooking\Infrastructure\Services\Mailchimp;

use AmeliaBooking\Domain\Services\DateTime\DateTimeService;
use AmeliaBooking\Domain\Services\Logger\LoggerInterface;
use AmeliaBooking\Infrastructure\Common\Container;

/**
 * Class MailchimpService
 *
 * @package AmeliaBooking\Infrastructure\Services\Mailchimp
 */
class MailchimpService extends AbstractMailchimpService
{
    private array $settings;
    private LoggerInterface $logger;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->logger = $container->getLoggerService();

        $this->settings = $this->container->get('domain.settings.service')->getCategorySettings('mailchimp');
    }

    public function createAuthUrl(): string
    {
        return 'https://login.mailchimp.com/oauth2/authorize?' .
            http_build_query([
                'response_type' => 'code',
                'client_id'     => AMELIA_MAILCHIMP_CLIENT_ID,
                'redirect_uri'  => AMELIA_MIDDLEWARE_URL . 'mailchimp/redirect',
                'state'         => urlencode(admin_url('admin-ajax.php', ''))
            ]);
    }

    private function makeRequest(string $endpoint, string $method = 'GET', ?array $body = null): array
    {
        $mailchimpAccessToken = $this->settings['accessToken'];
        $mailchimpServer = $this->settings['server'];

        if (!$mailchimpAccessToken || !$mailchimpServer) {
            // Missing configuration; keep prior behavior to avoid noisy exceptions
            return [];
        }

        $url = 'https://' . $mailchimpServer . '.api.mailchimp.com/3.0' . $endpoint;

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $mailchimpAccessToken,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $message = $response instanceof \WP_Error ? $response->get_error_message() : 'WP HTTP error';
            throw new MailchimpRequestException(
                $message,
                $endpoint,
                $method,
                0,
                null
            );
        }

        $responseBody = wp_remote_retrieve_body($response);
        $responseCode = (int) wp_remote_retrieve_response_code($response);
        if ($responseCode < 200 || $responseCode >= 300) {
            if ($responseCode === 404) {
                throw new MailchimpNotFoundException(
                    'Mailchimp resource not found',
                    $endpoint,
                    $method,
                    $responseCode,
                    $responseBody
                );
            }

            throw new MailchimpRequestException(
                'Mailchimp request failed',
                $endpoint,
                $method,
                $responseCode,
                $responseBody
            );
        }

        return json_decode($responseBody, true);
    }

    public function getLists(): array
    {
        try {
            $response = $this->makeRequest('/lists', 'GET');

            if (!$response || !isset($response['lists'])) {
                return [];
            }

            return array_map(function ($list) {
                return [
                    'id'   => $list['id'],
                    'name' => $list['name'],
                ];
            }, $response['lists']);
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch Mailchimp lists', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function getMetadataServerName(string $accessToken): ?string
    {
        try {
            $response = wp_remote_get('https://login.mailchimp.com/oauth2/metadata', [
                'headers' => [
                    'Authorization' => 'OAuth ' . $accessToken,
                ],
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                return null;
            }

            $responseBody = wp_remote_retrieve_body($response);
            $data = json_decode($responseBody, true);

            return $data['dc'] ?? null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get Mailchimp metadata server name', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }


    /**
     * Add subscriber to the mailing list or update existing subscriber.
     *
     * @param string $email
     * @param array  $customer
     * @param bool   $add
     *
     * @return void
     */
    public function addOrUpdateSubscriber($email, array $customer, bool $add = true): void
    {
        $mailchimpList = $this->settings['list'];

        if (!$mailchimpList) {
            return;
        }

        $birthday = '';
        if (!empty($customer['birthday'])) {
            $birthday = $customer['birthday'];
            if (is_string($customer['birthday'])) {
                $birthday = DateTimeService::getCustomDateTimeObject($birthday);
            }
            $birthday = $birthday->format('m/d');
        }

        $mergeFields = [
            'FNAME' => $customer['firstName'],
            'LNAME' => !empty($customer['lastName']) ? $customer['lastName'] : '',
            'PHONE' => !empty($customer['phone']) ? $customer['phone'] : '',
            'BIRTHDAY' => $birthday,
        ];

        try {
            $subscriberHash = md5(strtolower($add ? $customer['email'] : $email));
            $endpoint = '/lists/' . $mailchimpList . '/members/' . $subscriberHash;

            if ($add) {
                $this->makeRequest($endpoint, 'PUT', [
                    'email_address' => $customer['email'],
                    'status' => 'subscribed',
                    'merge_fields'  => $mergeFields
                ]);

                return;
            }

            $this->makeRequest($endpoint, 'PATCH', [
                'email_address' => $customer['email'],
                'merge_fields' => $mergeFields
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to add or update Mailchimp subscriber', [
                'email' => $email,
                'add' => $add,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function deleteSubscriber($email)
    {
        $mailchimpList = $this->settings['list'];
        if (!$mailchimpList) {
            return;
        }

        try {
            $subscriberHash = md5(strtolower($email));
            $endpoint = '/lists/' . $mailchimpList . '/members/' . $subscriberHash;
            $this->makeRequest($endpoint, 'DELETE');
        } catch (MailchimpNotFoundException $e) {
            // Intentionally ignore 404 Not Found when deleting non-existent subscriber
            return;
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete Mailchimp subscriber', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
