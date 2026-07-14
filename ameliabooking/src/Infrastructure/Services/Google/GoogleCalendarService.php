<?php

namespace AmeliaBooking\Infrastructure\Services\Google;

use AmeliaBooking\Application\Services\CustomField\AbstractCustomFieldApplicationService;
use AmeliaBooking\Application\Services\Placeholder\PlaceholderService;
use AmeliaBooking\Application\Services\User\ProviderApplicationService;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Booking\Appointment\Appointment;
use AmeliaBooking\Domain\Entity\Booking\Appointment\CustomerBooking;
use AmeliaBooking\Domain\Entity\Booking\Event\Event;
use AmeliaBooking\Domain\Entity\Booking\Event\EventPeriod;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Entity\User\Provider;
use AmeliaBooking\Domain\Factory\Booking\Appointment\AppointmentFactory;
use AmeliaBooking\Domain\Factory\Google\GoogleCalendarFactory;
use AmeliaBooking\Domain\Factory\User\ProviderFactory;
use AmeliaBooking\Domain\Services\DateTime\DateTimeService;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Domain\ValueObjects\String\Name;
use AmeliaBooking\Domain\ValueObjects\String\Token;
use AmeliaBooking\Infrastructure\Common\Container;
use AmeliaBooking\Infrastructure\Common\Exceptions\NotFoundException;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Booking\Appointment\AppointmentRepository;
use AmeliaBooking\Infrastructure\Repository\Booking\Event\EventPeriodsRepository;
use AmeliaBooking\Infrastructure\Repository\Booking\Event\EventRepository;
use AmeliaBooking\Infrastructure\Repository\Location\LocationRepository;
use AmeliaBooking\Infrastructure\Repository\User\CustomerRepository;
use AmeliaBooking\Infrastructure\Repository\User\ProviderRepository;
use AmeliaBooking\Infrastructure\WP\EventListeners\Booking\Appointment\AppointmentAddedEventHandler;
use AmeliaBooking\Infrastructure\WP\EventListeners\Booking\Appointment\AppointmentDeletedEventHandler;
use AmeliaBooking\Infrastructure\WP\EventListeners\Booking\Appointment\AppointmentEditedEventHandler;
use AmeliaBooking\Infrastructure\WP\EventListeners\Booking\Appointment\AppointmentStatusUpdatedEventHandler;
use AmeliaBooking\Infrastructure\WP\EventListeners\Booking\Appointment\AppointmentTimeUpdatedEventHandler;
use AmeliaBooking\Infrastructure\WP\EventListeners\Booking\Appointment\BookingAddedEventHandler;
use AmeliaBooking\Infrastructure\WP\EventListeners\Booking\Appointment\BookingApprovedEventHandler;
use AmeliaBooking\Infrastructure\WP\EventListeners\Booking\Appointment\BookingCanceledEventHandler;
use AmeliaBooking\Infrastructure\WP\EventListeners\Booking\Appointment\BookingRejectedEventHandler;
use AmeliaBooking\Infrastructure\WP\EventListeners\Booking\Event\EventAddedEventHandler;
use AmeliaBooking\Infrastructure\WP\EventListeners\Booking\Event\EventEditedEventHandler;
use AmeliaBooking\Infrastructure\WP\EventListeners\Booking\Event\EventStatusUpdatedEventHandler;
use AmeliaVendor\Google\Client;
use AmeliaVendor\Google\Service\Calendar;
use AmeliaVendor\Google\Service\Calendar\CalendarListEntry;
use Exception;

/**
 * Class GoogleCalendarService
 *
 * @package AmeliaBooking\Infrastructure\Services\Google
 */
class GoogleCalendarService extends AbstractGoogleCalendarService
{
    /** @var Client|null $client */
    private $client;

    /** @var Calendar|null $service */
    private $service;

    /** @var mixed */
    private $googleCalendarSettings;

    /** @var string */
    private $timeZone;

    /** @var SettingsService */
    private $settings;

    /**
     * GoogleClientService constructor.
     *
     * @param Container $container
     *
     */
    public function __construct(Container $container)
    {
        $this->container = $container;

        $this->settings = $this->container->get('domain.settings.service');
        $this->googleCalendarSettings = $this->settings->getCategorySettings('googleCalendar');
        $this->client = new Client();
        $this->client->setClientId($this->googleCalendarSettings['clientID']);
        $this->client->setClientSecret($this->googleCalendarSettings['clientSecret']);
    }

    /**
     * @return bool
     */
    private function isCalendarEnabled()
    {
        return $this->settings->isFeatureEnabled('googleCalendar') &&
            $this->googleCalendarSettings['clientID'] &&
            $this->googleCalendarSettings['clientSecret'];
    }

    private function isAccessTokenSet()
    {
        return (
            (!array_key_exists('accessToken', $this->googleCalendarSettings) ||
                $this->googleCalendarSettings['accessToken']) &&
            $this->settings->isFeatureEnabled('googleCalendar')
        );
    }

