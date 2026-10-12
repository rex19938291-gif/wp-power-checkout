<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Http;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\AioSettingsDTO;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Enums\RtnCode;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\CheckMacValueService;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Plugin;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Classes\WP;
use J7\WpUtils\Traits\SingletonTrait;

/**
 * 綠界 AIO 幕後通知接收（ReturnURL / PaymentInfoURL）
 *
 * 比照 ShoplinePayment\Http\WebHook 採 ApiBase + SingletonTrait。
 *
 * 協定鐵律：
 *  - CheckMacValue 以 hash_equals() timing-safe 驗章；不符仍回 1|OK（避免綠界重送風暴）
 *  - 冪等：以 MerchantTradeNo 為 key，已處理過則 skip
 *  - 回應必須是純文字 `1|OK`，HTTP 200（非 JSON）
 *
 * @see https://developers.ecpay.com.tw/?p=2878
 */
final class AioCallback extends ApiBase {
	use SingletonTrait;

	/** @var string 純文字成功回應（綠界協定） */
	private const RESPONSE_OK = '1|OK';

	/** @var string Namespace power-checkout/ecpay */
	protected $namespace = 'power-checkout/ecpay';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint'            => 'aio/return',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
		[
			'endpoint'            => 'aio/payment-info',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	// region REST callbacks（回純文字 1|OK）

	/**
	 * 付款結果通知（ReturnURL）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response 回應（純文字 1|OK）
	 */
	public function post_aio_return_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_params();
		try {
			$this->handle_return( $params );
		} catch ( \Throwable $e ) {
			Plugin::logger( '綠界 AIO ReturnURL 處理失敗', 'error', [ 'error' => $e->getMessage() ] );
		}
		return self::ok_response();
	}

