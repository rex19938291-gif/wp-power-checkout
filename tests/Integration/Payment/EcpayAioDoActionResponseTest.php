<?php
/**
 * 綠界 AIO DoAction 回應解析回歸測試（Issue #20）
 *
 * 缺陷：DoActionClient::parse_response() 以 `|` 切 `RtnCode|RtnMsg`，但綠界 /CreditDetail/DoAction
 * 實際回應為 URL-encoded 字串（`key=value&key=value`，ECPay-API-Skill
 * guides/19-http-protocol-reference.md §回應格式對照表）。正式環境回應不含 `|`，
 * 導致 RtnCode 永遠被判定非 1 → 綠界端退款已成功，WC 卻寫「❌ 退款失敗」並刪除 refund，
 * 站方以為失敗再退一次即造成重複退款。
 *
 * 既有測試皆在 API_MODE=mock 下執行，而 do_action() 在 mock 時於送 HTTP 前直接回 fixture，
 * 從未走過真實解析路徑，故本檔另含「端到端」測試：暫時切換為非 mock，
 * 以 pre_http_request 攔截 payment.ecpay.com.tw/CreditDetail/DoAction 回傳真實格式 body，
 * 讓 wc_create_refund → process_refund → woocommerce_order_refunded → DoAction → parse_response 全鏈執行。
 *
 * @group integration
 * @group payment
 * @group ecpay
 * @group refund
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Http\DoActionClient;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\AioRedirectGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界 AIO DoAction 回應解析回歸測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 * @group refund
 */
final class EcpayAioDoActionResponseTest extends TestCase {

	/** @var string 綠界測試環境 HashKey */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界測試環境 HashIV */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	/** @var string 綠界 DoAction 正式環境端點（DoAction 僅正式環境可用） */
	private const DOACTION_URL = 'https://payment.ecpay.com.tw/CreditDetail/DoAction';

	/** @var string Issue #20 回報之正式環境實際回應（已去識別化） */
	private const ISSUE_RAW_RESPONSE = 'MerchantID=3474272&Merchant=3474272&MerchantTradeNo=35042TS4590420971&TradeNo=2609241709159574&RtnCode=1&RtnMsg=Succeeded.';

	/** @var string 綠界交易編號 TradeNo（測試訂單 fixture） */
	private const TRADE_NO = '2609241709159574';

	/** @var string|false 測試開始前的 API_MODE 環境變數（tear_down 還原用） */
	private string|false $original_api_mode = false;

	/** @var array<int, array{url: string, body: array<string, mixed>}> 攔截到的 DoAction 請求 */
	private array $captured_requests = [];

	/** @var string 攔截 DoAction 時回傳的 body */
	private string $fake_response_body = '';

	/** 每次測試前：保存原 API_MODE 後再初始化 */
	public function set_up(): void {
		$this->original_api_mode  = \getenv( 'API_MODE' );
		$this->captured_requests  = [];
		$this->fake_response_body = '';
		parent::set_up();
	}

