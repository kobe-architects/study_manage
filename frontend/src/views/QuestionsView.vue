<script setup lang="ts">
/**
 * 質問事項（生徒 ⇄ 講師）。Slack のようにスレッド（質問）を立て、スレッドごとにメッセージをやりとりする。
 * - 左: スレッドの表（未読は太字＋青い丸と件数。ステータス 質問中／解決済み）。右: 選んだスレッドのメッセージと入力欄
 *   スマホ・iPad 縦は 表 → スレッドの 2 画面で切り替える
 * - 新しい質問: タイトル・科目・大分類・中分類は任意。本文か添付があれば立てられる
 * - ステータスの変更は生徒だけ（canChangeStatus）。タイトル・分類はどちらでも直せる
 * - メッセージ本文は簡易タグで装飾（components/QuestionComposer.vue で入力、components/RichText.vue で表示）
 * - 添付の画像はタップで拡大、「書き込んで返信」でその画像に図形・手書きを入れて返信できる。ファイルは保存（共有シート）
 * - 20 秒ごとに一覧（と開いているスレッド）を取り直す。開いたスレッドは既読になりナビのバッジが減る
 * 生徒側（/questions）と講師側（/tutor/questions）で同じ画面。API のプレフィックスはロールで切り替わる
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import ActionMenu, { type ActionMenuItem } from '@/components/ActionMenu.vue'
import AuthImage from '@/components/AuthImage.vue'
import HelpTip from '@/components/HelpTip.vue'
import ImageMarkupModal from '@/components/ImageMarkupModal.vue'
import QuestionComposer from '@/components/QuestionComposer.vue'
import RichText from '@/components/RichText.vue'
import client from '@/api/client'
import { questionsApi, type PendingAttachment } from '@/api/questions'
import { appConfirm } from '@/lib/dialog'
import { saveFile, viewportWidth } from '@/lib/native'
import { useAuthStore } from '@/stores/auth'
import { useQuestionStore } from '@/stores/questions'
import { useUiStore } from '@/stores/ui'
import type { QuestionAttachment, QuestionMessage, QuestionStatus, QuestionThread, QuestionThreadDetail, StudyItemRow } from '@/types'

const ui = useUiStore()
const auth = useAuthStore()
const qstore = useQuestionStore()
const isMobile = computed(() => viewportWidth.value < 860)
const myId = computed(() => auth.user?.id ?? 0)

// ---- 一覧 ----
const threads = ref<QuestionThread[]>([])
const loading = ref(true)
const filter = ref<'all' | QuestionStatus>('all')
const shown = computed(() => threads.value.filter((t) => filter.value === 'all' || t.status === filter.value))
const openCount = computed(() => threads.value.filter((t) => t.status === 'open').length)

async function loadList() {
  try {
    const r = await questionsApi.list()
    threads.value = r.threads
    qstore.setUnread(r.unreadThreads, r.threads.reduce((s, t) => s + t.unreadCount, 0))
  } catch {
    ui.notify('質問の一覧を取得できませんでした')
  } finally {
    loading.value = false
  }
}

// ---- 分類の候補（学習項目データ） ----
const items = ref<StudyItemRow[]>([])
async function loadItems() {
  try {
    const { data } = await client.get(`${questionsApi.prefix()}/study-items`)
    items.value = data.data
  } catch {
    items.value = []
  }
}
const subjects = computed(() => {
  const m = new Map<number, { id: number; name: string }>()
  for (const it of items.value) if (!m.has(it.subjectId)) m.set(it.subjectId, { id: it.subjectId, name: it.subjectName })
  return [...m.values()]
})
function majorsOf(subjectId: number) {
  const m = new Map<number, { id: number; name: string }>()
  for (const it of items.value) if (it.subjectId === subjectId && !m.has(it.majorId)) m.set(it.majorId, { id: it.majorId, name: it.major })
  return [...m.values()]
}
function midsOf(majorId: number) {
  const m = new Map<number, { id: number; name: string }>()
  for (const it of items.value) if (it.majorId === majorId && !m.has(it.midId)) m.set(it.midId, { id: it.midId, name: it.mid })
  return [...m.values()]
}

// ---- スレッド（詳細） ----
const selectedId = ref<number | null>(null)
const detail = ref<QuestionThreadDetail | null>(null)
const detailLoading = ref(false)
const composing = ref(false) // 新しい質問
const msgList = ref<HTMLElement | null>(null)
const composer = ref<InstanceType<typeof QuestionComposer> | null>(null)
const newComposer = ref<InstanceType<typeof QuestionComposer> | null>(null)
const sending = ref(false)
const progress = ref<number | null>(null)

async function openThread(id: number) {
  composing.value = false
  selectedId.value = id
  detailLoading.value = true
  try {
    const d = await questionsApi.show(id)
    if (selectedId.value !== id) return
    detail.value = d
    const t = threads.value.find((x) => x.id === id)
    if (t) t.unreadCount = 0
    qstore.setUnread(threads.value.filter((x) => x.unreadCount > 0).length, threads.value.reduce((s, x) => s + x.unreadCount, 0))
    await nextTick()
    scrollToBottom()
  } catch {
    ui.notify('スレッドを開けませんでした')
    selectedId.value = null
  } finally {
    detailLoading.value = false
  }
}
function closeThread() {
  selectedId.value = null
  detail.value = null
  composing.value = false
}
function scrollToBottom() {
  const el = msgList.value
  if (el) el.scrollTop = el.scrollHeight
}
const threadTitle = computed(() => (detail.value ? detail.value.title || detail.value.lastMessage?.excerpt || '（無題）' : ''))

// ---- 新しい質問 ----
const draft = reactive<{ title: string; subjectId: number; majorId: number; midId: number }>({ title: '', subjectId: 0, majorId: 0, midId: 0 })
function startNew() {
  selectedId.value = null
  detail.value = null
  composing.value = true
  draft.title = ''
  draft.subjectId = 0
  draft.majorId = 0
  draft.midId = 0
  nextTick(() => newComposer.value?.focus())
}
watch(
  () => draft.subjectId,
  () => {
    if (!majorsOf(draft.subjectId).some((m) => m.id === draft.majorId)) draft.majorId = 0
  },
)
watch(
  () => draft.majorId,
  () => {
    if (!midsOf(draft.majorId).some((m) => m.id === draft.midId)) draft.midId = 0
  },
)

async function uploadAll(att: PendingAttachment[]) {
  progress.value = att.length ? 0 : null
  return questionsApi.prepareAttachments(att, (done, total) => (progress.value = done / total))
}
async function submitNew(p: { body: string; attachments: PendingAttachment[]; notifyLine: boolean }) {
  if (sending.value) return
  sending.value = true
  try {
    const attachments = await uploadAll(p.attachments)
    const d = await questionsApi.createThread({
      title: draft.title.trim() || null,
      subjectId: draft.subjectId || null,
      majorId: draft.majorId || null,
      midId: draft.midId || null,
      body: p.body,
      notifyLine: p.notifyLine,
      attachments,
    })
    newComposer.value?.reset()
    composing.value = false
    await loadList()
    selectedId.value = d.id
    detail.value = d
    await nextTick()
    scrollToBottom()
    ui.notify(p.notifyLine && d.messages[0]?.lineNotified ? '質問を送り、LINE に通知しました' : '質問を送りました', { center: true })
  } catch (e: unknown) {
    ui.notify(errorMessage(e, '送信に失敗しました'), { center: true })
  } finally {
    sending.value = false
    progress.value = null
  }
}
async function submitReply(p: { body: string; attachments: PendingAttachment[]; notifyLine: boolean }) {
  const d = detail.value
  if (!d || sending.value) return
  sending.value = true
  try {
    const attachments = await uploadAll(p.attachments)
    const m = await questionsApi.sendMessage(d.id, { body: p.body, notifyLine: p.notifyLine, attachments })
    composer.value?.reset()
    if (detail.value?.id === d.id) {
      detail.value.messages.push(m)
      detail.value.messageCount = detail.value.messages.length
      detail.value.lastMessageAt = m.createdAt
    }
    const t = threads.value.find((x) => x.id === d.id)
    if (t) {
      t.messageCount++
      t.lastMessageAt = m.createdAt
      t.lastMessage = { excerpt: m.body.replace(/\[\/?(?:b|s|u|m|color)(?:=[^\]]*)?\]/g, '').slice(0, 80), userName: m.userName, userId: m.userId, hasAttachment: m.attachments.length > 0 }
      threads.value = [t, ...threads.value.filter((x) => x.id !== t.id)]
    }
    await nextTick()
    scrollToBottom()
    if (p.notifyLine && !m.lineNotified) ui.notify('送信しました（相手が LINE と連携していないため通知はされていません）', { center: true })
  } catch (e: unknown) {
    ui.notify(errorMessage(e, '送信に失敗しました'), { center: true })
  } finally {
    sending.value = false
    progress.value = null
  }
}
function errorMessage(e: unknown, fallback: string): string {
  const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
  return msg && !/^The /.test(msg) ? msg : fallback
}

// ---- ステータス・タイトル・分類・削除 ----
async function toggleStatus() {
  const d = detail.value
  if (!d || !d.canChangeStatus) return
  const next: QuestionStatus = d.status === 'open' ? 'resolved' : 'open'
  try {
    const t = await questionsApi.updateThread(d.id, { status: next })
    applyThread(t)
    ui.notify(next === 'resolved' ? '解決済みにしました' : '質問中に戻しました', { center: true })
  } catch {
    ui.notify('更新に失敗しました')
  }
}
function applyThread(t: QuestionThread) {
  const i = threads.value.findIndex((x) => x.id === t.id)
  if (i >= 0) threads.value.splice(i, 1, { ...threads.value[i]!, ...t, unreadCount: threads.value[i]!.unreadCount })
  if (detail.value?.id === t.id) detail.value = { ...detail.value, ...t, unreadCount: 0 }
}
const editMeta = ref(false)
const meta = reactive<{ title: string; subjectId: number; majorId: number; midId: number }>({ title: '', subjectId: 0, majorId: 0, midId: 0 })
function startEditMeta() {
  const d = detail.value
  if (!d) return
  meta.title = d.title ?? ''
  meta.subjectId = d.subjectId ?? 0
  meta.majorId = d.majorId ?? 0
  meta.midId = d.midId ?? 0
  editMeta.value = true
}
watch(
  () => meta.subjectId,
  () => {
    if (!majorsOf(meta.subjectId).some((m) => m.id === meta.majorId)) meta.majorId = 0
  },
)
watch(
  () => meta.majorId,
  () => {
    if (!midsOf(meta.majorId).some((m) => m.id === meta.midId)) meta.midId = 0
  },
)
async function saveMeta() {
  const d = detail.value
  if (!d) return
  try {
    const t = await questionsApi.updateThread(d.id, { title: meta.title.trim() || null, subjectId: meta.subjectId || null, majorId: meta.majorId || null, midId: meta.midId || null })
    applyThread(t)
    editMeta.value = false
  } catch {
    ui.notify('更新に失敗しました')
  }
}
const threadMenu = computed<ActionMenuItem[]>(() => {
  const d = detail.value
  const out: ActionMenuItem[] = [{ key: 'meta', label: 'タイトル・分類を編集' }]
  if (d?.canDelete) out.push({ key: 'remove', label: 'スレッドを削除', danger: true })
  return out
})
async function onThreadMenu(key: string) {
  if (key === 'meta') startEditMeta()
  else if (key === 'remove') await removeThread()
}
async function removeThread() {
  const d = detail.value
  if (!d) return
  if (!(await appConfirm(`「${threadTitle.value}」を削除しますか？\nメッセージと添付もすべて消えます。`, { danger: true, okText: '削除' }))) return
  try {
    await questionsApi.removeThread(d.id)
    threads.value = threads.value.filter((x) => x.id !== d.id)
    closeThread()
    ui.notify('削除しました')
  } catch {
    ui.notify('削除に失敗しました')
  }
}
async function removeMessage(m: QuestionMessage) {
  if (!(await appConfirm('このメッセージを削除しますか？', { danger: true, okText: '削除' }))) return
  try {
    await questionsApi.removeMessage(m.id)
    if (detail.value) {
      detail.value.messages = detail.value.messages.filter((x) => x.id !== m.id)
      detail.value.messageCount = detail.value.messages.length
    }
    const t = threads.value.find((x) => x.id === m.threadId)
    if (t) t.messageCount = Math.max(0, t.messageCount - 1)
  } catch {
    ui.notify('削除に失敗しました')
  }
}

// ---- 添付の表示・保存・書き込んで返信 ----
const lightbox = ref<QuestionAttachment | null>(null)
const markup = ref<{ blob: Blob; name: string } | null>(null)
async function downloadAttachment(a: QuestionAttachment) {
  try {
    await saveFile(await questionsApi.fetchAttachment(a.id), a.name, { preferShare: true })
  } catch {
    ui.notify('ダウンロードに失敗しました')
  }
}
async function markupAndReply(a: QuestionAttachment) {
  try {
    const blob = await questionsApi.fetchAttachment(a.id)
    lightbox.value = null
    markup.value = { blob, name: a.name }
  } catch {
    ui.notify('画像を取得できませんでした')
  }
}
function onMarkupSaved(blob: Blob) {
  const name = (markup.value?.name ?? 'image').replace(/\.[A-Za-z0-9]+$/, '') + '.jpg'
  markup.value = null
  composer.value?.addFiles([new File([blob], name, { type: 'image/jpeg' })])
  nextTick(() => composer.value?.focus())
}
function onPaneDrop(e: DragEvent) {
  const files = Array.from(e.dataTransfer?.files ?? [])
  if (!files.length) return
  ;(composing.value ? newComposer.value : composer.value)?.addFiles(files)
}

// ---- 表示用 ----
function fmtWhen(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso.replace(' ', 'T'))
  const now = new Date()
  const hm = `${d.getHours()}:${String(d.getMinutes()).padStart(2, '0')}`
  if (d.toDateString() === now.toDateString()) return hm
  const y = new Date(now)
  y.setDate(now.getDate() - 1)
  if (d.toDateString() === y.toDateString()) return `昨日 ${hm}`
  const md = `${d.getMonth() + 1}/${d.getDate()}`
  return d.getFullYear() === now.getFullYear() ? `${md} ${hm}` : `${d.getFullYear()}/${md}`
}
function category(t: QuestionThread): string {
  return [t.subjectName, t.majorName, t.midName].filter(Boolean).join(' › ')
}
function initial(name: string | null): string {
  return (name ?? '?').trim().charAt(0) || '?'
}
function fmtSize(n: number): string {
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`
  return `${(n / 1024 / 1024).toFixed(1)} MB`
}
/** 日付の区切り（メッセージの日が変わるところ） */
function dayLabel(m: QuestionMessage, i: number, list: QuestionMessage[]): string | null {
  const cur = (m.createdAt ?? '').slice(0, 10)
  const prev = i > 0 ? (list[i - 1]!.createdAt ?? '').slice(0, 10) : null
  if (cur === prev) return null
  const d = new Date(cur + 'T00:00:00')
  const today = new Date().toISOString().slice(0, 10)
  if (cur === today) return '今日'
  return `${d.getFullYear()}年${d.getMonth() + 1}月${d.getDate()}日`
}

