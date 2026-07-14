<?php

/**
 * Add Accommodations to Bookings
 */
// Define accommodation data
function eap_get_accommodations() {
    return [
        [
            'id' => 1,
            'name' => 'Radisson Blu Hotel Leipzig',
            'image' => 'https://eapaediatrics.eu/wp-content/uploads/2025/04/16256-116544-f64893257_3xl.webp',
            'google_maps_url' => 'https://maps.google.com/?q=Augustusplatz+5-6,+Leipzig,+04109,+Germany',
            'capacity' => 25,
            'current_occupancy' => 0
        ],
        // Add more accommodations as needed
    ];
}

// Add accommodation selection to booking form
add_action('amelia_before_booking_form', 'eap_add_accommodation_selection');
function eap_add_accommodation_selection() {
    $accommodations = eap_get_accommodations();
    $occupancy_data = get_option('eap_accommodation_occupancy', []);

    // Update current occupancy from stored data
    foreach ($accommodations as &$accommodation) {
        $accommodation['current_occupancy'] = isset($occupancy_data[$accommodation['id']]) ? $occupancy_data[$accommodation['id']] : 0;
    }
    unset($accommodation);

    ?>
    <style>
        .eap-accommodation-cards { display: flex; flex-wrap: wrap; gap: 20px; }
        .eap-accommodation-card { border: 1px solid #ccc; padding: 10px; width: 200px; text-align: center; cursor: pointer; }
        .eap-accommodation-card img { max-width: 100%; height: auto; }
    </style>
    <div id="eap-accommodation-step" style="display: none;">
        <h3>Select Your Accommodation</h3>
        <div class="eap-accommodation-cards">
            <?php foreach ($accommodations as $acc) : ?>
                <div class="eap-accommodation-card" 
                     data-id="<?php echo esc_attr($acc['id']); ?>" 
                     data-available="<?php echo esc_attr($acc['capacity'] - $acc['current_occupancy']); ?>">
                    <img src="<?php echo esc_url($acc['image']); ?>" alt="<?php echo esc_attr($acc['name']); ?>">
                    <h4><?php echo esc_html($acc['name']); ?></h4>
                    <a href="<?php echo esc_url($acc['google_maps_url']); ?>" target="_blank">View on Google Maps</a>
                    <p>Available: <?php echo esc_html($acc['capacity'] - $acc['current_occupancy']); ?></p>
                </div>
            <?php endforeach; ?>
            <div class="eap-accommodation-card" data-id="self">
                <h4>I will make my own accommodation arrangements</h4>
            </div>
        </div>
        <button id="eap-accommodation-next" disabled>Next</button>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const step = document.getElementById('eap-accommodation-step');
            const cards = document.querySelectorAll('.eap-accommodation-card');
            const nextBtn = document.getElementById('eap-accommodation-next');
            let selectedAccommodation = null;

            // Fallback: Show step if service select isn’t found
            const serviceSelect = document.querySelector('.amelia-service-select');
            if (serviceSelect) {
                serviceSelect.addEventListener('change', function() {
                    step.style.display = 'block';
                });
            } else {
                step.style.display = 'block'; // Show immediately as fallback
                console.warn('Service select element not found; showing accommodation step by default.');
            }

            // Card selection logic
            cards.forEach(card => {
                card.addEventListener('click', function() {
                    cards.forEach(c => c.style.border = '1px solid #ccc');
                    this.style.border = '2px solid #0073aa';
                    selectedAccommodation = this.dataset.id;
                    nextBtn.disabled = false;

                    if (this.dataset.id !== 'self' && parseInt(this.dataset.available) <= 0) {
                        alert('This accommodation is fully booked.');
                        nextBtn.disabled = true;
                    }
                });
            });

            // Proceed to next step
            nextBtn.addEventListener('click', function() {
                if (selectedAccommodation) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'eap_accommodation_id';
                    input.value = selectedAccommodation;
                    const form = document.querySelector('#amelia-booking-form');
                    if (form) {
                        form.appendChild(input);
                        step.style.display = 'none';
                        form.style.display = 'block';
                    } else {
                        console.error('Amelia booking form not found. Please check the form ID.');
                    }
                }
            });
        });
    </script>
    <?php
}

// Update occupancy on booking completion
add_action('amelia_booking_completed', 'eap_update_occupancy_on_booking', 10, 1);
function eap_update_occupancy_on_booking($booking) {
    if (isset($_POST['eap_accommodation_id']) && $_POST['eap_accommodation_id'] !== 'self') {
        $acc_id = intval($_POST['eap_accommodation_id']);
        $occupancy_data = get_option('eap_accommodation_occupancy', []);

        // Increment occupancy
        $occupancy_data[$acc_id] = isset($occupancy_data[$acc_id]) ? $occupancy_data[$acc_id] + 1 : 1;
        update_option('eap_accommodation_occupancy', $occupancy_data);

        // Store accommodation ID with booking
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'amelia_customer_bookings',
            ['customFields' => json_encode(['eap_accommodation_id' => $acc_id])],
            ['id' => $booking['id']]
        );
    }
}

// Update occupancy on cancellation
add_action('amelia_booking_canceled', 'eap_update_occupancy_on_cancellation', 10, 1);
function eap_update_occupancy_on_cancellation($booking) {
    global $wpdb;
    $booking_id = $booking['id'];

    $custom_fields = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT customFields FROM {$wpdb->prefix}amelia_customer_bookings WHERE id = %d",
            $booking_id
        )
    );

    $custom_fields = json_decode($custom_fields, true);
    if (isset($custom_fields['eap_accommodation_id'])) {
        $acc_id = intval($custom_fields['eap_accommodation_id']);
        $occupancy_data = get_option('eap_accommodation_occupancy', []);

        if (isset($occupancy_data[$acc_id]) && $occupancy_data[$acc_id] > 0) {
            $occupancy_data[$acc_id]--;
            update_option('eap_accommodation_occupancy', $occupancy_data);
        }
    }
}

// Add accommodation info to email
add_filter('amelia_get_email_data', 'eap_add_accommodation_to_email', 10, 2);
function eap_add_accommodation_to_email($data, $booking) {
    $custom_fields = json_decode($booking['customFields'], true);
    $acc_id = isset($custom_fields['eap_accommodation_id']) ? $custom_fields['eap_accommodation_id'] : 'self';

    if ($acc_id === 'self') {
        $data['accommodation_info'] = 'You have chosen to make your own accommodation arrangements.';
    } else {
        $accommodations = eap_get_accommodations();
        foreach ($accommodations as $acc) {
            if ($acc['id'] == $acc_id) {
                $data['accommodation_info'] = "Accommodation: {$acc['name']}<br><a href='{$acc['google_maps_url']}'>View on Google Maps</a>";
                break;
            }
        }
    }

    return $data;
}

/**
 * Amelia 3
 */
