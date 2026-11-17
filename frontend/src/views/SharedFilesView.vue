<script setup lang="ts">
/**
 * ファイル共有（Google Drive 風）。生徒と講師が同じ階層フォルダを見て、学習データの PDF などを置く・見る・探す。
 * - フォルダは階層（道しるべで上へ）。URL の ?f= に今のフォルダを持つ（戻る・共有できる）
 * - アップロードは 4MB ずつの分割。PDF は画面側で文字を抜き出してサーバーに送り、本文でも検索できるようにする
 * - フォルダごとまとめてアップロードできる（「フォルダをアップロード」か、フォルダをドラッグ）。中の階層もそのまま作る。
 *   容量は 1 ファイル 300MB・生徒 1 人の合計（既定 15GB）。始める前に合計を見て、入らなければ受け付けない
 * - PDF は本のようにめくるビューア（ブックマーク対応）で閲覧、画像はそのまま表示、その他は保存
 * - 名前の変更・移動・削除。検索は名前と PDF の本文
 * 生徒・講師の両方で同じ画面（API のプレフィックスだけ違う）。
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ActionMenu, { type ActionMenuItem } from '@/components/ActionMenu.vue'
import AuthImage from '@/components/AuthImage.vue'
import HelpTip from '@/components/HelpTip.vue'
import PdfBookViewer from '@/components/PdfBookViewer.vue'
import { fmtBytes, sharedApi } from '@/api/shared'
import { appConfirm } from '@/lib/dialog'
import { saveFile } from '@/lib/native'
import { useUiStore } from '@/stores/ui'
import type { SharedBrowse, SharedFile, SharedFolder } from '@/types'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const folderId = computed<number | null>(() => {
  const f = Number(route.query.f)
  return Number.isInteger(f) && f > 0 ? f : null
})
const data = ref<SharedBrowse | null>(null)
const loading = ref(true)
const dragOver = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const folderInput = ref<HTMLInputElement | null>(null)
/** フォルダ選択（webkitdirectory）が使える端末か。iPhone・iPad の Safari は選べないので出さない */
const canPickFolder = (() => {
  const ua = navigator.userAgent
  const iosLike = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
  return !iosLike && 'webkitdirectory' in document.createElement('input')
})()

