<?php
/**
 * 綠界 ECPay AIO 信用卡分期回歸測試（後台分期設定從未生效，與藍新 Issue #21 同類缺陷）
 *
 * 缺陷：RequestParams::build_credit_variant() 只在 order meta `_pc_ecpay_credit_variant === 'installment'`
 * 時送 CreditInstallment，但全專案沒有任何程式寫入該 meta（無結帳頁分期 UI），
 * 導致 Vue 設定頁的「信用卡分期期數」勾了也沒用。
 *
 * 查證結論（不能比照藍新做成「全站開關一律送期數」）：
 *  - 綠界收銀台（payment-stage.ecpay.com.tw AioCheckOut/V5 實測）只要 CreditInstallment 帶有效期數，
 *    `_viewPageType` 即為 'CreditInstallment'，信用卡區塊的「一次付清」選項被隱藏（shouldShowCreditRadio=false），
 *    且期數下拉必選（selectedInstalmentVal <= 0 驗證失敗）→ 帶了就只能分期。
 *  - 綠界 30 期為永豐「圓夢彈性分期」，參數必須送 `30N`；送 `30` 會被綠界靜默忽略
 *    （只送 "30" 時頁面退回一般信用卡一次付清）。
 *
 * 修法：改為「結帳頁讓顧客自選」：
 *  - AioSettingsDTO 新增 enableInstallment（預設關閉）；開啟 + allowedPayments 含 Credit + 期數非空時，
 *    classic checkout（payment_fields）與 block checkout（ecpay_aio.tsx）顯示分期下拉。
 *  - 顧客選期數 → validate_fields 白名單驗證 → before_process_payment 寫 order meta
 *    → RequestParams 送 ChoosePayment=Credit + CreditInstallment（30 → 30N）。
 *  - order meta 覆寫路徑（向後相容第三方外掛）改為逐段 ctype_digit + 白名單驗證；'period' 路徑維持原樣。
 *
 * @group integration
 * @group payment
 * @group ecpay
 * @group credit-variants
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\AioSettingsDTO;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\RequestParams;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\AioRedirectGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\InstallmentCheckoutField;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\AioBlocksIntegration;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\CheckMacValueService;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Domains\Settings\Services\SettingApiService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界 AIO 信用卡分期測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 * @group credit-variants
 */
final class EcpayAioInstallmentTest extends TestCase {

	/** @var string 綠界 AIO 公開測試帳號 HashKey */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界 AIO 公開測試帳號 HashIV */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	/** @var string 綠界 AIO 公開測試帳號 MerchantID */
	private const MERCHANT_ID = '3002607';