    /**
     * Create a URL to obtain user authorization.
     *
     * @param $providerId
     * @param $redirectUri
     *
     * @return string
     */
    public function createAuthUrl($providerId, $redirectUri)
    {
        // TODO: Redesign back to '/wp-admin/admin.php?page=wpamelia-employees' after redesign will be finished
        $this->client->setRedirectUri(
            empty($redirectUri) ?
            AMELIA_SITE_URL . '/wp-admin/admin.php?page=wpamelia-employees' :
            explode('?', $redirectUri)[0]
        );
        $this->client->setState($providerId);
        $this->client->addScope('https://www.googleapis.com/auth/calendar');
        $this->client->setApprovalPrompt('force');
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');

        return $this->client->createAuthUrl();
    }

    /**
     * Exchange a code for a valid authentication token.
     *
     * @param $authCode
     * @param $redirectUri
     * @return array
     */
    public function fetchAccessTokenWithAuthCode($authCode, $redirectUri)
    {
        $this->client->setRedirectUri($redirectUri);

        return $this->client->fetchAccessTokenWithAuthCode($authCode);
    }

    /**
     * Returns entries on the user's calendar list.
     *
     * @param Provider $provider
     *
     * @return array
     *
     * @throws InvalidArgumentException
     * @throws QueryExecutionException
     * @throws ContainerException
     */
    public function listCalendarList($provider)
    {
        $calendars = [];

        if ($provider && $provider->getGoogleCalendar() && $this->isCalendarEnabled()) {
            if (!$this->authorizeProvider($provider)) {
                return $calendars;
            }

            $calendarList = $this->service->calendarList->listCalendarList(['minAccessRole' => 'writer']);

            /** @var CalendarListEntry $calendar */
            foreach ($calendarList->getItems() as $calendar) {
                $calendars[] = [
                    'id'      => $calendar->getId(),
                    'primary' => $calendar->getPrimary(),
                    'summary' => $calendar->getSummary()
                ];
            }
        }

        return $calendars;
    }

    /**
     * Get Provider's Google Calendar ID.
     *
     * @param Provider $provider
     *
     * @return null|string
     * @throws InvalidArgumentException
     * @throws QueryExecutionException
     * @throws ContainerException
     */
    public function getProviderGoogleCalendarId($provider)
    {
        if (!$this->isCalendarEnabled()) {
            return null;
        }

        // If Google Calendar ID is not set, take the primary calendar and save it as Provider's Google Calendar ID
        if ($provider && $provider->getGoogleCalendar() && empty($provider->getGoogleCalendar()->getCalendarId()->getValue())) {
            $calendarList = $this->listCalendarList($provider);

            /** @var ProviderApplicationService $providerApplicationService */
            $providerApplicationService = $this->container->get('application.user.provider.service');

            $provider->getGoogleCalendar()->setCalendarId(new Name($calendarList[0]['id']));

            $providerApplicationService->updateProviderGoogleCalendar($provider);

            return $provider->getGoogleCalendar()->getCalendarId()->getValue();
        }

        // If Google Calendar is set, return it
        if ($provider && $provider->getGoogleCalendar() && !empty($provider->getGoogleCalendar()->getCalendarId()->getValue())) {
            return $provider->getGoogleCalendar()->getCalendarId()->getValue();
        }

        return null;
    }

    /**
     * Handle Google Calendar Event's.
     *
     * @param Appointment|Event $appointment
     * @param string      $commandSlug
     *
     * @return void
     * @throws QueryExecutionException
     * @throws ContainerException
     */
    public function handleEvent($appointment, $commandSlug)
    {
        if (!$this->isCalendarEnabled() && !$this->isAccessTokenSet()) {
            return;
        }

        try {
            $this->handleEventAction($appointment, $commandSlug);
        } catch (Exception $e) {
            /** @var AppointmentRepository $appointmentRepository */
            $appointmentRepository = $this->container->get('domain.booking.appointment.repository');

            $appointmentRepository->updateErrorColumn($appointment->getId()->getValue(), $e->getMessage());
        }
    }

    /**
     * Handle Google Calendar Events.
     *
     * @param Event $event
     * @param string $commandSlug
     * @param Collection $periods
     * @param array $providers
     *
     * @return void
     * @throws QueryExecutionException
     * @throws ContainerException
     */
    public function handleEventPeriodsChange($event, $commandSlug, $periods, $providers = null, $providersRemove = null)
    {
        if (!$this->isCalendarEnabled() && !$this->isAccessTokenSet()) {
            return;
        }

        try {
            $this->handleEventPeriodsChangeAction($event, $commandSlug, $periods, $providers, $providersRemove);
        } catch (Exception $e) {
            /** @var EventRepository $eventRepository */
            $eventRepository = $this->container->get('domain.booking.event.repository');

            $eventRepository->updateErrorColumn($event->getId()->getValue(), $e->getMessage());
        }
    }