add_action('wp_ajax_insert_temp_booking', 'insert_temp_booking');
add_action('wp_ajax_nopriv_insert_temp_booking', 'insert_temp_booking');
function insert_temp_booking() {
    check_ajax_referer('amelia_access_token_nonce', 'nonce');
    $booking = $_POST['booking'];
    global $wpdb;
    $table_name = 'llw_event_bookings';

    $sql = "INSERT INTO $table_name (
                event_id, accommodation_id, check_in, check_out, 
                first_name, last_name, email, ticket_type, paid, 
                date_added, date_updated
            ) VALUES (%d, %d, %s, %s, %s, %s, %s, %s, %d, %s, %s)
            ON DUPLICATE KEY UPDATE
                accommodation_id = VALUES(accommodation_id),
                check_in = VALUES(check_in),
                check_out = VALUES(check_out),
                first_name = VALUES(first_name),
                last_name = VALUES(last_name),
                ticket_type = VALUES(ticket_type),
                date_updated = VALUES(date_updated)";

    $result = $wpdb->query($wpdb->prepare($sql,
        $booking['event_id'],
        $booking['accommodation_id'],
        $booking['check_in'],
        $booking['check_out'],
        $booking['first_name'],
        $booking['last_name'],
        $booking['email'],
        $booking['ticket_type'],
        $booking['paid'],
        current_time('mysql'),
        current_time('mysql')
    ));

    if ($result === false) {
        wp_send_json_error('Database operation failed: ' . $wpdb->last_error);
    } else {
        wp_send_json_success();
    }
}


