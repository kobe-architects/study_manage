<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import HelpTip from '@/components/HelpTip.vue'
import client from '@/api/client'
import { INVOICE_STATUS, hoursLabel, invoiceApi, minToTime, workDateLabel, yen } from '@/api/invoice'
import { renderInvoiceSheet } from '@/lib/invoiceSheet'
import { useUiStore } from '@/stores/ui'
import type { InvoiceDetail, InvoiceSummary, TutorAccount } from '@/types'

/**
 * 生徒用: 講師請求管理。
 * 講師の稼働時間（日付・開始〜終了 30分刻み）を月ごとに登録し、
 * 締め → 仮発行 → 講師の内容確認（正式発行）→ 支払済み更新 → 講師の支払確認 の流れで管理する。
 */
const ui = useUiStore()

const tutors = ref<TutorAccount[]>([])
const tutorSel = ref<number | null>(null)
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

async function loadAll() {
  try {
    const [t, l] = await Promise.all([client.get('/tutors'), invoiceApi.list()])
    tutors.value = t.data.data
    list.value = l
    if (tutorSel.value === null && tutors.value.length) tutorSel.value = tutors.value[0]!.id
  } catch {
    ui.notify('請求データの取得に失敗しました')
  } finally {
    loading.value = false
  }
}

async function loadCurrent() {
  const found = list.value.find((i) => i.year === ym.y && i.month === ym.m && i.tutorId === tutorSel.value)
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
  await loadAll()
  await loadCurrent()
})
watch([ym, tutorSel], loadCurrent)

function applyDetail(d: InvoiceDetail) {
  cur.value = d
  const i = list.value.findIndex((x) => x.id === d.id)
  if (i >= 0) list.value[i] = d
  else list.value = [d, ...list.value]
}

// ---- 稼働時間の登録 ----
const defaultDate = () => {
  const t = new Date()
  if (t.getFullYear() === ym.y && t.getMonth() + 1 === ym.m) return isoDate(t)
  return `${ym.y}-${String(ym.m).padStart(2, '0')}-01`
}
function isoDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const form = reactive({ workOn: defaultDate(), startMin: 18 * 60, endMin: 19 * 60 + 30, note: '' })
watch(ym, () => (form.workOn = defaultDate()))

const startOpts = Array.from({ length: 48 }, (_, i) => i * 30)
const endOpts = computed(() => Array.from({ length: 48 }, (_, i) => (i + 1) * 30).filter((v) => v > form.startMin))
watch(
  () => form.startMin,
  (s) => {
    if (form.endMin <= s) form.endMin = Math.min(1440, s + 90)
  },
)

const editable = computed(() => !cur.value || cur.value.status === 'open')

async function addEntry() {
  if (!form.workOn || tutorSel.value === null) return
  const [fy, fm] = form.workOn.split('-').map(Number)
  if (fy !== ym.y || fm !== ym.m) {
    ui.notify(`${ymLabel.value}の日付を指定してください`)
    return
  }
  busy.value = true
  try {
    applyDetail(
      await invoiceApi.addEntry({
        tutorId: tutorSel.value,
        workOn: form.workOn,
        startMin: form.startMin,
        endMin: form.endMin,
        note: form.note.trim() || null,
      }),
    )
    form.note = ''
    ui.notify('稼働時間を登録しました')
  } catch (e: unknown) {
    ui.notify(errMsg(e, '登録に失敗しました'))
  } finally {
    busy.value = false
  }
}

async function removeEntry(id: number) {
  if (!cur.value) return
  try {
    await invoiceApi.removeEntry(id)
    applyDetail(await invoiceApi.show(cur.value.id))
  } catch (e: unknown) {
    ui.notify(errMsg(e, '削除に失敗しました'))
  }
}

// ---- 時給 ----
const rateEdit = ref<number | null>(null)
watch(cur, (c) => (rateEdit.value = c?.hourlyRate ?? null))
async function saveRate() {
  if (!cur.value || rateEdit.value === null || rateEdit.value === cur.value.hourlyRate) return
  try {
    applyDetail(await invoiceApi.update(cur.value.id, { hourlyRate: Math.max(0, Math.round(Number(rateEdit.value) || 0)) }))
    ui.notify('時給を更新しました')
  } catch (e: unknown) {
    ui.notify(errMsg(e, '更新に失敗しました'))
  }
}