    /**
     * Handle Google Calendar Event's.
     *
     * @param Appointment|Event $appointment
     * @param string      $commandSlug
     *
     * @return void
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws QueryExecutionException
     * @throws ContainerException
     */
    private function handleEventAction($appointment, $commandSlug)
    {
        if (!$this->isCalendarEnabled() && !$this->isAccessTokenSet()) {
            return;
        }

        /** @var ProviderRepository $providerRepository */
        $providerRepository = $this->container->get('domain.users.providers.repository');

        $appointmentStatus = $appointment->getStatus()->getValue();
        $provider          = $providerRepository->getById($appointment->getProviderId()->getValue());
        if (
            $provider && (
                ($provider->getGoogleCalendar() && $provider->getGoogleCalendar()->getCalendarId()->getValue()) ||
                ($provider->getGoogleCalendarId() && $provider->getGoogleCalendarId()->getValue())
            )
        ) {
            if (!$this->authorizeProvider($provider)) {
                return;
            }

            switch ($commandSlug) {
                case AppointmentAddedEventHandler::APPOINTMENT_ADDED:
                case BookingAddedEventHandler::BOOKING_ADDED:
                    // Add new appointment or update existing one
                    if (!$appointment->getGoogleCalendarEventId()) {
                        $this->insertEvent($appointment, $provider);
                    } else {
                        $this->updateEvent($appointment, $provider);
                    }

                    // When status is pending we must first insert the event to get event ID
                    // because if we update the status later to 'Approved' we must have ID of the event
                    if ($appointmentStatus === 'pending' && $this->googleCalendarSettings['insertPendingAppointments'] === false) {
                        $this->deleteEvent($appointment, $provider);
                    }
                    break;
                case AppointmentEditedEventHandler::APPOINTMENT_EDITED:
                case AppointmentTimeUpdatedEventHandler::TIME_UPDATED:
                case AppointmentStatusUpdatedEventHandler::APPOINTMENT_STATUS_UPDATED:
                case BookingCanceledEventHandler::BOOKING_CANCELED:
                case BookingApprovedEventHandler::BOOKING_APPROVED:
                case BookingRejectedEventHandler::BOOKING_REJECTED:
                    if (
                        $appointmentStatus === 'canceled' || $appointmentStatus === 'rejected' ||
                        ($appointmentStatus === 'pending' && $this->googleCalendarSettings['insertPendingAppointments'] === false)
                    ) {
                        $this->deleteEvent($appointment, $provider);
                        break;
                    }

                    if (!$appointment->getGoogleCalendarEventId()) {
                        $this->insertEvent($appointment, $provider);
                        break;
                    }

                    $this->updateEvent($appointment, $provider);
                    break;
                case AppointmentDeletedEventHandler::APPOINTMENT_DELETED:
                    $this->deleteEvent($appointment, $provider);
                    break;
            }
        }
    }

    /**
     * Handle Google Calendar Events.
     *
     * @param Event $event
     * @param string $commandSlug
     * @param Collection $periods
     * @param array $providers
     *
     * @return void
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws QueryExecutionException
     * @throws ContainerException
     */
    private function handleEventPeriodsChangeAction($event, $commandSlug, $periods, $providers = null, $providersRemove = null)
    {
        if (!$this->isCalendarEnabled() && !$this->isAccessTokenSet()) {
            return;
        }

        /** @var ProviderRepository $providerRepository */
        $providerRepository = $this->container->get('domain.users.providers.repository');

        if ($event->getOrganizerId()) {
            $provider = $providerRepository->getById($event->getOrganizerId()->getValue());

            if (
                $provider && (
                    ($provider->getGoogleCalendar() && $provider->getGoogleCalendar()->getCalendarId()->getValue()) ||
                    ($provider->getGoogleCalendarId() && $provider->getGoogleCalendarId()->getValue())
                )
            ) {
                if (!$this->authorizeProvider($provider)) {
                    return;
                }

                /** @var EventPeriod $period */
                foreach ($periods->getItems() as $period) {
                    switch ($commandSlug) {
                        case EventAddedEventHandler::EVENT_ADDED:
                        case EventEditedEventHandler::TIME_UPDATED:
                        case EventEditedEventHandler::PROVIDER_CHANGED:
                            if (!$period->getGoogleCalendarEventId()) {
                                $this->insertEvent($event, $provider, $period);
                                break;
                            }

                            $this->updateEvent($event, $provider, $period, $providers, $providersRemove);
                            break;
                        case EventEditedEventHandler::EVENT_PERIOD_DELETED:
                            $this->deleteEvent($period, $provider);
                            $this->deleteEventPeriodEvent($period);
                            break;
                        case BookingAddedEventHandler::BOOKING_ADDED:
                        case BookingCanceledEventHandler::BOOKING_CANCELED:
                            $this->patchEvent($event, $provider, $period);
                            break;
                        case EventStatusUpdatedEventHandler::EVENT_STATUS_UPDATED:
                            if ($event->getStatus()->getValue() === 'rejected') {
                                $this->deleteEvent($period, $provider);
                                $this->deleteEventPeriodEvent($period);
                            } elseif ($event->getStatus()->getValue() === 'approved') {
                                $this->insertEvent($event, $provider, $period);
                            }

                            break;
                        case EventEditedEventHandler::EVENT_PERIOD_ADDED:
                            $this->insertEvent($event, $provider, $period);
                            break;
                    }
                }
            }
        }
    }