function get_accommodation_availability($accommodation_id) {
    global $wpdb;
    $table_name = 'llw_event_accommodations';
    $booking_table = $wpdb->prefix . 'amelia_customer_bookings'; // Amelia bookings table

    // Get original capacity
    $original_capacity = $wpdb->get_var($wpdb->prepare(
        "SELECT original_capacity FROM $table_name WHERE id = %d",
        $accommodation_id
    ));

    // Count approved bookings for this event and accommodation via customFields
    $booked = $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM `tsJCGvj_amelia_customer_bookings` WHERE status = "approved" 
  AND customFields LIKE '."'".'%"3":{"label":"Accommodation","type":"text","value":"' . intval($accommodation_id) . '"}%'."'"));
    $result = $wpdb->update($table_name, [
                'availability' => intval($original_capacity) - intval($booked),
            ], ['id' => $accommodation_id]);
    return intval($original_capacity) - intval($booked);
}
function accommodation_selection_shortcode() {
    global $wpdb;
    $table_name = 'llw_event_accommodations';
    $accommodations = $wpdb->get_results("SELECT * FROM $table_name");
    $accommodation_data = [];
    foreach ($accommodations as $acc) {
        $event_ids = explode(',', $acc->event_ids);
        $availability = get_accommodation_availability($acc->id);
        $accommodation_data[] = [
            'id' => $acc->id,
            'name' => $acc->name,
            'image' => wp_get_attachment_url($acc->image_id) ?: '',
            'availability' => $availability,
            'event_id' => $event_ids, 
        ];
    }
    ob_start();
    ?>

    <!-- Hidden accommodation selection container -->
    <style>
		#am-cf-3 {display:none !important;}
		*:has(>#custom-accommodation-selection) { width: 100% !important; max-width: 100% !important;}
        .accommodation-card img { border-radius: 20px 20px 0 0 !important; }
        .am-pei__info { display: none; }
        .am-eli__timetable-main__time { display: none; }
        span.am-eli__timetable-main__date.am-eli__main-text { display: none; }
        .accommodation-card { border: 1px solid #ccc; padding: 10px !important; margin: 5px 0; cursor: pointer;
            border-radius: 20px; box-shadow: -4px 4px 12px -2px; text-align: center; align-content: center;
        }
        .accommodation-card:hover { background-color: #007bff55; }
        .accommodation-card.fully-booked { opacity: 0.5; pointer-events: none;  background-color: #f0f0f0; }
        .accommodation-card.selected { border-color: #007bff; background-color: #003b7f !important; color: white !important; }
        #custom-accommodation-selection { width: 100%; }
        #accommodation-grid { display:grid;grid-template-columns:50% 50%;width:800px;gap:10px;max-width:100%;padding:20px 0; }
        .access-ticket-button { display:block;margin: auto !important;padding:10px 20px;background:#007bff;color:white;text-align:center;
            text-decoration:none;border-radius:5px; 
        }
        .amelia-button.access-ticket-button { color:white;padding:7px 14px !important; font-size:14px !important;
            background-color:#5487d5;font-weight: 500 !important;font-family: Amelia Roboto, sans-serif !important;}
        .amelia-button.access-ticket-button:hover { color: white;background-color: #215f9a;}
        #custom-accommodation-selection h4 { margin-bottom: 20px !important; }
/*         .am-elf__footer.am-congrats button:not(.access-ticket-button) { display: none !important;} */
        .flatpickr-calendar.rangeMode { z-index: 9999999999 !important;}
        #am-cf-9, #am-cf-8 { display:none !important;}
    </style>
    <div id="custom-accommodation-selection" style="display:none;border-bottom:1px solid #000;border-top:1px solid #000; padding:20px">
        <h4 style="font-weight: 600">Select your accommodation for the event <b>(included in the price):</b></h4>
        <div id="accommodation-grid">
            <!-- Accommodation units generated here -->
        </div>
        <div id="date-range-container" style="display:none; margin-top:20px;">
            <label for="date-range" style="display:inline; padding-right:5px; width:200px;"><span style="color:red;">*</span> <b style="font-weight:700 !important;">Check-in and Check-out Dates:</b></label>
            <input type="text" id="date-range" placeholder="Select dates" style="width:300px;">
        </div>
    </div>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
	var onInfo = false;
	var onFinish = false;
    var ThisTriggered = "false";
	var emailField = null;
    //document.onclick = function() { console.log(ThisTriggered); }	
    function updateBookingStatus() {
		// Ensure bookingData is available (set in beforeBooking hook)
		const customerEmail = emailField ?? document.querySelector('.am-fs__congrats-info-customer-email .am-congrats__info-item__value').value;
		const eventId = 1; //make dynamic
		const customerId = 0;

		if (!customerEmail || !eventId) {
			console.error('Missing required booking data');
			return;
		}

		jQuery.ajax({
			url: '/wp-admin/admin-ajax.php',
			type: 'POST',
			data: {
				action: 'update_booking_status',
				nonce: "<?= wp_create_nonce('amelia_access_token_nonce') ?>",
				booking_data: {
					customer_id: 0,
					event_id: eventId, //ZZZ: Make dynamic:: Also event Id should be used
					email: customerEmail
				}
			},
			success: function(response) {
				if (response.success) {
					console.log('Booking status updated successfully');
				} else {
					console.error('Failed to update booking status:', response);
				}
			},
			error: function(xhr, status, error) {
				console.error('AJAX error:', status, error);
			}
		});
	}
    function pollForCongrats() {
        let attempts = 0;
        const maxAttempts = 30; // Stop after 30 seconds
        const interval = 1000;  // Check every 1 second

        const pollInterval = setInterval(() => {
            attempts++;
            const congratsElement = document.querySelector('.am-congrats');
			var onInfo = false;
			var onFinish = false;
            if (congratsElement && !onFinish) {
                console.log('.am-congrats found');
                clearInterval(pollInterval); // Stop polling
                onFinish = true;
	            updateBookingStatus();       // Trigger the AJAX update
                //appendAccessTicketButton();  // Append the button
            } else {
                onFinish = false;
				console.log('Congrats hidden');
                //clearInterval(pollInterval); // Stop polling if timeout occurs
            }
        }, interval);
    }

    // Function to append the "Access Ticket" button
    function appendAccessTicketButton() {
        const customerEmail = bookingData?.booking?.customer?.email;
        if (!customerEmail) {
            console.error('Customer email not found in bookingData');
            return;
        }

        jQuery.ajax({
            url: '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: {
                action: 'get_access_token',
                customer_email: customerEmail, // Send email instead of ID
                nonce: "<?=wp_create_nonce('amelia_access_token_nonce')?>"
            },
            success: function(response) {
                if (response.success && response.data?.token) {
                    const accessUrl = 'https://eapaediatrics.eu/event-attendee-profile/?access_token=' + response.data.token;
                    const button = jQuery('<a href="' + accessUrl + '" class="amelia-button access-ticket-button">Access Ticket</a>');
                    jQuery('.am-congrats').append(button);
                    console.log('Access Ticket button appended');
                } else {
                    console.error('Failed to retrieve access token:', response);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', status, error);
            }
        });
    }
    let bookingData = null;
    var selectedAccommodationName = "";
    let selectedCheckIn = null;
	let selectedCheckOut = null;
    // Define event dates (replace with dynamic values)
    const eventStartDate = '2025-09-25'; // Example: fetch from event data
    const eventEndDate = '2025-09-27';   // Example: fetch from event data
    // Flatpickr instance
    let datePicker;
    var checkin = null;
    var checkout = null;
           
    jQuery(document).ready(function($) {
		document.addEventListener('click', function(event) {
			if (event.target.matches('button.am-heading-prev') || event.target.matches('.el-dialog__headerbtn[aria-label="close"]')) {
				setTimeout(function() {
					location.reload();
				}, 100); // Small delay to allow default action
			}
		});
        function initDatePicker() {
            datePicker = flatpickr('#date-range', {
                mode: 'range',           // Enables range selection
                minDate: eventStartDate, // Restrict to event start
                maxDate: eventEndDate,   // Restrict to event end
                dateFormat: 'Y-m-d',     // Format for consistency
                onChange: function(selectedDates) {
                    if (selectedDates.length === 2) {						
                        const checkinF = document.querySelector('[name="cf8"]');
                        const checkoutF = document.querySelector('[name="cf9"]');
                        
                        const checkinDate = flatpickr.formatDate(selectedDates[0], 'Y-m-d');
                        const checkoutDate = flatpickr.formatDate(selectedDates[1], 'Y-m-d');

		                selectedCheckIn = flatpickr.formatDate(selectedDates[0], 'Y-m-d');
		                selectedCheckOut = flatpickr.formatDate(selectedDates[1], 'Y-m-d');
                        
                        checkinF.value = checkinDate;
                        checkinF.dispatchEvent(new Event('input', { bubbles: true }));
                        checkinF.dispatchEvent(new Event('change', { bubbles: true }));
                        checkoutF.value = checkoutDate;
                        checkoutF.dispatchEvent(new Event('input', { bubbles: true }));
                        checkoutF.dispatchEvent(new Event('change', { bubbles: true }));
						
						updateAmeliaField();
                    }
                }
            });
        }		

        let selectedAccommodation = null;
        let isInitialized = false;
        const eventId = "1";//getUrlParameter('ameliaEventId'); //ZZZ Make dynamic when start adding new events
        const accommodationData = <?php echo json_encode($accommodation_data); ?>;
        const relevantAccommodations = accommodationData.filter(acc => acc.event_id.includes(eventId));
        const grid = $('#accommodation-grid');
        console.log('Relevant accommodations:', relevantAccommodations);
        // Initialize accommodation cards
        getEventTicketAvailability(eventId).then(ticketAvailability => {
            console.log('Generating cards for availability:', ticketAvailability);
            relevantAccommodations.forEach(acc => {
                const card = `
                    <div class="accommodation-card" data-id="${acc.id}" data-availability="${acc.availability}" data-aname="${acc.name}">
                        ${acc.image ? `<img src="${acc.image}" alt="${acc.name}">` : ''}
                        <h4 style="margin-bottom:0 !important">${acc.name}</h4>
                        <p><b>Available:</b> <span class="availability">${acc.availability}</span></p>
                    </div>`;
                grid.append(card);
            });
            grid.append('<div class="accommodation-card self-arrange" data-id="self"><h4>I will make my own accommodation arrangements</h4></div>');

            $('.accommodation-card').each(function() {
                const availability = parseInt($(this).data('availability'));
                if (availability <= 0 && $(this).data('id') !== 'self') {
                    $(this).addClass('fully-booked');
                }
            });
        });

        // Function to initialize the custom UI
        function initAccommodationUI() {
			if (isInitialized) return true;
			const targetField = $('[name="cf3"]');
			const targetField2 = $('[name="cf4"]');
			const targetField2_cont = $('#am-cf-4');
			console.log('Checking for target field, found:', targetField.length);
			if (targetField.length) {
				targetField.hide();
				targetField2_cont.hide();
				$('#custom-accommodation-selection').show();

				// Find the parent form item for "Accommodation"
				const formItem = targetField.closest('.el-form-item');

				// Wrap the custom section in a new div with form item classes
				const customSection = $('#custom-accommodation-selection');
				customSection.wrap('<div class="el-form-item el-form-item--label-top am-ff__item am-elfci__item"></div>');

				// Insert the wrapped custom section after the "Accommodation" form item
				formItem.after(customSection.parent());

				isInitialized = true;
				return true;
			} else return false;
		}
        function pollForField() {
            if (isInitialized) return;
            let attempts = 0;
            const maxAttempts = 10;
            const pollInterval = setInterval(() => {
                if (initAccommodationUI() || attempts >= maxAttempts) {
                    clearInterval(pollInterval);
                    console.log('Polling stopped:', isInitialized ? 'UI initialized' : 'Max attempts reached');
                    window.ameliaActions.InitInfoStep = function(success, error, data) {
                        console.log('Amelia InitInfoStep triggered');
                        pollForField(); // Start polling immediately after InitInfoStep
                    };
                }
                attempts++;
            }, 1000);
        }
        // Handle card selection
        $('#accommodation-grid').on('click', '.accommodation-card', function() {
            if (!$(this).hasClass('fully-booked')) {
                $('.accommodation-card').removeClass('selected');
                $(this).addClass('selected');
                selectedAccommodation = $(this).data('id');
                selectedAccommodationName = $(this).data('aname');
                updateAmeliaField();
                
                if (selectedAccommodation !== 'self') {
                    $('#date-range-container').show();
                    if (!datePicker) initDatePicker(); // Initialize only once
                } else {
                    $('#date-range-container').hide();
                    if (datePicker) datePicker.clear(); // Clear dates if switching to 'self'
                    $('#check_in').val('');
                    $('#check_out').val('');
                }
            }
        });
		function updateAmeliaField() {
			if (selectedAccommodation) {
				setTimeout(() => {
					const targetField = $('[name="cf3"]');
					const targetField2 = $('[name="cf4"]');
					if (targetField.length && targetField2.length) {
						// Update cf3 with accommodation ID
						targetField.val(selectedAccommodation);
						targetField[0].dispatchEvent(new Event('input', { bubbles: true }));
						targetField[0].dispatchEvent(new Event('change', { bubbles: true }));

						// Set cf4 message based on selection
						let message;
						if (selectedAccommodation === 'self') {
							message = "You have selected to organise your own accommodation for this conference. If you change your mind, please contact us at secretariat@eapaediatrics.eu to find out if we can still secure accommodation for you.";
						} else {
							const amendmentsLink = "https://www.guestreservations.com/radisson-blu-hotel-leipzig/booking";
							let dateInfo = '';
							if (selectedCheckIn && selectedCheckOut) {
								dateInfo = ` You will be checking in on ${selectedCheckIn} and checking out on ${selectedCheckOut}.`;
							}
							message = `You have selected to stay at ${selectedAccommodationName} for this conference.${dateInfo} This booking covers your accommodation in a standard single room. You have the option to upgrade your room or extend your stay at your own expense. Any upgrades/prolonged stay must be arranged directly with the hotel. Additional details, prices and availability to be consulted here: ${amendmentsLink}.`;
						}
						targetField2.val(message);
						targetField2[0].dispatchEvent(new Event('input', { bubbles: true }));
						targetField2[0].dispatchEvent(new Event('change', { bubbles: true }));
					} else console.error('Target fields not found');
				}, 100);
			}
		}
        // ZZZ: Placeholder for ticket availability
        function getEventTicketAvailability(eventId) {
            console.log('Fetching ticket availability for event:', eventId);
            return Promise.resolve(25); // Static for now
        }
        // Amelia form initialization hook with polling
        window.ameliaActions = window.ameliaActions || {};
        window.ameliaActions.InitInfoStep = function(success, error, data) {
            console.log('Amelia InitInfoStep triggered');
            pollForField(); // Start polling immediately after InitInfoStep
        };
        window.ameliaActions.ViewContent = function(success,error,data) {
            console.log('Form Loaded.'); // happens on event listing load
        }
        window.ameliaActions.customValidation = function(success,error,data) {
			console.log('Custom Validation triggered:', data);

			const booking = data.booking;
			const event = data.event;
			const customer = booking.customer;
			const customFields = booking.customFields;
			let accommodation_id = 0;
			let check_in = null;
			let check_out = null;
			customFields.forEach(field => {
				if (field.id === 3) accommodation_id = field.value;
				if (field.id === 8) check_in = field.value;
				if (field.id === 9) check_out = field.value;
			});
			emailField = customer.email;
			const bookingData = {
				event_id: event.id,
				accommodation_id: accommodation_id,
				check_in: check_in,
				check_out: check_out,
				first_name: customer.firstName,
				last_name: customer.lastName,
				email: customer.email,
				ticket_type: 'Standard Participant',
				paid: 0,
			};
			$.ajax({
				url: '/wp-admin/admin-ajax.php',
				type: 'POST',
				data: {
					action: 'insert_temp_booking',
	                nonce: "<?=wp_create_nonce('amelia_access_token_nonce')?>",
					booking: bookingData,
				},
				success: function(response) {
					if (response.success) {
						console.log('Booking inserted/updated during validation');
						if (typeof success === 'function') {
							success();
						}
					} else {
						console.error('Failed to insert booking:', response);
						error('Booking processing failed');
					}
				},
				error: function(xhr, status, error) {
					console.error('AJAX error:', status, error);
					error('Server error');
				}
			});
        }
        window.ameliaActions.InitiateCheckout = function(success,error,data) {
            console.log('Checkout Initiated.'); //on checkout load
        }
        window.ameliaActions.beforeBooking = function(success,error,data) {
            console.log('payment button:',data);
            bookingData = data; // Save data for later use
            pollForCongrats();
            success();
        }
        window.ameliaActions.Purchased = function(success,error,data) {
            // This never triggers
            console.log('Successful purchase!', data);
        }	
        console.log('Attempting initial UI initialization');
        initAccommodationUI();
    });

    // Utility to get URL parameters
    function getUrlParameter(name) {
        name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
        var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
        var results = regex.exec(location.search);
        return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
    }
    </script>

    <?php
    return ob_get_clean();
}
add_shortcode('accommodation_selection', 'accommodation_selection_shortcode');

add_action('amelia_booking_completed', 'update_booking_after_completion', 10, 1);
function update_booking_after_completion($booking) {
    global $wpdb;
    $table_name = 'llw_event_bookings';
    $customer_id = $booking['customerId'];
    $event_id = $booking['booking']['eventId'];
    $email = $booking['customer']['email'];

    $wpdb->update(
        $table_name,
        ['user_id' => $customer_id, 'paid' => 1, 'date_updated' => current_time('mysql')],
        ['email' => $email, 'event_id' => $event_id],
        ['%d', '%d', '%s'],
        ['%s', '%d']
    );

    if ($wpdb->last_error) {
        error_log('Update error: ' . $wpdb->last_error);
    }

    // Existing redirect logic
    if ($customer_id) {
        set_transient('allow_password_set_' . $customer_id, true, 3000);
        if (!is_user_logged_in()) {
            wp_set_current_user($customer_id);
            wp_set_auth_cookie($customer_id);
        }
        wp_redirect(home_url('/system_reset_password_llw'));
        exit;
    }
}


add_action('wp_ajax_update_booking_status', 'update_booking_status');
add_action('wp_ajax_nopriv_update_booking_status', 'update_booking_status');

function update_booking_status() {
    check_ajax_referer('amelia_access_token_nonce', 'nonce');
    $booking_data = $_POST['booking_data'];
    $email = sanitize_email($booking_data['email']);
    if (empty($email)) {
        wp_send_json_error('Missing email');
        return;
    }
    $user = get_user_by('email', $email);
    if (!$user) {
        wp_send_json_error('User not found');
        return;
    }
    $user_id = $user->ID;
    global $wpdb;
    $table_name = 'llw_event_bookings';

    $result = $wpdb->update(
        $table_name,
        [
            'user_id' => $user_id,              // Set the user_id
            'paid' => 1                        // Mark as paid
        ],
        [
            'email' => $email                   // Match the row by email
        ],
        ['%d', '%d'],                     // Data types for values
        ['%s']                                  // Data type for condition
    );

    // Check if the update was successful
    if ($result === false) wp_send_json_error('Update failed: ' . $wpdb->last_error);
    else wp_send_json_success();
}

/**
 * Accommodation Units WP Admin Menu
 */
// Generate token on booking completion
function generate_customer_access_token($customer_id) {
    global $wpdb;
    if (!$customer_id) return false;
    $token = bin2hex(random_bytes(32)); // Secure random token
    $expires = time() + (7 * 24 * 60 * 60); // Valid for 7 days
    $wpdb->insert(
        'llw_amelia_access_tokens', [
            'customer_id' => $customer_id,
            'token' => $token,
            'expires' => $expires,
        ],
        ['%d', '%s', '%d']
    );
    return $token;
}
function get_access_token_callback() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'amelia_access_token_nonce')) {
		wp_send_json_error(array('message' => 'Security check failed'));
        return;
	}
	global $wpdb;

    $customer_email = sanitize_email($_POST['customer_email']);
    if (!$customer_email) {
        wp_send_json_error(['message' => 'Invalid or missing email']);
    }

    // Look up customer by email in Amelia users table
    $customer = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}amelia_users WHERE email = %s AND type = 'customer'",
        $customer_email
    ));

    if ($customer) {
        $customer_id = $customer->id;
    } else {
        // Create a new customer if not found
        $first_name = sanitize_text_field($_POST['first_name'] ?? 'Unknown');
        $last_name = sanitize_text_field($_POST['last_name'] ?? 'Unknown');
        $wpdb->insert(
            $wpdb->prefix . 'amelia_users',
            [
                'email' => $customer_email,
                'type' => 'customer',
                'firstName' => $first_name,
                'lastName' => $last_name,
            ],
            ['%s', '%s', '%s', '%s']
        );
        $customer_id = $wpdb->insert_id;
    }

    // Generate token using existing function
    $token = generate_customer_access_token($customer_id);
    if ($token) {
        wp_send_json_success(['token' => $token]);
    } else {
        wp_send_json_error(['message' => 'Failed to generate token']);
    }
}
add_action('wp_ajax_get_access_token', 'get_access_token_callback');
add_action('wp_ajax_nopriv_get_access_token', 'get_access_token_callback');

