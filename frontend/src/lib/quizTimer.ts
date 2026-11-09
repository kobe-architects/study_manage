// 1 問ごとの解答時間の計測。問題を表示したら start()、解答したら stop() で経過ミリ秒を得る。
// 画面を離れていた時間（タブが隠れていた間）は数えない。
export class QuestionTimer {
  private startedAt: number | null = null
  private acc = 0
  private onVis = () => {
    if (document.hidden) this.pause()
    else this.resume()
  }

  constructor() {
    if (typeof document !== 'undefined') document.addEventListener('visibilitychange', this.onVis)
  }

  /** 新しい問題を表示した */
  start() {
    this.acc = 0
    this.startedAt = document.hidden ? null : performance.now()
  }

  private pause() {
    if (this.startedAt !== null) {
      this.acc += performance.now() - this.startedAt
      this.startedAt = null
    }
  }

  private resume() {
    if (this.startedAt === null) this.startedAt = performance.now()
  }

  /** 解答した。経過ミリ秒（整数）。start していなければ null */
  stop(): number | null {
    if (this.startedAt === null && this.acc === 0) return null
    this.pause()
    const ms = Math.round(this.acc)
    this.acc = 0
    return Math.min(ms, 86_400_000)
  }

  dispose() {
    if (typeof document !== 'undefined') document.removeEventListener('visibilitychange', this.onVis)
  }
}
