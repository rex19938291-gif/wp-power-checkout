<?php
/**
 * 綠界 AIO 全方位金流區塊結帳整合
 *
 * 繼承共用 BlocksIntegration，於 block 前端資料（get_payment_method_data）額外帶上
 * 結帳頁「信用卡分期」欄位設定（欄位名 / 標題 / 說明 / 選項），供 ecpay_aio.tsx 渲染下拉。
 * 選項為空陣列代表後台未開啟分期（或未允許信用卡 / 未勾期數），前端不渲染下拉。
 *
 * @see InstallmentCheckoutField::get_block_data()
 * @see .claude/rules/react-blocks.rule.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\AioSettingsDTO;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\InstallmentCheckoutField;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\BlocksIntegration;

if ( ! \class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
	return;
}

/** 綠界 AIO 區塊結帳整合 */
final class AioBlocksIntegration extends BlocksIntegration {

	/**
	 * 給前端取得的付款方式資料（共用欄位 + 信用卡分期欄位設定）
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		return \array_merge(
			parent::get_payment_method_data(),
			[
				'installment' => InstallmentCheckoutField::get_block_data(
					AioSettingsDTO::instance()->get_checkout_installment_periods()
				),
			]
		);
	}
}
