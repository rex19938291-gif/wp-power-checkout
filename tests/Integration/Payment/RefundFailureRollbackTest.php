<?php
/**
 * 退款 API 失敗收尾（AbstractPaymentGateway::fail_gateway_refund）整合測試
 *
 * 背景：SLP / ECPG / PAYUNi UPP / PAYUNi UNi Embed / PayNow 五家 gateway 在退款 API 失敗時，
 * 修正前只做 ROLLBACK + order note + 刪除 refund。全額退款時 wc_create_refund 會先把訂單改成 refunded
 * 才觸發 woocommerce_order_refunded（退款 API 在此才送出），所以失敗後訂單殘留 refunded 卻沒有任何退款記錄，
 * 失敗原因也到不了 REST /refund 的回應。現在統一走 fail_gateway_refund：
 *  1. order note「❌ {金流} 退款失敗：{原因}」
 *  2. 刪除 refund
 *  3. 全額退款：訂單從 refunded 回滾到退款前狀態（由 remember_status_before_refund 記住；查不到回 processing）
 *  4. record_refund_error：訂單 meta _pc_refund_error（error_code / raw_code / message / time），
 *     REST 以 consume_refund_error 取回（read-once）
 * PayNow 另有新行為：ATM 退款缺銀行三欄（bankCode / bankBranchCode / bankAccount）視同失敗（VALIDATION），
 * 不再只留 note 並保留 refund。
 *
 * 測試手法：一律走真實退款鏈
 *   WC()->payment_gateways()->init() → wc_create_refund(refund_payment=true)
 *   → process_refund 能力檢查 → 全額時訂單先改 refunded → woocommerce_order_refunded → gateway handler。
 * 失敗觸發（皆不連外）：
 *  - SLP：pre_http_request 回 SLP 錯誤 body / WP_Error（Requester 以 wp_remote_post 送出，不看 API_MODE）。
 *  - ECPG：缺 TradeNo（EcpgApiClient::refund 直接 throw）；或切非 mock + pre_http_request 回 TransCode / RtnCode 失敗。
 *  - PAYUNi UPP：切非 mock + pre_http_request 回外層 Status 非 SUCCESS；或 API_MODE=mock_fail。
 *  - PAYUNi UNi Embed：payuni_uni_embed_mock_do_action_response / _exception filter。
 *  - PayNow：paynow_mock_refund_response / paynow_mock_refund_exception filter。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Payment/RefundFailureRollbackTest.php --no-coverage"
 *
 * @group integration
 * @group payment
 * @group refund
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Domains\Payment\Ecpg\DTOs\EcpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Ecpg\Services\EcpgGateway;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto;
use J7\PowerCheckout\Domains\Payment\Paynow\DTOs\PaynowSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Paynow\Http\PaynowRestClient;
use J7\PowerCheckout\Domains\Payment\Paynow\Services\PaynowGateway;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowMetaKeys;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowTradeNo;
use J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Payuni\Services\PayuniUppGateway;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniTradeNo;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\UniDoActionClient;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Services\PayuniUniEmbedGateway;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedTradeNo;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys as PaymentMetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 退款 API 失敗收尾測試類別
 *
 * @group integration
 * @group payment
 * @group refund
 */
final class RefundFailureRollbackTest extends TestCase {

	/** @var string 退款失敗記錄 order meta key（AbstractPaymentGateway::REFUND_ERROR_META 為 protected，測試端複製一份） */
	private const REFUND_ERROR_META = '_pc_refund_error';

	/** @var string 綠界 ECPG 測試環境 HashKey */
	private const ECPAY_HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界 ECPG 測試環境 HashIV */
	private const ECPAY_HASH_IV = 'EkRm7iFT261dpevs';

	/** @var string PAYUNi 官方公開測試向量 HashKey（32 字元） */
	private const PAYUNI_HASH_KEY = '12345678901234567890123456789012';

	/** @var string PAYUNi 官方公開測試向量 HashIV（16 字元） */
	private const PAYUNI_HASH_IV = '1234567890123456';

	/** @var string SLP 在 order note / 回滾 note 使用的名稱（fail_gateway_refund 的 $label） */
	private const LABEL_SLP = 'Shopline Payment';

	/** @var string ECPG 在 order note / 回滾 note 使用的名稱 */
	private const LABEL_ECPG = '綠界 ECPay 站內付';

	/** @var string PAYUNi UPP 在 order note / 回滾 note 使用的名稱 */
	private const LABEL_UPP = 'PAYUNi';

	/** @var string PAYUNi UNi Embed 在 order note / 回滾 note 使用的名稱 */
	private const LABEL_UNI = 'PAYUNi UNi Embed';

	/** @var string PayNow 在 order note / 回滾 note 使用的名稱 */
	private const LABEL_PAYNOW = 'PayNow';

	/** @var string|false 測試開始前的 API_MODE 環境變數（tear_down 還原用） */
	private string|false $original_api_mode = false;

	/** @var array<string, mixed>|\WP_Error|null 命中攔截條件時回傳的假 HTTP 回應 */
	private array|\WP_Error|null $fake_http_response = null;

	/** @var string 只攔截 URL 含此片段的請求（其餘一律擋下，測試不連外） */
	private string $fake_http_url_fragment = '';

	/** @var array<int, string> 已被攔截並回假回應的請求 URL */
	private array $requested_urls = [];

	/** 每次測試前：保存原 API_MODE 後再初始化 */
	public function set_up(): void {
		$this->original_api_mode      = \getenv( 'API_MODE' );
		$this->fake_http_response     = null;
		$this->fake_http_url_fragment = '';
		$this->requested_urls         = [];
		parent::set_up();
	}

