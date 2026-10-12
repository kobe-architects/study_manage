<script setup lang="ts">
/**
 * 間違いノートの 1 問（1 ページ）の作成・編集。
 * 対象の問題の写真（必須）を撮影／選択／貼り付け／ドロップで登録すると保存が始まり、以降の変更は自動で保存する。
 * 科目・分野・タイトル・出典・日付と、ページの配置（MistakeCanvas）を扱う。
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import MistakeCanvas from '@/components/MistakeCanvas.vue'
import HelpTip from '@/components/HelpTip.vue'
import { mistakeApi } from '@/api/mistake'
import { iso } from '@/lib/design'
import { bottomOf, defaultTextItem, ensureLayout, fileToJpeg, fitExtra, fitProblem, forgetImageUrl, imageObjectUrl, newItemId, registerImageUrl } from '@/lib/mistake'
import { isTouch, viewportWidth } from '@/lib/native'
import { useStudyStore } from '@/stores/study'
import { useUiStore } from '@/stores/ui'
import type { MistakeLayout, MistakeLayoutItem, MistakeNoteDetail, MistakeNoteSummary } from '@/types'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const study = useStudyStore()

const noteId = computed(() => (route.name === 'mistake-edit' ? Number(route.params.id) : null))
const note = ref<MistakeNoteDetail | null>(null)
const layout = ref<MistakeLayout>(ensureLayout())
const urls = reactive<Record<number, string>>({})
const meta = reactive({ subjectId: 0, field: '', title: '', source: '', notedOn: iso(new Date()) })
const loading = ref(true)
const busy = ref(false)
const saveState = ref<'none' | 'dirty' | 'saving' | 'saved' | 'error'>('none')
const isMobile = computed(() => viewportWidth.value < 860)
const hasProblem = computed(() => layout.value.items.some((i) => i.role === 'problem'))

// ---- 科目・分野の候補 ----
const subjects = computed(() => {
  const m = new Map<number, { id: number; name: string; color: string }>()
  for (const it of study.items) if (!m.has(it.subjectId)) m.set(it.subjectId, { id: it.subjectId, name: it.subjectName, color: ui.colorOf(it.colorSoft, it.colorVivid) })
  return Array.from(m.values())
})
const allNotes = ref<MistakeNoteSummary[]>([])
const fieldOptions = computed(() => {
  const out: string[] = []
  for (const it of study.items) if (it.subjectId === meta.subjectId && !out.includes(it.major)) out.push(it.major)
  for (const n of allNotes.value) if (n.subjectId === meta.subjectId && n.field && !out.includes(n.field)) out.push(n.field)
  return out
})
const subjectColor = computed(() => subjects.value.find((s) => s.id === meta.subjectId)?.color)

// ---- 読み込み ----
async function load() {
  loading.value = true
  try {
    mistakeApi.list().then((l) => (allNotes.value = l)).catch(() => {})
    if (noteId.value) {
      const d = await mistakeApi.show(noteId.value)
      note.value = d
      layout.value = ensureLayout(d.layout)
      meta.subjectId = d.subjectId ?? 0
      meta.field = d.field ?? ''
      meta.title = d.title ?? ''
      meta.source = d.source ?? ''
      meta.notedOn = d.notedOn
      await Promise.all(d.images.map((im) => imageObjectUrl(im.id).then((u) => (urls[im.id] = u)).catch(() => {})))
      saveState.value = 'saved'
    } else {
      // 新規: 科目は前回使ったもの
      const last = Number(localStorage.getItem('sm_mn_subject') ?? 0)
      meta.subjectId = subjects.value.some((s) => s.id === last) ? last : (subjects.value[0]?.id ?? 0)
    }
  } catch {
    ui.notify('間違いノートが見つかりません')
    router.replace({ name: 'mistakes' })
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(
  () => meta.subjectId,
  (id) => {
    if (id) localStorage.setItem('sm_mn_subject', String(id))
  },
)

function metaPayload() {
  return {
    subjectId: meta.subjectId || null,
    field: meta.field.trim() || null,
    title: meta.title.trim() || null,
    source: meta.source.trim() || null,
    notedOn: meta.notedOn,
  }
}

// ---- 自動保存（登録後の変更を 1.2 秒まとめて保存） ----
let timer: number | undefined
let saveSeq = 0
function scheduleSave() {
  if (!note.value) return
  saveState.value = 'dirty'
  window.clearTimeout(timer)
  timer = window.setTimeout(saveNow, 1200)
}
async function saveNow() {
  if (!note.value) return
  window.clearTimeout(timer)
  const seq = ++saveSeq
  saveState.value = 'saving'
  try {
    const d = await mistakeApi.update(note.value.id, { ...metaPayload(), layout: layout.value })
    if (seq === saveSeq) {
      note.value = { ...d, layout: layout.value }
      saveState.value = 'saved'
    }
  } catch {
    if (seq === saveSeq) {
      saveState.value = 'error'
      ui.notify('保存に失敗しました')
    }
  }
}
watch(meta, () => !loading.value && scheduleSave())
function onLayout(l: MistakeLayout) {
  layout.value = l
  scheduleSave()
}
function onBeforeUnload(e: BeforeUnloadEvent) {
  if (saveState.value === 'dirty' || saveState.value === 'saving') {
    e.preventDefault()
  }
}
onMounted(() => window.addEventListener('beforeunload', onBeforeUnload))
onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', onBeforeUnload)
  document.removeEventListener('paste', onPaste)
  // 画面を離れるときに未保存分があれば送っておく
  if (saveState.value === 'dirty') saveNow()
})

// ---- 画像の登録 ----
const problemInput = ref<HTMLInputElement | null>(null)
const extraInput = ref<HTMLInputElement | null>(null)

/** 対象の問題の写真を登録（初回は保存の開始、2 回目以降は差し替え） */
async function setProblem(file: Blob) {
  if (busy.value) return
  busy.value = true
  try {
    const { blob, width, height } = await fileToJpeg(file)
    const fit = fitProblem(width, height)
    if (!note.value) {
      const created = await mistakeApi.create(blob, metaPayload(), layout.value)
      const img = created.images.find((i) => i.role === 'problem')
      if (!img) throw new Error('画像の登録に失敗しました')
      registerImageUrl(img.id, blob)
      const rest = layout.value.items.filter((i) => i.role !== 'problem')
      const problem: MistakeLayoutItem = { id: newItemId('p'), type: 'image', imageId: img.id, role: 'problem', ...fit }
      if (!rest.some((i) => i.type === 'text')) rest.push(defaultTextItem(fit.h))
      const next: MistakeLayout = { items: [problem, ...rest], ink: layout.value.ink }
      await mistakeApi.update(created.id, { layout: next })
      ui.notify('問題を登録しました。以後の変更は自動で保存されます')
      // URL を編集画面に切り替える（保存済みの内容を読み直す）
      router.replace({ name: 'mistake-edit', params: { id: created.id } })
      return
    }
    const img = await mistakeApi.addImage(note.value.id, blob, 'problem')
    registerImageUrl(img.id, blob)
    urls[img.id] = await imageObjectUrl(img.id)
    const old = layout.value.items.find((i) => i.role === 'problem')
    if (old?.imageId) forgetImageUrl(old.imageId)
    const problem: MistakeLayoutItem = { id: old?.id ?? newItemId('p'), type: 'image', imageId: img.id, role: 'problem', ...fit }
    note.value.images = [...note.value.images.filter((i) => i.role !== 'problem'), img]
    onLayout({ items: [problem, ...layout.value.items.filter((i) => i.role !== 'problem')], ink: layout.value.ink })
    ui.notify('問題の写真を差し替えました')
  } catch (e) {
    ui.notify(errorMessage(e, '画像の登録に失敗しました'))
  } finally {
    busy.value = false
  }
}

