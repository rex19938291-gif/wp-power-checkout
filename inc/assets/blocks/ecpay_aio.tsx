/**
 * 綠界 ECPay AIO 全方位金流 — WC Block Checkout 付款方式註冊
 *
 * 信用卡分期：綠界收銀台只要帶 CreditInstallment，信用卡就只剩分期（一次付清被隱藏），
 * 故分期不做成全站開關，而是在結帳頁由顧客自選（對齊 classic checkout 的 payment_fields 下拉）：
 *   1. 後端 AioBlocksIntegration 於 `ecpay_aio_data.installment` 提供欄位名 / 標題 / 說明 / 選項；
 *      options 為空代表後台未開啟分期，不渲染下拉。
 *   2. 顧客選擇後於 onPaymentSetup 以 paymentMethodData 回傳；Store API 會把 payment_data 放進 $_POST，
 *      後端 AioRedirectGateway::validate_fields / before_process_payment 依白名單驗證後寫入 order meta。
 *
 * 含 hooks 的元件以 `@wordpress/element`（window.wp.element）的 createElement 建立，
 * 與 WC Blocks 渲染器同一份 React（比照 ecpay_logistics.tsx）。
 *
 * @see inc/classes/Domains/Payment/EcpayAIO/Services/InstallmentCheckoutField.php
 * @see .claude/rules/react-blocks.rule.md
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry'
import { getSetting } from '@woocommerce/settings'
import {
	createElement,
	Fragment,
	useEffect,
	useState,
} from '@wordpress/element'
import { decodeEntities } from '@wordpress/html-entities'

/** 分期下拉選項（value 為期數字串，'' 代表不分期） */
type TInstallmentOption = {
	value: string
	label: string
}

/** 後端 InstallmentCheckoutField::get_block_data() 提供的分期欄位設定 */
type TInstallmentData = {
	field: string
	label: string
	description: string
	options: TInstallmentOption[]
}

/** WC Blocks 傳給付款方式 content 元件的 props（僅宣告本檔用到的部分） */
type TPaymentMethodProps = {
	eventRegistration?: {
		onPaymentSetup?: (callback: () => unknown) => () => void

		/** WC 舊版名稱（onPaymentSetup 前身），新版缺 onPaymentSetup 時的後備 */
		onPaymentProcessing?: (callback: () => unknown) => () => void
	}
	emitResponse?: {
		responseTypes?: {
			SUCCESS?: string
		}
	}
}

const id = 'ecpay_aio'
const settings = getSetting(`${id}_data`, {})
const { name, order_button_text, supports: features } = settings
const label = decodeEntities(settings.title)

/** 分期下拉的 DOM id */
const INSTALLMENT_SELECT_ID = 'pc-ecpay-aio-installment'

const installment: TInstallmentData = {
	field: '',
	label: '',
	description: '',
	options: [],
	...(settings.installment ?? {}),
}

const hasInstallment =
	'' !== installment.field &&
	Array.isArray(installment.options) &&
	installment.options.length > 0

/** 付款方式描述 */
const Description = () => {
	return decodeEntities(settings.description || '')
}

/**
 * 信用卡分期下拉：選擇結果於 onPaymentSetup 以 paymentMethodData 回傳後端
 *
 * @param props WC Blocks 付款方式 props
 * @return 下拉元素
 */
const InstallmentSelect = (props: TPaymentMethodProps): unknown => {
	const [selected, setSelected] = useState('')
	const register =
		props.eventRegistration?.onPaymentSetup ??
		props.eventRegistration?.onPaymentProcessing
	const successType = props.emitResponse?.responseTypes?.SUCCESS ?? 'success'

	useEffect(() => {
		if (!register) {
			return undefined
		}
		return register(() => ({
			type: successType,
			meta: {
				paymentMethodData: {
					[installment.field]: selected,
				},
			},
		}))
	}, [register, successType, selected])

	return createElement(
		'div',
		{
			className: 'pc-ecpay-aio-installment',
			style: { marginTop: '12px' },
		},
		createElement(
			'label',
			{
				htmlFor: INSTALLMENT_SELECT_ID,
				style: { display: 'block', marginBottom: '4px' },
			},
			decodeEntities(installment.label)
		),
		createElement(
			'select',
			{
				id: INSTALLMENT_SELECT_ID,
				value: selected,
				onChange: (event: { target: { value: string } }) =>
					setSelected(event.target.value),
				style: { width: '100%' },
			},
			...installment.options.map((option) =>
				createElement(
					'option',
					{ key: option.value, value: option.value },
					decodeEntities(option.label)
				)
			)
		),
		createElement(
			'small',
			{ style: { display: 'block', marginTop: '4px' } },
			decodeEntities(installment.description)
		)
	)
}

/**
 * 結帳頁付款方式內容：描述 + （後台開啟分期時）信用卡分期下拉
 *
 * @param props WC Blocks 付款方式 props
 * @return 內容元素
 */
const Content = (props: TPaymentMethodProps): unknown =>
	createElement(
		Fragment,
		null,
		createElement(Description),
		hasInstallment ? createElement(InstallmentSelect, props) : null
	)

/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = (props: any) => {
	const { PaymentMethodLabel } = props.components
	return <PaymentMethodLabel text={label} />
}

const options = {
	name,
	label: <Label />,
	ariaLabel: label,
	placeOrderButtonLabel: order_button_text,
	content: createElement(Content),
	edit: createElement(Description),
	canMakePayment: () => true,
	paymentMethodId: id,
	supports: {
		features,
		showSavedCards: false,
		showSaveOption: false,
	},
}

/**
 * 註冊綠界 ECPay AIO 付款方式
 */
registerPaymentMethod(options)
