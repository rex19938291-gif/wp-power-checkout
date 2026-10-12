<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers;

/**
 * 綠界 AIO / ECPG 共用 Order Meta Key 存取
 *
 * 設計比照 Shared\Helpers\MetaKeys，但綠界有專屬的冪等鍵（MerchantTradeNo）與
 * 取號資訊（ATM/CVS/BARCODE），語意與 SLP 不同，故獨立一個 helper 避免污染共用 MetaKeys。
 * 一律透過 $order->get_meta() / update_meta_data()（HPOS 相容），禁用 get_post_meta()。
 */
final class EcpayMetaKeys {

	/**
	 * @var string 綠界 MerchantTradeNo（AIO / ECPG 共用：最近一次送出的編號；付款成功後改寫為實際付款成功的編號）
	 *
	 * DoAction（退款 / 請款 / 取消授權）與 QueryTradeInfo 皆以此值當 MerchantTradeNo。
	 */
	private const TRADE_NO_KEY = '_pc_ecpay_trade_no';

	/**
	 * @var string AIO / ECPG 送出過的全部 MerchantTradeNo（每個編號一筆 meta row，非序列化陣列）
	 *
	 * 綠界同一 MerchantTradeNo 不可重送，顧客重試付款時每次都換新編號；舊編號的取號 / 繳費仍可能晚到
	 * （例：先拿 ATM 帳號、改刷卡未付、再用 ATM 帳號繳費），故全部保留供 callback 反查。
	 * 每筆獨立 row 才能以 meta_key + meta_value 精確查詢（HPOS / CPT 皆同）。
	 */
	private const TRADE_NO_HISTORY_KEY = '_pc_ecpay_trade_no_history';

	/** @var string 顧客已發起付款（process_payment）、待 order-received 頁導轉綠界的旗標（'yes'，導轉後清除） */
	private const REDIRECT_PENDING_KEY = '_pc_ecpay_redirect_pending';

	/** @var string 付款結果明細（ReturnURL 通知寫入） */
	private const PAYMENT_DETAIL_KEY = '_pc_ecpay_payment_detail';

	/** @var string 取號繳費資訊（PaymentInfoURL 通知寫入） */
	private const PAYMENT_INFO_KEY = '_pc_ecpay_payment_info';

	/** @var string 信用卡付款變體（''｜'installment'｜'period'，checkout 顧客選擇寫入） */
	private const CREDIT_VARIANT_KEY = '_pc_ecpay_credit_variant';

	/** @var string 信用卡分期期數（顧客選擇的單一期數，如 '6'，checkout 寫入） */
	private const INSTALLMENT_KEY = '_pc_ecpay_installment';

	/** @var string 信用卡請款 / 授權狀態（''｜'captured' 已請款｜'voided' 已取消授權，DoAction C/N 寫入） */
	private const CAPTURE_STATUS_KEY = '_pc_ecpay_capture_status';

	/** Constructor */
	public function __construct(
		private readonly \WC_Order $_order,
	) {}

	/** @return string 取得 MerchantTradeNo */
	public function get_trade_no(): string {
		return (string) ( $this->_order->get_meta( self::TRADE_NO_KEY ) ?: '' );
	}

	/**
	 * 儲存 MerchantTradeNo
	 *
	 * @param string $value MerchantTradeNo
	 * @return void
	 */
	public function update_trade_no( string $value ): void {
		$this->_order->update_meta_data( self::TRADE_NO_KEY, $value );
		$this->_order->save_meta_data();
	}

	/**
	 * @return array<int, string> AIO / ECPG 送出過的全部 MerchantTradeNo（依送出順序，不含升級前未入歷史的舊編號）
	 */
	public function get_trade_no_history(): array {
		$rows = $this->_order->get_meta( self::TRADE_NO_HISTORY_KEY, false );
		if ( ! \is_array( $rows ) ) {
			return [];
		}

		$values = [];
		foreach ( $rows as $row ) {
			$value = $row instanceof \WC_Meta_Data ? ( $row->get_data()['value'] ?? null ) : null;
			if ( \is_string( $value ) && '' !== $value && ! \in_array( $value, $values, true ) ) {
				$values[] = $value;
			}
		}

		return $values;
	}

