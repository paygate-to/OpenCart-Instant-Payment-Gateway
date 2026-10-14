<?php
namespace Opencart\Catalog\Controller\Extension\Paygatedotto\Payment;

class Paygatedotto extends \Opencart\System\Engine\Controller {

    /**
     * Display payment method
     *
     * @return string
     */
    public function index(): string {
        $this->load->language('extension/paygatedotto/payment/paygatedotto');

         $data['confirm'] = $this->url->link('extension/payment/paygatedotto/checkout/confirm', '', true);
		 
		 $data['language'] = $this->config->get('config_language');
		 $data['payment_paygatedotto_description'] = $this->config->get('payment_paygatedotto_description');

        return $this->load->view('extension/paygatedotto/payment/paygatedotto', $data);
    }

    /**
     * Confirm and initialize payment
     *
     * @return void
     */
    public function confirm(): void {
				$this->load->language('extension/paygatedotto/payment/paygatedotto');
				$this->load->model('checkout/order');
				$this->load->model('extension/paygatedotto/payment/paygatedotto');

				$json = [];

				// Validate order
				if (!isset($this->session->data['order_id'])) {
					$json['error'] = $this->language->get('error_order');
				}

				// Validate payment method
				if (!isset($this->session->data['payment_method']) || $this->session->data['payment_method']['code'] != 'paygatedotto.paygatedotto') {
					$json['error'] = $this->language->get('error_payment_method');
				}

				if (!$json) {
					$order_id = $this->session->data['order_id'];
					$order = $this->model_checkout_order->getOrder($order_id);

					$wallet = $this->config->get('payment_paygatedotto_wallet_address');
					$domain = $this->config->get('payment_paygatedotto_custom_domain') ?: 'checkout.paygate.to';

					// Order total in the order's currency as a plain number (no symbols or
					// thousands separators, so totals of 1,000 and more work)
					$currency = $order['currency_code'];
					$amount = number_format((float)$this->currency->format($order['total'], $currency, $order['currency_value'], false), 2, '.', '');

					// Create wallet (generate address_in)
					$callback_url = $this->url->link('extension/paygatedotto/payment/paygatedotto.callback', 'order_id=' . $order_id . '&sig=' . $this->callbackSignature((int)$order_id), true);
					$wallet_api = "https://" . $this->apiHost() . "/control/wallet.php?address=" . urlencode($wallet) . "&callback=" . urlencode($callback_url);
					$wallet_resp = json_decode($this->curlGet($wallet_api), true);

					if (empty($wallet_resp['address_in'])) {
						$json['error'] = 'Wallet API Error: invalid or missing address_in';
					}

					if (!$json) {
						// ⚠️ Do NOT urlencode() the address_in (PayGate validates this exact string)
						$address_in = $wallet_resp['address_in'];
						$email = urlencode($order['email']);
						$comment='';
						$comment .='address_in:'.$wallet_resp['address_in'];
						$comment .='<br>polygon_address_in:'.$wallet_resp['polygon_address_in'];
						$comment .='<br>ipn_token:'.$wallet_resp['ipn_token'];
						$this->model_checkout_order->addHistory($order_id, 1, $comment, false);
						// Multi-provider Hosted Payment Page
						$redirect_url = "https://{$domain}/pay.php?address={$address_in}&amount={$amount}&email={$email}&currency={$currency}";

						$json['redirect'] = $redirect_url;
					}
				}

				// Return JSON response
				$this->response->addHeader('Content-Type: application/json');
				$this->response->setOutput(json_encode($json));
    }
	
	// PayGate callback
    public function callback(): void {
        $order_id = (int)($this->request->get['order_id'] ?? 0);
        $value_coin = (float)($this->request->get['value_coin'] ?? 0);
        $txid_out = $this->request->get['txid_out'] ?? '';

        // Only accept callbacks whose order_id carries our signature (added to the callback URL at checkout)
        if (!$order_id || !hash_equals($this->callbackSignature($order_id), (string)($this->request->get['sig'] ?? ''))) {
            http_response_code(403);
            echo 'Invalid callback signature';
            return;
        }

        // PayGate's RSA signature over the exact URL it requested: proves the callback came from
        // PayGate and that value_coin was not changed
        $registered = $this->url->link('extension/paygatedotto/payment/paygatedotto.callback', 'order_id=' . $order_id . '&sig=' . $this->callbackSignature($order_id), true);
        $verify = $this->verifyPaygateSignature($registered);
        if (!$verify['verified']) {
            http_response_code(403);
            echo 'Invalid PayGate signature (' . $verify['reason'] . ')';
            return;
        }

        if ($order_id && $value_coin) {
            $this->load->model('checkout/order');
            $order = $this->model_checkout_order->getOrder($order_id);
            if (!$order) {
                echo 'Invalid order';
                return;
            }
            // Already completed: ignore repeated callbacks
            $paid_status_id = $this->paidStatusId();
            if ((int)$order['order_status_id'] === $paid_status_id) {
                echo 'OK';
                return;
            }
			$callbackcurrency = $order['currency_code'];
			// Expected amount in USD (order total in the order currency, converted when the order is not in USD)
			$order_amount = (float)$this->currency->format($order['total'], $callbackcurrency, $order['currency_value'], false);
			$expected = $order_amount;
			// Convert to USD if not USD
					if ($callbackcurrency != 'USD') {
						$convert_url = "https://" . $this->apiHost() . "/control/convert.php?from=" . $callbackcurrency . "&value=" . $order_amount;
						$convert_resp = json_decode($this->curlGet($convert_url), true);
						if (!empty($convert_resp['value_coin'])) {
							$expected = (float)$convert_resp['value_coin'];
						} else {
							echo 'ERROR: currency conversion failed';
							return;
						}
					}

            // Fees tolerance setting (default 0.60: at least 60% of the total must arrive; 0 disables the check)
            $tolerance = $this->config->get('payment_paygatedotto_tolerance');
            $tolerance = is_numeric($tolerance) ? max(0.0, min(1.0, (float)$tolerance)) : 0.60;
            $threshold = $tolerance * $expected;

            if ($value_coin < $threshold) {
                $this->model_checkout_order->addHistory($order_id, 10, 'Partial payment (below the fees tolerance of ' . round($tolerance * 100) . '%). TXID: ' . $txid_out, true);
                echo 'FAILED';
                return;
            }

            $this->model_checkout_order->addHistory(
                $order_id,
                $paid_status_id,
                'Payment completed via PayGate Multi-Provider. TXID: ' . $txid_out,
                true
            );
            echo 'OK';
        }
    }
	
