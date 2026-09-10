<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AuthImage from '@/components/AuthImage.vue'
import { quizApi } from '@/api/quiz'
import { useUiStore } from '@/stores/ui'
import type { QuizDetail } from '@/types'

/**
 * 回答用紙の撮影・提出（生徒）。ブラウザから直接カメラを起動し、出題ページ数分を順番に撮影して提出する。
 * カメラが使えない環境ではファイル選択（スマホでは端末のカメラアプリ）にフォールバックする。
 */
const props = defineProps<{ quiz: QuizDetail }>()
const emit = defineEmits<{ close: []; submitted: [] }>()

const ui = useUiStore()
const pages = computed(() => props.quiz.pages)

const video = ref<HTMLVideoElement | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
let stream: MediaStream | null = null
const camReady = ref(false)
const videoLive = ref(false) // <video> が実際に映像を出し始めたか（撮影ボタンの有効化に使う）
const camError = ref('')
const facing = ref<'environment' | 'user'>('environment')
const canSwitch = ref(false)

const idx = ref(0)
const phase = ref<'shoot' | 'review' | 'done' | 'uploading'>('shoot')
const shots = ref<Map<number, { blob: Blob; url: string }>>(new Map())
const progress = ref({ done: 0, total: 0 })

const current = computed(() => pages.value[idx.value] ?? null)
const currentShot = computed(() => (current.value ? shots.value.get(current.value.id) ?? null : null))
const hasAll = computed(() => pages.value.every((p) => shots.value.has(p.id) || p.hasAnswer))
const newCount = computed(() => shots.value.size)

function stateOf(pageId: number, hasAnswer: boolean): 'new' | 'old' | 'none' {
  if (shots.value.has(pageId)) return 'new'
  return hasAnswer ? 'old' : 'none'
}

async function startCamera() {
  stopCamera()
  camReady.value = false
  camError.value = ''
  if (!navigator.mediaDevices?.getUserMedia) {
    camError.value = 'このブラウザではカメラを直接起動できません。下の「写真を選ぶ／撮影」から提出してください。'
    return
  }
  try {
    stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: facing.value }, width: { ideal: 2560 }, height: { ideal: 1920 } },
      audio: false,
    })
    const v = video.value
    if (!v) return
    v.srcObject = stream
    v.setAttribute('playsinline', '')
    v.muted = true
    await v.play()
    camReady.value = true
    try {
      const devices = await navigator.mediaDevices.enumerateDevices()
      canSwitch.value = devices.filter((d) => d.kind === 'videoinput').length > 1
    } catch {
      canSwitch.value = false
    }
  } catch (e: unknown) {
    const name = (e as { name?: string })?.name
    camError.value =
      name === 'NotAllowedError'
        ? 'カメラの使用が許可されていません。ブラウザの設定でカメラを許可するか、下の「写真を選ぶ／撮影」から提出してください。'
        : 'カメラを起動できませんでした。下の「写真を選ぶ／撮影」から提出してください。'
  }
}

function stopCamera() {
  stream?.getTracks().forEach((t) => t.stop())
  stream = null
  camReady.value = false
}

/** 撮影画面に戻ったとき（<video> が作り直される）にストリームを再接続する */
async function attachVideo() {
  await nextTick()
  const v = video.value
  if (!v) return
  if (!stream) {
    await startCamera()
    return
  }
  if (v.srcObject !== stream) {
    v.srcObject = stream
    v.setAttribute('playsinline', '')
    v.muted = true
    try {
      await v.play()
    } catch {
      // 自動再生がブロックされた場合は次の操作で再生される
    }
  }
}
watch(phase, (ph) => {
  if (ph === 'shoot') {
    videoLive.value = false
    attachVideo()
  }
})

async function switchCamera() {
  facing.value = facing.value === 'environment' ? 'user' : 'environment'
  await startCamera()
}

