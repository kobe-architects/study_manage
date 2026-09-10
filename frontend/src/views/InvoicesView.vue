<script setup lang="ts">
import { onMounted, ref } from 'vue'
import HelpTip from '@/components/HelpTip.vue'
import { INVOICE_STATUS, hoursLabel, invoiceApi, minToTime, workDateLabel, yen } from '@/api/invoice'
import { renderInvoiceSheet } from '@/lib/invoiceSheet'
import { useUiStore } from '@/stores/ui'
import type { InvoiceDetail, InvoiceSummary } from '@/types'

/**
 * 生徒用: 講師請求管理。
 * 講師が締めた月の請求書を仮発行し、講師の内容確認（正式発行）後に支払い、
 * 「支払済み」に更新する。請求書は PDF でプレビュー・保存できる。
 */
const ui = useUiStore()

const list = ref<InvoiceSummary[]>([])
const loading = ref(true)
const busy = ref(false)
const detail = ref<InvoiceDetail | null>(null)

async function load() {
  try {
    list.value = await invoiceApi.list()
  } catch {
    ui.notify('請求データの取得に失敗しました')
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function openDetail(i: InvoiceSummary) {
  try {
    detail.value = await invoiceApi.show(i.id)
  } catch {
    ui.notify('請求書の取得に失敗しました')
  }
}

function applyDetail(d: InvoiceDetail) {
  detail.value = d
  const i = list.value.findIndex((x) => x.id === d.id)
  if (i >= 0) list.value[i] = d
}

async function doAction(inv: InvoiceSummary, act: 'issue' | 'pay', confirmText: string) {
  if (!confirm(confirmText)) return
  busy.value = true
  try {
    const d = await invoiceApi.action(inv.id, act)
    applyDetail(d)
    if (!detail.value || detail.value.id !== d.id) {
      const i = list.value.findIndex((x) => x.id === d.id)
      if (i >= 0) list.value[i] = d
    }
    ui.notify(act === 'issue' ? '請求書を仮発行しました。講師の内容確認をお待ちください' : '支払済みに更新しました。講師の確認をお待ちください')
  } catch (e: unknown) {
    ui.notify((e as { response?: { data?: { message?: string } } })?.response?.data?.message || '処理に失敗しました')
  } finally {
    busy.value = false
  }
}

async function openPdf(inv: InvoiceSummary) {
  busy.value = true
  try {
    const d = detail.value?.id === inv.id ? detail.value : await invoiceApi.show(inv.id)
    await invoiceApi.openPdf(d.id, await renderInvoiceSheet(d))
  } catch {
    ui.notify('PDF の作成に失敗しました')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 14px">
      <div style="font-size: 17px; font-weight: 700">講師請求管理</div>
      <HelpTip
        text="講師が登録した稼働時間をもとに、月ごとの請求書を管理します。&#10;流れ: 講師が締め → あなたが「仮発行」→ 講師が内容確認（正式発行）→ あなたが支払い後「支払済み」に更新 → 講師が支払確認。各更新時に LINE 通知が届きます。"
      />
    </div>

    <div v-if="loading" class="hint">読み込み中…</div>
    <div v-else-if="!list.length" class="hint">請求書はまだありません。講師が稼働時間を登録して締めると、ここに表示されます。</div>
    <div v-else class="ilist">
      <div v-for="i in list" :key="i.id" class="irow">
        <div class="i-month" @click="openDetail(i)">
          <b>{{ i.year }}年{{ i.month }}月分</b>
          <span class="i-tutor">{{ i.tutorName }}</span>
        </div>
        <span class="i-hours">{{ hoursLabel(i.totalMinutes) }}時間</span>
        <span class="i-amount">{{ yen(i.amount) }}</span>
        <span class="ichip" :class="i.status">{{ INVOICE_STATUS[i.status].label }}</span>
        <span class="i-actions">
          <button v-if="i.status === 'closed'" class="btn primary" :disabled="busy" @click="doAction(i, 'issue', `${i.year}年${i.month}月分（${yen(i.amount)}）の請求書を仮発行しますか？\n講師へ通知され、内容確認後に正式発行となります。`)">請求書を仮発行</button>
          <button v-if="i.status === 'confirmed'" class="btn primary" :disabled="busy" @click="doAction(i, 'pay', `${i.year}年${i.month}月分（${yen(i.amount)}）を支払済みにしますか？`)">支払済みにする</button>
          <button v-if="i.status !== 'open'" class="btn" :disabled="busy" @click="openPdf(i)">請求書PDF</button>
          <button class="btn" @click="openDetail(i)">明細</button>
        </span>
      </div>
    </div>

    <!-- 明細モーダル -->
    <div v-if="detail" class="overlay" @click="detail = null">
      <div class="modal" @click.stop>
        <div class="m-head">
          <div>
            <div style="font-size: 15px; font-weight: 700">{{ detail.year }}年{{ detail.month }}月分 請求明細</div>
            <div style="font-size: 11.5px; color: var(--mut); margin-top: 2px">講師: {{ detail.tutorName }}・<span class="ichip sm" :class="detail.status">{{ INVOICE_STATUS[detail.status].label }}</span></div>
          </div>
          <button class="x" @click="detail = null">×</button>
        </div>
        <div class="entries">
          <div v-for="e in detail.entries" :key="e.id" class="erow">
            <span class="e-date">{{ workDateLabel(e.workOn) }}</span>
            <span class="e-time">{{ minToTime(e.startMin) }} 〜 {{ minToTime(e.endMin) }}</span>
            <span class="e-hours">{{ hoursLabel(e.minutes) }}h</span>
            <span class="e-note">{{ e.note }}</span>
          </div>
          <div v-if="!detail.entries.length" class="hint" style="margin: 0">稼働時間はまだ登録されていません。</div>
        </div>
        <div class="totals">
          <span>合計 <b>{{ hoursLabel(detail.totalMinutes) }}</b> 時間</span>
          <span>時給 {{ yen(detail.hourlyRate) }}</span>
          <span class="amount">合計金額 <b>{{ yen(detail.amount) }}</b></span>
        </div>
        <div class="m-foot">
          <button v-if="detail.status === 'closed'" class="btn primary" :disabled="busy" @click="doAction(detail, 'issue', `${detail.year}年${detail.month}月分（${yen(detail.amount)}）の請求書を仮発行しますか？\n講師へ通知され、内容確認後に正式発行となります。`)">請求書を仮発行</button>
          <button v-if="detail.status === 'confirmed'" class="btn primary" :disabled="busy" @click="doAction(detail, 'pay', `${detail.year}年${detail.month}月分（${yen(detail.amount)}）を支払済みにしますか？`)">支払済みにする</button>
          <button v-if="detail.status !== 'open'" class="btn" :disabled="busy" @click="openPdf(detail)">請求書PDF</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.hint {
  background: #f8f9fb;
  border: 1px dashed #d8dce1;
  border-radius: 14px;
  padding: 15px 18px;
  font-size: 12.5px;
  color: var(--faint);
  line-height: 1.7;
}
.ilist {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.irow {
  display: flex;
  align-items: center;
  gap: 14px;
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 12px 16px;
  flex-wrap: wrap;
}
.i-month {
  min-width: 150px;
  cursor: pointer;
  font-size: 13.5px;
}
.i-tutor {
  display: block;
  font-size: 11px;
  color: var(--faint);
}
.i-hours {
  width: 74px;
  font-size: 12.5px;
  color: var(--mut);
}
.i-amount {
  width: 100px;
  font-size: 14px;
  font-weight: 700;
}
.i-actions {
  display: flex;
  gap: 6px;
  margin-left: auto;
  flex-wrap: wrap;
}
.btn {
  padding: 7px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  font-size: 12px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
  white-space: nowrap;
}
.btn.primary {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.btn:disabled {
  opacity: 0.4;
  cursor: default;
}
.ichip {
  font-size: 10.5px;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 999px;
  white-space: nowrap;
}
.ichip.sm {
  font-size: 10px;
  padding: 2px 8px;
}
.ichip.open {
  background: #f1f2f4;
  color: var(--mut);
}
.ichip.closed {
  background: #fff3e0;
  color: #b26a00;
}
.ichip.issued {
  background: #e8eefb;
  color: #2e4a8f;
}
.ichip.confirmed {
  background: #ede7f6;
  color: #5e35b1;
}
.ichip.paid {
  background: #e0f2f1;
  color: #00695c;
}
.ichip.done {
  background: #e6f5ec;
  color: #2f7a4f;
}
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(20, 24, 32, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 60;
  padding: 16px;
}
.modal {
  background: #fff;
  border-radius: 16px;
  padding: 18px 20px;
  width: 100%;
  max-width: 620px;
  max-height: 92vh;
  overflow-y: auto;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.m-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 10px;
  margin-bottom: 12px;
}
.x {
  border: none;
  background: transparent;
  font-size: 22px;
  color: #9aa1ab;
  cursor: pointer;
  line-height: 1;
}
.entries {
  border: 1px solid var(--line);
  border-radius: 10px;
  overflow: hidden;
}
.erow {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 12px;
  font-size: 12.5px;
  flex-wrap: wrap;
}
.erow + .erow {
  border-top: 1px solid #f1f2f4;
}
.e-date {
  width: 80px;
  font-weight: 600;
}
.e-time {
  width: 130px;
  font-variant-numeric: tabular-nums;
}
.e-hours {
  width: 48px;
  font-weight: 700;
}
.e-note {
  flex: 1;
  min-width: 0;
  color: var(--faint);
  font-size: 11.5px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.totals {
  display: flex;
  align-items: center;
  gap: 20px;
  flex-wrap: wrap;
  margin-top: 12px;
  padding: 10px 14px;
  background: #f6f7f9;
  border-radius: 10px;
  font-size: 13px;
}
.totals b {
  font-size: 17px;
}
.totals .amount {
  margin-left: auto;
}
.m-foot {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 14px;
}
</style>
