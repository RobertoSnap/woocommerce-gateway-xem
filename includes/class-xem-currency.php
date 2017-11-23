<?php

/**
 * Created by PhpStorm.
 * User: rpe
 * Date: 13.05.2017
 * Time: 13.31
 */
class Xem_Currency {

	private static $instance;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/*
	 * @returns
	 * */
	public static function get_xem_amount($amount, $currency = "EUR"){

		$response = false;
		$currency = strtoupper($currency);

		if(!get_transient( 'xem_currency_data')) {

            //Get Value of NEM to currency
            switch ( $currency ) {
                case 'EUR':
                    $response = wp_remote_get('https://api.coinmarketcap.com/v1/ticker/nem/?convert=EUR');
                    break;
                case 'USD':
                    $response = wp_remote_get('https://api.coinmarketcap.com/v1/ticker/nem/?convert=USD');
                    break;
		case 'JPY':
                    $response = wp_remote_get('https://api.coinmarketcap.com/v1/ticker/nem/?convert=USD');
                    break;
                case 'ALL':
                    $response = wp_remote_get('https://api.coinmarketcap.com/v1/ticker/nem/?convert=USD');
                    break;
                default:
                    self::error("Currency not supported");
            }

            if ( !$response ) {
                return self::error("No reponse from currency server");
            }
            //standarise the response
            $response = rest_ensure_response($response);
            //Check for valid response
            if ( $response->status !== 200 ) {
                self::error("Not 200 response");
            }
            //Check for body element
            if ( empty($response->data['body']) ) {
                self::error("Response body empty");
            }
            //Decode the json string
            $data = json_decode($response->data['body']);
            //Set a transient that expires each minute
            set_transient( 'xem_currency_data', $response->data['body'], 60  );
        }else{
            $data = json_decode(get_transient( 'xem_currency_data'));
        }
		//Check that data is not empty and it is an array.
		if(empty($data) && ! is_array($data)){
			self::error("Reponse empty or not array");
		}
		//Do the calculation
		if(empty($data[0]) && $data[0]->name === "NEM"){
			self::error("Data not set or not NEM");
		}




		//Done checking, lets prepare callback
		$callback = array(
			$data[0]
		);

		//Set the amount
		switch ($currency) {
			case 'EUR':
				$callback['amount'] = $amount / $data[0]->price_eur;
				break;
			case 'USD':
				$callback['amount'] = $amount / $data[0]->price_usd;
				break;
			case 'JPY':
				$callback['amount'] = $amount / $data[0]->price_jpy;
				break;
			case 'BTC':
				$callback['amount'] = $amount / $data[0]->price_btc;
				break;
			case 'ALL':
				//For future currency switching
				if(!empty($data[0]->price_eur)){
					$callback['amount_eur'] = $amount /  $data[0]->price_eur ;
				}
				if(!empty($data[0]->price_usd)){
					$callback['amount_usd'] = $amount / $data[0]->price_usd;
				}
				if(!empty($data[0]->price_jpy)){
					$callback['amount_jpy'] = $amount / $data[0]->price_jpy;
				}
				if(!empty($data[0]->price_btc)){
					$callback['amount_btc'] = $amount / $data[0]->price_btc;
				}
				return $callback;
			default:
				self::error("Currency not supported");
		}
		//Check if amount got set and round it.
		if (!empty($callback['amount']) && $callback['amount'] > 0)
			return round( $callback['amount'], 6, PHP_ROUND_HALF_UP );
		return self::error("Something wrong with amount");

	}

	private static function error($msg = "Error"){
		return false;
	}

}
Xem_Currency::get_instance();