// ---- ステータス操作 ----
async function doAction(act: 'close' | 'reopen' | 'issue' | 'pay', confirmText?: string) {
  if (!cur.value) return
  if (confirmText && !confirm(confirmText)) return
  busy.value = true
  try {
    applyDetail(await invoiceApi.action(cur.value.id, act))
    ui.notify(
      act === 'close' ? '締めました。「請求書を仮発行」で講師へ確認依頼できます'
      : act === 'reopen' ? '締めを解除しました'
      : act === 'issue' ? '請求書を仮発行しました。講師の内容確認をお待ちください'
      : '支払済みに更新しました。講師の確認をお待ちください',
    )
  } catch (e: unknown) {
    ui.notify(errMsg(e, '処理に失敗しました'))
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

function errMsg(e: unknown, fallback: string): string {
  return (e as { response?: { data?: { message?: string } } })?.response?.data?.message || fallback
}

function jumpTo(i: InvoiceSummary) {
  tutorSel.value = i.tutorId
  ym.y = i.year
  ym.m = i.month
}

const st = computed(() => (cur.value ? INVOICE_STATUS[cur.value.status] : null))
</script>

<template>
  <div>
    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 14px; flex-wrap: wrap">
      <div style="font-size: 17px; font-weight: 700">講師請求管理</div>
      <HelpTip
        text="講師の稼働時間（日付と開始〜終了・30分刻み）を月ごとに登録し、請求書を管理します。&#10;流れ: 稼働時間を登録 → 締め → 請求書を仮発行 → 講師が内容確認（正式発行）→ 支払い後「支払済み」に更新 → 講師が支払確認。各更新時に LINE 通知が届きます。"
      />
      <select v-if="tutors.length > 1" v-model.number="tutorSel" class="tutor-sel">
        <option v-for="t in tutors" :key="t.id" :value="t.id">{{ t.name }}</option>
      </select>
      <span v-else-if="tutors.length === 1" style="font-size: 13px; font-weight: 600">{{ tutors[0]!.name }}</span>
      <div class="month-nav">
        <button class="mini" @click="moveMonth(-1)">‹ 前月</button>
        <b>{{ ymLabel }}</b>
        <button class="mini" @click="moveMonth(1)">翌月 ›</button>
      </div>
      <span v-if="st" class="ichip" :class="cur!.status">{{ st.label }}</span>
      <span v-else-if="!loading" class="ichip open">未登録</span>
    </div>

    <div v-if="!loading && !tutors.length" class="hint-line" style="margin-bottom: 12px">
      家庭教師アカウントがありません。設定画面の「家庭教師アカウント」から追加してください。
    </div>

    <div class="grid">
      <!-- 稼働時間 -->
      <div class="card">
        <div class="sec-t">稼働時間（{{ ymLabel }}）</div>

        <div v-if="editable && tutorSel !== null" class="entry-form">
          <label class="fld"><span>日付</span><input v-model="form.workOn" type="date" /></label>
          <label class="fld"><span>開始</span>
            <select v-model.number="form.startMin"><option v-for="t in startOpts" :key="t" :value="t">{{ minToTime(t) }}</option></select>
          </label>
          <label class="fld"><span>終了</span>
            <select v-model.number="form.endMin"><option v-for="t in endOpts" :key="t" :value="t">{{ minToTime(t) }}</option></select>
          </label>
          <label class="fld" style="flex: 1; min-width: 120px"><span>備考（任意）</span><input v-model="form.note" maxlength="50" /></label>
          <button class="btn primary" :disabled="busy" @click="addEntry">登録</button>
        </div>
        <div v-else-if="cur" class="hint-line">締め済みのため稼働時間は編集できません（編集する場合は締め解除してください）。</div>

        <div v-if="cur?.entries?.length" class="entries">
          <div v-for="e in cur.entries" :key="e.id" class="erow">
            <span class="e-date">{{ workDateLabel(e.workOn) }}</span>
            <span class="e-time">{{ minToTime(e.startMin) }} 〜 {{ minToTime(e.endMin) }}</span>
            <span class="e-hours">{{ hoursLabel(e.minutes) }}h</span>
            <span class="e-note">{{ e.note }}</span>
            <button v-if="editable" class="mini danger" @click="removeEntry(e.id)">削除</button>
          </div>
        </div>
        <div v-else class="hint-line" style="margin-top: 10px">この月の稼働時間はまだ登録されていません。</div>

        <div v-if="cur" class="totals">
          <span>合計 <b>{{ hoursLabel(cur.totalMinutes) }}</b> 時間</span>
          <span class="rate">
            時給
            <input v-model.number="rateEdit" type="number" min="0" step="100" :disabled="!(cur.status === 'open' || cur.status === 'closed')" @change="saveRate" />
            円
          </span>
          <span class="amount">合計金額 <b>{{ yen(cur.amount) }}</b></span>
        </div>
      </div>

      <!-- ステータス・操作 -->
      <div class="side">
        <div class="card">
          <div class="sec-t">請求書の発行フロー</div>
          <ol class="flow">
            <li :class="{ done: cur && cur.status !== 'open' }">締め（あなた）</li>
            <li :class="{ done: cur && ['issued', 'confirmed', 'paid', 'done'].includes(cur.status) }">請求書を仮発行（あなた）</li>
            <li :class="{ done: cur && ['confirmed', 'paid', 'done'].includes(cur.status) }">請求内容確認＝正式発行（講師）</li>
            <li :class="{ done: cur && ['paid', 'done'].includes(cur.status) }">支払済み（あなた）</li>
            <li :class="{ done: cur && cur.status === 'done' }">支払確認済み（講師）</li>
          </ol>

          <div class="actions">
            <button v-if="cur && cur.status === 'open'" class="btn primary" :disabled="busy || !cur.entryCount" @click="doAction('close', `${ymLabel}分を締めますか？\n締めると稼働時間の編集ができなくなり、請求書を仮発行できるようになります。`)">締め（入力を確定）</button>
            <button v-if="cur && cur.status === 'closed'" class="btn primary" :disabled="busy" @click="doAction('issue', `${ymLabel}分（${yen(cur.amount)}）の請求書を仮発行しますか？\n講師へ通知され、内容確認後に正式発行となります。`)">請求書を仮発行</button>
            <button v-if="cur && cur.status === 'closed'" class="btn" :disabled="busy" @click="doAction('reopen')">締め解除</button>
            <button v-if="cur && cur.status === 'confirmed'" class="btn primary" :disabled="busy" @click="doAction('pay', `${ymLabel}分（${yen(cur.amount)}）を支払済みにしますか？`)">支払済みにする</button>
            <button v-if="cur && cur.status !== 'open'" class="btn" :disabled="busy" @click="openPdf">請求書PDF</button>
          </div>
          <div v-if="cur && cur.status === 'issued'" class="hint-line">講師の請求内容確認（正式発行）を待っています。</div>
          <div v-if="cur && cur.status === 'paid'" class="hint-line">講師の支払確認を待っています。</div>
        </div>

        <!-- 請求書一覧 -->
        <div class="card">
          <div class="sec-t">請求書一覧</div>
          <div v-if="!list.length" class="hint-line">まだ請求書はありません。</div>
          <div v-for="i in list" :key="i.id" class="hrow" :class="{ cur: cur && i.id === cur.id }" @click="jumpTo(i)">
            <span style="font-weight: 600">{{ i.year }}年{{ i.month }}月<template v-if="tutors.length > 1">・{{ i.tutorName }}</template></span>
            <span style="color: var(--faint)">{{ hoursLabel(i.totalMinutes) }}h・{{ yen(i.amount) }}</span>
            <span class="ichip sm" :class="i.status">{{ INVOICE_STATUS[i.status].label }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.tutor-sel {
  padding: 7px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  font-size: 12.5px;
  background: #fff;
}
.month-nav {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-left: 4px;
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
.entry-form {
  display: flex;
  gap: 10px;
  align-items: flex-end;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.fld span {
  display: block;
  font-size: 11px;
  color: var(--mut);
  margin-bottom: 4px;
}
.fld input,
.fld select {
  padding: 8px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  font-size: 13px;
  background: #fff;
  width: 100%;
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
.mini.danger {
  color: #c0444f;
  border-color: #f0b8be;
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
.rate input {
  width: 90px;
  padding: 6px 8px;
  border: 1px solid #e3e6ea;
  border-radius: 7px;
  font-size: 13px;
  text-align: right;
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