/** 長辺 2400px 以内の JPEG にする */
function toJpeg(source: HTMLVideoElement | HTMLImageElement, sw: number, sh: number): Promise<Blob> {
  const long = Math.max(sw, sh)
  const s = long > 2400 ? 2400 / long : 1
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(sw * s)
  canvas.height = Math.round(sh * s)
  const ctx = canvas.getContext('2d')!
  ctx.drawImage(source, 0, 0, canvas.width, canvas.height)
  return new Promise((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('capture failed'))), 'image/jpeg', 0.9))
}

function setShot(pageId: number, blob: Blob) {
  const prev = shots.value.get(pageId)
  if (prev) URL.revokeObjectURL(prev.url)
  const next = new Map(shots.value)
  next.set(pageId, { blob, url: URL.createObjectURL(blob) })
  shots.value = next
  phase.value = 'review'
}

async function capture() {
  const v = video.value
  if (!v || !camReady.value || !current.value) return
  if (!v.videoWidth || !v.videoHeight) {
    ui.notify('カメラの準備中です。少し待ってからもう一度押してください')
    attachVideo()
    return
  }
  try {
    const blob = await toJpeg(v, v.videoWidth, v.videoHeight)
    setShot(current.value.id, blob)
  } catch {
    ui.notify('撮影に失敗しました')
  }
}

function pickFile() {
  fileInput.value?.click()
}
async function onFile(e: Event) {
  const input = e.target as HTMLInputElement
  const f = input.files?.[0]
  input.value = ''
  if (!f || !current.value) return
  // 端末で撮った写真は大きいので縮小してから保持する
  try {
    const img = new Image()
    const url = URL.createObjectURL(f)
    await new Promise<void>((res, rej) => {
      img.onload = () => res()
      img.onerror = () => rej(new Error('load'))
      img.src = url
    })
    const blob = await toJpeg(img, img.naturalWidth, img.naturalHeight)
    URL.revokeObjectURL(url)
    setShot(current.value.id, blob)
  } catch {
    setShot(current.value.id, f)
  }
}

function retake() {
  phase.value = 'shoot'
}
function next() {
  if (idx.value < pages.value.length - 1) {
    idx.value++
    phase.value = 'shoot'
  } else {
    phase.value = 'done'
  }
}
function goto(i: number) {
  idx.value = i
  phase.value = shots.value.has(pages.value[i]!.id) ? 'review' : 'shoot'
}

async function submit() {
  if (!hasAll.value) {
    ui.notify('すべてのページを撮影してください')
    return
  }
  phase.value = 'uploading'
  progress.value = { done: 0, total: newCount.value }
  try {
    for (const p of pages.value) {
      const s = shots.value.get(p.id)
      if (!s) continue
      await quizApi.uploadAnswer(props.quiz.id, p.id, s.blob)
      progress.value.done++
    }
    await quizApi.submit(props.quiz.id)
    ui.notify('回答を提出しました')
    emit('submitted')
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
    ui.notify(msg || '提出に失敗しました。通信状況を確認してもう一度お試しください')
    phase.value = 'done'
  }
}

onMounted(startCamera)
onBeforeUnmount(() => {
  stopCamera()
  shots.value.forEach((s) => URL.revokeObjectURL(s.url))
})
</script>

