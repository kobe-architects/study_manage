<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import HelpTip from '@/components/HelpTip.vue'
import { INVOICE_STATUS, hoursLabel, invoiceApi, minToTime, workDateLabel, yen } from '@/api/invoice'
import { renderInvoiceSheet } from '@/lib/invoiceSheet'
import { useUiStore } from '@/stores/ui'
import type { InvoiceDetail, InvoiceSummary } from '@/types'

/**
 * 講師用: 請求書管理。
 * 稼働時間・締め・仮発行は生徒側で行われる。講師は内容を確認して
 * 「請求内容確認済み（正式発行）」「支払確認済み」への更新と PDF 発行を行う。
 */
const ui = useUiStore()

const list = ref<InvoiceSummary[]>([])
const cur = ref<InvoiceDetail | null>(null)
const loading = ref(true)
const busy = ref(false)

const now = new Date()
const ym = reactive({ y: now.getFullYear(), m: now.getMonth() + 1 })
const ymLabel = computed(() => `${ym.y}年${ym.m}月`)

function moveMonth(d: number) {
  const dt = new Date(ym.y, ym.m - 1 + d, 1)
  ym.y = dt.getFullYear()
  ym.m = dt.getMonth() + 1
}

async function loadList() {
  try {
    list.value = await invoiceApi.list()
  } catch {
    ui.notify('請求データの取得に失敗しました')
  } finally {
    loading.value = false
  }
}

async function loadCurrent() {
  const found = list.value.find((i) => i.year === ym.y && i.month === ym.m)
  if (!found) {
    cur.value = null
    return
  }
  try {
    cur.value = await invoiceApi.show(found.id)
  } catch {
    ui.notify('請求書の取得に失敗しました')
  }
}

onMounted(async () => {
  await loadList()
  await loadCurrent()
})
watch(ym, loadCurrent)

function applyDetail(d: InvoiceDetail) {
  cur.value = d
  const i = list.value.findIndex((x) => x.id === d.id)
  if (i >= 0) list.value[i] = d
}

async function doAction(act: 'confirm' | 'confirm-payment', confirmText: string) {
  if (!cur.value || !confirm(confirmText)) return
  busy.value = true
  try {
    applyDetail(await invoiceApi.action(cur.value.id, act))
    ui.notify(act === 'confirm' ? '請求内容を確認済みにしました（正式発行）' : '支払いを確認済みにしました')
  } catch (e: unknown) {
    ui.notify((e as { response?: { data?: { message?: string } } })?.response?.data?.message || '処理に失敗しました')
  } finally {
    busy.value = false
  }
}

async function openPdf() {
  if (!cur.value) return
  busy.value = true
  try {
    await invoiceApi.openPdf(cur.value.id, await renderInvoiceSheet(cur.value))
  } catch {
    ui.notify('PDF の作成に失敗しました')
  } finally {
    busy.value = false
  }
}

const st = computed(() => (cur.value ? INVOICE_STATUS[cur.value.status] : null))
</script>

