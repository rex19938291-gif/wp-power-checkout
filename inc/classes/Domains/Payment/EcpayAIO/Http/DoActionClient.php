<?php
/**
 * 綠界 AIO 信用卡 DoAction（請款 / 退款 / 取消授權）API client
 *
 * 端點：/CreditDetail/DoAction（CMV-SHA256 協議，回應為 URL-encoded 字串，以 `&` / `=` 解析為 key-value，
 * 例：`MerchantID=...&MerchantTradeNo=...&TradeNo=...&RtnCode=1&RtnMsg=Succeeded.`；**不是** `RtnCode|RtnMsg`）。
 * 支援 Action：C 請款（關帳）、N 放棄（取消授權 / 取消請款）、R 退款（退刷）。
 *
 * ⚠️ 重要限制（Source: ECPay-API-Skill guides/01-payment-aio.md §信用卡請款 / 退款 / 取消，2026-06）：
 *  - DoAction **僅正式環境可用**（payment.ecpay.com.tw），Stage 環境因無法實際授權故不支援。
 *  - TradeNo 必須是綠界回傳的交易編號（存於 _pc_ecpay_payment_detail），非 MerchantTradeNo。
 *  - Action=R 時 TotalAmount 為「欲退款金額」（非原訂單金額），支援多次部分退款，累計不得超過原交易。
 *  - Action=C 請款（關帳）：授權後請款；Action=N 放棄（取消授權）：請款前取消，不支援部分取消。
 *  - 僅信用卡類付款方式可呼叫；ATM/CVS/BARCODE/WebATM/ApplePay 由呼叫端先擋下（見 EcpayPaymentType）。
 *
 * ⚠️ 回應解析（Issue #20）：正式環境回應不含 `|`，過去以 pipe 切割導致「綠界端已成功、WC 判定失敗」
 * 並刪除 refund，站方若再退一次即重複退款。現以 parse_str 解析；缺 RtnCode（空字串 / HTML 錯誤頁 /
 * 格式無法解析）一律 fail-closed（throw），並於 order note 提醒站方至綠界廠商後台確認實際狀態、勿重複操作。
 *
 * MOCK 模式（API_MODE=mock）不打真 API（DoAction 連 Stage 都不可用），改以與正式環境相同格式的
 * fixture（`...&RtnCode=1&RtnMsg=Succeeded.`）走同一個 parse_response()，確保 mock 測試也覆蓋解析路徑。
 *
 * @see https://developers.ecpay.com.tw/?p=2901 §信用卡請退款功能
 * @see ECPay-API-Skill guides/01-payment-aio.md §信用卡請款 / 退款 / 取消（Action C/N/R/E 對照表）
 * @see ECPay-API-Skill guides/19-http-protocol-reference.md §回應格式對照表（/CreditDetail/DoAction → URL-encoded）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Http;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\AioSettingsDTO;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\CheckMacValueService;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Plugin;

/** 綠界 AIO 信用卡 DoAction API client */
final class DoActionClient {

	/** @var int HTTP 逾時秒數 */
	private const TIMEOUT = 60;

	/** @var string DoAction 正式環境端點（Stage 不支援 DoAction） */
	private const ENDPOINT_PROD = 'https://payment.ecpay.com.tw/CreditDetail/DoAction';

	/** @var string 失敗 / 結果未知時給站方的處置提醒（避免重複退款 / 重複請款） */
	private const VERIFY_HINT = '請至綠界廠商後台確認實際狀態，勿重複操作';

	/** @var int 寫入 log 的原始回應 body 最大字元數 */
	private const LOG_BODY_MAX_LENGTH = 500;

