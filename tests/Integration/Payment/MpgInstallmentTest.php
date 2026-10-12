<?php
/**
 * 藍新 NewebPay MPG 信用卡分期 InstFlag 回歸測試（Issue #21）
 *
 * 缺陷：MpgRequestParams::resolve_inst_flag() 只在 order meta `_pc_newebpay_credit_variant === 'installment'`
 * 時送 InstFlag，但全專案沒有任何程式寫入該 meta（無結帳頁分期 UI），導致後台 installmentPeriods 完全無效。
 *
 * 修法（issue 選項 B）：
 *  - MpgSettingsDTO 新增 enableInstallment 開關（預設關閉，避免未開通分期的既有商家被影響）。
 *  - 優先序：
 *    a. order meta 覆寫（向後相容第三方外掛）：credit_variant='installment' 時，_pc_newebpay_installment
 *       依 `,` 拆開 trim，每段須 ctype_digit 且在 installmentPeriods 白名單內，否則 throw。
 *    b. 開關開啟 + allowedPayments 含 CREDIT + installmentPeriods 非空 → 送排序去重後的 "3,6,12"，
 *       由顧客在藍新付款頁自行選擇一次付清或分期（藍新 InstFlag 原生支援逗號分隔期數）。
 *    c. 其他 → 空字串（不送）。
 *
 * @see .claude/skills/newebpay-mpg/references/api-reference.md §Credit Card（InstFlag "3,6,12,18,24,30"）
 *
 * @group integration
 * @group payment
 * @group newebpay_mpg
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgRequestParams;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Services\MpgRedirectGateway;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgMetaKeys;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\TradeInfoCrypto;
use J7\PowerCheckout\Domains\Settings\Services\SettingApiService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 藍新 MPG 信用卡分期 InstFlag 測試類別
 *
 * @group integration
 * @group payment
 * @group newebpay_mpg
 */
final class MpgInstallmentTest extends TestCase {

	/** @var string 藍新 MPG 公開測試帳號 HashKey */
	private const HASH_KEY = 'Vh2Br0kFGSGHA9zXFDJuf9KIVgVxX1pn';

	/** @var string 藍新 MPG 公開測試帳號 HashIV */
	private const HASH_IV = 'IZGViXjMd2gWMtsR';

	/** @var string 藍新 MPG 公開測試帳號 MerchantID */
	private const MERCHANT_ID = 'MS154450763';

