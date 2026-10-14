<?php
/** Standalone synthetic tests; no WordPress bootstrap or payment requests. */
declare(strict_types=1);

namespace J7\WpUtils\Traits { trait SingletonTrait {} }
namespace J7\WpUtils\Classes {
	class ApiBase {}
	class WP { public static function array_to_html(...$args): string { return 'synthetic note'; } }
}
namespace J7\PowerCheckout { class Plugin { public static function logger(...$args): void {} } }
namespace J7\PowerCheckout\Domains\Payment\Payuni\DTOs {
	class PayuniSettingsDTO {
		public string $merchant_id = 'TEST_MERCHANT';
		public string $hash_key = 'synthetic';
		public string $hash_iv = 'synthetic';
		public static function instance(): static { return new static(); }
	}
}
namespace J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs {
	class PayuniUniEmbedSettingsDTO extends \J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO {}
}
namespace J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers {
	class PayuniCrypto {
		public static array $inner = [];
		public function __construct(...$args) {}
		public function verify_hash(...$args): bool { return true; }
		public function decrypt(...$args): array { return self::$inner; }
	}
	class PayuniMetaKeys {
		public function __construct(private \WC_Order $order) {}
		public static function get_order_by_trade_no($number): mixed { return $GLOBALS['test_order']; }
		public function update_payment_detail($data): void { ++$this->order->details; }
		public function update_payment_info($data): void { ++$this->order->details; }
	}
}
namespace J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers {
	class PayuniUniEmbedMetaKeys extends \J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys {}
}
namespace {
	class WC_Payment_Gateway { public string $id = 'synthetic_gateway'; }
	class WC_Order {
		public int $details = 0;
		public int $notes = 0;
		public int $completions = 0;
		public int $updates = 0;
		public string $transaction = '';
		public string $method = 'synthetic_gateway';
		public function __construct(public string $status = 'pending', public bool $virtual = false) {}
		public function is_paid(): bool { return in_array($this->status, ['processing', 'completed', 'custom-paid'], true); }
		public function has_status($statuses): bool { return in_array($this->status, (array) $statuses, true); }
		public function get_transaction_id(): string { return $this->transaction; }
		public function get_total(): string { return '100'; }
		public function add_order_note($note): void { ++$this->notes; }
		public function payment_complete($transaction): void {
			++$this->completions;
			$this->transaction = $transaction;
			$this->status = $this->virtual ? 'completed' : 'processing';
		}
		public function update_status($status): void { ++$this->updates; $this->status = $status; }
		public function get_payment_method(): string { return $this->method; }
		public function get_order_key(): string { return 'wc_order_synthetic'; }
		public function needs_payment(): bool { return in_array($this->status, ['pending', 'failed'], true); }
	}
	function wc_get_order($id): mixed { return $GLOBALS['test_order']; }
	function wp_unslash($value): string { return stripslashes($value); }
	function WC(): object { return $GLOBALS['test_wc']; }
	$root = getenv('PC_TEST_ROOT') ?: dirname(__DIR__, 2);
	$base = $root . '/inc/classes/Domains/Payment/';
	require $base . 'Shared/Enums/OrderStatus.php';
	require $base . 'Shared/Interfaces/IGateway.php';
	require $base . 'Shared/Interfaces/IPaymentProvider.php';
	require $base . 'Shared/Abstracts/AbstractPaymentGateway.php';
	foreach (['Payuni', 'PayuniUniEmbed'] as $domain) {
		$enum = $domain === 'Payuni' ? 'PayuniTradeStatus' : 'PayuniUniEmbedTradeStatus';
		require $base . $domain . '/Shared/Enums/' . $enum . '.php';
		require $base . $domain . '/Managers/StatusManager.php';
		$callback = $domain === 'Payuni' ? 'PayuniCallback' : 'PayuniUniEmbedCallback';
		require $base . $domain . '/Http/' . $callback . '.php';
	}
	class TestGateway extends \J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway {
		public int $renders = 0;
		public function __construct() {}
		public static function get_settings(bool $with_default = true): array { return []; }
		protected function before_order_received(WC_Order $order): void { ++$this->renders; }
	}
	$count = 0;
	function check(bool $condition, string $label): void {
		global $count;
		if (!$condition) { throw new \RuntimeException($label); }
		++$count;
	}
	$inner = ['Status' => 'SUCCESS', 'MerID' => 'TEST_MERCHANT', 'MerTradeNo' => 'synthetic-order', 'TradeNo' => 'synthetic-trade', 'TradeStatus' => 1, 'TradeAmt' => '100', 'Gateway' => '9'];
	$outer = ['Status' => 'SUCCESS', 'MerID' => 'TEST_MERCHANT', 'EncryptInfo' => 'synthetic', 'HashInfo' => str_repeat('a', 64)];
	foreach (['Payuni', 'PayuniUniEmbed'] as $domain) {
		$manager = 'J7\\PowerCheckout\\Domains\\Payment\\' . $domain . '\\Managers\\StatusManager';
		$callback = 'J7\\PowerCheckout\\Domains\\Payment\\' . $domain . '\\Http\\' . ($domain === 'Payuni' ? 'PayuniCallback' : 'PayuniUniEmbedCallback');
		foreach (['manager', 'callback'] as $entry) {
			$invoke = function (array $data) use ($manager, $callback, $entry, $outer): void {
				\J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniCrypto::$inner = $data;
				if ($entry === 'manager') { (new $manager($data, $GLOBALS['test_order']))->update_order_status(); }
				else { (new $callback())->handle_notify($outer); }
			};
			foreach ([false, true] as $virtual) {
				$test_order = new WC_Order('pending', $virtual);
				$invoke($inner);
				check($test_order->status === 'processing' && $test_order->completions === 1 && $test_order->details === 1 && $test_order->notes === 1 && $test_order->updates === 1, "$domain/$entry initial payment");
				$invoke($inner);
				check($test_order->completions === 1 && $test_order->details === 1 && $test_order->notes === 1 && $test_order->updates === 1, "$domain/$entry repeated payment");
			}
			foreach (['processing', 'completed', 'refunded', 'cancelled', 'custom-paid'] as $status) {
				$test_order = new WC_Order($status);
				$invoke($inner);
				check($test_order->status === $status && $test_order->details + $test_order->notes + $test_order->completions + $test_order->updates === 0, "$domain/$entry settled order");
			}
			$test_order = new WC_Order();
			$test_order->transaction = 'synthetic-trade';
			$invoke($inner);
			check($test_order->details + $test_order->notes + $test_order->completions + $test_order->updates === 0, "$domain/$entry same transaction");
			$test_order->transaction = 'different-trade';
			$invoke($inner);
			check($test_order->completions === 1, "$domain/$entry different transaction");
			$test_order = new WC_Order();
			$invoke(array_replace($inner, ['TradeAmt' => '101']));
			check($test_order->completions === 0 && $test_order->details === 0 && $test_order->status === 'pending', "$domain/$entry amount check");
			$test_order = new WC_Order('refunded');
			$invoke(array_replace($inner, ['TradeStatus' => 0]));
			check($test_order->details + $test_order->notes + $test_order->completions + $test_order->updates === 0, "$domain/$entry late unpaid result");
		}
	}
	$wp = (object) ['query_vars' => ['order-received' => 1]];
	$test_wc = (object) ['cart' => new class {
		public int $empties = 0;
		public function empty_cart(): void { ++$this->empties; }
	}];
	foreach ([null, '', 'wrong-key', ['wc_order_synthetic'], 'wc_order_synthetic'] as $key) {
		foreach (['pending', 'failed', 'processing', 'completed', 'refunded', 'cancelled'] as $status) {
			$_GET = $key === null ? [] : ['key' => $key];
			$test_order = new WC_Order($status);
			$gateway = new TestGateway();
			$test_wc->cart->empties = 0;
			$gateway->before_page_render();
			$allowed = $key === 'wc_order_synthetic' && in_array($status, ['pending', 'failed'], true);
			check($gateway->renders === (int) $allowed && $test_wc->cart->empties === (int) $allowed && ($gateway->order !== null) === $allowed, 'foreground access and side effects');
		}
	}
	$_GET = ['key' => 'wc_order_synthetic'];
	foreach ([false, new WC_Order()] as $test_order) {
		if ($test_order instanceof WC_Order) { $test_order->method = 'other_gateway'; }
		$gateway = new TestGateway();
		$test_wc->cart->empties = 0;
		$gateway->before_page_render();
		check($gateway->renders === 0 && $test_wc->cart->empties === 0, 'missing order or different gateway');
	}
	echo "PASS $count checks\n";
}
