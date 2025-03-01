<?php
namespace Opencart\System\Library\Cart;
/**
 * Class Cart
 *
 * @package Opencart\System\Library\Cart
 */
class Cart {
	/**
	 * @var object
	 */
	private object $db;
	/**
	 * @var object
	 */
	private object $config;
	/**
	 * @var object
	 */
	private object $customer;
	/**
	 * @var object
	 */
	private object $session;
	/**
	 * @var object
	 */
	private object $tax;
	/**
	 * @var object
	 */
	private object $weight;
	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $data = [];

	/**
	 * Constructor
	 *
	 * @param \Opencart\System\Engine\Registry $registry
	 */
	public function __construct(\Opencart\System\Engine\Registry $registry) {
		$this->db = $registry->get('db');
		$this->config = $registry->get('config');
		$this->customer = $registry->get('customer');
		$this->session = $registry->get('session');
		$this->tax = $registry->get('tax');
		$this->weight = $registry->get('weight');

		// Remove all the expired carts for visitors who never registered
		$this->db->query("DELETE FROM `" . DB_PREFIX . "cart` WHERE `store_id` = '" . (int)$this->config->get('config_store_id') . "' AND `customer_id` = '0' AND `date_added` < DATE_SUB(NOW(), INTERVAL " . (int)$this->config->get('config_session_expire') . " SECOND)");

		if ($this->customer->isLogged()) {
			// We want to change the session ID on all the old items in the customers cart
			$this->db->query("UPDATE `" . DB_PREFIX . "cart` SET `session_id` = '" . $this->db->escape($this->session->getId()) . "', `date_added` = NOW() WHERE `store_id` = '" . (int)$this->config->get('config_store_id') . "' AND `customer_id` = '" . (int)$this->customer->getId() . "'");

			// Once the customer is logged in we want to update the customers cart
			$this->db->query("UPDATE `" . DB_PREFIX . "cart` SET `customer_id` = '" . (int)$this->customer->getId() . "', `date_added` = NOW() WHERE `store_id` = '" . (int)$this->config->get('config_store_id') . "' AND `customer_id` = '0' AND `session_id` = '" . $this->db->escape($this->session->getId()) . "'");
		}

		// Populate the cart data
		$this->data = $this->getProducts();
	}