/** 説明用の画像を追加 */
async function addExtra(file: Blob) {
  if (busy.value) return
  if (!note.value) {
    ui.notify('先に対象の問題の写真を登録してください')
    return
  }
  busy.value = true
  try {
    const { blob, width, height } = await fileToJpeg(file, 1600)
    const img = await mistakeApi.addImage(note.value.id, blob, 'extra')
    registerImageUrl(img.id, blob)
    urls[img.id] = await imageObjectUrl(img.id)
    note.value.images = [...note.value.images, img]
    const problem = layout.value.items.find((i) => i.role === 'problem')
    const item: MistakeLayoutItem = { id: newItemId('i'), type: 'image', imageId: img.id, ...fitExtra(width, height, problem ? problem.y + problem.h : bottomOf(layout.value.items)) }
    onLayout({ items: [...layout.value.items, item], ink: layout.value.ink })
  } catch (e) {
    ui.notify(errorMessage(e, '画像の追加に失敗しました'))
  } finally {
    busy.value = false
  }
}

async function removeImage(imageId: number) {
  try {
    await mistakeApi.removeImage(imageId)
    forgetImageUrl(imageId)
    if (note.value) note.value.images = note.value.images.filter((i) => i.id !== imageId)
  } catch {
    ui.notify('画像の削除に失敗しました')
  }
}

