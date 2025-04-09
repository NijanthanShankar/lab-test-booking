<?php
/**
 * Plugin Name: Lab Test Booking System
 * Description: Provides API endpoints for lab test booking form
 * Version: 1.0
 * Author: Nijanthan Shankar
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include our API functions
require_once plugin_dir_path(__FILE__) . 'includes/api-endpoints.php';

// Add admin menu page for settings
add_action('admin_menu', 'lab_test_admin_menu');

function lab_test_admin_menu() {
    add_menu_page(
        'Lab Test Bookings',
        'Lab Test Bookings',
        'manage_options',
        'lab-test-bookings',
        'lab_test_admin_page',
        'dashicons-clipboard',
        30
    );
}

function lab_test_admin_page() {
    // Simple admin interface to view bookings
    global $wpdb;
    $table_name = $wpdb->prefix . 'lab_test_bookings';
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        echo '<div class="notice notice-warning"><p>No bookings have been made yet.</p></div>';
        return;
    }
    
    // Get all bookings
    $bookings = $wpdb->get_results("SELECT * FROM $table_name ORDER BY booking_date DESC");
    
    // Display bookings in a table
    echo '<div class="wrap">';
    echo '<h1>Lab Test Bookings</h1>';
    
    if (empty($bookings)) {
        echo '<p>No bookings found.</p>';
    } else {
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>
            <th>Booking Ref</th>
            <th>Name</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Date</th>
            <th>Payment</th>
        </tr></thead>';
        
        echo '<tbody>';
        foreach ($bookings as $booking) {
            echo '<tr>';
            echo '<td>' . esc_html($booking->booking_reference) . '</td>';
            echo '<td>' . esc_html($booking->first_name . ' ' . $booking->last_name) . '</td>';
            echo '<td>' . esc_html($booking->phone) . '</td>';
            echo '<td>' . esc_html($booking->email) . '</td>';
            echo '<td>' . esc_html($booking->booking_date) . '</td>';
            echo '<td>' . esc_html(ucfirst($booking->payment_status)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
    }
    
    echo '</div>';
}

// Add to your plugin file
function lab_test_booking_form_shortcode() {
    ob_start();
    include plugin_dir_path(__FILE__) . 'templates/lab-test.html';
    return ob_get_clean();
}
add_shortcode('lab_test_booking_form', 'lab_test_booking_form_shortcode');