    /**
     * Get providers events within date range
     *
     * @param array $providerArr
     * @param string $dateStart
     * @param string $dateStartEnd
     * @param string $dateEnd
     * @param array $eventIds

     * @return array
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws QueryExecutionException
     * @throws ContainerException
     */
    public function getEvents($providerArr, $dateStart, $dateStartEnd, $dateEnd, $eventIds)
    {
        if (!$this->isCalendarEnabled() && !$this->isAccessTokenSet()) {
            return [];
        }

        $finalEvents = [];
        $provider    = ProviderFactory::create($providerArr);
        if (
            $provider && (
                ($provider->getGoogleCalendar() && $provider->getGoogleCalendar()->getCalendarId()->getValue()) ||
                ($provider->getGoogleCalendarId() && $provider->getGoogleCalendarId()->getValue())
            )
        ) {
            if (!$this->authorizeProvider($provider)) {
                return $finalEvents;
            }

            $googleCalendarId = $provider->getGoogleCalendar() ?
                $provider->getGoogleCalendar()->getCalendarId()->getValue() :
                $provider->getGoogleCalendarId()->getValue();

            $events = $this->service->events->listEvents(
                $googleCalendarId,
                [
                    'maxResults'   => $this->googleCalendarSettings['maximumNumberOfEventsReturned'],
                    'orderBy'      => 'startTime',
                    'singleEvents' => true,
                    'timeMin'      => $dateStart,
                    'timeMax'      => $dateEnd
                ]
            );

            $startDate    = DateTimeService::getCustomDateTimeObject($dateStart);
            $startDateEnd = DateTimeService::getCustomDateTimeObject($dateStartEnd);

            foreach ($events->getItems() as $event) {
                if (empty($event->getTransparency())) {
                    $extendedProperties = $event->getExtendedProperties();
                    if ($extendedProperties !== null) {
                        $shared = $extendedProperties->shared;
                        if (
                            is_array($shared) &&
                            array_key_exists('ameliaEvent', $shared) &&
                            $eventIds !== null &&
                            array_key_exists('ameliaAppointmentId', $shared) &&
                            in_array((int)$shared['ameliaAppointmentId'], $eventIds)
                        ) {
                            continue;
                        }
                    }

                    $eventStart = DateTimeService::getCustomDateTimeObject($event->getStart()->getDateTime());
                    $eventEnd   = DateTimeService::getCustomDateTimeObject($event->getEnd()->getDateTime());

                    $eventDateStart = DateTimeService::getCustomDateTimeObject($eventStart->format('Y-m-d') . ' ' . $startDate->format('H:i:s'));
                    $eventDateEnd   = DateTimeService::getCustomDateTimeObject($eventEnd->format('Y-m-d') . ' ' . $startDateEnd->format('H:i:s'));

                    if ($eventDateEnd <= $eventStart || $eventDateStart >= $eventEnd) {
                        continue;
                    }

                    $finalEvents[] = $event;
                }
            }
        }
        return $finalEvents;
    }


    /**
     * Create fake appointments in provider's list so that these slots will not be available for booking
     *
     * @param Collection $providers
     * @param int        $excludeAppointmentId
     * @param \DateTime  $startDateTime
     * @param \DateTime  $endDateTime
     *
     * @throws InvalidArgumentException
     * @throws QueryExecutionException
     * @throws Exception
     * @throws ContainerException
     */
    public function removeSlotsFromGoogleCalendar(
        $providers,
        $excludeAppointmentId,
        $startDateTime,
        $endDateTime
    ) {
        if (!$this->isCalendarEnabled() && !$this->isAccessTokenSet()) {
            return;
        }

        if ($this->googleCalendarSettings['removeGoogleCalendarBusySlots'] === true) {
            foreach ($providers->keys() as $providerKey) {
                /** @var Provider $provider */
                $provider = $providers->getItem($providerKey);

                if ($provider && ($provider->getGoogleCalendar() || $provider->getGoogleCalendarId())) {
                    if (!array_key_exists($provider->getId()->getValue(), self::$providersGoogleEvents)) {
                        if (!$this->authorizeProvider($provider)) {
                            continue;
                        }

                        $this->timeZone = $this->service->calendars->get('primary')->getTimeZone();

                        $startDateTimeCopy = clone $startDateTime;

                        $startDateTimeCopy->modify('-1 days');

                        $endDateTimeCopy = clone $endDateTime;

                        $endDateTimeCopy->modify('+1 days');

                        $googleCalendarId = $provider->getGoogleCalendar() ?
                            $provider->getGoogleCalendar()->getCalendarId()->getValue() :
                            $provider->getGoogleCalendarId()->getValue();

                        $events = $this->service->events->listEvents(
                            $googleCalendarId,
                            [
                                'maxResults'   => $this->googleCalendarSettings['maximumNumberOfEventsReturned'],
                                'orderBy'      => 'startTime',
                                'singleEvents' => true,
                                'timeMin'      => DateTimeService::getCustomDateTimeRFC3339(
                                    $startDateTimeCopy->format('Y-m-d')
                                ),
                                'timeMax'      => DateTimeService::getCustomDateTimeRFC3339(
                                    $endDateTimeCopy->format('Y-m-d')
                                ),
                            ]
                        );

                        self::$providersGoogleEvents[$provider->getId()->getValue()] = $events;
                    } else {
                        $events = self::$providersGoogleEvents[$provider->getId()->getValue()];
                    }

                    /** @var Calendar\Event $event */
                    foreach ($events->getItems() as $event) {
                        // Continue if event is set to "Free"
                        if ($event->getTransparency() === 'transparent') {
                            continue;
                        }

                        $extendedProperties = $event->getExtendedProperties();
                        if ($extendedProperties !== null) {
                            $shared = $extendedProperties->shared;
                            if (
                                is_array($shared) &&
                                array_key_exists('ameliaEvent', $shared) &&
                                $excludeAppointmentId !== null &&
                                array_key_exists('ameliaAppointmentId', $shared) &&
                                (int)$shared['ameliaAppointmentId'] === (int)$excludeAppointmentId
                            ) {
                                continue;
                            }
                        }

                        if ($event->getStart()->dateTime === null) {
                            $eventStartString = $this->getEventDateTimeStringFromEventDate($event->getStart()->date);

                            $eventEndString = $this->getEventDateTimeStringFromEventDate($event->getEnd()->date);
                        } else {
                            $eventStartString = $this->getEventDateTimeStringFromEventDateTime($event->getStart()->dateTime);

                            $eventEndString = $this->getEventDateTimeStringFromEventDateTime($event->getEnd()->dateTime);
                        }

                        /** @var Appointment $appointment */
                        $appointment = AppointmentFactory::create(
                            [
                                'bookingStart'       => $eventStartString,
                                'bookingEnd'         => $eventEndString,
                                'notifyParticipants' => false,
                                'serviceId'          => 0,
                                'providerId'         => $provider->getId()->getValue(),
                            ]
                        );

                        $provider->getAppointmentList()->addItem($appointment);
                    }
                }
            }
        }
    }