	/**
	 * Get Products
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getProducts(): array {
		if (!$this->data) {
			$cart_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "cart` WHERE `store_id` = '" . (int)$this->config->get('config_store_id') . "' AND `customer_id` = '" . (int)$this->customer->getId() . "' AND `session_id` = '" . $this->db->escape($this->session->getId()) . "'");

			foreach ($cart_query->rows as $cart) {
				$stock_status = true;

				$product_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "product_to_store` `p2s` LEFT JOIN `" . DB_PREFIX . "product` `p` ON (`p2s`.`product_id` = `p`.`product_id`) LEFT JOIN `" . DB_PREFIX . "product_description` `pd` ON (`p`.`product_id` = `pd`.`product_id`) WHERE `p2s`.`store_id` = '" . (int)$this->config->get('config_store_id') . "' AND `p2s`.`product_id` = '" . (int)$cart['product_id'] . "' AND `pd`.`language_id` = '" . (int)$this->config->get('config_language_id') . "' AND `p`.`date_available` <= NOW() AND `p`.`status` = '1'");

				if ($product_query->num_rows && ($cart['quantity'] > 0)) {
					$stock = $product_query->row['quantity'];

					$option_price = 0;
					$option_points = 0;
					$option_weight = 0;

					$option_data = [];

					$product_options = (array)json_decode($cart['option'], true);

					// Merge variant code with options
					$variant = json_decode($product_query->row['variant'], true);

					if ($variant) {
						foreach ($variant as $key => $value) {
							$product_options[$key] = $value;
						}
					}

					foreach ($product_options as $product_option_id => $value) {
						if (!$product_query->row['master_id']) {
							$product_id = $cart['product_id'];
						} else {
							$product_id = $product_query->row['master_id'];
						}

						$option_query = $this->db->query("SELECT `po`.`product_option_id`, `po`.`option_id`, `od`.`name`, `o`.`type` FROM `" . DB_PREFIX . "product_option` `po` LEFT JOIN `" . DB_PREFIX . "option` `o` ON (`po`.`option_id` = `o`.`option_id`) LEFT JOIN `" . DB_PREFIX . "option_description` `od` ON (`o`.`option_id` = `od`.`option_id`) WHERE `po`.`product_option_id` = '" . (int)$product_option_id . "' AND `po`.`product_id` = '" . (int)$product_id . "' AND `od`.`language_id` = '" . (int)$this->config->get('config_language_id') . "'");

						if ($option_query->num_rows) {
							if ($option_query->row['type'] == 'select' || $option_query->row['type'] == 'radio') {
								$option_value_query = $this->db->query("SELECT `pov`.`option_value_id`, `ovd`.`name`, `pov`.`quantity`, `pov`.`subtract`, `pov`.`price`, `pov`.`price_prefix`, `pov`.`points`, `pov`.`points_prefix`, `pov`.`weight`, `pov`.`weight_prefix` FROM `" . DB_PREFIX . "product_option_value` `pov` LEFT JOIN `" . DB_PREFIX . "option_value` `ov` ON (`pov`.`option_value_id` = `ov`.`option_value_id`) LEFT JOIN `" . DB_PREFIX . "option_value_description` `ovd` ON (`ov`.`option_value_id` = `ovd`.`option_value_id`) WHERE `pov`.`product_option_value_id` = '" . (int)$value . "' AND `pov`.`product_option_id` = '" . (int)$product_option_id . "' AND `ovd`.`language_id` = '" . (int)$this->config->get('config_language_id') . "'");

								if ($option_value_query->num_rows) {
									if ($option_value_query->row['price_prefix'] == '+') {
										$option_price += $option_value_query->row['price'];
									} elseif ($option_value_query->row['price_prefix'] == '-') {
										$option_price -= $option_value_query->row['price'];
									}

									if ($option_value_query->row['points_prefix'] == '+') {
										$option_points += $option_value_query->row['points'];
									} elseif ($option_value_query->row['points_prefix'] == '-') {
										$option_points -= $option_value_query->row['points'];
									}

									if ($option_value_query->row['weight_prefix'] == '+') {
										$option_weight += $option_value_query->row['weight'];
									} elseif ($option_value_query->row['weight_prefix'] == '-') {
										$option_weight -= $option_value_query->row['weight'];
									}

									if ($option_value_query->row['subtract'] && (!$option_value_query->row['quantity'] || ($option_value_query->row['quantity'] < $cart['quantity']))) {
										$stock_status = false;
									}

									$option_data[] = [
										'product_option_id'       => $product_option_id,
										'product_option_value_id' => $value,
										'option_id'               => $option_query->row['option_id'],
										'option_value_id'         => $option_value_query->row['option_value_id'],
										'name'                    => $option_query->row['name'],
										'value'                   => $option_value_query->row['name'],
										'type'                    => $option_query->row['type'],
										'quantity'                => $option_value_query->row['quantity'],
										'subtract'                => $option_value_query->row['subtract'],
										'price'                   => $option_value_query->row['price'],
										'price_prefix'            => $option_value_query->row['price_prefix'],
										'points'                  => $option_value_query->row['points'],
										'points_prefix'           => $option_value_query->row['points_prefix'],
										'weight'                  => $option_value_query->row['weight'],
										'weight_prefix'           => $option_value_query->row['weight_prefix']
									];
								}
							} elseif ($option_query->row['type'] == 'checkbox' && is_array($value)) {
								foreach ($value as $product_option_value_id) {
									$option_value_query = $this->db->query("SELECT `pov`.`option_value_id`, `pov`.`quantity`, `pov`.`subtract`, `pov`.`price`, `pov`.`price_prefix`, `pov`.`points`, `pov`.`points_prefix`, `pov`.`weight`, `pov`.`weight_prefix`, `ovd`.`name` FROM `" . DB_PREFIX . "product_option_value` `pov` LEFT JOIN `" . DB_PREFIX . "option_value_description` `ovd` ON (`pov`.`option_value_id` = `ovd`.option_value_id) WHERE `pov`.product_option_value_id = '" . (int)$product_option_value_id . "' AND `pov`.product_option_id = '" . (int)$product_option_id . "' AND `ovd`.language_id = '" . (int)$this->config->get('config_language_id') . "'");

									if ($option_value_query->num_rows) {
										if ($option_value_query->row['price_prefix'] == '+') {
											$option_price += $option_value_query->row['price'];
										} elseif ($option_value_query->row['price_prefix'] == '-') {
											$option_price -= $option_value_query->row['price'];
										}

										if ($option_value_query->row['points_prefix'] == '+') {
											$option_points += $option_value_query->row['points'];
										} elseif ($option_value_query->row['points_prefix'] == '-') {
											$option_points -= $option_value_query->row['points'];
										}

										if ($option_value_query->row['weight_prefix'] == '+') {
											$option_weight += $option_value_query->row['weight'];
										} elseif ($option_value_query->row['weight_prefix'] == '-') {
											$option_weight -= $option_value_query->row['weight'];
										}

										if ($option_value_query->row['subtract'] && (!$option_value_query->row['quantity'] || ($option_value_query->row['quantity'] < $cart['quantity']))) {
											$stock_status = false;
										}

										$option_data[] = [
											'product_option_id'       => $product_option_id,
											'product_option_value_id' => $product_option_value_id,
											'option_id'               => $option_query->row['option_id'],
											'option_value_id'         => $option_value_query->row['option_value_id'],
											'name'                    => $option_query->row['name'],
											'value'                   => $option_value_query->row['name'],
											'type'                    => $option_query->row['type'],
											'quantity'                => $option_value_query->row['quantity'],
											'subtract'                => $option_value_query->row['subtract'],
											'price'                   => $option_value_query->row['price'],
											'price_prefix'            => $option_value_query->row['price_prefix'],
											'points'                  => $option_value_query->row['points'],
											'points_prefix'           => $option_value_query->row['points_prefix'],
											'weight'                  => $option_value_query->row['weight'],
											'weight_prefix'           => $option_value_query->row['weight_prefix']
										];
									}
								}
							} elseif ($option_query->row['type'] == 'text' || $option_query->row['type'] == 'textarea' || $option_query->row['type'] == 'file' || $option_query->row['type'] == 'date' || $option_query->row['type'] == 'datetime' || $option_query->row['type'] == 'time') {
								$option_data[] = [
									'product_option_id'       => $product_option_id,
									'product_option_value_id' => '',
									'option_id'               => $option_query->row['option_id'],
									'option_value_id'         => '',
									'name'                    => $option_query->row['name'],
									'value'                   => $value,
									'type'                    => $option_query->row['type'],
									'quantity'                => '',
									'subtract'                => '',
									'price'                   => '',
									'price_prefix'            => '',
									'points'                  => '',
									'points_prefix'           => '',
									'weight'                  => '',
									'weight_prefix'           => ''
								];
							}
						}
					}

					$price = $product_query->row['price'];

					// Product Discounts
					$discount_quantity = 0;

					foreach ($cart_query->rows as $cart_2) {
						if ($cart_2['product_id'] == $cart['product_id']) {
							$discount_quantity += $cart_2['quantity'];
						}
					}

					$product_discount_query = $this->db->query("SELECT `price` FROM `" . DB_PREFIX . "product_discount` WHERE `product_id` = '" . (int)$cart['product_id'] . "' AND `customer_group_id` = '" . (int)$this->config->get('config_customer_group_id') . "' AND `quantity` <= '" . (int)$discount_quantity . "' AND ((`date_start` = '0000-00-00' OR `date_start` < NOW()) AND (`date_end` = '0000-00-00' OR `date_end` > NOW())) ORDER BY `quantity` DESC, `priority` ASC, `price` ASC LIMIT 1");

					if ($product_discount_query->num_rows) {
						$price = $product_discount_query->row['price'];
					}

					// Product Specials
					$product_special_query = $this->db->query("SELECT `price` FROM `" . DB_PREFIX . "product_special` WHERE `product_id` = '" . (int)$cart['product_id'] . "' AND `customer_group_id` = '" . (int)$this->config->get('config_customer_group_id') . "' AND ((`date_start` = '0000-00-00' OR `date_start` < NOW()) AND (`date_end` = '0000-00-00' OR `date_end` > NOW())) ORDER BY `priority` ASC, `price` ASC LIMIT 1");

					if ($product_special_query->num_rows) {
						$price = $product_special_query->row['price'];
					}

					$product_total = 0;

					foreach ($cart_query->rows as $cart_2) {
						if ($cart_2['product_id'] == $cart['product_id']) {
							$product_total += $cart_2['quantity'];
						}
					}

					// Stock
					if (!$product_query->row['quantity'] || ($product_query->row['quantity'] < $product_total)) {
						$stock_status = false;
					}

					// Minimum quantity
					if ($product_query->row['minimum'] > $product_total) {
						$minimum = false;
					} else {
						$minimum = true;
					}

					// Reward Points
					$product_reward_query = $this->db->query("SELECT `points` FROM `" . DB_PREFIX . "product_reward` WHERE `product_id` = '" . (int)$cart['product_id'] . "' AND `customer_group_id` = '" . (int)$this->config->get('config_customer_group_id') . "'");

					if ($product_reward_query->num_rows) {
						$reward = $product_reward_query->row['points'];
					} else {
						$reward = 0;
					}

					// Downloads
					$download_data = [];

					$download_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "product_to_download` `p2d` LEFT JOIN `" . DB_PREFIX . "download` `d` ON (`p2d`.`download_id` = `d`.`download_id`) LEFT JOIN `" . DB_PREFIX . "download_description` `dd` ON (`d`.`download_id` = `dd`.`download_id`) WHERE `p2d`.`product_id` = '" . (int)$cart['product_id'] . "' AND `dd`.`language_id` = '" . (int)$this->config->get('config_language_id') . "'");

					foreach ($download_query->rows as $download) {
						$download_data[] = [
							'download_id' => $download['download_id'],
							'name'        => $download['name'],
							'filename'    => $download['filename'],
							'mask'        => $download['mask']
						];
					}

					$subscription_data = [];

					$subscription_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "product_subscription` `ps` LEFT JOIN `" . DB_PREFIX . "subscription_plan` `sp` ON (`ps`.`subscription_plan_id` = `sp`.`subscription_plan_id`) LEFT JOIN `" . DB_PREFIX . "subscription_plan_description` `spd` ON (`sp`.`subscription_plan_id` = `spd`.`subscription_plan_id`) WHERE `ps`.`product_id` = '" . (int)$cart['product_id'] . "' AND `ps`.`subscription_plan_id` = '" . (int)$cart['subscription_plan_id'] . "' AND `ps`.`customer_group_id` = '" . (int)$this->config->get('config_customer_group_id') . "' AND `spd`.`language_id` = '" . (int)$this->config->get('config_language_id') . "' AND `sp`.`status` = '1'");

					if ($subscription_query->num_rows) {
						$price = $subscription_query->row['price'];

						if ($subscription_query->row['trial_status']) {
							$price = $subscription_query->row['trial_price'];
						}

						$subscription_data = [
							'subscription_plan_id' => $subscription_query->row['subscription_plan_id'],
							'name'                 => $subscription_query->row['name'],
							'trial_price'          => $subscription_query->row['trial_price'],
							'trial_frequency'      => $subscription_query->row['trial_frequency'],
							'trial_cycle'          => $subscription_query->row['trial_cycle'],
							'trial_duration'       => $subscription_query->row['trial_duration'],
							'trial_remaining'      => $subscription_query->row['trial_duration'],
							'trial_status'         => $subscription_query->row['trial_status'],
							'price'                => $subscription_query->row['price'],
							'frequency'            => $subscription_query->row['frequency'],
							'cycle'                => $subscription_query->row['cycle'],
							'duration'             => $subscription_query->row['duration'],
							'remaining'            => $subscription_query->row['duration']
						];
					}

					$default = [
						'name'   => $product_query->row['name'],
						'model'  => $product_query->row['model'],
						'price'  => $price,
						'reward' => $reward
					];

					// Use with order editor and subscriptions
					if ($cart['override']) {
						$override = json_decode($cart['override']);
					} else {
						$override = [];
					}

					foreach ($default as $key => $value) {
						if (isset($override[$key])) {
							${$key} = $override[$key];
						} else {
							${$key} = $value;
						}
					}

					$this->data[$cart['cart_id']] = [
						'cart_id'         => $cart['cart_id'],
						'product_id'      => $product_query->row['product_id'],
						'master_id'       => $product_query->row['master_id'],
						'name'            => $name,
						'model'           => $model,
						'shipping'        => $product_query->row['shipping'],
						'image'           => $product_query->row['image'],
						'option'          => $option_data,
						'subscription'    => $subscription_data,
						'download'        => $download_data,
						'quantity'        => $cart['quantity'],
						'minimum'         => $product_query->row['minimum'],
						'minimum_status'  => $minimum,
						'subtract'        => $product_query->row['subtract'],
						'stock'           => $stock,
						'stock_status'    => $stock_status,
						'price'           => $price,
						'total'           => $price * $cart['quantity'],
						'reward'          => $reward * $cart['quantity'],
						'points'          => $product_query->row['points'] ? ($product_query->row['points'] + $option_points) * $cart['quantity'] : 0,
						'tax_class_id'    => $product_query->row['tax_class_id'],
						'weight'          => ($product_query->row['weight'] + $option_weight) * $cart['quantity'],
						'weight_class_id' => $product_query->row['weight_class_id'],
						'length'          => $product_query->row['length'],
						'width'           => $product_query->row['width'],
						'height'          => $product_query->row['height'],
						'length_class_id' => $product_query->row['length_class_id']
					];
				} else {
					$this->remove($cart['cart_id']);
				}
			}
		}

		return $this->data;
	}

	/**
	 * Add
	 *
	 * @param int          $product_id
	 * @param int          $quantity
	 * @param array<mixed> $option
	 * @param int          $subscription_plan_id
	 * @param array        $override
	 * @param float        $price
	 *
	 * @return void
	 */
	public function add(int $product_id, int $quantity = 1, array $option = [], int $subscription_plan_id = 0, array $override = []): void {
		$query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "cart` WHERE `store_id` = '" . (int)$this->config->get('config_store_id') . "' AND `customer_id` = '" . (int)$this->customer->getId() . "' AND `session_id` = '" . $this->db->escape($this->session->getId()) . "' AND `product_id` = '" . (int)$product_id . "' AND `subscription_plan_id` = '" . (int)$subscription_plan_id . "' AND `option` = '" . $this->db->escape(json_encode($option)) . "'");