// Customize email notification
function customize_amelia_notification($notification, $booking) {
    if ($notification['type'] === 'booking_confirmation') {
        global $wpdb;
        $customer_id = $booking['customerId'] ?? null;
        if ($customer_id) {
            $token = $wpdb->get_var($wpdb->prepare(
                "SELECT token FROM llw_amelia_access_tokens WHERE customer_id = %d AND expires > %d",
                $customer_id,
                time()
            ));
            if ($token) {
                $access_url = 'https://eapaediatrics.eu/event-attendee-profile/?access_token=' . $token;
                $notification['body'] .= '<p><a href="' . esc_url($access_url) . '">Access Your Ticket</a></p>';
            }
        }
    }
    return $notification;
}
add_filter('amelia_before_notification_sent', 'customize_amelia_notification', 10, 2);

// Auto-login with token
function auto_login_customer_panel() {
    if (!isset($_GET['access_token'])) return;
    global $wpdb;
    $token = sanitize_text_field($_GET['access_token']);
    $token_data = $wpdb->get_row($wpdb->prepare(
        "SELECT customer_id, expires FROM llw_amelia_access_tokens WHERE token = %s",
        $token
    ));
    if (!$token_data || $token_data->expires < time()) {
        wp_die('Invalid or expired token.');
    }

    $customer = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}amelia_users WHERE id = %d AND type = 'customer'",
        $token_data->customer_id
    ));
    if (!$customer) wp_die('Customer not found.');

    $wp_user_id = $customer->externalId;
    $is_new_user = false;

    if (!$wp_user_id) {
        // New user: Create WordPress user
        $email = $customer->email;
        $username = sanitize_user(strstr($email, '@', true));
        $password = wp_generate_password(); // Temporary password
        $wp_user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($wp_user_id)) wp_die('Failed to create user.');

        $wp_user = new WP_User($wp_user_id);
        $wp_user->set_role('amelia_customer');
        $wpdb->update(
            $wpdb->prefix . 'amelia_users',
            ['externalId' => $wp_user_id],
            ['id' => $token_data->customer_id]
        );

        // Mark as new user needing password setup
        update_user_meta($wp_user_id, 'password_set', 0);
        $is_new_user = true;
    } else {
        // Existing user: Check if password is set
        $password_set = get_user_meta($wp_user_id, 'password_set', true);
        if ($password_set !== '1') {
            $is_new_user = true; // Treat as needing password if not set
        }
    }

    wp_set_current_user($wp_user_id);
    wp_set_auth_cookie($wp_user_id, true);

    // Clean up the used token
    $wpdb->delete(
        'llw_amelia_access_tokens',
        ['token' => $token],
        ['%s']
    );

    // Redirect with set_password parameter if needed
    $redirect_url = 'https://eapaediatrics.eu/event-attendee-profile/';
    if ($is_new_user) {
        $redirect_url = add_query_arg('set_password', '1', $redirect_url);
    }
    wp_redirect($redirect_url);
    exit;
}
add_action('template_redirect', 'auto_login_customer_panel');