    /**
     * Delete Event period google calendar id
     *
     * @param EventPeriod $period
     *
     * @throws QueryExecutionException
     * @throws ContainerException
     */
    private function deleteEventPeriodEvent($period)
    {
        /** @var EventPeriodsRepository $eventPeriodsRepository */
        $eventPeriodsRepository = $this->container->get('domain.booking.event.period.repository');

        $period->setGoogleCalendarEventId(null);
        $period->setGoogleMeetUrl(null);

        $eventPeriodsRepository->updateFieldById($period->getId()->getValue(), null, 'googleCalendarEventId');
        $eventPeriodsRepository->updateFieldById($period->getId()->getValue(), null, 'googleMeetUrl');
    }

        /**
     * Insert an Event in Google Calendar.
     *
     * @param Appointment|Event $appointment
     * @param Provider    $provider
     * @param EventPeriod $period
     *
     * @throws NotFoundException
     * @throws QueryExecutionException
     * @throws ContainerException
     */
    private function insertEvent($appointment, $provider, $period = null)
    {
        $queryParams = ['sendUpdates' => $this->googleCalendarSettings['sendEventInvitationEmail'] ? 'all' : 'none'];

        /** @var SettingsService $settingsService */
        $settingsService  = $this->container->get('domain.settings.service');
        $enabledForEntity = $settingsService
            ->getEntitySettings($period ? $appointment->getSettings() : $appointment->getService()->getSettings())
            ->getGoogleMeetSettings()
            ->getEnabled();

        if ($enabledForEntity) {
            $queryParams['conferenceDataVersion'] = 1;
        }

        $event = $this->createEvent($appointment, $provider, $period);

        $event = apply_filters('amelia_before_google_calendar_event_added_filter', $event, $appointment->toArray(), $provider->toArray());

        do_action('amelia_before_google_calendar_event_added', $event, $appointment->toArray(), $provider->toArray());

        $googleCalendarId = $provider->getGoogleCalendar() ?
            $provider->getGoogleCalendar()->getCalendarId()->getValue() :
            $provider->getGoogleCalendarId()->getValue();

        $event = $this->service->events->insert(
            $googleCalendarId,
            $event,
            $queryParams
        );

        if ($period) {
            /** @var EventPeriodsRepository $eventPeriodsRepository */
            $eventPeriodsRepository = $this->container->get('domain.booking.event.period.repository');

            $period->setGoogleCalendarEventId(new Token($event->getId()));
            $period->setGoogleMeetUrl($event->getHangoutLink());

            $eventPeriodsRepository->updateFieldById($period->getId()->getValue(), $period->getGoogleCalendarEventId()->getValue(), 'googleCalendarEventId');
            $eventPeriodsRepository->updateFieldById($period->getId()->getValue(), $period->getGoogleMeetUrl(), 'googleMeetUrl');
        } else {
            /** @var AppointmentRepository $appointmentRepository */
            $appointmentRepository = $this->container->get('domain.booking.appointment.repository');

            $appointment->setGoogleCalendarEventId(new Token($event->getId()));
            $appointment->setGoogleMeetUrl($event->getHangoutLink());

            $appointmentRepository->update($appointment->getId()->getValue(), $appointment);
        }

        do_action('amelia_after_google_calendar_event_added', $event, $appointment->toArray(), $provider->toArray());
    }