		if (!$query->row['total']) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "cart` SET `store_id` = '" . (int)$this->config->get('config_store_id') . "', `customer_id` = '" . (int)$this->customer->getId() . "', `session_id` = '" . $this->db->escape($this->session->getId()) . "', `product_id` = '" . (int)$product_id . "', `subscription_plan_id` = '" . (int)$subscription_plan_id . "', `option` = '" . $this->db->escape(json_encode($option)) . "', `quantity` = '" . (int)$quantity . "', `override` = '" . $this->db->escape(json_encode($override)) . "', `date_added` = NOW()");
		} else {
			$this->db->query("UPDATE `" . DB_PREFIX . "cart` SET `quantity` = (`quantity` + " . (int)$quantity . ") WHERE `store_id` = '" . (int)$this->config->get('config_store_id') . "' AND `customer_id` = '" . (int)$this->customer->getId() . "' AND `session_id` = '" . $this->db->escape($this->session->getId()) . "' AND `product_id` = '" . (int)$product_id . "' AND `subscription_plan_id` = '" . (int)$subscription_plan_id . "' AND `option` = '" . $this->db->escape(json_encode($option)) . "'");
		}

		// Clear cart data
		$this->data = [];

		// Populate the cart data
		$this->data = $this->getProducts();
	}

	/**
	 * Update
	 *
	 * @param int $cart_id
	 * @param int $quantity
	 *
	 * @return void
	 */
	public function update(int $cart_id, int $quantity): void {
		$this->db->query("UPDATE `" . DB_PREFIX . "cart` SET `quantity` = '" . (int)$quantity . "' WHERE `cart_id` = '" . (int)$cart_id . "' AND `store_id` = '" . (int)$this->config->get('config_store_id') . "' AND `customer_id` = '" . (int)$this->customer->getId() . "' AND `session_id` = '" . $this->db->escape($this->session->getId()) . "'");

		// Clear cart data
		$this->data = [];

		// Populate the cart data
		$this->data = $this->getProducts();
	}

	/**
	 * Has
	 *
	 * @param int $cart_id
	 *
	 * @return bool
	 */
	public function has(int $cart_id): bool {
		return isset($this->data[$cart_id]);
	}

	/**
	 * Remove
	 *
	 * @param int $cart_id
	 *
	 * @return void
	 */
	public function remove(int $cart_id): void {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "cart` WHERE `cart_id` = '" . (int)$cart_id . "' AND `store_id` = '" . (int)$this->config->get('config_store_id') . "' AND `customer_id` = '" . (int)$this->customer->getId() . "' AND `session_id` = '" . $this->db->escape($this->session->getId()) . "'");

		unset($this->data[$cart_id]);
	}

	/**
	 * Clear
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "cart` WHERE `store_id` = '" . (int)$this->config->get('config_store_id') . "' AND `customer_id` = '" . (int)$this->customer->getId() . "' AND `session_id` = '" . $this->db->escape($this->session->getId()) . "'");

		$this->data = [];
	}

	/**
	 * Get Subscriptions
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getSubscriptions(): array {
		$product_data = [];

		foreach ($this->getProducts() as $value) {
			if ($value['subscription']) {
				$product_data[] = $value;
			}
		}

		return $product_data;
	}

	/**
	 * Get Weight
	 *
	 * @return float
	 */
	public function getWeight(): float {
		$weight = 0;

		foreach ($this->getProducts() as $product) {
			if ($product['shipping']) {
				$weight += $this->weight->convert($product['weight'], $product['weight_class_id'], $this->config->get('config_weight_class_id'));
			}
		}

		return $weight;
	}

	/**
	 * Get Sub Total
	 *
	 * @return float
	 */
	public function getSubTotal(): float {
		$total = 0;

		foreach ($this->getProducts() as $product) {
			$total += $product['total'];
		}

		return $total;
	}

	/**
	 * Get Taxes
	 *
	 * @return array<int, float>
	 */
	public function getTaxes(): array {
		$tax_data = [];

		foreach ($this->getProducts() as $product) {
			if ($product['tax_class_id']) {
				$tax_rates = $this->tax->getRates($product['price'], $product['tax_class_id']);

				foreach ($tax_rates as $tax_rate) {
					if (!isset($tax_data[$tax_rate['tax_rate_id']])) {
						$tax_data[$tax_rate['tax_rate_id']] = ($tax_rate['amount'] * $product['quantity']);
					} else {
						$tax_data[$tax_rate['tax_rate_id']] += ($tax_rate['amount'] * $product['quantity']);
					}
				}
			}
		}

		return $tax_data;
	}

	/**
	 * Get Total
	 *
	 * @return float
	 */
	public function getTotal(): float {
		$total = 0;

		foreach ($this->getProducts() as $product) {
			$total += $this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax')) * $product['quantity'];
		}

		return $total;
	}

	/**
	 * Count Products
	 *
	 * @return int
	 */
	public function countProducts(): int {
		$product_total = 0;

		$products = $this->getProducts();

		foreach ($products as $product) {
			$product_total += $product['quantity'];
		}

		return $product_total;
	}

	/**
	 * Has Products
	 *
	 * @return bool
	 */
	public function hasProducts(): bool {
		return (bool)count($this->getProducts());
	}

	/**
	 * Has Subscription
	 *
	 * @return bool
	 */
	public function hasSubscription(): bool {
		return (bool)count($this->getSubscriptions());
	}

	/**
	 * Has Stock
	 *
	 * @return bool
	 */
	public function hasStock(): bool {
		foreach ($this->getProducts() as $product) {
			if (!$product['stock']) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Has Minimum
	 *
	 * Check if any products have a minimum order quantity amount and do not meet the requirement
	 *
	 * @return bool
	 */
	public function hasMinimum() {
		foreach ($this->getProducts() as $product) {
			if (!$product['minimum_status']) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Has Shipping
	 *
	 * @return bool
	 */
	public function hasShipping(): bool {
		foreach ($this->getProducts() as $product) {
			if ($product['shipping']) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Has Download
	 *
	 * @return bool
	 */
	public function hasDownload(): bool {
		foreach ($this->getProducts() as $product) {
			if ($product['download']) {
				return true;
			}
		}

		return false;
	}
}
