<script setup lang="ts">
import { useMutation } from '@tanstack/vue-query'
import type { FormRules } from 'element-plus'
import { reactive, ref, toRaw, watch } from 'vue'

import apiClient from '@/api'
import { appData, MAPPER, isAdmin } from '@/external/InvoiceApp'
import {
	EIndividual,
	EInvoiceType,
	individuals,
	invoiceTypes,
} from '@/external/InvoiceApp/Shared/constants'
import { TFormData } from '@/external/InvoiceApp/Shared/types'
import { resolveErrorMessage, getNormalizedError } from '@/utils/error-code'

const emit = defineEmits<{
	close: []
}>()

const props = defineProps<{
	dialogVisible: boolean
}>()

const active = ref(0)

// Element Plus 表單 ref
const formRef = ref()

// 表單資料
const DEFAULT_FORM: TFormData = {
	provider: '', // 服務提供商
	invoiceType: undefined, // 發票類型
	individual: undefined, // 個人發票類型 雲端載具、手機條碼、自然人憑證
	carrier: '', // 載具
	moica: '', //自然人憑證
	companyName: '',
	companyId: '',
	donateCode: '', // 捐贈碼
}

// 用副本建立 reactive，避免表單編輯時改到 DEFAULT_FORM 本身
const form = reactive<TFormData>({ ...DEFAULT_FORM })

const prev = () => {
	if (active.value-- < 0) active.value = 0
}
const next = () => {
	if (active.value++ > 2) active.value = 0
}

const { mutate: issueInvoice, isPending } = useMutation({
	mutationFn: async ({ orderId, data }: { orderId: string; data: TFormData }) =>
		await apiClient.post(`/invoices/issue/${orderId}`, data),
	onError: (err) => {
		// interceptor 已用 error_code 對照彈出使用者通知；此處解析出同一則
		// 精確訊息供 debug log（取代純粹的 console.error）。
		const normalized = getNormalizedError(err)
		// eslint-disable-next-line no-console
		console.error(
			'發行電子發票失敗',
			resolveErrorMessage(normalized),
			normalized?.raw_code ?? '',
			err
		)
	},
})

const providers = appData?.invoice_providers || []

const handleIssue = async () => {
	await formRef.value.validate((valid: boolean) => {
		if (form.invoiceType === EInvoiceType.DONATE) {
			form.carrier = ''
			form.moica = ''
			form.companyName = ''
			form.companyId = ''
		}

		if (form.invoiceType === EInvoiceType.COMPANY) {
			form.carrier = ''
			form.moica = ''
			form.donateCode = ''
		}

		if (form.invoiceType === EInvoiceType.INDIVIDUAL) {
			if (
				form.individual === EIndividual.CLOUD ||
				form.individual === EIndividual.PAPER
			) {
				form.carrier = ''
				form.moica = ''
			}

			if (form.individual === EIndividual.BARCODE) {
				form.moica = ''
			}

			if (form.individual === EIndividual.MOICA) {
				form.carrier = ''
			}

			form.companyName = ''
			form.companyId = ''
			form.donateCode = ''
		}

		if (!valid) {
			return
		}

		const formObj = toRaw(form)

		// Admin 介面就發 API 開發票
		if (isAdmin) {
			const orderId = appData?.order?.id
			issueInvoice({
				orderId,
				data: formObj,
			})
			emit('close')
			return
		}

		// Checkout 頁面就填入 input 欄位
		const render_ids = appData?.render_ids || []
		render_ids.forEach((id: string) => {
			const input = document.getElementById(id) as HTMLInputElement
			if (!input) {
				return
			}

			// 確認 input html tag name 為 input
			if ('INPUT' !== (input?.tagName || '').toUpperCase()) {
				return
			}

			input.value = JSON.stringify(formObj)
			emit('close')

			//TODO 區塊結帳之後處理
		})
	})
}

const STRING_FIELDS = [
	'carrier',
	'moica',
	'companyName',
	'companyId',
	'donateCode',
] as const