    /**
     * Update an Event in Google Calendar.
     *
     * @param Appointment|Event $appointment
     * @param Provider    $provider
     * @param EventPeriod $period
     *
     *
     * @throws NotFoundException
     * @throws QueryExecutionException
     * @throws ContainerException
     */
    private function updateEvent($appointment, $provider, $period = null, $providers = null, $providersRemove = null)
    {
        $event = $this->createEvent($appointment, $provider, $period, $providers, $providersRemove);

        $entity = $period ?: $appointment;
        if ($entity->getGoogleCalendarEventId()) {
            $event = apply_filters('amelia_before_google_calendar_event_updated_filter', $event, $appointment->toArray(), $provider->toArray());

            do_action('amelia_before_google_calendar_event_updated', $event, $appointment->toArray(), $provider->toArray());

            $googleCalendarId = $provider->getGoogleCalendar() ?
                $provider->getGoogleCalendar()->getCalendarId()->getValue() :
                $provider->getGoogleCalendarId()->getValue();

            $this->service->events->update(
                $googleCalendarId,
                $entity->getGoogleCalendarEventId()->getValue(),
                $event,
                ['sendUpdates' => $this->googleCalendarSettings['sendEventInvitationEmail'] ? 'all' : 'none']
            );

            do_action('amelia_after_google_calendar_event_updated', $event, $appointment->toArray(), $provider->toArray());
        }
    }

    /**
     * Patch an Event in Google Calendar.
     *
     * @param Appointment|Event $appointment
     * @param Provider    $provider
     * @param EventPeriod $period
     *
     *
     * @throws NotFoundException
     * @throws QueryExecutionException
     * @throws ContainerException
     */
    private function patchEvent($appointment, $provider, $period = null)
    {
        $event = $this->createEvent($appointment, $provider, $period);
        $event->setAttendees($this->getAttendees($appointment, $period));

        $entity = $period ?: $appointment;
        if ($entity->getGoogleCalendarEventId()) {
            $event = apply_filters('amelia_before_google_calendar_event_patched_filter', $event, $appointment->toArray(), $provider->toArray());

            do_action('amelia_before_google_calendar_event_patched', $event, $appointment->toArray(), $provider->toArray());

            $googleCalendarId = $provider->getGoogleCalendar() ?
                $provider->getGoogleCalendar()->getCalendarId()->getValue() :
                $provider->getGoogleCalendarId()->getValue();

            $this->service->events->patch(
                $googleCalendarId,
                $entity->getGoogleCalendarEventId()->getValue(),
                $event,
                ['sendUpdates' => $this->googleCalendarSettings['sendEventInvitationEmail'] ? 'all' : 'none']
            );

            do_action('amelia_after_google_calendar_event_patched', $event, $appointment->toArray(), $provider->toArray());
        }
    }

    /**
     * Delete an Event from Google Calendar.
     *
     * @param Appointment|EventPeriod $appointment
     * @param Provider $provider
     *
     * @throws ContainerException
     */
    private function deleteEvent($appointment, $provider)
    {
        if ($appointment->getGoogleCalendarEventId()) {
            do_action('amelia_before_google_calendar_event_deleted', $appointment->toArray(), $provider->toArray());

            $googleCalendarId = $provider->getGoogleCalendar() ?
                $provider->getGoogleCalendar()->getCalendarId()->getValue() :
                $provider->getGoogleCalendarId()->getValue();

            $this->service->events->delete(
                $googleCalendarId,
                $appointment->getGoogleCalendarEventId()->getValue()
            );

            do_action('amelia_after_google_calendar_event_deleted', $appointment->toArray(), $provider->toArray());
        }
    }

