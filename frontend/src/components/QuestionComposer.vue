<script setup lang="ts">
/**
 * 質問事項のメッセージ入力欄。
 * - 文字の装飾（太字・取消し線・下線・マーカー・色）は選択範囲を簡易タグで囲む（lib/richText.ts）。下に表示イメージのプレビューを出す
 * - 添付: 「添付」ボタン（画像・ファイル）、画像の Ctrl+V 貼り付け、ドラッグ＆ドロップ、iPad・iPhone 向けの「貼り付け」ボタン（navigator.clipboard.read）
 * - 画像は送る前に「書き込み」（図形・手書き・文字。ImageMarkupModal）で編集できる
 * - 右下に「LINE で通知」のチェック（前回の状態を端末に記憶）と送信ボタン。Ctrl+Enter でも送信
 * - replyTo があれば「返信先」を上に出し、送信時に本文の先頭へ [re=ID]名前: 抜粋[/re] を付ける
 * - mode='edit' は本文の編集（添付・LINE は出さず、保存／キャンセル）
 * - collapsible（スマホ・iPad）: ふだんは 1 行の入力欄と送信ボタンだけ。入力欄をタップすると道具・プレビュー・添付・LINE を含む全体が開く。
   右上の「∨」で畳む。送信後も畳む
 * 親は submit を受けて添付のアップロードと送信を行い、終わったら reset() を呼ぶ
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import ImageMarkupModal from '@/components/ImageMarkupModal.vue'
import RichText from '@/components/RichText.vue'
import type { PendingAttachment } from '@/api/questions'
import { RICH_COLORS, replyTag, wrapSelection, type RichTag } from '@/lib/richText'
import { isTouch } from '@/lib/native'
import { useUiStore } from '@/stores/ui'

const props = withDefaults(
  defineProps<{
    submitLabel?: string
    placeholder?: string
    busy?: boolean
    /** 送信中の進み（添付のアップロード。0〜1） */
    progress?: number | null
    /** 入力欄を自動で大きくする最小の行数 */
    minRows?: number
    /** send=送信（既定）/ edit=本文の編集（添付・LINE なし。保存／キャンセル） */
    mode?: 'send' | 'edit'
    /** 編集時の元の本文 */
    initialBody?: string
    /** 返信先のメッセージ（送信時に本文の先頭へ [re=ID] を付ける） */
    replyTo?: { id: number; name: string; excerpt: string } | null
    /** スマホ・iPad: ふだんは 1 行だけ出し、タップで全体を開く */
    collapsible?: boolean
  }>(),
  { submitLabel: '送信', placeholder: 'メッセージを入力…', busy: false, progress: null, minRows: 3, mode: 'send', initialBody: '', replyTo: null, collapsible: false },
)
const emit = defineEmits<{ submit: [payload: { body: string; attachments: PendingAttachment[]; notifyLine: boolean }]; cancel: []; clearReply: [] }>()

const ui = useUiStore()
const LINE_KEY = 'sm_q_line'

const body = ref(props.initialBody)
const attachments = ref<PendingAttachment[]>([])
const isEdit = computed(() => props.mode === 'edit')
/** collapsible のとき、全体（道具・プレビュー・添付・LINE）を開いているか */
const expanded = ref(!props.collapsible)
const collapsed = computed(() => props.collapsible && !expanded.value)
function expand() {
  if (!expanded.value) {
    expanded.value = true
    nextTick(autosize)
  }
}
function collapse() {
  expanded.value = false
  colorOpen.value = false
  textarea.value?.blur()
  nextTick(autosize)
}
const notifyLine = ref(localStorage.getItem(LINE_KEY) !== '0')
const preview = ref(localStorage.getItem('sm_q_preview') !== '0')
const textarea = ref<HTMLTextAreaElement | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const colorOpen = ref(false)
const dragOver = ref(false)
const editing = ref<PendingAttachment | null>(null)
const canClipboardRead = isTouch && typeof navigator.clipboard?.read === 'function'

const isEmpty = computed(() => body.value.trim() === '' && attachments.value.length === 0)
const hasTags = computed(() => /\[(b|s|u|m|color)(=[^\]]*)?\]/.test(body.value))

function setLine(v: boolean) {
  notifyLine.value = v
  localStorage.setItem(LINE_KEY, v ? '1' : '0')
}
function togglePreview() {
  preview.value = !preview.value
  localStorage.setItem('sm_q_preview', preview.value ? '1' : '0')
}