// ---- 定期更新 ----
let timer: number | null = null
async function poll() {
  if (document.visibilityState !== 'visible' || sending.value) return
  const before = detail.value ? detail.value.messageCount : null
  await loadList()
  const id = selectedId.value
  if (id && detail.value && !editMeta.value) {
    const t = threads.value.find((x) => x.id === id)
    if (t && (t.messageCount !== before || t.unreadCount > 0)) {
      try {
        const d = await questionsApi.show(id)
        if (selectedId.value === id) {
          const atBottom = msgList.value ? msgList.value.scrollHeight - msgList.value.scrollTop - msgList.value.clientHeight < 80 : true
          detail.value = d
          t.unreadCount = 0
          qstore.setUnread(threads.value.filter((x) => x.unreadCount > 0).length)
          if (atBottom) nextTick(scrollToBottom)
        }
      } catch {
        // 次回に任せる
      }
    }
  }
}
onMounted(() => {
  loadList()
  loadItems()
  timer = window.setInterval(poll, 20000)
})
onBeforeUnmount(() => {
  if (timer) window.clearInterval(timer)
})
</script>

<template>
  <div class="qv" :class="{ mobile: isMobile, 'has-thread': !!(selectedId || composing) }">
    <!-- 左: スレッドの表 -->
    <section v-if="!isMobile || (!selectedId && !composing)" class="list card">
      <div class="list-head">
        <div class="title">
          質問事項
          <HelpTip text="先生への質問をスレッドとして立て、スレッドごとにメッセージをやりとりします。&#10;タイトル・科目・分類は任意です。未読のスレッドは太字と青い丸で分かります。&#10;ステータス（質問中／解決済み）は生徒が変えます。" />
        </div>
        <div v-seg class="seg">
          <button class="seg-btn" :class="{ on: filter === 'all' }" @click="filter = 'all'">すべて</button>
          <button class="seg-btn" :class="{ on: filter === 'open' }" @click="filter = 'open'">質問中<span v-if="openCount" class="cnt">{{ openCount }}</span></button>
          <button class="seg-btn" :class="{ on: filter === 'resolved' }" @click="filter = 'resolved'">解決済み</button>
        </div>
        <button class="new-btn" @click="startNew">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
          新しい質問
        </button>
      </div>

      <div v-if="loading" class="empty">読み込み中…</div>
      <div v-else-if="!shown.length" class="empty">{{ threads.length ? '該当する質問はありません' : 'まだ質問はありません。「新しい質問」から立てられます' }}</div>
      <table v-else class="tbl">
        <thead>
          <tr>
            <th class="c-dot"></th>
            <th>質問</th>
            <th class="c-cat">科目・分類</th>
            <th class="c-st">ステータス</th>
            <th class="c-n">件数</th>
            <th class="c-when">更新</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in shown" :key="t.id" class="row" :class="{ unread: t.unreadCount > 0, sel: t.id === selectedId, resolved: t.status === 'resolved' }" @click="openThread(t.id)">
            <td class="c-dot"><span v-if="t.unreadCount > 0" class="dot" :title="`未読 ${t.unreadCount} 件`"></span></td>
            <td class="c-main">
              <div class="t-title">
                <span class="t-text">{{ t.title || t.lastMessage?.excerpt || '（無題）' }}</span>
                <span v-if="t.unreadCount > 0" class="badge">{{ t.unreadCount }}</span>
              </div>
              <div v-if="t.lastMessage" class="t-sub">
                <span class="t-who">{{ t.lastMessage.userName }}</span>
                <span class="t-ex">{{ t.title ? t.lastMessage.excerpt : '' }}</span>
                <svg v-if="t.lastMessage.hasAttachment" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.4 11.1l-8.5 8.5a5.5 5.5 0 0 1-7.8-7.8l8.8-8.8a3.5 3.5 0 0 1 5 5l-8.9 8.8a1.5 1.5 0 0 1-2.1-2.1l8.2-8.1" /></svg>
              </div>
              <div v-if="isMobile" class="t-meta">
                <span v-if="category(t)" class="cat">{{ category(t) }}</span>
                <span class="st" :class="t.status">{{ t.status === 'open' ? '質問中' : '解決済み' }}</span>
                <span class="when">{{ fmtWhen(t.lastMessageAt) }}</span>
              </div>
            </td>
            <td class="c-cat"><span v-if="category(t)" class="cat">{{ category(t) }}</span><span v-else class="none">—</span></td>
            <td class="c-st"><span class="st" :class="t.status">{{ t.status === 'open' ? '質問中' : '解決済み' }}</span></td>
            <td class="c-n">{{ t.messageCount }}</td>
            <td class="c-when">{{ fmtWhen(t.lastMessageAt) }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- 右: スレッド / 新しい質問 -->
    <section v-if="selectedId || composing" class="thread card" @dragover.prevent @drop.prevent="onPaneDrop">
      <!-- 新しい質問 -->
      <template v-if="composing">
        <div class="th-head">
          <button v-if="isMobile" class="back" @click="closeThread">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7" /></svg>
            一覧
          </button>
          <div class="th-title">新しい質問</div>
          <button v-if="!isMobile" class="x" title="閉じる" @click="closeThread">×</button>
        </div>
        <div class="new-form">
          <input v-model="draft.title" class="inp" type="text" maxlength="200" placeholder="タイトル（任意。空ならメッセージの先頭が見出しになります）" />
          <div class="cat-row">
            <select v-model.number="draft.subjectId" class="sel">
              <option :value="0">科目（任意）</option>
              <option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <select v-model.number="draft.majorId" class="sel" :disabled="!draft.subjectId">
              <option :value="0">大分類（任意）</option>
              <option v-for="m in majorsOf(draft.subjectId)" :key="m.id" :value="m.id">{{ m.name }}</option>
            </select>
            <select v-model.number="draft.midId" class="sel" :disabled="!draft.majorId">
              <option :value="0">中分類（任意）</option>
              <option v-for="m in midsOf(draft.majorId)" :key="m.id" :value="m.id">{{ m.name }}</option>
            </select>
          </div>
          <QuestionComposer ref="newComposer" submit-label="質問を送る" placeholder="質問の内容を入力…（画像は Ctrl+V やドラッグ＆ドロップで添付できます）" :busy="sending" :progress="progress" :min-rows="5" @submit="submitNew" />
        </div>
      </template>

      <!-- スレッド -->
      <template v-else-if="detail">
        <div class="th-head">
          <button v-if="isMobile" class="back" @click="closeThread">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7" /></svg>
            一覧
          </button>
          <div class="th-main">
            <div class="th-title" :title="threadTitle">{{ threadTitle }}</div>
            <div class="th-sub">
              <span v-if="category(detail)" class="cat">{{ category(detail) }}</span>
              <span class="who">{{ detail.createdByName }} が {{ fmtWhen(detail.createdAt) }} に作成</span>
            </div>
          </div>
          <button v-if="detail.canChangeStatus" class="st-btn" :class="detail.status" @click="toggleStatus">
            <span class="st-dot"></span>{{ detail.status === 'open' ? '質問中' : '解決済み' }}
            <span class="st-arrow">→ {{ detail.status === 'open' ? '解決済みにする' : '質問中に戻す' }}</span>
          </button>
          <span v-else class="st" :class="detail.status">{{ detail.status === 'open' ? '質問中' : '解決済み' }}</span>
          <ActionMenu :items="threadMenu" @select="onThreadMenu" />
          <button v-if="!isMobile" class="x" title="閉じる" @click="closeThread">×</button>
        </div>

        <div ref="msgList" class="msgs">
          <template v-for="(m, i) in detail.messages" :key="m.id">
            <div v-if="dayLabel(m, i, detail.messages)" class="day"><span>{{ dayLabel(m, i, detail.messages) }}</span></div>
            <div class="msg" :class="{ mine: m.userId === myId, tutor: m.userRole === 'tutor' }">
              <div class="avatar" :class="{ tutor: m.userRole === 'tutor' }">{{ initial(m.userName) }}</div>
              <div class="bubble">
                <div class="m-head">
                  <span class="m-name">{{ m.userName }}<span v-if="m.userRole === 'tutor'" class="role">先生</span></span>
                  <span class="m-when">{{ fmtWhen(m.createdAt) }}</span>
                  <span v-if="m.lineNotified" class="m-line" title="LINE に通知済み">LINE</span>
                  <button v-if="m.userId === myId" class="m-del" title="削除" @click="removeMessage(m)">削除</button>
                </div>
                <RichText v-if="m.body" :text="m.body" class="m-body" />
                <div v-if="m.attachments.length" class="m-atts">
                  <template v-for="a in m.attachments" :key="a.id">
                    <button v-if="a.kind === 'image'" class="m-img" @click="lightbox = a">
                      <AuthImage :src="questionsApi.attachmentUrl(a.id, true)" :alt="a.name" />
                    </button>
                    <button v-else class="m-file" @click="downloadAttachment(a)">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3.5h9l4 4v13H6z" /><path d="M15 3.5v4h4" /></svg>
                      <span class="f-name">{{ a.name }}</span>
                      <span class="f-size">{{ fmtSize(a.sizeBytes) }}</span>
                    </button>
                  </template>
                </div>
              </div>
            </div>
          </template>
          <div v-if="!detail.messages.length" class="empty">メッセージはありません</div>
        </div>

        <div class="th-compose">
          <QuestionComposer ref="composer" :busy="sending" :progress="progress" :min-rows="2" placeholder="返信を入力…" @submit="submitReply" />
        </div>
      </template>
      <div v-else class="empty" style="padding: 40px">{{ detailLoading ? '読み込み中…' : '' }}</div>
    </section>

    <!-- PC: 何も選んでいないときの案内 -->
    <section v-else-if="!isMobile" class="thread card placeholder">
      <div class="ph">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v11H9l-5 4z" /><path d="M8 9.5h8" /><path d="M8 12.5h5" /></svg>
        <div>左の表から質問を選ぶか、「新しい質問」で質問を立てます</div>
      </div>
    </section>

    <!-- タイトル・分類の編集 -->
    <Transition name="ui-modal">
      <div v-if="editMeta" class="ui-overlay">
        <div class="ui-panel meta-panel">
          <div class="mp-title">タイトル・分類を編集</div>
          <input v-model="meta.title" class="inp" type="text" maxlength="200" placeholder="タイトル（任意）" />
          <select v-model.number="meta.subjectId" class="sel">
            <option :value="0">科目（任意）</option>
            <option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
          <select v-model.number="meta.majorId" class="sel" :disabled="!meta.subjectId">
            <option :value="0">大分類（任意）</option>
            <option v-for="m in majorsOf(meta.subjectId)" :key="m.id" :value="m.id">{{ m.name }}</option>
          </select>
          <select v-model.number="meta.midId" class="sel" :disabled="!meta.majorId">
            <option :value="0">中分類（任意）</option>
            <option v-for="m in midsOf(meta.majorId)" :key="m.id" :value="m.id">{{ m.name }}</option>
          </select>
          <div class="mp-row">
            <button class="btn ghost" @click="editMeta = false">キャンセル</button>
            <button class="btn primary" @click="saveMeta">保存</button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- 画像の拡大 -->
    <Transition name="ui-modal">
      <div v-if="lightbox" class="lb ui-fullscreen">
        <div class="lb-top">
          <button class="lb-btn" @click="lightbox = null">閉じる</button>
          <div class="lb-name">{{ lightbox.name }}</div>
          <button class="lb-btn" @click="markupAndReply(lightbox)">書き込んで返信</button>
          <button class="lb-btn" @click="downloadAttachment(lightbox)">保存</button>
        </div>
        <div class="lb-body" @click.self="lightbox = null">
          <AuthImage :src="questionsApi.attachmentUrl(lightbox.id)" :alt="lightbox.name" />
        </div>
      </div>
    </Transition>

    <!-- 受け取った画像に書き込んで返信 -->
    <Transition name="ui-modal">
      <ImageMarkupModal v-if="markup" :blob="markup.blob" :name="markup.name" @close="markup = null" @save="onMarkupSaved" />
    </Transition>
  </div>
</template>

<style scoped>
.qv {
  display: grid;
  grid-template-columns: minmax(360px, 5fr) minmax(380px, 6fr);
  gap: 14px;
  align-items: stretch;
  height: calc(100vh - 118px);
  min-height: 520px;
}
.qv.mobile {
  display: block;
  height: auto;
  min-height: 0;
}
.card {
  display: flex;
  flex-direction: column;
  min-height: 0;
  overflow: hidden;
}
/* ---- 一覧 ---- */
.list-head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  border-bottom: 1px solid var(--line);
  flex-wrap: wrap;
}
.title {
  font-size: 15px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 6px;
}
.seg {
  display: inline-flex;
  position: relative;
  background: #eef0f4;
  border-radius: 10px;
  padding: 3px;
}
.seg-btn {
  position: relative;
  z-index: 1;
  min-height: 28px;
  padding: 0 11px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--mut);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.seg-btn.on {
  color: var(--ink);
}
.cnt {
  margin-left: 4px;
  font-size: 10px;
  color: var(--primary);
}
.new-btn {
  margin-left: auto;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  min-height: 34px;
  padding: 0 13px;
  border: none;
  border-radius: 10px;
  background: var(--primary);
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
}
.empty {
  padding: 34px 16px;
  text-align: center;
  color: var(--faint);
  font-size: 13px;
  line-height: 1.7;
}
.tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.list .tbl {
  display: block;
  overflow: auto;
  flex: 1;
  min-height: 0;
}
.list .tbl thead,
.list .tbl tbody,
.list .tbl tr {
  display: table;
  width: 100%;
  table-layout: fixed;
}
.tbl th {
  text-align: left;
  font-size: 11px;
  font-weight: 600;
  color: var(--faint);
  padding: 8px 10px;
  border-bottom: 1px solid var(--line);
  background: #fafbfc;
  position: sticky;
  top: 0;
}
.tbl td {
  padding: 10px 10px;
  border-bottom: 1px solid var(--line);
  vertical-align: middle;
}
.row {
  cursor: pointer;
}
.row:active {
  background: #f3f5f9;
}
.row.sel {
  background: #eef1fb;
}
.row.resolved .t-text {
  color: var(--mut);
}
.row.unread .t-text {
  font-weight: 700;
}
.row.unread .t-sub {
  color: var(--ink);
}
.c-dot {
  width: 22px;
  padding-right: 0 !important;
}
.dot {
  display: inline-block;
  width: 9px;
  height: 9px;
  border-radius: 50%;
  background: var(--primary);
}
.c-cat {
  width: 24%;
}
.c-st {
  width: 86px;
}
.c-n {
  width: 48px;
  text-align: right;
}
.c-when {
  width: 86px;
  color: var(--faint);
  font-size: 12px;
  white-space: nowrap;
}
.t-title {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.t-text {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
}
.badge {
  flex-shrink: 0;
  font-size: 10px;
  font-weight: 700;
  background: var(--primary);
  color: #fff;
  border-radius: 99px;
  padding: 1px 6px;
}
.t-sub {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 2px;
  font-size: 11.5px;
  color: var(--faint);
  min-width: 0;
}
.t-who {
  flex-shrink: 0;
}
.t-ex {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
}
.t-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 5px;
  font-size: 11px;
  color: var(--faint);
  flex-wrap: wrap;
}
.cat {
  display: inline-block;
  font-size: 11px;
  color: #5b6b8c;
  background: #eef1f6;
  border-radius: 6px;
  padding: 2px 7px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
  vertical-align: middle;
}
.none {
  color: #c5cad2;
}
.st {
  display: inline-flex;
  align-items: center;
  font-size: 11px;
  font-weight: 700;
  border-radius: 99px;
  padding: 2px 9px;
  white-space: nowrap;
}
.st.open {
  background: #fff1e6;
  color: #c2660f;
}
.st.resolved {
  background: #e8f5ec;
  color: #2a8a4f;
}
.qv.mobile .c-cat,
.qv.mobile .c-st,
.qv.mobile .c-n,
.qv.mobile .c-when,
.qv.mobile th.c-cat,
.qv.mobile th.c-st,
.qv.mobile th.c-n,
.qv.mobile th.c-when {
  display: none;
}
/* ---- スレッド ---- */
.thread {
  position: relative;
}
.qv.mobile .thread {
  height: calc(100vh - 120px);
  min-height: 480px;
}
.th-head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-bottom: 1px solid var(--line);
  flex-shrink: 0;
}
.back {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  border: none;
  background: transparent;
  color: var(--primary);
  font-size: 14px;
  font-weight: 600;
  padding: 4px 6px 4px 0;
  cursor: pointer;
  flex-shrink: 0;
}
.th-main {
  flex: 1;
  min-width: 0;
}
.th-title {
  font-size: 14.5px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.th-sub {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 11px;
  color: var(--faint);
  margin-top: 2px;
  min-width: 0;
}
.who {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.st-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 32px;
  padding: 0 12px;
  border: 1px solid #dfe3e8;
  border-radius: 99px;
  background: #fff;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
  color: var(--ink);
}
.st-btn.open .st-dot {
  background: #e58f2d;
}
.st-btn.resolved .st-dot {
  background: #2a8a4f;
}
.st-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
}
.st-arrow {
  font-weight: 500;
  color: var(--faint);
  font-size: 11px;
}
.x {
  width: 32px;
  height: 32px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--faint);
  font-size: 20px;
  cursor: pointer;
}
.new-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px;
  overflow: auto;
}
.inp,
.sel {
  min-height: 38px;
  padding: 6px 10px;
  border: 1px solid #dfe3e8;
  border-radius: 10px;
  font-size: 13.5px;
  color: var(--ink);
  background: #fff;
  outline: none;
  width: 100%;
}
.inp:focus,
.sel:focus {
  border-color: #b9c3f0;
}
.cat-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}
.msgs {
  flex: 1;
  min-height: 0;
  overflow: auto;
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: #fafbfc;
}
.day {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--faint);
  font-size: 11px;
  margin: 4px 0;
}
.day::before,
.day::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--line);
}
.msg {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  max-width: 100%;
}
.avatar {
  width: 34px;
  height: 34px;
  border-radius: 10px;
  background: #3b50cc;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 14px;
  flex-shrink: 0;
}
.avatar.tutor {
  background: #1c2024;
}
.bubble {
  flex: 1;
  min-width: 0;
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 9px 12px 10px;
}
.msg.mine .bubble {
  background: #f1f4ff;
  border-color: #e0e6fb;
}
.m-head {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  margin-bottom: 4px;
}
.m-name {
  font-weight: 700;
}
.role {
  margin-left: 4px;
  font-size: 10px;
  font-weight: 700;
  color: #fff;
  background: #1c2024;
  border-radius: 5px;
  padding: 1px 5px;
  vertical-align: middle;
}
.m-when {
  color: var(--faint);
  font-size: 11px;
}
.m-line {
  font-size: 9.5px;
  font-weight: 700;
  color: #089b46;
  border: 1px solid #a9e3bf;
  border-radius: 5px;
  padding: 0 4px;
}
.m-del {
  margin-left: auto;
  border: none;
  background: transparent;
  color: var(--faint);
  font-size: 11px;
  cursor: pointer;
  padding: 2px 6px;
  border-radius: 6px;
}
.m-del:active {
  background: #eef0f4;
}
.m-body {
  display: block;
  font-size: 14px;
}
.m-atts {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 8px;
}
.m-img {
  border: 1px solid var(--line);
  border-radius: 10px;
  padding: 0;
  background: #fff;
  cursor: pointer;
  width: 160px;
  height: 120px;
  overflow: hidden;
}
.m-img :deep(img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.m-file {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 8px 12px;
  border: 1px solid var(--line);
  border-radius: 10px;
  background: #fff;
  color: var(--ink);
  font-size: 12.5px;
  cursor: pointer;
  max-width: 100%;
}
.f-name {
  max-width: 240px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-weight: 600;
}
.f-size {
  color: var(--faint);
  font-size: 11px;
}
.th-compose {
  padding: 10px 12px calc(10px + env(safe-area-inset-bottom));
  border-top: 1px solid var(--line);
  flex-shrink: 0;
  background: #fff;
}
.placeholder {
  align-items: center;
  justify-content: center;
  color: var(--faint);
  font-size: 13px;
}
.ph {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  text-align: center;
  padding: 30px;
  line-height: 1.7;
}
/* ---- タイトル・分類の編集 ---- */
.meta-panel {
  width: min(440px, calc(100vw - 32px));
  padding: 18px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  background: #fff;
  border-radius: 16px;
}
.mp-title {
  font-size: 14px;
  font-weight: 700;
}
.mp-row {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 4px;
}
.btn {
  min-height: 36px;
  padding: 0 16px;
  border: 1px solid #dfe3e8;
  border-radius: 10px;
  background: #fff;
  color: var(--ink);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.btn.primary {
  background: var(--primary);
  border-color: var(--primary);
  color: #fff;
}
.btn.ghost {
  border-color: transparent;
  color: var(--mut);
}
/* ---- 画像の拡大 ---- */
.lb {
  position: fixed;
  inset: 0;
  z-index: 2600;
  display: flex;
  flex-direction: column;
  background: #1c1f24;
  color: #fff;
}
.lb-top {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: max(8px, env(safe-area-inset-top)) 10px 8px;
  flex-shrink: 0;
}
.lb-name {
  flex: 1;
  min-width: 0;
  text-align: center;
  font-size: 13px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: #cfd4dc;
}
.lb-btn {
  min-height: 36px;
  padding: 0 12px;
  border: none;
  border-radius: 9px;
  background: transparent;
  color: #cfe0ff;
  font-size: 13.5px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.lb-body {
  flex: 1;
  min-height: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: auto;
  padding: 8px;
}
.lb-body :deep(img) {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}
@media (max-width: 1100px) {
  .qv:not(.mobile) {
    grid-template-columns: minmax(320px, 1fr) minmax(360px, 1fr);
  }
}
</style>