    /**
     * Create and return Google Calendar Event Object filled with appointments data.
     *
     * @param Appointment|Event $appointment
     * @param Provider    $provider
     * @param EventPeriod $period
     *
     * @return Calendar\Event
     *
     * @throws NotFoundException
     * @throws QueryExecutionException
     * @throws ContainerException
     * @throws Exception
     */
    private function createEvent($appointment, $provider, $period = null, $providers = null, $providersRemove = null)
    {
        /** @var LocationRepository $locationRepository */
        $locationRepository = $this->container->get('domain.locations.repository');

        /** @var AbstractCustomFieldApplicationService $customFieldService */
        $customFieldService = $this->container->get('application.customField.service');

        $type = $period ? Entities::EVENT : Entities::APPOINTMENT;
        /** @var PlaceholderService $placeholderService */
        $placeholderService = $this->container->get("application.placeholder.{$type}.service");

        $appointmentLocationId = $appointment->getLocationId() ? $appointment->getLocationId()->getValue() : null;

        $providerLocationId = $provider->getLocationId() ? $provider->getLocationId()->getValue() : null;

        $locationId = $appointmentLocationId ?: $providerLocationId;

        $location = $locationId ? $locationRepository->getById($locationId) : null;

        $address = $customFieldService->getCalendarEventLocation($appointment);
        $address = $address ?: ($location ? $location->getAddress()->getValue() : null);

        $attendees = $this->getAttendees($appointment, $period, $providers, $providersRemove);

        $appointmentArray           = $appointment->toArray();
        $appointmentArray['sendCF'] = true;

        $placeholderData = $placeholderService->getPlaceholdersData($appointmentArray);

        $start = $period ? clone $period->getPeriodStart()->getValue() : clone $appointment->getBookingStart()->getValue();

        if ($period) {
            $time = (int)$period->getPeriodEnd()->getValue()->format('H') * 60 + (int)$period->getPeriodEnd()->getValue()->format('i');

            $end = DateTimeService::getCustomDateTimeObject(
                $start->format('Y-m-d')
            )->add(new \DateInterval('PT' . $time . 'M'));
        } else {
            $end = clone $appointment->getBookingEnd()->getValue();
        }

        if ($this->googleCalendarSettings['includeBufferTimeGoogleCalendar'] === true && $type === Entities::APPOINTMENT) {
            $timeBefore = $appointment->getService()->getTimeBefore() ?
                $appointment->getService()->getTimeBefore()->getValue() : 0;

            $timeAfter = $appointment->getService()->getTimeAfter() ?
                $appointment->getService()->getTimeAfter()->getValue() : 0;

            $start->modify('-' . $timeBefore . ' second');
            $end->modify('+' . $timeAfter . ' second');
        }

        $eventData = [
            'start'                   => [
                'dateTime' => DateTimeService::getCustomDateTimeRFC3339($start->format('Y-m-d H:i:s')),
                'timeZone' => $start->getTimezone()->getName()
            ],
            'end'                     => [
                'dateTime' => DateTimeService::getCustomDateTimeRFC3339($end->format('Y-m-d H:i:s')),
                'timeZone' => $end->getTimezone()->getName()
            ],
            'guestsCanSeeOtherGuests' => $this->googleCalendarSettings['showAttendees'],
            'attendees'               => $attendees,
            'description'             => $placeholderService->applyPlaceholders(
                $period ? $this->googleCalendarSettings['description']['event'] : $this->googleCalendarSettings['description']['appointment'],
                $placeholderData
            ),
            'extendedProperties'      => [
                'shared' => [
                    'ameliaEvent'         => true,
                    'ameliaAppointmentId' => $appointment->getId()->getValue()
                ]
            ],
            'location'                => $address,
            'locked'                  => true,
            'status'                  => $this->googleCalendarSettings['status'],
            'summary'                 => $placeholderService->applyPlaceholders(
                $period ? $this->googleCalendarSettings['title']['event'] : $this->googleCalendarSettings['title']['appointment'],
                $placeholderData
            )
        ];

        /** @var SettingsService $settingsService */
        $settingsService  = $this->container->get('domain.settings.service');
        $enabledForEntity = $settingsService
            ->getEntitySettings($period ? $appointment->getSettings() : $appointment->getService()->getSettings())
            ->getGoogleMeetSettings()
            ->getEnabled();

        if ($enabledForEntity) {
            $token = new Token();

            $eventData['conferenceData'] = [
                'createRequest' => [
                    'conferenceSolutionKey' => [
                        'type' => 'hangoutsMeet',
                    ],
                    'requestId' => $appointment->getId()->getValue() . '_' . $token->getValue(),
                ]
            ];
        }

        if ($period && $period->getPeriodStart()->getValue()->diff($period->getPeriodEnd()->getValue())->format('%a') !== '0') {
            $eventData['recurrence'] = [
                'RRULE:FREQ=DAILY;UNTIL=' .
                $period->getPeriodEnd()->getValue()->format('Ymd\THis\Z')
            ];
        }

        return new Calendar\Event($eventData);
    }

    /**
     * Get All Attendees that need to be added in Google Calendar Event based on "addAttendees" Settings.
     *
     * @param Appointment|Event $appointment
     *
     * @return array
     *
     * @throws NotFoundException
     * @throws QueryExecutionException
     */
    private function getAttendees($appointment, $period = null, $providersNew = null, $providersRemove = null)
    {
        $attendees = [];

        if ($this->googleCalendarSettings['addAttendees'] === true) {
            /** @var ProviderRepository $providerRepository */
            $providerRepository = $this->container->get('domain.users.providers.repository');

            $provider =
                $period ?
                    $providerRepository->getById($appointment->getOrganizerId()->getValue()) :
                    $providerRepository->getById($appointment->getProviderId()->getValue());

            $organizerId = null;

            if ($provider && $provider->getGoogleCalendar() && $provider->getGoogleCalendar()->getCalendarId()) {
                $attendees[] = [
                    'displayName'    => $provider->getFirstName()->getValue() . ' ' . $provider->getLastName()->getValue(),
                    'email'          => $provider->getGoogleCalendar()->getCalendarId()->getValue(),
                    'responseStatus' => 'accepted',
                    'organizer'      => true
                ];

                $organizerId = $provider->getId()->getValue();
            } elseif ($provider && $provider->getGoogleCalendarId()) {
                $attendees[] = [
                    'displayName'    => $provider->getFirstName()->getValue() . ' ' . $provider->getLastName()->getValue(),
                    'email'          => $provider->getGoogleCalendarId()->getValue(),
                    'responseStatus' => 'accepted',
                    'organizer'      => true
                ];

                $organizerId = $provider->getId()->getValue();
            }

            if ($period) {
                $providers = $appointment->getProviders()->getItems();

                if ($providersNew) {
                    $providers = array_merge($providers, $providersNew);
                }
                if ($providersRemove) {
                    $providersRemoveIds = array_map(
                        function ($value) {
                            return $value->getId()->getValue();
                        },
                        $providersRemove
                    );
                }

                /** @var Provider $provider */
                foreach ($providers as $provider) {
                    if (
                        $provider->getId()->getValue() !== $organizerId &&
                        (empty($providersRemoveIds) || !in_array($provider->getId()->getValue(), $providersRemoveIds))
                    ) {
                        $attendees[] = [
                            'displayName'    => $provider->getFirstName()->getValue() . ' ' . $provider->getLastName()->getValue(),
                            'email'          =>
                                $provider->getGoogleCalendar()
                                    ? $provider->getGoogleCalendar()->getCalendarId()->getValue()
                                    : (
                                        $provider->getGoogleCalendarId()
                                            ? $provider->getGoogleCalendarId()->getValue()
                                            : $provider->getEmail()->getValue()
                                ),
                            'responseStatus' => 'accepted'
                        ];
                    }
                }
            }

            /** @var CustomerRepository $customerRepository */
            $customerRepository = $this->container->get('domain.users.customers.repository');

            $bookings = $appointment->getBookings()->getItems();

            /** @var CustomerBooking $booking */
            foreach ($bookings as $booking) {
                $bookingStatus = $booking->getStatus()->getValue();

                if (
                    $bookingStatus === 'approved' ||
                    ($bookingStatus === 'pending' && $this->googleCalendarSettings['insertPendingAppointments'] === true)
                ) {
                    $customer = $customerRepository->getById($booking->getCustomerId()->getValue());

                    if ($customer->getEmail()->getValue()) {
                        $attendees[] = [
                            'displayName'    =>
                                $customer->getFirstName()->getValue() . ' ' . $customer->getLastName()->getValue(),
                            'email'          => $customer->getEmail()->getValue(),
                            'responseStatus' => 'needsAction'
                        ];
                    }
                }
            }
        }

        return $attendees;
    }