	// Status for paid orders: the "Order Status" setting, or Processing when it was never saved
	private function paidStatusId(): int {
		$status_id = (int)$this->config->get('payment_paygatedotto_order_status_id');
		if ($status_id > 0) {
			return $status_id;
		}
		$query = $this->db->query("SELECT `order_status_id` FROM `" . DB_PREFIX . "order_status` WHERE `name` = 'Processing' ORDER BY `order_status_id` ASC LIMIT 1");
		return $query->num_rows ? (int)$query->row['order_status_id'] : 2;
	}

	// Verify PayGate's callback signature (RSA, SHA-256, PKCS#1 v1.5) over the registered callback
	// URL up to its "?" plus the raw query string exactly as received
	// https://paygate.to/docs/payment-gateway-api/#callback-event
	private function verifyPaygateSignature(string $registered_callback_url): array {
		$signature = isset($_SERVER['HTTP_X_PAYGATE_SIGNATURE']) ? trim((string)$_SERVER['HTTP_X_PAYGATE_SIGNATURE']) : '';
		if ($signature === '') {
			return ['verified' => false, 'reason' => 'signature_absent'];
		}
		if (!function_exists('openssl_verify')) {
			return ['verified' => false, 'reason' => 'openssl_unavailable'];
		}
		$key_id = isset($_SERVER['HTTP_X_PAYGATE_KEY_ID']) ? trim((string)$_SERVER['HTTP_X_PAYGATE_KEY_ID']) : '';
		if ($key_id === '') {
			$key_id = 'v1';
		}
		if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $key_id)) {
			return ['verified' => false, 'reason' => 'unknown_key_id'];
		}
		$pem_file = DIR_EXTENSION . 'paygatedotto/system/library/keys/paygate_callback_public_' . $key_id . '.pem';
		if (!is_file($pem_file) || !is_readable($pem_file)) {
			return ['verified' => false, 'reason' => 'unknown_key_id'];
		}
		$public_key = openssl_pkey_get_public((string)file_get_contents($pem_file));
		if ($public_key === false) {
			return ['verified' => false, 'reason' => 'bad_public_key'];
		}
		$raw_signature = base64_decode($signature, true);
		if ($raw_signature === false || $raw_signature === '') {
			return ['verified' => false, 'reason' => 'signature_not_base64'];
		}
		$pos = strpos($registered_callback_url, '?');
		$signed = $pos === false ? $registered_callback_url : substr($registered_callback_url, 0, $pos);
		$uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
		$qpos = strpos($uri, '?');
		$query = $qpos !== false ? explode('#', substr($uri, $qpos + 1), 2)[0] : (isset($_SERVER['QUERY_STRING']) ? (string)$_SERVER['QUERY_STRING'] : '');
		if ($query !== '') {
			$signed .= '?' . $query;
		}
		$result = openssl_verify($signed, $raw_signature, $public_key, OPENSSL_ALGO_SHA256);
		if ($result === 1) {
			return ['verified' => true, 'reason' => ''];
		}
		return ['verified' => false, 'reason' => $result === -1 ? 'openssl_error' : 'signature_mismatch'];
	}

	// Callback signature: HMAC of the order ID with this store's random callback secret
	private function callbackSignature(int $order_id): string {
		return hash_hmac('sha256', (string)$order_id, $this->callbackSecret());
	}

	// Random secret created once and kept in the extension settings (never shown or derived from public data)
	private function callbackSecret(): string {
		$sql = "SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `key` = 'payment_paygatedotto_callback_secret' ORDER BY `setting_id` ASC LIMIT 1";
		$query = $this->db->query($sql);
		if (!$query->num_rows || $query->row['value'] === '') {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = 'payment_paygatedotto', `key` = 'payment_paygatedotto_callback_secret', `value` = '" . $this->db->escape(bin2hex(random_bytes(32))) . "', `serialized` = '0'");
			$query = $this->db->query($sql);
		}
		return (string)$query->row['value'];
	}

	// API host: dedicated API endpoint from settings, or the shared api.paygate.to when empty/invalid
	private function apiHost(): string {
		$host = strtolower(trim((string)$this->config->get('payment_paygatedotto_api_domain')));
		$host = preg_replace('#^https?://#', '', $host);
		$host = rtrim(explode('/', $host, 2)[0], '.');
		if ($host === '' || !preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
			return 'api.paygate.to';
		}
		return $host;
	}
	
	// Simple helper to GET remote URL
    private function curlGet(string $url) {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($curl);
        $err  = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return false;
        }
        return $resp;
    }
	
}