	/** 啟用五家 gateway（test 模式 + 公開測試憑證），預設 API_MODE=mock */
	protected function configure_dependencies(): void {
		$this->set_api_mode( 'mock' );

		ProviderUtils::update_option(
			RedirectGateway::ID,
			[
				'enabled' => 'yes',
				'mode'    => 'test',
			]
		);
		ProviderUtils::update_option(
			EcpgGateway::ID,
			[
				'enabled'    => 'yes',
				'mode'       => 'test',
				'merchantId' => '3002607',
				'hashKey'    => self::ECPAY_HASH_KEY,
				'hashIv'     => self::ECPAY_HASH_IV,
			]
		);
		ProviderUtils::update_option(
			PayuniSettingsDTO::ID,
			[
				'enabled'     => 'yes',
				'mode'        => 'test',
				'merchant_id' => 'TEST_MER_001',
				'hash_key'    => self::PAYUNI_HASH_KEY,
				'hash_iv'     => self::PAYUNI_HASH_IV,
			]
		);
		ProviderUtils::update_option(
			PayuniUniEmbedSettingsDTO::ID,
			[
				'enabled'       => 'yes',
				'mode'          => 'test',
				'merchant_id'   => 'UNI_EMBED_TEST_001',
				'hash_key'      => self::PAYUNI_HASH_KEY,
				'hash_iv'       => self::PAYUNI_HASH_IV,
				'iframe_domain' => 'https://localhost',
			]
		);
		ProviderUtils::update_option(
			PaynowSettingsDTO::ID,
			[
				'enabled'     => 'yes',
				'mode'        => 'test',
				'public_key'  => 'test_public_key_refund_failure',
				'private_key' => 'test_private_key_refund_failure',
			]
		);

		EcpgSettingsDTO::reset();
		PaynowSettingsDTO::reset();
	}

	/** 每次測試後：移除 HTTP 攔截 / mock filter、重置單例並還原 API_MODE（不論測試成敗） */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		\remove_all_filters( UniDoActionClient::FILTER_MOCK_RESPONSE );
		\remove_all_filters( UniDoActionClient::FILTER_MOCK_EXCEPTION );
		\remove_all_filters( PaynowRestClient::FILTER_MOCK_REFUND );
		\remove_all_filters( PaynowRestClient::FILTER_MOCK_REFUND_EXCEPTION );

		if ( false === $this->original_api_mode ) {
			\putenv( 'API_MODE' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		} else {
			\putenv( "API_MODE={$this->original_api_mode}" ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		}

		EcpgSettingsDTO::reset();
		PaynowSettingsDTO::reset();

		parent::tear_down();
	}

	// ========== Helpers：環境與 HTTP 攔截 ==========

	/**
	 * 切換 API_MODE 環境變數（mock / mock_fail / sandbox）
	 *
	 * @param string $mode API_MODE 值
	 * @return void
	 */
	private function set_api_mode( string $mode ): void {
		\putenv( "API_MODE={$mode}" ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
	}

	/**
	 * 攔截 URL 含指定片段的 HTTP 請求並回假回應（其餘外部請求一律擋下）
	 *
	 * @param string                        $url_fragment URL 片段（例：/trade/refund/create）
	 * @param array<string, mixed>|\WP_Error $response     假回應（WP_Error 用來模擬連線失敗）
	 * @return void
	 */
	private function use_fake_http( string $url_fragment, array|\WP_Error $response ): void {
		$this->fake_http_url_fragment = $url_fragment;
		$this->fake_http_response     = $response;
		\add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
	}

	/**
	 * pre_http_request 攔截器：命中片段回假回應並記錄 URL，其餘一律回 WP_Error（測試不連外）
	 *
	 * @param false|array<string, mixed>|\WP_Error $preempt     既有 preempt 值
	 * @param array<string, mixed>                 $parsed_args 請求參數
	 * @param string                               $url         請求 URL
	 * @return array<string, mixed>|\WP_Error
	 */
	public function intercept_http( $preempt, array $parsed_args, string $url ) {
		if ( '' === $this->fake_http_url_fragment || ! \str_contains( $url, $this->fake_http_url_fragment ) || null === $this->fake_http_response ) {
			return new \WP_Error( 'http_blocked_in_test', "測試中禁止外部請求：{$url}" );
		}

		$this->requested_urls[] = $url;
		return $this->fake_http_response;
	}

	/**
	 * 組出 HTTP 200 + JSON body 的假回應
	 *
	 * @param array<string, mixed> $body 回應 body（會 JSON 編碼）
	 * @return array<string, mixed>
	 */
	private function json_http_response( array $body ): array {
		return [
			'headers'  => [ 'content-type' => 'application/json' ],
			'body'     => (string) \wp_json_encode( $body ),
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	// ========== Helpers：各金流的已付款訂單 fixture ==========

	/**
	 * 建立 SLP 已付款訂單（LINE Pay：支援全額 / 部分退款），付款明細結構同 LinePayTest
	 *
	 * @param string $status 付款後的訂單狀態
	 * @param float  $total  訂單金額
	 * @return \WC_Order
	 */
	private function create_slp_order( string $status = 'processing', float $total = 1000.0 ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => $status,
				'payment_method' => RedirectGateway::ID,
				'total'          => $total,
			]
		);

		( new PaymentMetaKeys( $order ) )->update_payment_detail(
			[
				'referenceOrderId' => 'REF_RFR_' . $order->get_id(),
				'tradeOrderId'     => 'TRADE_RFR_' . $order->get_id(),
				'status'           => 'SUCCEEDED',
				'order'            => [
					'merchantId'       => 'MERCHANT_TEST',
					'referenceOrderId' => 'REF_RFR_' . $order->get_id(),
					'createTime'       => \time(),
					'amount'           => [
						'value'    => (int) ( $total * 100 ),
						'currency' => 'TWD',
					],
					'customer'         => [
						'referenceCustomerId' => 'CUSTOMER_RFR_001',
						'customerId'          => 'SLP_CUSTOMER_RFR_001',
					],
				],
				'payment'          => [
					'paymentMethod'   => 'LinePay',
					'paymentBehavior' => 'Regular',
					'paidAmount'      => [
						'value'    => (int) ( $total * 100 ),
						'currency' => 'TWD',
					],
				],
			]
		);

		return $order;
	}

	/**
	 * 建立 ECPG 信用卡已付款訂單
	 *
	 * @param string $status        付款後的訂單狀態
	 * @param float  $total         訂單金額
	 * @param bool   $with_trade_no 是否寫入綠界 TradeNo（false = 缺 TradeNo，EcpgApiClient::refund 會 throw）
	 * @return \WC_Order
	 */
	private function create_ecpg_order( string $status = 'processing', float $total = 1000.0, bool $with_trade_no = true ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => $status,
				'payment_method' => EcpgGateway::ID,
				'total'          => $total,
			]
		);

		$detail = [ 'PaymentType' => 'Credit_CreditCard' ];
		if ( $with_trade_no ) {
			$detail['TradeNo'] = '2609241709159574';
		}

		$meta_keys = new EcpayMetaKeys( $order );
		$meta_keys->update_trade_no( 'MTN' . $order->get_id() );
		$meta_keys->update_payment_detail( $detail );

		return $order;
	}