<template>
  <div class="cam-overlay">
    <div class="cam-top">
      <div style="min-width: 0">
        <div style="font-size: 13.5px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ quiz.title }}</div>
        <div style="font-size: 11px; color: #b7bcc6">回答用紙を撮影して提出（{{ pages.length }}ページ）</div>
      </div>
      <button class="x" @click="emit('close')">×</button>
    </div>

    <!-- ページ一覧 -->
    <div class="strip">
      <button
        v-for="(p, i) in pages"
        :key="p.id"
        class="chip"
        :class="[stateOf(p.id, p.hasAnswer), { cur: i === idx && phase !== 'done' }]"
        @click="goto(i)"
      >
        <span class="chip-n">{{ i + 1 }}</span>
        <span class="chip-l">{{ p.label }}</span>
        <span class="chip-s">{{ stateOf(p.id, p.hasAnswer) === 'new' ? '撮影済' : stateOf(p.id, p.hasAnswer) === 'old' ? '提出済' : '未撮影' }}</span>
      </button>
    </div>

    <!-- 撮影 -->
    <div v-if="phase === 'shoot'" class="stage">
      <div class="view">
        <video ref="video" class="video" autoplay playsinline muted @playing="videoLive = true" @loadeddata="videoLive = true"></video>
        <div v-if="!camReady && !camError" class="msg">カメラを起動しています…</div>
        <div v-if="camError" class="msg err">{{ camError }}</div>
        <div class="guide">
          <div class="guide-cap">ページ {{ idx + 1 }} / {{ pages.length }}<span v-if="current">・{{ current.label }}</span></div>
        </div>
      </div>
      <div class="controls">
        <button class="sub-btn" @click="pickFile">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2" /><circle cx="9" cy="10" r="2" /><path d="M21 16l-5-5-8 8" /></svg>
          <span>写真を選ぶ／撮影</span>
        </button>
        <button class="shutter" :disabled="!camReady || !videoLive" title="撮影" @click="capture"><span></span></button>
        <button class="sub-btn" :disabled="!canSwitch" @click="switchCamera">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h3l2-2h6l2 2h3v12H4z" /><path d="M8.5 13a3.5 3.5 0 0 0 6.5 1.8" /><path d="M15.5 13a3.5 3.5 0 0 0-6.5-1.8" /><path d="M15 15v-2h2" /><path d="M9 11v2H7" /></svg>
          <span>カメラ切替</span>
        </button>
      </div>
      <input ref="fileInput" type="file" accept="image/*" capture="environment" style="display: none" @change="onFile" />
      <div v-if="current?.hasAnswer && !currentShot" class="note">このページは提出済みの写真があります。撮影すると差し替えられます。</div>
    </div>

    <!-- 確認 -->
    <div v-else-if="phase === 'review'" class="stage">
      <div class="view">
        <img v-if="currentShot" :src="currentShot.url" class="photo" alt="" />
        <div class="guide">
          <div class="guide-cap">ページ {{ idx + 1 }} / {{ pages.length }}<span v-if="current">・{{ current.label }}</span></div>
        </div>
      </div>
      <div class="controls">
        <button class="btn ghost" @click="retake">撮り直す</button>
        <button class="btn primary" @click="next">{{ idx < pages.length - 1 ? '次のページへ' : '確認へ' }}</button>
      </div>
    </div>

    <!-- 提出前確認 -->
    <div v-else-if="phase === 'done'" class="stage">
      <div class="done-list">
        <div v-for="(p, i) in pages" :key="p.id" class="done-row" @click="goto(i)">
          <div class="done-thumb">
            <img v-if="shots.get(p.id)" :src="shots.get(p.id)!.url" alt="" />
            <AuthImage v-else-if="p.hasAnswer" :src="quizApi.answerImageUrl(quiz.id, p.id, p.answerVersion)" />
            <div v-else class="none">未撮影</div>
          </div>
          <div style="flex: 1; min-width: 0">
            <div style="font-size: 12.5px; font-weight: 600">{{ i + 1 }}. {{ p.label }}</div>
            <div style="font-size: 11px" :style="{ color: stateOf(p.id, p.hasAnswer) === 'none' ? '#ff9f9f' : '#b7bcc6' }">
              {{ stateOf(p.id, p.hasAnswer) === 'new' ? '新しく撮影' : stateOf(p.id, p.hasAnswer) === 'old' ? '提出済みの写真を使用' : '未撮影（タップして撮影）' }}
            </div>
          </div>
          <span style="font-size: 11px; color: #b7bcc6">撮り直す ›</span>
        </div>
      </div>
      <div class="controls">
        <button class="btn ghost" @click="goto(0)">最初から撮り直す</button>
        <button class="btn primary" :disabled="!hasAll" @click="submit">提出する</button>
      </div>
      <div class="note">提出後、先生が採点・添削します。採点・添削が始まる前なら撮り直して再提出できます。</div>
    </div>

    <!-- 送信中 -->
    <div v-else class="stage center">
      <div class="spinner"></div>
      <div style="font-size: 13px">アップロード中… {{ progress.done }} / {{ progress.total }}</div>
    </div>
  </div>
