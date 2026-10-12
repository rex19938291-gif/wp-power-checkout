<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers;

use J7\PowerCheckout\Shared\Utils\StrHelper;

/**
 * 綠界 MerchantTradeNo 編解碼
 *
 * MerchantTradeNo 格式：{order_id}TS{反轉後的 timestamp}，並截斷至 20 碼以內。
 * 反轉 timestamp 是為了讓相鄰訂單的編號前綴差異化（避免綠界端視為重複）。
 *
 * ⚠️ 綠界 MerchantTradeNo 永久唯一：同一編號未付款前再送 AioCheckOut 也會被拒
 * （stage 實測回「10300028 訂單編號重覆，建立失敗」），故顧客重試付款時須以 {@see self::encode_unique()}
 * 產生「不在本訂單已用過清單內」的新編號。
 *
 * @see https://www.ecpay.com.tw/CascadeFAQ/CascadeFAQ_Qa?nID=1454
 */
final class TradeNo {

	/** @var int MerchantTradeNo 長度上限（綠界規格 String(20)） */
	private const MAX_LENGTH = 20;

	/** @var int encode_unique 最多嘗試次數（每次 timestamp +1 秒，實務上 1~2 次即可） */
	private const MAX_UNIQUE_ATTEMPTS = 100;

	/**
	 * 由訂單 ID 生成唯一的 MerchantTradeNo（≤ 20 碼）
	 *
	 * @param int $order_id 訂單 ID
	 * @return string MerchantTradeNo
	 */
	public static function encode( int $order_id ): string {
		return self::encode_at( $order_id, \time() );
	}

	/**
	 * 由訂單 ID 生成不在 $used 清單內的 MerchantTradeNo（≤ 20 碼，格式同 {@see self::encode()}）
	 *
	 * 由於 encode() 以秒為單位，同一秒內重試會得到相同編號；此時改用下一秒的 timestamp 重算，
	 * 直到不與本訂單已送出過的編號重複（格式不變，decode 仍可反解訂單 ID）。
	 *
	 * @param int           $order_id 訂單 ID
	 * @param array<string> $used     本訂單已用過的 MerchantTradeNo
	 * @return string MerchantTradeNo
	 * @throws \RuntimeException 嘗試上限內仍無法產生不重複編號（理論上不會發生）
	 */
	public static function encode_unique( int $order_id, array $used ): string {
		$now = \time();
		for ( $offset = 0; $offset < self::MAX_UNIQUE_ATTEMPTS; $offset++ ) {
			$candidate = self::encode_at( $order_id, $now + $offset );
			if ( ! \in_array( $candidate, $used, true ) ) {
				return $candidate;
			}
		}

		throw new \RuntimeException( "無法為訂單 #{$order_id} 產生不重複的 MerchantTradeNo" );
	}

	/**
	 * 以指定 timestamp 組 MerchantTradeNo
	 *
	 * @param int $order_id  訂單 ID
	 * @param int $timestamp Unix timestamp
	 * @return string MerchantTradeNo（≤ 20 碼）
	 */
	private static function encode_at( int $order_id, int $timestamp ): string {
		$trade_no = $order_id . 'TS' . \strrev( (string) $timestamp );
		return \substr( $trade_no, 0, self::MAX_LENGTH );
	}

	/**
	 * 由 MerchantTradeNo 反解出訂單 ID
	 *
	 * @param string $trade_no MerchantTradeNo
	 * @return string 訂單 ID
	 */
	public static function decode( string $trade_no ): string {
		$order_prefix = '';
		$offset       = ( new StrHelper( $order_prefix ) )->get_strlen();
		return substr( $trade_no, $offset, strrpos( $trade_no, 'TS' ) ?: 0 );
	}
}