// ---- 装飾 ----
function wrap(tag: RichTag, value?: string) {
  const el = textarea.value
  const start = el?.selectionStart ?? body.value.length
  const end = el?.selectionEnd ?? body.value.length
  const r = wrapSelection(body.value, start, end, tag, value)
  body.value = r.text
  colorOpen.value = false
  nextTick(() => {
    el?.focus()
    el?.setSelectionRange(r.start, r.end)
    autosize()
  })
}

// ---- 添付 ----
function keyOf() {
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`
}
function addFiles(files: Iterable<File | Blob>, nameHint?: string) {
  expand()
  let n = 0
  for (const f of files) {
    const name = f instanceof File && f.name ? f.name : nameHint ?? (f.type.startsWith('image/') ? `image-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '')}.png` : 'file')
    const isImage = f.type.startsWith('image/')
    attachments.value.push({ key: keyOf(), file: f, name, kind: isImage ? 'image' : 'file', previewUrl: isImage ? URL.createObjectURL(f) : null })
    n++
  }
  if (n && attachments.value.length > 20) {
    attachments.value.splice(20).forEach(release)
    ui.notify('添付は 1 回に 20 件までです')
  }
}
function release(a: PendingAttachment) {
  if (a.previewUrl) URL.revokeObjectURL(a.previewUrl)
}
function removeAttachment(a: PendingAttachment) {
  release(a)
  attachments.value = attachments.value.filter((x) => x.key !== a.key)
}
function pickFiles() {
  fileInput.value?.click()
}
function onFilePicked(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files?.length) addFiles(Array.from(input.files))
  input.value = ''
}
function onPaste(e: ClipboardEvent) {
  const items = Array.from(e.clipboardData?.items ?? [])
  const files = items.filter((x) => x.kind === 'file').map((x) => x.getAsFile()).filter((f): f is File => !!f)
  if (!files.length) return
  e.preventDefault()
  addFiles(files)
}
/** iPad・iPhone: paste イベントが使えないので、ボタンでクリップボードの画像を読む */
async function pasteFromClipboard() {
  try {
    const items = await navigator.clipboard.read()
    const blobs: Blob[] = []
    for (const it of items) {
      const type = it.types.find((t) => t.startsWith('image/'))
      if (type) blobs.push(await it.getType(type))
    }
    if (!blobs.length) {
      ui.notify('クリップボードに画像がありません', { center: true })
      return
    }
    addFiles(blobs)
  } catch {
    ui.notify('貼り付けが許可されませんでした', { center: true })
  }
}
function onDrop(e: DragEvent) {
  dragOver.value = false
  const files = Array.from(e.dataTransfer?.files ?? [])
  if (files.length) addFiles(files)
}
function onEdited(blob: Blob) {
  const a = editing.value
  editing.value = null
  if (!a) return
  release(a)
  const name = a.name.replace(/\.[A-Za-z0-9]+$/, '') + '.jpg'
  const file = new File([blob], name, { type: 'image/jpeg' })
  const i = attachments.value.findIndex((x) => x.key === a.key)
  const next: PendingAttachment = { key: a.key, file, name, kind: 'image', previewUrl: URL.createObjectURL(file) }
  if (i >= 0) attachments.value.splice(i, 1, next)
  else attachments.value.push(next)
}
function fmtSize(n: number): string {
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`
  return `${(n / 1024 / 1024).toFixed(1)} MB`
}

// ---- 送信 ----
function submit() {
  if (props.busy || isEmpty.value) return
  let text = body.value.trim()
  if (props.replyTo && !isEdit.value) text = replyTag(props.replyTo.id, props.replyTo.name, props.replyTo.excerpt) + text
  emit('submit', { body: text, attachments: [...attachments.value], notifyLine: isEdit.value ? false : notifyLine.value })
}
function onKey(e: KeyboardEvent) {
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
    e.preventDefault()
    submit()
  }
}
/** 送信が終わったら親が呼ぶ（添付の object URL は親が使い終わっているので解放する） */
function reset() {
  attachments.value.forEach(release)
  attachments.value = []
  body.value = ''
  if (props.collapsible) expanded.value = false
  nextTick(autosize)
}
function autosize() {
  const el = textarea.value
  if (!el) return
  el.style.height = 'auto'
  const min = (collapsed.value ? 1 : props.minRows) * 22 + 16
  el.style.height = `${Math.max(min, Math.min(collapsed.value ? 110 : 360, el.scrollHeight))}px`
}
function focus() {
  textarea.value?.focus()
}
onBeforeUnmount(() => attachments.value.forEach(release))
onMounted(() => nextTick(autosize))