/**
 * 讀取結帳頁隱藏欄位中已儲存的發票資料
 * 只有結帳頁的 render id 是 input；後台 metabox 是 div，會回傳 null
 * 服務商已停用、發票種類不合法時視為無資料，讓顧客重新選擇
 */
const getSavedForm = (): TFormData | null => {
	const render_ids = appData?.render_ids || []
	for (const id of render_ids) {
		const input = document.getElementById(id)
		if (!(input instanceof HTMLInputElement) || !input.value) {
			continue
		}

		try {
			const saved = JSON.parse(input.value)
			const isValidProvider = providers.some(
				(provider) => provider.id === saved?.provider
			)
			const isValidInvoiceType = invoiceTypes.some(
				(item) => item.value === saved?.invoiceType
			)
			if (!isValidProvider || !isValidInvoiceType) {
				return null
			}

			const savedForm: TFormData = {
				...DEFAULT_FORM,
				provider: saved.provider,
				invoiceType: saved.invoiceType,
				individual: individuals.some((item) => item.value === saved.individual)
					? saved.individual
					: undefined,
			}
			STRING_FIELDS.forEach((key) => {
				if (typeof saved[key] === 'string') {
					savedForm[key] = saved[key]
				}
			})
			return savedForm
		} catch {
			return null
		}
	}
	return null
}

// 每次開啟 dialog：有已儲存的發票資料就帶回並停在最後一步，否則重置表單
// dialog 內容在第一次開啟時才 mount，需 immediate 才能涵蓋第一次開啟
watch(
	() => props.dialogVisible,
	(visible) => {
		if (!visible) return
		formRef.value?.resetFields()
		const savedForm = getSavedForm()
		Object.assign(form, savedForm ?? DEFAULT_FORM)
		active.value = savedForm ? 2 : 0
	},
	{ immediate: true }
)

const CARRIER_PATTERN = /^\/[0-9A-Z+\-.]{7}$/
const MOICA_PATTERN = /^TP[0-9]{14}$/

const rules = reactive<FormRules<TFormData>>({
	carrier: [
		{
			validator: (_rule, value, callback) => {
				if (!value) return callback()
				if (!CARRIER_PATTERN.test(value)) {
					return callback(new Error('載具格式不符合'))
				}
				callback()
			},
			trigger: 'blur',
		},
	],
	moica: [
		{
			validator: (_rule, value, callback) => {
				if (!value) return callback()
				if (!MOICA_PATTERN.test(value)) {
					return callback(new Error('自然人憑證格式不符合'))
				}
				callback()
			},
			trigger: 'blur',
		},
	],
})
</script>

