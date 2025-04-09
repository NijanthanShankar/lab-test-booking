<?php
/**
 * Lab Test Booking API Functions
 * 
 * Add this to your theme's functions.php or create a custom plugin
 */

// Register REST API routes
add_action('rest_api_init', 'register_lab_test_api_routes');

function register_lab_test_api_routes() {
    // Register the OTP sending endpoint
    register_rest_route('lab-test/v1', '/send-otp', array(
        'methods' => 'POST',
        'callback' => 'handle_send_otp',
        'permission_callback' => '__return_true',
    ));
    
    // Register the OTP verification endpoint
    register_rest_route('lab-test/v1', '/verify-otp', array(
        'methods' => 'POST',
        'callback' => 'handle_verify_otp',
        'permission_callback' => '__return_true',
    ));
    
    // Register other endpoints
    register_rest_route('lab-test/v1', '/check-user', array(
        'methods' => 'GET',
        'callback' => 'handle_check_user',
        'permission_callback' => '__return_true',
    ));
    
    register_rest_route('lab-test/v1', '/save-booking', array(
        'methods' => 'POST',
        'callback' => 'handle_save_booking',
        'permission_callback' => '__return_true',
    ));
    
    register_rest_route('lab-test/v1', '/send-confirmation-email', array(
        'methods' => 'POST',
        'callback' => 'handle_send_confirmation_email',
        'permission_callback' => '__return_true',
    ));
    
    register_rest_route('lab-test/v1', '/process-payment', array(
        'methods' => 'POST',
        'callback' => 'handle_process_payment',
        'permission_callback' => '__return_true',
    ));
}

/**
 * Handle the OTP sending request
 */
function handle_send_otp($request) {
    // Get the phone number from the request
    $params = $request->get_json_params();
    $phone = sanitize_text_field($params['phone']);
    
    if (empty($phone)) {
        return new WP_Error('missing_phone', 'Phone number is required', array('status' => 400));
    }
    
    // Generate a random 4-digit OTP
    $otp = sprintf("%04d", mt_rand(1000, 9999));
    
    // Store the OTP in the database with expiration time (2 minutes from now)
    $expiry = time() + (2 * 60); // 2 minutes
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'lab_test_otps';
    
    // Check if table exists, if not create it
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        create_otp_table();
    }
    
    // Delete any existing OTPs for this phone number
    $wpdb->delete($table_name, array('phone' => $phone));
    
    // Insert the new OTP
    $result = $wpdb->insert(
        $table_name,
        array(
            'phone' => $phone,
            'otp' => $otp,
            'expiry' => $expiry,
            'created_at' => current_time('mysql')
        )
    );
    
    if (!$result) {
        return new WP_Error('db_error', 'Failed to store OTP', array('status' => 500));
    }
    
    // Send the OTP via SMS
    $sms_sent = send_otp_via_sms($phone, $otp);
    
    if ($sms_sent) {
        return array(
            'success' => true,
            'message' => 'OTP sent successfully'
        );
    } else {
        return new WP_Error('sms_failed', 'Failed to send OTP SMS', array('status' => 500));
    }
}

/**
 * Create OTP table if it doesn't exist
 */
function create_otp_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'lab_test_otps';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        phone varchar(20) NOT NULL,
        otp varchar(10) NOT NULL,
        expiry int(11) NOT NULL,
        created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Send OTP via SMS using a service
 */
function send_otp_via_sms($phone, $otp) {
    // Option 1: Using a WordPress SMS plugin if you have one installed
    if (function_exists('your_sms_plugin_function')) {
        return your_sms_plugin_function($phone, "Your Lab Test OTP is: $otp");
    }
    
    // Option 2: Using a direct API call to an SMS gateway (example with Twilio)
    $twilio_sid = 'YOUR_TWILIO_SID'; // Replace with actual credentials
    $twilio_token = 'YOUR_TWILIO_TOKEN';
    $twilio_phone = 'YOUR_TWILIO_PHONE_NUMBER';
    
    $url = "https://api.twilio.com/2010-04-01/Accounts/{$twilio_sid}/Messages.json";
    
    $body = "Your Lab Test Booking OTP is: $otp. Valid for 2 minutes.";
    
    $response = wp_remote_post($url, array(
        'method' => 'POST',
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode("$twilio_sid:$twilio_token"),
            'Content-Type' => 'application/x-www-form-urlencoded',
        ),
        'body' => array(
            'From' => $twilio_phone,
            'To' => $phone,
            'Body' => $body,
        ),
    ));
    
    if (is_wp_error($response)) {
        error_log('SMS sending error: ' . $response->get_error_message());
        return false;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    return $response_code >= 200 && $response_code < 300;
    
    // For testing without an SMS gateway, just log the OTP
    error_log("OTP for $phone: $otp");
    return true; // For testing, always return true
}