	/** 每次測試前：MOCK 模式 + WC session（validate_fields 的 wc_add_notice 需要 session） */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );

		$wc = \WC();
		if ( ! isset( $wc->session ) || ! $wc->session instanceof \WC_Session ) {
			$wc->initialize_session();
		}
		\wc_clear_notices();
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		unset( $_POST[ InstallmentCheckoutField::FIELD_NAME ] );
		\wc_clear_notices();
		\delete_option( ProviderUtils::get_option_name( AioRedirectGateway::ID ) );
		\putenv( 'API_MODE' );
		parent::tear_down();
	}

	// ========== Helpers ==========

	/**
	 * 寫入 ecpay_aio 設定（覆蓋整個 option，模擬既有 DB 狀態）
	 *
	 * @param array<string, mixed> $extra 額外設定（allowedPayments / installmentPeriods / enableInstallment 等）
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
	 * 寫入「分期開關開啟 + Credit + 指定期數」設定
	 *
	 * @param array<int> $periods 分期期數
	 * @return void
	 */
	private function seed_enabled( array $periods = [ 3, 6, 12 ] ): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'Credit', 'ATM', 'CVS' ],
				'installmentPeriods' => $periods,
				'enableInstallment'  => true,
			]
		);
	}

	/**
	 * 建立綠界 AIO 訂單
	 *
	 * @param array<string, string> $meta 訂單 meta（credit_variant / installment）
	 * @return \WC_Order
	 */
	private function create_aio_order( array $meta = [] ): \WC_Order {
		$order     = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => AioRedirectGateway::ID,
				'total'          => 12000,
			]
		);
		$meta_keys = new EcpayMetaKeys( $order );
		if ( isset( $meta['credit_variant'] ) ) {
			$meta_keys->update_credit_variant( $meta['credit_variant'] );
		}
		if ( isset( $meta['installment'] ) ) {
			$meta_keys->update_installment( $meta['installment'] );
		}
		$order->save();
		return $order;
	}

	/**
	 * 組裝建單參數（等同 before_order_received 的組裝步驟），回傳實際送往綠界的 form 參數
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, string|int>
	 */
	private function build_form( \WC_Order $order ): array {
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		return RequestParams::instance( $fresh, new AioRedirectGateway() )->to_form_params();
	}

	/**
	 * 斷言 meta 覆寫值會讓建單拋出「分期期數不在允許範圍」
	 *
	 * @param string $installment _pc_ecpay_installment 值
	 * @return void
	 */
	private function assert_override_rejected( string $installment ): void {
		$order = $this->create_aio_order(
			[
				'credit_variant' => 'installment',
				'installment'    => $installment,
			]
		);

		$thrown = null;
		try {
			$this->build_form( $order );
		} catch ( \Exception $e ) {
			$thrown = $e;
		}

		$this->assertNotNull( $thrown, "預期 installment='{$installment}' 應拋例外" );
		$this->assertStringContainsString( '分期期數不在允許範圍', $thrown->getMessage() );
	}

	/**
	 * 模擬顧客於結帳頁送出分期欄位（classic form POST / block payment_data 皆落在 $_POST）
	 *
	 * @param string $value 欄位值（'' 代表不分期）
	 * @return void
	 */
	private function post_installment( string $value ): void {
		$_POST[ InstallmentCheckoutField::FIELD_NAME ] = $value;
	}

	/**
	 * 取得 payment_fields() 輸出的 HTML
	 *
	 * @return string
	 */
	private function render_payment_fields(): string {
		\ob_start();
		( new AioRedirectGateway() )->payment_fields();
		return (string) \ob_get_clean();
	}

	/**
	 * 取得目前 error notice 文字
	 *
	 * @return array<int, string>
	 */
	private function get_error_notices(): array {
		return \array_values(
			\array_map(
				static fn( $notice ): string => \is_array( $notice ) ? (string) ( $notice['notice'] ?? '' ) : (string) $notice,
				\wc_get_notices( 'error' )
			)
		);
	}

	// ========== 結帳頁可選期數：開關 / Credit / 期數 ==========

	/**
	 * DTO 預設：enableInstallment 關閉、預設期數不含 30（30 期須另申請圓夢彈性分期），get_settings 帶出開關
	 *
	 * @test
	 * @group smoke
	 */
	public function test_設定DTO預設關閉且get_settings含開關(): void {
		$this->seed_settings();

		$dto = new AioSettingsDTO();
		$this->assertFalse( $dto->enableInstallment );
		$this->assertSame( [ 3, 6, 12, 18, 24 ], $dto->installmentPeriods );

		$settings = AioRedirectGateway::get_settings();
		$this->assertArrayHasKey( 'enableInstallment', $settings );
		$this->assertFalse( $settings['enableInstallment'] );
	}

	/**
	 * 舊 option 沒有 enableInstallment（升級前既有商家，DB 已存 6 個期數）→ 預設關閉、結帳頁不顯示、建單不送分期
	 *
	 * @test
	 * @group happy
	 */
	public function test_舊設定無開關key時預設關閉且不顯示分期欄位(): void {
		// Given: 既有 DB 有 Credit 與舊預設期數，但沒有新開關
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'Credit', 'ATM', 'WebATM', 'CVS', 'BARCODE', 'ApplePay' ],
				'installmentPeriods' => [ 3, 6, 12, 18, 24, 30 ],
			]
		);

		// Then: 開關關閉、無可選期數、結帳頁無分期欄位
		$this->assertFalse( AioSettingsDTO::instance()->enableInstallment );
		$this->assertSame( [], AioSettingsDTO::instance()->get_checkout_installment_periods() );
		$this->assertFalse( ( new AioRedirectGateway() )->has_fields() );
		$this->assertStringNotContainsString( InstallmentCheckoutField::FIELD_NAME, $this->render_payment_fields() );

		// And: 建單維持 ALL 流程，不送 CreditInstallment
		$form = $this->build_form( $this->create_aio_order() );
		$this->assertSame( 'ALL', $form['ChoosePayment'] );
		$this->assertArrayNotHasKey( 'CreditInstallment', $form );
	}

	/**
	 * 開關明確關閉（REST 存成空字串 / false / 'no' / '0'）→ 無可選期數
	 *
	 * @test
	 * @group edge
	 */
	public function test_開關明確關閉時無可選期數(): void {
		foreach ( [ '', false, 'no', '0' ] as $off_value ) {
			$this->seed_settings(
				[
					'allowedPayments'    => [ 'Credit' ],
					'installmentPeriods' => [ 3, 6 ],
					'enableInstallment'  => $off_value,
				]
			);

			$this->assertSame(
				[],
				AioSettingsDTO::instance()->get_checkout_installment_periods(),
				'開關值 ' . \wp_json_encode( $off_value ) . ' 應視為關閉'
			);
		}
	}

	/**
	 * 開關開啟 + Credit + 期數 [3,6] → 結帳頁顯示不分期 / 3 期 / 6 期下拉
	 *
	 * @test
	 * @group happy
	 */
	public function test_開關開啟且Credit時結帳頁顯示分期下拉(): void {
		$this->seed_enabled( [ 3, 6 ] );

		$this->assertSame( [ 3, 6 ], AioSettingsDTO::instance()->get_checkout_installment_periods() );
		$this->assertTrue( ( new AioRedirectGateway() )->has_fields() );

		$html = $this->render_payment_fields();
		$this->assertStringContainsString( 'name="' . InstallmentCheckoutField::FIELD_NAME . '"', $html );
		$this->assertStringContainsString( '<option value="">', $html );
		$this->assertStringContainsString( '<option value="3">', $html );
		$this->assertStringContainsString( '<option value="6">', $html );
		$this->assertStringNotContainsString( '<option value="12">', $html );
	}

	/**
	 * 期數亂序 / 重複 / 字串（admin form 送來）→ 排序去重
	 *
	 * @test
	 * @group edge
	 */
	public function test_期數亂序重複時排序去重(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'ATM', 'Credit' ],
				'installmentPeriods' => [ '12', 3, '6', 3, 12 ],
				'enableInstallment'  => '1', // REST sanitize_text_field 後的 true
			]
		);

		$this->assertSame( [ 3, 6, 12 ], AioSettingsDTO::instance()->get_checkout_installment_periods() );
	}

	/**
	 * 開關開啟但 allowedPayments 不含 Credit → 無可選期數、不顯示欄位
	 *
	 * @test
	 * @group edge
	 */
	public function test_開關開啟但未啟用Credit時無可選期數(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'ATM', 'CVS' ],
				'installmentPeriods' => [ 3, 6 ],
				'enableInstallment'  => true,
			]
		);

		$this->assertSame( [], AioSettingsDTO::instance()->get_checkout_installment_periods() );
		$this->assertFalse( ( new AioRedirectGateway() )->has_fields() );
	}

	/**
	 * 開關開啟但期數為空陣列 → 無可選期數
	 *
	 * @test
	 * @group edge
	 */
	public function test_開關開啟但期數為空陣列時無可選期數(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'Credit' ],
				'installmentPeriods' => [],
				'enableInstallment'  => true,
			]
		);

		$this->assertSame( [], AioSettingsDTO::instance()->get_checkout_installment_periods() );
	}

	// ========== 顧客於結帳頁選擇 → 寫 meta → 建單參數 ==========

	/**
	 * 顧客選 6 期 → validate_fields 通過 → process_payment 寫 meta → 建單送 Credit + CreditInstallment=6
	 *
	 * @test
	 * @group happy
	 */
	public function test_顧客選6期時寫入meta且建單送CreditInstallment(): void {
		// Given
		$this->seed_enabled( [ 3, 6, 12 ] );
		$order   = $this->create_aio_order();
		$gateway = new AioRedirectGateway();
		$this->post_installment( '6' );

		// When: WC checkout 先 validate_fields 再 process_payment
		$this->assertTrue( $gateway->validate_fields() );
		$result = $gateway->process_payment( $order->get_id() );

		// Then: 付款流程成功、meta 寫入
		$this->assertSame( 'success', $result['result'] );
		$fresh     = \wc_get_order( $order->get_id() );
		$meta_keys = new EcpayMetaKeys( $fresh );
		$this->assertSame( 'installment', $meta_keys->get_credit_variant() );
		$this->assertSame( '6', $meta_keys->get_installment() );

		// And: 送往綠界的參數改為 Credit-only + CreditInstallment=6（不再帶 IgnorePayment）
		$form = $this->build_form( $order );
		$this->assertSame( 'Credit', $form['ChoosePayment'] );
		$this->assertSame( '6', $form['CreditInstallment'] );
		$this->assertArrayNotHasKey( 'IgnorePayment', $form );
	}

	/**
	 * 顧客選「不分期」（空字串）→ 不寫 meta，建單維持 ALL 流程（一次付清或其他付款方式）
	 *
	 * @test
	 * @group happy
	 */
	public function test_顧客選不分期時不寫meta且維持ALL流程(): void {
		$this->seed_enabled();
		$order   = $this->create_aio_order();
		$gateway = new AioRedirectGateway();
		$this->post_installment( '' );

		$this->assertTrue( $gateway->validate_fields() );
		$gateway->process_payment( $order->get_id() );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( '', ( new EcpayMetaKeys( $fresh ) )->get_credit_variant() );

		$form = $this->build_form( $order );
		$this->assertSame( 'ALL', $form['ChoosePayment'] );
		$this->assertArrayNotHasKey( 'CreditInstallment', $form );
	}

	/**
	 * 顧客送出未啟用的期數（竄改）→ validate_fields 失敗 + error notice，process_payment 也不寫 meta
	 *
	 * @test
	 * @group security
	 */
	public function test_結帳送出未啟用期數時驗證失敗且不寫meta(): void {
		$this->seed_enabled( [ 3, 6 ] );
		$order   = $this->create_aio_order();
		$gateway = new AioRedirectGateway();
		$this->post_installment( '24' );

		// validate_fields 擋下並提示
		$this->assertFalse( $gateway->validate_fields() );
		$notices = $this->get_error_notices();
		$this->assertCount( 1, $notices );
		$this->assertStringContainsString( '分期期數不在允許範圍', $notices[0] );

		// 即使略過 validate_fields（order-pay 等其他路徑），process_payment 仍擋下且不寫 meta
		$result = $gateway->process_payment( $order->get_id() );
		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( '', ( new EcpayMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_credit_variant() );
	}

	/**
	 * 開關關閉時仍送出分期欄位（竄改 / 快取舊頁面）→ 驗證失敗
	 *
	 * @test
	 * @group security
	 */
	public function test_開關關閉時送出分期欄位驗證失敗(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'Credit' ],
				'installmentPeriods' => [ 3, 6 ],
			]
		);
		$this->post_installment( '6' );

		$this->assertFalse( ( new AioRedirectGateway() )->validate_fields() );
	}

	/**
	 * 結帳欄位格式不合法（非數字 / 多期 / 負數 / 陣列）→ 驗證失敗
	 *
	 * @test
	 * @group security
	 */
	public function test_結帳欄位格式不合法時驗證失敗(): void {
		$this->seed_enabled( [ 3, 6, 12 ] );
		$gateway = new AioRedirectGateway();

		foreach ( [ 'abc', '6abc', '-3', '3,6', ' 6 x' ] as $value ) {
			\wc_clear_notices();
			$this->post_installment( $value );
			$this->assertFalse( $gateway->validate_fields(), "欄位值 '{$value}' 應驗證失敗" );
		}

		\wc_clear_notices();
		$_POST[ InstallmentCheckoutField::FIELD_NAME ] = [ '6' ];
		$this->assertFalse( $gateway->validate_fields(), '陣列語法應驗證失敗' );
	}

	/**
	 * 未送出分期欄位（block checkout 舊快取 / 其他外掛）→ 驗證通過、視同不分期
	 *
	 * @test
	 * @group edge
	 */
	public function test_未送出分期欄位時驗證通過視同不分期(): void {
		$this->seed_enabled();
		$order   = $this->create_aio_order();
		$gateway = new AioRedirectGateway();

		$this->assertTrue( $gateway->validate_fields() );
		$gateway->process_payment( $order->get_id() );

		$this->assertSame( '', ( new EcpayMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_credit_variant() );
	}

	// ========== 同一訂單重新結帳時改選（WC 以購物車 hash 沿用同一張 pending 訂單） ==========

	/**
	 * 模擬一次結帳送出：validate_fields → process_payment
	 *
	 * @param \WC_Order   $order       訂單（WC 沿用的同一張）
	 * @param string|null $installment 分期欄位值；null 代表本次未送出欄位
	 * @return void
	 */
	private function submit_checkout( \WC_Order $order, ?string $installment ): void {
		unset( $_POST[ InstallmentCheckoutField::FIELD_NAME ] );
		if ( null !== $installment ) {
			$this->post_installment( $installment );
		}

		$gateway = new AioRedirectGateway();
		$this->assertTrue( $gateway->validate_fields() );
		$this->assertSame( 'success', $gateway->process_payment( $order->get_id() )['result'] );
	}

	/**
	 * 先選 3 期（未付款返回）→ 同一訂單改選「不分期」再結帳 → 分期 meta 清空，建單回到 ALL、不送 CreditInstallment
	 *
	 * 修正前：apply_to_order 遇空字串直接 return，meta 殘留 installment / 3，重新導轉會帶 CreditInstallment=3。
	 *
	 * @test
	 * @group happy
	 */
	public function test_同一訂單先選3期再改選不分期時清除分期meta(): void {
		$this->seed_enabled( [ 3, 6, 12 ] );
		$order = $this->create_aio_order();

		// 第一次：3 期
		$this->submit_checkout( $order, '3' );
		$this->assertSame( '3', $this->build_form( $order )['CreditInstallment'] ?? null );

		// 第二次：不分期
		$this->submit_checkout( $order, '' );

		$meta_keys = new EcpayMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '', $meta_keys->get_credit_variant() );
		$this->assertSame( '', $meta_keys->get_installment() );

		$form = $this->build_form( $order );
		$this->assertSame( 'ALL', $form['ChoosePayment'] );
		$this->assertArrayNotHasKey( 'CreditInstallment', $form, '改選不分期後第二次送出不得帶 CreditInstallment' );
	}

	/**
	 * 先選 3 期 → 改選 6 期 → meta 以最後一次為準
	 *
	 * @test
	 * @group happy
	 */
	public function test_同一訂單改選其他期數時以最後一次為準(): void {
		$this->seed_enabled( [ 3, 6, 12 ] );
		$order = $this->create_aio_order();

		$this->submit_checkout( $order, '3' );
		$this->submit_checkout( $order, '6' );

		$this->assertSame( '6', $this->build_form( $order )['CreditInstallment'] ?? null );
	}

	/**
	 * 定期定額（'period'，開發者 / 訂閱外掛寫入）一律不受結帳分期欄位影響：選不分期不清除、選期數也不覆寫
	 *
	 * @test
	 * @group edge
	 */
	public function test_定期定額訂單不受結帳分期欄位影響(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'Credit' ],
				'installmentPeriods' => [ 3, 6 ],
				'enableInstallment'  => true,
				'periodConfig'       => [
					'PeriodType' => 'M',
					'Frequency'  => 1,
					'ExecTimes'  => 12,
				],
			]
		);

		foreach ( [ '', '6' ] as $choice ) {
			$order = $this->create_aio_order( [ 'credit_variant' => 'period' ] );

			$this->submit_checkout( $order, $choice );

			$meta_keys = new EcpayMetaKeys( \wc_get_order( $order->get_id() ) );
			$this->assertSame( 'period', $meta_keys->get_credit_variant(), "選 '{$choice}' 不得改動 period" );
			$this->assertSame( '', $meta_keys->get_installment(), "選 '{$choice}' 不得寫入分期期數" );

			$form = $this->build_form( $order );
			$this->assertSame( 'M', $form['PeriodType'] ?? null );
			$this->assertArrayNotHasKey( 'CreditInstallment', $form );
		}
	}

	/**
	 * 開關關閉（結帳頁沒有分期欄位）→ 第三方外掛寫入的分期 meta 一律保留：未送出欄位、或舊快取頁送出空字串皆同
	 *
	 * @test
	 * @group edge
	 */
	public function test_開關關閉時第三方寫入的分期meta保留(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'Credit' ],
				'installmentPeriods' => [ 3, 6 ],
			]
		);

		foreach ( [ null, '' ] as $posted ) {
			$order = $this->create_aio_order(
				[
					'credit_variant' => 'installment',
					'installment'    => '6',
				]
			);

			$this->submit_checkout( $order, $posted );

			$label     = null === $posted ? '未送出欄位' : '送出空字串';
			$meta_keys = new EcpayMetaKeys( \wc_get_order( $order->get_id() ) );
			$this->assertSame( 'installment', $meta_keys->get_credit_variant(), "{$label}：第三方 variant 須保留" );
			$this->assertSame( '6', $meta_keys->get_installment(), "{$label}：第三方期數須保留" );
			$this->assertSame( '6', $this->build_form( $order )['CreditInstallment'] ?? null );
		}
	}

	/**
	 * 開關開啟但本次請求未送出欄位（block 舊快取 / 其他外掛代送）→ 不視為顧客選擇，既有分期 meta 保留
	 *
	 * @test
	 * @group edge
	 */
	public function test_欄位開啟但未送出時保留既有分期meta(): void {
		$this->seed_enabled( [ 3, 6, 12 ] );
		$order = $this->create_aio_order(
			[
				'credit_variant' => 'installment',
				'installment'    => '3,6',
			]
		);

		$this->submit_checkout( $order, null );

		$this->assertSame( '3,6', $this->build_form( $order )['CreditInstallment'] ?? null );
	}

	// ========== 30 期 → 30N（永豐圓夢彈性分期） ==========

	/**
	 * 結帳頁 30 期選項標示永豐限定；選 30 期 → 送綠界 CreditInstallment=30N（送 30 會被綠界忽略）
	 *
	 * @test
	 * @group edge
	 */
	public function test_選30期時送出30N(): void {
		$this->seed_enabled( [ 3, 30 ] );
		$order   = $this->create_aio_order();
		$gateway = new AioRedirectGateway();

		$this->assertStringContainsString( '永豐', $this->render_payment_fields() );

		$this->post_installment( '30' );
		$this->assertTrue( $gateway->validate_fields() );
		$gateway->process_payment( $order->get_id() );

		$this->assertSame( '30', ( new EcpayMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_installment() );
		$this->assertSame( '30N', $this->build_form( $order )['CreditInstallment'] );
	}

	/**
	 * meta 覆寫 "30" 或綠界原生寫法 "30N" → 皆送 30N；"3,30" → "3,30N"
	 *
	 * @test
	 * @group edge
	 */
	public function test_meta覆寫30期正規化為30N(): void {
		$this->seed_settings( [ 'installmentPeriods' => [ 3, 6, 30 ] ] );

		$cases = [
			'30'   => '30N',
			'30N'  => '30N',
			'3,30' => '3,30N',
		];
		foreach ( $cases as $meta_value => $expected ) {
			$order = $this->create_aio_order(
				[
					'credit_variant' => 'installment',
					'installment'    => (string) $meta_value,
				]
			);
			$this->assertSame( $expected, $this->build_form( $order )['CreditInstallment'], "meta '{$meta_value}'" );
		}
	}

	// ========== order meta 覆寫（向後相容第三方外掛） ==========

	/**
	 * meta 覆寫單一期數 "6" → "6"（開關關閉也生效）
	 *
	 * @test
	 * @group happy
	 */
	public function test_meta覆寫單一期數(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'Credit' ],
				'installmentPeriods' => [ 3, 6, 12 ],
			]
		);
		$order = $this->create_aio_order(
			[
				'credit_variant' => 'installment',
				'installment'    => '6',
			]
		);

		$form = $this->build_form( $order );

		$this->assertSame( 'Credit', $form['ChoosePayment'] );
		$this->assertSame( '6', $form['CreditInstallment'] );
	}

	/**
	 * meta 覆寫多期數 " 3, 6 " → "3,6"（綠界原生支援逗號分隔，顧客於綠界頁選期數）
	 *
	 * @test
	 * @group happy
	 */
	public function test_meta覆寫多期數(): void {
		$this->seed_enabled( [ 3, 6, 12, 24 ] );
		$order = $this->create_aio_order(
			[
				'credit_variant' => 'installment',
				'installment'    => ' 3, 6 ',
			]
		);

		$this->assertSame( '3,6', $this->build_form( $order )['CreditInstallment'] );
	}

	/**
	 * meta 覆寫含非白名單期數 "3,9" → throw（不再因 (int)"3,9"=3 而意外通過）
	 *
	 * @test
	 * @group error
	 */
	public function test_meta覆寫含非白名單期數時拋例外(): void {
		$this->seed_settings( [ 'installmentPeriods' => [ 3, 6, 12 ] ] );

		$this->assert_override_rejected( '3,9' );
	}

	/**
	 * meta 覆寫格式不合法（非數字 / 空段 / 空字串 / 負數 / 小寫 n）→ throw
	 *
	 * @test
	 * @group security
	 */
	public function test_meta覆寫格式不合法時拋例外(): void {
		$this->seed_settings( [ 'installmentPeriods' => [ 3, 6, 12, 30 ] ] );

		$this->assert_override_rejected( 'abc' );
		$this->assert_override_rejected( '3,' );
		$this->assert_override_rejected( '' );
		$this->assert_override_rejected( '3abc' );
		$this->assert_override_rejected( '-3' );
		$this->assert_override_rejected( '30n' );
		$this->assert_override_rejected( '6N' );
	}

	/**
	 * 定期定額（'period'，開發者 / 訂閱外掛擴充點）不受分期開關影響
	 *
	 * @test
	 * @group edge
	 */
	public function test_定期定額meta覆寫不受分期開關影響(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'Credit' ],
				'installmentPeriods' => [ 3, 6 ],
				'enableInstallment'  => true,
				'periodConfig'       => [
					'PeriodType' => 'M',
					'Frequency'  => 1,
					'ExecTimes'  => 12,
				],
			]
		);
		$order = $this->create_aio_order( [ 'credit_variant' => 'period' ] );

		$form = $this->build_form( $order );

		$this->assertSame( 'Credit', $form['ChoosePayment'] );
		$this->assertSame( 'M', $form['PeriodType'] );
		$this->assertSame( 12000, $form['PeriodAmount'] );
		$this->assertArrayNotHasKey( 'CreditInstallment', $form );
	}

	// ========== 送出參數完整性 ==========

	/**
	 * 最終送往綠界的 form 參數含 CreditInstallment，且 CheckMacValue 以含 CreditInstallment 的參數計算
	 *
	 * @test
	 * @group happy
	 */
	public function test_送出參數含CreditInstallment且CheckMacValue正確(): void {
		$this->seed_enabled( [ 3, 6, 30 ] );
		$order = $this->create_aio_order(
			[
				'credit_variant' => 'installment',
				'installment'    => '3,30',
			]
		);

		$form = $this->build_form( $order );

		$this->assertSame( '3,30N', $form['CreditInstallment'] );
		$this->assertArrayHasKey( 'CheckMacValue', $form );

		// 以官方演算法（CheckMacValueService 已有官方黃金 vector 測試）重算，須一致
		$args = $form;
		unset( $args['CheckMacValue'] );
		$this->assertSame(
			CheckMacValueService::get_check_value( $args, self::HASH_KEY, self::HASH_IV, 'sha256' ),
			$form['CheckMacValue']
		);

		// 竄改 CreditInstallment 後 CheckMacValue 必不同 → 證明 CreditInstallment 確實參與檢查碼
		$args['CreditInstallment'] = '3';
		$this->assertNotSame(
			CheckMacValueService::get_check_value( $args, self::HASH_KEY, self::HASH_IV, 'sha256' ),
			$form['CheckMacValue']
		);
	}

	// ========== 區塊結帳資料 ==========

	/**
	 * Block checkout 付款方式資料帶出分期欄位設定（欄位名 + 選項）；關閉時 options 為空
	 *
	 * @test
	 * @group happy
	 */
	public function test_區塊結帳資料帶出分期選項(): void {
		$this->seed_enabled( [ 6, 3, 30 ] );

		$data = ( new AioBlocksIntegration( new AioRedirectGateway() ) )->get_payment_method_data();

		$this->assertSame( InstallmentCheckoutField::FIELD_NAME, $data['installment']['field'] ?? null );
		$values = \array_column( $data['installment']['options'] ?? [], 'value' );
		$this->assertSame( [ '', '3', '6', '30' ], $values );
		// 共用欄位仍在
		$this->assertSame( AioRedirectGateway::ID, $data['name'] ?? null );

		// 關閉開關 → options 為空（前端不渲染下拉）
		$this->seed_settings( [ 'installmentPeriods' => [ 3, 6 ] ] );
		$data = ( new AioBlocksIntegration( new AioRedirectGateway() ) )->get_payment_method_data();
		$this->assertSame( [], $data['installment']['options'] ?? null );
	}

	// ========== 設定儲存路徑 ==========

	/**
	 * REST POST /settings/ecpay_aio 儲存開關 → option 保留新 key，DTO 正規化為 bool
	 *
	 * @test
	 * @group happy
	 */
	public function test_REST儲存設定保留enableInstallment(): void {
		// Given: 既有設定（無開關）
		$this->seed_settings( [ 'allowedPayments' => [ 'Credit' ] ] );

		// When: 設定頁送出開啟開關 + 期數
		$request = new \WP_REST_Request( 'POST', '/power-checkout/v1/settings/' . AioRedirectGateway::ID );
		$request->set_url_params( [ 'provider_id' => AioRedirectGateway::ID ] );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			(string) \wp_json_encode(
				[
					'enableInstallment'  => true,
					'installmentPeriods' => [ 3, 6 ],
				]
			)
		);
		$response = SettingApiService::post_providers_with_id_callback( $request );

		// Then: 儲存成功、option 保留 key、DTO 讀回 true、結帳頁可選 3 / 6 期
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'enableInstallment', (array) ProviderUtils::get_option( AioRedirectGateway::ID ) );
		$this->assertTrue( AioSettingsDTO::instance()->enableInstallment );
		$this->assertSame( [ 3, 6 ], AioSettingsDTO::instance()->get_checkout_installment_periods() );

		// When: 再送出關閉
		$request->set_body( (string) \wp_json_encode( [ 'enableInstallment' => false ] ) );
		SettingApiService::post_providers_with_id_callback( $request );

		// Then: 讀回 false，無可選期數
		$this->assertFalse( AioSettingsDTO::instance()->enableInstallment );
		$this->assertSame( [], AioSettingsDTO::instance()->get_checkout_installment_periods() );
	}
}
