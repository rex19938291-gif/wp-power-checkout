<?php
/**
 * 綠界 ECPay AIO「顧客重試付款」回歸測試
 *
 * 缺陷（真瀏覽器驗收，local-turbo 訂單 6518）：
 *  顧客選分期 → 導到綠界不付款返回 → 購物車不變再結帳（WC 沿用同一張 pending 訂單）→ 停在「已收到訂單」頁，
 *  沒有任何付款入口。根因：before_order_received 以 `_pc_ecpay_trade_no` 已存在為由直接 return。
 *
 * 查證（綠界 stage AioCheckOut/V5，測試特店 3002607 實測）：同一 MerchantTradeNo 未付款前再送一次，綠界回
 * 「訊息代碼：10300028 訊息說明：訂單編號重覆，建立失敗，請返回商店頁面重新下單。」→ 不能沿用舊編號，
 * 每次重新導轉都必須產生新的 MerchantTradeNo。
 *
 * 修法（本測試鎖定的不變量）：
 *  1. 顧客發起付款（process_payment）→ order-received 以新 MerchantTradeNo 重新輸出導轉表單；
 *     單純重訪（重新整理 / 上一頁 / 綠界「返回商店」）不自動導轉（避免與 ClientBackURL 形成迴圈），改提供付款連結。
 *  2. 所有送出過的編號保留於 `_pc_ecpay_trade_no_history`，舊編號的 ReturnURL / PaymentInfoURL 仍能反查訂單。
 *  3. 已付款訂單不再導轉；同一請求內不產生兩個編號；callback 重送不重複入帳。
 *  4. 付款成功時把「實際成功」的 MerchantTradeNo 寫回 `_pc_ecpay_trade_no`，退款 / 請款 / 查詢都用它。
 *
 * @group integration
 * @group payment
 * @group ecpay
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Http\AioCallback;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\AioRedirectGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\InstallmentCheckoutField;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\CheckMacValueService;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\TradeNo;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界 AIO 重試付款測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 */
final class EcpayAioRetryPaymentTest extends TestCase {

	/** @var string 綠界 AIO 公開測試帳號 HashKey */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界 AIO 公開測試帳號 HashIV */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	/** @var string 綠界 AIO 公開測試帳號 MerchantID */
	private const MERCHANT_ID = '3002607';

	/** @var string 綠界 AIO stage 收銀台端點（auto-form 的 action） */
	private const CASHIER_URL = 'payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5';

	/** @var string 綠界 DoAction 正式環境端點（DoAction 僅正式環境可用） */
	private const DOACTION_URL = 'https://payment.ecpay.com.tw/CreditDetail/DoAction';

	/** @var string|false 測試開始前的 API_MODE（tear_down 還原用） */
	private string|false $original_api_mode = false;

	/** @var array<int, array<string, mixed>> 攔截到的 DoAction 請求 body */
	private array $captured_requests = [];

	/** 每次測試前：保存原 API_MODE */
	public function set_up(): void {
		$this->original_api_mode = \getenv( 'API_MODE' );
		$this->captured_requests = [];
		parent::set_up();
	}