function onFile(e: Event, kind: 'problem' | 'extra') {
  const input = e.target as HTMLInputElement
  const f = input.files?.[0]
  input.value = ''
  if (!f) return
  if (kind === 'problem') setProblem(f)
  else addExtra(f)
}
/** タッチ端末で、カメラではなく写真ライブラリから対象の問題を選ぶ */
function pickProblemFromLibrary() {
  const el = document.createElement('input')
  el.type = 'file'
  el.accept = 'image/*'
  el.onchange = (ev) => onFile(ev, 'problem')
  el.click()
}
function onPaste(e: ClipboardEvent) {
  const items = Array.from(e.clipboardData?.items ?? [])
  const it = items.find((x) => x.type.startsWith('image/'))
  const f = it?.getAsFile()
  if (!f) return
  e.preventDefault()
  if (!hasProblem.value) setProblem(f)
  else addExtra(f)
}
function onDrop(e: DragEvent) {
  e.preventDefault()
  const f = Array.from(e.dataTransfer?.files ?? []).find((x) => x.type.startsWith('image/'))
  if (!f) return
  if (!hasProblem.value) setProblem(f)
  else addExtra(f)
}
onMounted(() => document.addEventListener('paste', onPaste))

function errorMessage(e: unknown, fallback: string): string {
  const r = (e as { response?: { data?: { message?: string } } })?.response
  return r?.data?.message || (e instanceof Error && e.message) || fallback
}

const saveLabel = computed(
  () =>
    ({
      none: hasProblem.value ? '' : '問題の写真を登録すると保存が始まります',
      dirty: '変更あり…',
      saving: '保存中…',
      saved: '保存済み',
      error: '保存に失敗（再試行します）',
    })[saveState.value],
)
</script>