	/** @var AioSettingsDTO 設定 */
	private readonly AioSettingsDTO $settings;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單（用於 order note 與冪等鍵） */
		private readonly \WC_Order $order,
	) {
		$this->settings = AioSettingsDTO::instance();
	}

	/**
	 * 信用卡退款（Action=R 退刷）
	 *
	 * @param string $trade_no 綠界交易編號 TradeNo（非 MerchantTradeNo）
	 * @param float  $amount   欲退款金額（來自 WC refund 物件，非前端）
	 *
	 * @return array{RtnCode: string, RtnMsg: string} DoAction 回應（RtnCode='1' 為成功）
	 * @throws \Exception 連線失敗 / 缺 TradeNo / 回應無法解析 / RtnCode 非 1
	 */
	public function refund( string $trade_no, float $amount ): array {
		return $this->do_action( 'R', $trade_no, $amount, '退款' );
	}

	/**
	 * 信用卡請款 / 關帳（Action=C）
	 *
	 * 語意：授權後請款。TotalAmount 為欲請款金額（通常等於授權金額）。
	 *
	 * @param string $trade_no 綠界交易編號 TradeNo（非 MerchantTradeNo）
	 * @param float  $amount   欲請款金額
	 *
	 * @return array{RtnCode: string, RtnMsg: string} DoAction 回應（RtnCode='1' 為成功）
	 * @throws \Exception 連線失敗 / 缺 TradeNo / 回應無法解析 / RtnCode 非 1
	 */
	public function capture( string $trade_no, float $amount ): array {
		return $this->do_action( 'C', $trade_no, $amount, '請款' );
	}

	/**
	 * 信用卡取消授權 / 放棄（Action=N）
	 *
	 * 語意：請款前取消授權。TotalAmount 為原授權金額（不支援部分取消）。
	 *
	 * @param string $trade_no 綠界交易編號 TradeNo（非 MerchantTradeNo）
	 * @param float  $amount   原授權金額
	 *
	 * @return array{RtnCode: string, RtnMsg: string} DoAction 回應（RtnCode='1' 為成功）
	 * @throws \Exception 連線失敗 / 缺 TradeNo / 回應無法解析 / RtnCode 非 1
	 */
	public function cancel_auth( string $trade_no, float $amount ): array {
		return $this->do_action( 'N', $trade_no, $amount, '取消授權' );
	}

	/**
	 * 發送 DoAction（C 請款 / N 取消授權 / R 退款），共用組裝 + CMV + MOCK + 解析
	 *
	 * @param string $action    'C' | 'N' | 'R'
	 * @param string $trade_no  綠界交易編號 TradeNo（非 MerchantTradeNo）
	 * @param float  $amount    金額（新台幣整數，無條件進位）
	 * @param string $action_zh 動作中文（order note 用：請款 / 取消授權 / 退款）
	 *
	 * @return array{RtnCode: string, RtnMsg: string}
	 * @throws \Exception 缺 TradeNo / 連線失敗 / 回應無法解析 / RtnCode 非 1
	 */
	private function do_action( string $action, string $trade_no, float $amount, string $action_zh ): array {
		$merchant_trade_no = ( new EcpayMetaKeys( $this->order ) )->get_trade_no();

		if ( '' === $trade_no ) {
			$msg = "綠界 AIO {$action_zh}缺少 TradeNo（綠界交易編號）";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		// 綠界僅收新台幣整數，無條件進位（與建單 TotalAmount 一致，避免少收 / 少退）
		$total_amount = (int) \ceil( $amount );

		$params = [
			'MerchantID'      => $this->settings->merchantId,
			'MerchantTradeNo' => $merchant_trade_no,
			'TradeNo'         => $trade_no,
			'Action'          => $action, // C=請款 / N=取消授權 / R=退款
			'TotalAmount'     => $total_amount,
		];

		// CheckMacValue（SHA256），演算法與建單一致
		$params['CheckMacValue'] = CheckMacValueService::get_check_value(
			$params,
			$this->settings->hashKey,
			$this->settings->hashIv,
			'sha256'
		);

		// MOCK 模式：不打真 API（DoAction 連 Stage 都不可用），以正式環境相同格式的 fixture 走同一解析路徑
		$response_body = self::is_mock()
		? self::build_mock_response_body( $params )
		: $this->request( $params, $action_zh );

		return $this->parse_response( $response_body, $action_zh );
	}

	/**
	 * 組 MOCK 成功回應 body（與正式環境 DoAction 回應同為 URL-encoded 字串）
	 *
	 * @param array<string, string|int> $params 請求參數
	 *
	 * @return string 例：`MerchantID=...&Merchant=...&MerchantTradeNo=...&TradeNo=...&RtnCode=1&RtnMsg=Succeeded.`
	 */
	private static function build_mock_response_body( array $params ): string {
		return \http_build_query(
			[
				'MerchantID'      => (string) ( $params['MerchantID'] ?? '' ),
				'Merchant'        => (string) ( $params['MerchantID'] ?? '' ),
				'MerchantTradeNo' => (string) ( $params['MerchantTradeNo'] ?? '' ),
				'TradeNo'         => (string) ( $params['TradeNo'] ?? '' ),
				'RtnCode'         => '1',
				'RtnMsg'          => 'Succeeded.',
			]
		);
	}

	/**
	 * 發送 POST 請求（form-urlencoded），回傳原始 body 字串
	 *
	 * @param array<string, string|int> $params    請求參數（含 CheckMacValue）
	 * @param string                    $action_zh 動作中文（log 用）
	 *
	 * @return string 原始回應 body（URL-encoded 字串 `key=value&...`）
	 * @throws \Exception 連線失敗
	 */
	private function request( array $params, string $action_zh = '退款' ): string {
		Plugin::logger(
			"綠界 AIO DoAction {$action_zh}請求 #{$this->order->get_id()}",
			'info',
			[ 'endpoint' => $this->get_endpoint() ]
		);

		$response = \wp_remote_post(
			$this->get_endpoint(),
			[
				'body'     => $params,
				'blocking' => true,
				'timeout'  => self::TIMEOUT,
			]
		);

		if ( \is_wp_error( $response ) ) {
			$msg = "綠界 AIO DoAction 連線失敗：{$response->get_error_message()}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		return (string) \wp_remote_retrieve_body( $response );
	}

	/**
	 * 解析 DoAction 回應（URL-encoded 字串，以 `&` / `=` 解析為 key-value）
	 *
	 * 綠界正式環境回應範例：`MerchantID=...&Merchant=...&MerchantTradeNo=...&TradeNo=...&RtnCode=1&RtnMsg=Succeeded.`。
	 * 拆為獨立方法以利測試（不需真 HTTP）。判定規則（fail-closed）：
	 *  - 無 RtnCode 鍵或非字串（空字串 / HTML 錯誤頁 / 舊 pipe 格式等無法解析）→ throw「回應無法解析」，
	 *    綠界端實際結果未知，order note 提醒站方至綠界廠商後台確認、勿重複操作；原始 body 僅截斷寫入 log。
	 *  - RtnCode 非 '1' → throw「失敗 RtnCode=...」，同樣附勿重複操作提醒。
	 *  - RtnCode = '1' → 成功。
	 *
	 * @param string $body      原始回應 body
	 * @param string $action_zh 動作中文（訊息用：請款 / 取消授權 / 退款；空字串則不帶）
	 *
	 * @return array{RtnCode: string, RtnMsg: string}
	 * @throws \Exception 回應無法解析 / RtnCode 非 1
	 */
	public function parse_response( string $body, string $action_zh = '' ): array {
		$trimmed_body = \trim( $body );
		$response     = [];
		\parse_str( $trimmed_body, $response );

		$label    = '' === $action_zh ? '綠界 AIO DoAction ' : "綠界 AIO DoAction {$action_zh}";
		$rtn_code = $response['RtnCode'] ?? null;
		$rtn_msg  = $response['RtnMsg'] ?? '';
		$rtn_msg  = \is_string( $rtn_msg ) ? $rtn_msg : '';

		// 無 RtnCode（或被陣列語法注入成非字串）→ 回應無法解析，綠界端實際結果未知，fail-closed
		if ( ! \is_string( $rtn_code ) ) {
			$this->log_unexpected_response( "{$label}回應無法解析（缺少 RtnCode）", $trimmed_body );
			$msg = "{$label}回應無法解析（缺少 RtnCode），綠界端實際結果未知，" . self::VERIFY_HINT;
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		$rtn_code = \trim( $rtn_code );
		if ( '1' !== $rtn_code ) {
			$this->log_unexpected_response( "{$label}失敗 RtnCode={$rtn_code}", $trimmed_body );
			$msg = "{$label}失敗 RtnCode={$rtn_code}：{$rtn_msg}。" . self::VERIFY_HINT;
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		return [
			'RtnCode' => $rtn_code,
			'RtnMsg'  => $rtn_msg,
		];
	}

	/**
	 * 將非預期回應寫入 log（原始 body 截斷，不寫進 order note）
	 *
	 * @param string $message      log 訊息
	 * @param string $trimmed_body 已 trim 的原始回應 body
	 *
	 * @return void
	 */
	private function log_unexpected_response( string $message, string $trimmed_body ): void {
		Plugin::logger(
			"{$message} #{$this->order->get_id()}",
			'error',
			[ 'body' => \mb_substr( $trimmed_body, 0, self::LOG_BODY_MAX_LENGTH, 'UTF-8' ) ]
		);
	}

	/** @return string DoAction 端點（一律正式環境，Stage 不支援） */
	private function get_endpoint(): string {
		// DoAction 僅正式環境可用，無論 mode 皆指向 prod 端點（呼叫端 MOCK 模式不會走到此）
		return self::ENDPOINT_PROD;
	}

	/** @return bool 是否為 MOCK 模式（測試用，不打真 API） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		return 'mock' === \strtolower( $mode );
	}
}