//*****************************************************************
//	TESTED AND WORKING
//*****************************************************************
// Add custom admin menu under "Legacy Live Web Tools"
function retrieve_amelia_events() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'amelia_events'; 
    $events = $wpdb->get_results("SELECT id, name FROM $table_name");
    if ($events) return $events;
    else {
        error_log('No events found in ' . $table_name);
        return array();
    }
}
function enqueue_media_scripts($hook) {
    $screen = get_current_screen();
    if ($screen->id === 'legacy-live-web-tools_page_event-accommodation') {
        wp_enqueue_media();
        // Load Thickbox (used by the media uploader)
        add_thickbox();
    }
}
add_action('admin_enqueue_scripts', 'enqueue_media_scripts');
function add_accommodation_menu() {
    add_menu_page(
        'Legacy Live Web Tools',      // Page title
        'Legacy Live Web Tools',      // Menu title
        'manage_options',             // Capability
        'legacy-live-web-tools',      // Menu slug
        'legacy_live_web_tools_page', // Callback function
        'dashicons-admin-tools',      // Icon
        20                            // Position
    );
    add_submenu_page(
        'legacy-live-web-tools',      // Parent slug
        'Event Accommodation',        // Page title
        'Event Accommodation',        // Menu title
        'manage_options',             // Capability
        'event-accommodation',        // Menu slug
        'event_accommodation_page'    // Callback function
    );
}
add_action('admin_menu', 'add_accommodation_menu');