async function load() {
  loading.value = true
  try {
    data.value = await sharedApi.browse(folderId.value)
  } catch (e: unknown) {
    const st = (e as { response?: { status?: number } })?.response?.status
    if (st === 404 && folderId.value !== null) {
      // 消えたフォルダを指していたらルートへ
      router.replace({ query: {} })
      return
    }
    ui.notify('ファイル一覧の取得に失敗しました')
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(folderId, () => {
  search.q = ''
  search.results = null
  load()
})

function openFolder(id: number | null) {
  router.push({ query: id ? { f: String(id) } : {} })
}

// ---- 検索（名前と PDF の本文） ----
const search = reactive<{ q: string; busy: boolean; results: { folders: SharedFolder[]; files: SharedFile[] } | null }>({ q: '', busy: false, results: null })
let searchTimer: number | null = null
watch(
  () => search.q,
  (q) => {
    if (searchTimer) window.clearTimeout(searchTimer)
    if (!q.trim()) {
      search.results = null
      return
    }
    searchTimer = window.setTimeout(runSearch, 350)
  },
)
async function runSearch() {
  const q = search.q.trim()
  if (!q) return
  search.busy = true
  try {
    const r = await sharedApi.search(q)
    if (search.q.trim() === q) search.results = r
  } catch {
    ui.notify('検索に失敗しました')
  } finally {
    search.busy = false
  }
}
function pathLabel(path?: { id: number; name: string }[]): string {
  return ['ホーム', ...(path ?? []).map((p) => p.name)].join(' › ')
}

// ---- 新しいフォルダ・名前の変更・移動（小さなモーダル） ----
const nameDlg = reactive<{ open: boolean; mode: 'newFolder' | 'renameFolder' | 'renameFile'; id: number | null; value: string; busy: boolean }>({ open: false, mode: 'newFolder', id: null, value: '', busy: false })
const nameInput = ref<HTMLInputElement | null>(null)
function openNewFolder() {
  Object.assign(nameDlg, { open: true, mode: 'newFolder', id: null, value: '', busy: false })
  focusName()
}
function openRename(kind: 'folder' | 'file', item: { id: number; name: string }) {
  Object.assign(nameDlg, { open: true, mode: kind === 'folder' ? 'renameFolder' : 'renameFile', id: item.id, value: item.name, busy: false })
  focusName()
}
function focusName() {
  window.setTimeout(() => {
    nameInput.value?.focus()
    // 拡張子は選択に含めない
    const v = nameDlg.value
    const dot = nameDlg.mode === 'renameFile' ? v.lastIndexOf('.') : -1
    nameInput.value?.setSelectionRange(0, dot > 0 ? dot : v.length)
  }, 60)
}
async function submitName() {
  const v = nameDlg.value.trim()
  if (!v || nameDlg.busy) return
  nameDlg.busy = true
  try {
    if (nameDlg.mode === 'newFolder') {
      await sharedApi.createFolder(folderId.value, v)
      ui.notify('フォルダを作成しました')
    } else if (nameDlg.mode === 'renameFolder' && nameDlg.id !== null) {
      await sharedApi.updateFolder(nameDlg.id, { name: v })
    } else if (nameDlg.id !== null) {
      await sharedApi.updateFile(nameDlg.id, { name: v })
    }
    nameDlg.open = false
    await refresh()
  } catch (e: unknown) {
    ui.notify((e as { response?: { data?: { message?: string } } })?.response?.data?.message || '保存に失敗しました')
  } finally {
    nameDlg.busy = false
  }
}

const moveDlg = reactive<{ open: boolean; kind: 'folder' | 'file'; id: number | null; name: string; target: number | null; folders: { id: number; parentId: number | null; name: string }[]; busy: boolean }>({
  open: false,
  kind: 'file',
  id: null,
  name: '',
  target: null,
  folders: [],
  busy: false,
})
async function openMove(kind: 'folder' | 'file', item: { id: number; name: string }) {
  try {
    const all = await sharedApi.folders()
    Object.assign(moveDlg, { open: true, kind, id: item.id, name: item.name, target: folderId.value, folders: all, busy: false })
  } catch {
    ui.notify('フォルダの取得に失敗しました')
  }
}
/** 移動先の一覧（木を平らに。移動するフォルダ自身とその中は選べない） */
const moveOptions = computed(() => {
  const byParent = new Map<number | null, { id: number; parentId: number | null; name: string }[]>()
  for (const f of moveDlg.folders) {
    const list = byParent.get(f.parentId) ?? []
    list.push(f)
    byParent.set(f.parentId, list)
  }
  const out: { id: number | null; label: string; depth: number; disabled: boolean }[] = [{ id: null, label: 'ホーム', depth: 0, disabled: false }]
  const walk = (pid: number | null, depth: number, blocked: boolean) => {
    for (const f of byParent.get(pid) ?? []) {
      const self = moveDlg.kind === 'folder' && f.id === moveDlg.id
      const dis = blocked || self
      out.push({ id: f.id, label: f.name, depth, disabled: dis })
      if (depth < 12) walk(f.id, depth + 1, dis)
    }
  }
  walk(null, 1, false)
  return out
})
async function submitMove() {
  if (moveDlg.busy || moveDlg.id === null) return
  moveDlg.busy = true
  try {
    if (moveDlg.kind === 'folder') await sharedApi.updateFolder(moveDlg.id, { parentId: moveDlg.target })
    else await sharedApi.updateFile(moveDlg.id, { folderId: moveDlg.target })
    moveDlg.open = false
    ui.notify('移動しました')
    await refresh()
  } catch (e: unknown) {
    ui.notify((e as { response?: { data?: { message?: string } } })?.response?.data?.message || '移動に失敗しました')
  } finally {
    moveDlg.busy = false
  }
}

async function removeFolder(f: SharedFolder) {
  const inside = f.folderCount + f.fileCount
  if (!(await appConfirm(`フォルダ「${f.name}」を削除しますか？${inside ? `\n中の ${f.folderCount} フォルダ・${f.fileCount} ファイルも削除されます。` : ''}`, { danger: true, okText: '削除' }))) return
  try {
    await sharedApi.deleteFolder(f.id)
    ui.notify('削除しました')
    await refresh()
  } catch {
    ui.notify('削除に失敗しました')
  }
}
async function removeFile(f: SharedFile) {
  if (!(await appConfirm(`「${f.name}」を削除しますか？`, { danger: true, okText: '削除' }))) return
  try {
    await sharedApi.deleteFile(f.id)
    ui.notify('削除しました')
    await refresh()
  } catch {
    ui.notify('削除に失敗しました')
  }
}
async function refresh() {
  await load()
  if (search.results) await runSearch()
}

// ---- 操作メニュー ----
function folderMenu(): ActionMenuItem[] {
  return [
    { key: 'rename', label: '名前を変更' },
    { key: 'move', label: '移動' },
    { key: 'remove', label: '削除', danger: true },
  ]
}
function fileMenu(): ActionMenuItem[] {
  return [
    { key: 'open', label: '開く' },
    { key: 'download', label: '保存・ダウンロード' },
    { key: 'rename', label: '名前を変更' },
    { key: 'move', label: '移動' },
    { key: 'remove', label: '削除', danger: true },
  ]
}
function onFolderAction(f: SharedFolder, key: string) {
  if (key === 'rename') openRename('folder', f)
  else if (key === 'move') openMove('folder', f)
  else if (key === 'remove') removeFolder(f)
}
function onFileAction(f: SharedFile, key: string) {
  if (key === 'open') openFile(f)
  else if (key === 'download') download(f)
  else if (key === 'rename') openRename('file', f)
  else if (key === 'move') openMove('file', f)
  else if (key === 'remove') removeFile(f)
}

// ---- 閲覧 ----
const viewer = ref<SharedFile | null>(null)
const imageView = ref<SharedFile | null>(null)
function isImage(f: SharedFile): boolean {
  return !!f.mime && f.mime.startsWith('image/')
}
function openFile(f: SharedFile) {
  if (f.isPdf) viewer.value = f
  else if (isImage(f)) imageView.value = f
  else download(f)
}
async function download(f: SharedFile) {
  try {
    await saveFile(await sharedApi.fetchBlob(f.id), f.name)
  } catch {
    ui.notify('ダウンロードに失敗しました')
  }
}
function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    if (imageView.value) imageView.value = null
    else if (nameDlg.open) nameDlg.open = false
    else if (moveDlg.open) moveDlg.open = false
  }
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

// ---- アップロード ----
interface UploadJob {
  name: string
  size: number
  ratio: number
  status: 'reading' | 'uploading' | 'done' | 'error'
  message?: string
}
/** 選んだ・落とされたファイル 1 つ。rel は落とした場所からのフォルダの道（ファイル名は含まない） */
interface PickedFile {
  file: File
  rel: string[]
}
const jobs = ref<UploadJob[]>([])
/** まとめてアップロード（複数ファイル・フォルダ）の進み具合 */
const batch = reactive<{ active: boolean; label: string; total: number; done: number; failed: number; bytesTotal: number; bytesDone: number; ctrl: AbortController | null }>({
  active: false,
  label: '',
  total: 0,
  done: 0,
  failed: 0,
  bytesTotal: 0,
  bytesDone: 0,
  ctrl: null,
})
const batchRatio = computed(() => (batch.bytesTotal ? Math.min(1, batch.bytesDone / batch.bytesTotal) : 0))
const usage = computed(() => data.value?.usage ?? null)
const usageRatio = computed(() => (usage.value && usage.value.quotaBytes ? Math.min(1, usage.value.usedBytes / usage.value.quotaBytes) : 0))

function pickFiles() {
  fileInput.value?.click()
}
function pickFolder() {
  folderInput.value?.click()
}
/** 隠しファイル・OS の管理ファイルは入れない */
function isJunk(name: string): boolean {
  return name.startsWith('.') || /^(Thumbs\.db|desktop\.ini|\.DS_Store)$/i.test(name)
}
function onFilesPicked(e: Event) {
  const input = e.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  input.value = ''
  startUpload(files.filter((f) => !isJunk(f.name)).map((file) => ({ file, rel: [] })))
}
/** フォルダ選択: webkitRelativePath（"フォルダ/サブ/ファイル.pdf"）から階層を組む */
function onFolderPicked(e: Event) {
  const input = e.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  input.value = ''
  const items: PickedFile[] = []
  for (const file of files) {
    const segs = ((file as File & { webkitRelativePath?: string }).webkitRelativePath || file.name).split('/').filter(Boolean)
    const name = segs.pop() ?? file.name
    if (isJunk(name) || segs.some(isJunk)) continue
    items.push({ file, rel: segs })
  }
  startUpload(items)
}
async function onDrop(e: DragEvent) {
  dragOver.value = false
  const dt = e.dataTransfer
  if (!dt) return
  const items = await collectDropped(dt)
  if (items.length) startUpload(items)
}
/** ドロップされたもの（ファイル・フォルダ）を階層ごと集める。フォルダが読めないブラウザはファイルだけ */
async function collectDropped(dt: DataTransfer): Promise<PickedFile[]> {
  type Entry = { isFile: boolean; isDirectory: boolean; name: string; file: (ok: (f: File) => void, ng: (e: unknown) => void) => void; createReader: () => { readEntries: (ok: (es: Entry[]) => void, ng: (e: unknown) => void) => void } }
  const entries: Entry[] = []
  for (const it of Array.from(dt.items ?? [])) {
    const getEntry = (it as DataTransferItem & { webkitGetAsEntry?: () => Entry | null }).webkitGetAsEntry
    const en = typeof getEntry === 'function' ? getEntry.call(it) : null
    if (en) entries.push(en)
  }
  if (!entries.length) return Array.from(dt.files ?? []).filter((f) => !isJunk(f.name)).map((file) => ({ file, rel: [] }))
  const out: PickedFile[] = []
  const walk = async (en: Entry, rel: string[]) => {
    if (isJunk(en.name)) return
    if (en.isFile) {
      const f = await new Promise<File>((ok, ng) => en.file(ok, ng)).catch(() => null)
      if (f) out.push({ file: f, rel })
      return
    }
    if (!en.isDirectory) return
    const reader = en.createReader()
    // readEntries は 100 件ずつしか返さないので空になるまで繰り返す
    for (;;) {
      const chunk = await new Promise<Entry[]>((ok, ng) => reader.readEntries(ok, ng)).catch(() => [] as Entry[])
      if (!chunk.length) break
      for (const c of chunk) await walk(c, [...rel, en.name])
      if (out.length > MAX_FILES + 1) return
    }
  }
  for (const en of entries) await walk(en, [])
  return out
}
/** 1 回にまとめて送れるファイル数 */
const MAX_FILES = 1000
/** PDF の文字を抜き出す（本文検索用）。大きすぎる・読めない PDF は本文なしで登録する */
async function extractPdf(file: File): Promise<{ text: string | null; pageCount: number | null }> {
  if (file.size > 80 * 1024 * 1024) return { text: null, pageCount: null }
  try {
    const { pdfjsLib } = await import('@/lib/pdf')
    const doc = await pdfjsLib.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise
    const n = doc.numPages
    const parts: string[] = []
    let len = 0
    for (let i = 1; i <= Math.min(n, 400) && len < 600000; i++) {
      const page = await doc.getPage(i)
      const tc = await page.getTextContent()
      const line = tc.items
        .map((it) => ('str' in it ? it.str : ''))
        .join(' ')
        .replace(/\s+/g, ' ')
        .trim()
      if (line) {
        parts.push(line)
        len += line.length
      }
      page.cleanup()
    }
    await doc.destroy()
    return { text: parts.join('\n') || null, pageCount: n }
  } catch {
    return { text: null, pageCount: null }
  }
}
/**
 * アップロードの入口。容量（1 ファイルの上限・合計の上限・件数）を先に確かめ、
 * 複数ファイルやフォルダなら中身の数と合計を見せて確認してから始める。
 */
async function startUpload(items: PickedFile[]) {
  if (!items.length) return
  if (batch.active) {
    ui.notify('アップロード中です。終わってからもう一度お試しください。')
    return
  }
  if (items.length > MAX_FILES) {
    ui.notify(`一度に送れるのは ${MAX_FILES} ファイルまでです（${items.length} ファイル選ばれています）。フォルダを分けてアップロードしてください。`)
    return
  }
  const u = usage.value ?? { usedBytes: 0, quotaBytes: 15 * 1024 * 1024 * 1024, maxFileBytes: 300 * 1024 * 1024 }
  const tooBig = items.filter((it) => it.file.size > u.maxFileBytes)
  const okItems = items.filter((it) => it.file.size <= u.maxFileBytes)
  const total = okItems.reduce((a, it) => a + it.file.size, 0)
  const remain = Math.max(0, u.quotaBytes - u.usedBytes)
  if (total > remain) {
    ui.notify(`容量が足りません。追加する合計 ${fmtBytes(total)} に対して残りは ${fmtBytes(remain)} です（上限 ${fmtBytes(u.quotaBytes)}）。不要なファイルを削除してから追加してください。`)
    return
  }
  const dirs = new Set(okItems.filter((it) => it.rel.length).map((it) => it.rel.join('/')))
  const isBatch = okItems.length > 1 || dirs.size > 0 || tooBig.length > 0
  const tops = Array.from(new Set(items.map((it) => it.rel[0]).filter((x): x is string => !!x)))
  const label = tops.length === 1 ? `フォルダ「${tops[0]}」` : tops.length > 1 ? `${tops.length} フォルダ` : `${okItems.length} ファイル`
  if (isBatch) {
    const lines = [`${label}をアップロードします。`, `${okItems.length} ファイル${dirs.size ? `・${dirs.size} フォルダ` : ''}・合計 ${fmtBytes(total)}`, `（アップロード後の使用量 ${fmtBytes(u.usedBytes + total)} / ${fmtBytes(u.quotaBytes)}）`]
    if (tooBig.length) lines.push('', `次の ${tooBig.length} ファイルは 1 ファイルの上限（${fmtBytes(u.maxFileBytes)}）を超えるため送りません:`, ...tooBig.slice(0, 5).map((it) => `・${it.file.name}（${fmtBytes(it.file.size)}）`), ...(tooBig.length > 5 ? [`・ほか ${tooBig.length - 5} ファイル`] : []))
    if (!okItems.length) {
      ui.notify(lines.slice(3).join('\n'))
      return
    }
    if (!(await appConfirm(lines.join('\n'), { okText: 'アップロード' }))) return
  }
  await runUpload(okItems, { isBatch, label })
}
async function runUpload(items: PickedFile[], opt: { isBatch: boolean; label: string }) {
  const target = folderId.value
  const ctrl = new AbortController()
  Object.assign(batch, { active: true, label: opt.label, total: items.length, done: 0, failed: 0, bytesTotal: items.reduce((a, it) => a + it.file.size, 0), bytesDone: 0, ctrl })
  /** 作ったフォルダの ID（道 → ID）。同じ道は 1 回だけ作る */
  const folderIds = new Map<string, number | null>([['', target]])
  const folderIdFor = async (rel: string[]): Promise<number | null> => {
    const key = rel.join('/')
    const hit = folderIds.get(key)
    if (hit !== undefined) return hit
    const id = await sharedApi.ensureFolders(target, rel)
    folderIds.set(key, id)
    return id
  }
  // 浅いフォルダから順に作る（深い道は親まで一緒に作られる）
  const sorted = [...items].sort((a, b) => a.rel.length - b.rel.length || a.rel.join('/').localeCompare(b.rel.join('/')) || a.file.name.localeCompare(b.file.name))
  let aborted = false
  for (const it of sorted) {
    if (ctrl.signal.aborted) {
      aborted = true
      break
    }
    const file = it.file
    const job: UploadJob = { name: it.rel.length ? `${it.rel.join('/')}/${file.name}` : file.name, size: file.size, ratio: 0, status: 'reading' }
    jobs.value = [...jobs.value, job]
    const before = batch.bytesDone
    try {
      const fid = await folderIdFor(it.rel)
      const isPdf = /\.pdf$/i.test(file.name) || file.type === 'application/pdf'
      const extra = isPdf ? await extractPdf(file) : { text: null, pageCount: null }
      if (ctrl.signal.aborted) throw new DOMException('aborted', 'AbortError')
      job.status = 'uploading'
      await sharedApi.upload(
        fid,
        file,
        { searchText: extra.text, pageCount: extra.pageCount },
        (r) => {
          job.ratio = r
          batch.bytesDone = before + Math.round(file.size * r)
        },
        ctrl.signal,
      )
      job.status = 'done'
      job.ratio = 1
      batch.done++
      batch.bytesDone = before + file.size
      if (opt.isBatch) jobs.value = jobs.value.filter((j) => j !== job)
    } catch (e: unknown) {
      if (ctrl.signal.aborted || (e as { name?: string })?.name === 'AbortError' || (e as { code?: string })?.code === 'ERR_CANCELED') {
        jobs.value = jobs.value.filter((j) => j !== job)
        aborted = true
        break
      }
      job.status = 'error'
      job.message = (e as { response?: { data?: { message?: string } } })?.response?.data?.message || (e as Error)?.message || 'アップロードに失敗しました'
      batch.failed++
      batch.bytesDone = before + file.size
      // 容量の上限に当たったら残りは送っても入らないので止める
      if (/容量/.test(job.message)) {
        aborted = true
        break
      }
    }
  }
  batch.active = false
  batch.ctrl = null
  if (aborted) ui.notify(ctrl.signal.aborted ? `アップロードを中止しました（${batch.done} ファイルは追加済み）` : `${batch.done} ファイルを追加し、途中で止まりました`)
  else if (opt.isBatch) ui.notify(batch.failed ? `${batch.done} ファイルを追加しました（${batch.failed} ファイルは失敗）` : `${batch.done} ファイルを追加しました`)
  if (folderId.value === target) await load()
  else if (search.results) await runSearch()
  // 成功したものは少し後に消す
  window.setTimeout(() => (jobs.value = jobs.value.filter((j) => j.status !== 'done')), 4000)
}
function cancelUpload() {
  batch.ctrl?.abort()
}
function clearErrors() {
  jobs.value = jobs.value.filter((j) => j.status !== 'error')
}

function fmtDate(s: string | null): string {
  if (!s) return ''
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/)
  return m ? `${m[1]}/${Number(m[2])}/${Number(m[3])}` : s
}
function fileKind(f: SharedFile): string {
  if (f.isPdf) return 'PDF'
  if (isImage(f)) return '画像'
  const ext = f.name.split('.').pop() ?? ''
  return ext.length <= 5 && ext !== f.name ? ext.toUpperCase() : 'ファイル'
}
</script>