	/**
	 * @return array<int, string> 本訂單用過的全部 MerchantTradeNo（目前編號 + 歷史），產生新編號時須避開
	 */
	public function get_used_trade_nos(): array {
		$used    = $this->get_trade_no_history();
		$current = $this->get_trade_no();
		if ( '' !== $current && ! \in_array( $current, $used, true ) ) {
			$used[] = $current;
		}

		return $used;
	}

	/**
	 * 開始一次新的付款嘗試（AIO 導轉 / ECPG 取 token）：寫入新的 MerchantTradeNo 為目前編號、加入歷史、清除待導轉旗標（一次 save）
	 *
	 * 升級前寫入、尚未入歷史的目前編號會先補進歷史，確保其 callback 仍能反查到本訂單。
	 *
	 * @param string $trade_no 本次送往綠界的 MerchantTradeNo
	 * @return void
	 */
	public function start_trade_attempt( string $trade_no ): void {
		$this->add_to_history( [ $this->get_trade_no(), $trade_no ] );
		$this->_order->update_meta_data( self::TRADE_NO_KEY, $trade_no );
		$this->_order->delete_meta_data( self::REDIRECT_PENDING_KEY );
		$this->_order->save_meta_data();
	}

	/**
	 * 把目前編號改寫為實際付款成功的 MerchantTradeNo（原目前編號保留於歷史，callback 仍可反查）
	 *
	 * @param string $trade_no 付款成功的 MerchantTradeNo
	 * @return void
	 */
	public function settle_trade_no( string $trade_no ): void {
		$this->add_to_history( [ $this->get_trade_no(), $trade_no ] );
		$this->_order->update_meta_data( self::TRADE_NO_KEY, $trade_no );
		$this->_order->save_meta_data();
	}

	/**
	 * 將尚未入歷史的編號加入歷史（每個編號一筆 meta row；不 save，由呼叫端統一 save）
	 *
	 * @param array<int, string> $trade_nos MerchantTradeNo 清單（空字串略過）
	 * @return void
	 */
	private function add_to_history( array $trade_nos ): void {
		$history = $this->get_trade_no_history();
		foreach ( $trade_nos as $value ) {
			if ( '' !== $value && ! \in_array( $value, $history, true ) ) {
				$this->_order->add_meta_data( self::TRADE_NO_HISTORY_KEY, $value, false );
				$history[] = $value;
			}
		}
	}

	/** @return bool 顧客是否已發起付款、待 order-received 頁導轉綠界 */
	public function is_redirect_pending(): bool {
		return 'yes' === $this->_order->get_meta( self::REDIRECT_PENDING_KEY );
	}

	/**
	 * 標記「顧客已發起付款，待 order-received 頁導轉綠界」（process_payment 時寫入）
	 *
	 * @return void
	 */
	public function mark_redirect_pending(): void {
		$this->_order->update_meta_data( self::REDIRECT_PENDING_KEY, 'yes' );
		$this->_order->save_meta_data();
	}

	/** @return array<string, mixed> 取得付款結果明細 */
	public function get_payment_detail(): array {
		$value = $this->_order->get_meta( self::PAYMENT_DETAIL_KEY ) ?: [];
		return \is_array( $value ) ? $value : [];
	}