/**
 * Handle the OTP verification request
 */
function handle_verify_otp($request) {
    $params = $request->get_json_params();
    $phone = sanitize_text_field($params['phone']);
    $otp = sanitize_text_field($params['otp']);
    
    if (empty($phone) || empty($otp)) {
        return new WP_Error('missing_parameters', 'Phone and OTP are required', array('status' => 400));
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'lab_test_otps';
    
    // Get the stored OTP for this phone number
    $stored_otp = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE phone = %s ORDER BY id DESC LIMIT 1",
            $phone
        )
    );
    
    if (!$stored_otp) {
        return array(
            'success' => false,
            'message' => 'No OTP found for this phone number'
        );
    }
    
    // Check if OTP is expired
    if (time() > $stored_otp->expiry) {
        return array(
            'success' => false,
            'message' => 'OTP has expired'
        );
    }
    
    // Check if OTP matches
    if ($otp !== $stored_otp->otp) {
        return array(
            'success' => false,
            'message' => 'Invalid OTP'
        );
    }
    
    // OTP is valid, delete it to prevent reuse
    $wpdb->delete($table_name, array('id' => $stored_otp->id));
    
    return array(
        'success' => true,
        'message' => 'OTP verified successfully'
    );
}

// Replace the direct OTP generation with an API call
sendOtpBtn.addEventListener('click', function() {
    const phone = document.getElementById('phone').value;
    if (phone && /^\d{10}$/.test(phone)) {
        sendOtpBtn.disabled = true;
        sendOtpBtn.textContent = 'Sending...';
        
        // Call the WordPress REST API endpoint
        fetch('/wp-json/lab-test/v1/send-otp', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpApiSettings.nonce // Add WordPress nonce if you're using authentication
            },
            body: JSON.stringify({ phone: phone })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                otpSection.classList.remove('hidden');
                sendOtpBtn.textContent = 'Resend OTP';
                sendOtpBtn.disabled = false;
                
                // Start timer
                startOtpTimer();
                
                // Focus on first OTP input
                otpInputs[0].focus();
            } else {
                alert('Failed to send OTP: ' + data.message);
                sendOtpBtn.disabled = false;
                sendOtpBtn.textContent = 'Send OTP';
            }
        })
        .catch(error => {
            console.error('Error sending OTP:', error);
            alert('Failed to send OTP. Please try again.');
            sendOtpBtn.disabled = false;
            sendOtpBtn.textContent = 'Send OTP';
        });
        
        // Save to patient data
        patientData.phone = phone;
    } else {
        alert('Please enter a valid 10-digit phone number');
    }
});

// Update the OTP verification too
verifyOtpBtn.addEventListener('click', function() {
    let enteredOtp = '';
    otpInputs.forEach(input => {
        enteredOtp += input.value;
    });
    
    if (enteredOtp.length === 4) {
        fetch('/wp-json/lab-test/v1/verify-otp', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpApiSettings.nonce // Add if using authentication
            },
            body: JSON.stringify({ 
                phone: patientData.phone,
                otp: enteredOtp 
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('otpError').style.display = 'none';
                clearInterval(timerInterval);
                
                // Check if user exists in database using phone number
                checkExistingUser(patientData.phone);
            } else {
                document.getElementById('otpError').style.display = 'block';
                document.getElementById('otpError').textContent = data.message || 'Invalid OTP. Please try again.';
            }
        })
        .catch(error => {
            console.error('Error verifying OTP:', error);
            document.getElementById('otpError').style.display = 'block';
            document.getElementById('otpError').textContent = 'Error verifying OTP. Please try again.';
        });
    } else {
        document.getElementById('otpError').style.display = 'block';
        document.getElementById('otpError').textContent = 'Please enter a complete 4-digit OTP.';
    }
});

/**
 * Check if a user exists based on phone number
 */
function handle_check_user($request) {
    $phone = sanitize_text_field($request->get_param('phone'));
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'lab_test_bookings';
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        return array(
            'exists' => false
        );
    }
    
    // Check for existing user
    $existing_user = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE phone = %s ORDER BY id DESC LIMIT 1",
            $phone
        )
    );
    
    if ($existing_user) {
        return array(
            'exists' => true,
            'userData' => array(
                'firstName' => $existing_user->first_name,
                'lastName' => $existing_user->last_name,
                'email' => $existing_user->email,
                'age' => $existing_user->age,
                'gender' => $existing_user->gender,
                'healthConcern' => $existing_user->health_concern,
                'address' => $existing_user->address,
                'city' => $existing_user->city,
                'state' => $existing_user->state,
                'zipCode' => $existing_user->zip_code
            )
        );
    }
    
    return array(
        'exists' => false
    );
}