// ZZZ: Placeholder for the main "Legacy Live Web Tools" page
function legacy_live_web_tools_page() {
    echo '<h1>Legacy Live Web Tools</h1><p>Welcome to Legacy Live Web Tools. Use the submenu to manage Event Accommodations.</p>';
}
function event_accommodation_page() {
    global $wpdb;
    $table_name = 'llw_event_accommodations';
    // Handle form submissions (add, edit, delete)
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $name = sanitize_text_field($_POST['name']);
        $address = sanitize_textarea_field($_POST['address']);
        $google_maps_url = esc_url_raw($_POST['google_maps_url']);
        $image_id = intval($_POST['image_id']);
        $event_ids = isset($_POST['event_ids']) ? implode(',', array_map('intval', $_POST['event_ids'])) : '';
        $availability = intval($_POST['availability']);
        $original_capacity = intval($_POST['original_capacity']);  // New field
        if ($action == 'add') {
            $wpdb->insert($table_name, [
                'name' => $name,
                'address' => $address,
                'google_maps_url' => $google_maps_url,
                'image_id' => $image_id,
                'event_ids' => $event_ids,
                'availability' => $availability,
                'original_capacity' => $original_capacity,
            ]);
            echo '<div class="updated"><p>Accommodation added successfully.</p></div>';
        } elseif ($action == 'edit' && isset($_POST['id'])) {
            $id = intval($_POST['id']);
            $wpdb->update($table_name, [
                'name' => $name,
                'address' => $address,
                'google_maps_url' => $google_maps_url,
                'image_id' => $image_id,
                'event_ids' => $event_ids,
                'availability' => $availability,
                'original_capacity' => $original_capacity,
            ], ['id' => $id]);
            echo '<div class="updated"><p>Accommodation updated successfully.</p></div>';
        } elseif ($action == 'delete' && isset($_POST['id'])) {
            $id = intval($_POST['id']);
            $wpdb->delete($table_name, ['id' => $id]);
            echo '<div class="updated"><p>Accommodation deleted successfully.</p></div>';
        }
    }
    $accommodations = $wpdb->get_results("SELECT * FROM $table_name");
    // Fetch all events from Amelia
    $events = retrieve_amelia_events();
    // Handle edit form population
    $edit_data = null;
    if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
        $edit_id = intval($_GET['id']);
        $edit_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $edit_id));
    }
    ?>
	<style>
		.attachment-thumbnail {
			max-width:100% !important;
		}
	</style>
    <div class="wrap">
        <h1>Event Accommodation</h1>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?php echo $edit_data ? 'edit' : 'add'; ?>">
            <?php if ($edit_data) : ?>
                <input type="hidden" name="id" value="<?php echo $edit_data->id; ?>">
            <?php endif; ?>
            <table class="form-table">
                <tr>
                    <th><label for="name">Name</label></th>
                    <td><input type="text" name="name" id="name" value="<?php echo $edit_data ? esc_attr($edit_data->name) : ''; ?>" required class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="address">Address</label></th>
                    <td><textarea name="address" id="address" rows="5" class="large-text"><?php echo $edit_data ? esc_textarea($edit_data->address) : ''; ?></textarea></td>
                </tr>
                <tr>
                    <th><label for="google_maps_url">Google Maps URL</label></th>
                    <td><input type="url" name="google_maps_url" id="google_maps_url" value="<?php echo $edit_data ? esc_url($edit_data->google_maps_url) : ''; ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="image_id">Image</label></th>
                    <td>
                        <input type="hidden" name="image_id" id="image_id" value="<?php echo $edit_data ? esc_attr($edit_data->image_id) : ''; ?>">
                        <div id="image-preview">
                            <?php if ($edit_data && $edit_data->image_id) echo wp_get_attachment_image($edit_data->image_id, 'thumbnail'); ?>
                        </div>
                        <button type="button" class="button upload-image-button">Upload Image</button>
                    </td>
                </tr>
                <tr>
                    <th><label for="event_ids">Associated Events</label></th>
                    <td>
                        <select name="event_ids[]" id="event_ids" multiple size="5" class="widefat">
                            <?php 
                            $selected_ids = $edit_data ? explode(',', $edit_data->event_ids) : [];
                            foreach ($events as $event) : 
                            ?>
                                <option value="<?php echo esc_attr($event->id); ?>" <?php echo in_array($event->id, $selected_ids) ? 'selected' : ''; ?>>
                                    <?php echo esc_html($event->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Hold Ctrl (Windows) or Cmd (Mac) to select multiple events.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="availability">Availability</label></th>
                    <td><input type="number" name="availability" id="availability" value="<?php echo $edit_data ? esc_attr($edit_data->availability) : '25'; ?>" min="0" class="small-text"></td>
                </tr>
                <tr>
                    <th><label for="original_capacity">Original Capacity</label></th>
                    <td><input type="number" name="original_capacity" id="original_capacity" value="<?php echo $edit_data ? esc_attr($edit_data->original_capacity) : '25'; ?>" min="0" class="small-text"></td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" class="button button-primary"><?php echo $edit_data ? 'Update Accommodation' : 'Add Accommodation'; ?></button>
            </p>
        </form>

        <h2>Existing Accommodations</h2>
		<p><i><b>If a venue is being used for another event, list it again to ensure availability is accurate.</b></i></p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Address</th>
                    <th>Google Maps URL</th>
                    <th>Image</th>
                    <th>Event IDs</th>
                    <th>Availability</th>
                    <th>Original Capacity</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accommodations as $acc) : ?>
                    <tr>
						<?php $availability = get_accommodation_availability($acc->id); ?>
                        <td><?php echo $acc->id; ?></td>
                        <td><?php echo esc_html($acc->name); ?></td>
                        <td><?php echo esc_html($acc->address); ?></td>
                        <td><a href="<?php echo esc_url($acc->google_maps_url); ?>" target="_blank">Link</a></td>
                        <td><?php echo $acc->image_id ? wp_get_attachment_image($acc->image_id, 'thumbnail') : 'No Image'; ?></td>
                        <td><?php echo esc_html($acc->event_ids); ?></td>
                        <td><?=$availability?></td>
                        <td><?php echo esc_html($acc->original_capacity); ?></td>
                        <td>
                            <a href="?page=event-accommodation&action=edit&id=<?php echo $acc->id; ?>" class="button">Edit</a>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $acc->id; ?>">
                                <button type="submit" class="button" onclick="return confirm('Are you sure you want to delete this accommodation?');">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- Media uploader script -->
    <script>
    jQuery(document).ready(function($) {
        var mediaUploader;
        $('.upload-image-button').click(function(e) {
            e.preventDefault();
            if (mediaUploader) {
                mediaUploader.open();
                return;
            }
            mediaUploader = wp.media({
                title: 'Choose Image',
                button: { text: 'Select Image' },
                multiple: false
            });
            mediaUploader.on('select', function() {
                var attachment = mediaUploader.state().get('selection').first().toJSON();
                $('#image_id').val(attachment.id);
                $('#image-preview').html('<img src="' + attachment.url + '" style="max-width:150px;">');
            });
            mediaUploader.open();
        });
    });
    </script>
    <?php
}

/**
 * Events Client Page
 */