watch(
  () => props.replyTo,
  (v) => {
    if (v) expand()
  },
)

defineExpose({ addFiles, reset, focus, isEmpty, expand })
</script>

<template>
  <div class="qc" :class="{ over: dragOver, busy, collapsed }" @dragover.prevent="dragOver = true" @dragleave="dragOver = false" @drop.prevent="onDrop">
    <!-- 道具: 装飾・添付・プレビュー -->
    <div v-if="!collapsed" class="qc-tools">
      <button type="button" class="t" title="太字" @click="wrap('b')"><b>B</b></button>
      <button type="button" class="t" title="取消し線" @click="wrap('s')"><s>S</s></button>
      <button type="button" class="t" title="下線" @click="wrap('u')"><u>U</u></button>
      <button type="button" class="t" title="マーカー" @click="wrap('m')"><span class="mk">M</span></button>
      <button type="button" class="t" title="引用" @click="wrap('q')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M7 7h4v4H8.5c0 1.4 1 2.2 2.5 2.5V16c-3-.3-5-2.3-5-5.5V7zm8 0h4v4h-2.5c0 1.4 1 2.2 2.5 2.5V16c-3-.3-5-2.3-5-5.5V7z" /></svg>
      </button>
      <div class="color-wrap">
        <button type="button" class="t" title="文字の色" :class="{ on: colorOpen }" @click="colorOpen = !colorOpen">
          <span class="color-a">A</span>
        </button>
        <div v-if="colorOpen" class="color-pop">
          <button v-for="c in RICH_COLORS" :key="c.key" type="button" class="sw" :style="{ background: c.hex }" :title="c.label" @click="wrap('color', c.key)"></button>
        </div>
      </div>
      <span v-if="!isEdit" class="sep"></span>
      <button v-if="!isEdit" type="button" class="t wide" title="画像やファイルを添付" @click="pickFiles">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.4 11.1l-8.5 8.5a5.5 5.5 0 0 1-7.8-7.8l8.8-8.8a3.5 3.5 0 0 1 5 5l-8.9 8.8a1.5 1.5 0 0 1-2.1-2.1l8.2-8.1" /></svg>
        添付
      </button>
      <button v-if="canClipboardRead && !isEdit" type="button" class="t wide" title="クリップボードの画像を貼り付け" @click="pasteFromClipboard">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4h6v3H9z" /><path d="M15 5h3a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h3" /></svg>
        貼り付け
      </button>
      <span style="flex: 1"></span>
      <button type="button" class="t wide" :class="{ on: preview }" title="表示イメージ" @click="togglePreview">プレビュー</button>
      <button v-if="collapsible" type="button" class="t" title="畳む" @click="collapse">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6" /></svg>
      </button>
      <input ref="fileInput" type="file" multiple accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip" style="display: none" @change="onFilePicked" />
    </div>

    <div v-if="replyTo && !isEdit" class="qc-reply">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14L4 9l5-5" /><path d="M4 9h10a6 6 0 0 1 0 12h-3" /></svg>
      <span class="qr-label">返信先</span>
      <span class="qr-text"><b>{{ replyTo.name }}</b>: {{ replyTo.excerpt }}</span>
      <button type="button" class="qr-x" title="返信先を外す" @click="emit('clearReply')">×</button>
    </div>
    <div class="qc-row">
      <textarea
        ref="textarea"
        v-model="body"
        class="qc-text"
        :placeholder="placeholder"
        :rows="collapsed ? 1 : minRows"
        @focus="expand"
        @input="autosize"
        @paste="onPaste"
        @keydown="onKey"
      ></textarea>
      <button v-if="collapsed" type="button" class="send mini" :disabled="busy || isEmpty" @click="submit">{{ busy ? '…' : submitLabel }}</button>
    </div>

    <!-- 表示イメージ -->
    <div v-if="!collapsed && preview && body.trim()" class="qc-preview">
      <div class="pv-label">表示イメージ{{ hasTags ? '' : '（装飾なし）' }}</div>
      <RichText :text="body" />
    </div>

    <!-- 添付 -->
    <div v-if="!collapsed && attachments.length" class="qc-files">
      <div v-for="a in attachments" :key="a.key" class="att" :class="a.kind">
        <template v-if="a.kind === 'image'">
          <img :src="a.previewUrl ?? ''" :alt="a.name" @click="editing = a" />
          <button type="button" class="att-edit" title="書き込む（図形・手書き）" @click="editing = a">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20l4-1L19 8l-3-3L5 16z" /><path d="M14 7l3 3" /></svg>
            書き込み
          </button>
        </template>
        <template v-else>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3.5h9l4 4v13H6z" /><path d="M15 3.5v4h4" /></svg>
          <span class="att-name" :title="a.name">{{ a.name }}</span>
          <span class="att-size">{{ fmtSize(a.file.size) }}</span>
        </template>
        <button type="button" class="att-x" title="外す" @click="removeAttachment(a)">×</button>
      </div>
    </div>

    <!-- 下段: LINE 通知と送信 -->
    <div v-if="isEdit" class="qc-foot">
      <span class="hint">Ctrl+Enter で保存</span>
      <span style="flex: 1"></span>
      <button type="button" class="send ghost" :disabled="busy" @click="emit('cancel')">キャンセル</button>
      <button type="button" class="send" :disabled="busy || isEmpty" @click="submit">{{ busy ? '保存中…' : '保存' }}</button>
    </div>
    <div v-else-if="!collapsed" class="qc-foot">
      <span v-if="dragOver" class="drop-hint">ここにドロップして添付</span>
      <span v-else-if="progress !== null && busy" class="drop-hint">添付をアップロード中… {{ Math.round((progress ?? 0) * 100) }}%</span>
      <span v-else class="hint">Ctrl+V で画像を貼り付け、ドラッグ＆ドロップでも添付できます</span>
      <span style="flex: 1"></span>
      <label class="line-chk" :class="{ on: notifyLine }">
        <input type="checkbox" :checked="notifyLine" @change="setLine(($event.target as HTMLInputElement).checked)" />
        <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3C6.5 3 2 6.6 2 11c0 3.9 3.5 7.2 8.2 7.9.3.1.8.2.9.5.1.3.1.7 0 1l-.1.9c0 .3-.2 1 .9.6 1.1-.5 6-3.6 8.2-6.1C21.4 14.4 22 12.8 22 11c0-4.4-4.5-8-10-8z" /></svg>
        LINE で通知
      </label>
      <button type="button" class="send" :disabled="busy || isEmpty" @click="submit">{{ busy ? '送信中…' : submitLabel }}</button>
    </div>

    <Transition name="ui-modal">
      <ImageMarkupModal v-if="editing" :blob="editing.file" :name="editing.name" @close="editing = null" @save="onEdited" />
    </Transition>
  </div>