/**
 * Save booking information
 */
function handle_save_booking($request) {
    $params = $request->get_json_params();
    
    // Generate booking reference
    $booking_ref = generate_booking_reference();
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'lab_test_bookings';
    
    // Create table if it doesn't exist
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        create_bookings_table();
    }
    
    // Insert the booking data
    $result = $wpdb->insert(
        $table_name,
        array(
            'booking_reference' => $booking_ref,
            'phone' => sanitize_text_field($params['phone']),
            'first_name' => sanitize_text_field($params['firstName']),
            'last_name' => sanitize_text_field($params['lastName']),
            'email' => sanitize_email($params['email']),
            'age' => intval($params['age']),
            'gender' => sanitize_text_field($params['gender']),
            'health_concern' => sanitize_textarea_field($params['healthConcern']),
            'address' => sanitize_text_field($params['address']),
            'city' => sanitize_text_field($params['city']),
            'state' => sanitize_text_field($params['state']),
            'zip_code' => sanitize_text_field($params['zipCode']),
            'latitude' => sanitize_text_field($params['latitude']),
            'longitude' => sanitize_text_field($params['longitude']),
            'payment_method' => sanitize_text_field($params['paymentMethod']),
            'payment_status' => 'completed',
            'booking_date' => current_time('mysql')
        )
    );
    
    if (!$result) {
        return new WP_Error('db_error', 'Failed to save booking information', array('status' => 500));
    }
    
    return array(
        'success' => true,
        'bookingRef' => $booking_ref
    );
}

/**
 * Generate a booking reference number
 */
function generate_booking_reference() {
    $date = date('ymd');
    $random = sprintf('%05d', mt_rand(10000, 99999));
    return "LT-{$date}-{$random}";
}

/**
 * Create bookings table
 */
function create_bookings_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'lab_test_bookings';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        booking_reference varchar(20) NOT NULL,
        phone varchar(20) NOT NULL,
        first_name varchar(50) NOT NULL,
        last_name varchar(50) NOT NULL,
        email varchar(100) NOT NULL,
        age int(3) NOT NULL,
        gender varchar(10) NOT NULL,
        health_concern text NOT NULL,
        address text NOT NULL,
        city varchar(50) NOT NULL,
        state varchar(50) NOT NULL,
        zip_code varchar(20) NOT NULL,
        latitude varchar(20) NULL,
        longitude varchar(20) NULL,
        payment_method varchar(20) NOT NULL,
        payment_status varchar(20) NOT NULL,
        booking_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Send confirmation email
 */
function handle_send_confirmation_email($request) {
    $params = $request->get_json_params();
    
    $to = sanitize_email($params['email']);
    $subject = 'Lab Test Booking Confirmation - ' . $params['bookingRef'];
    
    // Create HTML email content
    $message = '
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #3498db; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; }
            .footer { background-color: #f5f5f5; padding: 15px; text-align: center; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Lab Test Booking Confirmation</h1>
            </div>
            <div class="content">
                <p>Dear ' . sanitize_text_field($params['name']) . ',</p>
                <p>Thank you for booking your lab test with us. Your booking has been confirmed.</p>
                <p><strong>Booking Reference:</strong> ' . sanitize_text_field($params['bookingRef']) . '</p>
                <p><strong>Test Type:</strong> Comprehensive Health Panel</p>
                <p><strong>Location:</strong> Home Collection</p>
                <p><strong>Address:</strong> ' . sanitize_text_field($params['address']) . '</p>
                <p>A lab technician will visit your location within 24-48 hours. Our team will call you to confirm the exact time.</p>
                <p>If you have any questions, please contact our support team.</p>
                <p>Thank you,<br>Lab Test Services Team</p>
            </div>
            <div class="footer">
                <p>This is an automated message. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ';
    
    // Email headers
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: Lab Test Services <noreply@yourdomain.com>'
    );
    
    // Send email
    $sent = wp_mail($to, $subject, $message, $headers);
    
    if ($sent) {
        return array(
            'success' => true,
            'message' => 'Confirmation email sent successfully',
            'bookingRef' => $params['bookingRef']
        );
    } else {
        return new WP_Error('email_failed', 'Failed to send confirmation email', array('status' => 500));
    }
}

/**
 * Process payment (simplified example)
 */
function handle_process_payment($request) {
    $params = $request->get_json_params();
    
    // In a real implementation, you would integrate with a payment gateway here
    // This is a placeholder that always returns success for demonstration
    
    return array(
        'success' => true,
        'transaction_id' => 'TRANS-' . time(),
        'message' => 'Payment processed successfully'
    );
}