	/**
	 * 儲存付款結果明細
	 *
	 * @param array<string, mixed> $value 付款結果明細
	 * @return void
	 */
	public function update_payment_detail( array $value ): void {
		$this->_order->update_meta_data( self::PAYMENT_DETAIL_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return array<string, mixed> 取得取號繳費資訊 */
	public function get_payment_info(): array {
		$value = $this->_order->get_meta( self::PAYMENT_INFO_KEY ) ?: [];
		return \is_array( $value ) ? $value : [];
	}

	/**
	 * 儲存取號繳費資訊
	 *
	 * @param array<string, mixed> $value 取號繳費資訊（BankCode/vAccount/PaymentNo/Barcode/ExpireDate 等）
	 * @return void
	 */
	public function update_payment_info( array $value ): void {
		$this->_order->update_meta_data( self::PAYMENT_INFO_KEY, $value );
		$this->_order->save_meta_data();
	}

	/**
	 * @return string 取得信用卡付款變體（''｜'installment'｜'period'）
	 */
	public function get_credit_variant(): string {
		return (string) ( $this->_order->get_meta( self::CREDIT_VARIANT_KEY ) ?: '' );
	}

	/**
	 * 儲存信用卡付款變體
	 *
	 * @param string $value ''｜'installment'｜'period'
	 * @return void
	 */
	public function update_credit_variant( string $value ): void {
		$this->_order->update_meta_data( self::CREDIT_VARIANT_KEY, $value );
		$this->_order->save_meta_data();
	}

	/**
	 * @return string 取得信用卡分期期數（顧客選擇的單一期數，如 '6'；無則空字串）
	 */
	public function get_installment(): string {
		return (string) ( $this->_order->get_meta( self::INSTALLMENT_KEY ) ?: '' );
	}

	/**
	 * 儲存信用卡分期期數
	 *
	 * @param string $value 期數（如 '6'）
	 * @return void
	 */
	public function update_installment( string $value ): void {
		$this->_order->update_meta_data( self::INSTALLMENT_KEY, $value );
		$this->_order->save_meta_data();
	}

	/**
	 * 清除信用卡分期設定（變體 + 期數；顧客於結帳頁改選「不分期」時使用）
	 *
	 * @return void
	 */
	public function clear_installment(): void {
		$this->_order->delete_meta_data( self::CREDIT_VARIANT_KEY );
		$this->_order->delete_meta_data( self::INSTALLMENT_KEY );
		$this->_order->save_meta_data();
	}

	/**
	 * @return string 取得信用卡請款 / 授權狀態（''｜'captured'｜'voided'）
	 */
	public function get_capture_status(): string {
		return (string) ( $this->_order->get_meta( self::CAPTURE_STATUS_KEY ) ?: '' );
	}

	/**
	 * 儲存信用卡請款 / 授權狀態
	 *
	 * @param string $value ''｜'captured' 已請款｜'voided' 已取消授權
	 * @return void
	 */
	public function update_capture_status( string $value ): void {
		$this->_order->update_meta_data( self::CAPTURE_STATUS_KEY, $value );
		$this->_order->save_meta_data();
	}

	/**
	 * 以 MerchantTradeNo 反查訂單
	 *
	 * 先比對目前編號（`_pc_ecpay_trade_no`，ECPG 與 AIO 共用），找不到再比對送出過的歷史編號，
	 * 讓顧客重試付款後，舊編號的取號 / 繳費通知仍能對應到同一張訂單。
	 *
	 * @param string $trade_no MerchantTradeNo
	 * @return \WC_Order|null 空字串或查無回 null
	 */
	public static function get_order_by_trade_no( string $trade_no ): \WC_Order|null {
		if ( '' === $trade_no ) {
			return null;
		}

		foreach ( [ self::TRADE_NO_KEY, self::TRADE_NO_HISTORY_KEY ] as $meta_key ) {
			$orders = \wc_get_orders(
				[
					'limit'      => 1,
					'meta_key'   => $meta_key, // phpcs:ignore
					'meta_value' => $trade_no, // phpcs:ignore
				]
			);
			$order  = \is_array( $orders ) ? \reset( $orders ) : false;
			if ( $order instanceof \WC_Order ) {
				return $order;
			}
		}

		return null;
	}
}
