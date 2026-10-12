<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Services;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\CreditInstallment;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;

/**
 * 綠界 AIO 結帳頁「信用卡分期」欄位
 *
 * 綠界收銀台只要帶 CreditInstallment，信用卡就只剩分期（一次付清選項被隱藏），
 * 故分期不能做成全站開關，改由顧客於結帳頁自選：
 *  - classic checkout：AioRedirectGateway::payment_fields() 輸出 {@see self::render()} 的下拉。
 *  - block checkout：AioBlocksIntegration 以 {@see self::get_block_data()} 提供選項，
 *    ecpay_aio.tsx 渲染下拉並於 onPaymentSetup 以 paymentMethodData 回傳（Store API 會放進 $_POST）。
 *  - 兩者皆在 validate_fields / before_process_payment 以 {@see self::get_posted_installment()}
 *    依後台白名單驗證，通過才由 {@see self::apply_to_order()} 寫入 order meta，交給 RequestParams 組參數。
 *
 * 同一張訂單可能被結帳多次（顧客未付款返回，購物車不變再結帳時 WC 沿用同一張 pending 訂單；
 * 或於「我的帳戶」對 pending 訂單點付款），故顧客「本次」的選擇必須能覆蓋上一次寫入的分期 meta，
 * 規則見 {@see self::apply_to_order()}。
 */
final class InstallmentCheckoutField {

	/** @var string 結帳欄位名稱（classic form POST key / block paymentMethodData key） */
	public const FIELD_NAME = 'pc_ecpay_aio_installment';

	/**
	 * 下拉選項（第一項固定為「不分期」）
	 *
	 * @param array<int> $periods 可選期數（AioSettingsDTO::get_checkout_installment_periods）
	 *
	 * @return array<int, array{value: string, label: string}> 選項；無可選期數回空陣列
	 */
	public static function get_options( array $periods ): array {
		if ( [] === $periods ) {
			return [];
		}

		$options = [
			[
				'value' => '',
				'label' => \__( '不分期（一次付清或其他付款方式）', 'power_checkout' ),
			],
		];
		foreach ( $periods as $period ) {
			$options[] = [
				'value' => (string) $period,
				'label' => CreditInstallment::get_label( $period ),
			];
		}

		return $options;
	}

	/**
	 * Classic checkout 下拉 HTML
	 *
	 * 下拉帶 id：WC checkout.js 於 update_order_review 重繪付款區塊時會依 id 還原 .payment_box 內欄位值。
	 *
	 * @param array<int> $periods 可選期數
	 *
	 * @return string HTML；無可選期數回空字串
	 */
	public static function render( array $periods ): string {
		$options = self::get_options( $periods );
		if ( [] === $options ) {
			return '';
		}

		$option_html = '';
		foreach ( $options as $option ) {
			$value        = \esc_attr( $option['value'] );
			$label        = \esc_html( $option['label'] );
			$option_html .= "<option value=\"{$value}\">{$label}</option>";
		}

		$field       = \esc_attr( self::FIELD_NAME );
		$title       = \esc_html( self::get_title() );
		$description = \esc_html( self::get_description() );

		return <<<HTML
		<p class="form-row form-row-wide pc-ecpay-aio-installment">
			<label for="{$field}">{$title}</label>
			<select id="{$field}" name="{$field}" class="select">{$option_html}</select>
			<small class="description">{$description}</small>
		</p>
		HTML;
	}

	/**
	 * Block checkout 付款方式資料（AioBlocksIntegration::get_payment_method_data 的 installment 欄位）
	 *
	 * @param array<int> $periods 可選期數
	 *
	 * @return array{field: string, label: string, description: string, options: array<int, array{value: string, label: string}>}
	 */
	public static function get_block_data( array $periods ): array {
		return [
			'field'       => self::FIELD_NAME,
			'label'       => self::get_title(),
			'description' => self::get_description(),
			'options'     => self::get_options( $periods ),
		];
	}

