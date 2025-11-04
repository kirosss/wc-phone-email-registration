<?php
/**
 * Plugin Name: WooCommerce Phone + Email Registration
 * Description: Adds a required mobile number field to WooCommerce registration and lets customers log in using either email or mobile number. Saves the number to billing_phone and My Account.
 * Version: 1.0.0
 * Author: Kirollos Remark Developer
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Phone_Email_Registration {
	public function __construct() {
		// Frontend register form (My Account /register)
		add_action( 'woocommerce_register_form', [ $this, 'render_phone_field' ] );
		add_filter( 'woocommerce_registration_errors', [ $this, 'validate_phone_on_register' ], 10, 3 );
		add_action( 'woocommerce_created_customer', [ $this, 'save_phone_on_register' ], 10, 3 );

		// Allow login with phone OR email
		add_filter( 'authenticate', [ $this, 'allow_phone_login' ], 30, 3 );
		add_filter( 'gettext', [ $this, 'override_login_label' ], 20, 3 );

		// My Account > Edit account: add & save phone
		add_action( 'woocommerce_edit_account_form', [ $this, 'render_phone_in_edit_account' ] );
		add_action( 'woocommerce_save_account_details', [ $this, 'save_phone_in_edit_account' ], 10, 1 );
	}

	/**
	 * Render phone field on Woo registration form.
	 */
	public function render_phone_field() {
		$phone = isset( $_POST['billing_phone'] ) ? wc_clean( wp_unslash( $_POST['billing_phone'] ) ) : '';
		echo '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">';
		woocommerce_form_field(
			'billing_phone',
			[
				'type'        => 'tel',
				'label'       => __( 'Mobile number', 'woocommerce' ),
				'required'    => true,
				'inputmode'   => 'tel',
				'custom_attributes' => [ 'autocomplete' => 'tel', 'maxlength' => '20' ],
				'placeholder' => __( 'e.g. +20 10 1234 5678', 'woocommerce' ),
			],
			$phone
		);
		echo '</p>';
	}

	/**
	 * Validate phone on registration (required + simple pattern + uniqueness).
	 */
	public function validate_phone_on_register( $errors, $username, $email ) {
		$phone_raw = isset( $_POST['billing_phone'] ) ? wp_unslash( $_POST['billing_phone'] ) : '';
		$phone     = $this->normalize_phone( $phone_raw );

		if ( empty( $phone ) ) {
			$errors->add( 'billing_phone_error', __( 'Please enter your mobile number.', 'woocommerce' ) );
			return $errors;
		}

		// Basic phone format: digits plus optional + and separators, 7-20 digits after stripping non-digits
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( strlen( $digits ) < 7 || strlen( $digits ) > 20 ) {
			$errors->add( 'billing_phone_format', __( 'Please enter a valid mobile number.', 'woocommerce' ) );
		}

		// Ensure uniqueness across users (checks billing_phone meta)
		$existing = get_users( [
			'fields'     => 'ID',
			'number'     => 1,
			'meta_key'   => 'billing_phone_normalized',
			'meta_value' => $digits,
		] );
		if ( ! empty( $existing ) ) {
			$errors->add( 'billing_phone_exists', __( 'This mobile number is already registered. Try logging in or use a different number.', 'woocommerce' ) );
		}

		return $errors;
	}

	/**
	 * Save phone to user meta on successful registration.
	 */
	public function save_phone_on_register( $customer_id ) {
		if ( isset( $_POST['billing_phone'] ) ) {
			$phone_raw = wp_unslash( $_POST['billing_phone'] );
			$phone     = wc_clean( $phone_raw );
			$digits    = preg_replace( '/\D+/', '', $phone );

			update_user_meta( $customer_id, 'billing_phone', $phone );
			update_user_meta( $customer_id, 'billing_phone_normalized', $digits );
		}
	}

	/**
	 * Authenticate with phone (maps phone to username, then uses core auth).
	 */
	public function allow_phone_login( $user, $username, $password ) {
    if ( $user instanceof WP_User || $username === '' || $password === '' ) {
        return $user;
    }

    // If the input contains '@', block (phone-only policy)
    if ( strpos( $username, '@' ) !== false ) {
        return new WP_Error( 'phone_only_login', __( 'Please log in with your mobile number.', 'woocommerce' ) );
    }

    // Try find by normalized phone
    $maybe_phone_digits = preg_replace( '/\D+/', '', $username );
    if ( strlen( $maybe_phone_digits ) >= 7 ) {
        $user_query = get_users( [
            'fields'     => [ 'ID', 'user_login' ],
            'number'     => 1,
            'meta_key'   => 'billing_phone_normalized',
            'meta_value' => $maybe_phone_digits,
        ] );
        if ( ! empty( $user_query ) ) {
            $u = $user_query[0];
            return wp_authenticate_username_password( null, $u->user_login, $password );
        }
    }

    return new WP_Error( 'phone_only_login_no_match', __( 'We could not find this mobile number. Please try again or register.', 'woocommerce' ) );
}

/**
 * Replace Woo login label "Username or email address" with "Email or mobile number".
 */
public function override_login_label( $translated, $text, $domain ) {
    if ( 'woocommerce' === $domain && 'Username or email address' === $text ) {
        return __( 'Mobile number', 'woocommerce' );
    }
    return $translated;
}

	/**
	 * Add phone to Edit Account form.
	 */
	public function render_phone_in_edit_account() {
		$user  = wp_get_current_user();
		$phone = get_user_meta( $user->ID, 'billing_phone', true );
		echo '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">';
		woocommerce_form_field(
			'billing_phone',
			[
				'type'        => 'tel',
				'label'       => __( 'Mobile number', 'woocommerce' ),
				'required'    => true,
				'inputmode'   => 'tel',
				'custom_attributes' => [ 'autocomplete' => 'tel', 'maxlength' => '20' ],
			],
			$phone
		);
		echo '</p>';
	}

	/**
	 * Save phone from Edit Account (with basic validation + uniqueness, excluding current user).
	 */
	public function save_phone_in_edit_account( $user_id ) {
		if ( isset( $_POST['billing_phone'] ) ) {
			$phone  = wc_clean( wp_unslash( $_POST['billing_phone'] ) );
			$digits = preg_replace( '/\D+/', '', $phone );

			if ( strlen( $digits ) < 7 || strlen( $digits ) > 20 ) {
				wc_add_notice( __( 'Please enter a valid mobile number.', 'woocommerce' ), 'error' );
				return;
			}

			$existing = get_users( [
				'fields'     => 'ID',
				'number'     => 1,
				'exclude'    => [ (int) $user_id ],
				'meta_key'   => 'billing_phone_normalized',
				'meta_value' => $digits,
			] );
			if ( ! empty( $existing ) ) {
				wc_add_notice( __( 'This mobile number is already in use by another account.', 'woocommerce' ), 'error' );
				return;
			}

			update_user_meta( $user_id, 'billing_phone', $phone );
			update_user_meta( $user_id, 'billing_phone_normalized', $digits );
		}
	}

	/**
	 * Normalize phone helper (trim spaces, unify plus, etc.)
	 */
	private function normalize_phone( $raw ) {
		$raw = trim( (string) $raw );
		// Convert Arabic digits to Latin (common in MENA)
		$ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
		$en = ['0','1','2','3','4','5','6','7','8','9'];
		$raw = str_replace( $ar, $en, $raw );
		return preg_replace( '/\s+/', ' ', $raw );
	}
	

}

new WC_Phone_Email_Registration();