</template>

<style scoped>
.cam-overlay {
  position: fixed;
  inset: 0;
  background: #14171c;
  color: #fff;
  z-index: 80;
  display: flex;
  flex-direction: column;
}
.cam-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 12px 14px 8px;
}
.x {
  border: none;
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  font-size: 20px;
  cursor: pointer;
  flex-shrink: 0;
}
.strip {
  display: flex;
  gap: 6px;
  padding: 4px 14px 8px;
  overflow-x: auto;
  scrollbar-width: none;
  flex-shrink: 0;
}
.strip::-webkit-scrollbar {
  display: none;
}
.chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: 999px;
  border: 1px solid rgba(255, 255, 255, 0.18);
  background: rgba(255, 255, 255, 0.06);
  color: #d5d9e0;
  font-size: 11.5px;
  cursor: pointer;
  white-space: nowrap;
  max-width: 220px;
}
.chip.cur {
  border-color: #9fb4ff;
  background: rgba(120, 140, 255, 0.2);
  color: #fff;
}
.chip-n {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.15);
  font-size: 10.5px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.chip-l {
  overflow: hidden;
  text-overflow: ellipsis;
}
.chip-s {
  font-size: 10px;
  color: #9aa1ab;
}
.chip.new .chip-s {
  color: #8ee0a8;
}
.chip.old .chip-s {
  color: #9fb4ff;
}
.stage {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  padding: 0 14px 14px;
  gap: 10px;
}
.stage.center {
  align-items: center;
  justify-content: center;
}
.view {
  position: relative;
  flex: 1;
  min-height: 0;
  background: #000;
  border-radius: 14px;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
}
.video,
.photo {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.msg {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  text-align: center;
  font-size: 13px;
  color: #d5d9e0;
  line-height: 1.7;
}
.msg.err {
  color: #ffb4b4;
}
.guide {
  position: absolute;
  left: 0;
  right: 0;
  top: 0;
  padding: 10px;
  pointer-events: none;
}
.guide-cap {
  display: inline-block;
  background: rgba(0, 0, 0, 0.55);
  padding: 5px 10px;
  border-radius: 8px;
  font-size: 12px;
}
.controls {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 18px;
  flex-shrink: 0;
  padding: 4px 0;
}
.shutter {
  width: 72px;
  height: 72px;
  border-radius: 50%;
  border: 4px solid #fff;
  background: transparent;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}
.shutter span {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: #fff;
}
.shutter:disabled {
  opacity: 0.35;
  cursor: default;
}
.sub-btn {
  width: 92px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  border: none;
  background: transparent;
  color: #d5d9e0;
  font-size: 10.5px;
  cursor: pointer;
}
.sub-btn:disabled {
  opacity: 0.3;
  cursor: default;
}
.btn {
  padding: 12px 22px;
  border-radius: 12px;
  border: none;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}
.btn.primary {
  background: #fff;
  color: #1c2024;
}
.btn.primary:disabled {
  opacity: 0.35;
  cursor: default;
}
.btn.ghost {
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
}
.note {
  font-size: 11.5px;
  color: #b7bcc6;
  text-align: center;
  flex-shrink: 0;
}
.done-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.done-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  background: rgba(255, 255, 255, 0.06);
  border-radius: 12px;
  cursor: pointer;
}
.done-thumb {
  width: 56px;
  height: 72px;
  border-radius: 6px;
  overflow: hidden;
  background: #000;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
.done-thumb img,
.done-thumb :deep(img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.none {
  font-size: 10px;
  color: #ff9f9f;
}
.spinner {
  width: 34px;
  height: 34px;
  border: 3px solid rgba(255, 255, 255, 0.2);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin-bottom: 12px;
}
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