</template>

<style scoped>
.qc {
  display: flex;
  flex-direction: column;
  border: 1px solid #dfe3e8;
  border-radius: 14px;
  background: #fff;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.qc:focus-within {
  border-color: #b9c3f0;
  box-shadow: 0 0 0 3px rgba(59, 80, 204, 0.08);
}
.qc.over {
  border-color: var(--primary);
  background: #f5f7ff;
}
.qc.busy {
  opacity: 0.85;
}
.qc-tools {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 6px 8px 0;
  flex-wrap: wrap;
}
.t {
  min-width: 32px;
  height: 30px;
  padding: 0 7px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--ink);
  font-size: 14px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
}
.t.wide {
  font-size: 12.5px;
  font-weight: 600;
  color: var(--mut);
}
.t:active,
.t.on {
  background: #eef0f4;
  color: var(--ink);
}
.mk {
  background: #fff3a3;
  padding: 0 3px;
  border-radius: 3px;
  font-weight: 700;
}
.color-a {
  font-weight: 700;
  border-bottom: 3px solid #e5322d;
  line-height: 1;
  padding-bottom: 1px;
}
.color-wrap {
  position: relative;
}
.color-pop {
  position: absolute;
  top: 34px;
  left: 0;
  z-index: 5;
  display: flex;
  gap: 6px;
  padding: 8px;
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
}
.sw {
  width: 24px;
  height: 24px;
  border: 2px solid #fff;
  border-radius: 50%;
  box-shadow: 0 0 0 1px #d8dce1;
  cursor: pointer;
}
.sep {
  width: 1px;
  height: 18px;
  background: var(--line);
  margin: 0 4px;
}
.qc-reply {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 6px 10px 0;
  padding: 5px 8px 5px 10px;
  border-left: 3px solid var(--primary);
  border-radius: 0 8px 8px 0;
  background: #eef1fb;
  font-size: 12px;
  color: #3f4a6b;
  min-width: 0;
}
.qc-reply svg {
  color: var(--primary);
  flex-shrink: 0;
}
.qr-label {
  font-weight: 700;
  color: var(--primary);
  flex-shrink: 0;
}
.qr-text {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.qr-x {
  border: none;
  background: transparent;
  color: var(--faint);
  font-size: 16px;
  line-height: 1;
  cursor: pointer;
  padding: 0 4px;
}
.send.ghost {
  background: transparent;
  color: var(--mut);
  border: 1px solid #dfe3e8;
}
.qc-row {
  display: flex;
  align-items: flex-end;
  gap: 6px;
}
.qc.collapsed .qc-row {
  padding: 6px 6px 6px 0;
}
.send.mini {
  min-height: 34px;
  padding: 0 12px;
  font-size: 12.5px;
  flex-shrink: 0;
}
.qc-text {
  display: block;
  flex: 1;
  min-width: 0;
  width: 100%;
  border: none;
  outline: none;
  resize: none;
  padding: 8px 12px;
  font-size: 14px;
  line-height: 22px;
  color: var(--ink);
  background: transparent;
}
.qc-preview {
  margin: 0 10px;
  padding: 8px 10px;
  border-top: 1px dashed #e3e6ea;
  font-size: 14px;
}
.pv-label {
  font-size: 10.5px;
  color: var(--faint);
  margin-bottom: 3px;
}
.qc-files {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 8px 10px 0;
}
.att {
  position: relative;
  display: flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
}
.att.image img {
  width: 96px;
  height: 96px;
  object-fit: cover;
  border-radius: 10px;
  border: 1px solid var(--line);
  cursor: pointer;
  display: block;
}
.att-edit {
  position: absolute;
  left: 4px;
  bottom: 4px;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 7px;
  border: none;
  border-radius: 99px;
  background: rgba(28, 32, 36, 0.78);
  color: #fff;
  font-size: 10.5px;
  font-weight: 600;
  cursor: pointer;
}
.att.file {
  padding: 6px 30px 6px 10px;
  border: 1px solid var(--line);
  border-radius: 10px;
  background: #f6f7f9;
  color: var(--ink);
  font-size: 12.5px;
}
.att-name {
  max-width: 220px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.att-size {
  color: var(--faint);
  font-size: 11px;
}
.att-x {
  position: absolute;
  top: -6px;
  right: -6px;
  width: 22px;
  height: 22px;
  border: none;
  border-radius: 50%;
  background: #1c2024;
  color: #fff;
  font-size: 14px;
  line-height: 1;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}
.att.file .att-x {
  top: 50%;
  right: 6px;
  transform: translateY(-50%);
  background: transparent;
  color: var(--faint);
  font-size: 16px;
}
.qc-foot {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px 10px;
  flex-wrap: wrap;
}
.hint,
.drop-hint {
  font-size: 11px;
  color: var(--faint);
}
.drop-hint {
  color: var(--primary);
  font-weight: 600;
}
.line-chk {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 5px 10px;
  border-radius: 99px;
  border: 1px solid #dfe3e8;
  font-size: 12px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
  user-select: none;
}
.line-chk.on {
  border-color: #06c755;
  color: #089b46;
  background: #effbf3;
}
.line-chk input {
  width: 15px;
  height: 15px;
  margin: 0;
  accent-color: #06c755;
}
.send {
  min-height: 36px;
  padding: 0 18px;
  border: none;
  border-radius: 10px;
  background: var(--primary);
  color: #fff;
  font-size: 13.5px;
  font-weight: 700;
  cursor: pointer;
}
.send:disabled {
  opacity: 0.4;
  cursor: default;
}
@media (max-width: 600px) {
  .hint {
    display: none;
  }
  .att-name {
    max-width: 140px;
  }
}
</style>