	/**
	 * 建立 PAYUNi UPP 信用卡已付款訂單（PaymentType=1）
	 *
	 * @param string $status 付款後的訂單狀態
	 * @param float  $total  訂單金額
	 * @return \WC_Order
	 */
	private function create_upp_order( string $status = 'processing', float $total = 1000.0 ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => $status,
				'payment_method' => PayuniUppGateway::ID,
				'total'          => $total,
			]
		);

		$meta_keys = new PayuniMetaKeys( $order );
		$meta_keys->update_trade_no( PayuniTradeNo::generate( $order->get_id() ) );
		$meta_keys->update_payment_detail(
			[
				'Status'      => 'SUCCESS',
				'MerTradeNo'  => PayuniTradeNo::generate( $order->get_id() ),
				'TradeNo'     => 'UNI20260712001',
				'TradeAmt'    => (string) (int) $total,
				'TradeStatus' => '1',
				'PaymentType' => '1',
			]
		);

		return $order;
	}

	/**
	 * 建立 PAYUNi UNi Embed 信用卡已付款訂單（PaymentType=1，Gateway=9）
	 *
	 * @param string $status 付款後的訂單狀態
	 * @param float  $total  訂單金額
	 * @return \WC_Order
	 */
	private function create_uni_embed_order( string $status = 'processing', float $total = 1000.0 ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => $status,
				'payment_method' => PayuniUniEmbedGateway::ID,
				'total'          => $total,
			]
		);

		$mer_trade_no = PayuniUniEmbedTradeNo::generate( $order->get_id() );
		$meta_keys    = new PayuniUniEmbedMetaKeys( $order );
		$meta_keys->update_trade_no( $mer_trade_no );
		$meta_keys->update_payment_detail(
			[
				'Status'      => 'SUCCESS',
				'MerTradeNo'  => $mer_trade_no,
				'TradeNo'     => 'UNI20260712002',
				'TradeAmt'    => (string) (int) $total,
				'TradeStatus' => '1',
				'PaymentType' => '1',
				'Gateway'     => PayuniUniEmbedGateway::GATEWAY_CODE,
			]
		);

		return $order;
	}

	/**
	 * 建立 PayNow 已付款訂單（TWD）
	 *
	 * @param string $payment_type PayNow PaymentType（CreditCard / ATM / ...）
	 * @param string $status       付款後的訂單狀態
	 * @param float  $total        訂單金額
	 * @return \WC_Order
	 */
	private function create_paynow_order( string $payment_type = 'CreditCard', string $status = 'processing', float $total = 1000.0 ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => $status,
				'payment_method' => PaynowGateway::ID,
				'total'          => $total,
			]
		);

		// PayNow 僅支援 TWD；store 預設幣別為 USD，須顯式覆寫
		$order->set_currency( 'TWD' );
		$order->save();

		$intent_id = 'pp_rfr_' . $order->get_id();
		$meta_keys = new PaynowMetaKeys( $order );
		$meta_keys->update_trade_no( PaynowTradeNo::generate( $order->get_id() ) );
		$meta_keys->update_payment_intent_id( $intent_id );
		$meta_keys->update_payment_detail(
			[
				'PaymentIntentId' => $intent_id,
				'PaymentType'     => $payment_type,
				'Amount'          => (int) $total,
				'Status'          => 'success',
			]
		);

		return $order;
	}

	// ========== Helpers：各金流 API 的 mock ==========

	/**
	 * UNi Embed：DoAction 回外層 Status 非 SUCCESS（parse_response 會 throw，訊息含 Status=ERROR）
	 *
	 * @return void
	 */
	private function mock_uni_embed_refund_status_error(): void {
		\add_filter(
			UniDoActionClient::FILTER_MOCK_RESPONSE,
			static fn(): array => [
				'Status'  => 'ERROR',
				'Message' => '交易已結帳，無法退款（測試）',
			],
			10,
			2
		);
	}

	/**
	 * UNi Embed：DoAction 直接拋例外（模擬連線層失敗，無 PAYUNi Status）
	 *
	 * @return void
	 */
	private function mock_uni_embed_refund_exception(): void {
		\add_filter( UniDoActionClient::FILTER_MOCK_EXCEPTION, '__return_true' );
	}

	/**
	 * UNi Embed：DoAction 回 SUCCESS
	 *
	 * @return void
	 */
	private function mock_uni_embed_refund_success(): void {
		\add_filter(
			UniDoActionClient::FILTER_MOCK_RESPONSE,
			static fn(): array => [
				'Status'    => 'SUCCESS',
				'Message'   => '退款成功',
				'TradeNo'   => 'UNI20260712002',
				'CloseType' => '2',
			],
			10,
			2
		);
	}

	/**
	 * PayNow：退款 API 拋例外（模擬連線失敗）
	 *
	 * @return void
	 */
	private function mock_paynow_refund_exception(): void {
		\add_filter( PaynowRestClient::FILTER_MOCK_REFUND_EXCEPTION, '__return_true' );
	}

	/**
	 * PayNow：退款 API 回 success，並累計被呼叫的次數
	 *
	 * @param int $api_calls 被呼叫次數（by reference，供測試斷言「API 有沒有被打」）
	 * @return void
	 */
	private function mock_paynow_refund_success( int &$api_calls ): void {
		\add_filter(
			PaynowRestClient::FILTER_MOCK_REFUND,
			static function () use ( &$api_calls ): array {
				++$api_calls;
				return [
					'status'  => 200,
					'type'    => 'success',
					'message' => '退款成功',
					'result'  => [
						'id'     => 'rf_rfr_ok_001',
						'type'   => 'success',
						'amount' => 1500,
					],
				];
			}
		);
	}

	// ========== Helpers：走真實退款鏈 ==========

	/**
	 * 以 wc_create_refund(refund_payment=true) 走完整退款鏈
	 *
	 * 鏈：process_refund 能力檢查 → 全額時訂單先改 refunded → woocommerce_order_refunded → gateway handler。
	 * 全程不手動呼叫 handler，所以 remember_status_before_refund 與 fail_gateway_refund 的串接也一併受測。
	 *
	 * @param \WC_Order $order                  訂單
	 * @param float     $amount                 退款金額
	 * @param bool      $forget_status_listener 是否移除「記住退款前狀態」的 listener（驗證查不到時的 fallback）
	 * @return \WC_Order_Refund|\WP_Error
	 */
	private function create_gateway_refund( \WC_Order $order, float $amount, bool $forget_status_listener = false ) {
		WC()->payment_gateways()->init(); // 載入 gateway（註冊 woocommerce_order_refunded / order_status_changed handler）

		if ( $forget_status_listener ) {
			\remove_action( 'woocommerce_order_status_changed', [ AbstractPaymentGateway::class, 'remember_status_before_refund' ], 10 );
		}

		return \wc_create_refund(
			[
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'reason'         => '退款失敗收尾測試',
				'refund_payment' => true,
			]
		);
	}

	/**
	 * 走真實退款鏈並斷言「確實進到 gateway handler 且 refund 被刪除」
	 *
	 * process_refund 通過才會建立 refund 並觸發 handler；若回 WP_Error 代表 fixture 沒讓 process_refund 通過，
	 * 測試的前提不成立，直接 fail 並帶出原因。
	 *
	 * @param \WC_Order $order                  訂單
	 * @param float     $amount                 退款金額
	 * @param bool      $forget_status_listener 是否移除「記住退款前狀態」的 listener
	 * @return \WC_Order_Refund 已被 handler 刪除的 refund（只剩物件，DB 已無）
	 */
	private function refund_expecting_gateway_failure( \WC_Order $order, float $amount, bool $forget_status_listener = false ): \WC_Order_Refund {
		$refund = $this->create_gateway_refund( $order, $amount, $forget_status_listener );

		$this->assertInstanceOf(
			\WC_Order_Refund::class,
			$refund,
			'process_refund 應通過（回 true）才會進到 gateway handler：' . ( $refund instanceof \WP_Error ? $refund->get_error_message() : '' )
		);
		$this->assertFalse( \wc_get_order( $refund->get_id() ), '退款 API 失敗後 refund 應被 handler 刪除' );

		return $refund;
	}

	// ========== Helpers：斷言 ==========

	/**
	 * 斷言全額退款失敗已收尾：refund 不殘留、訂單回滾為退款前狀態（非 refunded）、失敗 note 與回滾 note 皆在
	 *
	 * @param \WC_Order $order           訂單
	 * @param string    $expected_status 預期回滾後的狀態（不含 wc- 前綴）
	 * @param string    $label           金流名稱（fail_gateway_refund 的 $label）
	 * @return void
	 */
	private function assert_full_refund_rolled_back( \WC_Order $order, string $expected_status, string $label ): void {
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );

		$this->assertCount( 0, $fresh->get_refunds(), '退款失敗不得殘留 refund 記錄' );
		$this->assertNotSame( 'refunded', $fresh->get_status(), 'API 失敗時訂單不得停在 refunded' );
		$this->assertSame( $expected_status, $fresh->get_status(), "訂單應回滾為退款前狀態 {$expected_status}" );
		$this->assert_order_note_contains( $fresh, "❌ {$label} 退款失敗" );
		$this->assert_order_note_contains( $fresh, "{$label} 退款 API 失敗，訂單狀態回滾" );
	}

	/**
	 * 斷言部分退款失敗已收尾：refund 不殘留、訂單狀態不變、有失敗 note、且沒有多餘的「狀態回滾」動作
	 *
	 * @param \WC_Order $order           訂單
	 * @param string    $expected_status 預期狀態（部分退款不會推到 refunded，應等於退款前狀態）
	 * @param string    $label           金流名稱
	 * @return void
	 */
	private function assert_partial_refund_failed( \WC_Order $order, string $expected_status, string $label ): void {
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );

		$this->assertCount( 0, $fresh->get_refunds(), '退款失敗不得殘留 refund 記錄' );
		$this->assertSame( $expected_status, $fresh->get_status(), '部分退款失敗不應改變訂單狀態' );
		$this->assert_order_note_contains( $fresh, "❌ {$label} 退款失敗" );

		foreach ( $this->get_note_contents( $order->get_id() ) as $content ) {
			$this->assertStringNotContainsString( '訂單狀態回滾', $content, '部分退款訂單本來就不在 refunded，不應出現狀態回滾 note' );
		}
	}

	/**
	 * 取得訂單所有 order note 內容
	 *
	 * @param int $order_id 訂單 ID
	 * @return array<int, string>
	 */
	private function get_note_contents( int $order_id ): array {
		$notes = \wc_get_order_notes( [ 'order_id' => $order_id ] );
		return \array_values( \array_map( static fn( $note ): string => (string) $note->content, $notes ) );
	}

	/**
	 * 讀取訂單上的退款失敗記錄（_pc_refund_error）
	 *
	 * @param int $order_id 訂單 ID
	 * @return mixed
	 */
	private function get_refund_error_meta( int $order_id ): mixed {
		$fresh = \wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		return $fresh->get_meta( self::REFUND_ERROR_META );
	}

	/**
	 * 斷言已記錄退款失敗：結構完整、error_code 符合、訊息含指定片段
	 *
	 * @param int       $order_id         訂單 ID
	 * @param ErrorCode $code             預期的正規化錯誤碼
	 * @param string    $message_fragment 失敗訊息應包含的片段
	 * @return void
	 */
	private function assert_refund_error_recorded( int $order_id, ErrorCode $code, string $message_fragment ): void {
		$error = $this->get_refund_error_meta( $order_id );

		$this->assertIsArray( $error, '應記錄退款失敗（_pc_refund_error）' );
		$this->assertSame( $code->value, $error['error_code'] ?? null );
		$this->assertStringContainsString( $message_fragment, (string) ( $error['message'] ?? '' ) );
		$this->assertGreaterThan( 0, (int) ( $error['time'] ?? 0 ), '失敗記錄應帶時間戳' );
	}

	/**
	 * 斷言退款失敗記錄的金流原始錯誤碼
	 *
	 * @param int         $order_id 訂單 ID
	 * @param string|null $expected 預期 raw_code（null = 金流沒給原始錯誤碼）
	 * @return void
	 */
	private function assert_refund_error_raw_code( int $order_id, ?string $expected ): void {
		$error = $this->get_refund_error_meta( $order_id );

		$this->assertIsArray( $error );
		$this->assertArrayHasKey( 'raw_code', $error );
		if ( null === $expected ) {
			$this->assertNull( $error['raw_code'], '沒有金流原始錯誤碼時 raw_code 應為 null' );
			return;
		}
		$this->assertSame( $expected, $error['raw_code'] );
	}

	// ========== Smoke ==========

	/**
	 * 任一 gateway 建構後都會註冊「記住退款前狀態」listener（self::class，全部 gateway 共用一個）
	 *
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_gateway建構後註冊退款前狀態記憶listener(): void {
		// Given / When
		new PayuniUppGateway();

		// Then
		$this->assertNotFalse(
			\has_action( 'woocommerce_order_status_changed', [ AbstractPaymentGateway::class, 'remember_status_before_refund' ] ),
			'gateway 建構子應註冊 woocommerce_order_status_changed → remember_status_before_refund'
		);
	}

	// ========== Shopline Payment ==========

	/**
	 * SLP：全額退款 API 失敗 → refund 刪除、訂單回滾為 processing、記錄失敗原因（raw_code = SLP 錯誤碼）
	 *
	 * 修正前：SLP 的 catch 只 ROLLBACK + note + 刪 refund，訂單殘留 refunded。
	 *
	 * @test
	 * @group error
	 */
	public function test_SLP全額退款失敗時訂單狀態回滾且記錄失敗原因(): void {
		// Given: 已付款 LINE Pay 訂單；SLP 退款端點回業務錯誤（未收錄的錯誤碼 → 訊息取自 msg、例外 code = 9999）
		$order = $this->create_slp_order();
		$this->use_fake_http(
			'/trade/refund/create',
			$this->json_http_response(
				[
					'code' => '9999',
					'msg'  => '可退款餘額不足（測試）',
				]
			)
		);

		// When: 全額退款
		$this->refund_expecting_gateway_failure( $order, 1000.0 );

		// Then: 確實打到 SLP 退款端點一次，訂單回滾、失敗原因被記錄
		$this->assertCount( 1, $this->requested_urls );
		$this->assertStringContainsString( '/trade/refund/create', $this->requested_urls[0] );
		$this->assert_full_refund_rolled_back( $order, 'processing', self::LABEL_SLP );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, '可退款餘額不足（測試）' );
		$this->assert_refund_error_raw_code( $order->get_id(), '9999' );
	}

	/**
	 * SLP：部分退款 API 失敗 → 狀態維持 processing（本來就不會被推到 refunded），仍刪 refund 並記錄原因
	 *
	 * @test
	 * @group edge
	 */
	public function test_SLP部分退款失敗時狀態不變且記錄失敗原因(): void {
		// Given
		$order = $this->create_slp_order();
		$this->use_fake_http(
			'/trade/refund/create',
			$this->json_http_response(
				[
					'code' => '9999',
					'msg'  => '可退款餘額不足（測試）',
				]
			)
		);

		// When: 部分退款 300
		$this->refund_expecting_gateway_failure( $order, 300.0 );

		// Then
		$this->assert_partial_refund_failed( $order, 'processing', self::LABEL_SLP );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, '可退款餘額不足（測試）' );
		$this->assert_refund_error_raw_code( $order->get_id(), '9999' );
	}

	/**
	 * SLP：連線失敗（WP_Error，例外 code = 0）→ 回滾並記錄，但 raw_code 為 null
	 *
	 * @test
	 * @group error
	 */
	public function test_SLP退款連線失敗時回滾且raw_code為null(): void {
		// Given: 退款端點連線逾時
		$order = $this->create_slp_order();
		$this->use_fake_http( '/trade/refund/create', new \WP_Error( 'http_request_failed', '連線逾時（測試）' ) );

		// When
		$this->refund_expecting_gateway_failure( $order, 1000.0 );

		// Then
		$this->assert_full_refund_rolled_back( $order, 'processing', self::LABEL_SLP );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, '連線逾時（測試）' );
		$this->assert_refund_error_raw_code( $order->get_id(), null );
	}

	// ========== 綠界 ECPG ==========

	/**
	 * ECPG：全額退款缺 TradeNo（EcpgApiClient::refund 直接 throw）→ 回滾為 processing、記錄原因
	 *
	 * @test
	 * @group error
	 */
	public function test_ECPG全額退款缺TradeNo時訂單狀態回滾且記錄失敗原因(): void {
		// Given: 信用卡明細缺 TradeNo
		$order = $this->create_ecpg_order( 'processing', 1000.0, false );

		// When
		$this->refund_expecting_gateway_failure( $order, 1000.0 );

		// Then: 例外訊息沒有 RtnCode / TransCode → raw_code 為 null
		$this->assert_full_refund_rolled_back( $order, 'processing', self::LABEL_ECPG );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, '缺少 TradeNo' );
		$this->assert_refund_error_raw_code( $order->get_id(), null );
	}

	/**
	 * ECPG：訂單退款前是 completed → 失敗後回滾為 completed，而不是寫死的 processing
	 *
	 * @test
	 * @group error
	 */
	public function test_ECPG已完成訂單全額退款失敗時回滾為completed而非processing(): void {
		// Given: 已完成的訂單（wc_create_refund 會先 completed → refunded）
		$order = $this->create_ecpg_order( 'completed', 1000.0, false );
		$this->assert_order_status( $order, 'completed' );

		// When
		$this->refund_expecting_gateway_failure( $order, 1000.0 );

		// Then: 還原為 completed
		$this->assert_full_refund_rolled_back( $order, 'completed', self::LABEL_ECPG );
	}

	/**
	 * ECPG：傳輸層失敗（TransCode≠1）→ raw_code 取自訊息的 TransCode
	 *
	 * @test
	 * @group error
	 */
	public function test_ECPG傳輸層失敗時raw_code為TransCode(): void {
		// Given: 切非 mock，DoAction 端點回 TransCode=3
		$order = $this->create_ecpg_order();
		$this->set_api_mode( 'sandbox' );
		$this->use_fake_http(
			'/Credit/DoAction',
			$this->json_http_response(
				[
					'TransCode' => 3,
					'TransMsg'  => 'AES 解密失敗（測試）',
				]
			)
		);

		// When
		$this->refund_expecting_gateway_failure( $order, 1000.0 );

		// Then
		$this->assertCount( 1, $this->requested_urls );
		$this->assert_full_refund_rolled_back( $order, 'processing', self::LABEL_ECPG );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, 'TransCode=3' );
		$this->assert_refund_error_raw_code( $order->get_id(), '3' );
	}

	/**
	 * ECPG：業務層失敗（TransCode=1、解密後 RtnCode≠1）→ raw_code 取自訊息的 RtnCode
	 *
	 * @test
	 * @group error
	 */
	public function test_ECPG業務層失敗時raw_code為RtnCode(): void {
		// Given: 切非 mock，DoAction 端點回 TransCode=1 + AES 加密的 RtnCode=10100050
		$order  = $this->create_ecpg_order();
		$crypto = new AesCrypto( self::ECPAY_HASH_KEY, self::ECPAY_HASH_IV );
		$this->set_api_mode( 'sandbox' );
		$this->use_fake_http(
			'/Credit/DoAction',
			$this->json_http_response(
				[
					'TransCode' => 1,
					'TransMsg'  => 'Success',
					'Data'      => $crypto->encrypt(
						[
							'RtnCode' => 10100050,
							'RtnMsg'  => '退款失敗（測試）',
						]
					),
				]
			)
		);

		// When
		$this->refund_expecting_gateway_failure( $order, 1000.0 );

		// Then
		$this->assertCount( 1, $this->requested_urls );
		$this->assert_full_refund_rolled_back( $order, 'processing', self::LABEL_ECPG );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, 'RtnCode=10100050' );
		$this->assert_refund_error_raw_code( $order->get_id(), '10100050' );
	}

	/**
	 * ECPG：部分退款失敗 → 狀態維持 processing，仍刪 refund 並記錄原因
	 *
	 * @test
	 * @group edge
	 */
	public function test_ECPG部分退款失敗時狀態不變且記錄失敗原因(): void {
		// Given
		$order = $this->create_ecpg_order( 'processing', 1000.0, false );

		// When
		$this->refund_expecting_gateway_failure( $order, 300.0 );

		// Then
		$this->assert_partial_refund_failed( $order, 'processing', self::LABEL_ECPG );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, '缺少 TradeNo' );
	}

	// ========== PAYUNi UPP ==========

	/**
	 * UPP：全額退款失敗（外層 Status 非 SUCCESS）→ 回滾為 processing，raw_code 取自 PAYUNi 的 Status
	 *
	 * @test
	 * @group error
	 */
	public function test_UPP全額退款失敗時訂單狀態回滾且raw_code為PAYUNi外層Status(): void {
		// Given: 切非 mock，/api/trade/close 回外層 Status=API10001
		$order = $this->create_upp_order();
		$this->set_api_mode( 'sandbox' );
		$this->use_fake_http(
			'/api/trade/close',
			$this->json_http_response(
				[
					'Status'  => 'API10001',
					'Message' => '交易不存在（測試）',
				]
			)
		);

		// When
		$this->refund_expecting_gateway_failure( $order, 1000.0 );

		// Then
		$this->assertCount( 1, $this->requested_urls );
		$this->assert_full_refund_rolled_back( $order, 'processing', self::LABEL_UPP );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, 'Status=API10001' );
		$this->assert_refund_error_raw_code( $order->get_id(), 'API10001' );
	}

	/**
	 * UPP：訂單退款前是 completed → 失敗後回滾為 completed（API_MODE=mock_fail 觸發失敗）
	 *
	 * @test
	 * @group error
	 */
	public function test_UPP已完成訂單全額退款失敗時回滾為completed(): void {
		// Given
		$order = $this->create_upp_order( 'completed' );
		$this->set_api_mode( 'mock_fail' );

		// When
		$this->refund_expecting_gateway_failure( $order, 1000.0 );

		// Then
		$this->assert_full_refund_rolled_back( $order, 'completed', self::LABEL_UPP );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, 'SUCCESS' );
	}

	/**
	 * UPP：部分退款失敗 → 狀態維持 processing，仍刪 refund 並記錄原因
	 *
	 * @test
	 * @group edge
	 */
	public function test_UPP部分退款失敗時狀態不變且記錄失敗原因(): void {
		// Given
		$order = $this->create_upp_order();
		$this->set_api_mode( 'mock_fail' );

		// When
		$this->refund_expecting_gateway_failure( $order, 300.0 );

		// Then
		$this->assert_partial_refund_failed( $order, 'processing', self::LABEL_UPP );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, 'SUCCESS' );
	}

	// ========== PAYUNi UNi Embed（static handle_payment_gateway_refund 覆寫） ==========

	/**
	 * UNi Embed：全額退款失敗 → 回滾為 processing，raw_code 取自 PAYUNi 的 Status
	 *
	 * @test
	 * @group error
	 */
	public function test_UNiEmbed全額退款失敗時訂單狀態回滾且raw_code為PAYUNi外層Status(): void {
		// Given
		$order = $this->create_uni_embed_order();
		$this->mock_uni_embed_refund_status_error();

		// When
		$this->refund_expecting_gateway_failure( $order, 1000.0 );

		// Then
		$this->assert_full_refund_rolled_back( $order, 'processing', self::LABEL_UNI );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, 'Status=ERROR' );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, '交易已結帳，無法退款（測試）' );
		$this->assert_refund_error_raw_code( $order->get_id(), 'ERROR' );
	}

	/**
	 * UNi Embed：訂單退款前是 on-hold → 失敗後回滾為 on-hold（還原的是退款前狀態，不是 processing / completed 其中之一）
	 *
	 * @test
	 * @group error
	 */
	public function test_UNiEmbed保留中訂單全額退款失敗時回滾為on_hold(): void {
		// Given: on-hold 訂單；DoAction 拋例外（無 PAYUNi Status → raw_code null）
		$order = $this->create_uni_embed_order( 'on-hold' );
		$this->mock_uni_embed_refund_exception();

		// When
		$this->refund_expecting_gateway_failure( $order, 1000.0 );

		// Then
		$this->assert_full_refund_rolled_back( $order, 'on-hold', self::LABEL_UNI );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, 'mock exception' );
		$this->assert_refund_error_raw_code( $order->get_id(), null );
	}

	/**
	 * UNi Embed：部分退款失敗 → 狀態維持 processing，仍刪 refund 並記錄原因
	 *
	 * @test
	 * @group edge
	 */
	public function test_UNiEmbed部分退款失敗時狀態不變且記錄失敗原因(): void {
		// Given
		$order = $this->create_uni_embed_order();
		$this->mock_uni_embed_refund_status_error();

		// When
		$this->refund_expecting_gateway_failure( $order, 300.0 );

		// Then
		$this->assert_partial_refund_failed( $order, 'processing', self::LABEL_UNI );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, 'Status=ERROR' );
		$this->assert_refund_error_raw_code( $order->get_id(), 'ERROR' );
	}

	/**
	 * UNi Embed：連續兩次全額退款都失敗 → 兩次都回滾到原本的 completed（失敗後不留下過期的「退款前狀態」）
	 *
	 * @test
	 * @group edge
	 */
	public function test_UNiEmbed連續兩次全額退款失敗皆回滾為原狀態(): void {
		// Given
		$order = $this->create_uni_embed_order( 'completed' );
		$this->mock_uni_embed_refund_exception();

		// When / Then: 第一次
		$this->refund_expecting_gateway_failure( $order, 1000.0 );
		$this->assert_full_refund_rolled_back( $order, 'completed', self::LABEL_UNI );

		// When / Then: 第二次（訂單已回到 completed，會再次 completed → refunded）
		$this->refund_expecting_gateway_failure( $order, 1000.0 );
		$this->assert_full_refund_rolled_back( $order, 'completed', self::LABEL_UNI );
	}

	/**
	 * 全額退款成功 → 不回滾：訂單維持 refunded、refund 保留、沒有失敗記錄（防止成功路徑被誤傷）
	 *
	 * @test
	 * @group happy
	 */
	public function test_UNiEmbed全額退款成功時不回滾且無失敗記錄(): void {
		// Given
		$order = $this->create_uni_embed_order();
		$this->mock_uni_embed_refund_success();

		// When
		$refund = $this->create_gateway_refund( $order, 1000.0 );

		// Then
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertInstanceOf( \WC_Order_Refund::class, \wc_get_order( $refund->get_id() ), '成功時 refund 應保留' );
		$this->assert_order_status( $order, 'refunded' );
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '退款成功' );
		$this->assertEmpty( $this->get_refund_error_meta( $order->get_id() ), '成功不得留下失敗記錄' );
		foreach ( $this->get_note_contents( $order->get_id() ) as $content ) {
			$this->assertStringNotContainsString( '訂單狀態回滾', $content );
			$this->assertStringNotContainsString( '❌', $content );
		}
	}

	/**
	 * consume_refund_error 為 read-once：第一次回正規化 WP_Error，第二次回 null 且 meta 已清除
	 *
	 * @test
	 * @group edge
	 */
	public function test_consume_refund_error只能取回一次(): void {
		// Given: UNi Embed 全額退款失敗，已記錄失敗原因
		$order = $this->create_uni_embed_order();
		$this->mock_uni_embed_refund_status_error();
		$this->refund_expecting_gateway_failure( $order, 1000.0 );
		$gateway = new PayuniUniEmbedGateway();

		// When: 第一次取回
		$first = $gateway->consume_refund_error( $order->get_id() );

		// Then: 正規化 WP_Error，帶 raw_code 與 provider
		$this->assertInstanceOf( \WP_Error::class, $first );
		$this->assertSame( ErrorCode::PROVIDER->value, $first->get_error_code() );
		$this->assertTrue( NormalizedError::is_normalized_error( $first ) );
		$this->assertSame( 'ERROR', NormalizedError::get_raw_code( $first ) );
		$this->assertStringContainsString( '交易已結帳，無法退款（測試）', $first->get_error_message() );
		$data = $first->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( PayuniUniEmbedGateway::ID, $data['provider'] ?? null );

		// When: 第二次取回
		$second = $gateway->consume_refund_error( $order->get_id() );

		// Then: read-once，已被清除
		$this->assertNull( $second, '第二次呼叫應回 null（read-once）' );
		$this->assertEmpty( $this->get_refund_error_meta( $order->get_id() ) );
	}

	/**
	 * 沒有失敗記錄的訂單 / 不存在的訂單 → consume_refund_error 回 null
	 *
	 * @test
	 * @group edge
	 */
	public function test_沒有失敗記錄或訂單不存在時consume_refund_error回null(): void {
		// Given: 從未退款失敗的訂單
		$order   = $this->create_uni_embed_order();
		$gateway = new PayuniUniEmbedGateway();

		// When / Then
		$this->assertNull( $gateway->consume_refund_error( $order->get_id() ), '沒有失敗記錄應回 null' );
		$this->assertNull( $gateway->consume_refund_error( 999999999 ), '訂單不存在應回 null' );
	}

	// ========== PayNow ==========

	/**
	 * PayNow：全額退款 API 失敗 → 回滾為 processing、記錄原因（PayNow 沒有原始錯誤碼 → raw_code null）
	 *
	 * @test
	 * @group error
	 */
	public function test_PayNow全額退款API失敗時訂單狀態回滾且記錄失敗原因(): void {
		// Given
		$order = $this->create_paynow_order();
		$this->mock_paynow_refund_exception();

		// When
		$this->refund_expecting_gateway_failure( $order, 1000.0 );

		// Then
		$this->assert_full_refund_rolled_back( $order, 'processing', self::LABEL_PAYNOW );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, 'PayNow mock 連線失敗' );
		$this->assert_refund_error_raw_code( $order->get_id(), null );
	}

	/**
	 * PayNow：退款被拒絕（type=rejected）→ 失敗訊息帶出 RejectReason，訂單回滾
	 *
	 * @test
	 * @group error
	 */
	public function test_PayNow退款被拒絕時失敗原因含RejectReason且訂單回滾(): void {
		// Given: PayNow 回 rejected + RejectReason
		$order = $this->create_paynow_order( 'CreditCard', 'completed' );
		\add_filter(
			PaynowRestClient::FILTER_MOCK_REFUND,
			static fn(): array => [
				'status'  => 200,
				'type'    => 'success',
				'message' => '已受理',
				'result'  => [
					'id'           => 'rf_rfr_rejected_001',
					'type'         => 'rejected',
					'RejectReason' => '超過退款期限',
				],
			]
		);

		// When
		$this->refund_expecting_gateway_failure( $order, 1000.0 );

		// Then: 同時驗證 completed 還原與 RejectReason 透出
		$this->assert_full_refund_rolled_back( $order, 'completed', self::LABEL_PAYNOW );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, '退款遭拒絕' );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, '超過退款期限' );
	}

	/**
	 * PayNow：部分退款 API 失敗 → 狀態維持 processing，仍刪 refund 並記錄原因
	 *
	 * @test
	 * @group edge
	 */
	public function test_PayNow部分退款失敗時狀態不變且記錄失敗原因(): void {
		// Given
		$order = $this->create_paynow_order();
		$this->mock_paynow_refund_exception();

		// When
		$this->refund_expecting_gateway_failure( $order, 300.0 );

		// Then
		$this->assert_partial_refund_failed( $order, 'processing', self::LABEL_PAYNOW );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::PROVIDER, 'PayNow mock 連線失敗' );
	}

	/**
	 * PayNow（新行為）：ATM 全額退款缺銀行三欄 → 視同失敗：不打 API、refund 刪除、訂單回滾、錯誤碼 VALIDATION
	 *
	 * 修正前：只加 note 並保留 refund → WC 把可退餘額當成已歸零，補填銀行資料後也無法再發起退款，
	 * 全額時訂單還停在 refunded。
	 *
	 * @test
	 * @group error
	 */
	public function test_PayNowATM缺銀行資料全額退款時回滾且錯誤碼為VALIDATION(): void {
		// Given: ATM 訂單，未寫入 bankCode / bankBranchCode / bankAccount；退款 API 有掛成功 mock 以偵測「不該被呼叫」
		$order     = $this->create_paynow_order( 'ATM', 'processing', 1500.0 );
		$api_calls = 0;
		$this->mock_paynow_refund_success( $api_calls );

		// When
		$this->refund_expecting_gateway_failure( $order, 1500.0 );

		// Then
		$this->assertSame( 0, $api_calls, '缺銀行資料不得送出退款 API' );
		$this->assert_full_refund_rolled_back( $order, 'processing', self::LABEL_PAYNOW );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::VALIDATION, '銀行' );
		$this->assert_refund_error_raw_code( $order->get_id(), null );
	}

	/**
	 * PayNow（新行為）：ATM 部分退款缺銀行三欄 → 同樣視同失敗，refund 刪除（讓管理員補資料後可重試）、狀態不變
	 *
	 * @test
	 * @group edge
	 */
	public function test_PayNowATM缺銀行資料部分退款時狀態不變且refund被刪除(): void {
		// Given
		$order     = $this->create_paynow_order( 'ATM', 'processing', 1500.0 );
		$api_calls = 0;
		$this->mock_paynow_refund_success( $api_calls );

		// When: 部分退款 500
		$this->refund_expecting_gateway_failure( $order, 500.0 );

		// Then
		$this->assertSame( 0, $api_calls, '缺銀行資料不得送出退款 API' );
		$this->assert_partial_refund_failed( $order, 'processing', self::LABEL_PAYNOW );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::VALIDATION, '銀行' );
	}

	/**
	 * PayNow：ATM 只缺其中一欄（bankAccount 為空）也算缺銀行資料
	 *
	 * @test
	 * @group edge
	 */
	public function test_PayNowATM銀行資料只缺一欄時仍視為失敗(): void {
		// Given: bankCode / bankBranchCode 有值，bankAccount 缺
		$order = $this->create_paynow_order( 'ATM', 'processing', 1500.0 );
		$order->update_meta_data( PaynowGateway::META_REFUND_BANK_CODE, '013' );
		$order->update_meta_data( PaynowGateway::META_REFUND_BANK_BRANCH, '0001' );
		$order->save();
		$api_calls = 0;
		$this->mock_paynow_refund_success( $api_calls );

		// When
		$this->refund_expecting_gateway_failure( $order, 1500.0 );

		// Then
		$this->assertSame( 0, $api_calls );
		$this->assert_full_refund_rolled_back( $order, 'processing', self::LABEL_PAYNOW );
		$this->assert_refund_error_recorded( $order->get_id(), ErrorCode::VALIDATION, '銀行' );
	}

	/**
	 * PayNow：ATM 銀行三欄齊全 → 正常送出退款 API，成功不回滾（對照組，確認新守衛沒有誤擋）
	 *
	 * @test
	 * @group happy
	 */
	public function test_PayNowATM銀行資料齊全時退款成功不回滾(): void {
		// Given
		$order = $this->create_paynow_order( 'ATM', 'processing', 1500.0 );
		$order->update_meta_data( PaynowGateway::META_REFUND_BANK_CODE, '013' );
		$order->update_meta_data( PaynowGateway::META_REFUND_BANK_BRANCH, '0001' );
		$order->update_meta_data( PaynowGateway::META_REFUND_BANK_ACCT, '1234567890' );
		$order->save();
		$api_calls = 0;
		$this->mock_paynow_refund_success( $api_calls );

		// When
		$refund = $this->create_gateway_refund( $order, 1500.0 );

		// Then: API 送出一次、refund 保留、全額退款訂單為 refunded、無失敗記錄
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertSame( 1, $api_calls, '銀行資料齊全應送出一次退款 API' );
		$this->assertInstanceOf( \WC_Order_Refund::class, \wc_get_order( $refund->get_id() ), '成功時 refund 應保留' );
		$this->assert_order_status( $order, 'refunded' );
		$this->assertEmpty( $this->get_refund_error_meta( $order->get_id() ), '成功不得留下失敗記錄' );
		foreach ( $this->get_note_contents( $order->get_id() ) as $content ) {
			$this->assertStringNotContainsString( '訂單狀態回滾', $content );
		}
	}

	// ========== 跨 gateway：fallback ==========

	/**
	 * 查不到退款前狀態（狀態記憶 listener 沒掛）→ 全額退款失敗時 fallback 回滾為 processing
	 *
	 * @test
	 * @group edge
	 */
	public function test_查不到退款前狀態時全額退款失敗fallback回滾為processing(): void {
		// Given: completed 訂單；移除「記住退款前狀態」的 listener，模擬沒有記錄
		$order = $this->create_paynow_order( 'CreditCard', 'completed' );
		$this->mock_paynow_refund_exception();

		// When
		$this->refund_expecting_gateway_failure( $order, 1000.0, true );

		// Then: 沒有記錄就回 processing（至少不停在 refunded）
		$this->assert_full_refund_rolled_back( $order, 'processing', self::LABEL_PAYNOW );
	}
}