	/** 啟用 AIO（test 模式 + 測試帳號），預設 MOCK（端到端測試內會切為非 mock） */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );
		ProviderUtils::update_option(
			AioRedirectGateway::ID,
			[
				'enabled'    => 'yes',
				'mode'       => 'test',
				'merchantId' => '3002607',
				'hashKey'    => self::HASH_KEY,
				'hashIv'     => self::HASH_IV,
			]
		);
	}

	/** 每次測試後：移除 HTTP 攔截並還原 API_MODE（不論測試成敗） */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );

		if ( false === $this->original_api_mode ) {
			\putenv( 'API_MODE' );
		} else {
			\putenv( "API_MODE={$this->original_api_mode}" );
		}

		parent::tear_down();
	}

	// ========== Helpers ==========

	/**
	 * 建立 AIO 信用卡訂單（已寫 MerchantTradeNo + 綠界付款明細）
	 *
	 * @param float $total 訂單金額
	 * @return \WC_Order
	 */
	private function create_credit_order( float $total = 1000 ): \WC_Order {
		$order     = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => AioRedirectGateway::ID,
				'total'          => $total,
			]
		);
		$meta_keys = new EcpayMetaKeys( $order );
		$meta_keys->update_trade_no( 'MTN' . $order->get_id() );
		$meta_keys->update_payment_detail(
			[
				'PaymentType' => 'Credit_CreditCard',
				'TradeNo'     => self::TRADE_NO,
			]
		);
		return $order;
	}

	/**
	 * 切換為非 mock 並以 pre_http_request 攔截 DoAction，回傳指定 body
	 *
	 * @param string $body 綠界 DoAction 回應 body
	 * @return void
	 */
	private function use_real_http_path( string $body ): void {
		\putenv( 'API_MODE=sandbox' ); // 非 mock：DoActionClient 會實際組 HTTP 請求
		$this->fake_response_body = $body;
		\add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
	}

	/**
	 * pre_http_request 攔截器：DoAction 回 fixture；其餘外部請求一律擋下（測試不連外）
	 *
	 * @param false|array<string, mixed>|\WP_Error $preempt     既有 preempt 值
	 * @param array<string, mixed>                 $parsed_args 請求參數
	 * @param string                               $url         請求 URL
	 * @return array<string, mixed>|\WP_Error
	 */
	public function intercept_http( $preempt, array $parsed_args, string $url ) {
		if ( self::DOACTION_URL !== $url ) {
			return new \WP_Error( 'http_blocked_in_test', "測試中禁止外部請求：{$url}" );
		}

		$body                      = $parsed_args['body'] ?? [];
		$this->captured_requests[] = [
			'url'  => $url,
			'body' => \is_array( $body ) ? $body : [],
		];

		return [
			'headers'  => [ 'content-type' => 'text/html; charset=utf-8' ],
			'body'     => $this->fake_response_body,
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
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
	 * 斷言訂單沒有任何 ❌ 失敗 note
	 *
	 * @param int $order_id 訂單 ID
	 * @return void
	 */
	private function assert_no_failure_note( int $order_id ): void {
		foreach ( $this->get_note_contents( $order_id ) as $content ) {
			$this->assertStringNotContainsString( '❌', $content, "不應出現失敗 note，實際：{$content}" );
		}
	}

	// ========== parse_response：真實 URL-encoded 格式 ==========

	/**
	 * Issue #20 回報之正式環境實際回應 → RtnCode='1'、不 throw、無 ❌ note
	 *
	 * @test
	 * @group happy
	 */
	public function test_Issue20正式環境實際回應字串解析為成功(): void {
		// Given
		$order  = $this->create_credit_order();
		$client = new DoActionClient( $order );

		// When
		$result = $client->parse_response( self::ISSUE_RAW_RESPONSE );

		// Then
		$this->assertSame( '1', $result['RtnCode'] );
		$this->assertSame( 'Succeeded.', $result['RtnMsg'] );
		$this->assert_no_failure_note( $order->get_id() );
	}

	/**
	 * 回應尾端帶換行 / 空白仍可正確解析
	 *
	 * @test
	 * @group edge
	 */
	public function test_回應前後空白換行仍正確解析(): void {
		$client = new DoActionClient( $this->create_credit_order() );

		$result = $client->parse_response( "  \r\n" . self::ISSUE_RAW_RESPONSE . "\r\n" );

		$this->assertSame( '1', $result['RtnCode'] );
		$this->assertSame( 'Succeeded.', $result['RtnMsg'] );
	}

	/**
	 * RtnMsg 為 URL-encoded 中文 → 正確解碼（成功與失敗兩種情境）
	 *
	 * @test
	 * @group edge
	 */
	public function test_RtnMsg為URL編碼中文時正確解碼(): void {
		$order  = $this->create_credit_order();
		$client = new DoActionClient( $order );

		// 成功：RtnMsg=成功；'+' 視為空白
		$result = $client->parse_response( 'MerchantID=3002607&TradeNo=' . self::TRADE_NO . '&RtnCode=1&RtnMsg=%E6%88%90%E5%8A%9F+OK' );
		$this->assertSame( '成功 OK', $result['RtnMsg'] );

		// 失敗：RtnMsg=退款失敗 → 例外訊息含解碼後中文，不含 %E9 原始編碼
		try {
			$client->parse_response( 'MerchantID=3002607&TradeNo=' . self::TRADE_NO . '&RtnCode=10100050&RtnMsg=%E9%80%80%E6%AC%BE%E5%A4%B1%E6%95%97' );
			$this->fail( '預期 RtnCode 非 1 應拋例外' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( '10100050', $e->getMessage() );
			$this->assertStringContainsString( '退款失敗', $e->getMessage() );
			$this->assertStringNotContainsString( '%E9', $e->getMessage() );
		}
	}

	/**
	 * 空字串回應 → fail-closed throw，訊息含「無法解析」，order note 提醒勿重複操作
	 *
	 * @test
	 * @group edge
	 */
	public function test_空字串回應無法解析時拋例外並提醒勿重複操作(): void {
		$order  = $this->create_credit_order();
		$client = new DoActionClient( $order );

		try {
			$client->parse_response( '' );
			$this->fail( '預期空字串回應應拋例外' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( '無法解析', $e->getMessage() );
			$this->assertStringNotContainsString( 'RtnCode=', $e->getMessage(), '無法解析與 RtnCode 非 1 的訊息須可區分' );
		}

		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '無法解析' );
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '請至綠界廠商後台確認實際狀態' );
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '勿重複操作' );
	}

	/**
	 * HTML 錯誤頁回應 → throw「無法解析」，且原始 body 不寫進 order note
	 *
	 * @test
	 * @group error
	 */
	public function test_HTML錯誤頁回應無法解析且原始body不寫進order_note(): void {
		$order  = $this->create_credit_order();
		$client = new DoActionClient( $order );
		$html   = '<html><head><title>Service Unavailable</title></head><body><h1>503 Service Unavailable</h1></body></html>';

		try {
			$client->parse_response( $html );
			$this->fail( '預期 HTML 回應應拋例外' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( '無法解析', $e->getMessage() );
			$this->assertStringNotContainsString( '<html', $e->getMessage() );
		}

		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '勿重複操作' );
		foreach ( $this->get_note_contents( $order->get_id() ) as $content ) {
			$this->assertStringNotContainsString( 'Service Unavailable', $content, '原始 body 不應寫進 order note' );
		}
	}

	/**
	 * 舊 pipe 格式（`1|OK`）不再被接受 → 視為無法解析 fail-closed（不保留 pipe fallback）
	 *
	 * @test
	 * @group edge
	 */
	public function test_舊pipe格式回應視為無法解析(): void {
		$client = new DoActionClient( $this->create_credit_order() );

		try {
			$client->parse_response( '1|OK' );
			$this->fail( '預期 pipe 格式應視為無法解析並拋例外' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( '無法解析', $e->getMessage() );
		}
	}

	/**
	 * RtnCode 以陣列語法注入（RtnCode[]=1）→ 非字串，視為無法解析
	 *
	 * @test
	 * @group security
	 */
	public function test_RtnCode為陣列語法時視為無法解析(): void {
		$client = new DoActionClient( $this->create_credit_order() );

		try {
			$client->parse_response( 'RtnCode[]=1&RtnMsg=Succeeded.' );
			$this->fail( '預期陣列語法 RtnCode 應拋例外' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( '無法解析', $e->getMessage() );
		}
	}

	// ========== 端到端：非 mock + pre_http_request 攔截，走真實解析路徑 ==========

	/**
	 * 端到端：wc_create_refund(refund_payment) → process_refund → DoAction(R) 真實 HTTP 解析 → 退款成功
	 *
	 * 修正前：正式環境回應不含 `|` → 判定失敗 → ❌ note + 刪除 refund（綠界端其實已退款）。
	 *
	 * @test
	 * @group happy
	 */
	public function test_端到端信用卡退款走真實HTTP解析路徑判定成功(): void {
		// Given: AIO 信用卡訂單；非 mock + 攔截 DoAction 回正式環境真實格式
		$order = $this->create_credit_order( 1000 );
		$this->use_real_http_path(
			'MerchantID=3002607&Merchant=3002607&MerchantTradeNo=MTN' . $order->get_id() . '&TradeNo=' . self::TRADE_NO . '&RtnCode=1&RtnMsg=Succeeded.'
		);
		WC()->payment_gateways()->init(); // 載入 gateway（註冊 woocommerce_order_refunded handler）

		// When: 走 WC 完整退款鏈（refund_payment=true → wc_refund_payment → process_refund → woocommerce_order_refunded）
		$refund = \wc_create_refund(
			[
				'order_id'       => $order->get_id(),
				'amount'         => 1000,
				'reason'         => 'Issue #20 端到端',
				'refund_payment' => true,
			]
		);

		// Then: process_refund 回 true（否則 wc_create_refund 回 WP_Error）
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );

		// 確實送出一次 DoAction(Action=R)，金額 / TradeNo 正確
		$this->assertCount( 1, $this->captured_requests, '應送出恰好一次 DoAction 請求' );
		$sent = $this->captured_requests[0]['body'];
		$this->assertSame( 'R', $sent['Action'] ?? null );
		$this->assertSame( 1000, $sent['TotalAmount'] ?? null );
		$this->assertSame( self::TRADE_NO, $sent['TradeNo'] ?? null );
		$this->assertSame( 'MTN' . $order->get_id(), $sent['MerchantTradeNo'] ?? null );

		// refund 物件仍存在（未被回滾刪除）、標記為經 gateway 退款
		$saved_refund = \wc_get_order( $refund->get_id() );
		$this->assertInstanceOf( \WC_Order_Refund::class, $saved_refund );
		$this->assertTrue( $saved_refund->get_refunded_payment() );

		// 成功 note、無任何 ❌ note
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '退款成功' );
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), 'Succeeded.' );
		$this->assert_no_failure_note( $order->get_id() );
	}

	/**
	 * 端到端：DoAction 回 RtnCode 非 1 → 刪除 refund + ❌ note 提醒勿重複操作
	 *
	 * @test
	 * @group error
	 */
	public function test_端到端退款回應RtnCode非1時刪除refund並提醒勿重複操作(): void {
		// Given
		$order = $this->create_credit_order( 1000 );
		$this->use_real_http_path(
			'MerchantID=3002607&MerchantTradeNo=MTN' . $order->get_id() . '&TradeNo=' . self::TRADE_NO . '&RtnCode=10100050&RtnMsg=%E9%80%80%E6%AC%BE%E5%A4%B1%E6%95%97'
		);
		WC()->payment_gateways()->init();

		// When
		$refund = \wc_create_refund(
			[
				'order_id'       => $order->get_id(),
				'amount'         => 1000,
				'reason'         => 'Issue #20 端到端（失敗）',
				'refund_payment' => true,
			]
		);

		// Then: process_refund 前置檢查通過，實際 API 失敗 → refund 被回滾刪除
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertCount( 1, $this->captured_requests );
		$this->assertFalse( \wc_get_order( $refund->get_id() ) );
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '10100050' );
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '退款失敗' );
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '勿重複操作' );
	}

	/**
	 * 端到端：後台「請款」(Action=C) 走真實 HTTP 解析路徑 → 寫 captured + ✅ note
	 *
	 * @test
	 * @group happy
	 */
	public function test_端到端後台請款走真實HTTP解析路徑判定成功(): void {
		// Given
		$order = $this->create_credit_order( 1000 );
		$this->use_real_http_path(
			'MerchantID=3002607&MerchantTradeNo=MTN' . $order->get_id() . '&TradeNo=' . self::TRADE_NO . '&RtnCode=1&RtnMsg=Succeeded.'
		);

		// When
		AioRedirectGateway::handle_capture_action( $order );

		// Then
		$this->assertCount( 1, $this->captured_requests );
		$this->assertSame( 'C', $this->captured_requests[0]['body']['Action'] ?? null );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( 'captured', ( new EcpayMetaKeys( $fresh ) )->get_capture_status() );
		$this->assert_order_note_contains( $fresh, '請款成功' );
		$this->assert_no_failure_note( $order->get_id() );
	}
}