	/**
	 * 取號結果通知（PaymentInfoURL）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response 回應（純文字 1|OK）
	 */
	public function post_aio_payment_info_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_params();
		try {
			$this->handle_payment_info( $params );
		} catch ( \Throwable $e ) {
			Plugin::logger( '綠界 AIO PaymentInfoURL 處理失敗', 'error', [ 'error' => $e->getMessage() ] );
		}
		return self::ok_response();
	}

	// endregion

	// region 處理邏輯（可獨立測試）

	/**
	 * 處理付款結果通知
	 *
	 * 驗章失敗 → 維持狀態、不更新明細（仍由呼叫端回 1|OK）。
	 * 訂單以 MerchantTradeNo 反查（含顧客重試付款前送出過的歷史編號）。
	 * 冪等：訂單已入帳（已付款 processing / completed，或已全額退款 refunded）→ 不再交給 StatusManager，
	 * 不覆寫付款明細、不回退狀態；若是「另一個編號」的付款成功（顧客重試後兩邊都付了）→ 加註重複付款警告。
	 *
	 * @param array<string, mixed> $params ReturnURL 通知參數
	 * @return void
	 */
	public function handle_return( array $params ): void {
		if ( ! $this->is_valid( $params ) ) {
			Plugin::logger( '綠界 AIO ReturnURL CheckMacValue 驗章失敗', 'warning', [ 'params' => $params ] );
			return;
		}

		$trade_no = (string) ( $params['MerchantTradeNo'] ?? '' );
		$order    = EcpayMetaKeys::get_order_by_trade_no( $trade_no );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( "找不到訂單，MerchantTradeNo: {$trade_no}" );
		}

		// 冪等：已入帳則不重複處理（重送不重複入帳；另一編號的付款成功則加註重複付款）
		if ( self::is_settled( $order ) ) {
			self::note_duplicate_payment( $order, $params, $trade_no );
			return;
		}

		( new StatusManager( $params, $order ) )->update_order_status();

		// 記憶卡號（Token 綁卡）：付款成功且綠界回傳 CardID 時存為 WC_Payment_Token_CC（冪等於內部處理）
		\J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\TokenService::save_token_from_payload( $order, $params );
	}

	/**
	 * 訂單是否已入帳（不得再由付款通知改寫狀態 / 明細）
	 *
	 * 已付款（processing / completed，即 is_paid）或已全額退款（refunded）。
	 * cancelled 不算：未付款訂單被 WC 逾時取消後，顧客仍可能用先前取得的 ATM / 超商代碼繳費，須能入帳。
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	private static function is_settled( \WC_Order $order ): bool {
		return $order->is_paid() || $order->has_status( 'refunded' );
	}

	/**
	 * 已入帳訂單又收到「另一個 MerchantTradeNo」的付款成功通知 → 加註重複付款警告（不改狀態 / 明細）
	 *
	 * 情境：顧客重試付款產生多個編號（例：先取 ATM 帳號、回來改刷卡付款成功），之後又用舊 ATM 帳號繳費。
	 * 兩筆都是真實付款，第二筆不能默默吞掉，須讓站方看到並至綠界後台核對 / 人工退款。
	 * 同編號重送（_pc_ecpay_trade_no 即為付款成功的編號）→ 正常冪等，不加註。
	 *
	 * @param \WC_Order            $order    訂單
	 * @param array<string, mixed> $params   ReturnURL 通知參數（已驗章）
	 * @param string               $trade_no 通知的 MerchantTradeNo
	 * @return void
	 */
	private static function note_duplicate_payment( \WC_Order $order, array $params, string $trade_no ): void {
		$rtn_code = (string) ( $params['RtnCode'] ?? '' );
		$settled  = ( new EcpayMetaKeys( $order ) )->get_trade_no();
		if ( ! RtnCode::is_paid_success( $rtn_code ) || $trade_no === $settled ) {
			return;
		}

		$order->add_order_note(
			WP::array_to_html(
				$params,
				[
					'title' => \sprintf(
						/* translators: 1: 本次通知的 MerchantTradeNo, 2: 訂單已記錄的付款編號 */
						\__( '⚠️ 疑似重複付款：此訂單已入帳（付款編號 %2$s），又收到 MerchantTradeNo %1$s 的付款成功通知。訂單狀態與付款明細未變更，請至綠界廠商後台核對，必要時人工退款。', 'power_checkout' ),
						$trade_no,
						'' !== $settled ? $settled : '-'
					),
				]
			)
		);
	}

	/**
	 * 處理取號結果通知（ATM/CVS/BARCODE）
	 *
	 * 驗章失敗 → 不寫入取號資訊、不改狀態。
	 * 取號成功（RtnCode='2' ATM / '10100073' CVS/BARCODE）→ 寫繳費資訊 + order note，不改狀態。
	 * 訂單以 MerchantTradeNo 反查（含重試前的歷史編號）；冪等以 MerchantTradeNo 為單位：
	 * 同編號重送略過，顧客重試後新編號取號則更新為最新的繳費資訊（舊資訊仍留在 order note）。
	 *
	 * @param array<string, mixed> $params PaymentInfoURL 通知參數
	 * @return void
	 */
	public function handle_payment_info( array $params ): void {
		if ( ! $this->is_valid( $params ) ) {
			Plugin::logger( '綠界 AIO PaymentInfoURL CheckMacValue 驗章失敗', 'warning', [ 'params' => $params ] );
			return;
		}

		$rtn_code = (string) ( $params['RtnCode'] ?? '' );
		// 僅處理取號成功碼，其餘忽略（不改狀態）
		if ( ! RtnCode::is_get_code_success( $rtn_code ) ) {
			return;
		}

		$trade_no = (string) ( $params['MerchantTradeNo'] ?? '' );
		$order    = EcpayMetaKeys::get_order_by_trade_no( $trade_no );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( "找不到訂單，MerchantTradeNo: {$trade_no}" );
		}

		$meta_keys = new EcpayMetaKeys( $order );

		// 冪等：同一 MerchantTradeNo 的取號資訊已寫入則不重複（重試後的新編號取號須更新）
		$existing_trade_no = (string) ( $meta_keys->get_payment_info()['MerchantTradeNo'] ?? '' );
		if ( $trade_no === $existing_trade_no ) {
			return;
		}

		$meta_keys->update_payment_info( $params );

		$note = WP::array_to_html(
			$params,
			[ 'title' => '綠界 ECPay 取號繳費資訊' ]
		);
		$order->add_order_note( $note );
		// 不改訂單狀態（維持等待付款）
	}

	// endregion

	// region 驗證

	/**
	 * 以 timing-safe 方式驗證 CheckMacValue
	 *
	 * @param array<string, mixed> $params 綠界通知參數
	 * @return bool
	 */
	private function is_valid( array $params ): bool {
		$received = (string) ( $params['CheckMacValue'] ?? '' );
		if ( '' === $received ) {
			return false;
		}

		$settings = AioSettingsDTO::instance();
		if ( '' === $settings->hashKey || '' === $settings->hashIv ) {
			return false;
		}

		// 僅取 string|int 值參與計算（CheckMacValueService 由本類傳入時已過濾）
		$args = [];
		foreach ( $params as $key => $value ) {
			if ( 'CheckMacValue' === $key ) {
				continue;
			}
			if ( \is_string( $value ) || \is_int( $value ) ) {
				$args[ (string) $key ] = $value;
			}
		}

		$calculated = CheckMacValueService::get_check_value( $args, $settings->hashKey, $settings->hashIv, 'sha256' );

		return \hash_equals( $calculated, \strtoupper( $received ) );
	}

	// endregion

	// region URL helpers

	/** @return string ReturnURL（付款結果通知） */
	public static function get_return_url(): string {
		return \site_url( 'wp-json/power-checkout/ecpay/aio/return', 'https' );
	}

	/** @return string PaymentInfoURL（取號結果通知） */
	public static function get_payment_info_url(): string {
		return \site_url( 'wp-json/power-checkout/ecpay/aio/payment-info', 'https' );
	}

	// endregion

	/**
	 * 純文字 1|OK 回應（HTTP 200，非 JSON）
	 *
	 * 透過 rest_pre_echo_response 過濾把 body 直接輸出為純文字，避免 WP 序列化為 JSON。
	 *
	 * @return \WP_REST_Response
	 */
	private static function ok_response(): \WP_REST_Response {
		$response = new \WP_REST_Response( self::RESPONSE_OK, 200 );
		$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
		\add_filter(
			'rest_pre_echo_response',
			static function ( $result, $server, $request ) {
				$route = $request->get_route();
				if ( \is_string( $route ) && \str_contains( $route, 'power-checkout/ecpay/aio/' ) ) {
					echo self::RESPONSE_OK; // phpcs:ignore
					return null;
				}
				return $result;
			},
			10,
			3
		);
		return $response;
	}
}
