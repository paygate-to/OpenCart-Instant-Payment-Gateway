<?php
namespace Opencart\Admin\Controller\Extension\Paygatedotto\Payment;
/**
 * Class Cod 1
 *
 * @package Opencart\Admin\Controller\Extension\Opencart\Payment
 */
class Paygatedotto extends \Opencart\System\Engine\Controller {
	/**
	 * @return void
	 */
	public function index(): void {
		$this->load->language('extension/paygatedotto/payment/paygatedotto');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment')
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/paygatedotto/payment/paygatedotto', 'user_token=' . $this->session->data['user_token'])
		];

		$data['save'] = $this->url->link('extension/paygatedotto/payment/paygatedotto.save', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');

		$data['payment_paygatedotto_order_status_id'] = $this->config->get('payment_paygatedotto_order_status_id');
		$data['payment_paygatedotto_geo_zone_id'] = $this->config->get('payment_paygatedotto_geo_zone_id');
		$data['payment_paygatedotto_sort_order'] = $this->config->get('payment_paygatedotto_sort_order');
		
		 $fields = [
            'payment_paygatedotto_status',
            'payment_paygatedotto_title',
            'payment_paygatedotto_description',
            'payment_paygatedotto_wallet_address',
            'payment_paygatedotto_custom_domain',
            'payment_paygatedotto_api_domain',
            'payment_paygatedotto_icon_url'
        ];

        foreach ($fields as $field) {
            $data[$field] = $this->request->post[$field] ?? $this->config->get($field);
        }	
		
		$this->load->model('localisation/order_status');

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$this->load->model('localisation/geo_zone');

		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();
		
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/paygatedotto/payment/paygatedotto', $data));
	}

	/**
	 * @return void
	 */
	public function save(): void {
		$this->load->language('extension/paygatedotto/payment/paygatedotto');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/paygatedotto/payment/paygatedotto')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$this->load->model('setting/setting');

			// Keep the callback secret (created at checkout) when saving, so pending orders' callbacks stay valid
			$settings = $this->request->post;
			unset($settings['payment_paygatedotto_callback_secret']);
			$secret = $this->db->query("SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `key` = 'payment_paygatedotto_callback_secret' ORDER BY `setting_id` ASC LIMIT 1");
			if ($secret->num_rows && $secret->row['value'] !== '') {
				$settings['payment_paygatedotto_callback_secret'] = $secret->row['value'];
			}

			$this->model_setting_setting->editSetting('payment_paygatedotto', $settings);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}