	/** MOCK 模式 + 啟用 AIO（test 模式 + 公開測試帳號）+ auto-form 模板註冊 + WC session */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );

		// auto-form 模板平時由 Plugin::__construct 註冊；測試 bootstrap 不一定觸發，缺少時 load_template 會 throw
		\J7\PowerCheckout\Plugin::$template_page_names = [ 'auto-form' ];

		$this->seed_settings();

		$wc = \WC();
		if ( ! isset( $wc->session ) || ! $wc->session instanceof \WC_Session ) {
			$wc->initialize_session();
		}
		\wc_clear_notices();

		$this->reset_request();
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		unset( $_POST[ InstallmentCheckoutField::FIELD_NAME ], $_GET['key'] );
		\wc_clear_notices();
		\delete_option( ProviderUtils::get_option_name( AioRedirectGateway::ID ) );

		if ( false === $this->original_api_mode ) {
			\putenv( 'API_MODE' );
		} else {
			\putenv( "API_MODE={$this->original_api_mode}" );
		}

		parent::tear_down();
	}

	// ========== Helpers ==========

	/**
	 * 寫入 ecpay_aio 設定
	 *
	 * @param array<string, mixed> $extra 額外設定
	 * @return void
	 */
	private function seed_settings( array $extra = [] ): void {
		\update_option(
			ProviderUtils::get_option_name( AioRedirectGateway::ID ),
			\array_merge(
				[
					'enabled'    => 'yes',
					'mode'       => 'test',
					'merchantId' => self::MERCHANT_ID,
					'hashKey'    => self::HASH_KEY,
					'hashIv'     => self::HASH_IV,
				],
				$extra
			)
		);
	}

	/**
	 * 建立綠界 AIO 訂單
	 *
	 * @param string $status 訂單狀態
	 * @return \WC_Order
	 */
	private function create_aio_order( string $status = 'pending' ): \WC_Order {
		return $this->create_wc_order(
			[
				'status'         => $status,
				'payment_method' => AioRedirectGateway::ID,
				'total'          => 1000,
			]
		);
	}

	/**
	 * 重新取得訂單（避免物件快取讀到舊 meta）
	 *
	 * @param \WC_Order $order 訂單
	 * @return \WC_Order
	 */
	private function fresh( \WC_Order $order ): \WC_Order {
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		return $fresh;
	}

	/**
	 * 模擬「新的一個 HTTP 請求」：清除 AioRedirectGateway 的同請求防重入記錄
	 *
	 * @return void
	 */
	private function reset_request(): void {
		if ( ! \property_exists( AioRedirectGateway::class, 'redirect_attempts' ) ) {
			return;
		}
		$property = new \ReflectionProperty( AioRedirectGateway::class, 'redirect_attempts' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * 模擬一次結帳送出（classic / block / order-pay 最後都會呼叫 process_payment）
	 *
	 * @param \WC_Order   $order       訂單
	 * @param string|null $installment 分期欄位值；null 代表未送出欄位
	 * @return array<string, mixed> process_payment 回傳
	 */
	private function submit_payment( \WC_Order $order, ?string $installment = null ): array {
		$this->reset_request();
		unset( $_POST[ InstallmentCheckoutField::FIELD_NAME ] );
		if ( null !== $installment ) {
			$_POST[ InstallmentCheckoutField::FIELD_NAME ] = $installment;
		}

		$gateway = new AioRedirectGateway();
		$this->assertTrue( $gateway->validate_fields() );
		$result = $gateway->process_payment( $order->get_id() );
		unset( $_POST[ InstallmentCheckoutField::FIELD_NAME ] );

		$this->assertSame( 'success', $result['result'] ?? null );
		return $result;
	}

	/**
	 * 呼叫 protected before_order_received（order-received 頁 render 前）並擷取輸出
	 *
	 * @param \WC_Order   $order 訂單
	 * @param string|null $key   網址上的 order key；null 代表帶正確 key
	 * @return string 輸出 HTML（auto-form 或空字串）
	 */
	private function visit_order_received( \WC_Order $order, ?string $key = null ): string {
		$_GET['key'] = $key ?? $order->get_order_key();

		$gateway = new AioRedirectGateway();
		$method  = new \ReflectionMethod( $gateway, 'before_order_received' );
		$method->setAccessible( true );

		\ob_start();
		$method->invoke( $gateway, $this->fresh( $order ) );
		return (string) \ob_get_clean();
	}

	/**
	 * 由 auto-form HTML 解析送往綠界的參數
	 *
	 * @param string $html auto-form 輸出
	 * @return array<string, string>
	 */
	private function parse_form( string $html ): array {
		\preg_match_all( '/<input type="hidden" name="([^"]*)" value="([^"]*)">/', $html, $matches, PREG_SET_ORDER );
		$params = [];
		foreach ( $matches as $row ) {
			$params[ \html_entity_decode( $row[1], ENT_QUOTES ) ] = \html_entity_decode( $row[2], ENT_QUOTES );
		}
		return $params;
	}

	/**
	 * 取得 thank-you 頁「尚未完成付款」提示輸出
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private function render_pending_notice( \WC_Order $order ): string {
		\ob_start();
		AioRedirectGateway::render_pending_payment_notice( $order->get_id() );
		return (string) \ob_get_clean();
	}

	/**
	 * 綠界通知 payload 補上 CheckMacValue
	 *
	 * @param array<string, string> $params 通知參數
	 * @return array<string, string>
	 */
	private function sign( array $params ): array {
		$params['CheckMacValue'] = CheckMacValueService::get_check_value( $params, self::HASH_KEY, self::HASH_IV, 'sha256' );
		return $params;
	}

	/**
	 * 付款結果通知（ReturnURL）payload
	 *
	 * @param string $merchant_trade_no MerchantTradeNo
	 * @param string $trade_no          綠界 TradeNo
	 * @param string $payment_type      綠界 PaymentType
	 * @param string $rtn_code          RtnCode
	 * @return array<string, string>
	 */
	private function return_payload( string $merchant_trade_no, string $trade_no, string $payment_type = 'Credit_CreditCard', string $rtn_code = '1' ): array {
		return $this->sign(
			[
				'MerchantID'      => self::MERCHANT_ID,
				'MerchantTradeNo' => $merchant_trade_no,
				'RtnCode'         => $rtn_code,
				'RtnMsg'          => '1' === $rtn_code ? '交易成功' : '付款失敗',
				'TradeNo'         => $trade_no,
				'TradeAmt'        => '1000',
				'PaymentType'     => $payment_type,
			]
		);
	}

	/**
	 * ATM 取號結果通知（PaymentInfoURL）payload
	 *
	 * @param string $merchant_trade_no MerchantTradeNo
	 * @param string $v_account         虛擬帳號
	 * @return array<string, string>
	 */
	private function atm_info_payload( string $merchant_trade_no, string $v_account ): array {
		return $this->sign(
			[
				'MerchantID'      => self::MERCHANT_ID,
				'MerchantTradeNo' => $merchant_trade_no,
				'RtnCode'         => '2',
				'RtnMsg'          => 'Get VirtualAccount Succeeded',
				'TradeNo'         => '2410120000000' . \substr( $v_account, -3 ),
				'TradeAmt'        => '1000',
				'PaymentType'     => 'ATM_TAISHIN',
				'BankCode'        => '812',
				'vAccount'        => $v_account,
				'ExpireDate'      => '2099/12/31',
			]
		);
	}

	/**
	 * 發起一次付款並導轉，回傳本次送往綠界的 MerchantTradeNo
	 *
	 * @param \WC_Order   $order       訂單
	 * @param string|null $installment 分期欄位值
	 * @return array{trade_no: string, form: array<string, string>}
	 */
	private function pay_attempt( \WC_Order $order, ?string $installment = null ): array {
		$this->submit_payment( $order, $installment );
		$this->reset_request(); // 瀏覽器導向 order-received 是另一個請求
		$html = $this->visit_order_received( $order );
		$this->assertStringContainsString( self::CASHIER_URL, $html, '發起付款後 order-received 應輸出導轉綠界的表單' );

		$form = $this->parse_form( $html );
		$this->assertNotEmpty( $form['MerchantTradeNo'] ?? '' );
		return [
			'trade_no' => $form['MerchantTradeNo'],
			'form'     => $form,
		];
	}

	/**
	 * 取得訂單所有 order note
	 *
	 * @param \WC_Order $order 訂單
	 * @return string 全部 note 串接
	 */
	private function notes( \WC_Order $order ): string {
		$notes = \wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		return \implode( "\n", \array_map( static fn( $note ): string => (string) $note->content, $notes ) );
	}

	/**
	 * pre_http_request 攔截：DoAction 回成功；其餘外部請求擋下
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

		$body                      = \is_array( $parsed_args['body'] ?? null ) ? $parsed_args['body'] : [];
		$this->captured_requests[] = $body;

		return [
			'headers'  => [ 'content-type' => 'text/html; charset=utf-8' ],
			'body'     => \http_build_query(
				[
					'MerchantID'      => self::MERCHANT_ID,
					'MerchantTradeNo' => (string) ( $body['MerchantTradeNo'] ?? '' ),
					'TradeNo'         => (string) ( $body['TradeNo'] ?? '' ),
					'RtnCode'         => '1',
					'RtnMsg'          => 'Succeeded.',
				]
			),
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	// ========== 核心：沿用的 pending 訂單再次付款要能重新導轉 ==========

	/**
	 * 首次付款：輸出導轉表單，編號寫入目前編號與歷史
	 *
	 * @test
	 * @group smoke
	 */
	public function test_首次付款輸出導轉表單並記錄編號(): void {
		$order = $this->create_aio_order();

		$attempt = $this->pay_attempt( $order );

		$meta_keys = new EcpayMetaKeys( $this->fresh( $order ) );
		$this->assertSame( $attempt['trade_no'], $meta_keys->get_trade_no() );
		$this->assertSame( [ $attempt['trade_no'] ], $meta_keys->get_trade_no_history() );
		$this->assertMatchesRegularExpression( '/^' . $order->get_id() . 'TS\d+$/', $attempt['trade_no'] );
	}

	/**
	 * 訂單 6518 情境：pending 訂單已有 MerchantTradeNo，顧客再次結帳（WC 沿用訂單）→ 重新輸出導轉表單，
	 * 且用新的 MerchantTradeNo（綠界拒收重複編號 10300028），舊編號保留於歷史
	 *
	 * @test
	 * @group happy
	 */
	public function test_沿用的pending訂單再次付款時以新編號重新導轉(): void {
		$order = $this->create_aio_order();
		$first = $this->pay_attempt( $order );

		// When: 顧客沒付款，回商店再結帳一次（同一張訂單）
		$second = $this->pay_attempt( $order );

		// Then: 新編號、目前編號更新、歷史保留兩個
		$this->assertNotSame( $first['trade_no'], $second['trade_no'], '重試必須產生新的 MerchantTradeNo' );
		$meta_keys = new EcpayMetaKeys( $this->fresh( $order ) );
		$this->assertSame( $second['trade_no'], $meta_keys->get_trade_no() );
		$this->assertSame( [ $first['trade_no'], $second['trade_no'] ], $meta_keys->get_trade_no_history() );
	}

	/**
	 * 「我的帳戶 → 付款」（order-pay → process_payment）第三次重試同樣重新導轉
	 *
	 * @test
	 * @group happy
	 */
	public function test_多次重試每次都產生不重複的新編號(): void {
		$order = $this->create_aio_order();

		$trade_nos = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$trade_nos[] = $this->pay_attempt( $order )['trade_no'];
		}

		$this->assertCount( 3, \array_unique( $trade_nos ) );
		$this->assertSame( $trade_nos, ( new EcpayMetaKeys( $this->fresh( $order ) ) )->get_trade_no_history() );
	}

	/**
	 * 同一請求內 before_order_received 被觸發兩次（多個 gateway 實例各自掛 wp hook）→ 只產生一個編號、只輸出一次表單
	 *
	 * @test
	 * @group edge
	 */
	public function test_同一請求內重複觸發只產生一個編號(): void {
		$order = $this->create_aio_order();
		$this->submit_payment( $order );
		$this->reset_request();

		$first  = $this->visit_order_received( $order );
		$second = $this->visit_order_received( $order ); // 同一請求（未 reset）

		$this->assertStringContainsString( self::CASHIER_URL, $first );
		$this->assertSame( '', \trim( $second ), '同一請求不得輸出第二份表單' );
		$this->assertCount( 1, ( new EcpayMetaKeys( $this->fresh( $order ) ) )->get_trade_no_history() );
	}

	/**
	 * 單純重訪 order-received（重新整理 / 上一頁 / 綠界「返回商店」）不自動導轉，避免與 ClientBackURL 形成迴圈；
	 * 改於 thank-you 頁提供「前往付款」連結（order-pay → process_payment → 重新導轉）
	 *
	 * @test
	 * @group edge
	 */
	public function test_單純重訪order_received不自動導轉但提供付款連結(): void {
		$order = $this->create_aio_order();
		$first = $this->pay_attempt( $order );

		// When: 另一個請求重訪（未經 process_payment）
		$this->reset_request();
		$html = $this->visit_order_received( $order );

		// Then: 不輸出表單、不產生新編號
		$this->assertSame( '', \trim( $html ) );
		$this->assertSame( [ $first['trade_no'] ], ( new EcpayMetaKeys( $this->fresh( $order ) ) )->get_trade_no_history() );

		// And: thank-you 頁有前往付款（order-pay）連結
		$notice = $this->render_pending_notice( $this->fresh( $order ) );
		$this->assertStringContainsString( \esc_url( $this->fresh( $order )->get_checkout_payment_url() ), $notice );
	}

	/**
	 * 同一請求剛輸出 auto-form 時，thank-you 頁不再顯示付款提示（頁面即將自動導轉）
	 *
	 * @test
	 * @group edge
	 */
	public function test_自動導轉的請求不顯示付款提示(): void {
		$order = $this->create_aio_order();
		$this->submit_payment( $order );
		$this->reset_request();

		$this->assertStringContainsString( self::CASHIER_URL, $this->visit_order_received( $order ) );
		$this->assertSame( '', \trim( $this->render_pending_notice( $this->fresh( $order ) ) ) );
	}

	/**
	 * 建單參數組裝失敗（例：第三方寫入不在白名單的分期期數）→ 不輸出表單、不記錄編號、保留待導轉旗標，
	 * 同請求其他 gateway 實例不重試；thank-you 頁仍提供付款連結
	 *
	 * @test
	 * @group error
	 */
	public function test_建單失敗時不記錄編號且仍提供付款連結(): void {
		$this->seed_settings( [ 'installmentPeriods' => [ 3, 6 ] ] );
		$order     = $this->create_aio_order();
		$meta_keys = new EcpayMetaKeys( $order );
		$meta_keys->update_credit_variant( 'installment' );
		$meta_keys->update_installment( '3,9' ); // 9 不在白名單 → RequestParams throw
		$this->submit_payment( $order );
		$this->reset_request();

		$this->assertSame( '', \trim( $this->visit_order_received( $order ) ) );
		$this->assertSame( '', \trim( $this->visit_order_received( $order ) ), '同請求不得重試建單' );

		$fresh = $this->fresh( $order );
		$this->assertSame( '', ( new EcpayMetaKeys( $fresh ) )->get_trade_no(), '建單失敗不得記錄編號' );
		$this->assertTrue( ( new EcpayMetaKeys( $fresh ) )->is_redirect_pending(), '待導轉旗標須保留，修正設定後重訪即可導轉' );
		$this->assertStringContainsString( \esc_url( $fresh->get_checkout_payment_url() ), $this->render_pending_notice( $fresh ) );
	}

	// ========== 已付款 / 越權 不得導轉 ==========

	/**
	 * 已付款（processing / completed）訂單即使帶著待導轉旗標也絕不再導去綠界，亦不顯示付款提示
	 *
	 * @test
	 * @group security
	 */
	public function test_已付款訂單不再導轉也不顯示付款提示(): void {
		foreach ( [ 'processing', 'completed' ] as $status ) {
			$order = $this->create_aio_order();
			$this->submit_payment( $order ); // 留下待導轉旗標
			$order = $this->fresh( $order );
			$order->update_status( $status );

			$this->reset_request();
			$html = $this->visit_order_received( $order );

			$this->assertSame( '', \trim( $html ), "{$status} 訂單不得再導轉綠界" );
			$this->assertSame( '', ( new EcpayMetaKeys( $this->fresh( $order ) ) )->get_trade_no(), "{$status} 訂單不得產生 MerchantTradeNo" );
			$this->assertSame( '', \trim( $this->render_pending_notice( $this->fresh( $order ) ) ), "{$status} 訂單不得顯示付款提示" );
		}
	}

	/**
	 * order key 不符（匿名列舉 /checkout/order-received/{id}/）→ 不導轉、不產生編號
	 *
	 * @test
	 * @group security
	 */
	public function test_order_key不符時不導轉不產生編號(): void {
		$order = $this->create_aio_order();
		$this->submit_payment( $order );
		$this->reset_request();

		$this->assertSame( '', \trim( $this->visit_order_received( $order, 'wc_order_forged' ) ) );
		$this->assertSame( '', \trim( $this->visit_order_received( $order, '' ) ) );
		$this->assertSame( '', ( new EcpayMetaKeys( $this->fresh( $order ) ) )->get_trade_no() );
	}

	// ========== 舊編號 callback 仍能反查訂單並正確入帳 ==========

	/**
	 * 第一次選 ATM 取得虛擬帳號（T1），回來重試改刷卡（T2 未付），之後用 T1 的帳號繳費 →
	 * T1 的 PaymentInfoURL / ReturnURL 皆能找到訂單並入帳，付款成功編號寫回 `_pc_ecpay_trade_no`
	 *
	 * @test
	 * @group happy
	 */
	public function test_舊編號ATM取號與繳費通知仍能找到訂單並入帳(): void {
		$order = $this->create_aio_order();
		$t1    = $this->pay_attempt( $order )['trade_no'];

		// Given: T1 取號成功
		AioCallback::instance()->handle_payment_info( $this->atm_info_payload( $t1, '9103522850001' ) );
		$this->assertSame( $t1, ( new EcpayMetaKeys( $this->fresh( $order ) ) )->get_payment_info()['MerchantTradeNo'] ?? null );

		// And: 顧客回來重試（改刷卡，未付款）
		$t2 = $this->pay_attempt( $order )['trade_no'];
		$this->assertSame( $t2, ( new EcpayMetaKeys( $this->fresh( $order ) ) )->get_trade_no() );

		// When: 顧客用 T1 的虛擬帳號繳費
		AioCallback::instance()->handle_return( $this->return_payload( $t1, '2410120000000001', 'ATM_TAISHIN' ) );

		// Then: 訂單入帳、明細為 T1、目前編號寫回 T1（實際付款成功的那一個）
		$fresh = $this->fresh( $order );
		$this->assertSame( 'processing', $fresh->get_status() );
		$meta_keys = new EcpayMetaKeys( $fresh );
		$this->assertSame( $t1, $meta_keys->get_payment_detail()['MerchantTradeNo'] ?? null );
		$this->assertSame( $t1, $meta_keys->get_trade_no(), '付款成功的編號須寫回 _pc_ecpay_trade_no' );
		$this->assertContains( $t2, $meta_keys->get_trade_no_history(), 'T2 仍須可反查' );
		$this->assertSame( $fresh->get_id(), EcpayMetaKeys::get_order_by_trade_no( $t2 )?->get_id() );
	}

	/**
	 * 重試後新編號取號（T2）→ 繳費資訊更新為 T2（舊版以「已有繳費資訊」為由略過，顯示過期資訊）；同編號重送不重複寫入
	 *
	 * @test
	 * @group edge
	 */
	public function test_重試後新編號取號會更新繳費資訊且同編號重送冪等(): void {
		$order = $this->create_aio_order();
		$t1    = $this->pay_attempt( $order )['trade_no'];
		AioCallback::instance()->handle_payment_info( $this->atm_info_payload( $t1, '9103522850001' ) );

		$t2 = $this->pay_attempt( $order )['trade_no'];
		AioCallback::instance()->handle_payment_info( $this->atm_info_payload( $t2, '9103522850002' ) );
		AioCallback::instance()->handle_payment_info( $this->atm_info_payload( $t2, '9103522850002' ) ); // 重送

		$info = ( new EcpayMetaKeys( $this->fresh( $order ) ) )->get_payment_info();
		$this->assertSame( $t2, $info['MerchantTradeNo'] ?? null );
		$this->assertSame( '9103522850002', $info['vAccount'] ?? null );
		$this->assertSame( 1, \substr_count( $this->notes( $order ), '9103522850002' ), '同編號重送不得重複寫 note' );
		$this->assertSame( 'pending', $this->fresh( $order )->get_status() );
	}

	/**
	 * 舊版訂單（升級前只有 `_pc_ecpay_trade_no`、沒有歷史）重試 → 舊編號補進歷史，其 callback 仍能入帳
	 *
	 * @test
	 * @group edge
	 */
	public function test_升級前無歷史的訂單重試後舊編號仍可反查(): void {
		$order  = $this->create_aio_order();
		$legacy = $order->get_id() . 'TS0123456789';
		( new EcpayMetaKeys( $order ) )->update_trade_no( $legacy );

		$new = $this->pay_attempt( $order )['trade_no'];

		$this->assertNotSame( $legacy, $new );
		$this->assertSame( [ $legacy, $new ], ( new EcpayMetaKeys( $this->fresh( $order ) ) )->get_trade_no_history() );

		AioCallback::instance()->handle_return( $this->return_payload( $legacy, '2410120000000002' ) );
		$this->assertSame( 'processing', $this->fresh( $order )->get_status() );
		$this->assertSame( $legacy, ( new EcpayMetaKeys( $this->fresh( $order ) ) )->get_trade_no() );
	}

	// ========== callback 冪等 / 重複付款 ==========

	/**
	 * T2 已付款後又收到 T1 付款成功（顧客兩邊都付了）→ 不覆寫 T2 的付款明細與編號，加註重複付款警告供人工退款
	 *
	 * @test
	 * @group edge
	 */
	public function test_已付款後收到另一編號付款成功時加註重複付款且不覆寫(): void {
		$order = $this->create_aio_order();
		$t1    = $this->pay_attempt( $order )['trade_no'];
		$t2    = $this->pay_attempt( $order )['trade_no'];

		AioCallback::instance()->handle_return( $this->return_payload( $t2, '2410120000000222' ) );
		AioCallback::instance()->handle_return( $this->return_payload( $t1, '2410120000000111', 'ATM_TAISHIN' ) );

		$fresh     = $this->fresh( $order );
		$meta_keys = new EcpayMetaKeys( $fresh );
		$this->assertSame( 'processing', $fresh->get_status() );
		$this->assertSame( '2410120000000222', $meta_keys->get_payment_detail()['TradeNo'] ?? null );
		$this->assertSame( $t2, $meta_keys->get_trade_no() );
		$this->assertStringContainsString( '重複付款', $this->notes( $fresh ) );
		$this->assertStringContainsString( $t1, $this->notes( $fresh ) );
	}

	/**
	 * 已完成（completed）訂單收到同編號付款成功重送 → 不回退成 processing、不覆寫明細、不誤報重複付款
	 *
	 * @test
	 * @group edge
	 */
	public function test_已完成訂單收到同編號重送時不重複入帳(): void {
		$order = $this->create_aio_order();
		$t1    = $this->pay_attempt( $order )['trade_no'];
		$paid  = $this->return_payload( $t1, '2410120000000333' );
		AioCallback::instance()->handle_return( $paid );

		$fresh = $this->fresh( $order );
		$fresh->update_status( 'completed' );

		// When: 綠界重送同一筆
		AioCallback::instance()->handle_return( $paid );

		$fresh = $this->fresh( $order );
		$this->assertSame( 'completed', $fresh->get_status() );
		$this->assertStringNotContainsString( '重複付款', $this->notes( $fresh ) );
	}

	// ========== 退款用實際付款成功的編號 ==========

	/**
	 * T1 付款成功、T2 為之後產生的編號 → 退款 DoAction 送出的 MerchantTradeNo / TradeNo 皆為 T1 的
	 *
	 * @test
	 * @group happy
	 */
	public function test_退款使用實際付款成功的MerchantTradeNo與TradeNo(): void {
		// Given: T1、T2 都送出過；顧客在 T1 的分頁完成刷卡
		$order = $this->create_aio_order();
		$t1    = $this->pay_attempt( $order )['trade_no'];
		$t2    = $this->pay_attempt( $order )['trade_no'];
		AioCallback::instance()->handle_return( $this->return_payload( $t1, '2410120000000444' ) );
		$this->assertSame( 'processing', $this->fresh( $order )->get_status() );

		// When: 後台退款（走真實 HTTP 組裝路徑，攔截 DoAction）
		\putenv( 'API_MODE=sandbox' );
		\add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
		WC()->payment_gateways()->init();
		$refund = \wc_create_refund(
			[
				'order_id'       => $order->get_id(),
				'amount'         => 300,
				'reason'         => '重試付款退款',
				'refund_payment' => true,
			]
		);

		// Then
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertCount( 1, $this->captured_requests );
		$this->assertSame( $t1, $this->captured_requests[0]['MerchantTradeNo'] ?? null );
		$this->assertNotSame( $t2, $this->captured_requests[0]['MerchantTradeNo'] ?? null );
		$this->assertSame( '2410120000000444', $this->captured_requests[0]['TradeNo'] ?? null );
	}

	// ========== 與 Bug 1 合併：訂單 6518 完整重現 ==========

	/**
	 * 選 3 期導轉 → 未付返回 → 同一訂單改選「不分期」再結帳 → 新表單不帶 CreditInstallment、改回 ALL、編號更新
	 *
	 * @test
	 * @group happy
	 */
	public function test_訂單6518情境先選3期再改不分期重新導轉不帶分期(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'Credit', 'ATM', 'CVS' ],
				'installmentPeriods' => [ 3, 6, 12 ],
				'enableInstallment'  => true,
			]
		);
		$order = $this->create_aio_order();

		$first = $this->pay_attempt( $order, '3' );
		$this->assertSame( '3', $first['form']['CreditInstallment'] ?? null );
		$this->assertSame( 'Credit', $first['form']['ChoosePayment'] ?? null );

		$second = $this->pay_attempt( $order, '' );

		$this->assertNotSame( $first['trade_no'], $second['trade_no'] );
		$this->assertArrayNotHasKey( 'CreditInstallment', $second['form'], '改選不分期後不得再送 CreditInstallment' );
		$this->assertSame( 'ALL', $second['form']['ChoosePayment'] ?? null );
	}

	// ========== TradeNo 唯一性 ==========

	/**
	 * 同一秒內重試：TradeNo::encode 會產生相同編號 → encode_unique 須避開已用過的編號，且仍 ≤ 20 碼英數字
	 *
	 * @test
	 * @group edge
	 */
	public function test_同秒重試時編號仍不重複(): void {
		$order_id = 1234567;
		$used     = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$candidate = TradeNo::encode_unique( $order_id, $used );
			$this->assertNotContains( $candidate, $used );
			$this->assertLessThanOrEqual( 20, \strlen( $candidate ) );
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]+$/', $candidate );
			$used[] = $candidate;
		}
	}
}