function event_attendee_profile_shortcode() {
	if(isset($_COOKIE['ameliaUserEmail'])) {
		$user = get_user_by('email', $_COOKIE['ameliaUserEmail']) ?? null;
		if(empty($user)) return '';
		else $user_id = $user->ID;
	} else return '';

    $output = '';

    // Ticket Downloader Container and Modal
    $output .= '
    <style>
        .ticket-downloader {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            padding: 20px;
            text-align: center;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }
        .ticket-downloader h2 {
            margin-top: 0;
            color: #333;
            font-size: 24px;
        }
        .ticket-downloader select {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 16px;
        }
        .ticket-downloader button {
            background-color: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }
        .ticket-downloader button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        .ticket-downloader button:hover:not(:disabled) {
            background-color: #0056b3;
        }
        .llw_ticket-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999999;
        }
        .llw_ticket-modal-content {
            background: #0000;
            padding: 0;
            border-radius: 10px;
            max-width: 400px;
            width: 100%;
            max-width: 400px; /* Limit the width */
            max-height: 100vh; /* Restrict height to 90% of viewport */
            overflow-y: auto; /* Enable vertical scrolling when needed */
            position: relative;
        }
        .llw_print-button {
            display: block;
            background-color: #0066a3;
            color: white;
            border: none;
            padding: 12px 0;
            width: 100%;
            text-align: center;
            font-weight: 600;
            cursor: pointer;
            border-radius: 0 0 20px 20px !important;
            font-size: 16px;
            transition: background-color 0.3s;
        }
        .llw_print-button:hover {
            background-color: #00558a;
        }
        #llw_ticketBox * {
            box-sizing: border-box;
            font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif !important;
        }
        #llw_ticketBox {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0;
            background-color: #0000;
        }
        .llw_ticket-container {
            width: 100%;
            max-width: 380px;
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            position: relative;
        }
        .llw_ticket-header {
            background-color: #0066a3;
            color: white;
            padding: 16px 16px 40px 16px;
            text-align: center;
        }
        .llw_ticket-header h1 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 5px;
            padding: 0;
            margin: 0;
        }
        .llw_ticket-header h2 {
            font-size: 16px;
            font-weight: 400;
            opacity: 0.9;
            padding: 5px 0 0 0;
            margin: 0;
        }
        #view-ticket {
            border-radius:999px !important;
            color:white !important;
        }
        .llw_logo-container {
            background-color: white;
            padding: 15px;
            text-align: center;
        }
        .llw_logo-container img {
            max-width: 200px;
            height: auto;
        }
        .llw_event-image {
            width: 100%;
            height: 160px;
            background-position: center;
            background-size: cover;
        }
        .llw_ticket-body {
            padding: 20px;
        }
        .llw_close-modal {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #ff4d4d; /* Red background for visibility */
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            z-index: 10000; /* Ensures it stays above other elements */
        }

        .llw_close-modal:hover {
            background-color: #cc0000; /* Darker red on hover */
        }
        .llw_ticket-type {
            background-color: #0066a3;
            color: white;
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 14px;
            margin-bottom: 15px;
        }
        .llw_dates {
            color: #333;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .llw_ticket-info {
            margin-bottom: 20px;
        }
        .llw_info-row {
            display: flex;
            margin-bottom: 10px;
            border-bottom: 1px dashed #eee;
            padding-bottom: 8px;
        }
        .llw_info-label {
            width: 130px;
            color: #666;
            font-size: 14px;
        }
        .llw_info-value {
            flex: 1;
            color: #333;
            font-weight: 500;
            font-size: 14px;
            word-break: break-word;
        }
        .llw_accommodation {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .llw_accommodation-title {
            font-weight: 600;
            font-size: 15px;
            margin-bottom: 8px;
            color: #444;
        }
        .llw_paid-status {
            background-color: #dcf5e7;
            color: #0a8a45;
            padding: 8px 15px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 14px;
            display: inline-block;
            margin-bottom: 15px;
        }
        .llw_ticket-footer {
            padding: 15px 20px;
            background-color: #f8f9fa;
            border-top: 1px solid #eee;
            font-size: 14px;
            color: #666;
            text-align: center;
        }
        .llw_ticket-number {
            position: absolute;
            top: 72px;
            right: 90px;
            background: linear-gradient(33deg, #fff8 33%, #fffc 80%, #fff9 100%);
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            color: #0066a3;
        }
    </style>
    <div class="ticket-downloader">
        <h2>Ticket Downloader</h2>
        <select id="event-select">
            <option value="">Select an event</option>
            <!-- Options populated via JavaScript -->
        </select>
        <button id="view-ticket" disabled>VIEW</button>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
    jQuery(document).ready(function($) {
        // Fetch user\'s booked events via AJAX
        $.ajax({
            url: \'/wp-admin/admin-ajax.php\',
            type: \'POST\',
            data: {
                action: \'get_user_events\',
                nonce: \'' . wp_create_nonce('user_events_nonce') . '\'
            },
            success: function(response) {
                if (response.success) {
                    const events = response.data;
                    const select = $(\'#event-select\');
                    if (events.length > 0) {
                        events.forEach(event => {
                            select.append(`<option value="${event.id}">${event.name}</option>`);
                        });
                    } else {
                        select.append(\'<option value="">Not registered for any events</option>\');
                    }
                } else {
                    console.error(\'Failed to fetch events:\', response);
                }
            },
            error: function() {
                console.error(\'AJAX error while fetching events\');
            }
        });

        // Enable "VIEW" button when an event is selected
        $(\'#event-select\').on(\'change\', function() {
            const selectedEvent = $(this).val();
            $(\'#view-ticket\').prop(\'disabled\', !selectedEvent);
        });

        // Open modal when "VIEW" is clicked
        $(\'#view-ticket\').on(\'click\', function() {
            const eventId = $(\'#event-select\').val();
            if (eventId) {
                openTicketModal(eventId);
            }
        });

        // Function to open and populate the modal
        function openTicketModal(eventId) {
            const modal = $(\'<div class="llw_ticket-modal"></div>\');
            const modalContent = $(\'<div class="llw_ticket-modal-content"><p>Loading ticket data...</p></div>\');
            
            const closeButton = $(\'<button class="llw_close-modal">Close</button>\');

            modal.append(modalContent);
            modal.append(closeButton);
            $(\'body\').append(modal);

            // Fetch ticket data via AJAX
            $.ajax({
                url: \'/wp-admin/admin-ajax.php\',
                type: \'POST\',
                data: {
                    action: \'get_ticket_data\',
                    event_id: eventId,
                    nonce: \'' . wp_create_nonce('ticket_nonce') . '\'
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        const ticketHtml = `
                            <div id="llw_ticketBox">
                                <div class="llw_ticket-container" id="llw_ticket">
                                    <div class="llw_ticket-header">
                                        <h1>${data.event_name}</h1>
                                        <h2>Official Participant Ticket</h2>
                                    </div>
                                    <div class="llw_logo-container">
                                        <img src="https://eapaediatrics.eu/wp-content/uploads/2024/09/cropped-eap_logo-300x54.png" alt="EAP Logo">
                                    </div>
                                    <div class="llw_event-image" style="background-image: url(\'${data.event_image}\');"></div>
                                    <div class="llw_ticket-number">Ticket #: ${data.ticket_number}</div>
                                    <div class="llw_ticket-body">
                                        <div class="llw_ticket-type">${data.ticket_type}</div>
                                        <div class="llw_dates">${data.dates}</div>
                                        <div class="llw_ticket-info">
                                            <div class="llw_info-row">
                                                <div class="llw_info-label">Full Name:</div>
                                                <div class="llw_info-value">${data.full_name}</div>
                                            </div>
                                            <div class="llw_info-row">
                                                <div class="llw_info-label">Country:</div>
                                                <div class="llw_info-value">${data.country}</div>
                                            </div>
                                        </div>
                                        <div class="llw_accommodation">
                                            <div class="llw_accommodation-title">Accommodation Details</div>
                                            <div class="llw_info-row">
                                                <div class="llw_info-label">Address:</div>
                                                <div class="llw_info-value">${data.accommodation_address}</div>
                                            </div>
                                            <div class="llw_info-row">
                                                <div class="llw_info-label">Conference Venue:</div>
                                                <div class="llw_info-value">${data.venue_name}</div>
                                            </div>
                                        </div>
                                        <div class="llw_paid-status">Accommodation & Entry Fully Paid</div>
                                    </div>
                                    <div class="llw_ticket-footer">
                                        For more info: secretariat@eapaediatrics.eu
                                    </div>
                                    <button class="llw_print-button" onclick="generatePDF()">Download Ticket PDF</button>
                                </div>
                            </div>
                        `;
                        modalContent.html(ticketHtml);
                    } else {
                        modalContent.html(`<p>Error: ${response.data}</p>`);
                    }
                },
                error: function() {
                    modalContent.html(\'<p>Failed to load ticket data. Please try again later.</p>\');
                }
            });
            modal.on("click", function(event) {
                if (event.target === modal[0] || $(event.target).hasClass("llw_close-modal")) {
                    modal.remove();
                }
            });
        }

        // PDF generation function
        window.generatePDF = function() {
            const element = document.getElementById(\'llw_ticket\');
            const button = document.querySelector(\'.llw_print-button\');
            const footer = document.querySelector(\'.llw_ticket-footer\');

            button.style.display = \'none\';
            footer.style.display = \'none\';

            const options = {
                margin: 0.15,
                filename: \'EAP_Ticket_\' + new Date().getFullYear() + \'.pdf\',
                image: { type: \'jpeg\', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, scrollY: 0, windowHeight: element.offsetHeight },
                jsPDF: { unit: \'in\', format: [5.5, 8.5], orientation: \'portrait\', compress: true }
            };

            html2pdf().from(element).set(options).save().then(() => {
                button.style.display = \'block\';
                footer.style.display = \'block\';
            });
        };
    });
    </script>';
    return $output;
}
add_shortcode('event_attendee_profile', 'event_attendee_profile_shortcode');

// AJAX handler to get user's booked events
add_action('wp_ajax_get_user_events', 'get_user_events');
add_action('wp_ajax_nopriv_get_user_events', 'get_user_events');
function get_user_events() {
    check_ajax_referer('user_events_nonce', 'nonce');
    $wp_user_id = get_current_user_id();
    global $wpdb;

    // Get WordPress user email as fallback
    $wp_user = get_userdata($wp_user_id);
    $wp_email = $wp_user ? $wp_user->user_email : '';

    // Get Amelia customer ID using externalId or email
    $customer = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}amelia_users 
         WHERE (externalId = %d OR email = %s) AND type = 'customer'",
        $wp_user_id,
        $wp_email
    ));

    if (!$customer) {
        wp_send_json_success([]); // No customer found
    }

    $customer_id = $customer->id;

    // Fetch approved events for the customer
    $events = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT e.id, e.name 
         FROM {$wpdb->prefix}amelia_events e
         JOIN {$wpdb->prefix}amelia_events_periods ep ON e.id = ep.eventId
         JOIN {$wpdb->prefix}amelia_customer_bookings_to_events_periods btep ON ep.id = btep.eventPeriodId
         JOIN {$wpdb->prefix}amelia_customer_bookings b ON b.id = btep.customerBookingId
         WHERE b.customerId = %d AND b.status = 'approved'",
        $customer_id
    ));

    wp_send_json_success($events ?: []);
}

// AJAX handler to get ticket data
add_action('wp_ajax_nopriv_get_ticket_data', 'get_ticket_data');
add_action('wp_ajax_get_ticket_data', 'get_ticket_data');
function get_ticket_data() {
    check_ajax_referer('ticket_nonce', 'nonce');
    $event_id = intval($_POST['event_id']);
    $user_id = get_current_user_id();
    global $wpdb;

    // Get Amelia customer ID
    $wp_user = get_userdata($user_id);
    $wp_email = $wp_user ? $wp_user->user_email : '';
    $customer = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}amelia_users 
         WHERE (externalId = %d OR email = %s) AND type = 'customer'",
        $user_id,
        $wp_email
    ));
    if (!$customer) {
        wp_send_json_error('Customer not found');
    }
    $customer_id = $customer->id;

    // Fetch event details
    $event = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}amelia_events WHERE id = %d",
        $event_id
    ));
    if (!$event) {
        wp_send_json_error('Event not found');
    }

    // Fetch booking details
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}amelia_customer_bookings 
         WHERE customerId = %d AND id IN (
             SELECT customerBookingId FROM {$wpdb->prefix}amelia_customer_bookings_to_events_periods 
             WHERE eventPeriodId IN (
                 SELECT id FROM {$wpdb->prefix}amelia_events_periods WHERE eventId = %d
             )
         )",
        $customer_id,
        $event_id
    ));
    if (!$booking) {
        wp_send_json_error('Booking not found');
    }

    // Fetch accommodation details
    $custom_fields = json_decode($booking->customFields, true);
    $accommodation_id = $custom_fields['3']['value'] ?? null;
    $accommodation = $accommodation_id ? get_accommodation_by_id($accommodation_id) : null;

    // Fetch venue name
    $venue = $event->locationId ? get_venue_by_id($event->locationId) : null;

    // Generate ticket number
    $ticket_number = 'EAP-' . date('Y') . '-' . str_pad($booking->id, 5, '0', STR_PAD_LEFT);
    $event_period = $wpdb->get_row($wpdb->prepare(
        "SELECT periodStart, periodEnd FROM {$wpdb->prefix}amelia_events_periods WHERE eventId = %d",
        $event_id
    ));
    if (!$event_period) {
        wp_send_json_error('Event period not found');
    }
    $start_date = new DateTime($event_period->periodStart);
    $end_date = new DateTime($event_period->periodEnd);	
    if ($start_date->format('Y') === $end_date->format('Y')) {
        if ($start_date->format('F') === $end_date->format('F')) {
            $dates = $start_date->format('j') . ' - ' . $end_date->format('j F Y');
        } else $dates = $start_date->format('j F') . ' - ' . $end_date->format('j F Y');
    } else $dates = $start_date->format('j M Y') . ' - ' . $end_date->format('j M Y');
    // Prepare data
    $data = [
        'event_name' => $event->name,
        'event_image' => 'https://eapaediatrics.eu/wp-content/uploads/2025/04/16256-116544-f64893257_3xl.webp',
        'ticket_type' => 'Standard Participant', // Adjust if ticket type is stored elsewhere
        'dates' => $dates,
        'full_name' => $wp_user->first_name . ' ' . $wp_user->last_name,
        'email' => $wp_email,
        'country' => $custom_fields['1']['value'] ?? 'N/A',
        'accommodation_address' => $accommodation ? $accommodation->address : 'N/A',
        'venue_name' => $venue ? $venue->name : 'N/A',
        'ticket_number' => $ticket_number,
    ];

    wp_send_json_success($data);
}