    /**
     * Authorize Provider and create Google Calendar service
     *
     * @param Provider $provider
     *
     * @return bool
     *
     * @throws InvalidArgumentException
     * @throws QueryExecutionException
     * @throws ContainerException
     */
    private function authorizeProvider($provider)
    {
        if ($this->googleCalendarSettings['accessToken']) {
            $googleCalendarMiddlewareService = $this->container->get('infrastructure.google.calendar.middleware.service');

            $providerGoogleCalendar = $provider->getGoogleCalendar() ? $provider->getGoogleCalendar()->toArray() : null;
            $client = $googleCalendarMiddlewareService->getClient($providerGoogleCalendar);

            if ($client === null) {
                $this->client = null;
                $this->service = null;
                return false;
            }

            $this->client = $client;
            $this->service = new Calendar($this->client);

            return true;
        }

        if ($provider->getGoogleCalendar()) {
            $token = $provider->getGoogleCalendar()->getToken()
                ? $provider->getGoogleCalendar()->getToken()->getValue()
                : null;

            if (empty($token)) {
                error_log('GoogleCalendar: Provider has no valid token for legacy auth');
                $this->client = null;
                $this->service = null;
                return false;
            }

            $this->client = new Client();
            $this->client->setClientId($this->googleCalendarSettings['clientID']);
            $this->client->setClientSecret($this->googleCalendarSettings['clientSecret']);

            $this->client->setAccessToken($token);

            try {
                if ($this->client->isAccessTokenExpired()) {
                    $this->refreshToken($provider);
                }
            } catch (Exception $e) {
                error_log('GoogleCalendar: Failed to refresh provider token - ' . $e->getMessage());
                $this->client = null;
                $this->service = null;
                return false;
            }

            $this->service = new Calendar($this->client);

            return true;
        }

        $this->client = null;
        $this->service = null;
        return false;
    }

    /**
     * Refresh Provider's Token if it is expired and update it in database.
     *
     * @param Provider $provider
     *
     * @throws InvalidArgumentException
     * @throws QueryExecutionException
     * @throws ContainerException
     */
    private function refreshToken($provider)
    {
        /** @var ProviderApplicationService $providerApplicationService */
        $providerApplicationService = $this->container->get('application.user.provider.service');

        $this->client->refreshToken($this->client->getRefreshToken());

        $accessToken = $this->client->getAccessToken();
        if (is_array($accessToken)) {
            $accessToken = json_encode($accessToken);
        }

        $provider->setGoogleCalendar(
            GoogleCalendarFactory::create(
                [
                    'id'         => $provider->getGoogleCalendar()->getId()->getValue(),
                    'token'      => $accessToken,
                    'calendarId' => $provider->getGoogleCalendar()->getCalendarId()->getValue()
                ]
            )
        );

        $providerApplicationService->updateProviderGoogleCalendar($provider);
    }

    /**
     * @param string $eventDateTimeString
     *
     * @return string
     *
     * @throws Exception
     */
    private function getEventDateTimeStringFromEventDateTime($eventDateTimeString)
    {
        $googleEventDateTimeString = \DateTime::createFromFormat("Y-m-d\TH:i:sP", $eventDateTimeString);

        return DateTimeService::getCustomDateTimeFromUtc(
            $googleEventDateTimeString->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        );
    }

    /**
     * @param string $eventDateString
     *
     * @return string
     *
     * @throws Exception
     */
    private function getEventDateTimeStringFromEventDate($eventDateString)
    {
        $eventDateTimeInUtc = (new \DateTime($eventDateString . ' 00:00:00', new \DateTimeZone($this->timeZone)))
            ->setTimezone(new \DateTimeZone('UTC'));

        return DateTimeService::getCustomDateTimeFromUtc($eventDateTimeInUtc->format('Y-m-d H:i:s'));
    }
}