<template>
  <div>
    <div v-if="loading" class="hint" style="text-align: center">読み込み中…</div>
    <template v-else>
      <div class="top">
        <div class="left">
          <button v-if="!isMobile" class="back" @click="router.push({ name: 'mistakes' })">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7" /></svg>一覧
          </button>
          <div class="ttl">{{ note ? '間違いノートを編集' : '問題を追加' }}</div>
          <HelpTip
            text="対象の問題の写真は必須です。スマホ・iPad では「撮影する」でカメラ、PC では画像をコピーして Ctrl+V、またはファイルをドラッグ＆ドロップで登録できます。写真の下に「文字」で入力、iPad では「ペン」で手書きできます（Apple Pencil 推奨。指で書くときは「指で書く」を ON）。説明用の画像は「選択」でドラッグして移動、右下のつまみで大きさを変えられます。変更は自動で保存されます。"
          />
        </div>
        <div class="right">
          <span class="save" :class="saveState">{{ saveLabel }}</span>
          <button v-if="note && saveState !== 'saved'" class="btn-ghost" :disabled="saveState === 'saving'" @click="saveNow">今すぐ保存</button>
        </div>
      </div>

      <!-- 科目・分野・タイトルなど -->
      <div class="meta card">
        <label class="fld">
          <span>科目</span>
          <select v-model.number="meta.subjectId" :style="{ color: subjectColor, fontWeight: subjectColor ? 700 : undefined }">
            <option :value="0">科目なし</option>
            <option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <label class="fld">
          <span>分野</span>
          <input v-model="meta.field" list="mn-fields" placeholder="例: 二次関数" />
          <datalist id="mn-fields">
            <option v-for="f in fieldOptions" :key="f" :value="f"></option>
          </datalist>
        </label>
        <label class="fld grow">
          <span>タイトル（任意）</span>
          <input v-model="meta.title" placeholder="例: 例題12 判別式の利用" />
        </label>
        <label class="fld grow">
          <span>出典（任意）</span>
          <input v-model="meta.source" placeholder="例: Focus Gold p.120" />
        </label>
        <label class="fld">
          <span>日付</span>
          <input v-model="meta.notedOn" type="date" />
        </label>
      </div>

      <!-- 画像の登録 -->
      <div class="imgbar">
        <input ref="problemInput" type="file" accept="image/*" :capture="isTouch ? 'environment' : undefined" hidden @change="onFile($event, 'problem')" />
        <input ref="extraInput" type="file" accept="image/*" hidden @change="onFile($event, 'extra')" />
        <span class="imgbar-label">対象の問題の写真<span v-if="!hasProblem" style="color: #cf5563">（必須）</span></span>
        <button class="btn-dark" :disabled="busy" @click="problemInput?.click()">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l2-3h6l2 3h3v11H4z" /><circle cx="12" cy="13" r="3.2" /></svg>
          {{ isTouch ? (hasProblem ? '撮り直す' : '撮影する') : hasProblem ? '画像を差し替え' : '画像を選ぶ' }}
        </button>
        <button v-if="isTouch" class="btn-ghost" :disabled="busy" @click="pickProblemFromLibrary">写真から選ぶ</button>
        <span class="sep"></span>
        <button class="btn-ghost" :disabled="busy || !hasProblem" @click="extraInput?.click()">＋ 説明用の画像を追加</button>
        <span v-if="!isTouch" class="paste-hint">画像をコピーして Ctrl+V、またはページへドラッグ＆ドロップでも登録できます</span>
        <span v-if="busy" class="paste-hint">画像を処理中…</span>
      </div>

      <div @dragover.prevent @drop="onDrop">
        <MistakeCanvas :layout="layout" :urls="urls" :has-problem="hasProblem" @update:layout="onLayout" @remove-image="removeImage" />
      </div>
    </template>
  </div>
</template>

<style scoped>
.top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.left,
.right {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}
.back {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 7px 10px 7px 6px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  color: var(--mut);
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
}
.ttl {
  font-size: 17px;
  font-weight: 700;
}
.save {
  font-size: 12px;
  color: var(--faint);
}
.save.saved {
  color: #2e9d62;
}
.save.error {
  color: #cf5563;
}
.meta {
  display: flex;
  flex-wrap: wrap;
  gap: 10px 14px;
  padding: 12px 16px;
  margin-bottom: 10px;
}
.fld {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 140px;
}
.fld.grow {
  flex: 1;
  min-width: 180px;
}
.fld span {
  font-size: 11.5px;
  color: var(--mut);
  font-weight: 500;
}
.fld input,
.fld select {
  padding: 8px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  outline: none;
  background: #fff;
}
.fld input:focus,
.fld select:focus {
  border-color: #b9c2f2;
}
.imgbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}
.imgbar-label {
  font-size: 12.5px;
  font-weight: 700;
  color: var(--mut);
}
.sep {
  width: 1px;
  height: 22px;
  background: #e3e6ea;
  margin: 0 4px;
}
.paste-hint {
  font-size: 11.5px;
  color: var(--faint);
}
.btn-dark {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border: none;
  border-radius: 9px;
  background: #1c2024;
  color: #fff;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
}
.btn-dark:disabled {
  opacity: 0.4;
  cursor: default;
}
.btn-ghost {
  padding: 8px 14px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  cursor: pointer;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--mut);
}
.btn-ghost:disabled {
  opacity: 0.4;
  cursor: default;
}
.hint {
  background: #f8f9fb;
  border: 1px dashed #d8dce1;
  border-radius: 14px;
  padding: 18px;
  font-size: 12.5px;
  color: var(--faint);
}
</style>