function get_venue_by_id($location_id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT name FROM {$wpdb->prefix}amelia_locations WHERE id = %d",
        $location_id
    ));
}
function get_accommodation_by_id($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT address FROM llw_event_accommodations WHERE id = %d",
        $id
    ));
}

/**
 * Shortcode Redirect Page
 *
 * [llw_goto url="https://example.com" delay="5"]OR[llw_goto url="https://example.com"]Delay, if included is in seconds (for seconds before redirect), if not included then defaults to 0 secondsNB: replace example.com with the page you want to redirect to
 */
function wp_redirect_shortcode($atts) {
	if (isset($_GET['elementor-preview']) || 
        (function_exists('\Elementor\Plugin') && \Elementor\Plugin::$instance->editor->is_edit_mode()) ||
        (function_exists('\Elementor\Plugin') && \Elementor\Plugin::$instance->preview->is_preview_mode())) {
        return '<div style="padding: 10px; background: #f0f0f0; border: 1px dashed #ccc; text-align: center;">
                    <strong>Redirect Shortcode</strong><br>
                    <small>Would redirect to: ' . esc_html($atts['url'] ?? 'No URL specified') . '</small>
                </div>';
    }
    $atts = shortcode_atts(array(
        'url' => '',
        'delay' => 0, // Delay in seconds (0 = immediate)
        'status' => 302 // HTTP status code (301 = permanent, 302 = temporary)
    ), $atts, 'redirect');
    if (empty($atts['url'])) {
        return '';
    }
    $redirect_url = esc_url($atts['url']);
    $delay = intval($atts['delay']);
    $status = intval($atts['status']);
    if (!in_array($status, [301, 302, 303, 307, 308])) {
        $status = 302;
    }
    if ($delay <= 0) {
        if (!headers_sent()) {
            wp_redirect($redirect_url, $status);
            exit;
        } else {
            return '<div style="padding: 10px; background: #f0f0f0; border: 1px dashed #ccc; text-align: center;">
						<strong>Redirect Shortcode</strong><br>
						<small>Would redirect to: ' . esc_html($atts['url'] ?? 'No URL specified') . '</small>
					</div>
					<script>
					if (!document.body.classList.contains("elementor-editor-active") || ! elementorFrontend.isEditMode()) {
						window.location.href = "' . $redirect_url . '";
					}
					</script>';
        }
    } else {
        // Delayed redirect using meta refresh and JavaScript
        $message = "Redirecting in {$delay} seconds...";
        return '
        <div class="redirect-notice">
            <p>' . $message . '</p>
            <meta http-equiv="refresh" content="' . $delay . ';url=' . $redirect_url . '">
            <script>
				if (!document.body.classList.contains("elementor-editor-active") || ! elementorFrontend.isEditMode()) {
					setTimeout(function() {
						window.location.href = "' . $redirect_url . '";
					}, ' . ($delay * 1000) . ');
				}
            </script>
        </div>';
    }
}
add_shortcode('llw_goto', 'wp_redirect_shortcode');