<template>
  <div class="sf" :class="{ drag: dragOver }" @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false" @drop.prevent="onDrop">
    <div class="head-row">
      <div style="display: flex; align-items: center; gap: 8px; min-width: 0">
        <div style="font-size: 17px; font-weight: 700; white-space: nowrap">ファイル共有</div>
        <HelpTip text="生徒と先生で同じフォルダを共有します。学習データの PDF などをフォルダに分けて置き、先生が確認できます。&#10;「アップロード」またはここにファイル・フォルダをドラッグして追加。「フォルダをアップロード」でフォルダの中身を階層ごとまとめて追加できます（パソコンから）。&#10;容量は 1 ファイル 300MB まで、全体で右上に表示の上限まで。足りないときは不要なファイルを削除してください。&#10;PDF は本のようにめくって読め（1 ページ／見開きを切替可）、PDF にブックマーク（目次）があれば左に表示されます。&#10;検索はファイル名・フォルダ名のほか、PDF の本文（文字が入っている PDF）も対象です。&#10;「⋯」から名前の変更・移動・削除ができます。" />
      </div>
      <div class="tools">
        <div class="search">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#9aa1ab" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4-4" /></svg>
          <input v-model="search.q" type="search" placeholder="名前・PDF の本文で検索" enterkeyhint="search" @keydown.enter="runSearch" />
          <button v-if="search.q" class="clr" aria-label="検索をクリア" @click="search.q = ''">×</button>
        </div>
        <button class="btn-outline" @click="openNewFolder">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /><path d="M12 11v6M9 14h6" /></svg>
          新しいフォルダ
        </button>
        <button v-if="canPickFolder" class="btn-outline" :disabled="batch.active" @click="pickFolder">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /><path d="M12 17v-6" /><path d="M9.5 13.5 12 11l2.5 2.5" /></svg>
          フォルダをアップロード
        </button>
        <button class="btn-dark" :disabled="batch.active" @click="pickFiles">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4" /><path d="M7 9l5-5 5 5" /><path d="M5 15v4a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-4" /></svg>
          アップロード
        </button>
        <input ref="fileInput" type="file" multiple style="display: none" @change="onFilesPicked" />
        <input ref="folderInput" type="file" multiple webkitdirectory style="display: none" @change="onFolderPicked" />
      </div>
    </div>

    <!-- 進行中のアップロード -->
    <div v-if="jobs.length || batch.active" class="jobs">
      <div v-if="batch.active" class="job batch">
        <span class="job-name">{{ batch.label }}をアップロード中… {{ batch.done + batch.failed }} / {{ batch.total }} ファイル</span>
        <span class="job-size">{{ fmtBytes(batch.bytesDone) }} / {{ fmtBytes(batch.bytesTotal) }}</span>
        <button type="button" class="job-cancel" @click="cancelUpload">中止</button>
        <span class="job-bar"><span :style="{ width: `${Math.round(batchRatio * 100)}%` }"></span></span>
      </div>
      <div v-if="!batch.active && jobs.some((j) => j.status === 'error')" class="job-errhead">
        <span>失敗したファイル</span>
        <button type="button" class="job-cancel" @click="clearErrors">閉じる</button>
      </div>
      <div v-for="(j, i) in jobs" :key="i" class="job" :class="j.status">
        <span class="job-name">{{ j.name }}</span>
        <span class="job-size">{{ fmtBytes(j.size) }}</span>
        <span class="job-st">
          <template v-if="j.status === 'reading'">読み取り中…</template>
          <template v-else-if="j.status === 'uploading'">{{ Math.round(j.ratio * 100) }}%</template>
          <template v-else-if="j.status === 'done'">完了</template>
          <template v-else>{{ j.message }}</template>
        </span>
        <span class="job-bar"><span :style="{ width: `${Math.round(j.ratio * 100)}%` }"></span></span>
      </div>
    </div>

    <!-- 検索結果 -->
    <template v-if="search.q.trim()">
      <div class="crumbs">
        <span class="crumb cur">「{{ search.q.trim() }}」の検索結果</span>
        <span v-if="search.busy" class="faint">検索中…</span>
      </div>
      <div v-if="search.results && !search.results.folders.length && !search.results.files.length" class="hint">一致するフォルダ・ファイルはありません。</div>
      <template v-else-if="search.results">
        <div v-if="search.results.folders.length" class="grid">
          <div v-for="f in search.results.folders" :key="'f' + f.id" class="folder" role="button" tabindex="0" @click="openFolder(f.id)" @keydown.enter="openFolder(f.id)">
            <svg class="ficon" width="30" height="30" viewBox="0 0 24 24" fill="#f3c969" stroke="#d9a93b" stroke-width="1"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /></svg>
            <div class="fbody">
              <div class="fname">{{ f.name }}</div>
              <div class="fsub">{{ pathLabel(f.path) }}</div>
            </div>
          </div>
        </div>
        <div v-if="search.results.files.length" class="list">
          <div v-for="f in search.results.files" :key="'s' + f.id" class="row" role="button" tabindex="0" @click="openFile(f)" @keydown.enter="openFile(f)">
            <span class="kind" :class="{ pdf: f.isPdf, img: isImage(f) }">{{ fileKind(f) }}</span>
            <div class="rbody">
              <div class="rname">{{ f.name }}</div>
              <div class="rsub">{{ pathLabel(f.path) }}<template v-if="f.snippet">　<span class="snip">{{ f.snippet }}</span></template></div>
            </div>
            <span class="rmeta">{{ f.pageCount ? `${f.pageCount}p・` : '' }}{{ fmtBytes(f.sizeBytes) }}</span>
            <span class="racts" @click.stop><ActionMenu :items="fileMenu()" @select="onFileAction(f, $event)" /></span>
          </div>
        </div>
      </template>
    </template>

    <!-- フォルダの中身 -->
    <template v-else>
      <div class="crumbs">
        <button class="crumb" :class="{ cur: !folderId }" @click="openFolder(null)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 4l9 6.5" /><path d="M5 9.5V20h14V9.5" /></svg>
          ホーム
        </button>
        <template v-for="c in data?.breadcrumbs ?? []" :key="c.id">
          <span class="sep">›</span>
          <button class="crumb" :class="{ cur: c.id === folderId }" @click="openFolder(c.id)">{{ c.name }}</button>
        </template>
        <div v-if="usage" class="usage" :class="{ warn: usageRatio > 0.9 }" :title="`1 ファイル ${fmtBytes(usage.maxFileBytes)} まで`">
          <span class="usage-bar"><span :style="{ width: `${Math.round(usageRatio * 100)}%` }"></span></span>
          <span class="usage-txt">{{ fmtBytes(usage.usedBytes) }} / {{ fmtBytes(usage.quotaBytes) }}</span>
        </div>
      </div>

      <div v-if="loading && !data" class="hint">読み込み中…</div>
      <template v-else-if="data">
        <div v-if="!data.folders.length && !data.files.length" class="hint drop-hint">
          <div>このフォルダは空です。</div>
          <div style="margin-top: 6px">「アップロード」からファイルを追加するか、ここにファイルやフォルダをドラッグしてください。</div>
        </div>

        <div v-if="data.folders.length" class="sec-title">フォルダ</div>
        <div v-if="data.folders.length" class="grid">
          <div v-for="f in data.folders" :key="f.id" class="folder" role="button" tabindex="0" @click="openFolder(f.id)" @keydown.enter="openFolder(f.id)">
            <svg class="ficon" width="30" height="30" viewBox="0 0 24 24" fill="#f3c969" stroke="#d9a93b" stroke-width="1"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /></svg>
            <div class="fbody">
              <div class="fname">{{ f.name }}</div>
              <div class="fsub">{{ f.folderCount ? `${f.folderCount} フォルダ・` : '' }}{{ f.fileCount }} ファイル</div>
            </div>
            <span class="facts" @click.stop><ActionMenu :items="folderMenu()" @select="onFolderAction(f, $event)" /></span>
          </div>
        </div>

        <div v-if="data.files.length" class="sec-title">ファイル</div>
        <div v-if="data.files.length" class="list">
          <div v-for="f in data.files" :key="f.id" class="row" role="button" tabindex="0" @click="openFile(f)" @keydown.enter="openFile(f)">
            <span class="kind" :class="{ pdf: f.isPdf, img: isImage(f) }">{{ fileKind(f) }}</span>
            <div class="rbody">
              <div class="rname">{{ f.name }}</div>
              <div class="rsub">
                {{ fmtDate(f.createdAt) }}<template v-if="f.createdByName">・{{ f.createdByName }}</template>
                <template v-if="f.note">・{{ f.note }}</template>
              </div>
            </div>
            <span class="rmeta">{{ f.pageCount ? `${f.pageCount}p・` : '' }}{{ fmtBytes(f.sizeBytes) }}</span>
            <span class="racts" @click.stop><ActionMenu :items="fileMenu()" @select="onFileAction(f, $event)" /></span>
          </div>
        </div>
      </template>
    </template>

    <!-- 名前の入力 -->
    <Transition name="ui-modal">
      <div v-if="nameDlg.open" class="overlay ui-overlay">
        <form class="dlg ui-panel" @submit.prevent="submitName">
          <div class="dlg-title">{{ nameDlg.mode === 'newFolder' ? '新しいフォルダ' : '名前を変更' }}</div>
          <input ref="nameInput" v-model="nameDlg.value" class="dlg-input" :placeholder="nameDlg.mode === 'newFolder' ? 'フォルダ名' : '名前'" maxlength="255" />
          <div class="dlg-btns">
            <button type="button" class="btn-outline" @click="nameDlg.open = false">キャンセル</button>
            <button type="submit" class="btn-dark" :disabled="!nameDlg.value.trim() || nameDlg.busy">{{ nameDlg.mode === 'newFolder' ? '作成' : '保存' }}</button>
          </div>
        </form>
      </div>
    </Transition>

    <!-- 移動先の選択 -->
    <Transition name="ui-modal">
      <div v-if="moveDlg.open" class="overlay ui-overlay">
        <div class="dlg ui-panel">
          <div class="dlg-title">「{{ moveDlg.name }}」を移動</div>
          <div class="tree">
            <button v-for="o in moveOptions" :key="o.id ?? 'root'" class="tree-row" :class="{ on: o.id === moveDlg.target }" :disabled="o.disabled" :style="{ paddingLeft: `${10 + o.depth * 16}px` }" @click="moveDlg.target = o.id">
              <svg v-if="o.id === null" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 4l9 6.5" /><path d="M5 9.5V20h14V9.5" /></svg>
              <svg v-else width="15" height="15" viewBox="0 0 24 24" fill="#f3c969" stroke="#d9a93b" stroke-width="1"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /></svg>
              {{ o.label }}
            </button>
          </div>
          <div class="dlg-btns">
            <button type="button" class="btn-outline" @click="moveDlg.open = false">キャンセル</button>
            <button type="button" class="btn-dark" :disabled="moveDlg.busy" @click="submitMove">ここに移動</button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- PDF ビューア（本のようにめくる） -->
    <Transition name="ui-modal">
      <PdfBookViewer v-if="viewer" :url="sharedApi.fileUrl(viewer.id)" :title="viewer.name" :download-name="viewer.name" :fetch-blob="() => sharedApi.fetchBlob(viewer!.id)" @close="viewer = null" />
    </Transition>

    <!-- 画像の表示 -->
    <Transition name="ui-modal">
      <div v-if="imageView" class="img-view ui-fullscreen">
        <div class="img-top">
          <button class="iv-btn" @click="imageView = null">閉じる</button>
          <div class="iv-title">{{ imageView.name }}</div>
          <button class="iv-btn" @click="download(imageView)">保存</button>
        </div>
        <div class="img-body"><AuthImage :src="sharedApi.fileUrl(imageView.id)" :alt="imageView.name" /></div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.sf {
  min-height: 60vh;
  border-radius: 14px;
  transition: box-shadow 0.15s;
}
.sf.drag {
  box-shadow: 0 0 0 3px #3b50cc inset;
}
.head-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.tools {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.search {
  position: relative;
  display: flex;
  align-items: center;
  min-width: 220px;
}
.search svg {
  position: absolute;
  left: 11px;
}
.search input {
  width: 100%;
  padding: 8px 30px 8px 32px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  font-size: 13px;
  background: #fff;
  outline: none;
}
.search .clr {
  position: absolute;
  right: 6px;
  width: 22px;
  height: 22px;
  border: none;
  border-radius: 50%;
  background: #f1f2f4;
  color: #6b7280;
  cursor: pointer;
  line-height: 1;
}
.btn-outline,
.btn-dark {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  background: #fff;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--ink);
  cursor: pointer;
  white-space: nowrap;
}
.btn-dark {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.btn-dark:disabled,
.btn-outline:disabled {
  opacity: 0.5;
  cursor: default;
}
.jobs {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 6px 12px;
  margin-bottom: 12px;
}
.job {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 2px 12px;
  align-items: center;
  padding: 6px 0;
  font-size: 12px;
}
.job + .job {
  border-top: 1px solid #f1f2f4;
}
.job-name {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.job-size,
.job-st {
  color: var(--mut);
  white-space: nowrap;
}
.job.error .job-st {
  color: #c0444f;
}
.job.done .job-st {
  color: #2f7a4f;
}
.job-bar {
  grid-column: 1 / -1;
  height: 3px;
  background: #f1f2f4;
  border-radius: 2px;
  overflow: hidden;
}
.job-bar span {
  display: block;
  height: 100%;
  background: #3b50cc;
  transition: width 0.2s;
}
.job.error .job-bar span {
  background: #c0444f;
}
.job.batch .job-name {
  color: var(--ink);
}
.job-cancel {
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  padding: 3px 10px;
  font-size: 11.5px;
  font-weight: 700;
  color: var(--mut);
  cursor: pointer;
}
.job-errhead {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 6px 0 2px;
  font-size: 11.5px;
  font-weight: 700;
  color: #c0444f;
}
.usage {
  margin-left: auto;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 11px;
  color: var(--faint);
  white-space: nowrap;
}
.usage-bar {
  width: 90px;
  height: 5px;
  background: #e9ecf0;
  border-radius: 3px;
  overflow: hidden;
}
.usage-bar span {
  display: block;
  height: 100%;
  background: #3b50cc;
}
.usage.warn .usage-bar span {
  background: #c0444f;
}
.usage.warn .usage-txt {
  color: #c0444f;
  font-weight: 700;
}
.crumbs {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
  margin-bottom: 12px;
  font-size: 13px;
}
.crumb {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 6px 10px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--mut);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  max-width: 260px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.crumb:hover {
  background: #f1f2f4;
}
.crumb.cur {
  color: var(--ink);
  font-weight: 700;
  cursor: default;
}
.sep,
.faint {
  color: var(--faint);
}
.sec-title {
  font-size: 11.5px;
  font-weight: 700;
  color: var(--faint);
  margin: 6px 2px 8px;
}
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
  gap: 10px;
  margin-bottom: 16px;
}
.folder {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 8px 10px 12px;
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 12px;
  cursor: pointer;
  transition: border-color 0.15s, transform 0.15s;
}
.folder:hover {
  border-color: #3b50cc;
}
.folder:active {
  transform: scale(0.985);
}
.ficon {
  flex-shrink: 0;
}
.fbody,
.rbody {
  flex: 1;
  min-width: 0;
}
.fname,
.rname {
  font-size: 13px;
  font-weight: 700;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.fsub,
.rsub {
  font-size: 11px;
  color: var(--faint);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.list {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 12px;
  overflow: hidden;
  margin-bottom: 16px;
}
.row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 8px 10px 12px;
  cursor: pointer;
}
.row + .row {
  border-top: 1px solid #f1f2f4;
}
.row:hover {
  background: #f8f9fb;
}
.kind {
  flex-shrink: 0;
  width: 44px;
  height: 30px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 7px;
  background: #eef1f6;
  color: #5b6b8c;
  font-size: 10.5px;
  font-weight: 800;
  letter-spacing: 0.02em;
  overflow: hidden;
}
.kind.pdf {
  background: #fdecec;
  color: #c0444f;
}
.kind.img {
  background: #e6f5ec;
  color: #2f7a4f;
}
.rmeta {
  font-size: 11.5px;
  color: var(--mut);
  white-space: nowrap;
  flex-shrink: 0;
}
.racts,
.facts {
  flex-shrink: 0;
}
.snip {
  color: #4b5563;
}
.hint {
  background: #f8f9fb;
  border: 1px dashed #d8dce1;
  border-radius: 14px;
  padding: 18px;
  font-size: 12.5px;
  color: var(--faint);
  line-height: 1.7;
  text-align: center;
}
.drop-hint {
  padding: 40px 18px;
}
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(20, 24, 32, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 70;
  padding: 16px;
}
.dlg {
  background: #fff;
  border-radius: 16px;
  width: min(440px, 100%);
  padding: 18px 20px;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
  display: flex;
  flex-direction: column;
  gap: 12px;
  max-height: 86vh;
}
.dlg-title {
  font-size: 15px;
  font-weight: 700;
}
.dlg-input {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  font-size: 14px;
  outline: none;
}
.dlg-input:focus {
  border-color: #3b50cc;
}
.dlg-btns {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.tree {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  border: 1px solid var(--line);
  border-radius: 10px;
  padding: 4px 0;
}
.tree-row {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  min-height: 36px;
  padding: 6px 10px;
  border: none;
  background: transparent;
  text-align: left;
  font-size: 13px;
  color: var(--ink);
  cursor: pointer;
  font-family: inherit;
}
.tree-row.on {
  background: #e8eefb;
  font-weight: 700;
}
.tree-row:disabled {
  color: #c9ced6;
  cursor: default;
}
.img-view {
  position: fixed;
  inset: 0;
  z-index: 2600;
  background: #1c1f24;
  display: flex;
  flex-direction: column;
}
.img-top {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: max(8px, env(safe-area-inset-top)) 12px 8px;
  color: #fff;
}
.iv-title {
  flex: 1;
  min-width: 0;
  text-align: center;
  font-size: 14px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.iv-btn {
  min-height: 36px;
  padding: 0 12px;
  border: none;
  border-radius: 9px;
  background: transparent;
  color: #cfe0ff;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}
.img-body {
  flex: 1;
  min-height: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: auto;
  padding: 12px;
}
.img-body :deep(img) {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}
@media (max-width: 700px) {
  .tools {
    width: 100%;
  }
  .search {
    flex: 1 1 100%;
  }
  .rmeta {
    display: none;
  }
  .grid {
    grid-template-columns: 1fr;
  }
}
</style>
