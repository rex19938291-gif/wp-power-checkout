<?php
/**
 * 綠界站內付 2.0（ECPG）「顧客重試付款」回歸測試
 *
 * 缺陷：舊版 GetTokenbyTrade / 幕後取號一律沿用訂單既有的 `_pc_ecpay_trade_no`（「冪等」）。
 * 綠界規格「特店訂單編號均為唯一值，不可重複使用」（https://developers.ecpay.com.tw/9040.md）：
 * 顧客刷卡失敗 / 3DS 中斷後回 order-pay（或 block 結帳沿用同一張單）重付時，舊編號已建立過交易，
 * 不可再用（AIO 同編號重送 stage 實測回 10300028 訂單編號重覆）。
 * stage 實測（測試特店 3002607）：尚未 CreatePayment 的編號重送 GetTokenbyTrade 可過，故問題只出在「付過一次」之後。
 *
 * 修法（本測試鎖定的不變量）：
 *  1. 每次付款嘗試（process_payment → get_token / get_code）產生新 MerchantTradeNo，舊編號留在 `_pc_ecpay_trade_no_history`。
 *  2. 舊編號的 ReturnURL 仍反查得到訂單；付款成功時把「實際成功」的編號寫回 `_pc_ecpay_trade_no`，退款 DoAction 用它。
 *  3. 已入帳（已付款 / 已退款）訂單不被重送通知改寫；另一編號又付款成功 → 加註「疑似重複付款」。
 *  4. 已付款訂單的 create-payment 一律拒絕（防另一分頁的舊付款表單再扣一次款）。
 *
 * @group integration
 * @group payment
 * @group ecpay
 * @group ecpg
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Enums\EcpayPaymentMethod;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Domains\Payment\Ecpg\DTOs\EcpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Ecpg\Http\EcpgApiClient;
use J7\PowerCheckout\Domains\Payment\Ecpg\Http\EcpgCallback;
use J7\PowerCheckout\Domains\Payment\Ecpg\Http\EcpgFrontendApi;
use J7\PowerCheckout\Domains\Payment\Ecpg\Services\EcpgGateway;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界站內付 2.0 重試付款測試類別
 */
final class EcpgRetryPaymentTest extends TestCase {

	/** @var string 綠界 ECPG 線上金流測試環境 HashKey */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界 ECPG 線上金流測試環境 HashIV */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	/** @var string 退款 DoAction 端點（test 模式走 ecpayment-stage） */
	private const DOACTION_URL = 'https://ecpayment-stage.ecpay.com.tw/1.0.0/Credit/DoAction';

	/** @var array<int, array<string, mixed>> 攔截到的 DoAction 請求（已解密的 Data） */
	private array $captured_refunds = [];

	/** 每次測試前啟用 ecpay_ecpg（test 模式 + 測試帳號） */
	protected function configure_dependencies(): void {
		ProviderUtils::update_option(
			EcpgGateway::ID,
			[
				'enabled'    => 'yes',
				'mode'       => 'test',
				'merchantId' => '3002607',
				'hashKey'    => self::HASH_KEY,
				'hashIv'     => self::HASH_IV,
			]
		);
		EcpgSettingsDTO::reset();
		putenv( 'API_MODE=mock' );
		$this->captured_refunds = [];
	}

	/** 每次測試後清理設定，API_MODE 還原為 mock（避免外洩到後續測試） */
	public function tear_down(): void {
		\remove_all_filters( 'pre_http_request' );
		delete_option( ProviderUtils::get_option_name( EcpgGateway::ID ) );
		EcpgSettingsDTO::reset();
		putenv( 'API_MODE=mock' );
		parent::tear_down();
	}

	/** @return AesCrypto 測試用加解密器 */
	private function crypto(): AesCrypto {
		return new AesCrypto( self::HASH_KEY, self::HASH_IV );
	}