	/**
	 * 讀取並驗證顧客送出的分期期數
	 *
	 * 欄位不存在或為空字串 → 不分期（回 ''）；有值則須為純數字且在可選期數內，否則 throw。
	 * 後台關閉分期（$periods 為空）時送出任何期數一律視為不合法（竄改 / 舊頁面快取）。
	 *
	 * @param array<int> $periods 可選期數
	 *
	 * @return string 期數（如 "6"）；不分期回空字串
	 * @throws \Exception 分期期數不在允許範圍
	 */
	public static function get_posted_installment( array $periods ): string {
		// 只讀單一欄位；nonce 已由 WC checkout（woocommerce-process-checkout-nonce）/ Store API / order-pay 表單驗證；
		// 先確認為字串再於下方 wp_unslash + sanitize_text_field（陣列語法直接拒絕）
		$raw = $_POST[ self::FIELD_NAME ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( null === $raw ) {
			return '';
		}
		if ( ! \is_string( $raw ) ) {
			throw new \Exception( CreditInstallment::INVALID_MESSAGE );
		}

		$value = \sanitize_text_field( \wp_unslash( $raw ) );
		if ( '' === $value ) {
			return '';
		}

		if ( ! \ctype_digit( $value ) || ! \in_array( (int) $value, $periods, true ) ) {
			throw new \Exception( CreditInstallment::INVALID_MESSAGE );
		}

		return (string) (int) $value;
	}

	/**
	 * 顧客本次結帳是否送出了分期欄位
	 *
	 * 只有欄位有渲染（後台開關有效、可選期數非空）且本次請求帶了這個欄位（classic form POST /
	 * block paymentMethodData，Store API 皆放進 $_POST）才算數；值可以是空字串（選「不分期」）。
	 *
	 * @param array<int> $periods 可選期數（AioSettingsDTO::get_checkout_installment_periods）
	 *
	 * @return bool
	 */
	public static function is_submitted( array $periods ): bool {
		// 僅判斷 key 是否存在，不讀值；nonce 已由 WC checkout / Store API / order-pay 表單驗證
		return [] !== $periods && \array_key_exists( self::FIELD_NAME, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * 依顧客本次的選擇更新 order meta
	 *
	 * 規則（同一張訂單可能被結帳多次，本次選擇須能覆蓋上一次）：
	 *  1. 變體為 'period'（定期定額，開發者 / 訂閱外掛寫入）→ 一律不動，分期欄位不得改寫或清除。
	 *  2. 選了期數 → 寫入 variant='installment' + 期數。
	 *  3. 欄位有渲染且有送出（$is_submitted）並選「不分期」→ 顧客這次的選擇是唯一依據：
	 *     現有 variant 為 'installment' 時刪除 `_pc_ecpay_credit_variant` 與 `_pc_ecpay_installment`，
	 *     避免上一次（如 3 期）殘留，導致重新導轉綠界時仍送 CreditInstallment。
	 *  4. 欄位沒渲染（開關關閉）或本次未送出 → 不視為顧客選擇，維持現狀（保留第三方外掛寫入的分期 meta）。
	 *
	 * @param \WC_Order $order        訂單
	 * @param string    $installment  期數（get_posted_installment 的回傳值；'' 代表不分期 / 未送出）
	 * @param bool      $is_submitted 本次是否送出了分期欄位（{@see self::is_submitted()}）
	 *
	 * @return void
	 */
	public static function apply_to_order( \WC_Order $order, string $installment, bool $is_submitted ): void {
		$meta_keys = new EcpayMetaKeys( $order );
		$variant   = $meta_keys->get_credit_variant();

		if ( 'period' === $variant ) {
			return;
		}

		if ( '' !== $installment ) {
			$meta_keys->update_credit_variant( 'installment' );
			$meta_keys->update_installment( $installment );
			return;
		}

		if ( $is_submitted && 'installment' === $variant ) {
			$meta_keys->clear_installment();
		}
	}

	/** @return string 欄位標題 */
	private static function get_title(): string {
		return \__( '信用卡分期', 'power_checkout' );
	}

	/** @return string 欄位說明 */
	private static function get_description(): string {
		return \__( '選擇分期後，綠界付款頁僅提供信用卡分期付款', 'power_checkout' );
	}
}