<template>
  <div>
    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 14px; flex-wrap: wrap">
      <div style="font-size: 17px; font-weight: 700">請求書管理</div>
      <HelpTip
        text="稼働時間の登録・締め・仮発行は生徒側で行われます。&#10;仮発行されたら内容を確認し、問題なければ「請求内容確認済み（正式発行）」に更新してください。生徒の支払い後は「支払確認済み」に更新します。各更新時に LINE 通知が届きます。"
      />
      <div class="month-nav">
        <button class="mini" @click="moveMonth(-1)">‹ 前月</button>
        <b>{{ ymLabel }}</b>
        <button class="mini" @click="moveMonth(1)">翌月 ›</button>
      </div>
      <span v-if="st" class="ichip" :class="cur!.status">{{ st.label }}</span>
      <span v-else-if="!loading" class="ichip open">未登録</span>
    </div>

    <div class="grid">
      <!-- 稼働時間（閲覧のみ） -->
      <div class="card">
        <div class="sec-t">稼働時間（{{ ymLabel }}）<span style="font-weight: 400; color: var(--faint); font-size: 11.5px">　※登録・編集は生徒側で行われます</span></div>

        <div v-if="cur?.entries?.length" class="entries">
          <div v-for="e in cur.entries" :key="e.id" class="erow">
            <span class="e-date">{{ workDateLabel(e.workOn) }}</span>
            <span class="e-time">{{ minToTime(e.startMin) }} 〜 {{ minToTime(e.endMin) }}</span>
            <span class="e-hours">{{ hoursLabel(e.minutes) }}h</span>
            <span class="e-note">{{ e.note }}</span>
          </div>
        </div>
        <div v-else class="hint-line">この月の稼働時間はまだ登録されていません。</div>

        <div v-if="cur" class="totals">
          <span>合計 <b>{{ hoursLabel(cur.totalMinutes) }}</b> 時間</span>
          <span>時給 {{ yen(cur.hourlyRate) }}</span>
          <span class="amount">合計金額 <b>{{ yen(cur.amount) }}</b></span>
        </div>
      </div>

      <!-- ステータス・操作 -->
      <div class="side">
        <div class="card">
          <div class="sec-t">請求書の発行フロー</div>
          <ol class="flow">
            <li :class="{ done: cur && cur.status !== 'open' }">締め（生徒）</li>
            <li :class="{ done: cur && ['issued', 'confirmed', 'paid', 'done'].includes(cur.status) }">仮発行（生徒）</li>
            <li :class="{ done: cur && ['confirmed', 'paid', 'done'].includes(cur.status) }">請求内容確認＝正式発行（あなた）</li>
            <li :class="{ done: cur && ['paid', 'done'].includes(cur.status) }">支払済み（生徒）</li>
            <li :class="{ done: cur && cur.status === 'done' }">支払確認済み（あなた）</li>
          </ol>

          <div class="actions">
            <button v-if="cur && cur.status === 'issued'" class="btn primary" :disabled="busy" @click="doAction('confirm', `${ymLabel}分（${yen(cur.amount)}）の請求内容を確認済みにして正式発行しますか？`)">請求内容確認済みにする（正式発行）</button>
            <button v-if="cur && cur.status === 'paid'" class="btn primary" :disabled="busy" @click="doAction('confirm-payment', '支払いを確認済みにしますか？')">支払確認済みにする</button>
            <button v-if="cur && cur.status !== 'open'" class="btn" :disabled="busy" @click="openPdf">請求書PDF</button>
          </div>
          <div v-if="cur && cur.status === 'open'" class="hint-line">生徒側で稼働時間を入力中です。</div>
          <div v-if="cur && cur.status === 'closed'" class="hint-line">生徒側の仮発行を待っています。</div>
          <div v-if="cur && cur.status === 'confirmed'" class="hint-line">生徒の支払いを待っています。</div>
        </div>

        <!-- 請求書一覧 -->
        <div class="card">
          <div class="sec-t">請求書一覧</div>
          <div v-if="!list.length" class="hint-line">まだ請求書はありません。</div>
          <div v-for="i in list" :key="i.id" class="hrow" :class="{ cur: i.year === ym.y && i.month === ym.m }" @click="ym.y = i.year; ym.m = i.month">
            <span style="font-weight: 600">{{ i.year }}年{{ i.month }}月</span>
            <span style="color: var(--faint)">{{ hoursLabel(i.totalMinutes) }}h・{{ yen(i.amount) }}</span>
            <span class="ichip sm" :class="i.status">{{ INVOICE_STATUS[i.status].label }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.month-nav {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-left: 8px;
  font-size: 14px;
}
.grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 360px;
  gap: 14px;
  align-items: start;
}
@media (max-width: 1000px) {
  .grid {
    grid-template-columns: 1fr;
  }
}
.card {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 14px 16px;
}
.side {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.sec-t {
  font-size: 13px;
  font-weight: 700;
  margin-bottom: 10px;
}
.btn {
  padding: 9px 14px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  font-size: 12.5px;
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
.mini {
  padding: 5px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
  white-space: nowrap;
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
.amount {
  margin-left: auto;
}
.flow {
  margin: 0 0 12px;
  padding-left: 22px;
  font-size: 12.5px;
  color: var(--faint);
  display: flex;
  flex-direction: column;
  gap: 5px;
}
.flow li.done {
  color: #2f7a4f;
  font-weight: 600;
}
.actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.hint-line {
  font-size: 12px;
  color: var(--faint);
  margin-top: 8px;
  line-height: 1.7;
}
.hrow {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 9px;
  font-size: 12.5px;
  cursor: pointer;
  flex-wrap: wrap;
}
.hrow:hover {
  background: #f6f7f9;
}
.hrow.cur {
  background: #eef1fc;
}
.hrow .ichip {
  margin-left: auto;
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
</style>
