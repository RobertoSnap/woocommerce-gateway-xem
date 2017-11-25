<?php


class Xem_Ajax {

	private static $instance;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct( ) {
		$this->init();
	}

    public static function init() {
        \add_action('init', array(__CLASS__, 'define_ajax'), 0);
        \add_action('template_redirect', array(__CLASS__, 'do_wc_ajax'), 0);
        self::add_ajax_events();
    }

    public static function get_endpoint($request = '') {
        return esc_url_raw(add_query_arg('wc-ajax', $request, remove_query_arg(array('remove_item', 'add-to-cart', 'added-to-cart'))));
    }

    public static function define_ajax() {
        if (!empty($_GET['wc-ajax'])) {
            if (!defined('DOING_AJAX')) {
                define('DOING_AJAX', true);
            }
            if (!defined('WC_DOING_AJAX')) {
                define('WC_DOING_AJAX', true);
            }
            // Turn off display_errors during AJAX events to prevent malformed JSON
            if (!WP_DEBUG || (WP_DEBUG && !WP_DEBUG_DISPLAY)) {
                @ini_set('display_errors', 0);
            }
            $GLOBALS['wpdb']->hide_errors();
        }
    }

    private static function wc_ajax_headers() {
        \send_origin_headers();
        @header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        @header('X-Robots-Tag: noindex');
        \send_nosniff_header();
        \nocache_headers();
        \status_header(200);
    }

    public static function do_wc_ajax() {
        global $wp_query;
        if (!empty($_GET['wc-ajax'])) {
            $wp_query->set('wc-ajax', sanitize_text_field($_GET['wc-ajax']));
        }
        if ($action = $wp_query->get('wc-ajax')) {
            self::wc_ajax_headers();
            \do_action('wc_ajax_' . sanitize_text_field($action));
            die();
        }
    }

    public static function add_ajax_events() {
        // woocommerce_EVENT => nopriv
        $ajax_events = array(
            'get_xem_amount'                      => true,
	        'check_for_payment'                   => true,
        );
        foreach ($ajax_events as $ajax_event => $nopriv) {
            \add_action('wp_ajax_woocommerce_' . $ajax_event, array(__CLASS__, $ajax_event));
            if ($nopriv) {
                \add_action('wp_ajax_nopriv_woocommerce_' . $ajax_event, array(__CLASS__, $ajax_event));
                // WC AJAX can be used for frontend ajax requests
                \add_action('wc_ajax_' . $ajax_event, array(__CLASS__, $ajax_event));
            }
        }
    }

    public static function get_xem_amount( ){
	    \check_ajax_referer('woocommerce-xem', 'nounce');

	    $amount = \sanitize_text_field($_REQUEST['amount']);
	    $currency = \sanitize_text_field($_REQUEST['currency']);

	    $amount = WC()->cart->total;
	    $currency = strtoupper( get_woocommerce_currency() ) ;
	    $currency = "EUR";


	    $callback = Xem_Currency::get_xem_amount($amount, $currency);
		if($callback){
			self::send($callback);
		}
	    self::error("Something went wrong when fetching the currency");

    }

	public static function check_for_payment( ){
		\check_ajax_referer('woocommerce-xem', 'nounce');
		//This token is user spesific and expires each day.
		$ref_id = wp_create_nonce( "3h62h6u26h42h6i2462h6u4h624" );

		//Get information from the Payment gateway
		if(!class_exists('WC_Gateway_Xem')){
			return error("Please setup XEM payment");
		}

        $xem_options = get_option('woocommerce_xem_settings');
		$xem_address = $xem_options['xem_address'];
		$match_amount = $xem_options['match_amount'];
        $xem_test = 'yes' === $xem_options['test'];
        if($xem_test){
            $xem_address = $xem_options['test_xem_address'];
        }

		//If we also want to do amount matching
		$amount = WC()->cart->total;
		$currency = strtoupper( get_woocommerce_currency() );
		//Todo: If too high difference becuase of price volatility, add notice to lock in new amount. The customer has probably waited to long.
		$xem_amout = Xem_Currency::get_xem_amount($amount, $currency);
		$xem_amount_locked = WC()->session->get('xem_amount');
		//Remove the currency
		$xem_amount_locked = intval(str_replace('.', '', $xem_amount_locked));
		//Todo: If locked and new amount diff to much, we can call a refresh.

		//Get latest transactions from NEW
		include_once ('class-nem-api.php');
		$transactions = NemApi::get_latest_transactions($xem_address,$xem_test);

		if(!$transactions){
			self::error("No transactions from NEM");
		}
		$message_match = false;
		$message_amount_match = false;
		$amount_match = false;
		$matched_transaction = false;
		//xem amount roundprecision changed 5 from 0.
		$decimal_amount_precision = 5;
		foreach ($transactions as $key => $t){
			$message = self::hex2str($t->transaction->message->payload);
			//Check for matching message
			if( $ref_id === $message ){
				$message_match = true;
				//Check for matching, only need to check that its atleast
                $xem_amount_lock_check = round($xem_amount_locked / 1000000,$decimal_amount_precision);
                $xem_amount_transaction_check = round($t->transaction->amount / 1000000,$decimal_amount_precision);
				if( $xem_amount_lock_check <=  $xem_amount_transaction_check ){
					$message_amount_match = true;
					$matched_transaction = $t;
					break;
				}
			}
			//if we also do only match on amount we try it here, but then the amount must be axactly.
//			if(!$message_amount_match && $match_amount){
//                $xem_amount_lock_check = round($xem_amount_locked / 1000000,$decimal_amount_precision);
//                $xem_amount_transaction_check = round($t->transaction->amount / 1000000,$decimal_amount_precision);
//				if( $xem_amount_lock_check ===  $xem_amount_transaction_check ){
//					$amount_match = true;
//					$matched_transaction = $t;
//					break;
//				}
//			}

		}

		//Check if we found a matched transaction
		//Then check that this transaction is not already connected to an order
		if($matched_transaction && self::not_used_xem_transaction($matched_transaction)){
			//If not we can go ahead and process order
			WC()->session->set('xem_payment', json_encode($matched_transaction ));
			self::send(array(
				'match' => true,
				'matched_transaction' => $matched_transaction,
			));
		}

		self::send(array(
			'match' => false,
			'matched_transaction' => false,
		));
		return false;
	}

	private static function not_used_xem_transaction($matched_transaction){

		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "
                SELECT meta_key,meta_value FROM wp_postmeta
		 		WHERE meta_key=\"xem_payment_hash\" AND meta_value= %s
			", $matched_transaction->meta->hash->data
			), ARRAY_A
		);

		if ( $rows ) {
			return false;
		} else {
			return true;
		}
	}

	private static function hex2str($hex) {
		$str = '';
		for($i=0;$i<strlen($hex);$i+=2) $str .= chr(hexdec(substr($hex,$i,2)));
		return $str;
	}

	private static function send($message = 0){
		wp_send_json_success($message);
		wp_die();
		return false;
	}

	private static function error($message = 0){
	    wp_send_json_error($message);
	    wp_die();
	    return false;
    }

}// End class AJAX

Xem_Ajax::get_instance();
