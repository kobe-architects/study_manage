// クイズ正解・不正解の効果音
import seikaiUrl from '@/assets/sounds/seikai.mp3'
import fuseikaiUrl from '@/assets/sounds/fuseikai.mp3'

const cache: Record<string, HTMLAudioElement> = {}

export function playResultSound(isCorrect: boolean) {
  try {
    const url = isCorrect ? seikaiUrl : fuseikaiUrl
    let audio = cache[url]
    if (!audio) {
      audio = new Audio(url)
      cache[url] = audio
    }
    audio.currentTime = 0
    void audio.play().catch(() => {})
  } catch {
    // 再生不可環境では無視
  }
}