<template>
	<el-steps
		:active="active"
		finish-status="finish"
		align-center
		class="pc-invoice-steps my-8"
	>
		<el-step title="選擇服務提供商" />
		<el-step title="選擇發票種類" />
		<el-step title="填寫發票資料" />
	</el-steps>

	<el-form
		ref="formRef"
		element-loading-background="rgba(255, 255, 255, 0)"
		:model="form"
		label-position="top"
		label-width="auto"
		class="mb-8"
		:rules="rules"
	>
		<!-- 選擇發票服務提供商 -->
		<el-form-item
			:class="{
				'tw-hidden': active !== 0,
			}"
			prop="provider"
		>
			<el-radio-group
				v-model="form.provider"
				class="pc-invoice-radio-grid grid grid-cols-1 sm:grid-cols-3 gap-4 [&_label]:w-full w-full"
			>
				<el-radio
					v-for="provider in providers"
					:key="provider.id"
					:value="provider.id"
					border
					>{{ provider.title }}</el-radio
				>
			</el-radio-group>
		</el-form-item>

		<!-- 選擇發票種類 -->
		<el-form-item
			:class="{
				'tw-hidden': active !== 1,
			}"
			prop="invoiceType"
		>
			<el-radio-group
				v-model="form.invoiceType"
				class="pc-invoice-radio-grid grid grid-cols-1 sm:grid-cols-3 gap-4 [&_label]:w-full w-full"
			>
				<el-radio
					v-for="item in invoiceTypes"
					:key="item.value"
					:value="item.value"
					border
					>{{ item.label }}</el-radio
				>
			</el-radio-group>
		</el-form-item>

		<div
			:class="{
				'tw-hidden': active !== 2,
			}"
		>
			<el-form-item
				prop="individual"
				:class="{
					'tw-hidden': form.invoiceType !== EInvoiceType.INDIVIDUAL,
				}"
			>
				<el-radio-group
					v-model="form.individual"
					class="pc-invoice-radio-grid grid grid-cols-1 sm:grid-cols-3 gap-4 [&_label]:w-full w-full"
				>
					<el-radio
						v-for="item in individuals"
						:key="item.value"
						:value="item.value"
						border
						>{{ item.label }}</el-radio
					>
				</el-radio-group>
			</el-form-item>

			<el-form-item
				prop="carrier"
				label="載具"
				:class="{
					'tw-hidden':
						form.invoiceType !== EInvoiceType.INDIVIDUAL ||
						form.individual !== EIndividual.BARCODE,
				}"
			>
				<el-input v-model="form.carrier" clearable />
			</el-form-item>
			<el-form-item
				prop="moica"
				label="自然人憑證"
				:class="{
					'tw-hidden':
						form.invoiceType !== EInvoiceType.INDIVIDUAL ||
						form.individual !== EIndividual.MOICA,
				}"
			>
				<el-input v-model="form.moica" clearable />
			</el-form-item>
			<el-form-item
				prop="companyName"
				label="公司名稱"
				:class="{
					'tw-hidden': form.invoiceType !== EInvoiceType.COMPANY,
				}"
			>
				<el-input v-model="form.companyName" clearable />
			</el-form-item>
			<el-form-item
				prop="companyId"
				label="統一編號"
				:class="{
					'tw-hidden': form.invoiceType !== EInvoiceType.COMPANY,
				}"
			>
				<el-input v-model="form.companyId" clearable />
			</el-form-item>
			<el-form-item
				prop="donateCode"
				:class="{
					'tw-hidden': form.invoiceType !== EInvoiceType.DONATE,
				}"
			>
				<template #label>
					<span class="flex gap-x-2 items-center">
						<span
							>捐贈碼 (<a
								href="https://www.einvoice.nat.gov.tw/portal/btc/btc603w/search"
								target="_blank"
								>查詢</a
							>)
						</span>
					</span>
				</template>

				<el-input v-model="form.donateCode" clearable />
			</el-form-item>
		</div>
	</el-form>

	<div class="flex justify-between items-center">
		<el-button
			:class="{
				'opacity-0': active === 0,
			}"
			@click="prev"
			>上一步</el-button
		>
		<el-button
			:class="{
				'tw-hidden': active === 2,
			}"
			:disabled="
				(active === 0 && !form.provider) || (active === 1 && !form.invoiceType)
			"
			@click="next"
			>下一步</el-button
		>
		<el-button
			:class="{
				'tw-hidden': active !== 2,
			}"
			type="primary"
			:loading="isPending"
			@click="handleIssue"
			>{{ MAPPER.SUBMIT }}</el-button
		>
	</div>
</template>

<style>
.el-radio {
	position: relative !important;
	left: unset !important;
	top: unset !important;
	max-width: unset !important;
	margin: 0 30px 0 0;
}

/* 發票選項以 grid 排列，間距交給 gap；高度隨文字撐開，避免窄螢幕截斷或超出外框 */
.pc-invoice-radio-grid .el-radio.is-bordered {
	height: auto;
	min-height: 32px;
	margin: 0;
	padding-top: 4px;
	padding-bottom: 4px;
	white-space: normal;
}

/* 窄螢幕縮小步驟標題，避免折行 */
@media (max-width: 575px) {
	.pc-invoice-steps .el-step__title {
		font-size: 13px;
	}
}
</style>
