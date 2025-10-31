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

					$currency = $this->session->data['currency'];
					$amt=$this->currency->format($order['total'], $this->session->data['currency']);
					$currency_code = $this->session->data['currency'];  
					$currency_symbol_left = $this->currency->getSymbolLeft($currency_code); 
					$currency_symbol_right = $this->currency->getSymbolRight($currency_code);  
					$amount = str_replace([$currency_symbol_left, $currency_symbol_right], '', $amt);
					$amount = number_format($amount, 2, '.', '');
					
					// Convert to USD if not USD
					if ($currency != 'USD') {
						$convert_url = "https://api.paygate.to/control/convert.php?from=" . urlencode($currency) . "&value=" . $amount;
						$convert_resp = json_decode($this->curlGet($convert_url), true);
						if (!empty($convert_resp['value_coin'])) {
							$amount = $convert_resp['value_coin'];
						}
					}

					// Create wallet (generate address_in)
					$callback_url = $this->url->link('extension/paygatedotto/payment/paygatedotto.callback', 'order_id=' . $order_id, true);
					$wallet_api = "https://api.paygate.to/control/wallet.php?address=" . urlencode($wallet) . "&callback=" . urlencode($callback_url);
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
						$redirect_url = "https://{$domain}/pay.php?address={$address_in}&amount={$amount}&email={$email}&currency={$currency}&domain=" . urlencode($domain);

						$json['redirect'] = $redirect_url;
					}
				}

				// Return JSON response
				$this->response->addHeader('Content-Type: application/json');
				$this->response->setOutput(json_encode($json));
    }
	
	// PayGate callback
    public function callback(): void {
        $order_id = $this->request->get['order_id'] ?? 0;
        $value_coin = (float)($this->request->get['value_coin'] ?? 0);
        $txid_out = $this->request->get['txid_out'] ?? '';

        if ($order_id && $value_coin) {
            $this->load->model('checkout/order');
            $order = $this->model_checkout_order->getOrder($order_id);
            $expected = (float)$order['total'];
            $threshold = 0.6 * $expected;

            if ($value_coin < $threshold) {
                $this->model_checkout_order->addHistory($order_id, 10, 'Partial payment (<60%). TXID: ' . $txid_out, true);
                echo 'FAILED';
                return;
            }

            $this->model_checkout_order->addHistory(
                $order_id,
                $this->config->get('payment_paygatedotto_order_status_id'),
                'Payment completed via PayGate Multi-Provider. TXID: ' . $txid_out,
                true
            );
            echo 'OK';
        }
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
