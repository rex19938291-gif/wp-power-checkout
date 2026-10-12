<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers;

/**
 * 綠界 AIO 信用卡分期期數（CreditInstallment）編碼 / 驗證
 *
 * 綠界合法值（ECPay-API-Skill guides/01-payment-aio.md §分期專用參數）：
 *  - 一般分期：3,6,12,18,24（多期以 `,` 分隔，顧客於綠界付款頁選擇）。
 *  - 30 期：僅永豐「圓夢彈性分期」，參數須送 `30N`（需另向綠界申請、限永豐卡、須達最低交易金額）。
 *
 * 實測（payment-stage.ecpay.com.tw AioCheckOut/V5，測試特店 3002607）：
 *  - `CreditInstallment=30` → 綠界靜默忽略（只送 "30" 時頁面退回一般信用卡一次付清）。
 *  - `CreditInstallment=30N` → 頁面出現圓夢彈性分期 30 期。
 * 故後台 / order meta 仍以整數 30 表示，送綠界前於 {@see self::to_ecpay_value()} 轉為 `30N`。
 */
final class CreditInstallment {

	/** @var int 圓夢彈性分期期數（後台 / order meta 的整數表示） */
	public const FLEXIBLE_PERIOD = 30;

	/** @var string 圓夢彈性分期送綠界的參數值 */
	public const FLEXIBLE_CODE = '30N';

	/** @var string 分期期數不合法時的例外訊息（RequestParams / 結帳欄位共用） */
	public const INVALID_MESSAGE = '分期期數不在允許範圍';

	/**
	 * 將期數轉為綠界 CreditInstallment 參數值（30 → 30N，其餘原樣）
	 *
	 * @param int $period 期數
	 *
	 * @return string 綠界參數值（如 "6"、"30N"）
	 */
	public static function to_ecpay_value( int $period ): string {
		return self::FLEXIBLE_PERIOD === $period ? self::FLEXIBLE_CODE : (string) $period;
	}

	/**
	 * 驗證並正規化 order meta 指定的分期期數，回傳送綠界的 CreditInstallment
	 *
	 * 依 `,` 拆開並 trim，每段須為純數字（ctype_digit）或綠界原生寫法 `30N`，且在後台
	 * installmentPeriods 白名單內；通過則轉綠界參數值後以 `,` 重組（如 " 3, 30 " → "3,30N"）。
	 * 任一段不合法 → throw（亦杜絕 `(int) "3,9"` 轉型成 3 而誤通過的副作用）。
	 *
	 * @param string     $installment 期數字串（_pc_ecpay_installment，如 "6" 或 "3,6"）
	 * @param array<int> $allowed     後台 installmentPeriods 白名單
	 *
	 * @return string 綠界 CreditInstallment（如 "6"、"3,6"、"3,30N"）
	 * @throws \Exception 分期期數不在允許範圍（空字串 / 空段 / 非純數字 / 不在白名單）
	 */
	public static function normalize( string $installment, array $allowed ): string {
		$values = [];
		foreach ( \explode( ',', $installment ) as $segment ) {
			$period = self::parse_segment( \trim( $segment ) );
			if ( null === $period || ! \in_array( $period, $allowed, true ) ) {
				throw new \Exception( self::INVALID_MESSAGE );
			}
			$values[] = self::to_ecpay_value( $period );
		}

		return \implode( ',', $values );
	}

	/**
	 * 結帳頁下拉選項的顯示文字
	 *
	 * @param int $period 期數
	 *
	 * @return string 例：「信用卡分 6 期」；30 期加註永豐圓夢彈性分期限定
	 */
	public static function get_label( int $period ): string {
		if ( self::FLEXIBLE_PERIOD === $period ) {
			return \sprintf(
				/* translators: %d: 分期期數 */
				\__( '信用卡分 %d 期（永豐圓夢彈性分期，限永豐信用卡）', 'power_checkout' ),
				$period
			);
		}

		return \sprintf(
			/* translators: %d: 分期期數 */
			\__( '信用卡分 %d 期', 'power_checkout' ),
			$period
		);
	}

	/**
	 * 解析單一期數片段
	 *
	 * @param string $segment 已 trim 的片段（如 "6"、"30N"）
	 *
	 * @return int|null 期數；格式不合法回 null
	 */
	private static function parse_segment( string $segment ): ?int {
		if ( self::FLEXIBLE_CODE === $segment ) {
			return self::FLEXIBLE_PERIOD;
		}

		return \ctype_digit( $segment ) ? (int) $segment : null;
	}
}