	/** 每次測試前：MOCK 模式 */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		\putenv( 'API_MODE' );
		parent::tear_down();
	}

	// ========== Helpers ==========

	/**
	 * 寫入 newebpay_mpg 設定（覆蓋整個 option，模擬既有 DB 狀態）
	 *
	 * @param array<string, mixed> $extra 額外設定（allowedPayments / installmentPeriods / enableInstallment 等）
	 * @return void
	 */
	private function seed_settings( array $extra = [] ): void {
		\update_option(
			ProviderUtils::get_option_name( MpgRedirectGateway::ID ),
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
	 * 建立藍新訂單
	 *
	 * @param array<string, string> $meta 訂單 meta（credit_variant / installment）
	 * @return \WC_Order
	 */
	private function create_mpg_order( array $meta = [] ): \WC_Order {
		$order     = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => MpgRedirectGateway::ID,
				'total'          => 3000,
			]
		);
		$meta_keys = new MpgMetaKeys( $order );
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
	 * 組裝建單參數（等同 before_order_received 的組裝步驟）
	 *
	 * @param \WC_Order $order 訂單
	 * @return MpgRequestParams
	 */
	private function build_params( \WC_Order $order ): MpgRequestParams {
		return MpgRequestParams::instance( $order, new MpgRedirectGateway() );
	}

	/**
	 * 斷言組裝 InstFlag 時拋出「分期期數不在允許範圍」
	 *
	 * @param string $installment _pc_newebpay_installment 值
	 * @return void
	 */
	private function assert_override_rejected( string $installment ): void {
		$order = $this->create_mpg_order(
			[
				'credit_variant' => 'installment',
				'installment'    => $installment,
			]
		);

		$thrown = null;
		try {
			$this->build_params( $order );
		} catch ( \Exception $e ) {
			$thrown = $e;
		}

		$this->assertNotNull( $thrown, "預期 installment='{$installment}' 應拋例外" );
		$this->assertStringContainsString( '分期期數不在允許範圍', $thrown->getMessage() );
	}

	/**
	 * 解密最終送往藍新的 TradeInfo 為 key-value
	 *
	 * @param MpgRequestParams $params 建單參數
	 * @return array<int|string, mixed>
	 */
	private function decrypt_trade_info( MpgRequestParams $params ): array {
		$form   = $params->to_form_params();
		$crypto = new TradeInfoCrypto( self::HASH_KEY, self::HASH_IV );
		$plain  = $crypto->decrypt( (string) $form['TradeInfo'] );
		$result = [];
		\parse_str( $plain, $result );
		return $result;
	}

	// ========== c. 開關關閉 → 不送 ==========

	/**
	 * 舊 option 沒有 enableInstallment（升級前既有商家）→ 預設關閉、InstFlag 為空
	 *
	 * @test
	 * @group happy
	 */
	public function test_舊設定無開關key時預設關閉InstFlag為空(): void {
		// Given: 既有 DB 只有預設 installmentPeriods 與 CREDIT，沒有新開關
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'CREDIT' ],
				'installmentPeriods' => [ 3, 6, 12, 18, 24, 30 ],
			]
		);

		// When
		$params = $this->build_params( $this->create_mpg_order() );

		// Then
		$this->assertFalse( MpgSettingsDTO::instance()->enableInstallment );
		$this->assertSame( '', $params->InstFlag );
		$this->assertArrayNotHasKey( 'InstFlag', $this->decrypt_trade_info( $params ) );
	}

	/**
	 * 開關明確關閉（REST 存成空字串 / false / 'no'）→ InstFlag 為空
	 *
	 * @test
	 * @group edge
	 */
	public function test_開關明確關閉時InstFlag為空(): void {
		foreach ( [ '', false, 'no', '0' ] as $off_value ) {
			$this->seed_settings(
				[
					'allowedPayments'    => [ 'CREDIT' ],
					'installmentPeriods' => [ 3, 6 ],
					'enableInstallment'  => $off_value,
				]
			);

			$params = $this->build_params( $this->create_mpg_order() );

			$this->assertSame( '', $params->InstFlag, '開關值 ' . \wp_json_encode( $off_value ) . ' 應視為關閉' );
		}
	}

	// ========== b. 開關開啟 + CREDIT + 期數非空 → 送期數字串 ==========

	/**
	 * 開關開啟 + CREDIT + 期數 [3,6] → InstFlag "3,6"
	 *
	 * @test
	 * @group happy
	 */
	public function test_開關開啟且CREDIT時送設定期數(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'CREDIT' ],
				'installmentPeriods' => [ 3, 6 ],
				'enableInstallment'  => true,
			]
		);

		$params = $this->build_params( $this->create_mpg_order() );

		$this->assertSame( '3,6', $params->InstFlag );
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
				'allowedPayments'    => [ 'VACC', 'CREDIT' ],
				'installmentPeriods' => [ '12', 3, '6', 3, 12 ],
				'enableInstallment'  => '1', // REST sanitize_text_field 後的 true
			]
		);

		$params = $this->build_params( $this->create_mpg_order() );

		$this->assertSame( '3,6,12', $params->InstFlag );
	}

	/**
	 * 開關開啟但 allowedPayments 不含 CREDIT → InstFlag 為空
	 *
	 * @test
	 * @group edge
	 */
	public function test_開關開啟但未啟用CREDIT時InstFlag為空(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'VACC', 'CVS' ],
				'installmentPeriods' => [ 3, 6 ],
				'enableInstallment'  => true,
			]
		);

		$params = $this->build_params( $this->create_mpg_order() );

		$this->assertSame( '', $params->InstFlag );
	}

	/**
	 * 開關開啟但期數為空陣列 → InstFlag 為空
	 *
	 * @test
	 * @group edge
	 */
	public function test_開關開啟但期數為空陣列時InstFlag為空(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'CREDIT' ],
				'installmentPeriods' => [],
				'enableInstallment'  => true,
			]
		);

		$params = $this->build_params( $this->create_mpg_order() );

		$this->assertSame( '', $params->InstFlag );
	}

	/**
	 * 最終送往藍新的 TradeInfo（AES 解密後）確實含 InstFlag 與 CREDIT
	 *
	 * @test
	 * @group happy
	 */
	public function test_最終送出的TradeInfo解密後含InstFlag(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'CREDIT' ],
				'installmentPeriods' => [ 12, 3, 6 ],
				'enableInstallment'  => 'yes',
			]
		);

		$trade_info = $this->decrypt_trade_info( $this->build_params( $this->create_mpg_order() ) );

		$this->assertSame( '3,6,12', $trade_info['InstFlag'] ?? null );
		$this->assertSame( '1', $trade_info['CREDIT'] ?? null );
	}

	// ========== a. order meta 覆寫（向後相容第三方外掛） ==========

	/**
	 * meta 覆寫單一期數 "6" → "6"（開關關閉也生效）
	 *
	 * @test
	 * @group happy
	 */
	public function test_meta覆寫單一期數(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'CREDIT' ],
				'installmentPeriods' => [ 3, 6, 12 ],
			]
		);
		$order = $this->create_mpg_order(
			[
				'credit_variant' => 'installment',
				'installment'    => '6',
			]
		);

		$params = $this->build_params( $order );

		$this->assertSame( '6', $params->InstFlag );
	}

	/**
	 * meta 覆寫多期數 "3,6"（Issue 回報者暫時解法）→ "3,6"，且優先於設定開關
	 *
	 * @test
	 * @group happy
	 */
	public function test_meta覆寫多期數優先於設定開關(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'CREDIT' ],
				'installmentPeriods' => [ 3, 6, 12, 24 ],
				'enableInstallment'  => true,
			]
		);
		$order = $this->create_mpg_order(
			[
				'credit_variant' => 'installment',
				'installment'    => ' 3, 6 ',
			]
		);

		$params = $this->build_params( $order );

		$this->assertSame( '3,6', $params->InstFlag );
		$this->assertSame( '3,6', $this->decrypt_trade_info( $params )['InstFlag'] ?? null );
	}

	/**
	 * meta 覆寫含非白名單期數 "3,9" → throw（不再因 (int)"3,9"=3 而意外通過）
	 *
	 * @test
	 * @group error
	 */
	public function test_meta覆寫含非白名單期數時拋例外(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'CREDIT' ],
				'installmentPeriods' => [ 3, 6, 12 ],
			]
		);

		$this->assert_override_rejected( '3,9' );
	}

	/**
	 * meta 覆寫格式不合法（非數字 / 空段 / 空字串）→ throw
	 *
	 * @test
	 * @group security
	 */
	public function test_meta覆寫格式不合法時拋例外(): void {
		$this->seed_settings(
			[
				'allowedPayments'    => [ 'CREDIT' ],
				'installmentPeriods' => [ 3, 6, 12 ],
			]
		);

		$this->assert_override_rejected( 'abc' );
		$this->assert_override_rejected( '3,' );
		$this->assert_override_rejected( '' );
		$this->assert_override_rejected( '3abc' );
		$this->assert_override_rejected( '-3' );
	}

	// ========== 設定儲存路徑 ==========

	/**
	 * DTO 預設值：enableInstallment 預設 false，且 get_settings() 帶出此 key（供設定頁讀取）
	 *
	 * @test
	 * @group smoke
	 */
	public function test_設定DTO預設關閉且get_settings含開關(): void {
		$this->seed_settings();

		$this->assertFalse( ( new MpgSettingsDTO() )->enableInstallment );
		$settings = MpgRedirectGateway::get_settings();
		$this->assertArrayHasKey( 'enableInstallment', $settings );
		$this->assertFalse( $settings['enableInstallment'] );
	}

	/**
	 * REST POST /settings/newebpay_mpg 儲存開關 → option 保留新 key，DTO 正規化為 bool
	 *
	 * @test
	 * @group happy
	 */
	public function test_REST儲存設定保留enableInstallment(): void {
		// Given: 既有設定（無開關）
		$this->seed_settings( [ 'allowedPayments' => [ 'CREDIT' ] ] );

		// When: 設定頁送出開啟開關 + 期數
		$request = new \WP_REST_Request( 'POST', '/power-checkout/v1/settings/' . MpgRedirectGateway::ID );
		$request->set_url_params( [ 'provider_id' => MpgRedirectGateway::ID ] );
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

		// Then: 儲存成功、option 保留 key、DTO 讀回 true，建單送出 InstFlag
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'enableInstallment', (array) ProviderUtils::get_option( MpgRedirectGateway::ID ) );
		$this->assertTrue( MpgSettingsDTO::instance()->enableInstallment );
		$this->assertSame( '3,6', $this->build_params( $this->create_mpg_order() )->InstFlag );

		// When: 再送出關閉
		$request->set_body( (string) \wp_json_encode( [ 'enableInstallment' => false ] ) );
		SettingApiService::post_providers_with_id_callback( $request );

		// Then: 讀回 false，InstFlag 為空
		$this->assertFalse( MpgSettingsDTO::instance()->enableInstallment );
		$this->assertSame( '', $this->build_params( $this->create_mpg_order() )->InstFlag );
	}
}