	/**
	 * 建立含 billing email/phone 的 ECPG pending 訂單
	 *
	 * @return \WC_Order
	 */
	private function create_ecpg_order(): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => EcpgGateway::ID,
				'total'          => 1000,
			]
		);
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_billing_phone( '0912345678' );
		$order->save();
		return $order;
	}

	/**
	 * 一次付款嘗試（= process_payment 內的 get_token），回傳本次 MerchantTradeNo
	 *
	 * @param int $order_id 訂單 ID
	 * @return string
	 */
	private function attempt( int $order_id ): string {
		$order = \wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $order );
		( new EcpgApiClient( $order ) )->get_token();
		return $this->current_trade_no( $order_id );
	}

	/**
	 * @param int $order_id 訂單 ID
	 * @return string 訂單目前的 `_pc_ecpay_trade_no`
	 */
	private function current_trade_no( int $order_id ): string {
		$order = \wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $order );
		return ( new EcpayMetaKeys( $order ) )->get_trade_no();
	}

	/**
	 * 組裝 ReturnURL 通知（巢狀 OrderInfo）
	 *
	 * @param int    $rtn_code 業務層碼
	 * @param string $trade_no MerchantTradeNo
	 * @param string $gw_trade 綠界交易編號 TradeNo
	 * @return array<string, mixed>
	 */
	private function build_notify( int $rtn_code, string $trade_no, string $gw_trade = 'TN0001' ): array {
		return [
			'MerchantID' => '3002607',
			'TransCode'  => 1,
			'TransMsg'   => 'Success',
			'Data'       => $this->crypto()->encrypt(
				[
					'RtnCode'   => $rtn_code,
					'RtnMsg'    => 1 === $rtn_code ? '交易成功' : '授權失敗',
					'OrderInfo' => [
						'MerchantTradeNo' => $trade_no,
						'TradeNo'         => $gw_trade,
						'TradeAmt'        => 1000,
						'PaymentType'     => 'Credit',
					],
				]
			),
		];
	}

	/**
	 * @param int $order_id 訂單 ID
	 * @return int 「疑似重複付款」order note 數量
	 */
	private function count_duplicate_notes( int $order_id ): int {
		$count = 0;
		foreach ( \wc_get_order_notes( [ 'order_id' => $order_id ] ) as $note ) {
			if ( \str_contains( (string) $note->content, '疑似重複付款' ) ) {
				++$count;
			}
		}
		return $count;
	}

	// ========== 每次付款嘗試換新編號 ==========

	/**
	 * process_payment 兩次（第一次未付 → order-pay 重付）→ 兩個不同編號，皆在歷史、皆反查得到訂單
	 *
	 * @test
	 * @group happy
	 */
	public function test_重試付款時process_payment每次產生新MerchantTradeNo(): void {
		// Given
		$order   = $this->create_ecpg_order();
		$gateway = new EcpgGateway();

		// When: 兩次付款嘗試
		$gateway->process_payment( $order->get_id() );
		$first = $this->current_trade_no( $order->get_id() );
		$gateway->process_payment( $order->get_id() );
		$second = $this->current_trade_no( $order->get_id() );

		// Then
		$this->assertNotSame( '', $first );
		$this->assertNotSame( $first, $second );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( [ $first, $second ], ( new EcpayMetaKeys( $fresh ) )->get_trade_no_history() );
		$this->assertSame( 'mock_token_' . $second, EcpgGateway::get_ecpg_token( $fresh ), '前端拿到的 token 必須對應最新編號' );
		foreach ( [ $first, $second ] as $trade_no ) {
			$this->assertSame( $order->get_id(), EcpayMetaKeys::get_order_by_trade_no( $trade_no )?->get_id() );
		}
	}

	/**
	 * 幕後取號（ATM）重試 → 每次新編號（已取號的編號已建立交易，不可重送）
	 *
	 * @test
	 * @group edge
	 */
	public function test_幕後取號重試時每次產生新MerchantTradeNo(): void {
		// Given
		$order = $this->create_ecpg_order();

		// When
		( new EcpgApiClient( $order ) )->get_code( EcpayPaymentMethod::ATM );
		$first = $this->current_trade_no( $order->get_id() );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		( new EcpgApiClient( $fresh ) )->get_code( EcpayPaymentMethod::ATM );
		$second = $this->current_trade_no( $order->get_id() );

		// Then
		$this->assertNotSame( $first, $second );
		$this->assertSame( $order->get_id(), EcpayMetaKeys::get_order_by_trade_no( $first )?->get_id() );
	}

	// ========== callback：舊編號付款成功 ==========

	/**
	 * 第一次嘗試的 3DS 晚完成（舊編號付款成功）→ 訂單入帳，目前編號改寫為成功的舊編號
	 *
	 * @test
	 * @group edge
	 */
	public function test_舊編號付款成功時訂單入帳且目前編號改為成功的編號(): void {
		// Given: 兩次付款嘗試，目前編號是第二次
		$order  = $this->create_ecpg_order();
		$first  = $this->attempt( $order->get_id() );
		$second = $this->attempt( $order->get_id() );
		$this->assertSame( $second, $this->current_trade_no( $order->get_id() ) );

		// When: 第一次嘗試的付款成功通知
		EcpgCallback::instance()->handle_return( $this->build_notify( 1, $first, 'TN_FIRST' ) );

		// Then: processing；`_pc_ecpay_trade_no` = 成功的第一個編號（與付款明細 TradeNo 成對）；第二個仍在歷史
		$this->assert_order_status( $order, 'processing' );
		$this->assertSame( $first, $this->current_trade_no( $order->get_id() ) );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertContains( $second, ( new EcpayMetaKeys( $fresh ) )->get_trade_no_history() );
	}

	/**
	 * 舊編號付款成功後退款 → DoAction 送出的 MerchantTradeNo / TradeNo 是成功那一筆（成對）
	 *
	 * @test
	 * @group edge
	 */
	public function test_舊編號付款成功後退款DoAction送出成功的那組編號(): void {
		// Given: 第一次嘗試付款成功、第二次嘗試未付
		$order = $this->create_ecpg_order();
		$first = $this->attempt( $order->get_id() );
		$this->attempt( $order->get_id() );
		EcpgCallback::instance()->handle_return( $this->build_notify( 1, $first, 'TN_FIRST' ) );

		// 攔截退款 DoAction（真 HTTP 路徑；回綠界格式的成功密文）
		putenv( 'API_MODE=sandbox' );
		\add_filter(
			'pre_http_request',
			function ( $preempt, array $args, string $url ) {
				if ( self::DOACTION_URL !== $url ) {
					return $preempt;
				}
				$body                     = \json_decode( (string) $args['body'], true );
				$this->captured_refunds[] = $this->crypto()->decrypt( (string) ( $body['Data'] ?? '' ) );
				return [
					'headers'  => [],
					'body'     => (string) \wp_json_encode(
						[
							'MerchantID' => '3002607',
							'TransCode'  => 1,
							'TransMsg'   => 'Success',
							'Data'       => $this->crypto()->encrypt(
								[
									'RtnCode' => 1,
									'RtnMsg'  => 'Success',
								]
							),
						]
					),
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'cookies'  => [],
				];
			},
			10,
			3
		);
		WC()->payment_gateways()->init();

		// When: 全額退款
		$refund = \wc_create_refund(
			[
				'order_id'       => $order->get_id(),
				'amount'         => 1000,
				'reason'         => '重試付款後退款',
				'refund_payment' => true,
			]
		);

		// Then: 送出的是成功那一組（第一個編號 + TN_FIRST），退款成立
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertCount( 1, $this->captured_refunds );
		$this->assertSame( $first, $this->captured_refunds[0]['MerchantTradeNo'] ?? null );
		$this->assertSame( 'TN_FIRST', $this->captured_refunds[0]['TradeNo'] ?? null );
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '退款成功' );
	}

	// ========== callback：已入帳訂單不被改寫 ==========

	/**
	 * 已付款後，另一個編號又付款成功（兩個分頁各刷一次）→ 狀態 / 明細不變，加註疑似重複付款
	 *
	 * @test
	 * @group edge
	 */
	public function test_已付款後另一編號又付款成功時加註疑似重複付款(): void {
		// Given: 第二次嘗試付款成功
		$order  = $this->create_ecpg_order();
		$first  = $this->attempt( $order->get_id() );
		$second = $this->attempt( $order->get_id() );
		EcpgCallback::instance()->handle_return( $this->build_notify( 1, $second, 'TN_SECOND' ) );

		// When: 第一次嘗試也付款成功（晚到）
		EcpgCallback::instance()->handle_return( $this->build_notify( 1, $first, 'TN_FIRST' ) );

		// Then: 付款明細與目前編號仍是第二筆；加註一次疑似重複付款
		$this->assert_order_status( $order, 'processing' );
		$this->assertSame( $second, $this->current_trade_no( $order->get_id() ) );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$detail = ( new EcpayMetaKeys( $fresh ) )->get_payment_detail();
		$this->assertSame( 'TN_SECOND', $detail['OrderInfo']['TradeNo'] ?? null );
		$this->assertSame( 1, $this->count_duplicate_notes( $order->get_id() ) );
	}

	/**
	 * 同一編號重送成功通知 → 正常冪等，不加註重複付款
	 *
	 * @test
	 * @group edge
	 */
	public function test_同編號重送成功通知時不加註重複付款(): void {
		$order    = $this->create_ecpg_order();
		$trade_no = $this->attempt( $order->get_id() );

		for ( $i = 0; $i < 3; $i++ ) {
			EcpgCallback::instance()->handle_return( $this->build_notify( 1, $trade_no ) );
		}

		$this->assert_order_status( $order, 'processing' );
		$this->assertSame( 0, $this->count_duplicate_notes( $order->get_id() ) );
	}

	/**
	 * 已完成（completed）訂單收到重送的成功通知 → 維持 completed（舊版只擋 processing，會被改回 processing）
	 *
	 * @test
	 * @group edge
	 */
	public function test_已完成訂單收到重送成功通知時不被改回處理中(): void {
		$order    = $this->create_ecpg_order();
		$trade_no = $this->attempt( $order->get_id() );
		EcpgCallback::instance()->handle_return( $this->build_notify( 1, $trade_no ) );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$fresh->update_status( 'completed' );

		EcpgCallback::instance()->handle_return( $this->build_notify( 1, $trade_no ) );

		$this->assert_order_status( $order, 'completed' );
	}

	/**
	 * 已退款（refunded）訂單收到重送的成功通知 → 維持 refunded（不得被「復活」）
	 *
	 * @test
	 * @group security
	 */
	public function test_已退款訂單收到重送成功通知時維持已退款(): void {
		$order    = $this->create_ecpg_order();
		$trade_no = $this->attempt( $order->get_id() );
		EcpgCallback::instance()->handle_return( $this->build_notify( 1, $trade_no ) );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$fresh->update_status( 'refunded' );

		EcpgCallback::instance()->handle_return( $this->build_notify( 1, $trade_no ) );

		$this->assert_order_status( $order, 'refunded' );
	}

	/**
	 * 舊嘗試的失敗通知晚到（顧客已在第二次嘗試中）→ 維持 pending，目前編號不變
	 *
	 * @test
	 * @group edge
	 */
	public function test_舊嘗試失敗通知晚到時維持待付款且不動目前編號(): void {
		$order  = $this->create_ecpg_order();
		$first  = $this->attempt( $order->get_id() );
		$second = $this->attempt( $order->get_id() );

		EcpgCallback::instance()->handle_return( $this->build_notify( 10100058, $first ) );

		$this->assert_order_status( $order, 'pending' );
		$this->assertSame( $second, $this->current_trade_no( $order->get_id() ) );
	}

	// ========== create-payment：已付款訂單拒絕 ==========

	/**
	 * 訂單已付款後，舊分頁的付款表單又送出 create-payment → 400，不打 CreatePayment
	 *
	 * @test
	 * @group security
	 */
	public function test_已付款訂單的create_payment回400不再扣款(): void {
		// Given: 已付款訂單
		$order    = $this->create_ecpg_order();
		$trade_no = $this->attempt( $order->get_id() );
		EcpgCallback::instance()->handle_return( $this->build_notify( 1, $trade_no ) );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );

		// When
		$request = new \WP_REST_Request( 'POST', '/power-checkout/ecpay/ecpg/create-payment' );
		$request->set_body_params(
			[
				'order_id'  => $fresh->get_id(),
				'order_key' => $fresh->get_order_key(),
				'pay_token' => 'pay_token_from_old_tab',
			]
		);
		$response = EcpgFrontendApi::instance()->post_ecpg_create_payment_callback( $request );

		// Then
		$this->assertSame( 400, $response->get_status() );
		$this->assert_order_status( $order, 'processing' );
	}
}
